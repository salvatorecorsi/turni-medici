<?php

if (!defined('ABSPATH')) exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$check = function ($label, $condition) use (&$failures) {
    if ($condition) {
        echo "PASS  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label\n";
    }
};

$suffix = strtolower(substr(str_replace('-', '', wp_generate_uuid4()), 0, 12));
$aft_slug = 'tm-test-aft-' . $suffix;
$facility_slug = 'tm-test-facility-' . $suffix;
$user_login = 'tm_test_' . $suffix;
$user_email = $user_login . '@example.test';
$created_user_id = 0;
$created_facility_id = 0;
$created_aft_id = 0;
$register_calls = 0;
$profile_update_calls = 0;
$mail_calls = 0;
$register_probe = function () use (&$register_calls) {
    $register_calls++;
};
$profile_update_probe = function () use (&$profile_update_calls) {
    $profile_update_calls++;
};
$mail_probe = function ($return) use (&$mail_calls) {
    $mail_calls++;
    return $return;
};
$previous_user_id = get_current_user_id();

add_action('user_register', $register_probe, 10, 2);
add_action('profile_update', $profile_update_probe, 10, 3);
add_filter('pre_wp_mail', $mail_probe, 10, 2);
wp_set_current_user(1);

try {
    ob_start();
    tm_render_settings_page();
    $settings_html = ob_get_clean();
    $check('trasferimento dati: sezione presente in Impostazioni > Turni Medici', str_contains($settings_html, 'id="tm-data-transfer"')
        && str_contains($settings_html, 'Importa / esporta dati')
        && !function_exists('tm_data_transfer_menu'));

    $payload = [
        'schema'      => TM_DATA_TRANSFER_SCHEMA,
        'version'     => TM_DATA_TRANSFER_VERSION,
        'exported_at' => gmdate('c'),
        'site_url'    => 'https://transfer-test.invalid/',
        'datasets'    => [
            'afts' => [[
                'source_id'  => 700001,
                'slug'       => $aft_slug,
                'title'      => 'AFT Prova Ñ ' . $suffix,
                'status'     => 'draft',
                'content'    => '',
                'excerpt'    => '',
                'menu_order' => 0,
                'meta'       => ['tm_aft_area' => 'Area test'],
            ]],
            'facilities' => [[
                'source_id'  => 700002,
                'slug'       => $facility_slug,
                'title'      => 'Struttura Prova ' . $suffix,
                'status'     => 'draft',
                'content'    => '',
                'excerpt'    => '',
                'menu_order' => 0,
                'meta'       => ['tm_facility_short' => 'Prova'],
                'aft'        => ['source_id' => 700001, 'slug' => $aft_slug, 'title' => 'AFT Prova'],
            ]],
            'doctors' => [[
                'source_id'       => 700003,
                'user_login'      => $user_login,
                'user_email'      => $user_email,
                'user_nicename'   => $user_login,
                'display_name'    => 'Zoë Ñ Test',
                'first_name'      => 'Zoë',
                'last_name'       => 'Ñ Test',
                'description'     => '',
                'user_url'        => '',
                'user_registered' => current_time('mysql'),
                'roles'           => ['subscriber'],
                'meta'            => [
                    'tm_doctor_enabled' => 1,
                    'tm_qualification'  => 'mmg',
                    'tm_phone'          => '+39 0583 000001',
                    'tm_magic_token'    => hash('sha256', $suffix),
                ],
                'relations' => [
                    'tm_home_aft'             => ['source_id' => 700001, 'slug' => $aft_slug, 'title' => 'AFT Prova'],
                    'tm_coordinated_aft'      => null,
                    'tm_coordinated_facility' => null,
                    'tm_preferred_facility'   => ['source_id' => 700002, 'slug' => $facility_slug, 'title' => 'Struttura Prova'],
                ],
            ]],
        ],
    ];

    $first_report = tm_data_transfer_import_payload($payload);
    $created_aft = get_page_by_path($aft_slug, OBJECT, 'tm_aft');
    $created_facility = get_page_by_path($facility_slug, OBJECT, 'tm_facility');
    $created_user = get_user_by('email', $user_email);
    $created_aft_id = $created_aft ? (int) $created_aft->ID : 0;
    $created_facility_id = $created_facility ? (int) $created_facility->ID : 0;
    $created_user_id = $created_user ? (int) $created_user->ID : 0;

    $check('import anagrafico: primo passaggio senza errori', is_array($first_report) && $first_report['errors'] === []);
    $check('import anagrafico: crea AFT, struttura e medico', is_array($first_report)
        && ($first_report['created']['afts'] ?? 0) === 1
        && ($first_report['created']['facilities'] ?? 0) === 1
        && ($first_report['created']['users'] ?? 0) === 1
        && $created_aft_id > 0
        && $created_facility_id > 0
        && $created_user_id > 0);
    $check('import anagrafico: risolve le relazioni tra record', (int) get_post_meta($created_facility_id, 'tm_aft_id', true) === $created_aft_id
        && (int) get_user_meta($created_user_id, 'tm_home_aft', true) === $created_aft_id
        && (int) get_user_meta($created_user_id, 'tm_preferred_facility', true) === $created_facility_id);
    $check('import anagrafico: non esegue user_register', $register_calls === 0);
    $check('import anagrafico: ripristina gli hook user_register', has_action('user_register', $register_probe) !== false);
    $check('import anagrafico: ripristina gli hook profile_update', has_action('profile_update', $profile_update_probe) !== false);

    $payload['datasets']['afts'][0]['title'] = 'AFT Prova aggiornata ' . $suffix;
    $payload['datasets']['facilities'][0]['title'] = 'Struttura Prova aggiornata ' . $suffix;
    $payload['datasets']['doctors'][0]['meta']['tm_phone'] = '+39 0583 000002';
    $second_report = tm_data_transfer_import_payload($payload);
    $updated_aft = get_page_by_path($aft_slug, OBJECT, 'tm_aft');
    $updated_facility = get_page_by_path($facility_slug, OBJECT, 'tm_facility');
    $updated_user = get_user_by('email', $user_email);

    $check('import anagrafico: secondo passaggio idempotente', is_array($second_report)
        && $second_report['errors'] === []
        && array_sum($second_report['created']) === 0
        && ($second_report['updated']['afts'] ?? 0) === 1
        && ($second_report['updated']['facilities'] ?? 0) === 1
        && ($second_report['updated']['users'] ?? 0) === 1);
    $check('import anagrafico: aggiorna senza duplicare', $updated_aft && (int) $updated_aft->ID === $created_aft_id
        && $updated_facility && (int) $updated_facility->ID === $created_facility_id
        && $updated_user && (int) $updated_user->ID === $created_user_id
        && get_user_meta($created_user_id, 'tm_phone', true) === '+39 0583 000002');

    $exported_user = tm_data_transfer_user_record($created_user_id);
    $check('export anagrafico: round-trip conserva unicode e riferimenti', ($exported_user['display_name'] ?? '') === 'Zoë Ñ Test'
        && (int) ($exported_user['relations']['tm_home_aft']['source_id'] ?? 0) === $created_aft_id
        && (int) ($exported_user['relations']['tm_preferred_facility']['source_id'] ?? 0) === $created_facility_id);
    $check('export anagrafico: non espone credenziali', !array_key_exists('user_pass', $exported_user)
        && !array_key_exists('user_activation_key', $exported_user));

    $missing_relation_payload = $payload;
    $missing_relation_payload['datasets'] = ['doctors' => $payload['datasets']['doctors']];
    $missing_relation_payload['datasets']['doctors'][0]['relations']['tm_preferred_facility'] = [
        'source_id' => 799999,
        'slug'      => 'struttura-inesistente-' . $suffix,
        'title'     => 'Struttura inesistente',
    ];
    $missing_relation_report = tm_data_transfer_import_payload($missing_relation_payload);
    $check('import anagrafico: relazione mancante segnalata senza cancellare il dato esistente', is_array($missing_relation_report)
        && count($missing_relation_report['errors']) === 1
        && (int) get_user_meta($created_user_id, 'tm_preferred_facility', true) === $created_facility_id);

    $changed_email = $user_login . '-updated@example.test';
    $email_change_record = $payload['datasets']['doctors'][0];
    $email_change_record['source_id'] = $created_user_id;
    $email_change_record['user_email'] = $changed_email;
    $email_change_report = tm_data_transfer_import_payload([
        'schema'      => TM_DATA_TRANSFER_SCHEMA,
        'version'     => TM_DATA_TRANSFER_VERSION,
        'exported_at' => gmdate('c'),
        'site_url'    => home_url('/'),
        'datasets'    => ['doctors' => [$email_change_record]],
    ]);
    $changed_user = get_userdata($created_user_id);
    $check('import anagrafico: aggiornamento email senza workflow utente', is_array($email_change_report)
        && $email_change_report['errors'] === []
        && $changed_user
        && $changed_user->user_email === $changed_email
        && $profile_update_calls === 0);
    $check('import anagrafico: nessuna email generata durante create e update', $mail_calls === 0);
} finally {
    remove_action('user_register', $register_probe, 10);
    remove_action('profile_update', $profile_update_probe, 10);
    remove_filter('pre_wp_mail', $mail_probe, 10);
    if ($created_user_id) wp_delete_user($created_user_id);
    if ($created_facility_id) wp_delete_post($created_facility_id, true);
    if ($created_aft_id) wp_delete_post($created_aft_id, true);
    if (function_exists('tm_refresh_facility_rewrite_map')) tm_refresh_facility_rewrite_map();
    wp_set_current_user($previous_user_id);
}

echo $failures === 0 ? "\nTutti i test passano.\n" : "\n$failures test falliti.\n";
if ($failures > 0) exit(1);
