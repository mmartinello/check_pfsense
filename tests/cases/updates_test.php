<?php
/*
 * Tests of the updates subcommand (evaluation only, no pfSense needed).
 */

/**
 * Collected data: installed packages from the fixture, relevant packages
 * selected like the collector, available versions and comparison results.
 *
 * @param array<string, array{0: ?string, 1: string}> $remote name => [available, compare]
 */
function updates_data(array $remote = [], bool $cached = false): array
{
    $installed = cp_parse_pkg_list(fixture('updates/pkg_query.txt'));
    $packages = [];
    foreach (cp_updates_packages($installed, 'pfSense') as $name => $kind) {
        [$available, $compare] = $remote[$name] ?? [$installed[$name], '='];
        $packages[] = ['name' => $name, 'kind' => $kind, 'installed' => $installed[$name], 'available' => $available, 'compare' => $compare];
    }
    return ['checked' => time() - 125, 'product' => 'pfSense', 'packages' => $packages, 'cached' => $cached];
}

return [
    'relevant packages: core, meta and GUI packages' => function () {
        $installed = cp_parse_pkg_list(fixture('updates/pkg_query.txt'));
        assert_same('2.8.1', $installed['pfSense']);
        assert_same([
            'pfSense-base' => 'core',
            'pfSense-kernel-pfSense' => 'core',
            'pfSense-pkg-Status_Traffic_Totals' => 'package',
            'pfSense-pkg-WireGuard' => 'package',
            'pfSense-pkg-nrpe' => 'package',
            'pfSense-pkg-sudo' => 'package',
            'pfSense' => 'meta',
        ], cp_updates_packages($installed, 'pfSense'));

        $installed['pfSense-vmware'] = '2.8.1';
        assert_same('meta', cp_updates_packages($installed, 'pfSense')['pfSense-vmware'], 'vmware meta package wins');
    },

    'everything up to date' => function () {
        $result = cp_evaluate_updates(updates_data(), options_for('updates'));
        assert_same(CP_OK, $result->state);
        assert_same('pfSense 2.8.1 is up to date, 4/4 packages up to date', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'system_updates'=0;;;0;1 'package_updates'=0;;;0", $output);
        assert_contains('pfSense-pkg-nrpe 2.4.1_1: up to date', $output);
        assert_contains('Checked 2m 5s ago', $output);
    },

    'system update available' => function () {
        $data = updates_data([
            'pfSense' => ['2.8.2', '<'],
            'pfSense-base' => ['2.8.2', '<'],
            'pfSense-kernel-pfSense' => ['2.8.2', '<'],
        ]);
        $result = cp_evaluate_updates($data, options_for('updates'));
        assert_same(CP_WARNING, $result->state);
        assert_same('pfSense update available: 2.8.1 -> 2.8.2; 4/4 packages up to date', $result->message);
        assert_contains("'system_updates'=1;;;0;1", assert_valid_output($result));
    },

    'core package update without meta (patch release)' => function () {
        $result = cp_evaluate_updates(updates_data(['pfSense-base' => ['2.8.1_1', '<']]), options_for('updates'));
        assert_same(CP_WARNING, $result->state);
        assert_same('pfSense update available: 2.8.1 -> 2.8.1_1; 4/4 packages up to date', $result->message);
    },

    'package updates available' => function () {
        $data = updates_data([
            'pfSense-pkg-WireGuard' => ['0.2.7', '<'],
            'pfSense-pkg-sudo' => ['0.3_11', '<'],
        ]);
        $result = cp_evaluate_updates($data, options_for('updates', ['-v']));
        assert_same(CP_WARNING, $result->state);
        assert_same(
            'package updates available: pfSense-pkg-WireGuard 0.2.6 -> 0.2.7, pfSense-pkg-sudo 0.3_10 -> 0.3_11; pfSense 2.8.1 is up to date, 2/4 packages up to date',
            $result->message
        );
        $output = assert_valid_output($result, true);
        assert_contains("'package_updates'=2;;;0", $output);
        assert_contains('pfSense-pkg-WireGuard 0.2.6: update available (0.2.7)', $output);
    },

    'system and package updates' => function () {
        $data = updates_data(['pfSense' => ['2.8.2', '<'], 'pfSense-pkg-nrpe' => ['2.4.2', '<']]);
        $result = cp_evaluate_updates($data, options_for('updates'));
        assert_same(CP_WARNING, $result->state);
        assert_same('pfSense update available: 2.8.1 -> 2.8.2; package updates available: pfSense-pkg-nrpe 2.4.1_1 -> 2.4.2; 3/4 packages up to date', $result->message);
    },

    '--no-packages ignores package updates' => function () {
        $data = updates_data(['pfSense-pkg-nrpe' => ['2.4.2', '<']]);
        $result = cp_evaluate_updates($data, options_for('updates', ['--no-packages']));
        assert_same(CP_OK, $result->state);
        assert_same('pfSense 2.8.1 is up to date, packages not checked', $result->message);
    },

    'newer than repository and packages missing from the catalog' => function () {
        $data = updates_data(['pfSense-pkg-Status_Traffic_Totals' => [null, '?'], 'pfSense-pkg-sudo' => ['0.3_9', '>']]);
        $result = cp_evaluate_updates($data, options_for('updates'));
        assert_same(CP_OK, $result->state);
        $long = implode("\n", $result->long);
        assert_contains('pfSense-pkg-Status_Traffic_Totals 2.3.2_6: not found in the repository catalog', $long);
        assert_contains('pfSense-pkg-sudo 0.3_10: newer than the repository (0.3_9)', $long);
    },

    'meta package missing from the catalog is unknown' => function () {
        $result = cp_evaluate_updates(updates_data(['pfSense' => [null, '?']]), options_for('updates'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('Package pfSense not found in the repository catalog', $result->message);
    },

    'no core or meta packages is unknown' => function () {
        $result = cp_evaluate_updates(['product' => 'pfSense', 'packages' => []], options_for('updates'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('no meta or core packages found', $result->message);
    },

    'cached result and cache errors in the long output' => function () {
        $data = updates_data([], true);
        $data['cache_error'] = 'Cannot write cache file /var/db/check_pfsense/updates.json';
        $result = cp_evaluate_updates($data, options_for('updates'));
        assert_same(CP_OK, $result->state);
        $long = implode("\n", $result->long);
        assert_contains('Checked 2m 5s ago (cached)', $long);
        assert_contains('Cannot write cache file', $long);
    },

    'options' => function () {
        assert_same(CP_UPDATES_DEFAULT_TTL, options_for('updates')['cache-ttl']);
        assert_same(0, options_for('updates', ['--cache-ttl', '0'])['cache-ttl']);
        assert_throws(CPUsageException::class, fn () => options_for('updates', ['--cache-ttl', '-5']), 'requires an integer');
        assert_throws(CPUsageException::class, fn () => options_for('updates', ['-w', '1']), 'does not use -w');
    },

    'pkg list parsing' => function () {
        assert_same(['a' => '1', 'b' => '2_1'], cp_parse_pkg_list("a 1\nb 2_1\na 3\ngarbage\n\nc d e\n"));
    },
];
