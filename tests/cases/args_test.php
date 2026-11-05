<?php
/*
 * Tests of the command line parsing.
 */

/** Test subcommands, independent from the real ones. */
function args_commands(): array
{
    return [
        'states' => [
            'summary' => 'States', 'libs' => [], 'root' => true,
            'thresholds' => ['warning' => '80', 'critical' => '90', 'help_warning' => 'w', 'help_critical' => 'c'],
            'options' => ['src-nodes' => ['type' => 'flag', 'help' => 'x']],
            'collect' => null, 'evaluate' => 'strlen',
        ],
        'gateways' => [
            'summary' => 'Gateways', 'libs' => [], 'root' => true, 'thresholds' => null,
            'options' => cp_filter_options('gateways') + [
                'pending' => ['type' => 'choice', 'choices' => ['ok', 'unknown'], 'default' => 'unknown'],
            ],
            'collect' => null, 'evaluate' => 'strlen',
        ],
    ];
}

function parse(array $args): array
{
    return cp_parse_args($args, args_commands());
}

return [
    'options after the subcommand' => function () {
        $p = parse(['states', '-w', '70', '-c', '95']);
        assert_same('states', $p['command']);
        assert_same('70', $p['options']['warning']);
        assert_same('95', $p['options']['critical']);
        assert_same(CP_DEFAULT_TIMEOUT, $p['options']['timeout']);
        assert_same(false, $p['options']['verbose']);
    },

    'options before the subcommand, as built by the NRPE GUI' => function () {
        $p = parse(['-w', '70', '-c', '95', 'states']);
        assert_same('states', $p['command']);
        assert_same('70', $p['options']['warning']);
        assert_same('95', $p['options']['critical']);
    },

    'options before and after, subcommand option before the subcommand' => function () {
        $p = parse(['-w', '70', '--src-nodes', 'states', '-c', '95', '-v', '-t', '20']);
        assert_same(true, $p['options']['src-nodes']);
        assert_same(true, $p['options']['verbose']);
        assert_same(20, $p['options']['timeout']);
    },

    'long options with = and attached short values' => function () {
        $p = parse(['states', '--warning=70', '-c95', '--timeout=5']);
        assert_same('70', $p['options']['warning']);
        assert_same('95', $p['options']['critical']);
        assert_same(5, $p['options']['timeout']);
    },

    'threshold values starting with special characters' => function () {
        $p = parse(['states', '-w', '~:10', '-c', '@5:8']);
        assert_same('~:10', $p['options']['warning']);
        assert_same('@5:8', $p['options']['critical']);
        $p = parse(['-w', '-5:', 'states']);
        assert_same('-5:', $p['options']['warning'], 'a value can start with a dash');
    },

    'clustered short flags' => function () {
        $p = parse(['states', '-vt', '7']);
        assert_same(true, $p['options']['verbose']);
        assert_same(7, $p['options']['timeout']);
    },

    'list options are comma separated and repeatable' => function () {
        $p = parse(['gateways', '--include', 'WAN*, LTE', '--include=OPT1', '--exclude', 'a,,b']);
        assert_same(['WAN*', 'LTE', 'OPT1'], $p['options']['include']);
        assert_same(['a', 'b'], $p['options']['exclude']);
    },

    'choice options' => function () {
        assert_same('ok', parse(['gateways', '--pending', 'OK'])['options']['pending']);
        assert_same('unknown', parse(['gateways'])['options']['pending'], 'default value');
        assert_throws(CPUsageException::class, fn () => parse(['gateways', '--pending', 'maybe']), 'must be one of');
    },

    'unknown subcommand' => function () {
        assert_throws(CPUsageException::class, fn () => parse(['nope']), "Unknown subcommand 'nope'");
    },

    'unknown option' => function () {
        assert_throws(CPUsageException::class, fn () => parse(['states', '--nope']), "Unknown option '--nope' for subcommand 'states'");
        assert_throws(CPUsageException::class, fn () => parse(['states', '-x']), "Unknown option '-x'");
    },

    'option of another subcommand is rejected' => function () {
        assert_throws(CPUsageException::class, fn () => parse(['states', '--include', 'x']), "Unknown option '--include'");
        assert_throws(CPUsageException::class, fn () => parse(['--src-nodes', 'gateways']), "Unknown option '--src-nodes'");
    },

    'missing values and invalid types' => function () {
        assert_throws(CPUsageException::class, fn () => parse(['states', '-w']), 'requires a value');
        assert_throws(CPUsageException::class, fn () => parse(['states', '--timeout']), 'requires a value');
        assert_throws(CPUsageException::class, fn () => parse(['states', '-t', 'ten']), 'requires an integer');
        assert_throws(CPUsageException::class, fn () => parse(['states', '--verbose=yes']), 'does not take a value');
    },

    'extra positional arguments' => function () {
        assert_throws(CPUsageException::class, fn () => parse(['states', 'extra']), "Unexpected argument 'extra'");
    },

    'help with and without subcommand' => function () {
        $p = parse(['-h']);
        assert_same(true, $p['help']);
        assert_same(null, $p['command']);

        $p = parse(['states', '--help']);
        assert_same(true, $p['help']);
        assert_same('states', $p['command']);

        $p = parse(['-w', '80', 'states', '-h', '--whatever']);
        assert_same(true, $p['help'], 'help wins over invalid options');
    },

    'no subcommand' => function () {
        $p = parse(['-v']);
        assert_same(null, $p['command']);
    },

    'threshold preparation' => function () {
        $commands = args_commands();
        $o = cp_prepare_options('states', $commands['states'], parse(['states'])['options']);
        assert_same('80', $o['warn']->spec, 'default warning');
        assert_same('90', $o['crit']->spec, 'default critical');

        $o = cp_prepare_options('states', $commands['states'], parse(['-w', '50', 'states'])['options']);
        assert_same('50', $o['warn']->spec);

        assert_throws(CPUsageException::class, fn () => cp_prepare_options('states', $commands['states'], parse(['states', '-w', '95'])['options']), 'greater than critical');
        assert_throws(CPUsageException::class, fn () => cp_prepare_options('states', $commands['states'], parse(['states', '-w', 'x'])['options']), 'Invalid threshold');
        assert_throws(CPUsageException::class, fn () => cp_prepare_options('gateways', $commands['gateways'], parse(['gateways', '-c', '1'])['options']), 'does not use -c');
    },

    'timeout limits' => function () {
        $commands = args_commands();
        assert_throws(CPUsageException::class, fn () => cp_prepare_options('states', $commands['states'], parse(['states', '-t', '0'])['options']), 'Timeout must be');
        assert_throws(CPUsageException::class, fn () => cp_prepare_options('states', $commands['states'], parse(['states', '-t', '601'])['options']), 'Timeout must be');
    },
];
