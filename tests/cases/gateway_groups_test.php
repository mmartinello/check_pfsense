<?php
/*
 * Tests of the gateway-groups subcommand (evaluation only, no pfSense needed).
 */

/** Base fixture with the dpinger status lines of some gateways replaced. */
function groups_with(array $raw = [], array $config = []): array
{
    $data = fixture('gateway-groups/base.php');
    foreach ($raw as $name => $line) {
        $data['dpinger'][$name]['raw'] = $line;
    }
    foreach ($config as $name => $values) {
        $data['gateways'][$name] = array_merge($data['gateways'][$name], $values);
    }
    return $data;
}

return [
    'all groups on the first tier' => function () {
        $result = cp_evaluate_gateway_groups(fixture('gateway-groups/base.php'), options_for('gateway-groups'));
        assert_same(CP_OK, $result->state);
        assert_same('2/2 gateway groups OK', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'WAN_FAILOVER_active_tier'=1;;;0 'WAN_FAILOVER_usable'=2;;;0;2", $output);
        assert_contains('[OK] WAN_FAILOVER (FTTH with FTTC backup), trigger downlosslatency: tier 1 active; tier 1: GW_WAN_1 online; tier 2: GW_WAN_2 online', $output);
    },

    'failover to the second tier' => function () {
        $result = cp_evaluate_gateway_groups(groups_with(['GW_WAN_1' => 'GW_WAN_1 0 0 100']), options_for('gateway-groups'));
        assert_same(CP_WARNING, $result->state);
        // WAN_LB has trigger "down": a gateway with high packet loss is still used
        assert_same(
            'WAN_FAILOVER failover to tier 2 (GW_WAN_1 offline (packet loss)); 1/2 gateway groups OK',
            $result->message
        );
        $output = assert_valid_output($result);
        assert_contains("'WAN_FAILOVER_active_tier'=2;;;0 'WAN_FAILOVER_usable'=1;;;0;2", $output);
    },

    'no usable members is critical' => function () {
        $data = groups_with(['GW_WAN_1' => 'GW_WAN_1 0 0 100', 'GW_WAN_2' => 'GW_WAN_2 0 0 100']);
        $result = cp_evaluate_gateway_groups($data, options_for('gateway-groups', ['--include', 'WAN_FAILOVER']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('WAN_FAILOVER has no usable members (GW_WAN_1 offline (packet loss), GW_WAN_2 offline (packet loss)); 0/1 gateway groups OK', $result->message);
        assert_contains("'WAN_FAILOVER_active_tier'=0;;;0 'WAN_FAILOVER_usable'=0;;;0;2", assert_valid_output($result));
    },

    'trigger level decides if a member is usable' => function () {
        // High loss: excluded with trigger downlosslatency, kept with trigger down
        $data = groups_with(['GW_WAN_2' => 'GW_WAN_2 5000 100 50']);
        $result = cp_evaluate_gateway_groups($data, options_for('gateway-groups'));
        assert_same(CP_WARNING, $result->state);
        assert_same('WAN_FAILOVER members down (GW_WAN_2 offline (packet loss)); 1/2 gateway groups OK', $result->message);

        // Trigger downloss: high latency is acceptable, high loss is not
        assert_true(cp_gateway_usable('offline_latency', 'downloss', false));
        assert_true(!cp_gateway_usable('offline_loss', 'downloss', false));
        assert_true(cp_gateway_usable('offline_loss', 'downlatency', false));
        assert_true(!cp_gateway_usable('offline_latency', 'downlatency', false));
        assert_true(!cp_gateway_usable('offline_latency', 'downlosslatency', false));
        assert_true(!cp_gateway_usable('offline', 'down', false));
        assert_true(!cp_gateway_usable('offline_forced', 'down', false));
        assert_true(!cp_gateway_usable('pending', 'down', false));
        assert_true(cp_gateway_usable('online_latency', 'downlosslatency', false), 'online with latency is usable');
        assert_true(cp_gateway_usable('online_forced', 'downlosslatency', false), 'unmonitored is usable');
    },

    'members with action disabled are always usable' => function () {
        $data = groups_with(['GW_WAN_1' => 'GW_WAN_1 0 0 100'], ['GW_WAN_1' => ['action_disable' => '']]);
        $result = cp_evaluate_gateway_groups($data, options_for('gateway-groups'));
        assert_same(CP_OK, $result->state);
        assert_true(cp_gateway_usable('pending', 'downlosslatency', true));
    },

    'forced down member' => function () {
        $data = groups_with([], ['GW_WAN_1' => ['force_down' => '']]);
        $result = cp_evaluate_gateway_groups($data, options_for('gateway-groups', ['--include', 'WAN_FAILOVER']));
        assert_same(CP_WARNING, $result->state);
        assert_same('WAN_FAILOVER failover to tier 2 (GW_WAN_1 offline (forced)); 0/1 gateway groups OK', $result->message);
    },

    'member not available (disabled gateway)' => function () {
        $data = fixture('gateway-groups/base.php');
        unset($data['gateways']['GW_WAN_2']);
        $result = cp_evaluate_gateway_groups($data, options_for('gateway-groups', ['--include', 'WAN_FAILOVER']));
        assert_same(CP_WARNING, $result->state);
        assert_same('WAN_FAILOVER members down (GW_WAN_2 unavailable); 0/1 gateway groups OK', $result->message);
    },

    'unknown or empty trigger uses the strictest level' => function () {
        $data = groups_with(['GW_WAN_1' => 'GW_WAN_1 600000 100 0']);
        $data['groups'][0]['trigger'] = '';
        $result = cp_evaluate_gateway_groups($data, options_for('gateway-groups', ['--include', 'WAN_FAILOVER']));
        assert_same(CP_WARNING, $result->state);
        assert_contains('failover to tier 2 (GW_WAN_1 offline (latency))', $result->message);
    },

    'malformed groups and items' => function () {
        $data = fixture('gateway-groups/base.php');
        $data['groups'][] = ['name' => 'BROKEN', 'item' => ['nopipe', '|1|x', 'GW_WAN_1|notier']];
        $data['groups'][] = 'garbage';
        $result = cp_evaluate_gateway_groups($data, options_for('gateway-groups'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('BROKEN has no valid members; 2/3 gateway groups OK', $result->message);
    },

    'no groups and filters' => function () {
        $data = fixture('gateway-groups/base.php');
        assert_same('No gateway groups match the include/exclude filters', cp_evaluate_gateway_groups($data, options_for('gateway-groups', ['--exclude', 'WAN*']))->message);
        $data['groups'] = [];
        $result = cp_evaluate_gateway_groups($data, options_for('gateway-groups'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('No gateway groups configured', $result->message);
    },
];
