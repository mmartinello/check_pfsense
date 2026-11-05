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

ARCHIVE="$OUT.tar.gz"
tar -czf "$ARCHIVE" -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo "Done: $ARCHIVE"
