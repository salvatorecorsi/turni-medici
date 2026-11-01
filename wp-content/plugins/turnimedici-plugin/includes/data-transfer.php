<?php

if (!defined('ABSPATH')) exit;

const TM_DATA_TRANSFER_SCHEMA = 'turnimedici.data-transfer';
const TM_DATA_TRANSFER_VERSION = 1;

function tm_data_transfer_user_meta_keys() {
    return [
        'tm_ambulatory_schedule',
        'tm_ca_cot',
        'tm_ca_periods',
        'tm_ca_rank',
        'tm_ca_zone',
        'tm_codice_regionale',
        'tm_debug_doctor',
        'tm_doctor_color',
        'tm_doctor_enabled',
        'tm_estar_id',
        'tm_gender',
        'tm_invite_sent',
        'tm_magic_token',
        'tm_magic_token_created',
        'tm_monthly_hours',
        'tm_weekly_hours',
        'tm_patients',
        'tm_pending_profile',
        'tm_phone',
        'tm_profile_confirmed_at',
        'tm_qualification',
        'tm_role_start',
    ];
}

function tm_data_transfer_user_relation_types() {
    return [
        'tm_home_aft'             => 'tm_aft',
        'tm_coordinated_aft'      => 'tm_aft',
        'tm_coordinated_facility' => 'tm_facility',
        'tm_preferred_facility'   => 'tm_facility',
    ];
}

function tm_data_transfer_post_reference($post_id, $post_type) {
    $post = $post_id ? get_post((int) $post_id) : null;
    if (!$post || $post->post_type !== $post_type) return null;
    return [
        'source_id' => (int) $post->ID,
        'slug'      => (string) $post->post_name,
        'title'     => (string) $post->post_title,
    ];
}

function tm_data_transfer_meta_values($object_type, $object_id, $keys) {
    $values = [];
    foreach ($keys as $key) {
        $values[$key] = metadata_exists($object_type, $object_id, $key)
            ? get_metadata($object_type, $object_id, $key, true)
            : null;
    }
    return $values;
}

function tm_data_transfer_user_record($user_id, $include_magic_tokens = false) {
    $user = get_userdata((int) $user_id);
    if (!$user) return null;
    $relations = [];
    foreach (tm_data_transfer_user_relation_types() as $meta_key => $post_type) {
        $relations[$meta_key] = tm_data_transfer_post_reference((int) get_user_meta($user->ID, $meta_key, true), $post_type);
    }
    $meta_keys = tm_data_transfer_user_meta_keys();
    if (!$include_magic_tokens) {
        $meta_keys = array_values(array_diff($meta_keys, ['tm_magic_token', 'tm_magic_token_created']));
    }
    return [
        'source_id'       => (int) $user->ID,
        'user_login'      => (string) $user->user_login,
        'user_email'      => (string) $user->user_email,
        'user_nicename'   => (string) $user->user_nicename,
        'display_name'    => (string) $user->display_name,
        'first_name'      => (string) $user->first_name,
        'last_name'       => (string) $user->last_name,
        'description'     => (string) $user->description,
        'user_url'        => (string) $user->user_url,
        'user_registered' => (string) $user->user_registered,
        'roles'           => array_values((array) $user->roles),
        'meta'            => tm_data_transfer_meta_values('user', $user->ID, $meta_keys),
        'relations'       => $relations,
    ];
}

function tm_data_transfer_users($doctor_only = false, $include_magic_tokens = false) {
    $ids = $doctor_only
        ? tm_turnazione_doctor_ids()
        : array_map('intval', get_users(['fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC']));
    return array_values(array_filter(array_map(
        fn($id) => tm_data_transfer_user_record($id, $include_magic_tokens),
        $ids
    )));
}

function tm_data_transfer_post_record($post, $meta_keys) {
    return [
        'source_id'  => (int) $post->ID,
        'slug'       => (string) $post->post_name,
        'title'      => (string) $post->post_title,
        'status'     => (string) $post->post_status,
        'content'    => (string) $post->post_content,
        'excerpt'    => (string) $post->post_excerpt,
        'menu_order' => (int) $post->menu_order,
        'meta'       => tm_data_transfer_meta_values('post', $post->ID, $meta_keys),
    ];
}

function tm_data_transfer_afts() {
    $posts = get_posts([
        'post_type'      => 'tm_aft',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);
    $meta_keys = ['tm_estar_aft_name', 'tm_aft_area', 'tm_aft_manager', 'tm_aft_phone', 'tm_aft_email'];
    return array_map(fn($post) => tm_data_transfer_post_record($post, $meta_keys), $posts);
}

function tm_data_transfer_facilities() {
    $posts = get_posts([
        'post_type'      => 'tm_facility',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);
    return array_map(function ($post) {
        $record = tm_data_transfer_post_record($post, ['tm_facility_short', 'tm_facility_modes', 'tm_facility_pin']);
        $record['aft'] = tm_data_transfer_post_reference((int) get_post_meta($post->ID, 'tm_aft_id', true), 'tm_aft');
        return $record;
    }, $posts);
}

function tm_data_transfer_export_payload($datasets, $include_magic_tokens = false) {
    $datasets = array_values(array_intersect(['users', 'doctors', 'afts', 'facilities'], array_map('sanitize_key', $datasets)));
    if (in_array('users', $datasets, true)) $datasets = array_values(array_diff($datasets, ['doctors']));
    $payload = [
        'schema'      => TM_DATA_TRANSFER_SCHEMA,
        'version'     => TM_DATA_TRANSFER_VERSION,
        'exported_at' => gmdate('c'),
        'site_url'    => home_url('/'),
        'datasets'    => [],
    ];
    foreach ($datasets as $dataset) {
        if ($dataset === 'users') $payload['datasets']['users'] = tm_data_transfer_users(false, $include_magic_tokens);
        elseif ($dataset === 'doctors') $payload['datasets']['doctors'] = tm_data_transfer_users(true, $include_magic_tokens);
        elseif ($dataset === 'afts') $payload['datasets']['afts'] = tm_data_transfer_afts();
        elseif ($dataset === 'facilities') $payload['datasets']['facilities'] = tm_data_transfer_facilities();
    }
    return $payload;
}

add_action('admin_post_tm_export_data', 'tm_handle_data_transfer_export');
function tm_handle_data_transfer_export() {
    if (!current_user_can('manage_options')) wp_die('Non autorizzato.', 403);
    check_admin_referer('tm_export_data');
    $datasets = array_map('sanitize_key', (array) ($_POST['datasets'] ?? []));
    if (!$datasets) wp_die('Seleziona almeno un gruppo di dati.', 400);
    $payload = tm_data_transfer_export_payload($datasets, !empty($_POST['include_magic_tokens']));
    $suffix = implode('-', array_keys($payload['datasets']));
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="turnimedici-' . sanitize_file_name($suffix) . '-' . gmdate('Ymd-His') . '.json"');
    echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tm_data_transfer_sanitize_recursive($value) {
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $clean_key = is_int($key) ? $key : sanitize_key((string) $key);
            $clean[$clean_key] = tm_data_transfer_sanitize_recursive($item);
        }
        return $clean;
    }
    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) return $value;
    return sanitize_text_field((string) $value);
}

function tm_data_transfer_sanitize_user_meta($key, $value) {
    if ($value === null) return null;
    if ($key === 'tm_weekly_hours') return tm_sanitize_weekly_hours($value);
    if (in_array($key, ['tm_patients', 'tm_monthly_hours', 'tm_ca_rank', 'tm_magic_token_created'], true)) return absint($value);
    if (in_array($key, ['tm_doctor_enabled', 'tm_debug_doctor'], true)) return empty($value) ? 0 : 1;
    if ($key === 'tm_qualification') {
        $qualification = sanitize_key((string) $value);
        return in_array($qualification, tm_allowed_doctor_qualifications(), true) ? $qualification : '';
    }
    if ($key === 'tm_gender') return function_exists('tm_sanitize_gender') ? tm_sanitize_gender($value) : sanitize_key((string) $value);
    if ($key === 'tm_doctor_color') return sanitize_hex_color((string) $value) ?: '';
    if (in_array($key, ['tm_ambulatory_schedule', 'tm_pending_profile'], true)) return tm_data_transfer_sanitize_recursive($value);
    return sanitize_text_field((string) $value);
}

function tm_data_transfer_sanitize_post_meta($post_type, $key, $value) {
    if ($value === null) return null;
    if ($post_type === 'tm_aft') {
        if ($key === 'tm_aft_email') return sanitize_email((string) $value);
        return sanitize_text_field((string) $value);
    }
    if ($key === 'tm_facility_short') return sanitize_text_field((string) $value);
    if ($key === 'tm_facility_modes') {
        return array_values(array_intersect(array_keys(TM_FACILITY_MODES), array_map('sanitize_key', (array) $value)));
    }
    if ($key === 'tm_facility_pin') {
        $pin = is_array($value) ? $value : [];
        return [
            'lat'               => isset($pin['lat']) ? (float) $pin['lat'] : 0,
            'lng'               => isset($pin['lng']) ? (float) $pin['lng'] : 0,
            'formatted_address' => sanitize_text_field((string) ($pin['formatted_address'] ?? '')),
            'query'             => sanitize_text_field((string) ($pin['query'] ?? '')),
        ];
    }
    return tm_data_transfer_sanitize_recursive($value);
}

function tm_data_transfer_apply_meta($object_type, $object_id, $values, $allowed_keys, $post_type = '') {
    foreach ($allowed_keys as $key) {
        if (!array_key_exists($key, $values)) continue;
        $value = $object_type === 'user'
            ? tm_data_transfer_sanitize_user_meta($key, $values[$key])
            : tm_data_transfer_sanitize_post_meta($post_type, $key, $values[$key]);
        if ($value === null) delete_metadata($object_type, $object_id, $key);
        else update_metadata($object_type, $object_id, $key, $value);
    }
}

function tm_data_transfer_find_post($record, $post_type, $same_site) {
    $source_id = absint($record['source_id'] ?? 0);
    if ($same_site && $source_id && get_post_type($source_id) === $post_type) return $source_id;
    $slug = sanitize_title((string) ($record['slug'] ?? ''));
    $post = $slug ? get_page_by_path($slug, OBJECT, $post_type) : null;
    return $post ? (int) $post->ID : 0;
}

function tm_data_transfer_upsert_post($record, $post_type, $same_site) {
    if (!is_array($record)) return new WP_Error('tm_bad_record', 'Record post non valido.');
    $post_id = tm_data_transfer_find_post($record, $post_type, $same_site);
    $created = !$post_id;
    $status = sanitize_key((string) ($record['status'] ?? 'draft'));
    if (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) $status = 'draft';
    $postarr = [
        'post_type'    => $post_type,
        'post_title'   => sanitize_text_field((string) ($record['title'] ?? '')),
        'post_name'    => sanitize_title((string) ($record['slug'] ?? '')),
        'post_status'  => $status,
        'post_content' => wp_kses_post((string) ($record['content'] ?? '')),
        'post_excerpt' => wp_kses_post((string) ($record['excerpt'] ?? '')),
        'menu_order'   => (int) ($record['menu_order'] ?? 0),
    ];
    if (!$postarr['post_title']) return new WP_Error('tm_missing_title', 'Titolo mancante.');
    if ($post_id) $postarr['ID'] = $post_id;
    $result = $post_id ? wp_update_post(wp_slash($postarr), true) : wp_insert_post(wp_slash($postarr), true);
    if (is_wp_error($result)) return $result;
    return ['id' => (int) $result, 'created' => $created];
}

function tm_data_transfer_resolve_reference($reference, $post_type, $map, $same_site) {
    if (!is_array($reference)) return 0;
    $source_id = absint($reference['source_id'] ?? 0);
    if ($source_id && isset($map[$source_id])) return (int) $map[$source_id];
    if ($same_site && $source_id && get_post_type($source_id) === $post_type) return $source_id;
    $slug = sanitize_title((string) ($reference['slug'] ?? ''));
    $post = $slug ? get_page_by_path($slug, OBJECT, $post_type) : null;
    return $post ? (int) $post->ID : 0;
}

function tm_data_transfer_report() {
    return [
        'created' => ['afts' => 0, 'facilities' => 0, 'users' => 0],
        'updated' => ['afts' => 0, 'facilities' => 0, 'users' => 0],
        'errors'  => [],
    ];
}

function tm_data_transfer_report_post(&$report, $section, $result, $record) {
    if (is_wp_error($result)) {
        $report['errors'][] = $section . ' ' . ((string) ($record['title'] ?? $record['slug'] ?? '')) . ': ' . $result->get_error_message();
        return 0;
    }
    $report[$result['created'] ? 'created' : 'updated'][$section]++;
    return (int) $result['id'];
}

function tm_data_transfer_import_afts($records, $same_site, &$report) {
    $map = [];
    $meta_keys = ['tm_estar_aft_name', 'tm_aft_area', 'tm_aft_manager', 'tm_aft_phone', 'tm_aft_email'];
    foreach ((array) $records as $record) {
        $result = tm_data_transfer_upsert_post($record, 'tm_aft', $same_site);
        $post_id = tm_data_transfer_report_post($report, 'afts', $result, $record);
        if (!$post_id) continue;
        tm_data_transfer_apply_meta('post', $post_id, (array) ($record['meta'] ?? []), $meta_keys, 'tm_aft');
        $source_id = absint($record['source_id'] ?? 0);
        if ($source_id) $map[$source_id] = $post_id;
    }
    return $map;
}

function tm_data_transfer_import_facilities($records, $aft_map, $same_site, &$report) {
    $map = [];
    $meta_keys = ['tm_facility_short', 'tm_facility_modes', 'tm_facility_pin'];
    foreach ((array) $records as $record) {
        $aft_id = tm_data_transfer_resolve_reference($record['aft'] ?? null, 'tm_aft', $aft_map, $same_site);
        if (!$aft_id) {
            $report['errors'][] = 'facilities ' . ((string) ($record['title'] ?? $record['slug'] ?? '')) . ': AFT non trovata.';
            continue;
        }
        $result = tm_data_transfer_upsert_post($record, 'tm_facility', $same_site);
        $post_id = tm_data_transfer_report_post($report, 'facilities', $result, $record);
        if (!$post_id) continue;
        tm_data_transfer_apply_meta('post', $post_id, (array) ($record['meta'] ?? []), $meta_keys, 'tm_facility');
        update_post_meta($post_id, 'tm_aft_id', $aft_id);
        $source_id = absint($record['source_id'] ?? 0);
        if ($source_id) $map[$source_id] = $post_id;
    }
    return $map;
}

function tm_data_transfer_find_user($record, $same_site) {
    $source_id = absint($record['source_id'] ?? 0);
    if ($same_site && $source_id && get_userdata($source_id)) return $source_id;
    $email = sanitize_email((string) ($record['user_email'] ?? ''));
    $user = $email ? get_user_by('email', $email) : null;
    if ($user) return (int) $user->ID;
    $login = sanitize_user((string) ($record['user_login'] ?? ''), true);
    $user = $login ? get_user_by('login', $login) : null;
    return $user ? (int) $user->ID : 0;
}

function tm_data_transfer_write_user_without_notifications($userdata, $update) {
    global $wp_filter;
    $action_names = ['user_register', 'profile_update'];
    $saved_actions = [];
    foreach ($action_names as $action_name) {
        $saved_actions[$action_name] = isset($wp_filter[$action_name]) ? clone $wp_filter[$action_name] : null;
        remove_all_actions($action_name);
    }
    $block_change_email = fn($send) => false;
    add_filter('send_email_change_email', $block_change_email, PHP_INT_MAX);
    add_filter('send_password_change_email', $block_change_email, PHP_INT_MAX);
    try {
        return $update ? wp_update_user($userdata) : wp_insert_user($userdata);
    } finally {
        remove_filter('send_email_change_email', $block_change_email, PHP_INT_MAX);
        remove_filter('send_password_change_email', $block_change_email, PHP_INT_MAX);
        foreach ($saved_actions as $action_name => $saved_action) {
            if ($saved_action) $wp_filter[$action_name] = $saved_action;
            else unset($wp_filter[$action_name]);
        }
    }
}

function tm_data_transfer_valid_roles($roles) {
    $role_registry = wp_roles();
    return array_values(array_filter(array_map('sanitize_key', (array) $roles), fn($role) => $role_registry->is_role($role)));
}

function tm_data_transfer_upsert_user($record, $same_site) {
    if (!is_array($record)) return new WP_Error('tm_bad_user', 'Record utente non valido.');
    $user_id = tm_data_transfer_find_user($record, $same_site);
    $created = !$user_id;
    $email = sanitize_email((string) ($record['user_email'] ?? ''));
    $login = sanitize_user((string) ($record['user_login'] ?? ''), true);
    if (!$email || !is_email($email)) return new WP_Error('tm_bad_user_email', 'Email utente non valida.');
    if (!$login) return new WP_Error('tm_bad_user_login', 'Login utente non valido.');
    $userdata = [
        'user_email'    => $email,
        'user_nicename' => sanitize_title((string) ($record['user_nicename'] ?? $login)),
        'display_name'  => sanitize_text_field((string) ($record['display_name'] ?? $login)),
        'first_name'    => sanitize_text_field((string) ($record['first_name'] ?? '')),
        'last_name'     => sanitize_text_field((string) ($record['last_name'] ?? '')),
        'description'   => sanitize_textarea_field((string) ($record['description'] ?? '')),
        'user_url'      => esc_url_raw((string) ($record['user_url'] ?? '')),
    ];
    if ($user_id) {
        $userdata['ID'] = $user_id;
        $result = tm_data_transfer_write_user_without_notifications($userdata, true);
    } else {
        $roles = tm_data_transfer_valid_roles($record['roles'] ?? []);
        $userdata['user_login'] = $login;
        $userdata['user_pass'] = wp_generate_password(32, true, true);
        $userdata['role'] = $roles[0] ?? 'subscriber';
        $registered = (string) ($record['user_registered'] ?? '');
        if ($registered) $userdata['user_registered'] = $registered;
        $result = tm_data_transfer_write_user_without_notifications($userdata, false);
    }
    if (is_wp_error($result)) return $result;
    return ['id' => (int) $result, 'created' => $created];
}

function tm_data_transfer_import_users($records, $aft_map, $facility_map, $same_site, &$report) {
    $relation_types = tm_data_transfer_user_relation_types();
    $block_mail = fn($return) => true;
    add_filter('pre_wp_mail', $block_mail, PHP_INT_MAX);
    try {
        foreach ((array) $records as $record) {
            $result = tm_data_transfer_upsert_user($record, $same_site);
            if (is_wp_error($result)) {
                $report['errors'][] = 'users ' . ((string) ($record['user_email'] ?? $record['user_login'] ?? '')) . ': ' . $result->get_error_message();
                continue;
            }
            $user_id = (int) $result['id'];
            $report[$result['created'] ? 'created' : 'updated']['users']++;
            $user = get_userdata($user_id);
            foreach (tm_data_transfer_valid_roles($record['roles'] ?? []) as $role) {
                if ($user && !in_array($role, (array) $user->roles, true)) $user->add_role($role);
            }
            tm_data_transfer_apply_meta('user', $user_id, (array) ($record['meta'] ?? []), tm_data_transfer_user_meta_keys());
            foreach ($relation_types as $meta_key => $post_type) {
                if (!array_key_exists($meta_key, (array) ($record['relations'] ?? []))) continue;
                $reference = $record['relations'][$meta_key];
                if ($reference === null) {
                    delete_user_meta($user_id, $meta_key);
                    continue;
                }
                $map = $post_type === 'tm_aft' ? $aft_map : $facility_map;
                $resolved = tm_data_transfer_resolve_reference($reference, $post_type, $map, $same_site);
                if ($resolved) update_user_meta($user_id, $meta_key, $resolved);
                else $report['errors'][] = 'users ' . ((string) ($record['user_email'] ?? $record['user_login'] ?? '')) . ': relazione ' . $meta_key . ' non trovata.';
            }
        }
    } finally {
        remove_filter('pre_wp_mail', $block_mail, PHP_INT_MAX);
    }
}

function tm_data_transfer_import_payload($payload) {
    if (!is_array($payload) || ($payload['schema'] ?? '') !== TM_DATA_TRANSFER_SCHEMA || (int) ($payload['version'] ?? 0) !== TM_DATA_TRANSFER_VERSION) {
        return new WP_Error('tm_bad_transfer_schema', 'Schema JSON non riconosciuto.');
    }
    $datasets = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
    if (!$datasets) return new WP_Error('tm_empty_transfer', 'Il file non contiene gruppi importabili.');
    $same_site = untrailingslashit((string) ($payload['site_url'] ?? '')) === untrailingslashit(home_url('/'));
    $report = tm_data_transfer_report();
    $aft_map = tm_data_transfer_import_afts($datasets['afts'] ?? [], $same_site, $report);
    $facility_map = tm_data_transfer_import_facilities($datasets['facilities'] ?? [], $aft_map, $same_site, $report);
    $users = [];
    foreach (array_merge((array) ($datasets['users'] ?? []), (array) ($datasets['doctors'] ?? [])) as $record) {
        if (!is_array($record)) continue;
        $key = sanitize_email((string) ($record['user_email'] ?? '')) ?: sanitize_user((string) ($record['user_login'] ?? ''), true);
        if ($key) $users[$key] = $record;
    }
    tm_data_transfer_import_users(array_values($users), $aft_map, $facility_map, $same_site, $report);
    if (function_exists('tm_refresh_facility_rewrite_map')) tm_refresh_facility_rewrite_map();
    return $report;
}

function tm_data_transfer_notice_key() {
    return 'tm_data_transfer_notice_' . get_current_user_id();
}

function tm_data_transfer_redirect($notice) {
    set_transient(tm_data_transfer_notice_key(), $notice, MINUTE_IN_SECONDS);
    wp_safe_redirect(admin_url('options-general.php?page=tm-settings#tm-data-transfer'));
    exit;
}

add_action('admin_post_tm_import_data', 'tm_handle_data_transfer_import');
function tm_handle_data_transfer_import() {
    if (!current_user_can('manage_options')) wp_die('Non autorizzato.', 403);
    check_admin_referer('tm_import_data');
    $file = $_FILES['tm_import_file'] ?? null;
    if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        tm_data_transfer_redirect(['type' => 'error', 'message' => 'Caricamento del file non riuscito.']);
    }
    if ((int) ($file['size'] ?? 0) > 20 * MB_IN_BYTES) {
        tm_data_transfer_redirect(['type' => 'error', 'message' => 'Il file supera 20 MB.']);
    }
    $name = sanitize_file_name((string) ($file['name'] ?? ''));
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
        tm_data_transfer_redirect(['type' => 'error', 'message' => 'Carica un file JSON.']);
    }
    $contents = file_get_contents((string) $file['tmp_name']);
    if ($contents === false) tm_data_transfer_redirect(['type' => 'error', 'message' => 'Impossibile leggere il file.']);
    try {
        $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        tm_data_transfer_redirect(['type' => 'error', 'message' => 'JSON non valido: ' . $error->getMessage()]);
    }
    $result = tm_data_transfer_import_payload($payload);
    if (is_wp_error($result)) tm_data_transfer_redirect(['type' => 'error', 'message' => $result->get_error_message()]);
    $created = array_sum($result['created']);
    $updated = array_sum($result['updated']);
    $errors = count($result['errors']);
    tm_data_transfer_redirect([
        'type'    => $errors ? 'warning' : 'success',
        'message' => "Importazione completata: {$created} creati, {$updated} aggiornati, {$errors} errori.",
        'details' => array_slice($result['errors'], 0, 20),
    ]);
}

function tm_data_transfer_post_count($post_type) {
    $counts = wp_count_posts($post_type);
    return array_sum(array_map(fn($status) => (int) ($counts->$status ?? 0), ['publish', 'draft', 'pending', 'private']));
}

function tm_render_data_transfer_settings() {
    if (!current_user_can('manage_options')) return;
    $notice = get_transient(tm_data_transfer_notice_key());
    delete_transient(tm_data_transfer_notice_key());
    $users = count_users();
    $rows = [
        ['users', 'Tutti gli utenti', (int) ($users['total_users'] ?? 0)],
        ['doctors', 'Utenti medici', count(tm_turnazione_doctor_ids())],
        ['afts', 'AFT', tm_data_transfer_post_count('tm_aft')],
        ['facilities', 'Strutture', tm_data_transfer_post_count('tm_facility')],
    ];
    echo '<div class="card" id="tm-data-transfer" style="max-width:none;padding:4px 20px 20px"><h2>Importa / esporta dati</h2>';
    if (is_array($notice)) {
        echo '<div class="notice notice-' . esc_attr($notice['type'] ?? 'info') . ' is-dismissible"><p>' . esc_html($notice['message'] ?? '') . '</p>';
        if (!empty($notice['details'])) echo '<ul><li>' . implode('</li><li>', array_map('esc_html', $notice['details'])) . '</li></ul>';
        echo '</div>';
    }
    echo '<h3>Esporta</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="tm_export_data">';
    wp_nonce_field('tm_export_data');
    echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th style="width:48px"></th><th>Gruppo</th><th style="width:120px">Record</th></tr></thead><tbody>';
    foreach ($rows as [$key, $label, $count]) {
        echo '<tr><td><input type="checkbox" name="datasets[]" value="' . esc_attr($key) . '"></td><td>' . esc_html($label) . '</td><td>' . (int) $count . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p><label><input type="checkbox" name="include_magic_tokens" value="1"> Includi i magic link nel file (sono credenziali di accesso: solo per migrazione sito → sito)</label></p>';
    echo '<p class="description">Se selezioni tutti gli utenti, il gruppo medici viene incluso automaticamente. Le password non vengono mai esportate; i magic link solo se spunti la casella qui sopra.</p>';
    submit_button('Esporta JSON', 'primary', 'submit', false);
    echo '</form><hr><h3>Importa</h3><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Importare e aggiornare i dati contenuti nel file?\')">';
    echo '<input type="hidden" name="action" value="tm_import_data">';
    wp_nonce_field('tm_import_data');
    echo '<input type="file" name="tm_import_file" accept="application/json,.json" required>';
    echo '<p class="description">L’importazione crea o aggiorna i record presenti nel JSON. Non elimina gli altri dati, non esegue i workflow di registrazione e blocca ogni invio email durante la scrittura degli utenti.</p>';
    submit_button('Importa JSON', 'secondary', 'submit', false);
    echo '</form></div>';
}
