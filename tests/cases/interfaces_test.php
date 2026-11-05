<?php
/*
 * Tests of the interfaces subcommand (evaluation only, no pfSense needed).
 */

/** Base fixture with the ifconfig output of an interface (by name) changed. */
function interfaces_with(string $name, callable $change): array
{
    $data = fixture('interfaces/base.php');
    foreach ($data['interfaces'] as &$if) {
        if ($if['name'] === $name) {
            $if = $change($if);
        }
    }
    return $data;
}

return [
    'all up, disabled interfaces not checked' => function () {
        $result = cp_evaluate_interfaces(fixture('interfaces/base.php'), options_for('interfaces'));
        assert_same(CP_OK, $result->state);
        assert_same('4/4 interfaces up', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'LAN_in_errors'=12c;;;0 'LAN_out_errors'=3c;;;0 'LAN_collisions'=0c;;;0", $output);
        assert_contains('[OK] WAN (igb0): up, 1000baseT <full-duplex>, errors in 0, out 0, collisions 0', $output);
        assert_contains('[OK] VPN_M2M (ovpns4): up, errors', $output, 'no status line: up from the flags');
        assert_not_contains('SPARE', $output);
    },

    'no carrier is critical' => function () {
        $data = interfaces_with('opt1', function ($if) {
            $if['ifconfig'] = str_replace('status: active', 'status: no carrier', $if['ifconfig']);
            return $if;
        });
        $result = cp_evaluate_interfaces($data, options_for('interfaces'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('DMZ (igb2) no carrier; 3/4 interfaces up', $result->message);
        assert_valid_output($result, true);
    },

    'administratively down is critical' => function () {
        $data = interfaces_with('wan', function ($if) {
            $if['ifconfig'] = str_replace('flags=1008843<UP,BROADCAST,RUNNING', 'flags=8802<BROADCAST', $if['ifconfig']);
            return $if;
        });
        $result = cp_evaluate_interfaces($data, options_for('interfaces'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('WAN (igb0) down; 3/4 interfaces up', $result->message);
    },

    'missing interface is critical' => function () {
        $data = interfaces_with('lan', function ($if) {
            $if['ifconfig'] = null;
            $if['stats'] = null;
            return $if;
        });
        $result = cp_evaluate_interfaces($data, options_for('interfaces'));
        assert_same(CP_CRITICAL, $result->state);
        assert_same('LAN (igb1) not found; 3/4 interfaces up', $result->message);
    },

    'running and associated count as up' => function () {
        assert_same('up', cp_interface_status("wlan0: flags=8843<UP,BROADCAST,RUNNING> mtu 1500\n\tstatus: running\n"));
        assert_same('up', cp_interface_status("wlan0: flags=8843<UP,BROADCAST,RUNNING> mtu 1500\n\tstatus: associated\n"), 'associated is up, like the widget');
        assert_same('down', cp_interface_status('garbage'));
        assert_same('down', cp_interface_status("igb0: flags=8802<BROADCAST,SIMPLEX,MULTICAST> mtu 1500\n\tstatus: active\n"));
    },

    'media parsing' => function () {
        assert_same('1000baseT <full-duplex>', cp_interface_media("\tmedia: Ethernet autoselect (1000baseT <full-duplex>)\n"));
        assert_same('autoselect', cp_interface_media("\tmedia: Ethernet autoselect\n"));
        assert_same('', cp_interface_media(''));
    },

    'include and exclude by name, description and physical interface' => function () {
        $data = interfaces_with('opt1', function ($if) {
            $if['ifconfig'] = str_replace('status: active', 'status: no carrier', $if['ifconfig']);
            return $if;
        });
        assert_same(CP_OK, cp_evaluate_interfaces($data, options_for('interfaces', ['--exclude', 'opt1']))->state);
        assert_same(CP_OK, cp_evaluate_interfaces($data, options_for('interfaces', ['--exclude', 'dmz']))->state);
        assert_same(CP_OK, cp_evaluate_interfaces($data, options_for('interfaces', ['--exclude', 'igb2']))->state);
        assert_same('2/2 interfaces up', cp_evaluate_interfaces($data, options_for('interfaces', ['--include', 'wan,lan']))->message);
        assert_same(CP_UNKNOWN, cp_evaluate_interfaces($data, options_for('interfaces', ['--include', 'nothing']))->state);
    },

    'missing stats: no perfdata' => function () {
        $data = interfaces_with('wan', function ($if) {
            $if['stats'] = null;
            return $if;
        });
        $result = cp_evaluate_interfaces($data, options_for('interfaces'));
        assert_same(CP_OK, $result->state);
        assert_not_contains('WAN_', implode(' ', $result->perfdata));
    },

    'no enabled interfaces' => function () {
        $result = cp_evaluate_interfaces(['interfaces' => [['name' => 'wan', 'enabled' => false, 'hwif' => 'igb0']]], options_for('interfaces'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_same('No enabled interfaces found', $result->message);
        assert_same(CP_UNKNOWN, cp_evaluate_interfaces([], options_for('interfaces'))->state);
    },
];
