<?php
/*
 * Service data as returned by cp_collect_services(): get_services() with
 * get_service_status() (running) and is_service_enabled() (enabled).
 *
 * Reconstructed from the pfSense 2.9 source (service-utils.inc); replace
 * with data captured by tools/collect_fixtures.sh when available.
 */
return [
    'services' => [
        ['name' => 'nrpe', 'description' => 'Nagios NRPE daemon', 'mode' => null, 'vpnid' => null, 'zone' => null, 'running' => true, 'enabled' => true],
        ['name' => 'unbound', 'description' => 'DNS Resolver', 'mode' => null, 'vpnid' => null, 'zone' => null, 'running' => true, 'enabled' => true],
        ['name' => 'ntpd', 'description' => 'NTP clock sync', 'mode' => null, 'vpnid' => null, 'zone' => null, 'running' => true, 'enabled' => true],
        ['name' => 'syslogd', 'description' => 'System Logger Daemon', 'mode' => null, 'vpnid' => null, 'zone' => null, 'running' => true, 'enabled' => true],
        ['name' => 'kea-dhcp4', 'description' => 'Kea DHCP Server', 'mode' => null, 'vpnid' => null, 'zone' => null, 'running' => true, 'enabled' => true],
        ['name' => 'dpinger', 'description' => 'Gateway Monitoring Daemon', 'mode' => null, 'vpnid' => null, 'zone' => null, 'running' => true, 'enabled' => true],
        ['name' => 'sshd', 'description' => 'Secure Shell Daemon', 'mode' => null, 'vpnid' => null, 'zone' => null, 'running' => true, 'enabled' => true],
        ['name' => 'openvpn', 'description' => 'OpenVPN server: Road warriors', 'mode' => 'server', 'vpnid' => '1', 'zone' => null, 'running' => true, 'enabled' => true],
        ['name' => 'openvpn', 'description' => 'OpenVPN client: Site B', 'mode' => 'client', 'vpnid' => '2', 'zone' => null, 'running' => true, 'enabled' => true],
        ['name' => 'captiveportal', 'description' => 'Captive Portal: guests (Guest WiFi)', 'mode' => null, 'vpnid' => null, 'zone' => 'guests', 'running' => true, 'enabled' => true],
        // Package with the service disabled in its configuration
        ['name' => 'haproxy', 'description' => 'TCP/HTTP(S) Load Balancer', 'mode' => null, 'vpnid' => null, 'zone' => null, 'running' => false, 'enabled' => false],
    ],
];
