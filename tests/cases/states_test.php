<?php
/*
 * Tests of the states subcommand (evaluation only, no pfSense needed).
 *
 * Fixtures: pfctl -si and pfctl -sm output in the FreeBSD 14/15 format;
 * replace with data captured by tools/collect_fixtures.sh when available.
 */

/** pfctl data with the current state entries and the states limit changed. */
function states_with(?int $states = null, ?int $limit = null, ?int $srcNodes = null): array
{
    $info = fixture('states/pfctl_si.txt');
    $limits = fixture('states/pfctl_sm.txt');
    if ($states !== null) {
        $info = preg_replace('/(State Table.*\n  current entries\s+)\d+/', '${1}' . $states, $info);
    }
    if ($srcNodes !== null) {
        $info = preg_replace('/(Source Tracking Table\n  current entries\s+)\d+/', '${1}' . $srcNodes, $info);
    }
    if ($limit !== null) {
        $limits = preg_replace('/^states(\s+hard limit\s+)\d+/m', 'states${1}' . $limit, $limits);
    }
    return ['info' => $info, 'limits' => $limits];
}

return [
    'parse pfctl -si' => function () {
        assert_same(['enabled' => true, 'states' => 1523, 'src_nodes' => 37], cp_parse_pfctl_info(fixture('states/pfctl_si.txt')));
        assert_same(['enabled' => false, 'states' => 5, 'src_nodes' => null], cp_parse_pfctl_info("Status: Disabled for 0 days\n\nState Table  Total  Rate\n  current entries   5\n"));
        assert_same(['enabled' => null, 'states' => null, 'src_nodes' => null], cp_parse_pfctl_info('garbage'));
    },

    'parse pfctl -sm' => function () {
        assert_same(
            ['states' => 400000, 'src-nodes' => 400000, 'frags' => 5000, 'table-entries' => 400000],
            cp_parse_pfctl_limits(fixture('states/pfctl_sm.txt'))
        );
    },

    'low usage is ok' => function () {
        $result = cp_evaluate_states(states_with(), options_for('states'));
        assert_same(CP_OK, $result->state);
        assert_same('states 0.4% used (1523/400000)', $result->message);
        $output = assert_valid_output($result);
        assert_contains("'states'=1523;;;0;400000 'states_usage'=0.38%;80;90;0;100", $output);
    },

    'default thresholds 80/90' => function () {
        assert_same(CP_OK, cp_evaluate_states(states_with(800, 1000), options_for('states'))->state, '80% is not over 80');
        assert_same(CP_WARNING, cp_evaluate_states(states_with(801, 1000), options_for('states'))->state);
        assert_same(CP_WARNING, cp_evaluate_states(states_with(900, 1000), options_for('states'))->state);
        $result = cp_evaluate_states(states_with(950, 1000), options_for('states'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('states 95% used (950/1000)', $result->message);
        assert_valid_output($result);
    },

    'custom thresholds, options before the subcommand' => function () {
        $data = states_with(600, 1000);
        assert_same(CP_WARNING, cp_evaluate_states($data, options_for('states', ['-w', '50', '-c', '70']))->state);
        assert_same(CP_CRITICAL, cp_evaluate_states($data, options_for('states', ['-w', '40', '-c', '55']))->state);
    },

    'full table' => function () {
        $result = cp_evaluate_states(states_with(1000, 1000), options_for('states'));
        assert_same(CP_CRITICAL, $result->state);
        assert_contains("'states_usage'=100%;80;90;0;100", assert_valid_output($result));
    },

    'source nodes' => function () {
        $result = cp_evaluate_states(states_with(null, null, 380000), options_for('states', ['--src-nodes']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('states 0.4% used (1523/400000), source nodes 95% used (380000/400000)', $result->message);
        $output = assert_valid_output($result);
        assert_contains("'src_nodes'=380000;;;0;400000 'src_nodes_usage'=95%;80;90;0;100", $output);

        $result = cp_evaluate_states(states_with(), options_for('states'));
        assert_not_contains('src_nodes', implode(' ', $result->perfdata), 'source nodes only with --src-nodes');
    },

    'pf disabled is critical' => function () {
        $data = states_with();
        $data['info'] = str_replace('Status: Enabled', 'Status: Disabled', $data['info']);
        $result = cp_evaluate_states($data, options_for('states'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('pf is disabled, states 0.4% used (1523/400000)', $result->message);
    },

    'malformed output is unknown' => function () {
        $result = cp_evaluate_states(['info' => 'garbage', 'limits' => fixture('states/pfctl_sm.txt')], options_for('states'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('pfctl -si', $result->message);

        $result = cp_evaluate_states(['info' => fixture('states/pfctl_si.txt'), 'limits' => "states hard limit 0\n"], options_for('states'));
        assert_same(CP_UNKNOWN, $result->state, 'a zero limit must not divide by zero');
        assert_contains('pfctl -sm', $result->message);

        $result = cp_evaluate_states(['info' => "State Table\n  current entries 5\n", 'limits' => "states hard limit 10\n"], options_for('states', ['--src-nodes']));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('source tracking', $result->message);
    },

    'incoherent thresholds are usage errors' => function () {
        assert_throws(CPUsageException::class, fn () => options_for('states', ['-w', '95']), 'greater than critical');
        assert_throws(CPUsageException::class, fn () => options_for('states', ['-w', '90', '-c', '80']), 'greater than critical');
    },
];
