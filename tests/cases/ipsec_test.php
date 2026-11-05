<?php
/*
 * Tests of the ipsec subcommand (evaluation only, no pfSense needed).
 */

return [
    'all tunnels established' => function () {
        $result = cp_evaluate_ipsec(fixture('ipsec/base.php'), options_for('ipsec'));
        assert_same(CP_OK, $result->state);
        assert_same('2/2 IPsec tunnels established', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'con1_p2'=2;;;0;2 'con1_bytes_in'=1048576c;;;0 'con1_bytes_out'=2097152c;;;0", $output);
        assert_contains("'con2_p2'=2;;;0;2 'con2_bytes_in'=1300c;;;0 'con2_bytes_out'=2400c;;;0", $output);
        assert_contains('[OK] con1 (Site A), remote 198.51.100.1: P1 established, 2/2 P2 connected', $output);
        assert_not_contains('con3', $output, 'disabled P1 not checked');
        assert_not_contains('con4', $output, 'mobile P1 not checked');
        assert_not_contains('mobile', $output);
    },

    'phase 1 not connected is critical' => function () {
        $data = fixture('ipsec/base.php');
        unset($data['sas'][1]);
        $result = cp_evaluate_ipsec($data, options_for('ipsec'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('con2 P1 not connected; 1/2 IPsec tunnels established', $result->message);
        assert_contains("'con2_p2'=0;;;0;2", assert_valid_output($result, true));
    },

    'phase 1 connecting is critical' => function () {
        $data = fixture('ipsec/base.php');
        $data['sas'][0]['state'] = 'CONNECTING';
        $data['sas'][0]['child-sas'] = [];
        $result = cp_evaluate_ipsec($data, options_for('ipsec'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('con1 P1 connecting; 1/2 IPsec tunnels established', $result->message);
    },

    'missing phase 2 is critical (IKEv1, one child per P2)' => function () {
        $data = fixture('ipsec/base.php');
        unset($data['sas'][1]['child-sas'][1]);
        $result = cp_evaluate_ipsec($data, options_for('ipsec'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('con2 1/2 P2 connected (missing: VoIP B); 1/2 IPsec tunnels established', $result->message);
    },

    'child SA not installed does not count' => function () {
        $data = fixture('ipsec/base.php');
        $data['sas'][1]['child-sas'][0]['state'] = 'DELETING';
        $result = cp_evaluate_ipsec($data, options_for('ipsec'));
        assert_same(CP_CRITICAL, $result->state);
        assert_contains('con2 1/2 P2 connected (missing: LAN B)', $result->message);

        $data['sas'][1]['child-sas'][0]['state'] = 'REKEYING';
        assert_same(CP_OK, cp_evaluate_ipsec($data, options_for('ipsec'))->state, 'rekeying is up');
    },

    'IKEv2 without split: no child SA means all P2 missing' => function () {
        $data = fixture('ipsec/base.php');
        $data['sas'][0]['child-sas'] = [];
        $result = cp_evaluate_ipsec($data, options_for('ipsec'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('con1 0/2 P2 connected (missing: LAN A, DMZ A); 1/2 IPsec tunnels established', $result->message);
    },

    'IKEv2 with split connections needs one child per P2' => function () {
        $data = fixture('ipsec/base.php');
        $data['phase1'][0]['splitconn'] = '';
        $result = cp_evaluate_ipsec($data, options_for('ipsec'));
        assert_same(CP_CRITICAL, $result->state, 'child "con1" does not cover split P2');

        $data['sas'][0]['child-sas'] = [
            ['name' => 'con1_1', 'state' => 'INSTALLED'],
            ['name' => 'con1_2', 'state' => 'INSTALLED'],
        ];
        assert_same(CP_OK, cp_evaluate_ipsec($data, options_for('ipsec'))->state);
    },

    'P2 without description shows the reqid' => function () {
        $data = fixture('ipsec/base.php');
        unset($data['phase2'][3]['descr']);
        unset($data['sas'][1]['child-sas'][1]);
        assert_contains('(missing: reqid 4)', cp_evaluate_ipsec($data, options_for('ipsec'))->message);
    },

    'daemon not running is critical' => function () {
        $data = fixture('ipsec/base.php');
        $data['running'] = false;
        $data['sas'] = [];
        $result = cp_evaluate_ipsec($data, options_for('ipsec'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('IPsec daemon (charon) is not running', $result->message);
    },

    'no enabled tunnels' => function () {
        $data = fixture('ipsec/base.php');
        $data['phase1'] = array_slice($data['phase1'], 2);
        $result = cp_evaluate_ipsec($data, options_for('ipsec'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('No enabled IPsec phase 1', $result->message);
    },

    'include and exclude' => function () {
        $data = fixture('ipsec/base.php');
        unset($data['sas'][1]);
        assert_same(CP_OK, cp_evaluate_ipsec($data, options_for('ipsec', ['--exclude', 'con2']))->state);
        assert_same(CP_OK, cp_evaluate_ipsec($data, options_for('ipsec', ['--include', 'site a']))->state);
        assert_same(CP_OK, cp_evaluate_ipsec($data, options_for('ipsec', ['--include', '198.51.100.*']))->state);
        assert_same(CP_CRITICAL, cp_evaluate_ipsec($data, options_for('ipsec', ['--include', '2']))->state);
        assert_same(CP_UNKNOWN, cp_evaluate_ipsec($data, options_for('ipsec', ['--include', 'nothing']))->state);
    },

    'malformed data' => function () {
        $data = fixture('ipsec/base.php');
        $data['sas'][] = 'garbage';
        $data['sas'][] = ['state' => 'ESTABLISHED'];
        $data['sas'][0]['child-sas'][] = 'garbage';
        $data['phase1'][] = ['descr' => 'no ikeid'];
        $data['phase2'][] = ['ikeid' => '99', 'reqid' => '99'];
        unset($data['phase1'][1]['remote-gateway']);
        $result = cp_evaluate_ipsec($data, options_for('ipsec', ['-v']));
        assert_same(CP_OK, $result->state);
        assert_contains('[OK] con2 (Site B): P1 established', implode("\n", $result->long));
    },

    'connection id parsing' => function () {
        assert_same(['1', null], cp_ipsec_parse_conid('con1'));
        assert_same(['12', '345'], cp_ipsec_parse_conid('con12_345'));
        assert_same(['-mobile', null], cp_ipsec_parse_conid('con-mobile'));
    },
];
