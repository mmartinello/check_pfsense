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

    // Subcommand specific checks
    if (isset($def['validate'])) {
        ($def['validate'])($options);
    }

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
 * Gateway statuses, as the GW_STATUS_* constants of pfSense 2.9, with the
 * text and the level of get_gateway_status_text() (null: "Pending", the
 * state is chosen with --pending).
 */
const CP_GW_STATUSES = [
    'online' => ['text' => 'online', 'state' => CP_OK, 'online' => true],
    'online_latency' => ['text' => 'online (latency)', 'state' => CP_WARNING, 'online' => true],
    'online_loss' => ['text' => 'online (packet loss)', 'state' => CP_WARNING, 'online' => true],
    'online_forced' => ['text' => 'online (unmonitored)', 'state' => CP_OK, 'online' => true],
    'offline' => ['text' => 'offline', 'state' => CP_CRITICAL, 'online' => false],
    'offline_latency' => ['text' => 'offline (latency)', 'state' => CP_CRITICAL, 'online' => false],
    'offline_loss' => ['text' => 'offline (packet loss)', 'state' => CP_CRITICAL, 'online' => false],
    'offline_forced' => ['text' => 'offline (forced)', 'state' => CP_WARNING, 'online' => false],
    'pending' => ['text' => 'pending', 'state' => null, 'online' => false],
];

/**
 * dpinger metrics and thresholds of a gateway.
 *
 * @return array{metrics: ?array, limits: array, dpinger: ?array}
 */
function cp_gateway_metrics(string $name, array $gateway, array $data): array
{
    // dpinger thresholds: defaults replaced by the gateway settings
    $defaults = array_merge(CP_DPINGER_DEFAULTS, (array) ($data['defaults'] ?? []));
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

    return ['metrics' => $metrics, 'limits' => $limits, 'dpinger' => $dpinger];
}

/**
 * Status of a gateway (key of CP_GW_STATUSES), like get_dpinger_status()
 * and get_gateway_status() of pfSense 2.9.
 */
function cp_gateway_status(array $gateway, ?array $metrics, array $limits): string
{
    if (isset($gateway['monitor_disable'])) {
        return 'online_forced';
    }
    if (isset($gateway['force_down'])) {
        return 'offline_forced';
    }
    if ($metrics === null) {
        return 'pending';
    }
    if ($metrics['delay'] > $limits['latencyhigh']) {
        return 'offline_latency';
    }
    if ($metrics['loss'] > $limits['losshigh']) {
        return 'offline_loss';
    }
    if ($metrics['delay'] > $limits['latencylow']) {
        return 'online_latency';
    }
    if ($metrics['loss'] > $limits['losslow']) {
        return 'online_loss';
    }
    return 'online';
}

/**
 * Classify a gateway like get_dpinger_status() and get_gateway_status()
 * of pfSense 2.9, with the levels of get_gateway_status_text().
 *
 * @return array{status: string, state: int, online: bool}
 */
function cp_gateway_classify(array $gateway, ?array $metrics, array $limits, int $pendingState): array
{
    $status = CP_GW_STATUSES[cp_gateway_status($gateway, $metrics, $limits)];
    return ['status' => $status['text'], 'state' => $status['state'] ?? $pendingState, 'online' => $status['online']];
}

function cp_evaluate_gateways(array $data, array $options): CPResult
{
    $pendingState = array_search(strtoupper($options['pending'] ?? 'unknown'), CP_STATE_NAMES, true);

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

        ['metrics' => $metrics, 'limits' => $limits, 'dpinger' => $dpinger] = cp_gateway_metrics($name, $gateway, $data);
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
 * Subcommand: gateway-groups
 * ====================================================================== */

/**
 * Collect gateway groups data: the gateway data of cp_collect_gateways()
 * plus the groups of the configuration.
 *
 * return_gateway_groups_array() is not used: through
 * get_gwgroup_members_inner() it creates and removes /tmp/.down.* files,
 * writes to the system log, sends remote notifications and calls package
 * plugins when a member changes state.
 */
function cp_collect_gateway_groups(array $options): array
{
    $data = cp_collect_gateways($options);
    $data['groups'] = [];
    foreach (config_get_path('gateways/gateway_group', []) as $group) {
        if (!is_array($group) || empty($group['name'])) {
            continue;
        }
        $data['groups'][] = [
            'name' => (string) $group['name'],
            'descr' => (string) ($group['descr'] ?? ''),
            'trigger' => (string) ($group['trigger'] ?? ''),
            'item' => array_values(array_map('strval', (array) ($group['item'] ?? []))),
        ];
    }
    return $data;
}

/**
 * True if a gateway with this status can be used by a gateway group with
 * this trigger level, like is_online_gateway_status() of pfSense 2.9 and
 * the member check in filter.inc (members with action disabled are
 * always used).
 */
function cp_gateway_usable(string $status, string $trigger, bool $actionDisabled): bool
{
    if ($actionDisabled) {
        return true;
    }
    $online = ['online', 'online_latency', 'online_loss', 'online_forced'];
    $online = match ($trigger) {
        // Trigger "member down": high packet loss and latency are acceptable
        'down' => array_merge($online, ['offline_latency', 'offline_loss']),
        // Trigger "packet loss": high latency is acceptable
        'downloss' => array_merge($online, ['offline_latency']),
        // Trigger "high latency": high packet loss is acceptable
        'downlatency' => array_merge($online, ['offline_loss']),
        default => $online,
    };
    return in_array($status, $online, true);
}

function cp_evaluate_gateway_groups(array $data, array $options): CPResult
{
    $groups = array_values(array_filter((array) ($data['groups'] ?? []), fn ($group) => is_array($group) && !empty($group['name'])));
    if (!$groups) {
        return new CPResult(CP_UNKNOWN, 'No gateway groups configured');
    }

    $gateways = [];
    foreach ((array) ($data['gateways'] ?? []) as $key => $gateway) {
        if (is_array($gateway)) {
            $gateways[(string) ($gateway['name'] ?? $key)] = $gateway;
        }
    }

    $items = [];
    $perfdata = [];
    $ok = 0;
    foreach ($groups as $group) {
        $name = (string) $group['name'];
        $descr = (string) ($group['descr'] ?? '');
        if (!cp_filter_match([$name, $descr], $options['include'] ?? [], $options['exclude'] ?? [])) {
            continue;
        }
        $trigger = in_array($group['trigger'] ?? '', ['down', 'downloss', 'downlatency', 'downlosslatency'], true)
            ? $group['trigger'] : 'downlosslatency';

        // Members by tier: items are "<gateway>|<tier>|<vip>"
        $members = [];
        foreach ((array) ($group['item'] ?? []) as $item) {
            $parts = explode('|', (string) $item);
            if (count($parts) < 2 || $parts[0] === '' || !is_numeric($parts[1])) {
                continue;
            }
            [$gwname, $tier] = [$parts[0], (int) $parts[1]];
            if (isset($gateways[$gwname])) {
                $gateway = $gateways[$gwname];
                ['metrics' => $metrics, 'limits' => $limits] = cp_gateway_metrics($gwname, $gateway, $data);
                $status = cp_gateway_status($gateway, $metrics, $limits);
                $text = CP_GW_STATUSES[$status]['text'];
                $usable = cp_gateway_usable($status, $trigger, isset($gateway['action_disable']));
            } else {
                // Disabled gateway, or gateway on a disabled interface
                $text = 'unavailable';
                $usable = false;
            }
            $members[] = ['name' => $gwname, 'tier' => $tier, 'status' => $text, 'usable' => $usable];
        }

        if (!$members) {
            $items[] = ['state' => CP_UNKNOWN, 'text' => "{$name} has no valid members"];
            continue;
        }

        usort($members, fn ($a, $b) => $a['tier'] <=> $b['tier']);
        $topTier = $members[0]['tier'];
        $usableTiers = array_column(array_filter($members, fn ($m) => $m['usable']), 'tier');
        $activeTier = $usableTiers ? min($usableTiers) : null;
        $down = array_values(array_filter($members, fn ($m) => !$m['usable']));
        $downText = implode(', ', array_map(fn ($m) => "{$m['name']} {$m['status']}", $down));

        $tierText = [];
        foreach ($members as $member) {
            $tierText[$member['tier']][] = "{$member['name']} {$member['status']}";
        }
        $detail = $name . ($descr !== '' ? " ({$descr})" : '') . ", trigger {$trigger}: ";
        $detail .= ($activeTier === null) ? 'no usable members' : "tier {$activeTier} active";
        foreach ($tierText as $tier => $texts) {
            $detail .= "; tier {$tier}: " . implode(', ', $texts);
        }

        if ($activeTier === null) {
            $state = CP_CRITICAL;
            $text = "{$name} has no usable members ({$downText})";
        } elseif ($activeTier !== $topTier) {
            $state = CP_WARNING;
            $text = "{$name} failover to tier {$activeTier} ({$downText})";
        } elseif ($down) {
            $state = CP_WARNING;
            $text = "{$name} members down ({$downText})";
        } else {
            $state = CP_OK;
            $text = "{$name} tier {$activeTier} active";
            $ok++;
        }
        $items[] = ['state' => $state, 'text' => $text, 'detail' => $detail];

        $perfdata[] = cp_perf("{$name}_active_tier", $activeTier ?? 0, '', null, null, 0);
        $perfdata[] = cp_perf("{$name}_usable", count($members) - count($down), '', null, null, 0, count($members));
    }

    if (!$items) {
        return new CPResult(CP_UNKNOWN, 'No gateway groups match the include/exclude filters');
    }

    $summary = sprintf('%d/%d gateway groups OK', $ok, count($items));
    return cp_aggregate($items, $summary, $perfdata);
}

/* ======================================================================
 * Subcommand: services
 * ====================================================================== */

/**
 * Make get_gateways() read-only.
 *
 * On pfSense 2.9 get_gateways() calls refresh_gateways() when its cache is
 * empty, and refresh_gateways() can call write_config() to save dynamic
 * gateways missing from the config. Filling the cache with the same data
 * computed by refresh_gateways() (return_gateways_array(true, true, true))
 * skips that step. On 2.7 get_gateways() does not exist and nothing is done.
 */
function cp_seed_gateways_cache(): void
{
    if (!function_exists('get_gateways')) {
        return;
    }

    // Make sure this version of get_gateways() uses the cache
    $function = new ReflectionFunction('get_gateways');
    $lines = @file($function->getFileName());
    $source = $lines ? implode('', array_slice($lines, $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1)) : '';
    if (!str_contains($source, 'GatewaysCache')) {
        throw new CPException('Unsupported pfSense version: get_gateways() may write the configuration');
    }

    if (!is_array($GLOBALS['GatewaysCache'] ?? null)) {
        $GLOBALS['GatewaysCache'] = return_gateways_array(true, true, true);
    }
}

/**
 * Collect the services shown in Status > Services, with their status.
 *
 * Uses get_services(), get_service_status() and is_service_enabled() of
 * service-utils.inc, the same functions of the GUI page and widget.
 *
 * @return array{services: array}
 */
function cp_collect_services(array $options): array
{
    foreach (['get_services', 'get_service_status', 'is_service_enabled'] as $function) {
        if (!function_exists($function)) {
            throw new CPException("Function {$function}() not found: unsupported pfSense version");
        }
    }

    cp_seed_gateways_cache();

    $services = [];
    foreach (get_services() as $service) {
        if (!is_array($service) || empty($service['name'])) {
            continue;
        }
        $description = (string) ($service['description'] ?? '');
        if ($description === '' && function_exists('get_pkg_descr')) {
            $description = (string) get_pkg_descr($service['name']);
        }
        $services[] = [
            'name' => (string) $service['name'],
            'description' => html_entity_decode($description, ENT_QUOTES),
            'mode' => $service['mode'] ?? null,
            'vpnid' => $service['vpnid'] ?? null,
            'zone' => $service['zone'] ?? null,
            'running' => (bool) get_service_status($service),
            'enabled' => (bool) is_service_enabled($service['name']),
        ];
    }

    return ['services' => $services];
}

/**
 * Unique identifier of a service: the name, plus the instance for the
 * services with more instances (openvpn_server1, captiveportal_zone).
 */
function cp_service_id(array $service): string
{
    $name = $service['name'];
    if ($name === 'openvpn' && isset($service['mode'], $service['vpnid'])) {
        return "openvpn_{$service['mode']}{$service['vpnid']}";
    }
    if ($name === 'captiveportal' && isset($service['zone'])) {
        return "captiveportal_{$service['zone']}";
    }
    return $name;
}

function cp_evaluate_services(array $data, array $options): CPResult
{
    $services = array_values(array_filter(
        (array) ($data['services'] ?? []),
        fn ($service) => is_array($service) && !empty($service['name'])
    ));
    if (!$services) {
        return new CPResult(CP_UNKNOWN, 'No services found');
    }

    $seen = [];
    $items = [];
    $disabled = 0;
    $running = 0;
    $matched = 0;

    usort($services, fn ($a, $b) => strcasecmp(cp_service_id($a), cp_service_id($b)) ?: strcasecmp($a['description'] ?? '', $b['description'] ?? ''));

    foreach ($services as $service) {
        // Unique identifiers: add a suffix to duplicated ones
        $id = cp_service_id($service);
        $seen[$id] = ($seen[$id] ?? 0) + 1;
        if ($seen[$id] > 1) {
            $id .= '_' . $seen[$id];
        }

        $description = (string) ($service['description'] ?? '');
        if (!cp_filter_match([$id, $service['name'], $description], $options['include'] ?? [], $options['exclude'] ?? [])) {
            continue;
        }
        $matched++;

        $label = $id . ($description !== '' ? " ({$description})" : '');
        $isRunning = !empty($service['running']);
        $isEnabled = !array_key_exists('enabled', $service) || !empty($service['enabled']);

        if ($isRunning) {
            $running++;
            $items[] = ['state' => CP_OK, 'text' => "{$id} running", 'detail' => "{$label}: running"];
        } elseif ($isEnabled) {
            $items[] = ['state' => CP_CRITICAL, 'text' => "{$id} stopped", 'detail' => "{$label}: stopped"];
        } else {
            // Disabled in the configuration: not checked, like the GUI widget
            $disabled++;
        }
    }

    if ($matched === 0) {
        return new CPResult(CP_UNKNOWN, 'No services match the include/exclude filters');
    }
    if (!$items) {
        return new CPResult(CP_OK, "No enabled services to check ({$disabled} disabled)");
    }

    $summary = sprintf('%d/%d services running', $running, count($items));
    if ($disabled > 0) {
        $summary .= " ({$disabled} disabled not checked)";
    }

    $perfdata = [
        cp_perf('running', $running, '', null, null, 0, count($items)),
        cp_perf('stopped', count($items) - $running, '', null, null, 0, count($items)),
    ];

    return cp_aggregate($items, $summary, $perfdata);
}

/* ======================================================================
 * Subcommand: openvpn
 * ====================================================================== */

/**
 * Collect the OpenVPN instances like status_openvpn.php, with the
 * read-only functions of openvpn.inc (they send "status 2" and "state 1"
 * to the management socket of every enabled instance).
 *
 * @return array{servers: array, p2p_servers: array, clients: array}
 */
function cp_collect_openvpn(array $options): array
{
    foreach (['openvpn_get_active_servers', 'openvpn_get_active_clients'] as $function) {
        if (!function_exists($function)) {
            throw new CPException("Function {$function}() not found: unsupported pfSense version");
        }
    }

    $mode = $options['mode'] ?? 'all';
    $servers = ($mode !== 'client');
    $clients = ($mode !== 'server');

    return [
        'servers' => $servers ? openvpn_get_active_servers('multipoint') : [],
        'p2p_servers' => $servers ? openvpn_get_active_servers('p2p') : [],
        'clients' => $clients ? openvpn_get_active_clients() : [],
    ];
}

/**
 * Normalize the OpenVPN instances returned by openvpn.inc.
 *
 * Multipoint servers: an unreachable daemon is reported by
 * openvpn_get_server_status() as a connection with common name "[error]".
 * P2P servers and clients: "state" is set only if the daemon answered.
 *
 * @return array<int, array>
 */
function cp_openvpn_instances(array $data): array
{
    $instances = [];

    foreach ((array) ($data['servers'] ?? []) as $server) {
        if (!is_array($server)) {
            continue;
        }
        $conns = array_values(array_filter((array) ($server['conns'] ?? []), 'is_array'));
        $reachable = !in_array('[error]', array_column($conns, 'common_name'), true);
        $conns = $reachable ? $conns : [];
        $instances[] = [
            'type' => 'server',
            'mode' => 'multipoint',
            'id' => 'server' . ($server['vpnid'] ?? ''),
            'vpnid' => (string) ($server['vpnid'] ?? ''),
            'name' => trim((string) ($server['name'] ?? '')),
            'reachable' => $reachable,
            'state' => null,
            'status' => null,
            'clients' => array_map(fn ($c) => [
                'common_name' => trim((string) ($c['common_name'] ?? '')),
                'user_name' => trim((string) ($c['user_name'] ?? '')),
                'remote_host' => trim((string) ($c['remote_host'] ?? '')),
                'virtual_addr' => trim((string) ($c['virtual_addr'] ?? '')),
            ], $conns),
            'bytes_recv' => array_sum(array_map(fn ($c) => (float) trim((string) ($c['bytes_recv'] ?? 0)), $conns)),
            'bytes_sent' => array_sum(array_map(fn ($c) => (float) trim((string) ($c['bytes_sent'] ?? 0)), $conns)),
        ];
    }

    foreach (['p2p_servers' => 'server', 'clients' => 'client'] as $key => $type) {
        foreach ((array) ($data[$key] ?? []) as $instance) {
            if (!is_array($instance)) {
                continue;
            }
            $state = isset($instance['state']) ? trim((string) $instance['state']) : null;
            $instances[] = [
                'type' => $type,
                'mode' => 'p2p',
                'id' => $type . ($instance['vpnid'] ?? ''),
                'vpnid' => (string) ($instance['vpnid'] ?? ''),
                'name' => trim((string) ($instance['name'] ?? '')),
                'reachable' => $state !== null && $state !== '',
                'state' => $state,
                'status' => trim((string) ($instance['status'] ?? '')),
                'remote_host' => trim((string) ($instance['remote_host'] ?? '')),
                'virtual_addr' => trim((string) ($instance['virtual_addr'] ?? '')),
                'clients' => [],
                'bytes_recv' => (float) trim((string) ($instance['bytes_recv'] ?? 0)),
                'bytes_sent' => (float) trim((string) ($instance['bytes_sent'] ?? 0)),
            ];
        }
    }

    return $instances;
}

function cp_evaluate_openvpn(array $data, array $options): CPResult
{
    $warn = $options['warn'];
    $crit = $options['crit'];
    if (isset($options['min-clients'])) {
        $crit = CPThreshold::parse($options['min-clients'] . ':', '--min-clients');
    }

    $mode = $options['mode'] ?? 'all';
    $items = [];
    $perfdata = [];
    $total = 0;
    $up = 0;

    foreach (cp_openvpn_instances($data) as $vpn) {
        if (($mode === 'server' && $vpn['type'] !== 'server') || ($mode === 'client' && $vpn['type'] !== 'client')) {
            continue;
        }
        $total++;
        if (!cp_filter_match([$vpn['id'], $vpn['vpnid'], $vpn['name']], $options['include'] ?? [], $options['exclude'] ?? [])) {
            continue;
        }

        $label = $vpn['id'] . ($vpn['name'] !== '' ? " ({$vpn['name']})" : '');
        $bytes = sprintf('in %s, out %s', cp_format_bytes($vpn['bytes_recv']), cp_format_bytes($vpn['bytes_sent']));

        if (!$vpn['reachable']) {
            $state = CP_CRITICAL;
            $text = "{$vpn['id']} daemon not responding";
            $detail = "{$label}: daemon not responding (service not running?)";
        } elseif ($vpn['mode'] === 'multipoint') {
            $count = count($vpn['clients']);
            $state = cp_threshold_state($count, $warn, $crit);
            $text = "{$vpn['id']} {$count} clients" . ($state !== CP_OK ? ' (threshold ' . ($state === CP_CRITICAL ? $crit->spec : $warn->spec) . ')' : '');
            $names = array_map(fn ($c) => $c['common_name'] . ($c['remote_host'] !== '' ? "@{$c['remote_host']}" : ''), $vpn['clients']);
            $detail = "{$label}: {$count} clients connected, {$bytes}" . ($names ? ' (' . implode(', ', $names) . ')' : '');
            $perfdata[] = cp_perf("{$vpn['id']}_clients", $count, '', $warn, $crit, 0);
        } elseif ($vpn['state'] === 'CONNECTED') {
            $state = CP_OK;
            $text = "{$vpn['id']} up";
            $detail = "{$label}: up, remote {$vpn['remote_host']}, virtual address {$vpn['virtual_addr']}, {$bytes}";
        } else {
            $state = CP_CRITICAL;
            $status = $vpn['status'] !== '' ? $vpn['status'] : $vpn['state'];
            $text = "{$vpn['id']} down ({$status})";
            $detail = "{$label}: down ({$status})";
        }

        if ($state === CP_OK) {
            $up++;
        }
        $items[] = ['state' => $state, 'text' => $text, 'detail' => $detail];
        $perfdata[] = cp_perf("{$vpn['id']}_bytes_in", $vpn['bytes_recv'], 'c', null, null, 0);
        $perfdata[] = cp_perf("{$vpn['id']}_bytes_out", $vpn['bytes_sent'], 'c', null, null, 0);
    }

    if ($total === 0) {
        return new CPResult(CP_UNKNOWN, 'No enabled OpenVPN instances found');
    }
    if (!$items) {
        return new CPResult(CP_UNKNOWN, 'No OpenVPN instances match the include/exclude filters');
    }

    $summary = sprintf('%d/%d OpenVPN instances OK', $up, count($items));
    return cp_aggregate($items, $summary, $perfdata);
}

/** Human readable size (for messages, not for perfdata). */
function cp_format_bytes(float $bytes): string
{
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    $unit = 0;
    while ($bytes >= 1024 && $unit < count($units) - 1) {
        $bytes /= 1024;
        $unit++;
    }
    return cp_format_number(round($bytes, 1), 1) . ' ' . $units[$unit];
}

/* ======================================================================
 * Subcommand: ipsec
 * ====================================================================== */

/**
 * Collect IPsec data like status_ipsec.php: phase 1 and phase 2 entries of
 * the configuration, the charon service status and the security
 * associations (ipsec_list_sa(), VICI list-sas).
 *
 * Only the fields used by the check are kept: no pre-shared keys, no SPIs.
 *
 * @return array{running: bool, phase1: array, phase2: array, sas: array}
 */
function cp_collect_ipsec(array $options): array
{
    foreach (['ipsec_list_sa', 'get_service_status'] as $function) {
        if (!function_exists($function)) {
            throw new CPException("Function {$function}() not found: unsupported pfSense version");
        }
    }

    $pick = fn (array $array, array $keys) => array_intersect_key($array, array_flip($keys));

    $phase1 = [];
    foreach (config_get_path('ipsec/phase1', []) as $p1) {
        if (is_array($p1)) {
            $phase1[] = $pick($p1, ['ikeid', 'descr', 'disabled', 'mobile', 'iketype', 'splitconn', 'remote-gateway']);
        }
    }
    $phase2 = [];
    foreach (config_get_path('ipsec/phase2', []) as $p2) {
        if (is_array($p2)) {
            $phase2[] = $pick($p2, ['ikeid', 'reqid', 'descr', 'disabled', 'mobile', 'mode']);
        }
    }

    $sas = [];
    $list = ipsec_list_sa();
    foreach (is_array($list) ? $list : [] as $ikesa) {
        if (!is_array($ikesa)) {
            continue;
        }
        $sa = $pick($ikesa, ['con-id', 'uniqueid', 'version', 'state', 'remote-host']);
        $sa['child-sas'] = [];
        foreach ((array) ($ikesa['child-sas'] ?? []) as $child) {
            if (is_array($child)) {
                $sa['child-sas'][] = $pick($child, ['name', 'uniqueid', 'state', 'bytes-in', 'bytes-out']);
            }
        }
        $sas[] = $sa;
    }

    return [
        'running' => (bool) get_service_status(['name' => 'ipsec']),
        'phase1' => $phase1,
        'phase2' => $phase2,
        'sas' => $sas,
    ];
}

/**
 * Split a connection id like ipsec_id_by_conid(): "con1" is [1, null],
 * "con1_5" is [1, 5].
 *
 * @return array{0: string, 1: ?string}
 */
function cp_ipsec_parse_conid(string $conid): array
{
    if (str_starts_with($conid, 'con')) {
        $conid = substr($conid, 3);
    }
    $parts = explode('_', $conid);
    return [$parts[0], $parts[1] ?? null];
}

function cp_evaluate_ipsec(array $data, array $options): CPResult
{
    // Enabled site to site phase 1 entries
    $tunnels = [];
    foreach ((array) ($data['phase1'] ?? []) as $p1) {
        if (!is_array($p1) || !isset($p1['ikeid']) || isset($p1['disabled']) || isset($p1['mobile'])) {
            continue;
        }
        $ikeid = (string) $p1['ikeid'];
        $tunnels[$ikeid] = ['p1' => $p1, 'p2' => []];
    }
    foreach ((array) ($data['phase2'] ?? []) as $p2) {
        if (!is_array($p2) || !isset($p2['ikeid'], $p2['reqid'], $tunnels[(string) $p2['ikeid']])) {
            continue;
        }
        if (isset($p2['disabled']) || isset($p2['mobile'])) {
            continue;
        }
        $tunnels[(string) $p2['ikeid']]['p2'][(string) $p2['reqid']] = $p2;
    }

    if (!$tunnels) {
        return new CPResult(CP_UNKNOWN, 'No enabled IPsec phase 1 entries (mobile clients are not checked)');
    }
    if (empty($data['running'])) {
        return new CPResult(CP_CRITICAL, 'IPsec daemon (charon) is not running');
    }

    // Match the security associations, like status_ipsec.php
    $ikeStates = [];
    $childUp = [];
    $bytes = [];
    $upStates = ['INSTALLED', 'REKEYING', 'REKEYED'];
    foreach ((array) ($data['sas'] ?? []) as $ikesa) {
        if (!is_array($ikesa) || !isset($ikesa['con-id'])) {
            continue;
        }
        [$ikeid] = cp_ipsec_parse_conid((string) $ikesa['con-id']);
        if (!isset($tunnels[$ikeid])) {
            continue;
        }
        $ikeStates[$ikeid][] = strtoupper((string) ($ikesa['state'] ?? ''));

        foreach ((array) ($ikesa['child-sas'] ?? []) as $child) {
            if (!is_array($child) || !isset($child['name'])) {
                continue;
            }
            [$childIkeid, $reqid] = cp_ipsec_parse_conid((string) $child['name']);
            $bytes[$ikeid]['in'] = ($bytes[$ikeid]['in'] ?? 0) + (float) ($child['bytes-in'] ?? 0);
            $bytes[$ikeid]['out'] = ($bytes[$ikeid]['out'] ?? 0) + (float) ($child['bytes-out'] ?? 0);
            if (isset($child['state']) && !in_array(strtoupper((string) $child['state']), $upStates, true)) {
                continue;
            }
            $p1 = $tunnels[$childIkeid]['p1'] ?? null;
            if ($reqid !== null) {
                $childUp[$childIkeid][$reqid] = true;
            } elseif ($p1 !== null && ($p1['iketype'] ?? '') === 'ikev2' && !isset($p1['splitconn'])) {
                // IKEv2 without split connections: one child SA for all P2
                foreach (array_keys($tunnels[$childIkeid]['p2']) as $p2reqid) {
                    $childUp[$childIkeid][(string) $p2reqid] = true;
                }
            }
        }
    }

    $items = [];
    $perfdata = [];
    $established = 0;
    foreach ($tunnels as $ikeid => $tunnel) {
        $p1 = $tunnel['p1'];
        $id = "con{$ikeid}";
        $descr = (string) ($p1['descr'] ?? '');
        if (!cp_filter_match([$id, $ikeid, $descr, (string) ($p1['remote-gateway'] ?? '')], $options['include'] ?? [], $options['exclude'] ?? [])) {
            continue;
        }
        $label = $id . ($descr !== '' ? " ({$descr})" : '');

        $states = $ikeStates[$ikeid] ?? [];
        $isEstablished = in_array('ESTABLISHED', $states, true);
        $expected = $tunnel['p2'];
        $connected = array_intersect_key($expected, $childUp[$ikeid] ?? []);
        $missing = array_diff_key($expected, $connected);
        $missingText = implode(', ', array_map(
            fn ($reqid, $p2) => (($p2['descr'] ?? '') !== '') ? $p2['descr'] : "reqid {$reqid}",
            array_keys($missing),
            $missing
        ));

        $p2Text = sprintf('%d/%d P2 connected', count($connected), count($expected));
        if (!$isEstablished) {
            $state = CP_CRITICAL;
            $text = "{$id} P1 " . ($states ? strtolower(implode('/', array_unique($states))) : 'not connected');
        } elseif ($missing) {
            $state = CP_CRITICAL;
            $text = "{$id} {$p2Text} (missing: {$missingText})";
        } else {
            $state = CP_OK;
            $text = "{$id} established";
            $established++;
        }
        $remote = (string) ($p1['remote-gateway'] ?? '');
        $detail = $label . ($remote !== '' ? ", remote {$remote}" : '') . ': P1 ' . ($isEstablished ? 'established' : ($states ? strtolower(implode('/', array_unique($states))) : 'not connected'))
            . ", {$p2Text}" . ($missing ? " (missing: {$missingText})" : '');

        $items[] = ['state' => $state, 'text' => $text, 'detail' => $detail];
        $perfdata[] = cp_perf("{$id}_p2", count($connected), '', null, null, 0, count($expected));
        $perfdata[] = cp_perf("{$id}_bytes_in", $bytes[$ikeid]['in'] ?? 0, 'c', null, null, 0);
        $perfdata[] = cp_perf("{$id}_bytes_out", $bytes[$ikeid]['out'] ?? 0, 'c', null, null, 0);
    }

    if (!$items) {
        return new CPResult(CP_UNKNOWN, 'No IPsec tunnels match the include/exclude filters');
    }

    $summary = sprintf('%d/%d IPsec tunnels established', $established, count($items));
    return cp_aggregate($items, $summary, $perfdata);
}

/* ======================================================================
 * Subcommand: carp
 * ====================================================================== */

/**
 * Collect CARP data, like status_carp.php.
 *
 * get_carp_interface_status() is not used: it runs "ifconfig | grep" with
 * exec() once per VIP, without timeout. ifconfig is run once per interface
 * and parsed with the same logic.
 *
 * @return array{vips: array, ifconfig: array, sysctl: string, maintenance: bool}
 */
function cp_collect_carp(array $options): array
{
    $vips = [];
    $ifconfig = [];
    foreach (config_get_path('virtualip/vip', []) as $vip) {
        if (!is_array($vip) || ($vip['mode'] ?? '') !== 'carp') {
            continue;
        }
        $friendly = (string) ($vip['interface'] ?? '');
        $real = (string) get_real_interface($friendly);
        $vips[] = [
            'vhid' => (string) ($vip['vhid'] ?? ''),
            'interface' => $friendly,
            'interface_descr' => function_exists('convert_friendly_interface_to_friendly_descr')
                ? (string) convert_friendly_interface_to_friendly_descr($friendly)
                : $friendly,
            'real_interface' => $real,
            'subnet' => (string) ($vip['subnet'] ?? ''),
            'subnet_bits' => (string) ($vip['subnet_bits'] ?? ''),
            'descr' => (string) ($vip['descr'] ?? ''),
            'advskew' => (string) ($vip['advskew'] ?? ''),
        ];
        if ($real !== '' && !isset($ifconfig[$real])) {
            $ifconfig[$real] = cp_run_ok(['/sbin/ifconfig', $real], $options['timeout']);
        }
    }

    $sysctl = cp_run_ok(['/sbin/sysctl', 'net.inet.carp.allow', 'net.inet.carp.demotion'], $options['timeout']);

    return [
        'vips' => $vips,
        'ifconfig' => $ifconfig,
        'sysctl' => $sysctl,
        'maintenance' => config_path_enabled('', 'virtualip_carp_maintenancemode'),
    ];
}

/**
 * Parse "name: value" lines of sysctl.
 *
 * @return array<string, string>
 */
function cp_parse_sysctl(string $output): array
{
    $values = [];
    foreach (preg_split('/\R/', $output) as $line) {
        if (preg_match('/^([a-z0-9_.%-]+)\s*[:=]\s*(.*)$/i', trim($line), $m)) {
            $values[$m[1]] = trim($m[2]);
        }
    }
    return $values;
}

/**
 * CARP state of a VHID in the ifconfig output of an interface, with the
 * logic of get_carp_interface_status(): the line "carp: <STATE> vhid <N> ".
 * Returns MASTER, BACKUP, INIT or an empty string if not found.
 */
function cp_carp_vhid_state(string $ifconfig, string $vhid): string
{
    foreach (preg_split('/\R/', $ifconfig) as $line) {
        if (!preg_match('/carp:.* vhid ' . preg_quote($vhid, '/') . ' /', $line . ' ')) {
            continue;
        }
        foreach (['MASTER', 'BACKUP', 'INIT'] as $state) {
            if (stripos($line, $state) !== false) {
                return $state;
            }
        }
    }
    return '';
}

function cp_evaluate_carp(array $data, array $options): CPResult
{
    $vips = array_values(array_filter((array) ($data['vips'] ?? []), 'is_array'));
    if (!$vips) {
        if (!empty($options['allow-none'])) {
            return new CPResult(CP_OK, 'No CARP VIPs configured');
        }
        return new CPResult(CP_UNKNOWN, 'No CARP VIPs configured (use --allow-none to return OK)');
    }

    $sysctl = cp_parse_sysctl((string) ($data['sysctl'] ?? ''));
    if (!isset($sysctl['net.inet.carp.allow']) || !is_numeric($sysctl['net.inet.carp.allow'])) {
        return new CPResult(CP_UNKNOWN, 'Cannot read sysctl net.inet.carp.allow');
    }
    $enabled = (int) $sysctl['net.inet.carp.allow'] > 0;
    $demotion = is_numeric($sysctl['net.inet.carp.demotion'] ?? null) ? (int) $sysctl['net.inet.carp.demotion'] : null;
    $expect = strtoupper($options['expect'] ?? 'any');

    // State of every VIP
    $counts = ['MASTER' => 0, 'BACKUP' => 0, 'INIT' => 0, 'UNKNOWN' => 0];
    foreach ($vips as &$vip) {
        $label = ($vip['interface_descr'] ?: $vip['interface']) . '@' . $vip['vhid'];
        $vip['label'] = $label;
        $raw = $data['ifconfig'][$vip['real_interface'] ?? ''] ?? null;
        $vip['state'] = ($raw === null) ? '' : cp_carp_vhid_state((string) $raw, (string) $vip['vhid']);
        $counts[$vip['state'] !== '' ? $vip['state'] : 'UNKNOWN']++;
    }
    unset($vip);

    // Mixed MASTER/BACKUP: the VIPs in the minority state are the problem
    // (BACKUP with the same number)
    $mixed = $enabled && $counts['MASTER'] > 0 && $counts['BACKUP'] > 0;
    $minority = ($counts['MASTER'] < $counts['BACKUP']) ? 'MASTER' : 'BACKUP';

    $items = [];
    if (!empty($data['maintenance'])) {
        $items[] = ['state' => CP_WARNING, 'text' => 'persistent CARP maintenance mode enabled'];
    }
    if (!$enabled) {
        $items[] = ['state' => CP_WARNING, 'text' => 'CARP is disabled (net.inet.carp.allow=0)'];
    }
    if ($demotion !== null && $demotion > 0) {
        $items[] = ['state' => CP_WARNING, 'text' => "CARP demotion is {$demotion} (check interface links and the system log)"];
    }
    if ($mixed) {
        $items[] = ['state' => CP_CRITICAL, 'text' => "VIPs in mixed states ({$counts['MASTER']} MASTER, {$counts['BACKUP']} BACKUP)"];
    }

    foreach ($vips as $vip) {
        $state = $vip['state'] !== '' ? $vip['state'] : 'not found';
        $detail = "{$vip['label']} {$vip['subnet']}/{$vip['subnet_bits']}"
            . ($vip['descr'] !== '' ? " ({$vip['descr']})" : '')
            . ': ' . ($enabled ? $state : 'DISABLED');
        $text = "{$vip['label']} {$state}";

        if (!$enabled) {
            $itemState = CP_OK;
        } elseif ($vip['state'] === '' || $vip['state'] === 'INIT') {
            $itemState = CP_CRITICAL;
        } elseif ($mixed && $vip['state'] === $minority) {
            $itemState = CP_CRITICAL;
        } elseif ($expect !== 'ANY' && $vip['state'] !== $expect) {
            $itemState = CP_WARNING;
            $text .= " (expected {$expect})";
        } else {
            $itemState = CP_OK;
        }
        $items[] = ['state' => $itemState, 'text' => $text, 'detail' => $detail];
    }

    if (!$enabled) {
        $summary = sprintf('%d CARP VIPs disabled', count($vips));
    } else {
        $parts = [];
        foreach ($counts as $state => $count) {
            if ($count > 0) {
                $parts[] = $count . ' ' . (($state === 'UNKNOWN') ? 'not found' : $state);
            }
        }
        $summary = sprintf('%d CARP VIPs: %s', count($vips), implode(', ', $parts));
    }

    $perfdata = [
        cp_perf('master', $counts['MASTER'], '', null, null, 0, count($vips)),
        cp_perf('backup', $counts['BACKUP'], '', null, null, 0, count($vips)),
        cp_perf('init', $counts['INIT'] + $counts['UNKNOWN'], '', null, null, 0, count($vips)),
    ];
    if ($demotion !== null) {
        $perfdata[] = cp_perf('demotion', $demotion, '', 0);
    }

    return cp_aggregate($items, $summary, $perfdata);
}

/* ======================================================================
 * Subcommand: states
 * ====================================================================== */

/**
 * Collect the pf state table usage: "pfctl -si" (current entries) and
 * "pfctl -sm" (hard limits). The dashboard (get_pfstate()) uses the limit
 * of the configuration; the kernel limit of pfctl -sm is the real one.
 *
 * @return array{info: string, limits: string}
 */
function cp_collect_states(array $options): array
{
    return [
        'info' => cp_run_ok(['/sbin/pfctl', '-si'], $options['timeout']),
        'limits' => cp_run_ok(['/sbin/pfctl', '-sm'], $options['timeout']),
    ];
}

/**
 * Parse "pfctl -si": pf status and current entries of the state table and
 * of the source tracking table.
 *
 * @return array{enabled: ?bool, states: ?int, src_nodes: ?int}
 */
function cp_parse_pfctl_info(string $output): array
{
    $result = ['enabled' => null, 'states' => null, 'src_nodes' => null];
    $section = null;
    foreach (preg_split('/\R/', $output) as $line) {
        if (preg_match('/^Status:\s*(Enabled|Disabled)/i', $line, $m)) {
            $result['enabled'] = strcasecmp($m[1], 'Enabled') === 0;
        } elseif (preg_match('/^State Table/i', $line)) {
            $section = 'states';
        } elseif (preg_match('/^Source Tracking Table/i', $line)) {
            $section = 'src_nodes';
        } elseif (preg_match('/^\S/', $line)) {
            $section = null;
        } elseif ($section !== null && $result[$section] === null && preg_match('/^\s+current entries\s+(\d+)/i', $line, $m)) {
            $result[$section] = (int) $m[1];
        }
    }
    return $result;
}

/**
 * Parse "pfctl -sm": "<name> hard limit <value>" lines.
 *
 * @return array<string, int>
 */
function cp_parse_pfctl_limits(string $output): array
{
    $limits = [];
    foreach (preg_split('/\R/', $output) as $line) {
        if (preg_match('/^\s*(\S+)\s+hard limit\s+(\d+)/i', $line, $m)) {
            $limits[$m[1]] = (int) $m[2];
        }
    }
    return $limits;
}

function cp_evaluate_states(array $data, array $options): CPResult
{
    $info = cp_parse_pfctl_info((string) ($data['info'] ?? ''));
    $limits = cp_parse_pfctl_limits((string) ($data['limits'] ?? ''));

    if ($info['states'] === null) {
        return new CPResult(CP_UNKNOWN, 'Cannot read the current state entries from pfctl -si');
    }
    if (empty($limits['states'])) {
        return new CPResult(CP_UNKNOWN, 'Cannot read the states hard limit from pfctl -sm');
    }

    $tables = [['states', 'states', $info['states'], $limits['states']]];
    if (!empty($options['src-nodes'])) {
        if ($info['src_nodes'] === null || empty($limits['src-nodes'])) {
            return new CPResult(CP_UNKNOWN, 'Cannot read the source tracking entries or limit from pfctl');
        }
        $tables[] = ['src_nodes', 'source nodes', $info['src_nodes'], $limits['src-nodes']];
    }

    $state = CP_OK;
    $texts = [];
    $perfdata = [];
    if ($info['enabled'] === false) {
        $state = CP_CRITICAL;
        $texts[] = 'pf is disabled';
    }

    foreach ($tables as [$label, $name, $current, $limit]) {
        $percent = round($current / $limit * 100, 2);
        $state = cp_worst_state($state, cp_threshold_state($percent, $options['warn'], $options['crit']));
        $texts[] = sprintf('%s %s%% used (%d/%d)', $name, cp_format_number($percent, 1), $current, $limit);

        $perfdata[] = cp_perf($label, $current, '', null, null, 0, $limit);
        $perfdata[] = cp_perf("{$label}_usage", $percent, '%', $options['warn'], $options['crit'], 0, 100);
    }

    return new CPResult($state, implode(', ', $texts), $perfdata);
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
        'gateway-groups' => [
            'summary' => 'Status of the gateway groups and of their members by tier',
            'description' => [
                'Members are usable according to the trigger level of the group, like',
                'the gateway group routing of pfSense; members with the gateway action',
                'disabled are always usable. CRITICAL if no member is usable; WARNING',
                'if a member is not usable or if the group is not using its highest',
                'priority tier (failover).',
                'Perfdata: active tier (0: none) and usable members of every group.',
            ],
            'libs' => ['config.inc', 'gwlb.inc'],
            'root' => true,
            'thresholds' => null,
            'options' => cp_filter_options('gateway groups (by name or description)'),
            'collect' => 'cp_collect_gateway_groups',
            'evaluate' => 'cp_evaluate_gateway_groups',
        ],
        'services' => [
            'summary' => 'Status of the pfSense services, discovered automatically',
            'description' => [
                'Same services and status of Status > Services: a running service is OK,',
                'a stopped service that is enabled in the configuration is CRITICAL.',
                'Services disabled in the configuration are not checked.',
                'Service names: the service name (example: unbound, sshd), or',
                'openvpn_<mode><vpnid> (example: openvpn_server1) and',
                'captiveportal_<zone> for the services with more instances.',
                'Filters match the service name or the description.',
            ],
            'libs' => ['config.inc', 'service-utils.inc'],
            'root' => true,
            'thresholds' => null,
            'options' => cp_filter_options('services (by name or description)'),
            'collect' => 'cp_collect_services',
            'evaluate' => 'cp_evaluate_services',
        ],
        'openvpn' => [
            'summary' => 'Status of the OpenVPN servers and clients',
            'description' => [
                'Same instances of Status > OpenVPN (disabled instances are skipped).',
                'Clients and peer to peer servers: CRITICAL if not connected.',
                'Multipoint servers: CRITICAL if the daemon does not answer on the',
                'management socket; -w/-c (or --min-clients) on the connected clients.',
                'Instances are identified as server<vpnid> or client<vpnid>; filters',
                'match the identifier, the vpnid or the description.',
                'Perfdata: connected clients, bytes in/out (counters).',
            ],
            'libs' => ['config.inc', 'openvpn.inc'],
            'root' => true,
            'thresholds' => [
                'warning' => null, 'critical' => null,
                'help_warning' => 'Range for the clients connected to every multipoint server (example: 2: warns below 2 clients)',
                'help_critical' => 'Range for the clients connected to every multipoint server (example: 1: is critical without clients)',
            ],
            'options' => cp_filter_options('instances (server1, client2, vpnid or description)') + [
                'mode' => [
                    'type' => 'choice', 'choices' => ['server', 'client', 'all'],
                    'default' => 'all', 'metavar' => 'MODE',
                    'help' => 'Check only servers or only clients, default: all',
                ],
                'min-clients' => [
                    'type' => 'int', 'metavar' => 'N',
                    'help' => 'Critical if a multipoint server has less than N clients (same as -c N:)',
                ],
            ],
            'validate' => function (array $options): void {
                if (isset($options['min-clients']) && $options['critical'] !== null) {
                    throw new CPUsageException('--min-clients and -c cannot be used together');
                }
                if (isset($options['min-clients']) && $options['warn'] !== null) {
                    cp_check_threshold_order($options['warn'], CPThreshold::parse($options['min-clients'] . ':', '--min-clients'));
                }
            },
            'collect' => 'cp_collect_openvpn',
            'evaluate' => 'cp_evaluate_openvpn',
        ],
        'ipsec' => [
            'summary' => 'Status of the IPsec tunnels (phase 1 and phase 2)',
            'description' => [
                'CRITICAL if an enabled phase 1 is not established, or if one of its',
                'enabled phase 2 entries has no installed child SA. CRITICAL if the',
                'charon daemon is not running. Disabled entries and mobile clients',
                'are not checked. Tunnels are identified as con<ikeid>; filters match',
                'the identifier, the ikeid, the description or the remote gateway.',
                'Perfdata: connected P2 and bytes in/out (counters) of every tunnel.',
            ],
            'libs' => ['config.inc', 'ipsec.inc', 'service-utils.inc'],
            'root' => true,
            'thresholds' => null,
            'options' => cp_filter_options('tunnels (con1, ikeid, description or remote gateway)'),
            'collect' => 'cp_collect_ipsec',
            'evaluate' => 'cp_evaluate_ipsec',
        ],
        'carp' => [
            'summary' => 'Status of the CARP virtual IPs',
            'description' => [
                'Same status of Status > CARP. CRITICAL if a VIP is INIT or not found,',
                'or if the VIPs are in mixed states (some MASTER, some BACKUP).',
                'WARNING if the state is not the expected one, if the CARP demotion',
                'is greater than 0, if the persistent maintenance mode is enabled or',
                'if CARP is disabled (net.inet.carp.allow=0).',
                'Perfdata: VIPs in MASTER, BACKUP and INIT state, demotion.',
            ],
            'libs' => ['config.inc', 'interfaces.inc', 'pfsense-utils.inc'],
            'root' => true,
            'thresholds' => null,
            'options' => [
                'expect' => [
                    'type' => 'choice', 'choices' => ['master', 'backup', 'any'],
                    'default' => 'any', 'metavar' => 'STATE',
                    'help' => 'Expected state of the VIPs of this node, default: any',
                ],
                'allow-none' => [
                    'type' => 'flag',
                    'help' => 'Return OK if no CARP VIPs are configured (default: UNKNOWN)',
                ],
            ],
            'collect' => 'cp_collect_carp',
            'evaluate' => 'cp_evaluate_carp',
        ],
        'states' => [
            'summary' => 'Usage of the pf state table',
            'description' => [
                'Percentage of the state table used, compared with the kernel hard',
                'limit (pfctl -sm). CRITICAL if pf is disabled.',
                'Perfdata: current states (max: the limit) and usage percentage.',
            ],
            'libs' => [],
            'root' => true,
            'thresholds' => [
                'warning' => '80', 'critical' => '90',
                'help_warning' => 'Warning threshold for the usage percentage',
                'help_critical' => 'Critical threshold for the usage percentage',
            ],
            'options' => [
                'src-nodes' => [
                    'type' => 'flag',
                    'help' => 'Check the source tracking table (src-nodes) too, with the same thresholds',
                ],
            ],
            'collect' => 'cp_collect_states',
            'evaluate' => 'cp_evaluate_states',
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
