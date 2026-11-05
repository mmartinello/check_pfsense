<?php
/*
 * Tests of the services subcommand (evaluation only, no pfSense needed).
 */

/** Base fixture with some services changed, selected by service id. */
function services_with(array $changes): array
{
    $data = fixture('services/base.php');
    foreach ($data['services'] as &$service) {
        $id = cp_service_id($service);
        if (array_key_exists($id, $changes)) {
            $service = array_merge($service, $changes[$id]);
        }
    }
    return $data;
}

return [
    'all running, disabled services not checked' => function () {
        $result = cp_evaluate_services(fixture('services/base.php'), options_for('services'));
        assert_same(CP_OK, $result->state);
        assert_same('10/10 services running (1 disabled not checked)', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'running'=10;;;0;10 'stopped'=0;;;0;10", $output);
        assert_contains('[OK] openvpn_server1 (OpenVPN server: Road warriors): running', $output);
        assert_contains('[OK] captiveportal_guests (Captive Portal: guests (Guest WiFi)): running', $output);
        assert_not_contains('haproxy', $output);
    },

    'stopped enabled service is critical' => function () {
        $data = services_with(['unbound' => ['running' => false], 'openvpn_client2' => ['running' => false]]);
        $result = cp_evaluate_services($data, options_for('services'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('openvpn_client2 stopped, unbound stopped; 8/10 services running (1 disabled not checked)', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'stopped'=2;;;0;10", $output);
        assert_contains('[CRITICAL] unbound (DNS Resolver): stopped', $output);
    },

    'running service disabled in the config is still OK' => function () {
        $data = services_with(['haproxy' => ['running' => true]]);
        $result = cp_evaluate_services($data, options_for('services'));
        assert_same(CP_OK, $result->state);
        assert_same('11/11 services running', $result->message);
    },

    'service without enabled flag is treated as enabled' => function () {
        $data = ['services' => [['name' => 'custom', 'running' => false]]];
        $result = cp_evaluate_services($data, options_for('services'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('custom stopped; 0/1 services running', $result->message);
    },

    'include and exclude by id, name and description' => function () {
        $data = services_with(['unbound' => ['running' => false]]);

        $result = cp_evaluate_services($data, options_for('services', ['--exclude', 'unbound']));
        assert_same(CP_OK, $result->state);
        assert_same('9/9 services running (1 disabled not checked)', $result->message);

        $result = cp_evaluate_services($data, options_for('services', ['--include', 'openvpn']));
        assert_same('2/2 services running', $result->message, 'the service name matches all instances');

        $result = cp_evaluate_services($data, options_for('services', ['--include', 'openvpn_server*,dns*']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('unbound stopped; 1/2 services running', $result->message);

        $result = cp_evaluate_services($data, options_for('services', ['--include', 'nothing']));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('No services match', $result->message);
    },

    'only disabled services' => function () {
        $result = cp_evaluate_services(fixture('services/base.php'), options_for('services', ['--include', 'haproxy']));
        assert_same(CP_OK, $result->state);
        assert_same('No enabled services to check (1 disabled)', $result->message);
    },

    'duplicated ids get a suffix' => function () {
        $data = ['services' => [
            ['name' => 'pkgsvc', 'description' => 'B instance', 'running' => true, 'enabled' => true],
            ['name' => 'pkgsvc', 'description' => 'A instance', 'running' => false, 'enabled' => true],
        ]];
        $result = cp_evaluate_services($data, options_for('services', ['-v']));
        assert_same('pkgsvc stopped; 1/2 services running', $result->message);
        assert_same(['[CRITICAL] pkgsvc (A instance): stopped', '[OK] pkgsvc_2 (B instance): running'], $result->long);
    },

    'no services and malformed entries' => function () {
        assert_same(CP_UNKNOWN, cp_evaluate_services(['services' => []], options_for('services'))->state);
        assert_same(CP_UNKNOWN, cp_evaluate_services([], options_for('services'))->state);

        $data = ['services' => ['garbage', ['description' => 'no name'], ['name' => 'sshd', 'running' => true, 'enabled' => true]]];
        $result = cp_evaluate_services($data, options_for('services'));
        assert_same(CP_OK, $result->state);
        assert_same('1/1 services running', $result->message);
    },

    'service ids' => function () {
        assert_same('sshd', cp_service_id(['name' => 'sshd']));
        assert_same('openvpn_client3', cp_service_id(['name' => 'openvpn', 'mode' => 'client', 'vpnid' => '3']));
        assert_same('captiveportal_lan', cp_service_id(['name' => 'captiveportal', 'zone' => 'lan']));
        assert_same('openvpn', cp_service_id(['name' => 'openvpn']));
    },

    'thresholds are not accepted' => function () {
        assert_throws(CPUsageException::class, fn () => options_for('services', ['-c', '1']), 'does not use -c');
    },
];
