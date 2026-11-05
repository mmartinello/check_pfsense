<?php
/*
 * WireGuard data as returned by cp_collect_wireguard(): tunnels and peers
 * of installedpackages/wireguard (fields without secrets) and the output
 * of "wg show all dump" with private and preshared keys hidden.
 *
 * Keys are fake. Dump format (tab separated):
 *   interface: <if> <private key> <public key> <listen port> <fwmark>
 *   peer:      <if> <public key> <preshared key> <endpoint> <allowed ips>
 *              <latest handshake> <rx bytes> <tx bytes> <keepalive>
 */
$now = 1793960000;

$dump = implode("\n", [
    implode("\t", ['tun_wg0', '(hidden)', 'U2VydmVyMFB1YmxpY0tleUZha2VGYWtlRmFrZUZha2U=', '51820', 'off']),
    implode("\t", ['tun_wg0', 'UGVlckFsaWNlRmFrZUtleUZha2VGYWtlRmFrZUZha2U=', '(hidden)', '198.51.100.30:51820', '10.6.210.2/32', (string) ($now - 45), '1234567', '7654321', '25']),
    implode("\t", ['tun_wg0', 'UGVlckxhcHRvcEZha2VLZXlGYWtlRmFrZUZha2VGYWs=', '(hidden)', '(none)', '10.6.210.3/32', '0', '0', '0', 'off']),
    implode("\t", ['tun_wg1', '(hidden)', 'U2VydmVyMVB1YmxpY0tleUZha2VGYWtlRmFrZUZha2U=', '51821', 'off']),
    implode("\t", ['tun_wg1', 'UGVlclNpdGVCRmFrZUtleUZha2VGYWtlRmFrZUZha2U=', '(hidden)', '203.0.113.70:51821', '10.7.0.2/32,192.168.20.0/24', (string) ($now - 100), '99999', '88888', '25']),
]) . "\n";

return [
    'installed' => true,
    'tunnels' => [
        ['name' => 'tun_wg0', 'enabled' => 'yes', 'descr' => 'Road warriors', 'listenport' => '51820', 'publickey' => 'U2VydmVyMFB1YmxpY0tleUZha2VGYWtlRmFrZUZha2U='],
        ['name' => 'tun_wg1', 'enabled' => 'yes', 'descr' => 'Site B', 'listenport' => '51821', 'publickey' => 'U2VydmVyMVB1YmxpY0tleUZha2VGYWtlRmFrZUZha2U='],
        ['name' => 'tun_wg2', 'enabled' => 'no', 'descr' => 'Old tunnel', 'listenport' => '51822', 'publickey' => 'T2xkVHVubmVsRmFrZUtleUZha2VGYWtlRmFrZUZha2U='],
    ],
    'peers' => [
        ['enabled' => 'yes', 'tun' => 'tun_wg0', 'descr' => 'alice', 'endpoint' => '', 'port' => '', 'persistentkeepalive' => '25', 'publickey' => 'UGVlckFsaWNlRmFrZUtleUZha2VGYWtlRmFrZUZha2U='],
        ['enabled' => 'yes', 'tun' => 'tun_wg0', 'descr' => 'laptop', 'endpoint' => '', 'port' => '', 'persistentkeepalive' => '', 'publickey' => 'UGVlckxhcHRvcEZha2VLZXlGYWtlRmFrZUZha2VGYWs='],
        ['enabled' => 'no', 'tun' => 'tun_wg0', 'descr' => 'old phone', 'endpoint' => '', 'port' => '', 'persistentkeepalive' => '', 'publickey' => 'T2xkUGhvbmVGYWtlS2V5RmFrZUZha2VGYWtlRmFrZUY='],
        ['enabled' => 'yes', 'tun' => 'tun_wg1', 'descr' => 'site-b', 'endpoint' => '203.0.113.70', 'port' => '51821', 'persistentkeepalive' => '25', 'publickey' => 'UGVlclNpdGVCRmFrZUtleUZha2VGYWtlRmFrZUZha2U='],
        ['enabled' => 'yes', 'tun' => 'tun_wg2', 'descr' => 'old site', 'endpoint' => '192.0.2.99', 'port' => '51822', 'persistentkeepalive' => '', 'publickey' => 'T2xkU2l0ZUZha2VLZXlGYWtlRmFrZUZha2VGYWtlRmE='],
    ],
    'dump' => $dump,
    'now' => $now,
];
