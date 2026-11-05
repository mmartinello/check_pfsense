<?php
/*
 * Certificate data as returned by cp_collect_certificates(): one entry per
 * certificate and CA of the configuration, with the validity read from the
 * certificate and the usage (read-only checks of certs.inc and the
 * certificates used by packages). Private keys are never collected.
 *
 * Validity dates are relative to "now".
 */
$now = 1793960000;
$day = 86400;

return [
    'now' => $now,
    'certificates' => [
        [
            'type' => 'cert', 'refid' => '5f1a2b3c4d5e6', 'descr' => 'webConfigurator', 'subject' => 'fw.example.com',
            'valid_from' => $now - 300 * $day, 'valid_to' => $now + 200 * $day + 3600, 'has_crt' => true,
            'used_by' => ['WebGUI'],
        ],
        [
            'type' => 'cert', 'refid' => '6a7b8c9d0e1f2', 'descr' => 'OpenVPN server', 'subject' => 'vpn.example.com',
            'valid_from' => $now - 700 * $day, 'valid_to' => $now + 90 * $day + 3600, 'has_crt' => true,
            'used_by' => ['OpenVPN server'],
        ],
        [
            'type' => 'cert', 'refid' => '7b8c9d0e1f2a3', 'descr' => 'www.example.com', 'subject' => 'www.example.com',
            'valid_from' => $now - 60 * $day, 'valid_to' => $now + 45 * $day + 3600, 'has_crt' => true,
            'used_by' => ['HAProxy'],
        ],
        [
            'type' => 'cert', 'refid' => '8c9d0e1f2a3b4', 'descr' => 'old test', 'subject' => 'test.example.com',
            'valid_from' => $now - 400 * $day, 'valid_to' => $now - 35 * $day, 'has_crt' => true,
            'used_by' => [],
        ],
        [
            // Signing request: no certificate yet
            'type' => 'cert', 'refid' => '9d0e1f2a3b4c5', 'descr' => 'pending csr', 'subject' => '',
            'valid_from' => null, 'valid_to' => null, 'has_crt' => false, 'used_by' => [],
        ],
        [
            'type' => 'ca', 'refid' => 'a0e1f2a3b4c5d', 'descr' => 'Internal CA', 'subject' => 'Internal CA',
            'valid_from' => $now - 700 * $day, 'valid_to' => $now + 3000 * $day, 'has_crt' => true,
            'used_by' => ['OpenVPN server'],
        ],
    ],
];
