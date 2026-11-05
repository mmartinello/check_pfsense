<?php
/*
 * Tests of the openvpn subcommand (evaluation only, no pfSense needed).
 */

/** The multipoint server as reported when the daemon does not answer. */
function openvpn_unreachable_server(): array
{
    return [
        'port' => '1194', 'mode' => 'server_tls_user', 'name' => 'Road warriors UDP4:1194', 'vpnid' => '1', 'mgmt' => 'server1',
        'conns' => [[
            'common_name' => '[error]', 'remote_host' => 'Unable to contact daemon', 'virtual_addr' => 'Service not running?',
            'bytes_recv' => 0, 'bytes_sent' => 0, 'connect_time' => 0,
        ]],
    ];
}

return [
    'all up' => function () {
        $result = cp_evaluate_openvpn(fixture('openvpn/base.php'), options_for('openvpn'));
        assert_same(CP_OK, $result->state);
        assert_same('3/3 OpenVPN instances OK', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'server1_clients'=2;;;0 'server1_bytes_in'=1050624c;;;0 'server1_bytes_out'=5246976c;;;0", $output);
        assert_contains("'client2_bytes_in'=1234567c;;;0 'client2_bytes_out'=7654321c;;;0", $output, 'trailing newlines are removed');
        assert_contains('[OK] server1 (Road warriors UDP4:1194): 2 clients connected, in 1 MiB, out 5 MiB (alice@198.51.100.20:51820, bob@192.0.2.77:40112)', $output);
        assert_contains('[OK] server3 (Site A UDP4:1195): up, remote 203.0.113.50, virtual address 10.9.0.1', $output);
        assert_contains('[OK] client2 (Site B UDP4): up, remote 203.0.113.60', $output);
    },

    'client reconnecting is critical' => function () {
        $data = fixture('openvpn/base.php');
        $data['clients'][0] = array_merge($data['clients'][0], ['state' => 'RECONNECTING', 'state_detail' => '', 'status' => 'TLS Error, reconnecting']);
        $result = cp_evaluate_openvpn($data, options_for('openvpn'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('client2 down (TLS Error, reconnecting); 2/3 OpenVPN instances OK', $result->message);
        assert_valid_output($result, true);
    },

    'client daemon not running is critical' => function () {
        $data = fixture('openvpn/base.php');
        $data['clients'][0] = [
            'port' => '', 'name' => 'Site B UDP4', 'vpnid' => '2', 'mgmt' => 'client2', 'status' => 'down',
            'remote_host' => 'Unable to contact daemon', 'virtual_addr' => 'Service not running?',
            'bytes_recv' => 0, 'bytes_sent' => 0, 'connect_time' => 0,
        ];
        $result = cp_evaluate_openvpn($data, options_for('openvpn'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('client2 daemon not responding; 2/3 OpenVPN instances OK', $result->message);
    },

    'p2p server waiting for the peer is critical' => function () {
        $data = fixture('openvpn/base.php');
        $data['p2p_servers'][0] = array_merge($data['p2p_servers'][0], ['state' => 'WAIT', 'state_detail' => '', 'status' => 'Waiting for response from peer']);
        $result = cp_evaluate_openvpn($data, options_for('openvpn'));
        assert_same(CP_CRITICAL, $result->state);
        assert_contains('server3 down (Waiting for response from peer)', $result->message);
    },

    'multipoint server not responding is critical' => function () {
        $data = fixture('openvpn/base.php');
        $data['servers'][0] = openvpn_unreachable_server();
        $result = cp_evaluate_openvpn($data, options_for('openvpn'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('server1 daemon not responding; 2/3 OpenVPN instances OK', $result->message);
        $output = assert_valid_output($result);
        assert_not_contains('server1_clients', $output, 'no clients perfdata for an unreachable server');
        assert_contains("'server1_bytes_in'=0c", $output);
    },

    'multipoint server without clients is OK without thresholds' => function () {
        $data = fixture('openvpn/base.php');
        $data['servers'][0]['conns'] = [];
        $result = cp_evaluate_openvpn($data, options_for('openvpn'));
        assert_same(CP_OK, $result->state);
        assert_contains("'server1_clients'=0;;;0", assert_valid_output($result));
    },

    'client thresholds and --min-clients' => function () {
        $data = fixture('openvpn/base.php');

        $result = cp_evaluate_openvpn($data, options_for('openvpn', ['--min-clients', '3']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('server1 2 clients (threshold 3:); 2/3 OpenVPN instances OK', $result->message);

        $result = cp_evaluate_openvpn($data, options_for('openvpn', ['-w', '5:', '--min-clients', '1']));
        assert_same(CP_WARNING, $result->state);
        assert_contains("'server1_clients'=2;5:;1:;0", assert_valid_output($result));

        $result = cp_evaluate_openvpn($data, options_for('openvpn', ['-w', '1:', '-c', '~:1']));
        assert_same(CP_CRITICAL, $result->state, 'ranges: critical with more than 1 client');

        assert_same(CP_OK, cp_evaluate_openvpn($data, options_for('openvpn', ['--min-clients', '2']))->state);
    },

    'invalid threshold combinations' => function () {
        assert_throws(CPUsageException::class, fn () => options_for('openvpn', ['--min-clients', '2', '-c', '1:']), 'cannot be used together');
        assert_throws(CPUsageException::class, fn () => options_for('openvpn', ['--min-clients', '5', '-w', '2:']), 'lower than critical');
        assert_throws(CPUsageException::class, fn () => options_for('openvpn', ['--min-clients', 'two']), 'requires an integer');
    },

    'mode filter' => function () {
        $data = fixture('openvpn/base.php');
        $data['clients'][0]['state'] = 'RECONNECTING';

        $result = cp_evaluate_openvpn($data, options_for('openvpn', ['--mode', 'server']));
        assert_same(CP_OK, $result->state);
        assert_same('2/2 OpenVPN instances OK', $result->message);

        $result = cp_evaluate_openvpn($data, options_for('openvpn', ['--mode', 'client']));
        assert_same(CP_CRITICAL, $result->state);
        assert_contains('0/1 OpenVPN instances OK', $result->message);
    },

    'include and exclude by id, vpnid and description' => function () {
        $data = fixture('openvpn/base.php');
        assert_same('1/1 OpenVPN instances OK', cp_evaluate_openvpn($data, options_for('openvpn', ['--include', 'client2']))->message);
        assert_same('1/1 OpenVPN instances OK', cp_evaluate_openvpn($data, options_for('openvpn', ['--include', '3']))->message);
        assert_same('2/2 OpenVPN instances OK', cp_evaluate_openvpn($data, options_for('openvpn', ['--include', 'site*']))->message);
        assert_same('2/2 OpenVPN instances OK', cp_evaluate_openvpn($data, options_for('openvpn', ['--exclude', 'Road*']))->message);
        assert_same(CP_UNKNOWN, cp_evaluate_openvpn($data, options_for('openvpn', ['--include', 'nothing']))->state);
    },

    'no instances and malformed data' => function () {
        $result = cp_evaluate_openvpn(['servers' => [], 'p2p_servers' => [], 'clients' => []], options_for('openvpn'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('No enabled OpenVPN instances found', $result->message);

        $result = cp_evaluate_openvpn(['servers' => ['garbage'], 'clients' => [['vpnid' => '9']]], options_for('openvpn'));
        assert_same(CP_CRITICAL, $result->state, 'a client without state did not answer');
        assert_same('client9 daemon not responding; 0/1 OpenVPN instances OK', $result->message);
    },

    'byte formatting' => function () {
        assert_same('0 B', cp_format_bytes(0));
        assert_same('1023 B', cp_format_bytes(1023));
        assert_same('1.5 KiB', cp_format_bytes(1536));
        assert_same('70 MiB', cp_format_bytes(73400320));
    },
];
