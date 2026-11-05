<?php
/*
 * CARP data as returned by cp_collect_carp() on the primary node of a
 * cluster: three CARP VIPs (WAN vhid 1 and 10, LAN vhid 2), all MASTER.
 *
 * The ifconfig and sysctl outputs follow the FreeBSD 14/15 format; replace
 * with data captured by tools/collect_fixtures.sh when available.
 */
return [
    'vips' => [
        [
            'vhid' => '1', 'interface' => 'wan', 'interface_descr' => 'WAN', 'real_interface' => 'vtnet0',
            'subnet' => '203.0.113.1', 'subnet_bits' => '24', 'descr' => 'WAN CARP', 'advskew' => '0',
        ],
        [
            'vhid' => '10', 'interface' => 'wan', 'interface_descr' => 'WAN', 'real_interface' => 'vtnet0',
            'subnet' => '203.0.113.10', 'subnet_bits' => '24', 'descr' => 'Web server', 'advskew' => '0',
        ],
        [
            'vhid' => '2', 'interface' => 'lan', 'interface_descr' => 'LAN', 'real_interface' => 'vtnet1',
            'subnet' => '10.0.0.1', 'subnet_bits' => '24', 'descr' => 'LAN gateway', 'advskew' => '0',
        ],
    ],
    'ifconfig' => [
        'vtnet0' => file_get_contents(__DIR__ . '/ifconfig_vtnet0.txt'),
        'vtnet1' => file_get_contents(__DIR__ . '/ifconfig_vtnet1.txt'),
    ],
    'sysctl' => file_get_contents(__DIR__ . '/sysctl.txt'),
    'maintenance' => false,
];
