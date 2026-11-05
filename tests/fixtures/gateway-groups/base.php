<?php
/*
 * Gateway groups data as returned by cp_collect_gateway_groups(): the
 * gateway data captured on pfSense 2.8.1 (anonymized) plus two groups from
 * the configuration (gateways/gateway_group): a failover group (tier 1 and
 * 2) and a load balancing group (both on tier 1).
 *
 * The group items use the config format "<gateway>|<tier>|<vip>".
 */
$data = require __DIR__ . '/../gateways/pfsense-2.8.1.php';

$data['groups'] = [
    [
        'name' => 'WAN_FAILOVER',
        'descr' => 'FTTH with FTTC backup',
        'trigger' => 'downlosslatency',
        'item' => ['GW_WAN_1|1|address', 'GW_WAN_2|2|address'],
    ],
    [
        'name' => 'WAN_LB',
        'descr' => 'Load balancing',
        'trigger' => 'down',
        'item' => ['GW_WAN_1|1|address', 'GW_WAN_2|1|address'],
    ],
];

return $data;
