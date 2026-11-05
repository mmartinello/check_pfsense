<?php
/*
 * Tests of the dns subcommand (evaluation only, no pfSense needed).
 *
 * Fixtures: drill output (ldns) in the format of FreeBSD 14/15; replace
 * with output captured by tools/collect_fixtures.sh when available.
 */

/** drill result as returned by cp_collect_dns(). */
function drill(string $fixture, int $code = 0, string $stderr = ''): array
{
    return ['output' => fixture("dns/{$fixture}"), 'code' => $code, 'stderr' => $stderr];
}

return [
    'resolution OK' => function () {
        $result = cp_evaluate_dns(drill('drill_ok.txt'), options_for('dns'));
        assert_same(CP_OK, $result->state);
        assert_same('pfsense.org A @127.0.0.1: 198.51.100.80 in 23 ms', $result->message);
        assert_contains("'time'=23ms;500;2000;0 'answers'=1;;;0", assert_valid_output($result));
    },

    'query time thresholds' => function () {
        assert_same(CP_WARNING, cp_evaluate_dns(drill('drill_ok.txt'), options_for('dns', ['-w', '20', '-c', '100']))->state);
        assert_same(CP_CRITICAL, cp_evaluate_dns(drill('drill_ok.txt'), options_for('dns', ['-w', '10', '-c', '20']))->state);
        assert_same(CP_OK, cp_evaluate_dns(drill('drill_ok.txt'), options_for('dns', ['-w', '23', '-c', '30']))->state);
    },

    'cname chain: only records of the requested type' => function () {
        $o = options_for('dns', ['--query', 'www.example.com']);
        $result = cp_evaluate_dns(drill('drill_cname.txt'), $o);
        assert_same(CP_OK, $result->state);
        assert_same('www.example.com A @127.0.0.1: 192.0.2.10, 192.0.2.11 in 0 ms', $result->message);
        assert_contains("'answers'=2;;;0", assert_valid_output($result));
    },

    'expected addresses' => function () {
        $result = cp_evaluate_dns(drill('drill_cname.txt'), options_for('dns', ['--query', 'www.example.com', '--expect', '192.0.2.11']));
        assert_same(CP_OK, $result->state);

        $result = cp_evaluate_dns(drill('drill_cname.txt'), options_for('dns', ['--query', 'www.example.com', '--expect', '192.0.2.10,192.0.2.99']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('DNS query www.example.com A @127.0.0.1 returned 192.0.2.10, 192.0.2.11, expected 192.0.2.99', $result->message);
        assert_valid_output($result);
    },

    'IPv6 expected addresses are compared in canonical form' => function () {
        $o = options_for('dns', ['--type', 'AAAA', '--expect', '2001:DB8::80']);
        $result = cp_evaluate_dns(drill('drill_aaaa.txt'), $o);
        assert_same(CP_OK, $result->state);
        assert_same('pfsense.org AAAA @127.0.0.1: 2001:db8:0:0::80 in 12 ms', $result->message);
    },

    'NXDOMAIN is critical' => function () {
        $result = cp_evaluate_dns(drill('drill_nxdomain.txt'), options_for('dns', ['--query', 'nonexistent.example']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('DNS query nonexistent.example A @127.0.0.1 returned NXDOMAIN', $result->message);
        assert_contains("'time'=31ms;500;2000;0 'answers'=0;;;0", assert_valid_output($result));
    },

    'no records of the requested type is critical' => function () {
        $result = cp_evaluate_dns(drill('drill_cname.txt'), options_for('dns', ['--query', 'www.example.com', '--type', 'aaaa']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('DNS query www.example.com AAAA @127.0.0.1 returned no AAAA records', $result->message);
    },

    'resolver not answering is critical' => function () {
        $data = ['output' => '', 'code' => 1, 'stderr' => "Error: error sending query: Could not send or receive, because of network error\n"];
        $result = cp_evaluate_dns($data, options_for('dns'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('DNS query pfsense.org A @127.0.0.1 failed: Error: error sending query: Could not send or receive, because of network error', $result->message);
        assert_valid_output($result);

        $result = cp_evaluate_dns(['output' => 'garbage', 'code' => 0, 'stderr' => ''], options_for('dns'));
        assert_same(CP_CRITICAL, $result->state);
        assert_contains('failed: garbage', $result->message);
    },

    'missing query time is unknown' => function () {
        $data = drill('drill_ok.txt');
        $data['output'] = preg_replace('/^;; Query time.*$/m', '', $data['output']);
        $result = cp_evaluate_dns($data, options_for('dns'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('query time not found', $result->message);
    },

    'custom server' => function () {
        $result = cp_evaluate_dns(drill('drill_ok.txt'), options_for('dns', ['--server', '192.0.2.53']));
        assert_contains('@192.0.2.53', $result->message);
    },

    'invalid options are usage errors' => function () {
        assert_throws(CPUsageException::class, fn () => options_for('dns', ['--query', 'bad name']), 'Invalid query name');
        assert_throws(CPUsageException::class, fn () => options_for('dns', ['--query', '-oops']), 'Invalid query name');
        assert_throws(CPUsageException::class, fn () => options_for('dns', ['--server', 'dns.example.com']), 'IP address is required');
        assert_throws(CPUsageException::class, fn () => options_for('dns', ['--expect', '192.0.2.1,not-an-ip']), "Invalid expected address 'not-an-ip'");
        assert_throws(CPUsageException::class, fn () => options_for('dns', ['--type', 'mx']), 'must be one of');
        options_for('dns', ['--query', '_service._tcp.example.com.', '--server', '2001:db8::53']);
    },

    'drill output parsing' => function () {
        $parsed = cp_parse_drill(fixture('dns/drill_cname.txt'));
        assert_same('NOERROR', $parsed['rcode']);
        assert_same(0.0, $parsed['time']);
        assert_same(3, count($parsed['answers']));
        assert_same(['name' => 'www.example.com.', 'type' => 'CNAME', 'data' => 'web.example.net.'], $parsed['answers'][0]);
        assert_same(['rcode' => null, 'time' => null, 'answers' => []], cp_parse_drill(''));
    },
];
