<?php
/*
 * Tests of the carp subcommand (evaluation only, no pfSense needed).
 */

/** Base fixture with the state of some VHIDs replaced (vhid => state). */
function carp_with(array $states = [], array $changes = []): array
{
    $data = fixture('carp/base.php');
    foreach ($states as $vhid => $state) {
        foreach ($data['ifconfig'] as &$output) {
            $output = preg_replace("/carp: \\S+ vhid {$vhid} /", "carp: {$state} vhid {$vhid} ", $output);
        }
        unset($output);
    }
    return array_merge($data, $changes);
}

return [
    'all master' => function () {
        $result = cp_evaluate_carp(fixture('carp/base.php'), options_for('carp'));
        assert_same(CP_OK, $result->state);
        assert_same('3 CARP VIPs: 3 MASTER', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'master'=3;;;0;3 'backup'=0;;;0;3 'init'=0;;;0;3 'demotion'=0;0", $output);
        assert_contains('[OK] WAN@10 203.0.113.10/24 (Web server): MASTER', $output);
    },

    'vhid matching does not confuse 1 and 10' => function () {
        $ifconfig = fixture('carp/ifconfig_vtnet0.txt');
        assert_same('MASTER', cp_carp_vhid_state($ifconfig, '1'));
        assert_same('MASTER', cp_carp_vhid_state($ifconfig, '10'));
        assert_same('', cp_carp_vhid_state($ifconfig, '2'));
        $ifconfig = str_replace('carp: MASTER vhid 10 ', 'carp: BACKUP vhid 10 ', $ifconfig);
        assert_same('MASTER', cp_carp_vhid_state($ifconfig, '1'));
        assert_same('BACKUP', cp_carp_vhid_state($ifconfig, '10'));
        assert_same('INIT', cp_carp_vhid_state("\tcarp: INIT vhid 5 advbase 1 advskew 100", '5'));
        assert_same('', cp_carp_vhid_state('', '1'));
    },

    'all backup on the secondary node' => function () {
        $data = carp_with([1 => 'BACKUP', 10 => 'BACKUP', 2 => 'BACKUP']);
        assert_same(CP_OK, cp_evaluate_carp($data, options_for('carp'))->state);
        assert_same(CP_OK, cp_evaluate_carp($data, options_for('carp', ['--expect', 'backup']))->state);

        $result = cp_evaluate_carp($data, options_for('carp', ['--expect', 'master']));
        assert_same(CP_WARNING, $result->state);
        assert_same('WAN@1 BACKUP (expected MASTER), WAN@10 BACKUP (expected MASTER), LAN@2 BACKUP (expected MASTER); 3 CARP VIPs: 3 BACKUP', $result->message);
    },

    'mixed states are critical, minority listed' => function () {
        $result = cp_evaluate_carp(carp_with([2 => 'BACKUP']), options_for('carp'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('VIPs in mixed states (2 MASTER, 1 BACKUP), LAN@2 BACKUP; 3 CARP VIPs: 2 MASTER, 1 BACKUP', $result->message);
        assert_valid_output($result, true);

        $result = cp_evaluate_carp(carp_with([1 => 'BACKUP', 10 => 'BACKUP']), options_for('carp', ['--expect', 'backup']));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('VIPs in mixed states (1 MASTER, 2 BACKUP), LAN@2 MASTER; 3 CARP VIPs: 1 MASTER, 2 BACKUP', $result->message);
    },

    'init and missing vhid are critical' => function () {
        $result = cp_evaluate_carp(carp_with([10 => 'INIT']), options_for('carp'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('WAN@10 INIT; 3 CARP VIPs: 2 MASTER, 1 INIT', $result->message);
        assert_contains("'init'=1;;;0;3", assert_valid_output($result));

        $data = fixture('carp/base.php');
        unset($data['ifconfig']['vtnet1']);
        $result = cp_evaluate_carp($data, options_for('carp'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('LAN@2 not found; 3 CARP VIPs: 2 MASTER, 1 not found', $result->message);
    },

    'demotion is warning' => function () {
        $data = carp_with([], ['sysctl' => "net.inet.carp.allow: 1\nnet.inet.carp.demotion: 240\n"]);
        $result = cp_evaluate_carp($data, options_for('carp'));
        assert_same(CP_WARNING, $result->state);
        assert_same('CARP demotion is 240 (check interface links and the system log); 3 CARP VIPs: 3 MASTER', $result->message);
        assert_contains("'demotion'=240;0", assert_valid_output($result));
    },

    'maintenance mode is warning' => function () {
        $data = carp_with([1 => 'BACKUP', 10 => 'BACKUP', 2 => 'BACKUP'], ['maintenance' => true]);
        $result = cp_evaluate_carp($data, options_for('carp'));
        assert_same(CP_WARNING, $result->state);
        assert_same('persistent CARP maintenance mode enabled; 3 CARP VIPs: 3 BACKUP', $result->message);
    },

    'carp disabled is warning and vips are not evaluated' => function () {
        $data = carp_with([1 => 'INIT', 10 => 'INIT', 2 => 'INIT'], ['sysctl' => "net.inet.carp.allow: 0\nnet.inet.carp.demotion: 0\n"]);
        $result = cp_evaluate_carp($data, options_for('carp', ['--expect', 'master']));
        assert_same(CP_WARNING, $result->state);
        assert_same('CARP is disabled (net.inet.carp.allow=0); 3 CARP VIPs disabled', $result->message);
        assert_contains('[OK] LAN@2 10.0.0.1/24 (LAN gateway): DISABLED', assert_valid_output($result, true));
    },

    'critical wins over warnings' => function () {
        $data = carp_with([2 => 'INIT'], ['maintenance' => true]);
        $result = cp_evaluate_carp($data, options_for('carp'));
        assert_same(CP_CRITICAL, $result->state);
        assert_matches('/^LAN@2 INIT, persistent CARP maintenance mode enabled; /', $result->message);
    },

    'no carp vips' => function () {
        $data = ['vips' => [], 'ifconfig' => [], 'sysctl' => "net.inet.carp.allow: 1\n", 'maintenance' => false];
        $result = cp_evaluate_carp($data, options_for('carp'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('--allow-none', $result->message);

        $result = cp_evaluate_carp($data, options_for('carp', ['--allow-none']));
        assert_same(CP_OK, $result->state);
        assert_same('No CARP VIPs configured', $result->message);
    },

    'unreadable sysctl is unknown' => function () {
        $result = cp_evaluate_carp(carp_with([], ['sysctl' => 'garbage']), options_for('carp'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('net.inet.carp.allow', $result->message);

        // Missing demotion: no perfdata, no warning
        $result = cp_evaluate_carp(carp_with([], ['sysctl' => "net.inet.carp.allow: 1\n"]), options_for('carp'));
        assert_same(CP_OK, $result->state);
        assert_not_contains('demotion', implode(' ', $result->perfdata));
    },

    'sysctl parsing' => function () {
        assert_same(
            ['net.inet.carp.allow' => '1', 'net.inet.carp.demotion' => '-5', 'dev.cpu.0.temperature' => '45.0C'],
            cp_parse_sysctl("net.inet.carp.allow: 1\nnet.inet.carp.demotion: -5\r\ndev.cpu.0.temperature: 45.0C\n\nnoise")
        );
    },

    'invalid expect value' => function () {
        assert_throws(CPUsageException::class, fn () => options_for('carp', ['--expect', 'primary']), 'must be one of');
    },
];
