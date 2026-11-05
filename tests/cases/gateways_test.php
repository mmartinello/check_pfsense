<?php
/*
 * Tests of the gateways subcommand (evaluation only, no pfSense needed).
 */

/** Base fixture with the dpinger status line of a gateway replaced. */
function gateways_with(array $raw = [], array $config = []): array
{
    $data = fixture('gateways/base.php');
    foreach ($raw as $name => $line) {
        if ($line === null) {
            unset($data['dpinger'][$name]);
        } else {
            $data['dpinger'][$name]['raw'] = $line;
        }
    }
    foreach ($config as $name => $values) {
        $data['gateways'][$name] = array_merge($data['gateways'][$name], $values);
    }
    return $data;
}

return [
    'all online' => function () {
        $result = cp_evaluate_gateways(fixture('gateways/base.php'), options_for('gateways'));
        assert_same(CP_OK, $result->state);
        assert_same('3/3 gateways online', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'WAN_DHCP_delay'=12.345ms;200;500;0", $output);
        assert_contains("'WAN_DHCP_loss'=0%;10;20;0;100", $output);
        assert_contains("'FIBER_GW_delay'=8.25ms;100;300;0", $output);
        assert_not_contains('LTE_GW_delay', $output, 'unmonitored gateways have no perfdata');
        assert_not_contains('Null4', $output);
        assert_contains('[OK] LTE_GW (LTE modem, LTE, gateway 192.0.2.1): online (unmonitored)', $output);
        assert_contains('monitor 1.1.1.1, default): online, delay 12.345ms', $output);
    },

    'latency over the gateway low threshold is warning' => function () {
        // 150 ms: over the FIBER_GW custom latencylow (100), below default
        $result = cp_evaluate_gateways(gateways_with(['FIBER_GW' => 'FIBER_GW 150000 900 0']), options_for('gateways'));
        assert_same(CP_WARNING, $result->state);
        assert_same('FIBER_GW online (latency) (delay 150ms, loss 0%); 3/3 gateways online', $result->message);
        assert_valid_output($result);
    },

    'packet loss over low threshold is warning' => function () {
        $result = cp_evaluate_gateways(gateways_with(['WAN_DHCP' => 'WAN_DHCP 12000 900 11']), options_for('gateways'));
        assert_same(CP_WARNING, $result->state);
        assert_contains('WAN_DHCP online (packet loss)', $result->message);
    },

    'loss equal to the threshold is not an alert' => function () {
        // dpinger compares with ">" (get_dpinger_status)
        $result = cp_evaluate_gateways(gateways_with(['WAN_DHCP' => 'WAN_DHCP 200000 900 10']), options_for('gateways'));
        assert_same(CP_OK, $result->state);
    },

    'high loss is critical and listed first' => function () {
        $data = gateways_with(
            ['WAN_DHCP' => 'WAN_DHCP 0 0 100', 'FIBER_GW' => 'FIBER_GW 150000 900 0']
        );
        $result = cp_evaluate_gateways($data, options_for('gateways'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same(
            'WAN_DHCP offline (packet loss) (delay 0ms, loss 100%), FIBER_GW online (latency) (delay 150ms, loss 0%); 2/3 gateways online',
            $result->message
        );
        assert_valid_output($result, true);
    },

    'high latency is critical' => function () {
        $result = cp_evaluate_gateways(gateways_with(['FIBER_GW' => 'FIBER_GW 300500 900 0']), options_for('gateways'));
        assert_same(CP_CRITICAL, $result->state);
        assert_contains('FIBER_GW offline (latency)', $result->message);
    },

    'high latency with action disabled is still critical, like the GUI' => function () {
        $data = gateways_with(['FIBER_GW' => 'FIBER_GW 300500 900 0'], ['FIBER_GW' => ['action_disable' => '']]);
        $result = cp_evaluate_gateways($data, options_for('gateways'));
        assert_same(CP_CRITICAL, $result->state);
    },

    'forced down is warning' => function () {
        $result = cp_evaluate_gateways(gateways_with([], ['FIBER_GW' => ['force_down' => '']]), options_for('gateways'));
        assert_same(CP_WARNING, $result->state);
        assert_contains('FIBER_GW offline (forced)', $result->message);
        assert_contains('2/3 gateways online', $result->message);
    },

    'monitor disabled wins over forced down, like get_gateway_status()' => function () {
        $result = cp_evaluate_gateways(gateways_with([], ['LTE_GW' => ['force_down' => '']]), options_for('gateways'));
        assert_same(CP_OK, $result->state);
    },

    'pending gateway is unknown by default' => function () {
        $result = cp_evaluate_gateways(gateways_with(['FIBER_GW' => null]), options_for('gateways'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('FIBER_GW pending (dpinger not running)', $result->message);
        assert_valid_output($result);
    },

    'pending state can be changed' => function () {
        $data = gateways_with(['FIBER_GW' => null]);
        assert_same(CP_CRITICAL, cp_evaluate_gateways($data, options_for('gateways', ['--pending', 'critical']))->state);
        assert_same(CP_OK, cp_evaluate_gateways($data, options_for('gateways', ['--pending=ok']))->state);
    },

    'dpinger initializing is pending' => function () {
        $data = fixture('gateways/base.php');
        $data['dpinger']['WAN_DHCP']['raw'] = 'WAN_DHCP 0 0 0';
        $data['dpinger']['WAN_DHCP']['error'] = 'dpinger is still initializing';
        $result = cp_evaluate_gateways($data, options_for('gateways'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('WAN_DHCP pending (dpinger is still initializing)', $result->message);
    },

    'malformed dpinger output is pending' => function () {
        $result = cp_evaluate_gateways(gateways_with(['WAN_DHCP' => 'garbage']), options_for('gateways'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('WAN_DHCP pending', $result->message);
    },

    'critical is worse than unknown' => function () {
        $data = gateways_with(['WAN_DHCP' => 'WAN_DHCP 0 0 100', 'FIBER_GW' => null]);
        $result = cp_evaluate_gateways($data, options_for('gateways'));
        assert_same(CP_CRITICAL, $result->state);
        assert_matches('/^WAN_DHCP offline .*, FIBER_GW pending/', $result->message);
    },

    'include and exclude filters' => function () {
        $data = gateways_with(['WAN_DHCP' => 'WAN_DHCP 0 0 100']);

        $result = cp_evaluate_gateways($data, options_for('gateways', ['--exclude', 'wan_*']));
        assert_same(CP_OK, $result->state);
        assert_same('2/2 gateways online', $result->message);

        $result = cp_evaluate_gateways($data, options_for('gateways', ['--include', 'FIBER*,LTE modem']));
        assert_same('2/2 gateways online', $result->message);

        $result = cp_evaluate_gateways($data, options_for('gateways', ['--include', 'nothing']));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('No gateways match', $result->message);
    },

    'no gateways' => function () {
        $result = cp_evaluate_gateways(['gateways' => [], 'dpinger' => []], options_for('gateways'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('No gateways found', $result->message);
    },

    'missing defaults use the pfSense defaults' => function () {
        $data = fixture('gateways/base.php');
        unset($data['defaults']);
        $data['dpinger']['WAN_DHCP']['raw'] = 'WAN_DHCP 250000 100 0';
        $result = cp_evaluate_gateways($data, options_for('gateways'));
        assert_same(CP_WARNING, $result->state);
    },

    'thresholds are not accepted' => function () {
        assert_throws(CPUsageException::class, fn () => options_for('gateways', ['-w', '10']), 'does not use -w');
    },

    'dpinger status line parsing' => function () {
        assert_same(['delay' => 12.345, 'stddev' => 2.1, 'loss' => 0.0], cp_dpinger_parse("WAN 12345 2100 0\n"));
        assert_same(null, cp_dpinger_parse('WAN 1 2'));
        assert_same(null, cp_dpinger_parse(null));
        assert_same(null, cp_dpinger_parse('WAN a b c'));
    },
];
