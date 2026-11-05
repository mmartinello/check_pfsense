<?php
/*
 * Tests of the temperature subcommand (evaluation only, no pfSense needed).
 *
 * Fixtures: "sysctl -q dev.cpu hw.acpi.thermal" output (FreeBSD 14/15,
 * coretemp driver); replace with output captured by
 * tools/collect_fixtures.sh when available.
 */

function temperature(string $fixture): array
{
    return ['sysctl' => fixture("temperature/{$fixture}")];
}

return [
    'CPU cores: maximum' => function () {
        $result = cp_evaluate_temperature(temperature('sysctl_coretemp.txt'), options_for('temperature'));
        assert_same(CP_OK, $result->state);
        assert_same('Maximum temperature 51 C (cpu1), 4 sensors', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains("'max'=51;70;85 'cpu0'=46;70;85 'cpu1'=51;70;85 'cpu2'=45;70;85 'cpu3'=47;70;85", $output);
        assert_not_contains('tz0', $output, 'ACPI zones only without CPU sensors');
        assert_contains("\ncpu1: 51 C\n", $output);
    },

    'thresholds' => function () {
        assert_same(CP_WARNING, cp_evaluate_temperature(temperature('sysctl_coretemp.txt'), options_for('temperature', ['-w', '50', '-c', '60']))->state);
        assert_same(CP_CRITICAL, cp_evaluate_temperature(temperature('sysctl_coretemp.txt'), options_for('temperature', ['-w', '40', '-c', '50']))->state);
        assert_same(CP_OK, cp_evaluate_temperature(temperature('sysctl_coretemp.txt'), options_for('temperature', ['-w', '51', '-c', '60']))->state);
    },

    'ACPI thermal zones without CPU sensors' => function () {
        $result = cp_evaluate_temperature(temperature('sysctl_acpi_only.txt'), options_for('temperature'));
        assert_same(CP_OK, $result->state);
        assert_same('Maximum temperature 55.1 C (tz1), 2 sensors', $result->message);
        assert_contains("'max'=55.1;70;85 'tz0'=52.3;70;85 'tz1'=55.1;70;85", assert_valid_output($result));
    },

    'source option' => function () {
        $data = temperature('sysctl_coretemp.txt');
        assert_same('Maximum temperature 27.9 C (tz0), 1 sensors', cp_evaluate_temperature($data, options_for('temperature', ['--source', 'acpi']))->message);
        assert_same('Maximum temperature 51 C (cpu1), 5 sensors', cp_evaluate_temperature($data, options_for('temperature', ['--source', 'all']))->message);
        assert_same(CP_UNKNOWN, cp_evaluate_temperature(temperature('sysctl_acpi_only.txt'), options_for('temperature', ['--source', 'cpu']))->state);
    },

    'no sensor is unknown' => function () {
        $result = cp_evaluate_temperature(temperature('sysctl_none.txt'), options_for('temperature'));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('Temperature sensor not available', $result->message);
        assert_contains('coretemp or amdtemp', $result->message);
        assert_same(CP_UNKNOWN, cp_evaluate_temperature(['sysctl' => ''], options_for('temperature'))->state);
    },

    'negative and malformed values are ignored' => function () {
        $parsed = cp_parse_temperatures("dev.cpu.0.temperature: -1.0C\ndev.cpu.1.temperature: 40.0C\nhw.acpi.thermal.tz0.temperature: garbage\n");
        assert_same(['cpu' => [1 => 40.0], 'acpi' => []], $parsed);
    },
];
