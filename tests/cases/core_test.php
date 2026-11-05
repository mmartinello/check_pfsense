<?php
/*
 * Tests of the core helpers: states, thresholds, perfdata, output.
 */

return [
    'worst state order' => function () {
        assert_same(CP_OK, cp_worst_state());
        assert_same(CP_UNKNOWN, cp_worst_state(CP_OK, CP_UNKNOWN));
        assert_same(CP_WARNING, cp_worst_state(CP_UNKNOWN, CP_WARNING, CP_OK));
        assert_same(CP_CRITICAL, cp_worst_state(CP_WARNING, CP_CRITICAL, CP_UNKNOWN));
    },

    'number format' => function () {
        assert_same('12', cp_format_number(12));
        assert_same('12.5', cp_format_number(12.5));
        assert_same('0.001', cp_format_number(0.001));
        assert_same('0', cp_format_number(-0.0));
        assert_same('1000000', cp_format_number(1e6));
        assert_same('0.3', cp_format_number(0.26, 1));
    },

    'perfdata label sanitization' => function () {
        assert_same('WAN_DHCP', cp_perf_label('WAN_DHCP'));
        assert_same('my_gw_1__delay', cp_perf_label("my gw (1)'_delay"));
        assert_same('a.b-c', cp_perf_label('a.b-c'));
        assert_same('value', cp_perf_label('???'));
    },

    'perfdata format' => function () {
        assert_same("'x'=1.5ms;100;200;0", cp_perf('x', 1.5, 'ms', 100, 200, 0));
        assert_same("'x'=5", cp_perf('x', 5));
        assert_same("'x'=5%;;;0;100", cp_perf('x', 5, '%', null, null, 0, 100));
        assert_same("'x'=U", cp_perf('x', NAN));
        assert_same("'x'=U;1", cp_perf('x', null, 'ms', 1));
        assert_same("'x'=3c", cp_perf('x', 3, 'c'));
        assert_same("'x'=10;10:;5:", cp_perf('x', 10, '', CPThreshold::parse('10:', '-w'), CPThreshold::parse('5:', '-c')));
        assert_same("'x'=1;;;0", cp_perf('x', 1, '', null, null, 0, INF));
        assert_throws(LogicException::class, fn () => cp_perf('x', 1, 'Mbps'));
    },

    'threshold parsing and alerts' => function () {
        $t = CPThreshold::parse('10', '-w');
        assert_true(!$t->alert(0) && !$t->alert(10) && $t->alert(10.1) && $t->alert(-1));

        $t = CPThreshold::parse('10:', '-w');
        assert_true($t->alert(9.9) && !$t->alert(10) && !$t->alert(1e9));

        $t = CPThreshold::parse('~:10', '-w');
        assert_true(!$t->alert(-100) && $t->alert(11));

        $t = CPThreshold::parse('10:20', '-w');
        assert_true($t->alert(9) && !$t->alert(15) && $t->alert(21));

        $t = CPThreshold::parse('@10:20', '-w');
        assert_true(!$t->alert(9) && $t->alert(15) && !$t->alert(21));
    },

    'invalid thresholds' => function () {
        assert_throws(CPUsageException::class, fn () => CPThreshold::parse('abc', '-w'), 'Invalid threshold');
        assert_throws(CPUsageException::class, fn () => CPThreshold::parse('', '-w'));
        assert_throws(CPUsageException::class, fn () => CPThreshold::parse('20:10', '-c'), 'start is greater');
        assert_throws(CPUsageException::class, fn () => CPThreshold::parse('1:x', '-c'));
    },

    'threshold order' => function () {
        $p = fn ($s) => CPThreshold::parse($s, '-w');
        cp_check_threshold_order($p('80'), $p('90'));
        cp_check_threshold_order($p('90'), $p('90'));
        cp_check_threshold_order($p('30:'), $p('14:'));
        cp_check_threshold_order(null, $p('90'));
        assert_throws(CPUsageException::class, fn () => cp_check_threshold_order($p('90'), $p('80')), 'greater than critical');
        assert_throws(CPUsageException::class, fn () => cp_check_threshold_order($p('14:'), $p('30:')), 'lower than critical');
    },

    'threshold state' => function () {
        $w = CPThreshold::parse('80', '-w');
        $c = CPThreshold::parse('90', '-c');
        assert_same(CP_OK, cp_threshold_state(80, $w, $c));
        assert_same(CP_WARNING, cp_threshold_state(85, $w, $c));
        assert_same(CP_CRITICAL, cp_threshold_state(95, $w, $c));
        assert_same(CP_OK, cp_threshold_state(95, null, null));
    },

    'glob matching' => function () {
        assert_true(cp_glob_match('WAN*', 'wan_dhcp'));
        assert_true(cp_glob_match('opt?', 'OPT1'));
        assert_true(!cp_glob_match('opt?', 'OPT10'));
        assert_true(cp_glob_match('a.b', 'a.b'));
        assert_true(!cp_glob_match('a.b', 'axb'), 'dots are literal');
        assert_true(cp_glob_match('*', ''));
    },

    'include and exclude' => function () {
        assert_true(cp_filter_match(['a'], [], []));
        assert_true(cp_filter_match(['wan', 'Main link'], ['main*'], []));
        assert_true(!cp_filter_match(['wan'], ['lan'], []));
        assert_true(!cp_filter_match(['wan'], [], ['w*']));
        assert_true(!cp_filter_match(['wan'], ['w*'], ['wan']), 'exclude wins');
        assert_true(cp_filter_match(['wan', ''], [], ['']), 'empty names are ignored');
    },

    'aggregate: problems first, worst first, then summary' => function () {
        $result = cp_aggregate([
            ['state' => CP_OK, 'text' => 'a ok'],
            ['state' => CP_WARNING, 'text' => 'b warn'],
            ['state' => CP_UNKNOWN, 'text' => 'c unknown'],
            ['state' => CP_CRITICAL, 'text' => 'd crit', 'detail' => 'd crit detail'],
            ['state' => CP_WARNING, 'text' => 'e warn'],
        ], '1/5 ok');
        assert_same(CP_CRITICAL, $result->state);
        assert_same('d crit, b warn, e warn, c unknown; 1/5 ok', $result->message);
        assert_same('[CRITICAL] d crit detail', $result->long[3]);
        assert_same('[OK] a ok', $result->long[0]);
    },

    'aggregate: all ok shows only the summary' => function () {
        $result = cp_aggregate([['state' => CP_OK, 'text' => 'a']], '1/1 ok');
        assert_same(CP_OK, $result->state);
        assert_same('1/1 ok', $result->message);
    },

    'render: one line, perfdata, long output only with verbose' => function () {
        $result = new CPResult(CP_WARNING, "bad | text\nhere", ["'a'=1"], ['line | 1', 'line 2']);
        [$output, $code] = cp_render($result, false);
        assert_same("WARNING - bad / text here | 'a'=1\n", $output);
        assert_same(1, $code);

        [$output] = cp_render($result, true);
        assert_same("WARNING - bad / text here | 'a'=1\nline / 1\nline 2\n", $output);
        assert_valid_plugin_output($output, 1);
    },

    'render: invalid state becomes unknown' => function () {
        [$output, $code] = cp_render(new CPResult(42, 'x'), false);
        assert_same("UNKNOWN - x\n", $output);
        assert_same(3, $code);
    },

    'exceptions become unknown' => function () {
        $result = cp_result_from_exception(new CPUsageException('bad option'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('Usage error: bad option', $result->message);

        $result = cp_result_from_exception(new CPException('socket error'));
        assert_same('socket error', $result->message);

        $result = cp_result_from_exception(new TypeError('oops'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('Internal error: TypeError: oops', $result->message);
    },

    'cache: atomic write, read and expiry' => function () {
        $dir = sys_get_temp_dir() . '/check_pfsense_test_' . getmypid();
        try {
            assert_same(null, cp_cache_get('updates', 60, $dir));
            cp_cache_set('updates', ['version' => '2.9.0'], $dir);
            assert_same(['version' => '2.9.0'], cp_cache_get('updates', 60, $dir));
            assert_same(null, cp_cache_get('updates', 0, $dir), 'ttl 0 disables the cache');
            assert_same([], glob($dir . '/.tmp_*'), 'no temporary files left');

            // Expired entry
            file_put_contents($dir . '/updates.json', json_encode(['time' => time() - 100, 'data' => 1]));
            assert_same(null, cp_cache_get('updates', 60, $dir));

            // Corrupted entry
            file_put_contents($dir . '/updates.json', '{broken');
            assert_same(null, cp_cache_get('updates', 60, $dir));
        } finally {
            array_map('unlink', glob($dir . '/{,.}*.json', GLOB_BRACE) ?: []);
            @rmdir($dir);
        }
    },

    'run command: output, exit code, timeout, missing command' => function () {
        $result = cp_run(['/bin/sh', '-c', 'echo out; echo err >&2; exit 3'], 5);
        assert_same(3, $result['code']);
        assert_same("out\n", $result['stdout']);
        assert_same("err\n", $result['stderr']);

        assert_same("a b\n", cp_run(['/bin/echo', 'a b'], 5)['stdout'], 'arguments are escaped');
        assert_same("\$HOME;ls\n", cp_run(['/bin/echo', '$HOME;ls'], 5)['stdout'], 'no shell expansion');

        $start = microtime(true);
        assert_throws(CPException::class, fn () => cp_run(['/bin/sleep', '5'], 1), 'timed out after 1 seconds');
        assert_true(microtime(true) - $start < 3, 'timeout must kill the command');

        assert_throws(CPException::class, fn () => cp_run(['/nonexistent/cmd'], 1), 'Command not found');
        assert_throws(CPException::class, fn () => cp_run_ok(['/bin/sh', '-c', 'echo boom >&2; exit 1'], 5), 'sh exited with status 1: boom');
    },
];
