<?php
/*
 * Tests of the memory subcommand (evaluation only, no pfSense needed).
 *
 * Fixture: sysctl output of an 8 GB system; replace with output captured
 * by tools/collect_fixtures.sh when available.
 */

/** sysctl output with some values replaced (name without vm.stats.vm. prefix). */
function memory_with(array $values = []): array
{
    $output = fixture('memory/sysctl.txt');
    foreach ($values as $name => $value) {
        $output = preg_replace("/^(vm\\.stats\\.vm\\.{$name}|{$name}): .*$/m", "\$1: {$value}", $output);
    }
    return ['sysctl' => $output];
}

return [
    'usage like the dashboard' => function () {
        $result = cp_evaluate_memory(memory_with(), options_for('memory'));
        assert_same(CP_OK, $result->state);
        // (2008131 - 312004 - 1205 - 1140122) / 2008131 = 27.63%
        assert_same('Memory 27.6% used (2.1 GiB of 7.7 GiB; active 204.3 MiB, wired 1.9 GiB, inactive 1.2 GiB, laundry 4.7 MiB, free 4.3 GiB)', $result->message);
        $output = assert_valid_output($result);
        assert_contains("'usage'=27.63%;80;90;0;100 'used'=2272460800B;;;0;8225304576", $output);
        assert_contains("'free'=4669939712B;;;0;8225304576", $output);
    },

    'thresholds' => function () {
        // 1700000 used pages of 2008131: 84.66%
        $data = memory_with(['v_inactive_count' => 100000, 'v_laundry_count' => 0, 'v_free_count' => 208131]);
        assert_same(CP_WARNING, cp_evaluate_memory($data, options_for('memory'))->state);
        $data = memory_with(['v_inactive_count' => 50000, 'v_laundry_count' => 0, 'v_free_count' => 100000]);
        assert_same(CP_CRITICAL, cp_evaluate_memory($data, options_for('memory'))->state);
        assert_same(CP_WARNING, cp_evaluate_memory(memory_with(), options_for('memory', ['-w', '20', '-c', '50']))->state);
    },

    'missing or invalid values are unknown' => function () {
        $result = cp_evaluate_memory(['sysctl' => "hw.pagesize: 4096\n"], options_for('memory'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('Cannot read sysctl vm.stats.vm.v_page_count', $result->message);

        $result = cp_evaluate_memory(memory_with(['v_page_count' => 0]), options_for('memory'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('Invalid memory page count', $result->message);

        assert_same(CP_UNKNOWN, cp_evaluate_memory(memory_with(['v_free_count' => 'x']), options_for('memory'))->state);
    },
];
