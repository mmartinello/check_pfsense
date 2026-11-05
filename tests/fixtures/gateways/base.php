<?php
/*
 * Gateway data as returned by cp_collect_gateways():
 *  - gateways: return_gateways_array(false, false, false)
 *  - dpinger:  running_dpinger_processes() + status line of the sockets
 *              ("<name> <avg us> <stddev us> <loss %>")
 *  - defaults: return_dpinger_defaults()
 *
 * Reconstructed from the pfSense 2.9 source (gwlb.inc); replace with data
 * captured by tools/collect_fixtures.sh when available.
 */
return [
    'gateways' => [
        'WAN_DHCP' => [
            'dynamic' => true,
            'ipprotocol' => 'inet',
            'gateway' => '203.0.113.1',
            'interface' => 'igb0',
            'friendlyiface' => 'wan',
            'friendlyifdescr' => 'WAN',
            'name' => 'WAN_DHCP',
            'attribute' => 'system',
            'descr' => 'Interface WAN_DHCP Gateway',
            'monitor' => '192.0.2.53',
            'isdefaultgw' => true,
            'tiername' => 'Default (IPv4)',
        ],
        'FIBER_GW' => [
            'interface' => 'igb2',
            'gateway' => '198.51.100.1',
            'name' => 'FIBER_GW',
            'weight' => '1',
            'ipprotocol' => 'inet',
            'descr' => 'Fiber backup',
            'monitor' => '198.51.100.53',
            'latencylow' => '100',
            'latencyhigh' => '300',
            'losslow' => '5',
            'losshigh' => '15',
            'friendlyiface' => 'opt1',
            'friendlyifdescr' => 'FIBER',
            'attribute' => 0,
            'tiername' => '',
        ],
        'LTE_GW' => [
            'interface' => 'ue0',
            'gateway' => '192.0.2.1',
            'name' => 'LTE_GW',
            'weight' => '1',
            'ipprotocol' => 'inet',
            'descr' => 'LTE modem',
            'monitor_disable' => '',
            'friendlyiface' => 'opt2',
            'friendlyifdescr' => 'LTE',
            'attribute' => 1,
            'tiername' => '',
        ],
        'Null4' => [
            'name' => 'Null4',
            'interface' => 'lo0',
            'ipprotocol' => 'inet',
            'gateway' => '127.0.0.1',
            'attribute' => 'system',
        ],
    ],
    'dpinger' => [
        'WAN_DHCP' => [
            'srcip' => '203.0.113.20',
            'targetip' => '192.0.2.53',
            'raw' => 'WAN_DHCP 12345 2100 0',
            'error' => null,
        ],
        'FIBER_GW' => [
            'srcip' => '198.51.100.10',
            'targetip' => '198.51.100.53',
            'raw' => 'FIBER_GW 8250 900 0',
            'error' => null,
        ],
    ],
    'defaults' => [
        'latencylow' => '200',
        'latencyhigh' => '500',
        'losslow' => '10',
        'losshigh' => '20',
        'interval' => '500',
        'loss_interval' => '2000',
        'time_period' => '60000',
        'alert_interval' => '1000',
        'data_payload' => '1',
    ],
];
