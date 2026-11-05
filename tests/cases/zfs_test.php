<?php
/*
 * Tests of the zfs subcommand (evaluation only, no pfSense needed).
 *
 * Fixtures: zpool list -H -p and zpool status -x output (OpenZFS 2.x);
 * replace with output captured by tools/collect_fixtures.sh when available.
 */

function zfs(string $status = 'zpool_status_healthy.txt', ?string $list = null): array
{
    return ['installed' => true, 'list' => $list ?? fixture('zfs/zpool_list.txt'), 'status' => fixture("zfs/{$status}")];
}

return [
    'healthy pool' => function () {
        $result = cp_evaluate_zfs(zfs(), options_for('zfs'));
        assert_same(CP_OK, $result->state);
        assert_same('pfSense 10% used (3 GiB of 28 GiB), ONLINE', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'pfSense_capacity'=10%;80;90;0;100 'pfSense_allocated'=3221225472B;;;0;30064771072 'pfSense_fragmentation'=4%;;;0;100", $output);
        assert_contains('[OK] pfSense: ONLINE, 10% used (3 GiB of 28 GiB), fragmentation 4%', $output);
    },

    'capacity thresholds' => function () {
        $list = "pfSense\t100\t85\t15\t85\t30\tONLINE\n";
        assert_same(CP_WARNING, cp_evaluate_zfs(zfs('zpool_status_healthy.txt', $list), options_for('zfs'))->state);
        $list = "pfSense\t100\t95\t5\t95\t30\tONLINE\n";
        $result = cp_evaluate_zfs(zfs('zpool_status_healthy.txt', $list), options_for('zfs'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('pfSense 95% used (95 B of 100 B); 0/1 ZFS pools OK', $result->message);
    },

    'degraded pool is critical' => function () {
        $list = "pfSense\t30064771072\t3221225472\t26843545600\t10\t4\tDEGRADED\n";
        $result = cp_evaluate_zfs(zfs('zpool_status_degraded.txt', $list), options_for('zfs'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('pfSense DEGRADED (One or more devices could not be opened. Sufficient replicas exist for the pool to continue functioning in a degraded...); 0/1 ZFS pools OK', $result->message);
        assert_valid_output($result, true);
    },

    'online pool with data errors is a warning' => function () {
        $result = cp_evaluate_zfs(zfs('zpool_status_errors.txt'), options_for('zfs'));
        assert_same(CP_WARNING, $result->state);
        assert_matches('/^pfSense has errors \(One or more devices has experienced an error resulting in data corruption\./', $result->message);
        assert_contains("Errors: 1 data errors, use '-v' for a list", implode("\n", $result->long));
    },

    'several pools and filters' => function () {
        $list = "pfSense\t100\t10\t90\t10\t1\tONLINE\ndata\t1000\t950\t50\t95\t20\tONLINE\n";
        $result = cp_evaluate_zfs(zfs('zpool_status_healthy.txt', $list), options_for('zfs'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('data 95% used (950 B of 1000 B); 1/2 ZFS pools OK', $result->message);
        assert_same(CP_OK, cp_evaluate_zfs(zfs('zpool_status_healthy.txt', $list), options_for('zfs', ['--exclude', 'data']))->state);
        assert_same(CP_UNKNOWN, cp_evaluate_zfs(zfs('zpool_status_healthy.txt', $list), options_for('zfs', ['--include', 'nothing']))->state);
    },

    'no pools (UFS) and no zpool' => function () {
        $result = cp_evaluate_zfs(['installed' => true, 'list' => '', 'status' => "no pools available\n"], options_for('zfs'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('No ZFS pools found (UFS installation?)', $result->message);

        $result = cp_evaluate_zfs(['installed' => false], options_for('zfs'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('ZFS is not available', $result->message);
    },

    'parsing: missing fragmentation and capacity' => function () {
        $pools = cp_parse_zpool_list("pfSense\t200\t50\t150\t-\t-\tONLINE\ngarbage\n");
        assert_same(1, count($pools));
        assert_same(null, $pools[0]['capacity']);
        assert_same(null, $pools[0]['fragmentation']);
        $result = cp_evaluate_zfs(zfs('zpool_status_healthy.txt', "pfSense\t200\t50\t150\t-\t-\tONLINE\n"), options_for('zfs'));
        assert_contains("'pfSense_capacity'=25%", assert_valid_output($result), 'capacity computed from size');
        assert_not_contains('fragmentation', implode(' ', $result->perfdata));
    },

    'status parsing' => function () {
        assert_same([], cp_parse_zpool_status(fixture('zfs/zpool_status_healthy.txt')));
        $problems = cp_parse_zpool_status(fixture('zfs/zpool_status_degraded.txt'));
        assert_same(['pfSense'], array_keys($problems));
        assert_not_contains('Errors:', $problems['pfSense'], 'no known data errors is not an error');
    },
];
