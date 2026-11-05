<?php
/*
 * Tests of the ntp subcommand (evaluation only, no pfSense needed).
 *
 * Fixtures: ntpq -pnw output (ntp 4.2.8); replace with output captured by
 * tools/collect_fixtures.sh when available.
 */

function ntpq(string $fixture, int $code = 0, string $stderr = ''): array
{
    return ['output' => fixture("ntp/{$fixture}"), 'code' => $code, 'stderr' => $stderr];
}

return [
    'synchronized' => function () {
        $result = cp_evaluate_ntp(ntpq('ntpq_ok.txt'), options_for('ntp'));
        assert_same(CP_OK, $result->state);
        assert_same('synchronized to 192.0.2.123 (stratum 2), offset -0.512 ms, 1 candidates, 3/4 peers reachable', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'offset'=-0.512ms;-100:100;-500:500 'jitter'=0.789ms;;;0 'stratum'=3;;;0;16 'peers'=4;;;0 'reachable'=3;;;0;4 'candidates'=1;;;0", $output);
        assert_contains('203.0.113.9 (refid .GPS., stratum 1): outlier, reach 377', $output);
        assert_contains('192.0.2.200 (refid .INIT., stratum 16): unreachable/pending, reach 0', $output);
        assert_not_contains('pool.ntp.org', $output, 'pool placeholders are not peers');
    },

    'offset thresholds are symmetric' => function () {
        $data = ntpq('ntpq_ok.txt');
        $data['output'] = str_replace('-0.512', '-150.000', $data['output']);
        assert_same(CP_WARNING, cp_evaluate_ntp($data, options_for('ntp'))->state, 'negative offset over 100 ms');
        $data['output'] = str_replace('-150.000', '+600.000', $data['output']);
        assert_same(CP_CRITICAL, cp_evaluate_ntp($data, options_for('ntp'))->state);
        assert_same(CP_OK, cp_evaluate_ntp($data, options_for('ntp', ['-w', '1000', '-c', '2000']))->state);
        assert_same(CP_WARNING, cp_evaluate_ntp($data, options_for('ntp', ['-w', '0:500', '-c', '-1000:1000']))->state, 'explicit ranges');
    },

    'no peer selected is critical' => function () {
        $result = cp_evaluate_ntp(ntpq('ntpq_nosync.txt'), options_for('ntp'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('No NTP peer selected (2 peers, 0 reachable)', $result->message);
        assert_valid_output($result, true);
    },

    'wrapped long names are joined' => function () {
        $peers = cp_parse_ntpq(fixture('ntp/ntpq_wrapped.txt'));
        assert_same(2, count($peers));
        assert_same('*', $peers[0]['tally']);
        assert_same('2001:db8:1234:5678:9abc:def0:1234:5678', $peers[0]['remote']);
        assert_same('.GPS.', $peers[0]['refid']);
        assert_same(0.25, $peers[0]['offset']);

        $result = cp_evaluate_ntp(ntpq('ntpq_wrapped.txt'), options_for('ntp'));
        assert_same('synchronized to 2001:db8:1234:5678:9abc:def0:1234:5678 (stratum 1), offset 0.25 ms, 1 candidates, 2/2 peers reachable', $result->message);
    },

    'PPS peer counts as synchronized' => function () {
        $data = ntpq('ntpq_ok.txt');
        $data['output'] = str_replace('*192.0.2.123', 'o192.0.2.123', $data['output']);
        assert_same(CP_OK, cp_evaluate_ntp($data, options_for('ntp'))->state);
    },

    'ntpd not responding is critical' => function () {
        $result = cp_evaluate_ntp(['output' => '', 'code' => 1, 'stderr' => "localhost: timed out, nothing received\n***Request timed out\n"], options_for('ntp'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('ntpd is not responding: ***Request timed out', $result->message);

        $result = cp_evaluate_ntp(['output' => "ntpq: read: Connection refused\n", 'code' => 0, 'stderr' => ''], options_for('ntp'));
        assert_same('ntpd is not responding: ntpq: read: Connection refused', $result->message);

        $result = cp_evaluate_ntp(['output' => "     remote refid st t when poll reach delay offset jitter\n=====\n", 'code' => 0, 'stderr' => ''], options_for('ntp'));
        assert_same('No NTP peers configured', $result->message);
    },

    'threshold validation' => function () {
        $o = options_for('ntp', ['-w', '100', '-c', '500']);
        assert_same('-100:100', $o['warn']->spec);
        assert_throws(CPUsageException::class, fn () => options_for('ntp', ['-w', '500', '-c', '100']), 'wider than critical');
    },
];
