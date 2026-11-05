<?php
/*
 * IPsec data as returned by cp_collect_ipsec(): selected fields of the
 * phase 1/phase 2 configuration and of ipsec_list_sa() (VICI list-sas),
 * with the field names used by status_ipsec.php.
 *
 *  - con1: IKEv2 without split connections, one child SA for both P2
 *  - con2: IKEv1, one child SA per P2 (con2_<reqid>), one P2 disabled
 *  - con3: disabled, con4: mobile clients (both not checked)
 *
 * Reconstructed from the pfSense 2.9 source; replace with data captured by
 * tools/collect_fixtures.sh when available.
 */
return [
    'running' => true,
    'phase1' => [
        ['ikeid' => '1', 'descr' => 'Site A', 'iketype' => 'ikev2', 'remote-gateway' => '198.51.100.1'],
        ['ikeid' => '2', 'descr' => 'Site B', 'iketype' => 'ikev1', 'remote-gateway' => '203.0.113.9'],
        ['ikeid' => '3', 'descr' => 'Old site', 'iketype' => 'ikev2', 'splitconn' => '', 'remote-gateway' => '192.0.2.200', 'disabled' => ''],
        ['ikeid' => '4', 'descr' => 'Mobile clients', 'iketype' => 'ikev2', 'mobile' => '', 'remote-gateway' => ''],
    ],
    'phase2' => [
        ['ikeid' => '1', 'reqid' => '1', 'descr' => 'LAN A', 'mode' => 'tunnel'],
        ['ikeid' => '1', 'reqid' => '2', 'descr' => 'DMZ A', 'mode' => 'tunnel'],
        ['ikeid' => '2', 'reqid' => '3', 'descr' => 'LAN B', 'mode' => 'tunnel'],
        ['ikeid' => '2', 'reqid' => '4', 'descr' => 'VoIP B', 'mode' => 'tunnel'],
        ['ikeid' => '2', 'reqid' => '5', 'descr' => 'Old network B', 'mode' => 'tunnel', 'disabled' => ''],
        ['ikeid' => '3', 'reqid' => '6', 'descr' => 'Old LAN', 'mode' => 'tunnel'],
        ['ikeid' => '4', 'reqid' => '7', 'descr' => 'Mobile', 'mode' => 'tunnel', 'mobile' => ''],
    ],
    'sas' => [
        [
            'con-id' => 'con1', 'uniqueid' => '10', 'version' => '2', 'state' => 'ESTABLISHED', 'remote-host' => '198.51.100.1',
            'child-sas' => [
                ['name' => 'con1', 'uniqueid' => '21', 'state' => 'INSTALLED', 'bytes-in' => '1048576', 'bytes-out' => '2097152'],
            ],
        ],
        [
            'con-id' => 'con2', 'uniqueid' => '11', 'version' => '1', 'state' => 'ESTABLISHED', 'remote-host' => '203.0.113.9',
            'child-sas' => [
                ['name' => 'con2_3', 'uniqueid' => '22', 'state' => 'INSTALLED', 'bytes-in' => '1000', 'bytes-out' => '2000'],
                ['name' => 'con2_4', 'uniqueid' => '23', 'state' => 'INSTALLED', 'bytes-in' => '300', 'bytes-out' => '400'],
            ],
        ],
        [
            // Mobile client SA: ignored
            'con-id' => 'con-mobile', 'uniqueid' => '12', 'version' => '2', 'state' => 'ESTABLISHED', 'remote-host' => '192.0.2.50',
            'child-sas' => [['name' => 'con-mobile', 'uniqueid' => '24', 'state' => 'INSTALLED', 'bytes-in' => '5', 'bytes-out' => '6']],
        ],
    ],
];
