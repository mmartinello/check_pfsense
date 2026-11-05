<?php
/*
 * Tests of the mbuf subcommand (evaluation only, no pfSense needed).
 *
 * Fixture: netstat -m output in the FreeBSD 14/15 format; replace with
 * output captured by tools/collect_fixtures.sh when available.
 */

/** netstat -m output with the clusters line and denied counters replaced. */
function mbuf_with(?string $clusters = null, ?string $denied = null, ?string $jumboDenied = null): array
{
    $output = fixture('mbuf/netstat_m.txt');
    if ($clusters !== null) {
        $output = preg_replace('#^\S+ mbuf clusters in use#m', "{$clusters} mbuf clusters in use", $output);
    }
    if ($denied !== null) {
        $output = preg_replace('#^\S+ requests for mbufs denied#m', "{$denied} requests for mbufs denied", $output);
    }
    if ($jumboDenied !== null) {
        $output = preg_replace('#^\S+ requests for jumbo clusters denied#m', "{$jumboDenied} requests for jumbo clusters denied", $output);
    }
    return ['netstat' => $output];
}

return [
    'low usage is OK' => function () {
        $result = cp_evaluate_mbuf(mbuf_with(), options_for('mbuf'));
        assert_same(CP_OK, $result->state);
        assert_same('mbuf clusters 0.2% used (2292/1000000, 1022 in use, 1270 cached)', $result->message);
        $output = assert_valid_output($result);
        assert_contains("'clusters'=2292;;;0;1000000 'clusters_usage'=0.23%;80;90;0;100 'clusters_current'=1022;;;0;1000000 'denied'=0c;;;0", $output);
    },

    'usage thresholds like the dashboard (in use + cache)' => function () {
        assert_same(CP_OK, cp_evaluate_mbuf(mbuf_with('500/300/800/1000'), options_for('mbuf'))->state);
        assert_same(CP_WARNING, cp_evaluate_mbuf(mbuf_with('500/350/850/1000'), options_for('mbuf'))->state);
        $result = cp_evaluate_mbuf(mbuf_with('900/50/950/1000'), options_for('mbuf'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('mbuf clusters 95% used (950/1000, 900 in use, 50 cached)', $result->message);
        assert_same(CP_WARNING, cp_evaluate_mbuf(mbuf_with('500/300/800/1000'), options_for('mbuf', ['-w', '70', '-c', '95']))->state);
    },

    'denied requests are a warning' => function () {
        $result = cp_evaluate_mbuf(mbuf_with(null, '0/3/0'), options_for('mbuf'));
        assert_same(CP_WARNING, $result->state);
        assert_same('3 mbuf requests denied since boot, mbuf clusters 0.2% used (2292/1000000, 1022 in use, 1270 cached)', $result->message);
        assert_contains("'denied'=3c;;;0", assert_valid_output($result));

        $result = cp_evaluate_mbuf(mbuf_with(null, null, '2/0/1'), options_for('mbuf'));
        assert_same(CP_WARNING, $result->state, 'jumbo clusters denied count too');
        assert_contains('3 mbuf requests denied', $result->message);

        $result = cp_evaluate_mbuf(mbuf_with('900/50/950/1000', '1/0/0'), options_for('mbuf'));
        assert_same(CP_CRITICAL, $result->state, 'critical usage wins');
    },

    'denied requests can be ignored' => function () {
        $result = cp_evaluate_mbuf(mbuf_with(null, '5/5/5'), options_for('mbuf', ['--ignore-denied']));
        assert_same(CP_OK, $result->state);
        assert_contains("'denied'=15c", assert_valid_output($result), 'still in perfdata');
    },

    'sfbufs denied are not mbuf requests' => function () {
        $data = mbuf_with();
        $data['netstat'] = str_replace('0 requests for sfbufs denied', '7 requests for sfbufs denied', $data['netstat']);
        assert_same(CP_OK, cp_evaluate_mbuf($data, options_for('mbuf'))->state);
    },

    'malformed output is unknown' => function () {
        assert_same(CP_UNKNOWN, cp_evaluate_mbuf(['netstat' => 'garbage'], options_for('mbuf'))->state);
        $result = cp_evaluate_mbuf(mbuf_with('1/2/3/0'), options_for('mbuf'));
        assert_same(CP_UNKNOWN, $result->state, 'zero max');
        assert_contains('netstat -m', $result->message);

        // Missing denied lines: no perfdata and no warning
        $result = cp_evaluate_mbuf(['netstat' => "1/2/3/100 mbuf clusters in use (current/cache/total/max)\n"], options_for('mbuf'));
        assert_same(CP_OK, $result->state);
        assert_not_contains('denied', implode(' ', $result->perfdata));
    },
];
