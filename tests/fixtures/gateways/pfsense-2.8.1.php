<?php
/*
 * Gateway data captured on pfSense 2.8.1-RELEASE (PHP 8.3.19) with
 * tools/collect_fixtures.sh, anonymized: public IP addresses replaced
 * with documentation addresses (RFC 5737) and provider names removed.
 *
 * Gateways: OpenVPN_AS (monitored, DMZ), VPN_M2M_VPNV4 (dynamic, monitor
 * disabled), GW_WAN_2 (tier 2), GW_WAN_1 (tier 1, default).
 */
return [
    'gateways' => [
        'OpenVPN_AS' => [
            'interface' => 'vtnet7',
            'gateway' => '10.1.7.10',
            'name' => 'OpenVPN_AS',
            'weight' => '1',
            'ipprotocol' => 'inet',
            'descr' => 'OpenVPN Access Server',
            'monitor' => '10.1.7.10',
            'friendlyiface' => 'opt1',
            'friendlyifdescr' => 'DMZ',
            'attribute' => 0,
            'tiername' => '',
        ],
        'VPN_M2M_VPNV4' => [
            'interface' => 'ovpns4',
            'gateway' => '10.3.0.1',
            'name' => 'VPN_M2M_VPNV4',
            'weight' => '1',
            'ipprotocol' => 'inet',
            'descr' => 'Interface VPN_M2M_VPNV4 Gateway',
            'monitor_disable' => true,
            'dynamic' => true,
            'friendlyiface' => 'opt5',
            'friendlyifdescr' => 'VPN_M2M',
            'attribute' => 1,
            'tiername' => '',
        ],
        'GW_WAN_2' => [
            'interface' => 'vtnet10',
            'gateway' => '192.168.158.6',
            'name' => 'GW_WAN_2',
            'weight' => '1',
            'ipprotocol' => 'inet',
            'descr' => 'WAN 2 gateway',
            'gw_down_kill_states' => '',
            'monitor' => '198.51.100.53',
            'friendlyiface' => 'opt14',
            'friendlyifdescr' => 'WAN_2',
            'attribute' => 2,
            'tiername' => 'Tier 2 (IPv4)',
        ],
        'GW_WAN_1' => [
            'interface' => 'vtnet12',
            'gateway' => '203.0.113.153',
            'name' => 'GW_WAN_1',
            'weight' => '1',
            'ipprotocol' => 'inet',
            'descr' => 'WAN 1 gateway',
            'gw_down_kill_states' => '',
            'monitor' => '192.0.2.53',
            'friendlyiface' => 'opt12',
            'friendlyifdescr' => 'WAN_1',
            'isdefaultgw' => true,
            'attribute' => 3,
            'tiername' => 'Tier 1 (IPv4)',
        ],
    ],
    'dpinger' => [
        'GW_WAN_2' => [
            'srcip' => '192.168.158.1',
            'targetip' => '198.51.100.53',
            'raw' => 'GW_WAN_2 11239 271 0',
            'error' => null,
        ],
        'GW_WAN_1' => [
            'srcip' => '203.0.113.154',
            'targetip' => '192.0.2.53',
            'raw' => 'GW_WAN_1 4941 182 0',
            'error' => null,
        ],
        'OpenVPN_AS' => [
            'srcip' => '10.1.7.251',
            'targetip' => '10.1.7.10',
            'raw' => 'OpenVPN_AS 307 54 0',
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
