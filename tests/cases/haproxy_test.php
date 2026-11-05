<?php
/*
 * Tests of the haproxy subcommand (evaluation only, no pfSense needed).
 *
 * Fixture: "show stat" output with the HAProxy 2.8/3.x columns; replace
 * with output captured by tools/collect_fixtures.sh when available.
 */

/** Collected data with the status of some proxies/servers replaced ("proxy/server" => status). */
function haproxy_with(array $statuses = [], array $changes = []): array
{
    $lines = explode("\n", fixture('haproxy/show_stat.txt'));
    $header = explode(',', substr($lines[0], 2));
    $statusIndex = array_search('status', $header, true);
    foreach ($lines as &$line) {
        $fields = explode(',', $line);
        $key = ($fields[0] ?? '') . '/' . ($fields[1] ?? '');
        if (isset($statuses[$key])) {
            $fields[$statusIndex] = $statuses[$key];
            $line = implode(',', $fields);
        }
    }
    return array_merge([
        'installed' => true, 'enabled' => true, 'carpdev' => '', 'carp_state' => null,
        'stat' => implode("\n", $lines), 'error' => null,
    ], $changes);
}

return [
    'all backends up' => function () {
        $result = cp_evaluate_haproxy(haproxy_with(), options_for('haproxy'));
        assert_same(CP_OK, $result->state);
        assert_same('2/2 backends OK', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'web_backend_servers_up'=2;;;0;2 'web_backend_sessions'=9;;;0 'api_backend_servers_up'=2;;;0;2", $output);
        assert_contains('[OK] backend api_backend: UP, 2/2 servers up (api1 UP, api2 no check)', $output);
    },

    'server down is a warning' => function () {
        $result = cp_evaluate_haproxy(haproxy_with(['web_backend/web2' => 'DOWN']), options_for('haproxy'));
        assert_same(CP_WARNING, $result->state);
        assert_same('backend web_backend web2 DOWN; 1/2 backends OK', $result->message);
        assert_contains("'web_backend_servers_up'=1;;;0;2", assert_valid_output($result));
    },

    'maintenance, drain and transitions are warnings' => function () {
        assert_same(CP_WARNING, cp_evaluate_haproxy(haproxy_with(['web_backend/web1' => 'MAINT']), options_for('haproxy'))->state);
        assert_same(CP_WARNING, cp_evaluate_haproxy(haproxy_with(['web_backend/web1' => 'MAINT (via api_backend/api1)']), options_for('haproxy'))->state);
        assert_same(CP_WARNING, cp_evaluate_haproxy(haproxy_with(['web_backend/web1' => 'DRAIN']), options_for('haproxy'))->state);
        $result = cp_evaluate_haproxy(haproxy_with(['web_backend/web1' => 'UP 1/3']), options_for('haproxy'));
        assert_same(CP_WARNING, $result->state, 'UP 1/3: going down');
        assert_contains('web1 UP 1/3', $result->message);
    },

    'backend without servers up is critical' => function () {
        $data = haproxy_with(['web_backend/web1' => 'DOWN', 'web_backend/web2' => 'MAINT', 'web_backend/BACKEND' => 'DOWN']);
        $result = cp_evaluate_haproxy($data, options_for('haproxy'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('backend web_backend DOWN (0/2 servers up: web1 DOWN, web2 MAINT); 1/2 backends OK', $result->message);
        assert_valid_output($result, true);
    },

    'frontends only with --frontends' => function () {
        $data = haproxy_with(['https_frontend/FRONTEND' => 'STOP']);
        assert_same(CP_OK, cp_evaluate_haproxy($data, options_for('haproxy'))->state);
        $result = cp_evaluate_haproxy($data, options_for('haproxy', ['--frontends']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('frontend https_frontend STOP; 2/2 backends OK', $result->message);
        assert_contains("'https_frontend_sessions'=12;;;0;3000", assert_valid_output($result));
        assert_same(CP_WARNING, cp_evaluate_haproxy(haproxy_with(['https_frontend/FRONTEND' => 'FULL']), options_for('haproxy', ['--frontends']))->state);
    },

    'filters by backend name' => function () {
        $data = haproxy_with(['web_backend/web2' => 'DOWN']);
        $result = cp_evaluate_haproxy($data, options_for('haproxy', ['--exclude', 'web_*']));
        assert_same(CP_OK, $result->state);
        assert_same('1/1 backends OK', $result->message);
        $result = cp_evaluate_haproxy($data, options_for('haproxy', ['--include', 'nothing']));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('match the include/exclude filters', $result->message);
    },

    'not running' => function () {
        $data = haproxy_with([], ['stat' => null, 'error' => 'HAProxy stats socket /tmp/haproxy.socket not found']);
        $result = cp_evaluate_haproxy($data, options_for('haproxy'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('HAProxy is not running: HAProxy stats socket /tmp/haproxy.socket not found', $result->message);
    },

    'not running on the CARP backup node is OK' => function () {
        $data = haproxy_with([], ['stat' => null, 'error' => 'not found', 'carpdev' => '192.0.2.1', 'carp_state' => 'BACKUP']);
        $result = cp_evaluate_haproxy($data, options_for('haproxy'));
        assert_same(CP_OK, $result->state);
        assert_same('HAProxy is not running: it runs only on the CARP master of 192.0.2.1, this node is BACKUP', $result->message);

        $data['carp_state'] = 'MASTER';
        assert_same(CP_CRITICAL, cp_evaluate_haproxy($data, options_for('haproxy'))->state, 'must run on the master');
    },

    'package not installed or disabled' => function () {
        $result = cp_evaluate_haproxy(['installed' => false], options_for('haproxy'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('HAProxy package is not installed', $result->message);
        $result = cp_evaluate_haproxy(['installed' => true, 'enabled' => false], options_for('haproxy'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('disabled', $result->message);
    },

    'missing stats socket' => function () {
        assert_throws(CPException::class, fn () => cp_haproxy_command('/nonexistent/haproxy.socket', 'show stat', 1), 'not found');
    },

    'malformed statistics' => function () {
        assert_same(CP_UNKNOWN, cp_evaluate_haproxy(haproxy_with([], ['stat' => 'Unknown command']), options_for('haproxy'))->state);
        assert_same([], cp_parse_haproxy_stat(''));
        $rows = cp_parse_haproxy_stat(fixture('haproxy/show_stat.txt'));
        assert_same(7, count($rows));
        assert_same('no check', $rows[5]['status']);
        assert_same('10.0.20.12:8080', $rows[5]['addr']);
    },
];
