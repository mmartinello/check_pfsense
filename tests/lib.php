<?php
/*
 * Assertions and helpers for the check_pfsense tests.
 */

final class TestFailure extends Exception
{
    public string $where = '';
}

function fail(string $message): never
{
    $e = new TestFailure($message);
    foreach ($e->getTrace() as $frame) {
        if (isset($frame['file']) && !str_ends_with($frame['file'], 'lib.php')) {
            $e->where = basename($frame['file']) . ':' . $frame['line'];
            break;
        }
    }
    throw $e;
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message ? "{$message}: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $message = 'condition is false'): void
{
    if (!$condition) {
        fail($message);
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail(($message ? "{$message}: " : '') . "'{$needle}' not found in '{$haystack}'");
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        fail(($message ? "{$message}: " : '') . "'{$needle}' found in '{$haystack}'");
    }
}

function assert_matches(string $regex, string $value, string $message = ''): void
{
    if (!preg_match($regex, $value)) {
        fail(($message ? "{$message}: " : '') . "'{$value}' does not match {$regex}");
    }
}

/** Assert that the callable throws an exception of the class. */
function assert_throws(string $class, callable $callable, string $contains = ''): Throwable
{
    try {
        $callable();
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            fail("expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            fail("exception message '{$e->getMessage()}' does not contain '{$contains}'");
        }
        return $e;
    }
    fail("expected {$class}, nothing thrown");
}

/** Load a fixture file: PHP files are included, others are read. */
function fixture(string $path): mixed
{
    $file = __DIR__ . '/fixtures/' . $path;
    if (!is_file($file)) {
        fail("fixture not found: {$path}");
    }
    return str_ends_with($file, '.php') ? require $file : file_get_contents($file);
}

/** Default options of a subcommand, as produced by the argument parser. */
function options_for(string $command, array $args = []): array
{
    $parsed = cp_parse_args(array_merge([$command], $args), cp_commands());
    return cp_prepare_options($command, cp_commands()[$command], $parsed['options']);
}

/** Render a result and check the plugin output format. Returns the output. */
function assert_valid_output(CPResult $result, bool $verbose = false): string
{
    [$output, $code] = cp_render($result, $verbose);
    assert_valid_plugin_output($output, $code);
    return $output;
}

/** Check the format of the plugin output and its exit code. */
function assert_valid_plugin_output(string $output, int $code): void
{
    assert_true(str_ends_with($output, "\n"), 'output must end with a newline');
    $lines = explode("\n", rtrim($output, "\n"));
    $first = $lines[0];

    if (!preg_match('/^(OK|WARNING|CRITICAL|UNKNOWN) - ([^|]*?)(?: \| (.*))?$/', $first, $m)) {
        fail("invalid first line: {$first}");
    }
    $states = ['OK' => 0, 'WARNING' => 1, 'CRITICAL' => 2, 'UNKNOWN' => 3];
    assert_same($states[$m[1]], $code, 'exit code does not match the state');
    assert_true(trim($m[2]) !== '', 'empty message');

    if (isset($m[3])) {
        $uom = '(?:s|ms|us|%|B|KB|MB|GB|TB|c)?';
        $num = '-?[0-9]+(?:\.[0-9]+)?';
        $range = '[0-9.:~@-]*';
        $item = "'[A-Za-z0-9_.-]+'=(?:U|{$num}{$uom})(?:;{$range}(?:;{$range}(?:;(?:{$num})?(?:;(?:{$num})?)?)?)?)?";
        foreach (explode(' ', $m[3]) as $perf) {
            assert_matches("/^{$item}$/", $perf, 'invalid perfdata');
            assert_not_contains('NAN', strtoupper($perf), 'perfdata with NaN');
        }
    }

    foreach (array_slice($lines, 1) as $line) {
        assert_not_contains('|', $line, 'pipe in long output');
    }
}

/**
 * Run the plugin as a separate process.
 *
 * @return array{code: int, stdout: string, stderr: string}
 */
function run_plugin(array $args): array
{
    $command = array_merge([PHP_BINARY, __DIR__ . '/../check_pfsense.php'], $args);
    $line = implode(' ', array_map('escapeshellarg', $command));
    $process = proc_open($line, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}
