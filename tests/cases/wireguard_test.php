<?php
/*
 * Tests of the wireguard subcommand (evaluation only, no pfSense needed).
 */

/** Base fixture with the latest handshake of a peer (by public key prefix) replaced. */
function wireguard_with_handshake(string $keyPrefix, int $agoOrZero): array
{
    $data = fixture('wireguard/base.php');
    $lines = explode("\n", $data['dump']);
    foreach ($lines as &$line) {
        $fields = explode("\t", $line);
        if (count($fields) === 9 && str_starts_with($fields[1], $keyPrefix)) {
            $fields[5] = (string) ($agoOrZero === 0 ? 0 : $data['now'] - $agoOrZero);
            $line = implode("\t", $fields);
        }
    }
    $data['dump'] = implode("\n", $lines);
    return $data;
}

return [
    'never handshake is critical by default' => function () {
        $result = cp_evaluate_wireguard(fixture('wireguard/base.php'), options_for('wireguard'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('tun_wg0/laptop no handshake; 2/3 WireGuard peers OK', $result->message);
    },

    'all OK with --ignore-never' => function () {
        $result = cp_evaluate_wireguard(fixture('wireguard/base.php'), options_for('wireguard', ['--ignore-never']));
        assert_same(CP_OK, $result->state);
        assert_same('3/3 WireGuard peers OK', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'tun_wg0_alice_handshake_age'=45s;300;600;0 'tun_wg0_alice_rx'=1234567c;;;0 'tun_wg0_alice_tx'=7654321c;;;0", $output);
        assert_contains("'tun_wg1_site-b_handshake_age'=100s;300;600;0", $output);
        assert_contains('[OK] tun_wg0/alice (198.51.100.30:51820): last handshake 45s ago, rx 1.2 MiB, tx 7.3 MiB', $output);
        assert_contains('[OK] tun_wg0/laptop (no endpoint): no handshake yet', $output);
        assert_not_contains('old', $output, 'disabled tunnels and peers are not checked');
    },

    'handshake age thresholds' => function () {
        $o = options_for('wireguard', ['--ignore-never']);
        assert_same(CP_OK, cp_evaluate_wireguard(wireguard_with_handshake('UGVlclNpdGVC', 300), $o)->state, 'equal to warning is OK');
        $result = cp_evaluate_wireguard(wireguard_with_handshake('UGVlclNpdGVC', 301), $o);
        assert_same(CP_WARNING, $result->state);
        assert_same('tun_wg1/site-b last handshake 5m 1s ago; 2/3 WireGuard peers OK', $result->message);
        $result = cp_evaluate_wireguard(wireguard_with_handshake('UGVlclNpdGVC', 90000), $o);
        assert_same(CP_CRITICAL, $result->state);
        assert_contains('tun_wg1/site-b last handshake 1d 1h ago', $result->message);

        $o = options_for('wireguard', ['--ignore-never', '-w', '3600', '-c', '86400']);
        assert_same(CP_WARNING, cp_evaluate_wireguard(wireguard_with_handshake('UGVlclNpdGVC', 7200), $o)->state);
    },

    'tunnel not running is critical' => function () {
        $data = fixture('wireguard/base.php');
        $data['dump'] = implode("\n", array_filter(explode("\n", $data['dump']), fn ($l) => !str_starts_with($l, 'tun_wg1')));
        $result = cp_evaluate_wireguard($data, options_for('wireguard', ['--ignore-never']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('tun_wg1 not running; 2/2 WireGuard peers OK', $result->message);
    },

    'peer not loaded is critical' => function () {
        $data = fixture('wireguard/base.php');
        $data['dump'] = implode("\n", array_filter(explode("\n", $data['dump']), fn ($l) => !str_contains($l, 'UGVlckFsaWNl')));
        $result = cp_evaluate_wireguard($data, options_for('wireguard', ['--ignore-never']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('tun_wg0/alice not loaded; 2/3 WireGuard peers OK', $result->message);
    },

    'peer without description uses the key prefix' => function () {
        $data = wireguard_with_handshake('UGVlclNpdGVC', 700);
        $data['peers'][3]['descr'] = '';
        $result = cp_evaluate_wireguard($data, options_for('wireguard', ['--ignore-never']));
        assert_contains('tun_wg1/UGVlclNp last handshake', $result->message);
        assert_contains("'tun_wg1_UGVlclNp_handshake_age'=700s", assert_valid_output($result));
    },

    'include and exclude' => function () {
        $data = fixture('wireguard/base.php');
        assert_same('2/2 WireGuard peers OK', cp_evaluate_wireguard($data, options_for('wireguard', ['--exclude', 'laptop']))->message);
        assert_same('1/1 WireGuard peers OK', cp_evaluate_wireguard($data, options_for('wireguard', ['--include', 'Site B']))->message);
        assert_same('1/1 WireGuard peers OK', cp_evaluate_wireguard($data, options_for('wireguard', ['--include', 'UGVlckFsaWNl*']))->message);
        assert_same(CP_UNKNOWN, cp_evaluate_wireguard($data, options_for('wireguard', ['--include', 'nothing']))->state);
    },

    'not installed and no tunnels' => function () {
        $result = cp_evaluate_wireguard(['installed' => false], options_for('wireguard'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('WireGuard is not installed', $result->message);

        $data = fixture('wireguard/base.php');
        $data['tunnels'] = [['name' => 'tun_wg0', 'enabled' => 'no']];
        $result = cp_evaluate_wireguard($data, options_for('wireguard'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('No enabled WireGuard tunnels', $result->message);
    },

    'secrets are removed from the dump' => function () {
        $raw = "wg0\tPRIVATEKEY=\tPUBLICKEY=\t51820\toff\nwg0\tPEERKEY=\tPRESHARED=\t192.0.2.1:51820\t10.0.0.2/32\t0\t0\t0\toff\n";
        $clean = cp_wg_sanitize_dump($raw);
        assert_not_contains('PRIVATEKEY', $clean);
        assert_not_contains('PRESHARED', $clean);
        assert_contains("PUBLICKEY=\t51820", $clean);
        assert_contains("wg0\tPEERKEY=\t(hidden)\t192.0.2.1:51820", $clean);
    },

    'dump parsing and malformed lines' => function () {
        $parsed = cp_wg_parse_dump("garbage\nwg0\tk\tPUB\t51820\toff\nwg0\tPEER\tpsk\t(none)\t10.0.0.2/32\t1700000000\t10\t20\toff\n\n");
        assert_same(['wg0'], array_keys($parsed));
        assert_same(1700000000, $parsed['wg0']['peers']['PEER']['latest_handshake']);
        assert_same(20.0, $parsed['wg0']['peers']['PEER']['transfer_tx']);
        assert_same([], cp_wg_parse_dump(''));
    },

    'duration format' => function () {
        assert_same('0s', cp_format_duration(0));
        assert_same('59s', cp_format_duration(59));
        assert_same('1m 0s', cp_format_duration(60));
        assert_same('2h 5m', cp_format_duration(7500));
        assert_same('3d 0h', cp_format_duration(259200));
    },
];
