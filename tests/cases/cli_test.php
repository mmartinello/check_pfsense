<?php
/*
 * End to end tests: the plugin is run as a separate process.
 */

$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

return [
    'list is always OK with one line per subcommand' => function () {
        $r = run_plugin(['list']);
        assert_same(0, $r['code']);
        assert_same('', $r['stderr']);
        assert_valid_plugin_output($r['stdout'], $r['code']);
        assert_matches('/^OK - check_pfsense v1\.0\.0: \d+ subcommands available\n/', $r['stdout']);
        assert_contains("\ngateways - Status of the gateways", $r['stdout']);
    },

    'version' => function () {
        $r = run_plugin(['-V']);
        assert_same(0, $r['code']);
        assert_same("check_pfsense v1.0.0\n", $r['stdout']);
    },

    'general help' => function () {
        $r = run_plugin(['--help']);
        assert_same(0, $r['code']);
        assert_contains('Usage: check_pfsense.php [options] <subcommand>', $r['stdout']);
        assert_contains('gateways', $r['stdout']);
        assert_same('', $r['stderr']);
    },

    'subcommand help' => function () {
        $r = run_plugin(['gateways', '-h']);
        assert_same(0, $r['code']);
        assert_contains('Usage: check_pfsense.php gateways [options]', $r['stdout']);
        assert_contains('--include LIST', $r['stdout']);
        assert_contains('--pending STATE', $r['stdout']);
        assert_not_contains('--warning', $r['stdout'], 'gateways has no thresholds');
    },

    'usage errors are UNKNOWN on stdout' => function () {
        foreach ([[], ['nope'], ['gateways', '--nope'], ['-w', '80', '-c', '90', 'gateways'], ['list', '-t', 'x']] as $args) {
            $r = run_plugin($args);
            assert_same(3, $r['code'], 'args: ' . implode(' ', $args));
            assert_same('', $r['stderr']);
            assert_valid_plugin_output($r['stdout'], $r['code']);
            assert_matches('/^UNKNOWN - Usage error: /', $r['stdout']);
            assert_same(1, substr_count($r['stdout'], "\n"), 'one line only');
        }
    },

    'not root is UNKNOWN with a clear message' => function () use ($isRoot) {
        if ($isRoot) {
            return;
        }
        $r = run_plugin(['-w', '1', 'gateways', '-v']);
        assert_same(3, $r['code']);
        assert_contains('Usage error', $r['stdout']);

        $r = run_plugin(['gateways']);
        assert_same(3, $r['code']);
        assert_same("UNKNOWN - This check must run as root: enable the Sudo option of the NRPE command (sudo package required)\n", $r['stdout']);
        assert_same('', $r['stderr']);
    },
];
