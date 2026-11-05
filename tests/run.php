<?php
/*
 * Minimal test runner for check_pfsense, without dependencies.
 *
 * Usage: php tests/run.php [filter]
 *
 * Every file in tests/cases/ returns an array of test name => callable.
 * The optional filter runs only the tests whose "file: name" contains it.
 */

define('CHECK_PFSENSE_LIBRARY', true);

require __DIR__ . '/../check_pfsense.php';
require __DIR__ . '/lib.php';

error_reporting(E_ALL);
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    // Errors silenced with @ are not reported by error_reporting()
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$filter = $argv[1] ?? null;
$passed = 0;
$failed = [];

$files = glob(__DIR__ . '/cases/*.php');
sort($files);

foreach ($files as $file) {
    $tests = require $file;
    $prefix = basename($file, '.php');

    foreach ($tests as $name => $test) {
        $fullName = "{$prefix}: {$name}";
        if ($filter !== null && stripos($fullName, $filter) === false) {
            continue;
        }

        try {
            $test();
            $passed++;
            echo '.';
        } catch (Throwable $e) {
            $failed[] = [$fullName, $e];
            echo 'F';
        }
    }
}

echo "\n\n";

foreach ($failed as [$name, $e]) {
    $where = ($e instanceof TestFailure) ? $e->where : basename($e->getFile()) . ':' . $e->getLine();
    echo "FAIL {$name}\n  {$where}: " . get_class($e) . ': ' . $e->getMessage() . "\n\n";
}

$total = $passed + count($failed);
echo "{$passed}/{$total} tests passed\n";

exit($failed ? 1 : 0);
