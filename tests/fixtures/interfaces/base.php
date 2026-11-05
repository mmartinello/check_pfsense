<?php
/*
 * Interface data as returned by cp_collect_interfaces(): configuration,
 * ifconfig output (FreeBSD 14/15 format) and pfSense_get_interface_stats()
 * counters. Reconstructed; replace with data captured by
 * tools/collect_fixtures.sh when available.
 */
$ether = function (string $if, string $flags, string $status, string $media = 'Ethernet autoselect (1000baseT <full-duplex>)'): string {
    return implode("\n", [
        "{$if}: flags={$flags} metric 0 mtu 1500",
        "\toptions=4e507bb<RXCSUM,TXCSUM,VLAN_MTU,VLAN_HWTAGGING,JUMBO_MTU,VLAN_HWCSUM,TSO4,TSO6,LRO,VLAN_HWFILTER,VLAN_HWTSO,RXCSUM_IPV6,TXCSUM_IPV6,HWSTATS,MEXTPG>",
        "\tether 00:00:5e:00:53:10",
        "\tinet 192.0.2.10 netmask 0xffffff00 broadcast 192.0.2.255",
        "\tmedia: {$media}",
        "\tstatus: {$status}",
        "\tnd6 options=21<PERFORMNUD,AUTO_LINKLOCAL>",
    ]) . "\n";
};

return [
    'interfaces' => [
        [
            'name' => 'wan', 'descr' => 'WAN', 'hwif' => 'igb0', 'real' => 'igb0', 'enabled' => true,
            'ifconfig' => $ether('igb0', '1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP>', 'active'),
            'stats' => ['inerrs' => 0, 'outerrs' => 0, 'collisions' => 0],
        ],
        [
            'name' => 'lan', 'descr' => 'LAN', 'hwif' => 'igb1', 'real' => 'igb1', 'enabled' => true,
            'ifconfig' => $ether('igb1', '1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP>', 'active'),
            'stats' => ['inerrs' => 12, 'outerrs' => 3, 'collisions' => 0],
        ],
        [
            'name' => 'opt1', 'descr' => 'DMZ', 'hwif' => 'igb2', 'real' => 'igb2', 'enabled' => true,
            'ifconfig' => $ether('igb2', '1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP>', 'active'),
            'stats' => ['inerrs' => 0, 'outerrs' => 0, 'collisions' => 0],
        ],
        [
            // OpenVPN tun interface: no "status:" line
            'name' => 'opt2', 'descr' => 'VPN_M2M', 'hwif' => 'ovpns4', 'real' => 'ovpns4', 'enabled' => true,
            'ifconfig' => "ovpns4: flags=1008051<UP,POINTOPOINT,RUNNING,MULTICAST,LOWER_UP> metric 0 mtu 1500\n"
                . "\toptions=80000<LINKSTATE>\n\tinet 10.3.0.1 --> 10.3.0.2 netmask 0xffffff00\n"
                . "\tgroups: tun openvpn\n\tnd6 options=29<PERFORMNUD,IFDISABLED,AUTO_LINKLOCAL>\n\tOpened by PID 12345\n",
            'stats' => ['inerrs' => 0, 'outerrs' => 0, 'collisions' => 0],
        ],
        [
            'name' => 'opt3', 'descr' => 'SPARE', 'hwif' => 'igb3', 'real' => '', 'enabled' => false,
            'ifconfig' => null, 'stats' => null,
        ],
    ],
];
