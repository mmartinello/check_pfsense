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

# --- wireguard
# Only fields without secrets; private and preshared keys are removed from
# the wg dump (contains peer endpoints: anonymize them before sharing)
php_dump wireguard.php '
    require_once("config.inc");
    $pick = fn ($a, $k) => array_intersect_key($a, array_flip($k));
    $c = config_get_path("installedpackages/wireguard");
    $tunnels = []; $peers = []; $dump = null;
    if (is_array($c)) {
        foreach ((array) ($c["tunnels"]["item"] ?? []) as $t) {
            if (is_array($t)) { $tunnels[] = $pick($t, ["name", "enabled", "descr", "listenport", "publickey"]); }
        }
        foreach ((array) ($c["peers"]["item"] ?? []) as $p) {
            if (is_array($p)) { $peers[] = $pick($p, ["enabled", "tun", "descr", "endpoint", "port", "persistentkeepalive", "publickey"]); }
        }
    }
    if (is_executable("/usr/bin/wg")) {
        $lines = [];
        foreach (explode("\n", (string) shell_exec("/usr/bin/wg show all dump")) as $l) {
            $f = explode("\t", $l);
            if (count($f) === 5) { $f[1] = "(hidden)"; } elseif (count($f) === 9) { $f[2] = "(hidden)"; }
            $lines[] = implode("\t", $f);
        }
        $dump = implode("\n", $lines);
    }
    echo "<?php\nreturn ", var_export([
        "installed" => is_array($c) && is_executable("/usr/bin/wg"),
        "tunnels" => $tunnels, "peers" => $peers, "dump" => $dump, "now" => time(),
    ], true), ";\n";
'

# --- dns (queries the local resolver, like the check)
cmd_dump drill_ok.txt /usr/bin/drill @127.0.0.1 pfsense.org A
cmd_dump drill_aaaa.txt /usr/bin/drill @127.0.0.1 pfsense.org AAAA
cmd_dump drill_nxdomain.txt /usr/bin/drill @127.0.0.1 nonexistent.invalid A

# --- mbuf
cmd_dump netstat_m.txt /usr/bin/netstat -m

# --- updates
# Read-only: installed packages and the local copy of the repository
# catalog (-U: no catalog update, unlike the check)
cmd_dump pkg_query.txt /usr/local/sbin/pkg-static query -a '%n %v'
cmd_dump pkg_rquery.txt sh -c "/usr/local/sbin/pkg-static rquery -U '%n %v' \$(/usr/local/sbin/pkg-static query -a '%n' | grep -E '^pfSense(-vmware|-base.*|-kernel-.*|-pkg-.*)?\$')"

# --- interfaces
php_dump interfaces.php '
    require_once("config.inc");
    require_once("interfaces.inc");
    $result = [];
    foreach (config_get_path("interfaces", []) as $name => $c) {
        if (!is_array($c)) { continue; }
        $enabled = isset($c["enable"]);
        $real = $enabled ? (string) get_real_interface($name) : "";
        $ifconfig = null; $stats = null;
        if ($real !== "") {
            exec("/sbin/ifconfig " . escapeshellarg($real) . " 2>/dev/null", $out, $rc);
            $ifconfig = ($rc === 0) ? implode("\n", $out) . "\n" : null;
            unset($out);
            if ($ifconfig !== null && function_exists("pfSense_get_interface_stats")) {
                $stats = array_intersect_key((array) pfSense_get_interface_stats($real), array_flip(["inerrs", "outerrs", "collisions"]));
            }
        }
        $result[] = ["name" => (string) $name, "descr" => (string) ($c["descr"] ?? strtoupper($name)), "hwif" => (string) ($c["if"] ?? ""),
            "real" => $real, "enabled" => $enabled, "ifconfig" => $ifconfig, "stats" => $stats];
    }
    echo "<?php\nreturn ", var_export(["interfaces" => $result], true), ";\n";
'

# --- certificates
# Only validity dates, subject CN, description and usage: no certificate
# and no private key (descriptions and CNs may need anonymizing)
php_dump certificates.php '
    require_once("config.inc");
    require_once("certs.inc");
    require_once("pfsense-utils.inc");
    require_once("pkg-utils.inc");
    $pkg = [];
    foreach ((array) pkg_call_plugins("plugin_certificates", ["type" => "certificates", "event" => "used_certificates"]) as $n => $i) {
        foreach (array_keys((array) ($i["certificatelist"] ?? [])) as $r) { $pkg[$r][] = $i["pkgname"] ?? $n; }
    }
    $checks = [
        "cert" => ["is_webgui_cert" => "WebGUI", "is_user_cert" => "user", "is_openvpn_server_cert" => "OpenVPN server",
            "is_openvpn_client_cert" => "OpenVPN client", "is_ipsec_cert" => "IPsec", "is_captiveportal_cert" => "Captive Portal",
            "is_unbound_cert" => "DNS Resolver", "is_kea_cert" => "Kea DHCP HA"],
        "ca" => ["is_openvpn_server_ca" => "OpenVPN server", "is_openvpn_client_ca" => "OpenVPN client",
            "is_ipsec_peer_ca" => "IPsec", "is_ldap_peer_ca" => "LDAP"],
    ];
    $out = [];
    foreach (["cert", "ca"] as $t) {
        foreach (config_get_path($t, []) as $e) {
            if (!is_array($e) || empty($e["refid"])) { continue; }
            $used = [];
            foreach ($checks[$t] as $f => $l) { if (function_exists($f) && $f($e["refid"])) { $used[] = $l; } }
            if ($t === "cert") { $used = array_merge($used, $pkg[$e["refid"]] ?? []); }
            $d = !empty($e["crt"]) ? @openssl_x509_parse(base64_decode($e["crt"])) : false;
            $out[] = ["type" => $t, "refid" => $e["refid"], "descr" => $e["descr"] ?? "",
                "subject" => is_array($d) ? (string) ($d["subject"]["CN"] ?? "") : "",
                "valid_from" => is_array($d) ? $d["validFrom_time_t"] : null,
                "valid_to" => is_array($d) ? $d["validTo_time_t"] : null,
                "has_crt" => !empty($e["crt"]), "used_by" => array_values(array_unique($used))];
        }
    }
    echo "<?php\nreturn ", var_export(["certificates" => $out, "now" => time()], true), ";\n";
'

# --- ntp
cmd_dump ntpq.txt /usr/local/sbin/ntpq -pnw

# --- notices (copied as is, get_notices() is not called; messages may
# contain names and addresses to anonymize)
[ -f /tmp/notices/notices.serial ] && cp /tmp/notices/notices.serial "$OUT/notices.serial"

# --- crash (file list only, not the crash dumps)
cmd_dump crash_files.txt ls -la /var/crash
cmd_dump php_errors_tail.txt sh -c 'ls -l /tmp/PHP_errors.log && tail -n 20 /tmp/PHP_errors.log'

# --- temperature
cmd_dump sysctl_temperature.txt /sbin/sysctl -q dev.cpu hw.acpi.thermal

# --- zfs
cmd_dump zpool_list.txt /sbin/zpool list -H -p -o name,size,allocated,free,capacity,fragmentation,health
cmd_dump zpool_status.txt /sbin/zpool status -x

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
