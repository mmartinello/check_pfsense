<?php
/*
 * Tests of the speedtest subcommand (evaluation only, no pfSense needed).
 *
 * Expected outputs were produced by check_speedtest.py on the same
 * fixtures: the two plugins must give the same result.
 */

function speedtest(string $fixture, int $age = 0): array
{
    $now = 1793960000;
    return [
        'exists' => true,
        'file' => '/var/db/speedtest.json',
        'content' => fixture("speedtest/{$fixture}"),
        'mtime' => $now - $age,
        'now' => $now,
    ];
}

function speedtest_output(array $data, array $args = []): array
{
    return cp_render(cp_evaluate_speedtest($data, options_for('speedtest', $args)), false);
}

return [
    'speedtest-cli format, same output of check_speedtest.py' => function () {
        [$output, $code] = speedtest_output(speedtest('speedtest-cli.json'), ['-w', '100', '-c', '50', '-W', '10', '-C', '5', '-m', '1000', '-M', '300']);
        assert_same(
            "WARNING - Download speed: 93.12 Mbit/sec [WARNING], Upload speed: 20.5 Mbit/sec, Ping: 12.35 ms (server: Example ISP, Milan) | 'download_speed'=93.12;100.0:;50.0:;0.0;1000.0 'upload_speed'=20.5;10.0:;5.0:;0.0;300.0 'ping'=12.35ms;;;0\n",
            $output
        );
        assert_same(1, $code);
        assert_valid_plugin_output($output, $code);
    },

    'Ookla format, same output of check_speedtest.py' => function () {
        [$output, $code] = speedtest_output(speedtest('ookla.json'));
        assert_same(
            "OK - Download speed: 940.0 Mbit/sec, Upload speed: 200.0 Mbit/sec, Ping: 8.73 ms (server: Example ISP, Milan) | 'download_speed'=940.0 'upload_speed'=200.0 'ping'=8.73ms;;;0\n",
            $output
        );
        assert_same(0, $code);
    },

    'levels: alarm below the level, worst of download and upload' => function () {
        $data = speedtest('speedtest-cli.json');
        [$output, $code] = speedtest_output($data, ['-W', '30', '-C', '25']);
        assert_same(2, $code);
        assert_contains('Upload speed: 20.5 Mbit/sec [CRITICAL]', $output);
        assert_same(0, speedtest_output($data, ['-w', '93.12'])[1], 'equal to the level is OK');
        assert_same(2, speedtest_output($data, ['-w', '200', '-c', '100', '-W', '30'])[1]);
    },

    'thresholds before the subcommand (NRPE GUI)' => function () {
        $parsed = cp_parse_args(['-w', '100', '-c', '50', 'speedtest', '-W', '10'], cp_commands());
        $options = cp_prepare_options('speedtest', cp_commands()['speedtest'], $parsed['options']);
        $result = cp_evaluate_speedtest(speedtest('speedtest-cli.json'), $options);
        assert_same(CP_WARNING, $result->state);
    },

    'max age' => function () {
        assert_same(0, speedtest_output(speedtest('ookla.json', 30 * 60), ['--max-age', '60'])[1]);
        [$output, $code] = speedtest_output(speedtest('ookla.json', 75 * 60), ['--max-age', '60']);
        assert_same(3, $code);
        assert_same("UNKNOWN - Speedtest file /var/db/speedtest.json is too old: 75.0 minutes (max age: 60 minutes)\n", $output);
    },

    'missing file and invalid content' => function () {
        $result = cp_evaluate_speedtest(['exists' => false, 'file' => '/var/db/speedtest.json'], options_for('speedtest'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('not found (is the speedtest cron job configured?)', $result->message);

        $data = speedtest('ookla.json');
        $data['content'] = '{broken';
        assert_contains('Invalid speedtest JSON output', cp_evaluate_speedtest($data, options_for('speedtest'))->message);
        $data['content'] = '[1, 2]';
        assert_same('Invalid speedtest JSON output: not an object', cp_evaluate_speedtest($data, options_for('speedtest'))->message);
        $data['content'] = '{"upload": 1000000}';
        assert_same('No download speed in speedtest output', cp_evaluate_speedtest($data, options_for('speedtest'))->message);
        $data['content'] = '{"download": 1000000}';
        assert_same('No upload speed in speedtest output', cp_evaluate_speedtest($data, options_for('speedtest'))->message);
        $data['content'] = '{"download": "fast", "upload": 1}';
        assert_throws(CPException::class, fn () => cp_evaluate_speedtest($data, options_for('speedtest')), 'Invalid download value');
    },

    'ping and server are optional' => function () {
        $data = speedtest('ookla.json');
        $data['content'] = '{"download": 50000000, "upload": 10000000}';
        assert_same("OK - Download speed: 50.0 Mbit/sec, Upload speed: 10.0 Mbit/sec | 'download_speed'=50.0 'upload_speed'=10.0\n", speedtest_output($data)[0]);
    },

    'level validation like check_speedtest.py' => function () {
        assert_throws(CPUsageException::class, fn () => options_for('speedtest', ['-w', '50', '-c', '50']), 'Download warning level must be bigger');
        assert_throws(CPUsageException::class, fn () => options_for('speedtest', ['-W', '5', '-C', '10']), 'Upload warning level must be bigger');
        assert_throws(CPUsageException::class, fn () => options_for('speedtest', ['-c', '50', '-m', '40']), 'Download max level cannot be lower than Download critical');
        assert_throws(CPUsageException::class, fn () => options_for('speedtest', ['-W', '50', '-M', '40']), 'Upload max level cannot be lower than Upload warning');
        assert_throws(CPUsageException::class, fn () => options_for('speedtest', ['-w', '10:']), 'requires a speed in Mbit/s');
        assert_throws(CPUsageException::class, fn () => options_for('speedtest', ['-W', 'fast']), 'requires a number');
        assert_throws(CPUsageException::class, fn () => options_for('speedtest', ['--max-age', '-1']), 'requires an integer');
    },
];
