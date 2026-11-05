<?php
/*
 * OpenVPN data as returned by cp_collect_openvpn(), in the format of the
 * openvpn.inc functions of pfSense 2.9:
 *  - servers:     openvpn_get_active_servers('multipoint')
 *                 (openvpn_get_server_status(), "status 2")
 *  - p2p_servers: openvpn_get_active_servers('p2p')
 *                 (openvpn_get_client_status(), "state 1" + "status 2")
 *  - clients:     openvpn_get_active_clients()
 *
 * Values read from the management socket keep the trailing newline of the
 * last field, like the pfSense functions. Reconstructed from the source;
 * replace with data captured by tools/collect_fixtures.sh when available.
 */
return [
    'servers' => [
        [
            'port' => '1194',
            'mode' => 'server_tls_user',
            'name' => 'Road warriors UDP4:1194',
            'conns' => [
                [
                    'common_name' => 'alice', 'remote_host' => '198.51.100.20:51820', 'virtual_addr' => '10.8.0.2',
                    'virtual_addr6' => '', 'bytes_recv' => '1048576', 'bytes_sent' => '5242880',
                    'connect_time' => '2026-11-05 08:00:00', 'connect_time_unix' => '1793952000', 'user_name' => 'alice',
                    'client_id' => '3', 'peer_id' => '0', 'cipher' => "AES-256-GCM\n",
                ],
                [
                    'common_name' => 'bob', 'remote_host' => '192.0.2.77:40112', 'virtual_addr' => '10.8.0.3',
                    'virtual_addr6' => '', 'bytes_recv' => '2048', 'bytes_sent' => '4096',
                    'connect_time' => '2026-11-05 09:30:00', 'connect_time_unix' => '1793957400', 'user_name' => 'bob',
                    'client_id' => '4', 'peer_id' => '1', 'cipher' => "AES-256-GCM\n",
                ],
            ],
            'vpnid' => '1',
            'mgmt' => 'server1',
            'routes' => [
                ['virtual_addr' => '10.8.0.2', 'common_name' => 'alice', 'remote_host' => '198.51.100.20:51820', 'last_time' => '2026-11-05 10:00:00'],
                ['virtual_addr' => '10.8.0.3', 'common_name' => 'bob', 'remote_host' => '192.0.2.77:40112', 'last_time' => '2026-11-05 10:00:01'],
            ],
        ],
    ],
    'p2p_servers' => [
        [
            'port' => '1195',
            'mode' => 'p2p_tls',
            'name' => 'Site A UDP4:1195',
            'conns' => [],
            'vpnid' => '3',
            'mgmt' => 'server3',
            'connect_time' => 'Thu Nov 5 7:12:00 2026',
            'state' => 'CONNECTED',
            'state_detail' => 'SUCCESS',
            'virtual_addr' => '10.9.0.1',
            'remote_host' => '203.0.113.50',
            'remote_port' => '1195',
            'local_host' => '',
            'local_port' => '',
            'virtual_addr6' => '',
            'status' => 'Connected (Success)',
            'bytes_recv' => "73400320\n",
            'bytes_sent' => "10485760\n",
        ],
    ],
    'clients' => [
        [
            'port' => '',
            'name' => 'Site B UDP4',
            'vpnid' => '2',
            'mgmt' => 'client2',
            'status' => 'Connected (Success)',
            'connect_time' => 'Thu Nov 5 7:15:00 2026',
            'state' => 'CONNECTED',
            'state_detail' => 'SUCCESS',
            'virtual_addr' => '10.10.0.2',
            'remote_host' => '203.0.113.60',
            'remote_port' => '1194',
            'local_host' => '',
            'local_port' => '',
            'virtual_addr6' => '',
            'bytes_recv' => "1234567\n",
            'bytes_sent' => "7654321\n",
        ],
    ],
];
