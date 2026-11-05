#!/usr/local/bin/php -q
<?php
/*
 * check_pfsense.php - Nagios/Icinga plugin for pfSense
 *
 * Runs locally on the firewall (through NRPE) and checks pfSense specific
 * status information, reading it with the same PHP functions used by the
 * WebGUI. The plugin is strictly read-only.
 *
 * Usage: check_pfsense.php [global options] <subcommand> [options]
 *
 * Author: Mattia Martinello <mattia@mattiamartinello.com>
 */

/* ======================================================================
 * Constants
 * ====================================================================== */

const CP_NAME = 'check_pfsense';
const CP_VERSION = '1.0.0';

const CP_OK = 0;
const CP_WARNING = 1;
const CP_CRITICAL = 2;
const CP_UNKNOWN = 3;

const CP_STATE_NAMES = [
    CP_OK => 'OK',
    CP_WARNING => 'WARNING',
    CP_CRITICAL => 'CRITICAL',
    CP_UNKNOWN => 'UNKNOWN',
];

// Severity order used to choose the worst state:
// CRITICAL > WARNING > UNKNOWN > OK
const CP_STATE_RANK = [
    CP_OK => 0,
    CP_UNKNOWN => 1,
    CP_WARNING => 2,
    CP_CRITICAL => 3,
];

const CP_DEFAULT_TIMEOUT = 10;
const CP_MAX_TIMEOUT = 600;
const CP_CACHE_DIR = '/var/db/check_pfsense';
const CP_BOOTING_FILE = '/var/run/booting';
const CP_PFSENSE_INCLUDE_PATHS = [
    '/etc/inc',
    '/usr/local/pfSense/include',
    '/usr/local/www',
];

const CP_PERF_UOMS = ['', 's', 'ms', 'us', '%', 'B', 'KB', 'MB', 'GB', 'TB', 'c'];

// dpinger defaults, same values as return_dpinger_defaults() in gwlb.inc,
// used only if the function is not available
const CP_DPINGER_DEFAULTS = [
    'latencylow' => 200,
    'latencyhigh' => 500,
    'losslow' => 10,
    'losshigh' => 20,
];

/* ======================================================================
 * Exceptions and result
 * ====================================================================== */

/** Error that makes the check exit with UNKNOWN and a clear message. */
class CPException extends Exception
{
}

/** Wrong command line usage: exits with UNKNOWN too. */
class CPUsageException extends CPException
{
}

/** Result of a check: state, first line message, perfdata and long output. */
final class CPResult
{
    /**
     * @param int      $state    Nagios state (CP_OK ... CP_UNKNOWN)
     * @param string   $message  Message of the first output line
     * @param string[] $perfdata Perfdata items, already formatted
     * @param string[] $long     Long output lines (after the first line)
     * @param bool     $help     Help/version text: printed as is, exit 0
     */
    public function __construct(
        public int $state,
        public string $message,
        public array $perfdata = [],
        public array $long = [],
        public bool $help = false
    ) {
    }
}

/* ======================================================================
 * Core helpers (pure functions)
 * ====================================================================== */

/** Return the worst state, ordered CRITICAL > WARNING > UNKNOWN > OK. */
function cp_worst_state(int ...$states): int
{
    $worst = CP_OK;
    foreach ($states as $state) {
        if (CP_STATE_RANK[$state] > CP_STATE_RANK[$worst]) {
            $worst = $state;
        }
    }
    return $worst;
}

/** Format a number without exponent and trailing zeros. */
function cp_format_number(int|float $value, int $decimals = 6): string
{
    if (is_int($value)) {
        return (string) $value;
    }
    $text = sprintf('%.' . $decimals . 'F', $value);
    if (str_contains($text, '.')) {
        $text = rtrim(rtrim($text, '0'), '.');
    }
    return ($text === '-0') ? '0' : $text;
}

/** Sanitize a perfdata label: only [A-Za-z0-9_.-] are allowed. */
function cp_perf_label(string $label): string
{
    $label = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $label);
    $label = trim($label, '_');
    return ($label === '') ? 'value' : $label;
}

/**
 * Build a perfdata item: 'label'=value[UOM];[warn];[crit];[min];[max]
 *
 * Empty fields are empty strings, non finite values become "U" (unknown).
 */
function cp_perf(
    string $label,
    int|float|null $value,
    string $uom = '',
    CPThreshold|string|int|float|null $warn = null,
    CPThreshold|string|int|float|null $crit = null,
    int|float|null $min = null,
    int|float|null $max = null
): string {
    if (!in_array($uom, CP_PERF_UOMS, true)) {
        throw new LogicException("Invalid perfdata UOM '{$uom}'");
    }

    $field = function ($item): string {
        if ($item === null) {
            return '';
        }
        if ($item instanceof CPThreshold) {
            return $item->spec;
        }
        if (is_string($item)) {
            return preg_replace('/[^0-9.:~@-]/', '', $item);
        }
        return is_finite((float) $item) ? cp_format_number($item) : '';
    };

    if ($value === null || !is_finite((float) $value)) {
        $text = 'U';
    } else {
        $text = cp_format_number($value) . $uom;
    }

    $fields = [$text, $field($warn), $field($crit), $field($min), $field($max)];
    $text = rtrim(implode(';', $fields), ';');

    return "'" . cp_perf_label($label) . "'=" . $text;
}

/** Remove characters that would break the plugin output from a text. */
function cp_clean_text(string $text): string
{
    $text = str_replace('|', '/', $text);
    $text = preg_replace('/[\r\n\t]+/', ' ', $text);
    return trim(preg_replace('/ {2,}/', ' ', $text));
}

/**
 * Check if a value matches a simple glob pattern (* and ?), ignoring case.
 */
function cp_glob_match(string $pattern, string $value): bool
{
    $regex = preg_quote($pattern, '/');
    $regex = str_replace(['\*', '\?'], ['.*', '.'], $regex);
    return (bool) preg_match('/^' . $regex . '$/i', $value);
}

/**
 * Apply --include/--exclude filters to an item identified by one or more
 * names (example: name and description). An item is kept if it matches at
 * least one include pattern (or there are no include patterns) and does
 * not match any exclude pattern.
 *
 * @param string[] $names
 * @param string[] $include
 * @param string[] $exclude
 */
function cp_filter_match(array $names, array $include, array $exclude): bool
{
    $names = array_filter($names, fn ($name) => $name !== '' && $name !== null);
    $matches = function (array $patterns) use ($names): bool {
        foreach ($patterns as $pattern) {
            foreach ($names as $name) {
                if (cp_glob_match($pattern, (string) $name)) {
                    return true;
                }
            }
        }
        return false;
    };

    if ($include && !$matches($include)) {
        return false;
    }
    return !$matches($exclude);
}

/**
 * Build the result of a check with many items.
 *
 * Each item is an array with:
 *  - state:  Nagios state of the item;
 *  - text:   short text used in the first line when the item is not OK;
 *  - detail: text for the long output (default: text).
 *
 * The first line lists the items with problems first (worst first), then
 * the summary.
 */
function cp_aggregate(array $items, string $summary, array $perfdata = []): CPResult
{
    $states = array_map(fn ($item) => $item['state'], $items);
    $state = cp_worst_state(CP_OK, ...$states);

    $problems = array_values(array_filter($items, fn ($item) => $item['state'] !== CP_OK));
    // Stable sort, worst state first
    $order = array_keys($problems);
    usort($order, function ($a, $b) use ($problems) {
        $rank = CP_STATE_RANK[$problems[$b]['state']] <=> CP_STATE_RANK[$problems[$a]['state']];
        return $rank ?: $a <=> $b;
    });

    $texts = array_map(fn ($idx) => $problems[$idx]['text'], $order);
    $message = $texts ? implode(', ', $texts) . '; ' . $summary : $summary;

    $long = [];
    foreach ($items as $item) {
        $long[] = '[' . CP_STATE_NAMES[$item['state']] . '] ' . ($item['detail'] ?? $item['text']);
    }

    return new CPResult($state, $message, $perfdata, $long);
}

/**
 * Render a result as the plugin output.
 *
 * @return array{0: string, 1: int} output text and exit code
 */
function cp_render(CPResult $result, bool $verbose): array
{
    if ($result->help) {
        return [rtrim($result->message) . "\n", 0];
    }

    $state = array_key_exists($result->state, CP_STATE_NAMES) ? $result->state : CP_UNKNOWN;
    $line = CP_STATE_NAMES[$state] . ' - ' . cp_clean_text($result->message);
    if ($result->perfdata) {
        $line .= ' | ' . implode(' ', $result->perfdata);
    }

    $output = $line . "\n";
    if ($verbose) {
        foreach ($result->long as $long) {
            $output .= cp_clean_text($long) . "\n";
        }
    }

    return [$output, $state];
}

/* ======================================================================
 * Thresholds (Nagios range syntax)
 * ====================================================================== */

/**
 * Nagios threshold range:
 *   10     alert if < 0 or > 10
 *   10:    alert if < 10
 *   ~:10   alert if > 10
 *   10:20  alert if < 10 or > 20
 *   @10:20 alert if >= 10 and <= 20
 */
final class CPThreshold
{
    public function __construct(
        public readonly string $spec,
        public readonly float $start,
        public readonly float $end,
        public readonly bool $inside
    ) {
    }

    public static function parse(string $spec, string $option): self
    {
        $text = trim($spec);
        if ($text === '') {
            throw new CPUsageException("Empty threshold for {$option}");
        }

        $inside = false;
        if ($text[0] === '@') {
            $inside = true;
            $text = substr($text, 1);
        }

        $number = function (string $value) use ($spec, $option): float {
            if (!is_numeric($value)) {
                throw new CPUsageException("Invalid threshold '{$spec}' for {$option}");
            }
            return (float) $value;
        };

        if (!str_contains($text, ':')) {
            $start = 0.0;
            $end = $number($text);
        } else {
            [$low, $high] = explode(':', $text, 2);
            $start = ($low === '~') ? -INF : (($low === '') ? 0.0 : $number($low));
            $end = ($high === '') ? INF : $number($high);
        }

        if ($start > $end) {
            throw new CPUsageException("Invalid threshold '{$spec}' for {$option}: start is greater than end");
        }

        return new self(trim($spec), $start, $end, $inside);
    }

    /** True if the value must raise an alert. */
    public function alert(float $value): bool
    {
        $in = ($value >= $this->start) && ($value <= $this->end);
        return $this->inside ? $in : !$in;
    }
}

/**
 * Check that warning and critical thresholds are coherent: for "high is
 * bad" ranges (0:N) the warning must not be greater than the critical, for
 * "low is bad" ranges (N:) the warning must not be lower than the critical.
 */
function cp_check_threshold_order(?CPThreshold $warn, ?CPThreshold $crit): void
{
    if ($warn === null || $crit === null || $warn->inside || $crit->inside) {
        return;
    }

    $upper = fn (CPThreshold $t) => is_finite($t->end) && !is_finite($t->start) || ($t->start == 0.0 && is_finite($t->end));
    $lower = fn (CPThreshold $t) => is_finite($t->start) && !is_finite($t->end);

    if ($upper($warn) && $upper($crit) && $warn->end > $crit->end) {
        throw new CPUsageException("Warning threshold ({$warn->spec}) is greater than critical threshold ({$crit->spec})");
    }
    if ($lower($warn) && $lower($crit) && $warn->start < $crit->start) {
        throw new CPUsageException("Warning threshold ({$warn->spec}) is lower than critical threshold ({$crit->spec})");
    }
}

/** State of a value compared with warning and critical thresholds. */
function cp_threshold_state(float $value, ?CPThreshold $warn, ?CPThreshold $crit): int
{
    if ($crit !== null && $crit->alert($value)) {
        return CP_CRITICAL;
    }
    if ($warn !== null && $warn->alert($value)) {
        return CP_WARNING;
    }
    return CP_OK;
}

/* ======================================================================
 * Command line options
 * ====================================================================== */

/** Global options, valid for every subcommand. */
function cp_global_options(): array
{
    return [
        'warning' => [
            'short' => 'w', 'type' => 'string', 'metavar' => 'RANGE',
            'help' => 'Warning threshold (meaning depends on the subcommand)',
        ],
        'critical' => [
            'short' => 'c', 'type' => 'string', 'metavar' => 'RANGE',
            'help' => 'Critical threshold (meaning depends on the subcommand)',
        ],
        'timeout' => [
            'short' => 't', 'type' => 'int', 'default' => CP_DEFAULT_TIMEOUT, 'metavar' => 'SEC',
            'help' => 'Timeout for external commands and sockets in seconds (default: ' . CP_DEFAULT_TIMEOUT . ')',
        ],
        'verbose' => [
            'short' => 'v', 'type' => 'flag',
            'help' => 'Add detail lines after the first line (long output)',
        ],
        'help' => [
            'short' => 'h', 'type' => 'flag',
            'help' => 'Show the help (of the subcommand, if given) and exit',
        ],
        'version' => [
            'short' => 'V', 'type' => 'flag',
            'help' => 'Show the version and exit',
        ],
    ];
}

/** --include/--exclude option definitions. */
function cp_filter_options(string $what): array
{
    return [
        'include' => [
            'type' => 'list', 'metavar' => 'LIST',
            'help' => "Check only the {$what} matching these comma separated glob patterns",
        ],
        'exclude' => [
            'type' => 'list', 'metavar' => 'LIST',
            'help' => "Do not check the {$what} matching these comma separated glob patterns",
        ],
    ];
}

/**
 * Parse the command line.
 *
 * Options can be given before and after the subcommand, because the NRPE
 * package GUI builds the command as "<command> -w X -c Y <extra options>".
 *
 * @param string[] $args     Arguments without the program name
 * @param array    $commands Subcommand definitions
 *
 * @return array{command: ?string, options: array, help: bool}
 */
function cp_parse_args(array $args, array $commands): array
{
    $global = cp_global_options();

    // Map of every known option (global and of every subcommand), used to
    // know which options take a value before the subcommand is known
    $known = $global;
    foreach ($commands as $command) {
        $known += $command['options'] ?? [];
    }

    // First pass: find the subcommand and the help flag
    $command = null;
    $help = false;
    $tokens = cp_tokenize($args, $known, false);
    foreach ($tokens as $token) {
        if ($token['type'] === 'positional') {
            $command ??= $token['value'];
        } elseif ($token['name'] === 'help') {
            $help = true;
        }
    }

    if ($command !== null && !isset($commands[$command])) {
        throw new CPUsageException("Unknown subcommand '{$command}'. Run '" . CP_NAME . ".php list' to list the subcommands");
    }

    if ($help) {
        return ['command' => $command, 'options' => [], 'help' => true];
    }

    // Second pass: strict parsing with the options of the subcommand
    $spec = $global + (($command !== null) ? ($commands[$command]['options'] ?? []) : []);
    $options = [];
    foreach ($spec as $name => $def) {
        $options[$name] = $def['default'] ?? (($def['type'] === 'flag') ? false : (($def['type'] === 'list') ? [] : null));
    }

    $seenCommand = false;
    foreach (cp_tokenize($args, $spec, true, $command) as $token) {
        if ($token['type'] === 'positional') {
            if (!$seenCommand && $token['value'] === $command) {
                $seenCommand = true;
                continue;
            }
            throw new CPUsageException("Unexpected argument '{$token['value']}'");
        }

        $name = $token['name'];
        $def = $spec[$name];
        $options[$name] = cp_convert_option($name, $def, $token['value'], $options[$name]);
    }

    return ['command' => $command, 'options' => $options, 'help' => false];
}

/**
 * Split arguments into options and positional arguments.
 *
 * @param bool $strict Throw on unknown options (otherwise they are skipped)
 */
function cp_tokenize(array $args, array $spec, bool $strict, ?string $command = null): array
{
    $shorts = [];
    foreach ($spec as $name => $def) {
        if (isset($def['short'])) {
            $shorts[$def['short']] = $name;
        }
    }

    $unknown = function (string $option) use ($strict, $command) {
        if ($strict) {
            $hint = ($command !== null)
                ? "See '" . CP_NAME . ".php {$command} --help'"
                : "See '" . CP_NAME . ".php --help'";
            $where = ($command !== null) ? " for subcommand '{$command}'" : '';
            throw new CPUsageException("Unknown option '{$option}'{$where}. {$hint}");
        }
    };

    $tokens = [];
    $count = count($args);
    for ($i = 0; $i < $count; $i++) {
        $arg = (string) $args[$i];

        if ($arg === '--') {
            for ($j = $i + 1; $j < $count; $j++) {
                $tokens[] = ['type' => 'positional', 'value' => (string) $args[$j]];
            }
            break;
        }

        if (str_starts_with($arg, '--')) {
            $name = substr($arg, 2);
            $value = null;
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }
            if (!isset($spec[$name])) {
                $unknown("--{$name}");
                continue;
            }
            if ($spec[$name]['type'] === 'flag') {
                if ($value !== null && $strict) {
                    throw new CPUsageException("Option --{$name} does not take a value");
                }
                $tokens[] = ['type' => 'option', 'name' => $name, 'value' => true];
                continue;
            }
            if ($value === null) {
                if ($i + 1 >= $count) {
                    if ($strict) {
                        throw new CPUsageException("Option --{$name} requires a value");
                    }
                    continue;
                }
                $value = (string) $args[++$i];
            }
            $tokens[] = ['type' => 'option', 'name' => $name, 'value' => $value];
            continue;
        }

        if (strlen($arg) > 1 && $arg[0] === '-') {
            // Short options: -v, -vh, -w 80, -w80
            $chars = substr($arg, 1);
            for ($k = 0; $k < strlen($chars); $k++) {
                $short = $chars[$k];
                if (!isset($shorts[$short])) {
                    $unknown("-{$short}");
                    continue;
                }
                $name = $shorts[$short];
                if ($spec[$name]['type'] === 'flag') {
                    $tokens[] = ['type' => 'option', 'name' => $name, 'value' => true];
                    continue;
                }
                $value = substr($chars, $k + 1);
                if ($value === '') {
                    if ($i + 1 >= $count) {
                        if ($strict) {
                            throw new CPUsageException("Option -{$short} requires a value");
                        }
                        break;
                    }
                    $value = (string) $args[++$i];
                }
                $tokens[] = ['type' => 'option', 'name' => $name, 'value' => $value];
                break;
            }
            continue;
        }

        $tokens[] = ['type' => 'positional', 'value' => $arg];
    }

    return $tokens;
}

/** Convert and validate the value of an option. */
function cp_convert_option(string $name, array $def, mixed $value, mixed $current): mixed
{
    switch ($def['type']) {
        case 'flag':
            return true;

        case 'int':
            if (!preg_match('/^[0-9]+$/', (string) $value)) {
                throw new CPUsageException("Option --{$name} requires an integer, got '{$value}'");
            }
            return (int) $value;

        case 'float':
            if (!is_numeric($value)) {
                throw new CPUsageException("Option --{$name} requires a number, got '{$value}'");
            }
            return (float) $value;

        case 'list':
            $items = array_filter(array_map('trim', explode(',', (string) $value)), 'strlen');
            return array_values(array_merge($current ?? [], $items));

        case 'choice':
            $value = strtolower((string) $value);
            if (!in_array($value, $def['choices'], true)) {
                throw new CPUsageException("Option --{$name} must be one of: " . implode(', ', $def['choices']) . " (got '{$value}')");
            }
            return $value;

        default:
            if ((string) $value === '') {
                throw new CPUsageException("Option --{$name} requires a value");
            }
            return (string) $value;
    }
}

/**
 * Validate the global options and the thresholds of a subcommand. Adds the
 * parsed thresholds as "warn" and "crit" (CPThreshold or null).
 */
function cp_prepare_options(string $command, array $def, array $options): array
{
    if ($options['timeout'] < 1 || $options['timeout'] > CP_MAX_TIMEOUT) {
        throw new CPUsageException('Timeout must be between 1 and ' . CP_MAX_TIMEOUT . ' seconds');
    }

    $thresholds = $def['thresholds'] ?? null;
    if ($thresholds === null) {
        foreach (['warning' => '-w', 'critical' => '-c'] as $name => $flag) {
            if ($options[$name] !== null) {
                throw new CPUsageException("Subcommand '{$command}' does not use {$flag} thresholds. See '" . CP_NAME . ".php {$command} --help'");
            }
        }
        $options['warn'] = null;
        $options['crit'] = null;
        return $options;
    }

    $warn = $options['warning'] ?? $thresholds['warning'] ?? null;
    $crit = $options['critical'] ?? $thresholds['critical'] ?? null;
    $options['warn'] = ($warn !== null) ? CPThreshold::parse((string) $warn, '-w') : null;
    $options['crit'] = ($crit !== null) ? CPThreshold::parse((string) $crit, '-c') : null;
    cp_check_threshold_order($options['warn'], $options['crit']);

    return $options;
}

/* ======================================================================
 * Help
 * ====================================================================== */

function cp_format_option_help(string $name, array $def): string
{
    $flags = isset($def['short']) ? "-{$def['short']}, --{$name}" : "    --{$name}";
    if ($def['type'] !== 'flag') {
        $flags .= ' ' . ($def['metavar'] ?? 'VALUE');
    }
    $help = $def['help'] ?? '';
    if ($def['type'] === 'choice') {
        $help .= ' (' . implode('|', $def['choices']) . ')';
    }
    return sprintf('  %-28s %s', $flags, $help);
}

function cp_help(array $commands, ?string $command): string
{
    $global = cp_global_options();
    $script = CP_NAME . '.php';

    if ($command === null) {
        $lines = [
            CP_NAME . ' v' . CP_VERSION . ' - Nagios/Icinga plugin for pfSense',
            '',
            "Usage: {$script} [options] <subcommand> [subcommand options]",
            '',
            'Options can be given before or after the subcommand.',
            "Run '{$script} <subcommand> --help' for the options of a subcommand.",
            '',
            'Subcommands:',
        ];
        foreach ($commands as $name => $def) {
            $lines[] = sprintf('  %-16s %s', $name, $def['summary']);
        }
        $lines[] = '';
        $lines[] = 'Global options:';
        foreach ($global as $name => $def) {
            $lines[] = cp_format_option_help($name, $def);
        }
        return implode("\n", $lines);
    }

    $def = $commands[$command];
    $lines = [
        "Usage: {$script} {$command} [options]",
        '',
        $def['summary'] . '.',
    ];
    if (!empty($def['description'])) {
        $lines[] = '';
        foreach ((array) $def['description'] as $line) {
            $lines[] = $line;
        }
    }

    $thresholds = $def['thresholds'] ?? null;
    if ($thresholds !== null) {
        $lines[] = '';
        $lines[] = 'Thresholds:';
        $lines[] = sprintf('  %-28s %s', '-w, --warning RANGE', $thresholds['help_warning'] . (isset($thresholds['warning']) ? " (default: {$thresholds['warning']})" : ''));
        $lines[] = sprintf('  %-28s %s', '-c, --critical RANGE', $thresholds['help_critical'] . (isset($thresholds['critical']) ? " (default: {$thresholds['critical']})" : ''));
    }

    if (!empty($def['options'])) {
        $lines[] = '';
        $lines[] = 'Options:';
        foreach ($def['options'] as $name => $option) {
            $lines[] = cp_format_option_help($name, $option);
        }
    }

    $lines[] = '';
    $lines[] = 'Global options:';
    foreach ($global as $name => $option) {
        if (in_array($name, ['warning', 'critical'], true)) {
            continue;
        }
        $lines[] = cp_format_option_help($name, $option);
    }

    return implode("\n", $lines);
}

/* ======================================================================
 * External commands and cache
 * ====================================================================== */

/**
 * Run an external command with a timeout.
 *
 * @param string[] $command Command and arguments (the command must be an
 *                          absolute path)
 *
 * @return array{code: int, stdout: string, stderr: string}
 */
function cp_run(array $command, int $timeout): array
{
    $binary = $command[0];
    if (!is_file($binary) || !is_executable($binary)) {
        throw new CPException("Command not found: {$binary}");
    }

    $line = 'exec ' . implode(' ', array_map('escapeshellarg', $command));
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($line, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new CPException("Cannot run command: {$binary}");
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = [1 => '', 2 => ''];
    $code = null;
    $deadline = microtime(true) + $timeout;

    while (true) {
        $read = [];
        foreach ([1, 2] as $fd) {
            if (!feof($pipes[$fd])) {
                $read[] = $pipes[$fd];
            }
        }

        if ($read) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $write = $except = null;
            $seconds = (int) $remaining;
            $micro = (int) (($remaining - $seconds) * 1000000);
            if (@stream_select($read, $write, $except, $seconds, $micro) === false) {
                break;
            }
            foreach ([1, 2] as $fd) {
                $chunk = fread($pipes[$fd], 65536);
                if ($chunk !== false) {
                    $output[$fd] .= $chunk;
                }
            }
            continue;
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            $code = $status['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            break;
        }
        usleep(10000);
    }

    if ($code === null) {
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new CPException("Command {$binary} timed out after {$timeout} seconds");
        }
        $code = $status['exitcode'];
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    if ($code === -1) {
        $code = $closed;
    }

    return ['code' => (int) $code, 'stdout' => $output[1], 'stderr' => $output[2]];
}

/** Run a command and throw if it fails. Returns stdout. */
function cp_run_ok(array $command, int $timeout): string
{
    $result = cp_run($command, $timeout);
    if ($result['code'] !== 0) {
        $error = trim($result['stderr']) ?: trim($result['stdout']);
        $lines = preg_split('/\R/', $error);
        $error = $error !== '' ? ': ' . end($lines) : '';
        throw new CPException(basename($command[0]) . " exited with status {$result['code']}{$error}");
    }
    return $result['stdout'];
}

/**
 * Read a cached value. Returns null if missing, expired or invalid.
 */
function cp_cache_get(string $key, int $ttl, string $dir = CP_CACHE_DIR): mixed
{
    $file = $dir . '/' . cp_perf_label($key) . '.json';
    if ($ttl <= 0 || !is_file($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || !isset($data['time']) || !array_key_exists('data', $data)) {
        return null;
    }
    if ((time() - (int) $data['time']) > $ttl) {
        return null;
    }
    return $data['data'];
}

/** Write a value to the cache atomically (temporary file + rename). */
function cp_cache_set(string $key, mixed $value, string $dir = CP_CACHE_DIR): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new CPException("Cannot create cache directory {$dir}");
    }
    $file = $dir . '/' . cp_perf_label($key) . '.json';
    $tmp = tempnam($dir, '.tmp_');
    if ($tmp === false) {
        throw new CPException("Cannot create temporary file in {$dir}");
    }
    $json = json_encode(['time' => time(), 'data' => $value], JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        throw new CPException("Cannot write cache file {$file}");
    }
}

/** Effective user ID of the process, or null if it cannot be found. */
function cp_euid(): ?int
{
    if (function_exists('posix_geteuid')) {
        return posix_geteuid();
    }
    try {
        $output = trim(cp_run_ok(['/usr/bin/id', '-u'], 5));
    } catch (CPException) {
        return null;
    }
    return ctype_digit($output) ? (int) $output : null;
}

/* ======================================================================
 * Subcommand: gateways
 * ====================================================================== */

/**
 * Read the status line of a dpinger socket: "<name> <avg> <stddev> <loss>"
 * (latency in microseconds, loss in percent).
 *
 * As get_dpinger_status() in gwlb.inc, retries while dpinger returns
 * "<name> 0 0 0" (just started), until the deadline.
 *
 * @return array{raw: ?string, error: ?string}
 */
function cp_dpinger_read(string $socket, float $deadline): array
{
    if (!file_exists($socket)) {
        return ['raw' => null, 'error' => 'dpinger status socket not found'];
    }

    $raw = null;
    do {
        $remaining = max(0.1, $deadline - microtime(true));
        $fp = @stream_socket_client('unix://' . $socket, $errno, $errstr, $remaining);
        if (!$fp) {
            return ['raw' => null, 'error' => "cannot connect to dpinger socket: {$errstr}"];
        }
        stream_set_timeout($fp, (int) ceil($remaining));
        $raw = stream_get_contents($fp);
        fclose($fp);
        if ($raw === false) {
            return ['raw' => null, 'error' => 'cannot read dpinger socket'];
        }
        $raw = trim($raw);

        $fields = preg_split('/\s+/', $raw);
        if (count($fields) >= 4 && ($fields[2] !== '0' || $fields[3] !== '0')) {
            return ['raw' => $raw, 'error' => null];
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    return ['raw' => $raw, 'error' => 'dpinger is still initializing'];
}

/**
 * Collect gateway data.
 *
 * return_gateways_status() is not used: on pfSense 2.9 it calls
 * get_gateways(), that can call write_config() through refresh_gateways().
 * return_gateways_array() and running_dpinger_processes() are read-only;
 * the dpinger sockets are read directly (get_dpinger_status() changed its
 * signature between 2.7 and 2.9 and writes to the system log on errors).
 */
function cp_collect_gateways(array $options): array
{
    foreach (['return_gateways_array', 'running_dpinger_processes'] as $function) {
        if (!function_exists($function)) {
            throw new CPException("Function {$function}() not found: unsupported pfSense version");
        }
    }

    // Same gateways shown in Status > Gateways: enabled gateways on enabled
    // and active interfaces
    $gateways = return_gateways_array(false, false, false);
    $processes = running_dpinger_processes();
    $defaults = function_exists('return_dpinger_defaults') ? return_dpinger_defaults() : CP_DPINGER_DEFAULTS;

    $start = microtime(true);
    $dpinger = [];
    foreach ($processes as $name => $process) {
        // Like get_dpinger_status(): up to 3 seconds per gateway, within
        // the global timeout
        $deadline = min(microtime(true) + 3, $start + $options['timeout'] - 0.5);
        $read = cp_dpinger_read($process['socket'] ?? '', $deadline);
        $dpinger[$name] = [
            'srcip' => $process['srcip'] ?? '',
            'targetip' => $process['targetip'] ?? '',
            'raw' => $read['raw'],
            'error' => $read['error'],
        ];
    }

    return ['gateways' => $gateways, 'dpinger' => $dpinger, 'defaults' => $defaults];
}

/**
 * Parse the dpinger status line.
 *
 * @return array{delay: float, stddev: float, loss: float}|null
 */
function cp_dpinger_parse(?string $raw): ?array
{
    if ($raw === null) {
        return null;
    }
    $fields = preg_split('/\s+/', trim($raw));
    if (count($fields) < 4 || !is_numeric($fields[1]) || !is_numeric($fields[2]) || !is_numeric($fields[3])) {
        return null;
    }
    // Same conversions of get_dpinger_status(): microseconds to ms
    return [
        'delay' => round($fields[1] / 1000, 3),
        'stddev' => round($fields[2] / 1000, 3),
        'loss' => (float) $fields[3],
    ];
}

/**
 * Classify a gateway like get_dpinger_status() and get_gateway_status()
 * of pfSense 2.9, with the levels of get_gateway_status_text().
 *
 * @return array{status: string, state: int, online: bool}
 */
function cp_gateway_classify(array $gateway, ?array $metrics, array $limits, int $pendingState): array
{
    if (isset($gateway['monitor_disable'])) {
        return ['status' => 'online (unmonitored)', 'state' => CP_OK, 'online' => true];
    }
    if (isset($gateway['force_down'])) {
        return ['status' => 'offline (forced)', 'state' => CP_WARNING, 'online' => false];
    }
    if ($metrics === null) {
        return ['status' => 'pending', 'state' => $pendingState, 'online' => false];
    }

    if ($metrics['delay'] > $limits['latencyhigh']) {
        return ['status' => 'offline (latency)', 'state' => CP_CRITICAL, 'online' => false];
    }
    if ($metrics['loss'] > $limits['losshigh']) {
        return ['status' => 'offline (packet loss)', 'state' => CP_CRITICAL, 'online' => false];
    }
    if ($metrics['delay'] > $limits['latencylow']) {
        return ['status' => 'online (latency)', 'state' => CP_WARNING, 'online' => true];
    }
    if ($metrics['loss'] > $limits['losslow']) {
        return ['status' => 'online (packet loss)', 'state' => CP_WARNING, 'online' => true];
    }
    return ['status' => 'online', 'state' => CP_OK, 'online' => true];
}

function cp_evaluate_gateways(array $data, array $options): CPResult
{
    $pendingState = array_search(strtoupper($options['pending'] ?? 'unknown'), CP_STATE_NAMES, true);
    $defaults = array_merge(CP_DPINGER_DEFAULTS, (array) ($data['defaults'] ?? []));

    $items = [];
    $perfdata = [];
    $total = 0;
    $online = 0;

    foreach ((array) ($data['gateways'] ?? []) as $key => $gateway) {
        if (!is_array($gateway)) {
            continue;
        }
        $name = (string) ($gateway['name'] ?? $key);
        if ($name === 'Null4' || $name === 'Null6') {
            continue;
        }
        $total++;
        $descr = (string) ($gateway['descr'] ?? '');
        if (!cp_filter_match([$name, $descr], $options['include'] ?? [], $options['exclude'] ?? [])) {
            continue;
        }

        // dpinger thresholds: defaults replaced by the gateway settings
        $limits = [];
        foreach (['latencylow', 'latencyhigh', 'losslow', 'losshigh'] as $limit) {
            $value = $gateway[$limit] ?? null;
            $limits[$limit] = (float) (is_numeric($value) ? $value : $defaults[$limit]);
        }

        $dpinger = $data['dpinger'][$name] ?? null;
        $metrics = cp_dpinger_parse($dpinger['raw'] ?? null);
        if ($dpinger !== null && ($dpinger['error'] ?? null) !== null) {
            $metrics = null;
        }

        $class = cp_gateway_classify($gateway, $metrics, $limits, $pendingState);
        if ($class['online']) {
            $online++;
        }

        $text = "{$name} {$class['status']}";
        $reason = null;
        if ($metrics !== null && !isset($gateway['monitor_disable'])) {
            $text .= sprintf(' (delay %sms, loss %s%%)', cp_format_number($metrics['delay'], 3), cp_format_number($metrics['loss'], 1));
        } elseif ($class['status'] === 'pending') {
            $reason = $dpinger['error'] ?? 'dpinger not running';
            $text .= " ({$reason})";
        }

        $detail = $name;
        $info = array_filter([
            $descr,
            $gateway['friendlyifdescr'] ?? $gateway['friendlyiface'] ?? '',
            !empty($gateway['gateway']) ? "gateway {$gateway['gateway']}" : '',
            !empty($dpinger['targetip']) ? "monitor {$dpinger['targetip']}" : '',
            isset($gateway['isdefaultgw']) ? 'default' : '',
        ]);
        if ($info) {
            $detail .= ' (' . implode(', ', $info) . ')';
        }
        $detail .= ": {$class['status']}";
        if ($metrics !== null && !isset($gateway['monitor_disable'])) {
            $detail .= sprintf(
                ', delay %sms, stddev %sms, loss %s%% (thresholds: delay %s/%sms, loss %s/%s%%)',
                cp_format_number($metrics['delay'], 3),
                cp_format_number($metrics['stddev'], 3),
                cp_format_number($metrics['loss'], 1),
                cp_format_number($limits['latencylow']),
                cp_format_number($limits['latencyhigh']),
                cp_format_number($limits['losslow']),
                cp_format_number($limits['losshigh'])
            );
        } elseif ($reason !== null) {
            $detail .= " ({$reason})";
        }

        $items[] = ['state' => $class['state'], 'text' => $text, 'detail' => $detail];

        if ($metrics !== null && !isset($gateway['monitor_disable'])) {
            $perfdata[] = cp_perf("{$name}_delay", $metrics['delay'], 'ms', $limits['latencylow'], $limits['latencyhigh'], 0);
            $perfdata[] = cp_perf("{$name}_loss", $metrics['loss'], '%', $limits['losslow'], $limits['losshigh'], 0, 100);
            $perfdata[] = cp_perf("{$name}_stddev", $metrics['stddev'], 'ms', null, null, 0);
        }
    }

    if ($total === 0) {
        return new CPResult(CP_UNKNOWN, 'No gateways found');
    }
    if (!$items) {
        return new CPResult(CP_UNKNOWN, 'No gateways match the include/exclude filters');
    }

    $summary = sprintf('%d/%d gateways online', $online, count($items));
    return cp_aggregate($items, $summary, $perfdata);
}

/* ======================================================================
 * Subcommand registry
 * ====================================================================== */

function cp_commands(): array
{
    return [
        'gateways' => [
            'summary' => 'Status of the gateways monitored by dpinger',
            'description' => [
                'Same status of Status > Gateways: online is OK, online with latency',
                'or packet loss and offline (forced) are WARNING, offline is CRITICAL.',
                'Gateways with monitoring disabled are OK (unmonitored).',
                'Perfdata: delay, stddev (ms) and loss (%) with the dpinger thresholds.',
            ],
            'libs' => ['config.inc', 'gwlb.inc'],
            'root' => true,
            'thresholds' => null,
            'options' => cp_filter_options('gateways (by name or description)') + [
                'pending' => [
                    'type' => 'choice', 'choices' => ['ok', 'warning', 'critical', 'unknown'],
                    'default' => 'unknown', 'metavar' => 'STATE',
                    'help' => 'State of gateways without dpinger status ("Pending" in the GUI), default: unknown',
                ],
            ],
            'collect' => 'cp_collect_gateways',
            'evaluate' => 'cp_evaluate_gateways',
        ],
        'list' => [
            'summary' => 'List the available subcommands',
            'libs' => [],
            'root' => false,
            'thresholds' => null,
            'options' => [],
            'collect' => null,
            'evaluate' => 'cp_evaluate_list',
        ],
    ];
}

function cp_evaluate_list(array $data, array $options): CPResult
{
    $commands = cp_commands();
    $long = [];
    foreach ($commands as $name => $def) {
        $long[] = "{$name} - {$def['summary']}";
    }
    $result = new CPResult(CP_OK, CP_NAME . ' v' . CP_VERSION . ': ' . count($commands) . ' subcommands available', [], $long);
    return $result;
}

/* ======================================================================
 * Main
 * ====================================================================== */

/**
 * Parse the command line and prepare the execution context.
 *
 * @return CPResult|array Result to print (help, version) or the context
 */
function cp_prepare(array $argv): CPResult|array
{
    $commands = cp_commands();
    $parsed = cp_parse_args(array_slice($argv, 1), $commands);
    $command = $parsed['command'];

    if ($parsed['help']) {
        return new CPResult(CP_OK, cp_help($commands, $command), [], [], true);
    }
    if ($parsed['options']['version']) {
        return new CPResult(CP_OK, CP_NAME . ' v' . CP_VERSION, [], [], true);
    }
    if ($command === null) {
        throw new CPUsageException("Missing subcommand. Run '" . CP_NAME . ".php --help' for the usage");
    }

    $def = $commands[$command];
    $options = cp_prepare_options($command, $def, $parsed['options']);

    return ['command' => $command, 'def' => $def, 'options' => $options];
}

/** Checks done before loading the pfSense libraries. */
function cp_preflight(array $context): void
{
    if (!$context['def']['root']) {
        return;
    }
    $euid = cp_euid();
    if ($euid !== 0) {
        throw new CPException('This check must run as root: enable the Sudo option of the NRPE command (sudo package required)');
    }
    if ($context['def']['libs'] && file_exists(CP_BOOTING_FILE)) {
        throw new CPException('The system is booting, check skipped');
    }
}

/** Run the collect and evaluate steps of the subcommand. */
function cp_execute(array $context): CPResult
{
    $def = $context['def'];
    $data = [];
    if ($def['collect'] !== null) {
        ob_start();
        try {
            $data = ($def['collect'])($context['options']);
        } finally {
            ob_end_clean();
        }
    }
    return ($def['evaluate'])($data, $context['options']);
}

/** Convert an exception to a result. */
function cp_result_from_exception(Throwable $e): CPResult
{
    if ($e instanceof CPUsageException) {
        return new CPResult(CP_UNKNOWN, 'Usage error: ' . $e->getMessage());
    }
    if ($e instanceof CPException) {
        return new CPResult(CP_UNKNOWN, $e->getMessage());
    }
    return new CPResult(CP_UNKNOWN, 'Internal error: ' . get_class($e) . ': ' . $e->getMessage());
}

/** Print the result and exit. */
function cp_emit(CPResult $result, bool $verbose): never
{
    $GLOBALS['cp_emitted'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    [$output, $code] = cp_render($result, $verbose);
    fwrite(STDOUT, $output);
    exit($code);
}

/** Print UNKNOWN if the script ends without output (fatal error, exit). */
function cp_shutdown(): void
{
    if (!empty($GLOBALS['cp_emitted'])) {
        return;
    }
    $buffer = '';
    while (ob_get_level() > 0) {
        $buffer = ob_get_clean() . $buffer;
    }
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $message = 'PHP fatal error: ' . $error['message'];
    } else {
        $lines = array_filter(preg_split('/\R/', trim($buffer)));
        $message = 'Unexpected exit' . ($lines ? ': ' . reset($lines) : '');
    }
    cp_emit(new CPResult(CP_UNKNOWN, $message), false);
}

if (!defined('CHECK_PFSENSE_LIBRARY')) {
    // Only one line on stdout: never print PHP errors and warnings
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    error_reporting(E_ALL);
    register_shutdown_function('cp_shutdown');

    $cp_verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
    try {
        $cp_context = cp_prepare($argv);
        if ($cp_context instanceof CPResult) {
            cp_emit($cp_context, false);
        }
        $cp_verbose = $cp_context['options']['verbose'];

        cp_preflight($cp_context);

        // pfSense libraries must be included in the global scope, because
        // they define and use global variables ($g, $config)
        if ($cp_context['def']['libs']) {
            set_include_path(get_include_path() . PATH_SEPARATOR . implode(PATH_SEPARATOR, CP_PFSENSE_INCLUDE_PATHS));
            ob_start();
            foreach ($cp_context['def']['libs'] as $cp_lib) {
                require_once($cp_lib);
            }
            ob_end_clean();
        }

        $cp_result = cp_execute($cp_context);
    } catch (Throwable $cp_error) {
        $cp_result = cp_result_from_exception($cp_error);
    }

    cp_emit($cp_result, $cp_verbose || ($cp_context['command'] ?? null) === 'list');
}
