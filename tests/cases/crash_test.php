<?php
/*
 * Tests of the crash subcommand (evaluation only, no pfSense needed).
 */

/** Files of /var/crash on a system without crash reports. */
function crash_files_clean(): array
{
    return [
        ['name' => 'minfree', 'size' => 5, 'mtime' => 1700000000],
        ['name' => 'bounds', 'size' => 2, 'mtime' => 1793000000],
        ['name' => 'info.last', 'size' => 0, 'mtime' => 1793000000],
        ['name' => 'vmcore.last', 'size' => 0, 'mtime' => 1793000000],
    ];
}

function crash_files_dump(): array
{
    return array_merge(crash_files_clean(), [
        ['name' => 'info.0', 'size' => 512, 'mtime' => 1793000000],
        ['name' => 'textdump.tar.0', 'size' => 1048576, 'mtime' => 1793000000],
    ]);
}

function php_errors(string $text): array
{
    return ['size' => strlen($text), 'mtime' => 1793900000, 'tail' => $text];
}

return [
    'no crash reports' => function () {
        $result = cp_evaluate_crash(['files' => crash_files_clean(), 'php_errors' => null], options_for('crash'));
        assert_same(CP_OK, $result->state);
        assert_same('No crash reports', $result->message);
        assert_same("OK - No crash reports | 'crash_files'=0;;;0\n", assert_valid_output($result));
    },

    'crash reports are a warning' => function () {
        $result = cp_evaluate_crash(['files' => crash_files_dump(), 'php_errors' => null], options_for('crash'));
        assert_same(CP_WARNING, $result->state);
        assert_same('2 crash report files in /var/crash (latest ' . gmdate('Y-m-d H:i', 1793000000) . ': info.0, textdump.tar.0)', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'crash_files'=2;;;0", $output);
        assert_contains('/var/crash/textdump.tar.0: 1 MiB', $output);
        assert_contains('Diagnostics > Crash Reporter', $output);
    },

    'same file filter of the GUI' => function () {
        $names = fn ($files) => array_column(cp_crash_files($files), 'name');
        assert_same([], $names(crash_files_clean()));
        assert_same(['info.0', 'textdump.tar.0'], $names(crash_files_dump()));
        // Unanchored regex like cleanup_crash_file_list()
        assert_same([], $names([['name' => 'mylast'], ['name' => 'outofbounds.txt']]));
        assert_same([], $names(['garbage', ['size' => 1]]));
    },

    'PHP errors only with --php-errors' => function () {
        $log = "[05-Nov-2026 21:00:00 Europe/Rome] PHP Warning:  Undefined array key \"x\" in /etc/inc/util.inc on line 42\n"
            . "[05-Nov-2026 21:05:00 Europe/Rome] PHP Fatal error:  Uncaught TypeError in /usr/local/www/index.php:10\n";
        $data = ['files' => crash_files_clean(), 'php_errors' => php_errors($log)];

        assert_same(CP_OK, cp_evaluate_crash($data, options_for('crash'))->state, 'ignored by default');

        $result = cp_evaluate_crash($data, options_for('crash', ['--php-errors']));
        assert_same(CP_WARNING, $result->state);
        assert_matches('#^PHP errors in /tmp/PHP_errors.log \(\d+ B, last: \[05-Nov-2026 21:05:00 Europe/Rome\] PHP Fatal error: Uncaught TypeError in /usr/local/www/index.php:10\)$#', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'php_errors'=", $output);
        assert_contains('PHP error: [05-Nov-2026 21:00:00 Europe/Rome] PHP Warning', $output);
    },

    'crash reports and PHP errors together' => function () {
        $data = ['files' => crash_files_dump(), 'php_errors' => php_errors("error\n")];
        $result = cp_evaluate_crash($data, options_for('crash', ['--php-errors']));
        assert_same(CP_WARNING, $result->state);
        assert_matches('/^2 crash report files .*; PHP errors in /', $result->message);
    },

    'empty PHP error log is OK' => function () {
        $data = ['files' => [], 'php_errors' => php_errors('')];
        $result = cp_evaluate_crash($data, options_for('crash', ['--php-errors']));
        assert_same(CP_OK, $result->state);
        assert_same('No crash reports and no PHP errors', $result->message);
        assert_contains("'php_errors'=0B;;;0", assert_valid_output($result));
    },

    'many crash files are shortened in the first line' => function () {
        $files = [];
        for ($i = 0; $i < 8; $i++) {
            $files[] = ['name' => "info.{$i}", 'size' => 10, 'mtime' => 1793000000 + $i];
        }
        $result = cp_evaluate_crash(['files' => $files], options_for('crash'));
        assert_matches('/^8 crash report files .*: info.7, info.6, info.5, info.4, info.3, \.\.\.\)$/', $result->message);
        assert_same(9, count($result->long));
    },
];
