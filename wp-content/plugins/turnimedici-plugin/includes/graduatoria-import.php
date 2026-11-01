<?php

if (!defined('ABSPATH')) exit;

function tm_import_csv_records($csv) {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
    rewind($stream);
    $header = fgetcsv($stream, null, ',', '"', '');
    $records = [];
    while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
        if (count($record) === 1 && trim((string) $record[0]) === '') continue;
        $records[] = array_map(fn($cell) => trim((string) $cell), $record);
    }
    fclose($stream);
    return [
        'header' => is_array($header) ? array_map(fn($key) => strtolower(trim((string) $key)), $header) : [],
        'records' => $records,
    ];
}

function tm_parse_import_csv($csv) {
    $data = tm_import_csv_records($csv);
    $header = $data['header'];
    if (!$header || !$data['records']) return [];

    $aft_by_name = [];
    foreach (get_posts(['post_type' => 'tm_aft', 'numberposts' => -1, 'post_status' => ['publish', 'draft']]) as $p) {
        $aft_by_name[mb_strtolower(trim($p->post_title))] = $p->ID;
    }

    $rows = [];
    foreach ($data['records'] as $cells) {
        $raw = [];
        foreach ($header as $i => $key) $raw[$key] = (string) ($cells[$i] ?? '');
        if (($raw['email'] ?? '') === '' && ($raw['last_name'] ?? '') === '') continue;

        $row = [
            'first_name'    => $raw['first_name'] ?? '',
            'last_name'     => $raw['last_name'] ?? '',
            'email'         => strtolower($raw['email'] ?? ''),
            'phone'         => $raw['phone'] ?? '',
            'qualification' => $raw['qualification'] ?? '',
            'home_aft'      => ($raw['aft'] ?? '') !== '' ? ($aft_by_name[mb_strtolower($raw['aft'])] ?? 0) : 0,
            'patients'      => (int) ($raw['patients'] ?? 0),
            'role_start'    => $raw['role_start'] ?? '',
            'ca_start'      => $raw['ca_start'] ?? '',
            'ca_end'        => $raw['ca_end'] ?? '',
        ];
        if (array_key_exists('ca_rank', $raw)) $row['ca_rank'] = $raw['ca_rank'];
        if (array_key_exists('ca_zone', $raw)) $row['ca_zone'] = $raw['ca_zone'];
        if (array_key_exists('ca_cot', $raw)) {
            $row['ca_cot'] = in_array(mb_strtolower($raw['ca_cot']), ['si', 'sì', '1', 'true', 'x'], true) ? 1 : '';
        }
        if (array_key_exists('district', $raw)) $row['district'] = $raw['district'];
        $rows[] = $row;
    }
    return $rows;
}

function tm_parse_ca_roster_csv($csv) {
    if (str_contains($csv, "\0") || !mb_check_encoding($csv, 'UTF-8')) {
        return new WP_Error('tm_bad_csv', 'Il file deve essere un CSV UTF-8.', ['status' => 422]);
    }
    $data = tm_import_csv_records($csv);
    $columns = ['first_name', 'last_name', 'email', 'phone', 'qualification', 'aft', 'patients', 'role_start', 'ca_start', 'ca_end', 'ca_rank', 'ca_zone', 'ca_cot', 'district'];
    if (count($data['header']) !== count($columns) || array_diff($columns, $data['header'])) {
        return new WP_Error('tm_bad_csv', 'Intestazione CSV non valida. Colonne richieste: ' . implode(',', $columns), ['status' => 422]);
    }
    if (!$data['records']) return new WP_Error('tm_empty', 'Nessuna riga utilizzabile nel file.', ['status' => 422]);

    $district = null;
    foreach ($data['records'] as $index => $record) {
        $line = $index + 2;
        if (count($record) !== count($columns)) {
            return new WP_Error('tm_bad_csv', "Riga $line: numero di colonne non valido.", ['status' => 422]);
        }
        $row = array_combine($data['header'], $record);
        if ($row['first_name'] === '' || $row['last_name'] === '' || $row['qualification'] !== 'ca_temporaneo' || $row['district'] === '') {
            return new WP_Error('tm_bad_csv', "Riga $line: servono nome, cognome, qualifica ca_temporaneo e distretto.", ['status' => 422]);
        }
        $row_district = mb_strtolower($row['district']);
        if ($district !== null && $district !== $row_district) {
            return new WP_Error('tm_bad_csv', 'Ogni file deve contenere un solo distretto.', ['status' => 422]);
        }
        $district = $row_district;
        if (($row['ca_start'] !== '' || $row['ca_end'] !== '') && (!tm_valid_iso_date($row['ca_start']) || !tm_valid_iso_date($row['ca_end']) || $row['ca_start'] > $row['ca_end'])) {
            return new WP_Error('tm_bad_csv', "Riga $line: il contratto richiede due date YYYY-MM-DD valide e ordinate.", ['status' => 422]);
        }
        if ($row['ca_rank'] !== '' && (!ctype_digit($row['ca_rank']) || (int) $row['ca_rank'] < 1)) {
            return new WP_Error('tm_bad_csv', "Riga $line: la posizione in graduatoria deve essere un intero positivo o vuota.", ['status' => 422]);
        }
    }
    return tm_parse_import_csv($csv);
}

function tm_rows_district($rows) {
    foreach ($rows as $row) {
        $district = trim((string) ($row['district'] ?? ''));
        if ($district !== '') return $district;
    }
    return '';
}

/* Identità dei medici CA per nome+cognome normalizzati (le email degli elenchi
   cambiano o mancano): parole riordinate, così nome e cognome invertiti combaciano */
function tm_doctor_name_key($first, $last) {
    $full = remove_accents(mb_strtolower(trim($first . ' ' . $last)));
    $full = preg_replace('/[^a-z0-9\s]/', ' ', $full);
    $words = preg_split('/\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY);
    sort($words);
    return implode(' ', $words);
}

function tm_turnazione_doctor_name_index() {
    $index = [];
    foreach (get_users(['role' => 'tm_doctor']) as $user) {
        $key = tm_doctor_name_key($user->first_name, $user->last_name);
        if ($key === '') continue;
        $index[$key][] = $user;
    }
    return $index;
}

function tm_roster_row_name_key($row) {
    return tm_doctor_name_key((string) ($row['first_name'] ?? ''), (string) ($row['last_name'] ?? ''));
}

/* Un elenco è fonte di verità solo per l'intervallo di date che copre (il suo orizzonte:
   dal primo all'ultimo giorno di contratto nel file). I contratti fuori orizzonte sono
   storia dei trimestri precedenti e sopravvivono all'attivazione. */
function tm_ca_roster_horizon($rows) {
    $start = null;
    $end = null;
    foreach ($rows as $row) {
        $row_start = sanitize_text_field($row['ca_start'] ?? '');
        $row_end   = sanitize_text_field($row['ca_end'] ?? '');
        if (!tm_valid_iso_date($row_start) || !tm_valid_iso_date($row_end) || $row_start > $row_end) continue;
        if ($start === null || $row_start < $start) $start = $row_start;
        if ($end === null || $row_end > $end) $end = $row_end;
    }
    return $start !== null ? ['start' => $start, 'end' => $end] : null;
}

function tm_ca_periods_outside_horizon($periods, $horizon) {
    if ($horizon === null) return array_values((array) $periods);
    $last_day_before = date('Y-m-d', strtotime($horizon['start'] . ' -1 day'));
    $first_day_after = date('Y-m-d', strtotime($horizon['end'] . ' +1 day'));
    $kept = [];
    foreach ((array) $periods as $period) {
        $start = (string) ($period['start'] ?? '');
        $end   = (string) ($period['end'] ?? '');
        if ($start === '' || $end === '') continue;
        if ($end < $horizon['start'] || $start > $horizon['end']) {
            $kept[] = ['start' => $start, 'end' => $end];
            continue;
        }
        if ($start < $horizon['start']) $kept[] = ['start' => $start, 'end' => $last_day_before];
        if ($end > $horizon['end']) $kept[] = ['start' => $first_day_after, 'end' => $end];
    }
    return $kept;
}

function tm_ca_periods_intersect_horizon($periods, $horizon) {
    if ($horizon === null) return false;
    foreach ((array) $periods as $period) {
        $start = (string) ($period['start'] ?? '');
        $end   = (string) ($period['end'] ?? '');
        if ($start !== '' && $end !== '' && $start <= $horizon['end'] && $end >= $horizon['start']) return true;
    }
    return false;
}

function tm_merge_ca_periods($kept, $added) {
    $merged = [];
    foreach (array_merge((array) $kept, (array) $added) as $period) {
        $start = (string) ($period['start'] ?? '');
        $end   = (string) ($period['end'] ?? '');
        if ($start === '' || $end === '') continue;
        $merged[$start . '/' . $end] = ['start' => $start, 'end' => $end];
    }
    $merged = array_values($merged);
    usort($merged, fn($a, $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
    return $merged;
}

function tm_ca_periods_note($periods) {
    if (!$periods) return '';
    $labels = array_map(fn($period) => $period['start'] . ' → ' . $period['end'], $periods);
    return (count($labels) > 1 ? ' · contratti ' : ' · contratto ') . implode(', ', $labels);
}

function tm_ca_state_summary($rank, $zone, $cot) {
    $out = $rank !== null ? 'n.' . $rank : 'a chiamata';
    if ($zone !== '') $out .= ' · ' . $zone;
    if ($cot) $out .= ' · COT';
    return $out;
}

function tm_import_preview($rows) {
    $preview = ['new' => [], 'updated' => [], 'unchanged' => [], 'losing_rank' => [], 'has_ranking' => false];
    $keys_in_file = [];
    $name_index = tm_turnazione_doctor_name_index();
    $horizon = tm_ca_roster_horizon($rows);

    $periods_in_file = [];
    foreach ($rows as $row) {
        $key = tm_roster_row_name_key($row);
        $start = $row['ca_start'] ?? '';
        $end   = $row['ca_end'] ?? '';
        if ($key !== '' && $start && $end) $periods_in_file[$key][] = ['start' => $start, 'end' => $end];
    }

    foreach ($rows as $row) {
        $email = $row['email'];
        $label = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '')) ?: $email;
        $name_key = tm_roster_row_name_key($row);
        if (!empty($row['ca_rank'])) $preview['has_ranking'] = true;
        if ($name_key === '') continue;
        if (isset($keys_in_file[$name_key])) continue;
        $keys_in_file[$name_key] = true;

        $matches = $name_index[$name_key] ?? [];
        if (count($matches) > 1) {
            $preview['new'][] = ['name' => $label, 'before' => 'più utenti con questo nome', 'after' => 'riga da risolvere a mano: omonimia'];
            continue;
        }
        $user = $matches[0] ?? null;
        if (!$user && $email === '') {
            $preview['new'][] = ['name' => $label, 'before' => 'senza email nel file e non in anagrafica', 'after' => 'riga scartata: non verrà importata'];
            continue;
        }

        $new_rank = ($row['ca_rank'] ?? '') !== '' ? (int) $row['ca_rank'] : null;
        $new_zone = trim((string) ($row['ca_zone'] ?? ''));
        $new_cot = !empty($row['ca_cot']);
        $file_periods = tm_merge_ca_periods($periods_in_file[$name_key] ?? [], []);

        if (!$user) {
            $preview['new'][] = ['name' => $label, 'before' => 'non in anagrafica', 'after' => tm_ca_state_summary($new_rank, $new_zone, $new_cot) . tm_ca_periods_note($file_periods)];
            continue;
        }

        $email_note = ($email !== '' && strtolower($user->user_email) !== $email) ? ' · email aggiornata: ' . $email : '';

        $old_rank = tm_ca_rank_value($user->ID);
        $old_zone = trim((string) get_user_meta($user->ID, 'tm_ca_zone', true));
        $old_cot = (bool) get_user_meta($user->ID, 'tm_ca_cot', true);

        $current_periods = tm_merge_ca_periods(tm_get_ca_periods($user->ID), []);
        $merged_periods = tm_merge_ca_periods(tm_ca_periods_outside_horizon($current_periods, $horizon), $file_periods);

        $before = tm_ca_state_summary($old_rank, $old_zone, $old_cot) . tm_ca_periods_note($current_periods);
        $after  = tm_ca_state_summary($new_rank, $new_zone, $new_cot) . tm_ca_periods_note($merged_periods) . $email_note;

        $same_state = ($old_rank === $new_rank)
            && (mb_strtolower($old_zone) === mb_strtolower($new_zone))
            && ($old_cot === $new_cot)
            && $email_note === ''
            && $merged_periods === $current_periods;

        if ($same_state) {
            $preview['unchanged'][] = ['name' => $label, 'before' => $before, 'after' => $before];
        } else {
            $preview['updated'][] = ['name' => $label, 'before' => $before, 'after' => $after];
        }
    }

    global $wpdb;
    $with_rank = $wpdb->get_col("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'tm_ca_rank'");
    foreach ($with_rank as $uid) {
        $user = get_userdata((int) $uid);
        if (!$user || isset($keys_in_file[tm_doctor_name_key($user->first_name, $user->last_name)])) continue;
        $preview['losing_rank'][] = [
            'name'   => trim($user->first_name . ' ' . $user->last_name) ?: $user->user_email,
            'before' => 'n.' . tm_ca_rank_value($user->ID),
            'after'  => 'a chiamata (non è nel nuovo file)',
        ];
    }

    return $preview;
}

/* Le versioni degli elenchi CA: ogni file caricato è fonte di verità per il suo orizzonte.
   Attivarne una allinea i ca_temporaneo del distretto: graduatoria dal file, contratti
   dentro l'orizzonte sostituiti da quelli del file, contratti precedenti conservati e
   sommati. Chi non compare perde numero e contratti dell'orizzonte, non lo storico. */
add_action('init', function () {
    register_post_type('tm_ca_roster', [
        'public'   => false,
        'show_ui'  => false,
        'supports' => ['title'],
        'labels'   => ['name' => 'Elenchi CA', 'singular_name' => 'Elenco CA'],
    ]);
}, 5);

function tm_ca_roster_district_key($district) {
    $key = sanitize_title((string) $district);
    return $key !== '' ? $key : 'default';
}

function tm_active_ca_rosters() {
    $map = get_option('tm_active_ca_rosters', []);
    return is_array($map) ? array_map('intval', $map) : [];
}

function tm_ca_roster_payload($post) {
    $district = (string) get_post_meta($post->ID, 'tm_roster_district', true);
    $active = tm_active_ca_rosters();
    $status = (string) get_post_meta($post->ID, 'tm_roster_status', true);
    if ($status === '') $status = trim((string) $post->post_content) !== '' ? 'scanned' : 'uploaded';
    $scan_lock = (int) get_post_meta($post->ID, 'tm_roster_scan_lock', true);
    $rows = $status === 'scanned' ? tm_ca_roster_rows($post->ID) : null;
    return [
        'id'        => $post->ID,
        'filename'  => (string) get_post_meta($post->ID, 'tm_roster_filename', true) ?: $post->post_title,
        'district'  => $district,
        'rows'      => (int) get_post_meta($post->ID, 'tm_roster_row_count', true),
        'doctors'   => (int) get_post_meta($post->ID, 'tm_roster_doctor_count', true),
        'uploaded'  => get_the_date('d/m/Y H:i', $post),
        'active'    => ($active[tm_ca_roster_district_key($district)] ?? 0) === $post->ID,
        'status'    => $status,
        'scanning'  => $scan_lock && time() - $scan_lock < 300,
        'error'     => (string) get_post_meta($post->ID, 'tm_roster_error', true),
        'has_file'  => tm_ca_roster_file_path($post->ID) !== '',
        'horizon'   => $rows ? tm_ca_roster_horizon($rows) : null,
    ];
}

function tm_ca_roster_rows($roster_id) {
    $post = get_post($roster_id);
    if (!$post || $post->post_type !== 'tm_ca_roster') return null;
    $rows = json_decode($post->post_content, true);
    return is_array($rows) ? $rows : null;
}

/* I CSV originali restano in archivio (per la rilettura) in una cartella chiusa
   al web: contengono dati personali dei medici, non devono finire sotto URL pubbliche */
function tm_ca_roster_storage_dir() {
    $dir = wp_upload_dir()['basedir'] . '/tm-ca-rosters';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
        file_put_contents($dir . '/index.php', "<?php\n");
    }
    return $dir;
}

function tm_ca_roster_file_path($roster_id) {
    $stored_name = basename((string) get_post_meta($roster_id, 'tm_roster_file', true));
    if ($stored_name === '') return '';
    $path = tm_ca_roster_storage_dir() . '/' . $stored_name;
    return file_exists($path) ? $path : '';
}

function tm_store_ca_roster_upload($filename, $binary) {
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') return new WP_Error('tm_bad_ext', 'Serve un file .csv.', ['status' => 400]);
    if (strlen($binary) > 5 * MB_IN_BYTES) return new WP_Error('tm_file_too_large', 'Il CSV non deve superare 5 MB.', ['status' => 413]);
    $rows = tm_parse_ca_roster_csv($binary);
    if (is_wp_error($rows)) return $rows;
    $file_hash = md5($binary);
    $stored_name = $file_hash . '.csv';
    $path = tm_ca_roster_storage_dir() . '/' . $stored_name;
    if (!file_exists($path) && file_put_contents($path, $binary) === false) return new WP_Error('tm_roster_write', 'Impossibile archiviare il CSV.', ['status' => 500]);

    $existing = get_posts([
        'post_type'   => 'tm_ca_roster',
        'numberposts' => 1,
        'post_status' => 'private',
        'meta_key'    => 'tm_roster_hash',
        'meta_value'  => $file_hash,
        'fields'      => 'ids',
    ]);
    if ($existing) {
        $roster_id = (int) $existing[0];
        if ((string) get_post_meta($roster_id, 'tm_roster_file', true) === '') {
            update_post_meta($roster_id, 'tm_roster_file', $stored_name);
        }
        tm_write_ca_roster_rows($roster_id, $rows);
        return ['id' => $roster_id, 'already_known' => true];
    }

    $post_id = wp_insert_post([
        'post_type'   => 'tm_ca_roster',
        'post_status' => 'private',
        'post_title'  => $filename,
    ], true);
    if (is_wp_error($post_id)) return $post_id;

    update_post_meta($post_id, 'tm_roster_filename', $filename);
    update_post_meta($post_id, 'tm_roster_hash', $file_hash);
    update_post_meta($post_id, 'tm_roster_file', $stored_name);
    tm_write_ca_roster_rows($post_id, $rows);
    return ['id' => (int) $post_id, 'already_known' => false];
}

function tm_write_ca_roster_rows($roster_id, $rows) {
    wp_update_post(['ID' => $roster_id, 'post_content' => wp_slash(wp_json_encode($rows, JSON_UNESCAPED_UNICODE))]);
    $names = [];
    foreach ($rows as $row) {
        $key = tm_roster_row_name_key($row);
        if ($key !== '') $names[$key] = true;
    }
    update_post_meta($roster_id, 'tm_roster_district', tm_rows_district($rows));
    update_post_meta($roster_id, 'tm_roster_row_count', count($rows));
    update_post_meta($roster_id, 'tm_roster_doctor_count', count($names));
    update_post_meta($roster_id, 'tm_roster_status', 'scanned');
    delete_post_meta($roster_id, 'tm_roster_error');
    return tm_ca_roster_payload(get_post($roster_id));
}

function tm_scan_ca_roster($roster_id) {
    $post = get_post($roster_id);
    if (!$post || $post->post_type !== 'tm_ca_roster') {
        return new WP_Error('tm_roster_not_found', 'Versione non trovata.', ['status' => 404]);
    }
    $path = tm_ca_roster_file_path($roster_id);
    if ($path === '') {
        return new WP_Error('tm_roster_no_file', 'Il file originale non è in archivio: ricarica il CSV.', ['status' => 409]);
    }
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
        return new WP_Error('tm_bad_ext', 'Questa versione richiede un nuovo file CSV.', ['status' => 400]);
    }
    $csv = file_get_contents($path);
    if ($csv === false) return new WP_Error('tm_roster_read', 'Impossibile leggere il CSV archiviato.', ['status' => 500]);
    $rows = tm_parse_ca_roster_csv($csv);
    if (is_wp_error($rows)) {
        update_post_meta($roster_id, 'tm_roster_status', 'failed');
        update_post_meta($roster_id, 'tm_roster_error', $rows->get_error_message());
        return tm_ca_roster_payload($post);
    }
    delete_post_meta($roster_id, 'tm_roster_scan_lock');
    return tm_write_ca_roster_rows($roster_id, $rows);
}

function tm_ca_roster_report($rows) {
    $doctors = [];
    foreach ($rows as $row) {
        $key = tm_roster_row_name_key($row);
        if ($key === '') continue;
        if (!isset($doctors[$key])) {
            $doctors[$key] = [
                'name'    => trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '')),
                'rank'    => null,
                'zone'    => '',
                'cot'     => false,
                'email'   => '',
                'phone'   => '',
                'periods' => [],
            ];
        }
        if (($row['ca_start'] ?? '') && ($row['ca_end'] ?? '')) {
            $doctors[$key]['periods'][] = ['start' => $row['ca_start'], 'end' => $row['ca_end']];
        }
        if ($doctors[$key]['rank'] === null && ($row['ca_rank'] ?? '') !== '') $doctors[$key]['rank'] = (int) $row['ca_rank'];
        if ($doctors[$key]['zone'] === '') $doctors[$key]['zone'] = trim((string) ($row['ca_zone'] ?? ''));
        if (!$doctors[$key]['cot']) $doctors[$key]['cot'] = !empty($row['ca_cot']);
        if ($doctors[$key]['email'] === '') $doctors[$key]['email'] = strtolower((string) ($row['email'] ?? ''));
        if ($doctors[$key]['phone'] === '') $doctors[$key]['phone'] = (string) ($row['phone'] ?? '');
    }
    $contracts = 0;
    foreach ($doctors as &$doctor) {
        $doctor['periods'] = tm_merge_ca_periods($doctor['periods'], []);
        $contracts += count($doctor['periods']);
    }
    unset($doctor);
    $doctors = array_values($doctors);
    usort($doctors, function ($a, $b) {
        if (($a['rank'] === null) !== ($b['rank'] === null)) return $a['rank'] === null ? 1 : -1;
        if ($a['rank'] !== $b['rank']) return $a['rank'] <=> $b['rank'];
        return strcasecmp($a['name'], $b['name']);
    });
    return [
        'district'  => tm_rows_district($rows),
        'horizon'   => tm_ca_roster_horizon($rows),
        'rows'      => count($rows),
        'doctors'   => $doctors,
        'ranked'    => count(array_filter($doctors, fn($doctor) => $doctor['rank'] !== null)),
        'on_call'   => count(array_filter($doctors, fn($doctor) => $doctor['rank'] === null)),
        'contracts' => $contracts,
    ];
}

/* I ca_temporaneo governati da questo distretto: chi ha il distretto della versione
   e chi non ne ha ancora uno (import precedenti al versionamento) */
function tm_ca_roster_scope_user_ids($district) {
    $ids = [];
    foreach (get_users(['meta_key' => 'tm_qualification', 'meta_value' => 'ca_temporaneo', 'fields' => 'ID']) as $user_id) {
        $user_district = (string) get_user_meta($user_id, 'tm_ca_district', true);
        if ($user_district === '' || tm_ca_roster_district_key($user_district) === tm_ca_roster_district_key($district)) {
            $ids[] = (int) $user_id;
        }
    }
    return $ids;
}

function tm_doctor_open_queue_labels($doctor_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT a.schedule_id FROM ' . tm_table('assignments') . " a
         INNER JOIN {$wpdb->posts} p ON p.ID = a.schedule_id AND p.post_status IN ('publish', 'draft')
         WHERE a.doctor_id = %d AND (a.status IS NULL OR a.status = '' OR a.status NOT IN ('done', 'skipped'))",
        $doctor_id
    ));
    $labels = [];
    foreach ($rows as $row) {
        $facility_id = (int) get_post_meta($row->schedule_id, 'tm_facility_id', true);
        $label = trim(get_the_title($facility_id) . ' · ' . (function_exists('tm_queue_month_label') ? tm_queue_month_label($row->schedule_id) : ''), ' ·');
        if ($label) $labels[] = $label;
    }
    return $labels;
}

function tm_ca_roster_deactivation_list($rows, $district) {
    $keys_in_file = [];
    foreach ($rows as $row) {
        $key = tm_roster_row_name_key($row);
        if ($key !== '') $keys_in_file[$key] = true;
    }
    $horizon = tm_ca_roster_horizon($rows);
    $out = [];
    foreach (tm_ca_roster_scope_user_ids($district) as $user_id) {
        $user = get_userdata($user_id);
        if (!$user || isset($keys_in_file[tm_doctor_name_key($user->first_name, $user->last_name)])) continue;
        $periods = tm_get_ca_periods($user_id);
        if (!tm_ca_periods_intersect_horizon($periods, $horizon) && tm_ca_rank_value($user_id) === null) continue;
        $out[] = [
            'user_id'      => $user_id,
            'name'         => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
            'open_queues'  => tm_doctor_open_queue_labels($user_id),
            'kept_periods' => count(tm_ca_periods_outside_horizon($periods, $horizon)),
        ];
    }
    return $out;
}

function tm_ca_roster_doctor_entries($rows) {
    $doctors = [];
    foreach ($rows as $row) {
        $name_key = tm_roster_row_name_key($row);
        if ($name_key === '') continue;
        if (!isset($doctors[$name_key])) {
            $doctors[$name_key] = ['payload' => $row, 'periods' => []];
        }
        $start = sanitize_text_field($row['ca_start'] ?? '');
        $end   = sanitize_text_field($row['ca_end'] ?? '');
        if ($start && $end) $doctors[$name_key]['periods'][] = ['start' => $start, 'end' => $end];
        foreach (['ca_rank', 'ca_zone', 'ca_cot', 'email', 'phone'] as $key) {
            if (($row[$key] ?? '') !== '' && ($doctors[$name_key]['payload'][$key] ?? '') === '') {
                $doctors[$name_key]['payload'][$key] = $row[$key];
            }
        }
    }
    return $doctors;
}

function tm_activate_ca_roster($roster_id) {
    $rows = tm_ca_roster_rows($roster_id);
    if ($rows === null) {
        return new WP_Error('tm_roster_not_found', 'Versione non trovata.', ['status' => 404]);
    }
    $district = (string) get_post_meta($roster_id, 'tm_roster_district', true);
    $horizon = tm_ca_roster_horizon($rows);
    $doctors = tm_ca_roster_doctor_entries($rows);

    /* cambio email dal file: niente notifiche core al medico */
    add_filter('send_email_change_email', '__return_false');

    $name_index = tm_turnazione_doctor_name_index();
    $created = 0;
    $updated = 0;
    $errors = [];
    foreach ($doctors as $name_key => $entry) {
        $payload = $entry['payload'];
        $label = trim(($payload['last_name'] ?? '') . ' ' . ($payload['first_name'] ?? '')) ?: $name_key;
        $email = strtolower(sanitize_email($payload['email'] ?? ''));

        $matches = $name_index[$name_key] ?? [];
        if (count($matches) > 1) {
            $errors[] = "$label: più utenti con questo nome, risolvere a mano";
            continue;
        }
        $user = $matches[0] ?? null;

        $checked = function_exists('tm_validate_doctor_payload') ? tm_validate_doctor_payload($payload, $user ? $user->ID : 0) : $payload;
        if (is_wp_error($checked)) {
            $errors[] = "$label: " . $checked->get_error_message();
            continue;
        }
        $payload = $checked;
        $first = sanitize_text_field($payload['first_name'] ?? '');
        $last  = sanitize_text_field($payload['last_name'] ?? '');

        if (!$user) {
            if (!$email || !is_email($email)) {
                $errors[] = "$label: non in anagrafica e senza email nel file";
                continue;
            }
            $email_owner = get_user_by('email', $email);
            if ($email_owner) {
                $errors[] = "$label: l'email $email appartiene già a " . $email_owner->display_name;
                continue;
            }
            $user_id = tm_create_doctor_user($email, $first, $last);
            if (is_wp_error($user_id)) {
                $errors[] = "$label: " . $user_id->get_error_message();
                continue;
            }
            $created++;
        } else {
            if (!tm_user_is_turnazione_doctor($user->ID)) {
                $errors[] = "$label: l'utente esiste ma non è un medico";
                continue;
            }
            $update = [
                'ID'           => $user->ID,
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => trim("$first $last") ?: $user->user_email,
            ];
            if ($email && is_email($email) && strtolower($user->user_email) !== $email) {
                $email_owner = get_user_by('email', $email);
                if ($email_owner && (int) $email_owner->ID !== (int) $user->ID) {
                    $errors[] = "$label: l'email $email appartiene già a " . $email_owner->display_name . ', email non aggiornata';
                } else {
                    $update['user_email'] = $email;
                }
            }
            wp_update_user($update);
            $user_id = $user->ID;
            $updated++;
        }

        unset($payload['ca_start'], $payload['ca_end'], $payload['email']);
        if (($payload['ca_rank'] ?? '') === '') $payload['ca_rank'] = null;
        tm_save_doctor_meta($user_id, $payload);
        $kept_periods = tm_ca_periods_outside_horizon(tm_get_ca_periods($user_id), $horizon);
        tm_set_ca_periods($user_id, tm_merge_ca_periods($kept_periods, $entry['periods']));
        update_user_meta($user_id, 'tm_ca_district', $district);
    }

    remove_filter('send_email_change_email', '__return_false');

    $deactivated = [];
    foreach (tm_ca_roster_deactivation_list($rows, $district) as $item) {
        $kept_periods = tm_ca_periods_outside_horizon(tm_get_ca_periods($item['user_id']), $horizon);
        tm_set_ca_periods($item['user_id'], tm_merge_ca_periods($kept_periods, []));
        delete_user_meta($item['user_id'], 'tm_ca_rank');
        delete_user_meta($item['user_id'], 'tm_ca_cot');
        $deactivated[] = $item;
    }

    $active = tm_active_ca_rosters();
    $active[tm_ca_roster_district_key($district)] = (int) $roster_id;
    update_option('tm_active_ca_rosters', $active, false);

    return [
        'created'     => $created,
        'updated'     => $updated,
        'deactivated' => $deactivated,
        'errors'      => $errors,
        'roster'      => tm_ca_roster_payload(get_post($roster_id)),
    ];
}

/* Somma i contratti di un elenco (tipicamente storico) senza toccare la graduatoria
   né l'elenco in vigore: dentro il suo orizzonte i contratti vengono riallineati al
   file per tutto il distretto, fuori restano; i medici mancanti vengono creati senza
   numero. Rank, zona e COT restano materia dell'elenco attivo. */
function tm_apply_ca_roster_contracts($roster_id) {
    $rows = tm_ca_roster_rows($roster_id);
    if ($rows === null) {
        return new WP_Error('tm_roster_not_scanned', 'File non ancora scansionato.', ['status' => 409]);
    }
    $horizon = tm_ca_roster_horizon($rows);
    if ($horizon === null) {
        return new WP_Error('tm_no_contracts', 'Nessun contratto datato nel file.', ['status' => 422]);
    }
    $district = (string) get_post_meta($roster_id, 'tm_roster_district', true);
    $doctors = tm_ca_roster_doctor_entries($rows);
    $name_index = tm_turnazione_doctor_name_index();
    $created = 0;
    $updated = 0;
    $errors = [];

    foreach ($doctors as $name_key => $entry) {
        $payload = $entry['payload'];
        $label = trim(($payload['last_name'] ?? '') . ' ' . ($payload['first_name'] ?? '')) ?: $name_key;

        $matches = $name_index[$name_key] ?? [];
        if (count($matches) > 1) {
            $errors[] = "$label: più utenti con questo nome, risolvere a mano";
            continue;
        }
        $user = $matches[0] ?? null;

        if ($user) {
            if (!tm_user_is_turnazione_doctor($user->ID)) {
                $errors[] = "$label: l'utente esiste ma non è un medico";
                continue;
            }
            $user_id = $user->ID;
        } else {
            $email = strtolower(sanitize_email($payload['email'] ?? ''));
            if (!$email || !is_email($email)) {
                $errors[] = "$label: non in anagrafica e senza email nel file";
                continue;
            }
            $email_owner = get_user_by('email', $email);
            if ($email_owner) {
                $errors[] = "$label: l'email $email appartiene già a " . $email_owner->display_name;
                continue;
            }
            unset($payload['ca_rank'], $payload['ca_zone'], $payload['ca_cot'], $payload['ca_start'], $payload['ca_end'], $payload['email']);
            $checked = function_exists('tm_validate_doctor_payload') ? tm_validate_doctor_payload($payload, 0) : $payload;
            if (is_wp_error($checked)) {
                $errors[] = "$label: " . $checked->get_error_message();
                continue;
            }
            $user_id = tm_create_doctor_user($email, sanitize_text_field($payload['first_name'] ?? ''), sanitize_text_field($payload['last_name'] ?? ''));
            if (is_wp_error($user_id)) {
                $errors[] = "$label: " . $user_id->get_error_message();
                continue;
            }
            tm_save_doctor_meta($user_id, $checked);
            $created++;
        }

        $current = tm_merge_ca_periods(tm_get_ca_periods($user_id), []);
        $merged = tm_merge_ca_periods(tm_ca_periods_outside_horizon($current, $horizon), $entry['periods']);
        if ($merged !== $current) {
            tm_set_ca_periods($user_id, $merged);
            if ($user) $updated++;
        }
        if ((string) get_user_meta($user_id, 'tm_ca_district', true) === '') {
            update_user_meta($user_id, 'tm_ca_district', $district);
        }
    }

    $trimmed = 0;
    foreach (tm_ca_roster_scope_user_ids($district) as $user_id) {
        $scoped = get_userdata($user_id);
        if (!$scoped || isset($doctors[tm_doctor_name_key($scoped->first_name, $scoped->last_name)])) continue;
        $periods = tm_get_ca_periods($user_id);
        if (!tm_ca_periods_intersect_horizon($periods, $horizon)) continue;
        tm_set_ca_periods($user_id, tm_merge_ca_periods(tm_ca_periods_outside_horizon($periods, $horizon), []));
        $trimmed++;
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'trimmed' => $trimmed,
        'errors'  => $errors,
        'horizon' => $horizon,
        'roster'  => tm_ca_roster_payload(get_post($roster_id)),
    ];
}

add_action('rest_api_init', function () {
    $can = fn() => current_user_can('edit_users');

    register_rest_route('turnimedici/v1', '/doctors/ca-rosters/upload', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => function ($r) {
            $files = $r->get_file_params();
            if (empty($files['file']['tmp_name'])) return new WP_Error('tm_no_file', 'Nessun file ricevuto.', ['status' => 400]);
            $name = sanitize_file_name($files['file']['name'] ?: 'elenco-ca.csv');
            if (!preg_match('/\.csv$/i', $name)) return new WP_Error('tm_bad_ext', 'Serve un file .csv.', ['status' => 400]);

            if (!empty($files['file']['error'])) return new WP_Error('tm_upload_failed', 'Caricamento del CSV non riuscito.', ['status' => 400]);
            if (filesize($files['file']['tmp_name']) > 5 * MB_IN_BYTES) return new WP_Error('tm_file_too_large', 'Il CSV non deve superare 5 MB.', ['status' => 413]);
            $csv = file_get_contents($files['file']['tmp_name']);
            if ($csv === false) return new WP_Error('tm_roster_read', 'Impossibile leggere il CSV ricevuto.', ['status' => 400]);
            $stored = tm_store_ca_roster_upload($name, $csv);
            if (is_wp_error($stored)) return $stored;
            return [
                'roster'        => tm_ca_roster_payload(get_post($stored['id'])),
                'already_known' => $stored['already_known'],
            ];
        },
    ]);

    register_rest_route('turnimedici/v1', '/doctors/ca-rosters/(?P<id>\d+)/scan', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => fn($r) => tm_scan_ca_roster((int) $r['id']),
    ]);

    register_rest_route('turnimedici/v1', '/doctors/ca-rosters/(?P<id>\d+)/apply-contracts', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => fn($r) => tm_apply_ca_roster_contracts((int) $r['id']),
    ]);

    register_rest_route('turnimedici/v1', '/doctors/ca-rosters/(?P<id>\d+)/deactivate', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => function ($r) {
            $roster_id = (int) $r['id'];
            $post = get_post($roster_id);
            if (!$post || $post->post_type !== 'tm_ca_roster') {
                return new WP_Error('tm_roster_not_found', 'Versione non trovata.', ['status' => 404]);
            }
            $district_key = tm_ca_roster_district_key((string) get_post_meta($roster_id, 'tm_roster_district', true));
            $active = tm_active_ca_rosters();
            if (($active[$district_key] ?? 0) === $roster_id) {
                unset($active[$district_key]);
                update_option('tm_active_ca_rosters', $active, false);
            }
            return tm_ca_roster_payload(get_post($roster_id));
        },
    ]);

    register_rest_route('turnimedici/v1', '/doctors/ca-rosters/(?P<id>\d+)/report', [
        'methods' => 'GET', 'permission_callback' => $can,
        'callback' => function ($r) {
            $rows = tm_ca_roster_rows((int) $r['id']);
            if ($rows === null) return new WP_Error('tm_roster_not_scanned', 'File non ancora scansionato.', ['status' => 404]);
            return [
                'roster' => tm_ca_roster_payload(get_post((int) $r['id'])),
                'report' => tm_ca_roster_report($rows),
            ];
        },
    ]);

    register_rest_route('turnimedici/v1', '/doctors/ca-rosters', [
        'methods' => 'GET', 'permission_callback' => $can,
        'callback' => function () {
            $posts = get_posts([
                'post_type'   => 'tm_ca_roster',
                'numberposts' => -1,
                'post_status' => 'private',
                'orderby'     => 'date',
                'order'       => 'ASC',
            ]);
            return array_map('tm_ca_roster_payload', $posts);
        },
    ]);

    register_rest_route('turnimedici/v1', '/doctors/ca-rosters/(?P<id>\d+)/preview', [
        'methods' => 'GET', 'permission_callback' => $can,
        'callback' => function ($r) {
            $rows = tm_ca_roster_rows((int) $r['id']);
            if ($rows === null) return new WP_Error('tm_roster_not_found', 'Versione non trovata.', ['status' => 404]);
            return [
                'preview'      => tm_import_preview($rows),
                'deactivating' => tm_ca_roster_deactivation_list($rows, (string) get_post_meta((int) $r['id'], 'tm_roster_district', true)),
            ];
        },
    ]);

    register_rest_route('turnimedici/v1', '/doctors/ca-rosters/(?P<id>\d+)/activate', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => fn($r) => tm_activate_ca_roster((int) $r['id']),
    ]);

    register_rest_route('turnimedici/v1', '/doctors/ca-rosters/(?P<id>\d+)/csv', [
        'methods' => 'GET', 'permission_callback' => $can,
        'callback' => function ($r) {
            $roster_id = (int) $r['id'];
            $rows = tm_ca_roster_rows($roster_id);
            if ($rows === null) return new WP_Error('tm_roster_not_found', 'Versione non trovata.', ['status' => 404]);
            $columns = ['first_name', 'last_name', 'email', 'phone', 'qualification', 'aft', 'patients', 'role_start', 'ca_start', 'ca_end', 'ca_rank', 'ca_zone', 'ca_cot', 'district'];
            $lines = [implode(',', $columns)];
            foreach ($rows as $row) {
                $cells = array_map(function ($key) use ($row) {
                    $value = (string) ($row[$key] ?? '');
                    return (strpbrk($value, ",\"\n") !== false) ? '"' . str_replace('"', '""', $value) . '"' : $value;
                }, $columns);
                $lines[] = implode(',', $cells);
            }
            return [
                'filename' => preg_replace('/\.csv$/i', '', (string) get_post_meta($roster_id, 'tm_roster_filename', true) ?: 'elenco-ca') . '.csv',
                'csv'      => implode("\n", $lines),
            ];
        },
    ]);
});
