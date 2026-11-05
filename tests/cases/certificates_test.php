<?php
/*
 * Tests of the certificates subcommand (evaluation only, no pfSense needed).
 */

/** Base fixture with the expiration of a certificate (by description) moved. */
function certificates_expiring(string $descr, int $seconds): array
{
    $data = fixture('certificates/base.php');
    foreach ($data['certificates'] as &$cert) {
        if ($cert['descr'] === $descr) {
            $cert['valid_to'] = $data['now'] + $seconds;
        }
    }
    return $data;
}

return [
    'expired unused certificate is critical' => function () {
        $result = cp_evaluate_certificates(fixture('certificates/base.php'), options_for('certificates'));
        assert_same(CP_CRITICAL, $result->state);
        // The next expiry ignores the expired certificate
        $date = gmdate('Y-m-d', fixture('certificates/base.php')['now'] - 35 * 86400);
        assert_same(
            "old test expired 35 days ago ({$date}, not used); 3/4 certificates OK, next expiry in 45 days (www.example.com)",
            $result->message
        );
        $output = assert_valid_output($result, true);
        assert_contains("'webConfigurator_days'=200;30:;14:", $output);
        assert_contains("'old_test_days'=-35;30:;14:", $output);
        assert_contains('[OK] www.example.com CN=www.example.com: valid until ' . gmdate('Y-m-d', fixture('certificates/base.php')['now'] + 45 * 86400 + 3600) . ' (45 days), used by HAProxy', $output);
        assert_not_contains('pending csr', $output, 'signing requests are skipped');
        assert_not_contains('Internal CA', $output, 'CAs only with --include-ca');
    },

    'only used certificates' => function () {
        $result = cp_evaluate_certificates(fixture('certificates/base.php'), options_for('certificates', ['--only-used']));
        assert_same(CP_OK, $result->state);
        assert_same('3/3 certificates OK, next expiry in 45 days (www.example.com)', $result->message);
    },

    'default thresholds 30/14 days' => function () {
        $o = options_for('certificates', ['--only-used']);
        $result = cp_evaluate_certificates(certificates_expiring('www.example.com', 20 * 86400), $o);
        assert_same(CP_WARNING, $result->state);
        $date = gmdate('Y-m-d', fixture('certificates/base.php')['now'] + 20 * 86400);
        assert_same("www.example.com expires in 20 days ({$date}, used by HAProxy); 2/3 certificates OK, next expiry in 20 days (www.example.com)", $result->message);

        $result = cp_evaluate_certificates(certificates_expiring('webConfigurator', 10 * 86400), $o);
        assert_same(CP_CRITICAL, $result->state);
        assert_contains('webConfigurator expires in 10 days (' . gmdate('Y-m-d', fixture('certificates/base.php')['now'] + 10 * 86400) . ', used by WebGUI)', $result->message);

        assert_same(CP_OK, cp_evaluate_certificates(certificates_expiring('webConfigurator', 30 * 86400 + 60), $o)->state, '30 days is not below 30');
    },

    'plain numbers mean "less than N days", ranges are accepted' => function () {
        $o = options_for('certificates', ['--only-used', '-w', '60', '-c', '7']);
        assert_same('60:', $o['warn']->spec);
        assert_same(CP_WARNING, cp_evaluate_certificates(fixture('certificates/base.php'), $o)->state, '45 days < 60');

        $o = options_for('certificates', ['--only-used', '-w', '50:', '-c', '@0:46']);
        assert_same(CP_CRITICAL, cp_evaluate_certificates(fixture('certificates/base.php'), $o)->state);

        assert_throws(CPUsageException::class, fn () => options_for('certificates', ['-w', '14', '-c', '30']), 'lower than critical');
    },

    'certificate not valid yet is a warning' => function () {
        $data = fixture('certificates/base.php');
        $data['certificates'][0]['valid_from'] = $data['now'] + 2 * 86400;
        $result = cp_evaluate_certificates($data, options_for('certificates', ['--only-used']));
        assert_same(CP_WARNING, $result->state);
        assert_contains('webConfigurator not valid before ' . gmdate('Y-m-d', $data['now'] + 2 * 86400) . ' (used by WebGUI)', $result->message);
    },

    'certificate authorities' => function () {
        $result = cp_evaluate_certificates(fixture('certificates/base.php'), options_for('certificates', ['--include-ca', '--only-used', '-v']));
        assert_same('4/4 certificates OK, next expiry in 45 days (www.example.com)', $result->message);
        $output = assert_valid_output($result, true);
        assert_contains('[OK] CA Internal CA CN=Internal CA: valid until', $output);
        assert_contains("'ca_Internal_CA_days'=3000", $output);
    },

    'unparsable certificate is unknown' => function () {
        $data = fixture('certificates/base.php');
        $data['certificates'][1]['valid_to'] = null;
        $result = cp_evaluate_certificates($data, options_for('certificates', ['--only-used']));
        assert_same(CP_UNKNOWN, $result->state);
        assert_contains('OpenVPN server cannot be parsed', $result->message);
    },

    'duplicated descriptions get unique perfdata labels' => function () {
        $data = fixture('certificates/base.php');
        $data['certificates'][1]['descr'] = 'webConfigurator';
        $output = assert_valid_output(cp_evaluate_certificates($data, options_for('certificates', ['--only-used'])));
        assert_contains("'webConfigurator_days'=200", $output);
        assert_contains("'webConfigurator_6a7b8c9d0e1f2_days'=90", $output);
    },

    'filters' => function () {
        $data = fixture('certificates/base.php');
        assert_same(CP_OK, cp_evaluate_certificates($data, options_for('certificates', ['--exclude', 'old*']))->state);
        assert_same('1/1 certificates OK, next expiry in 90 days (OpenVPN server)', cp_evaluate_certificates($data, options_for('certificates', ['--include', 'vpn.example.com']))->message, 'by subject CN');
        assert_same(CP_UNKNOWN, cp_evaluate_certificates($data, options_for('certificates', ['--include', 'nothing']))->state);
        assert_same(CP_UNKNOWN, cp_evaluate_certificates(['certificates' => []], options_for('certificates'))->state);
    },

    'certificate parsing' => function () {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => 'test.example.com'], $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 10, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $pem);

        $info = cp_cert_info(base64_encode($pem));
        assert_same('test.example.com', $info['subject']);
        assert_true(abs($info['valid_to'] - (time() + 10 * 86400)) < 120, 'valid for 10 days');
        assert_true($info['valid_from'] <= time());

        assert_same(null, cp_cert_info(base64_encode('not a certificate')));
        assert_same(null, cp_cert_info('***'));
    },
];
