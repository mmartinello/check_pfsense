<?php
/*
 * Tests of the notices subcommand (evaluation only, no pfSense needed).
 *
 * Fixture: notices.serial in the format written by file_notice() (fields
 * encoded with htmlentities()); replace with a file captured by
 * tools/collect_fixtures.sh when available.
 */

function notices_data(): array
{
    return ['exists' => true, 'raw' => fixture('notices/notices.serial')];
}

return [
    'notices are a warning, newest first' => function () {
        $result = cp_evaluate_notices(notices_data(), options_for('notices'));
        assert_same(CP_WARNING, $result->state);
        assert_matches('/^4 system notices: \S+ \S+ \[filter_load\] There were error\(s\) loading the rules: .{40,}\.\.\.; \S+ \S+ \[gateways\] GW_WAN_1 is down, omitting from routing group WAN_FAILOVER; \S+ \S+ \[Certificate Manager\] Certificate "www.example.com" expires in 20 days <check>; and 1 more$/', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'notices'=4;;;0", $output);
        assert_contains('[Upgrade/pfSense-upgrade] ERROR: Remove all WireGuard tunnels before upgrading.', $output);
        assert_contains('table <bogonsv6> persist file "/etc/bogonsv6"', $output, 'full text in the long output');
    },

    'max items' => function () {
        $result = cp_evaluate_notices(notices_data(), options_for('notices', ['--max-items', '1']));
        assert_matches('/\[filter_load\].*; and 3 more$/', $result->message);
        $result = cp_evaluate_notices(notices_data(), options_for('notices', ['--max-items', '10']));
        assert_not_contains('more', $result->message);
    },

    'filters by id and category' => function () {
        $result = cp_evaluate_notices(notices_data(), options_for('notices', ['--exclude', 'Upgrade,filter_load']));
        assert_matches('/^2 system notices: /', $result->message);

        $result = cp_evaluate_notices(notices_data(), options_for('notices', ['--include', 'certificates']));
        assert_matches('/^1 system notices: .*\[Certificate Manager\]/', $result->message);

        $result = cp_evaluate_notices(notices_data(), options_for('notices', ['--include', 'nothing']));
        assert_same(CP_OK, $result->state, 'all notices filtered');
    },

    'no notices' => function () {
        $result = cp_evaluate_notices(['exists' => false, 'raw' => null], options_for('notices'));
        assert_same(CP_OK, $result->state);
        assert_same('No system notices', $result->message);
        assert_contains("'notices'=0;;;0", assert_valid_output($result));

        assert_same(CP_OK, cp_evaluate_notices(['exists' => true, 'raw' => ''], options_for('notices'))->state, 'empty file');
        assert_same(CP_OK, cp_evaluate_notices(['exists' => true, 'raw' => serialize([])], options_for('notices'))->state);
    },

    'corrupted file is unknown' => function () {
        $result = cp_evaluate_notices(['exists' => true, 'raw' => 'not serialized'], options_for('notices'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('Cannot decode the notices file', $result->message);
    },

    'objects are never created' => function () {
        $raw = 'a:1:{i:1;O:8:"stdClass":1:{s:6:"notice";s:4:"evil";}}';
        $notices = cp_parse_notices($raw);
        assert_same([], $notices, 'objects become __PHP_Incomplete_Class and are skipped');
    },

    'shorten' => function () {
        assert_same('abc', cp_shorten('abc', 10));
        assert_same('abcdefg...', cp_shorten('abcdefghijklmnop', 10));
        assert_same('a b', cp_shorten("a\nb", 10));
    },
];
