#!/bin/sh
#
# Collect the data needed by the check_pfsense test fixtures.
#
# Run on the pfSense firewall as root: sh collect_fixtures.sh
#
# The script is read-only: it only calls read-only pfSense functions and
# system commands, and writes only in a new temporary directory. It never
# collects private keys, passwords or certificates; review the files before
# sharing them anyway (they contain IP addresses and interface names).
#
# Author: Mattia Martinello <mattia@mattiamartinello.com>

set -u

OUT=$(mktemp -d /tmp/check_pfsense_fixtures.XXXXXX) || exit 1
PHP=/usr/local/bin/php

# Run a PHP snippet with the pfSense include path. Output goes to a file.
php_dump() {
    name=$1
    code=$2
    $PHP -d display_errors=stderr -r "
        set_include_path(get_include_path() . ':/etc/inc:/usr/local/pfSense/include:/usr/local/www');
        $code
    " > "$OUT/$name" 2> "$OUT/$name.stderr"
    [ -s "$OUT/$name.stderr" ] || rm -f "$OUT/$name.stderr"
}

# Run a command and save its output and exit code.
cmd_dump() {
    name=$1
    shift
    "$@" > "$OUT/$name" 2>&1
    echo "exit code: $?" >> "$OUT/$name"
}

echo "Collecting into $OUT"

# --- System
cmd_dump version.txt cat /etc/version /etc/version.patch
cmd_dump php_version.txt $PHP -v

# --- gateways
php_dump gateways_array.php '
    require_once("config.inc");
    require_once("gwlb.inc");
    echo "<?php\nreturn ", var_export(return_gateways_array(false, false, false), true), ";\n";
'
php_dump gateways_dpinger.php '
    require_once("config.inc");
    require_once("gwlb.inc");
    $result = [];
    foreach (running_dpinger_processes() as $name => $proc) {
        $fp = @stream_socket_client("unix://" . $proc["socket"], $errno, $errstr, 5);
        $raw = $fp ? trim(stream_get_contents($fp)) : null;
        if ($fp) { fclose($fp); }
        $result[$name] = $proc + ["raw" => $raw, "error" => $fp ? null : $errstr];
    }
    echo "<?php\nreturn ", var_export($result, true), ";\n";
'
php_dump gateways_defaults.php '
    require_once("config.inc");
    require_once("gwlb.inc");
    echo "<?php\nreturn ", var_export(return_dpinger_defaults(), true), ";\n";
'
cmd_dump gateways_dpinger_files.txt sh -c 'ls -l /var/run/dpinger_*'

# --- gateway-groups
php_dump gateway_groups.php '
    require_once("config.inc");
    $groups = [];
    foreach (config_get_path("gateways/gateway_group", []) as $g) {
        if (!is_array($g)) { continue; }
        $groups[] = [
            "name" => $g["name"] ?? "",
            "descr" => $g["descr"] ?? "",
            "trigger" => $g["trigger"] ?? "",
            "item" => array_values((array) ($g["item"] ?? [])),
        ];
    }
    echo "<?php\nreturn ", var_export($groups, true), ";\n";
'

# --- services
# get_gateways() (2.8+) may call write_config() through refresh_gateways()
# when its cache is empty: fill the cache first, like check_pfsense does.
php_dump services.php '
    require_once("config.inc");
    require_once("service-utils.inc");
    if (function_exists("get_gateways") && !is_array($GLOBALS["GatewaysCache"] ?? null)) {
        $GLOBALS["GatewaysCache"] = return_gateways_array(true, true, true);
    }
    $result = [];
    foreach (get_services() as $s) {
        if (!is_array($s) || empty($s["name"])) { continue; }
        $result[] = [
            "name" => $s["name"],
            "description" => html_entity_decode($s["description"] ?? "", ENT_QUOTES),
            "mode" => $s["mode"] ?? null,
            "vpnid" => $s["vpnid"] ?? null,
            "zone" => $s["zone"] ?? null,
            "running" => (bool) get_service_status($s),
            "enabled" => (bool) is_service_enabled($s["name"]),
        ];
    }
    echo "<?php\nreturn ", var_export(["services" => $result], true), ";\n";
'
# --- openvpn
# Contains user names and remote IP addresses of the connected clients:
# anonymize them before sharing
php_dump openvpn.php '
    require_once("config.inc");
    require_once("openvpn.inc");
    echo "<?php\nreturn ", var_export([
        "servers" => openvpn_get_active_servers("multipoint"),
        "p2p_servers" => openvpn_get_active_servers("p2p"),
        "clients" => openvpn_get_active_clients(),
    ], true), ";\n";
'

# --- ipsec
# Only the fields used by check_pfsense: no pre-shared keys, no SPIs
php_dump ipsec.php '
    require_once("config.inc");
    require_once("ipsec.inc");
    require_once("service-utils.inc");
    $pick = fn ($a, $k) => array_intersect_key($a, array_flip($k));
    $p1 = []; $p2 = []; $sas = [];
    foreach (config_get_path("ipsec/phase1", []) as $e) {
        if (is_array($e)) { $p1[] = $pick($e, ["ikeid", "descr", "disabled", "mobile", "iketype", "splitconn", "remote-gateway"]); }
    }
    foreach (config_get_path("ipsec/phase2", []) as $e) {
        if (is_array($e)) { $p2[] = $pick($e, ["ikeid", "reqid", "descr", "disabled", "mobile", "mode"]); }
    }
    $list = ipsec_list_sa();
    foreach (is_array($list) ? $list : [] as $ike) {
        if (!is_array($ike)) { continue; }
        $sa = $pick($ike, ["con-id", "uniqueid", "version", "state", "remote-host"]);
        $sa["child-sas"] = [];
        foreach ((array) ($ike["child-sas"] ?? []) as $c) {
            if (is_array($c)) { $sa["child-sas"][] = $pick($c, ["name", "uniqueid", "state", "bytes-in", "bytes-out"]); }
        }
        $sas[] = $sa;
    }
    echo "<?php\nreturn ", var_export([
        "running" => (bool) get_service_status(["name" => "ipsec"]),
        "phase1" => $p1,
        "phase2" => $p2,
        "sas" => $sas,
    ], true), ";\n";
'

# --- carp
# Only the fields used by check_pfsense: the VIP password is never exported
php_dump carp.php '
    require_once("config.inc");
    require_once("interfaces.inc");
    require_once("pfsense-utils.inc");
    $vips = [];
    $ifconfig = [];
    foreach (config_get_path("virtualip/vip", []) as $vip) {
        if (!is_array($vip) || ($vip["mode"] ?? "") !== "carp") { continue; }
        $real = (string) get_real_interface($vip["interface"]);
        $vips[] = [
            "vhid" => (string) ($vip["vhid"] ?? ""),
            "interface" => (string) $vip["interface"],
            "interface_descr" => (string) convert_friendly_interface_to_friendly_descr($vip["interface"]),
            "real_interface" => $real,
            "subnet" => (string) ($vip["subnet"] ?? ""),
            "subnet_bits" => (string) ($vip["subnet_bits"] ?? ""),
            "descr" => (string) ($vip["descr"] ?? ""),
            "advskew" => (string) ($vip["advskew"] ?? ""),
        ];
        if ($real !== "" && !isset($ifconfig[$real])) {
            $ifconfig[$real] = shell_exec("/sbin/ifconfig " . escapeshellarg($real));
        }
    }
    echo "<?php\nreturn ", var_export([
        "vips" => $vips,
        "ifconfig" => $ifconfig,
        "sysctl" => shell_exec("/sbin/sysctl net.inet.carp.allow net.inet.carp.demotion"),
        "maintenance" => config_path_enabled("", "virtualip_carp_maintenancemode"),
    ], true), ";\n";
'

# --- states
cmd_dump pfctl_si.txt /sbin/pfctl -si
cmd_dump pfctl_sm.txt /sbin/pfctl -sm

cmd_dump functions.txt sh -c 'grep -n "^function get_gateways\|^function refresh_gateways\|^function get_gateway_status\|^function return_gateways_status\|^function get_dpinger_status\|^function get_services\|write_config" /etc/inc/gwlb.inc /etc/inc/service-utils.inc'

ARCHIVE="$OUT.tar.gz"
tar -czf "$ARCHIVE" -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo "Done: $ARCHIVE"
