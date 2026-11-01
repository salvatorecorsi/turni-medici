<?php

if (!defined('ABSPATH')) exit;

const TM_ESTAR_DUMP_URL = 'https://servizi.estar.toscana.it/adiba/ambulatori.php?a=0';
const TM_ESTAR_SEARCH_URL = 'https://servizi.estar.toscana.it/adiba/ambulatori.php?chiave=';

function tm_download_estar_dump() {
    if (tm_demo_mode()) return false;
    $result = tm_with_database_lock('estar_refresh', function () {
        $response = wp_remote_get(TM_ESTAR_DUMP_URL, ['timeout' => 120]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) return false;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['medici'])) return false;
        return tm_store_estar_dump($data['medici']);
    }, 1);
    return !is_wp_error($result) && $result === true;
}

function tm_store_estar_dump($medici) {
    if (!is_array($medici) || empty($medici)) {
        return false;
    }

    $result = tm_with_database_lock('estar_dump', function () use ($medici) {
        global $wpdb;
        $table = tm_table('estar_dump');
        $wpdb->query('START TRANSACTION');
        if ($wpdb->query("DELETE FROM $table") === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $inserted = 0;
        foreach (array_chunk($medici, 200) as $chunk) {
            $placeholders = [];
            $values = [];
            foreach ($chunk as $medico) {
                if (!is_array($medico) || trim((string) ($medico['id'] ?? '')) === '') continue;
                $placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%d,%s)';
                array_push(
                    $values,
                    (string) $medico['id'],
                    (string) ($medico['nome'] ?? ''),
                    (string) ($medico['cognome'] ?? ''),
                    (string) ($medico['tipologia'] ?? ''),
                    (string) ($medico['ambito'] ?? ''),
                    (string) ($medico['aft'] ?? ''),
                    (string) ($medico['codice_regionale'] ?? ''),
                    (int) ($medico['scelte_disponibili'] ?? 0),
                    wp_json_encode($medico['ambulatori'] ?? [])
                );
            }
            if (!$placeholders) continue;
            $sql = "INSERT INTO $table (estar_id,nome,cognome,tipologia,ambito,aft,codice_regionale,scelte_disponibili,ambulatori) VALUES " . implode(',', $placeholders);
            $written = $wpdb->query($wpdb->prepare($sql, $values));
            if ($written === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            $inserted += (int) $written;
        }
        if ($inserted === 0 || $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        return $inserted;
    });
    if (is_wp_error($result) || !$result) return false;

    update_option('tm_estar_dump', ['updated' => time(), 'count' => (int) $result]);

    foreach (glob(trailingslashit(wp_upload_dir()['basedir']) . 'dump-doctors-*.json') as $old) {
        unlink($old);
    }
    return true;
}

function tm_estar_row_to_medico($row) {
    return [
        'id'                 => $row['estar_id'],
        'nome'               => $row['nome'],
        'cognome'            => $row['cognome'],
        'tipologia'          => $row['tipologia'],
        'ambito'             => $row['ambito'],
        'aft'                => $row['aft'],
        'codice_regionale'   => $row['codice_regionale'],
        'scelte_disponibili' => $row['scelte_disponibili'],
        'ambulatori'         => json_decode($row['ambulatori'] ?: '[]', true) ?: [],
    ];
}

function tm_estar_dump_count() {
    global $wpdb;
    return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . tm_table('estar_dump'));
}

function tm_search_estar($query) {
    global $wpdb;
    $table = tm_table('estar_dump');
    $terms = array_values(array_filter(explode(' ', trim($query))));
    if (empty($terms)) return [];

    $where = [];
    $params = [];
    foreach ($terms as $term) {
        $like = '%' . $wpdb->esc_like($term) . '%';
        $where[] = '(nome LIKE %s OR cognome LIKE %s)';
        $params[] = $like;
        $params[] = $like;
    }
    $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $where) . ' ORDER BY cognome ASC, nome ASC LIMIT 100';
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    return array_map('tm_estar_row_to_medico', $rows);
}

function tm_find_in_dump($estar_id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . tm_table('estar_dump') . ' WHERE estar_id = %s', $estar_id), ARRAY_A);
    return $row ? tm_estar_row_to_medico($row) : null;
}

function tm_find_in_dump_by_name($first, $last) {
    global $wpdb;
    $first = mb_strtolower(trim((string) $first), 'UTF-8');
    $last = mb_strtolower(trim((string) $last), 'UTF-8');
    if ($first === '' || $last === '') return ['count' => 0, 'medico' => null];

    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . tm_table('estar_dump') . ' WHERE LOWER(nome) = %s AND LOWER(cognome) = %s LIMIT 2',
        $first,
        $last
    ), ARRAY_A);

    return [
        'count'  => count($rows),
        'medico' => count($rows) === 1 ? tm_estar_row_to_medico($rows[0]) : null,
    ];
}


function tm_estar_live_search($query) {
    $response = wp_remote_get(TM_ESTAR_SEARCH_URL . rawurlencode($query), ['timeout' => 20]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return [];
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($data) ? ($data['medici'] ?? []) : [];
}

function tm_name_key($first, $last) {
    return mb_strtolower(trim((string) $first)) . '|' . mb_strtolower(trim((string) $last));
}

function tm_attach_local_matches($medici) {
    $by_estar = [];
    $by_name = [];
    $doctor_ids = function_exists('tm_turnazione_doctor_ids') ? tm_turnazione_doctor_ids() : get_users(['role' => 'tm_doctor', 'fields' => 'ID']);
    foreach (array_filter(array_map('get_userdata', $doctor_ids)) as $doctor) {
        $estar = get_user_meta($doctor->ID, 'tm_estar_id', true);
        if ($estar) $by_estar[$estar][] = $doctor->ID;
        $by_name[tm_name_key($doctor->first_name, $doctor->last_name)][] = $doctor->ID;
    }
    foreach ($medici as &$medico) {
        $medico['estar_phone'] = tm_estar_main_phone($medico);
        $medico['aft_id'] = tm_match_aft($medico['aft'] ?? '');
        $estar_matches = $by_estar[$medico['id'] ?? ''] ?? [];
        $name_matches = $by_name[tm_name_key($medico['nome'] ?? '', $medico['cognome'] ?? '')] ?? [];
        $local_id = count($estar_matches) === 1 ? (int) $estar_matches[0] : (count($estar_matches) === 0 && count($name_matches) === 1 ? (int) $name_matches[0] : 0);
        $medico['local_id'] = $local_id;
        $local_qualification = $local_id ? (string) get_user_meta($local_id, 'tm_qualification', true) : '';
        $medico['assistiti'] = max(0, tm_doctor_max_patients($local_qualification) - (int) ($medico['scelte_disponibili'] ?? 0));
        if ($local_id) {
            $medico['local'] = [
                'patients' => (int) get_user_meta($local_id, 'tm_patients', true),
                'phone'    => (string) get_user_meta($local_id, 'tm_phone', true),
            ];
        }
    }
    return $medici;
}

function tm_estar_main_phone($medico) {
    $ambulatori = (array) ($medico['ambulatori'] ?? []);
    foreach ($ambulatori as $ambulatorio) {
        if (($ambulatorio['principale'] ?? '') === 'Y' && !empty($ambulatorio['telefono'])) return $ambulatorio['telefono'];
    }
    foreach ($ambulatori as $ambulatorio) {
        if (!empty($ambulatorio['telefono'])) return $ambulatorio['telefono'];
    }
    return '';
}

function tm_map_estar_tipologia($tipologia) {
    $tipologia = mb_strtoupper(trim((string) $tipologia));
    if ($tipologia === 'MMG') return 'mmg';
    if (strpos($tipologia, 'CA') !== false) return 'ca_titolare';
    return 'mmg';
}

function tm_estar_person_name($name) {
    return mb_convert_case(mb_strtolower((string) $name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

function tm_sync_doctor_with_estar($user_id, $medico, $sync_profile = true) {
    $user = get_user_by('id', $user_id);
    if (!$user) return ['changed' => false, 'unmatched_aft' => false];

    $changed = false;
    if ($sync_profile) {
        $update = ['ID' => $user_id];
        $first = tm_estar_person_name($medico['nome'] ?? '');
        $last = tm_estar_person_name($medico['cognome'] ?? '');

        if ($first !== (string) $user->first_name) {
            $update['first_name'] = $first;
            $changed = true;
        }
        if ($last !== (string) $user->last_name) {
            $update['last_name'] = $last;
            $changed = true;
        }
        if (count($update) > 1) {
            $update['display_name'] = trim("$first $last") ?: $user->user_email;
            $updated = wp_update_user($update);
            if (is_wp_error($updated)) {
                return ['changed' => false, 'unmatched_aft' => false, 'error' => $updated->get_error_message()];
            }
        }
    }

    $doctor_qualification = (string) get_user_meta($user_id, 'tm_qualification', true);
    $payload = [
        'patients' => max(0, tm_doctor_max_patients($doctor_qualification) - (int) ($medico['scelte_disponibili'] ?? 0)),
        'phone'    => tm_estar_main_phone($medico),
    ];
    $unmatched_aft = false;
    if ($sync_profile) {
        $aft_id = tm_match_aft($medico['aft'] ?? '');
        $unmatched_aft = !$aft_id && trim((string) ($medico['aft'] ?? '')) !== '';
        if ($aft_id) {
            $payload['home_aft'] = $aft_id;
        }
    }

    foreach ([
        'patients' => ['tm_patients', 'int'],
        'phone'    => ['tm_phone', 'string'],
        'home_aft' => ['tm_home_aft', 'int'],
    ] as $field => [$meta_key, $type]) {
        if (!array_key_exists($field, $payload)) continue;
        $current = get_user_meta($user_id, $meta_key, true);
        $current = $type === 'int' ? (int) $current : (string) $current;
        $next = $type === 'int' ? (int) $payload[$field] : (string) $payload[$field];
        if ($current !== $next) {
            $changed = true;
            break;
        }
    }
    tm_save_doctor_meta($user_id, $payload);

    foreach ([
        'tm_estar_id'          => $medico['id'] ?? '',
        'tm_codice_regionale' => $medico['codice_regionale'] ?? '',
    ] as $meta_key => $value) {
        $value = sanitize_text_field($value);
        if ((string) get_user_meta($user_id, $meta_key, true) !== $value) {
            update_user_meta($user_id, $meta_key, $value);
            $changed = true;
        }
    }

    return ['changed' => $changed, 'unmatched_aft' => $unmatched_aft];
}

function tm_aft_norm($name) {
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', str_ireplace('AFT', '', (string) $name))));
}

function tm_aft_title_case($s) {
    $minor = ['di', 'del', 'dello', 'della', 'dei', 'degli', 'delle', 'da', 'dal', 'e', 'ed', 'il', 'lo', 'la', 'i', 'gli', 'le', 'in', 'con', 'su', 'per', 'tra', 'fra', 'a', 'al', 'allo', 'alla', 'ai', 'agli', 'alle'];
    $parts = preg_split('/(\s+|-|\/|,)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $first = true;
    foreach ($parts as $p) {
        if ($p === '') continue;
        if (preg_match('/^(\s+|[-\/,])$/u', $p)) { $out .= $p; continue; }
        $lw = mb_strtolower($p, 'UTF-8');
        if (!$first && in_array($lw, $minor, true)) {
            $out .= $lw;
        } else {
            $out .= mb_strtoupper(mb_substr($lw, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($lw, 1, null, 'UTF-8');
        }
        $first = false;
    }
    return $out;
}

function tm_aft_clean_title($raw) {
    $s = (string) $raw;
    $s = str_replace('–', '-', $s);                         // en-dash -> trattino
    $s = preg_replace('/\b(PLS|AFT|PED)\b/iu', ' ', $s);    // sigle burocratiche
    $s = preg_replace('/\b[A-Z]{2}\s*\d+\b/u', ' ', $s);    // codici provincia tipo "GR 18"
    $s = preg_replace('/^[\s\d]+/u', '', $s);               // numeri/spazi iniziali
    $s = preg_replace('/\s*\/\s*/u', ' / ', $s);            // barre con spazi
    $s = preg_replace('/\s*,\s*/u', ', ', $s);              // virgole
    $s = trim($s, " \t-,/");                                // separatori orfani ai bordi
    $s = preg_replace('/\s{2,}/u', ' ', trim($s));          // spazi multipli (preserva i trattini interni)
    $s = tm_aft_title_case($s);
    $s = preg_replace_callback('/\b(dell|nell|sull|dall|coll|all|d|l)([\x27’])([a-zà-ù])/iu', function ($m) {
        return mb_strtolower($m[1], 'UTF-8') . $m[2] . mb_strtoupper($m[3], 'UTF-8');
    }, $s);
    return $s;
}

function tm_match_aft($aft_string) {
    $raw = trim(preg_replace('/\s+/', ' ', (string) $aft_string));
    if ($raw === '') return 0;

    $needle = tm_aft_norm($raw);
    $exact = [];
    $partial = [];
    foreach (get_posts(['post_type' => 'tm_aft', 'numberposts' => -1, 'post_status' => ['publish', 'draft']]) as $aft) {
        $names = array_unique(array_filter([
            tm_aft_norm((string) get_post_meta($aft->ID, 'tm_estar_aft_name', true)),
            tm_aft_norm($aft->post_title),
        ]));
        foreach ($names as $hay) {
            if ($hay === $needle) $exact[$aft->ID] = true;
            elseif (mb_strpos($hay, $needle) !== false || mb_strpos($needle, $hay) !== false) $partial[$aft->ID] = true;
        }
    }
    if (count($exact) === 1) return (int) array_key_first($exact);
    if (!$exact && count($partial) === 1) return (int) array_key_first($partial);
    return 0;
}

function tm_rest_estar_search($request) {
    $query = trim((string) $request->get_param('q'));
    if (mb_strlen($query) < 2) {
        return rest_ensure_response(['medici' => [], 'source' => 'none']);
    }
    if (tm_estar_dump_count() === 0) {
        return rest_ensure_response(['medici' => tm_attach_local_matches(tm_estar_live_search($query)), 'source' => 'live']);
    }
    return rest_ensure_response(['medici' => tm_attach_local_matches(tm_search_estar($query)), 'source' => 'dump']);
}

function tm_create_aft_from_estar($aft_string) {
    $raw = trim(preg_replace('/\s+/', ' ', (string) $aft_string));
    if ($raw === '') return new WP_Error('estar_aft_empty', 'Nome AFT vuoto nel dump ESTAR.', ['status' => 400]);
    $aft_id = wp_insert_post([
        'post_type'   => 'tm_aft',
        'post_status' => 'publish',
        'post_title'  => tm_aft_clean_title($raw) ?: $raw,
    ], true);
    if (is_wp_error($aft_id)) return $aft_id;
    update_post_meta($aft_id, 'tm_estar_aft_name', $raw);
    return (int) $aft_id;
}

function tm_rest_estar_import($request) {
    $params = $request->get_json_params();
    $estar_id = sanitize_text_field($params['estar_id'] ?? '');
    $create_aft = !empty($params['create_aft']);
    $medico = tm_find_in_dump($estar_id);
    if (!$medico) {
        return new WP_Error('estar_not_found', 'Medico non presente nel dump.', ['status' => 404]);
    }

    $first = tm_estar_person_name($medico['nome']);
    $last  = tm_estar_person_name($medico['cognome']);
    $local_id = tm_attach_local_matches([$medico])[0]['local_id'];

    if ($local_id) {
        $result = tm_sync_doctor_with_estar($local_id, $medico, false);
        if (!empty($result['error'])) return new WP_Error('estar_sync_failed', $result['error'], ['status' => 400]);
        return rest_ensure_response(['action' => 'updated', 'doctor' => tm_doctor_to_array(get_user_by('id', $local_id))]);
    }

    $qualification = tm_map_estar_tipologia($medico['tipologia']);
    $aft_id = tm_match_aft($medico['aft']);
    if ($qualification !== 'ca_temporaneo' && !tm_valid_doctor_aft($aft_id)) {
        if (!$create_aft) {
            return new WP_Error('estar_aft_unmatched', 'AFT ESTAR non presente nel backend.', [
                'status'    => 409,
                'aft_name'  => (string) $medico['aft'],
                'aft_title' => tm_aft_clean_title((string) $medico['aft']),
            ]);
        }
        $aft_id = tm_create_aft_from_estar($medico['aft']);
        if (is_wp_error($aft_id)) return $aft_id;
    }

    $user_id = tm_create_doctor_user('estar-' . sanitize_key($medico['codice_regionale']) . '@example.invalid', $first, $last);
    if (is_wp_error($user_id)) {
        return $user_id;
    }
    tm_save_doctor_meta($user_id, [
        'qualification' => $qualification,
        'patients'      => max(0, tm_doctor_max_patients($qualification) - (int) $medico['scelte_disponibili']),
        'home_aft'      => $aft_id,
        'phone'         => tm_estar_main_phone($medico),
    ]);
    update_user_meta($user_id, 'tm_estar_id', $medico['id']);
    update_user_meta($user_id, 'tm_codice_regionale', $medico['codice_regionale']);
    return rest_ensure_response(['action' => 'created', 'doctor' => tm_doctor_to_array(get_user_by('id', $user_id))]);
}

function tm_rest_estar_sync_imported() {
    if (tm_estar_dump_count() === 0) {
        return new WP_Error('estar_dump_empty', 'Dump ESTAR non disponibile. Aggiorna ESTAR prima di allineare.', ['status' => 400]);
    }

    $checked = 0;
    $matched_by_id = 0;
    $matched_by_name = 0;
    $updated = 0;
    $unchanged = 0;
    $missing = 0;
    $ambiguous = 0;
    $unmatched_afts = 0;
    $errors = [];

    $doctor_ids = function_exists('tm_turnazione_doctor_ids') ? tm_turnazione_doctor_ids() : get_users(['role' => 'tm_doctor', 'fields' => 'ID']);
    $doctors = array_filter(array_map('get_userdata', $doctor_ids));

    foreach ($doctors as $doctor) {
        $estar_id = (string) get_user_meta($doctor->ID, 'tm_estar_id', true);
        $checked++;

        $medico = $estar_id !== '' ? tm_find_in_dump($estar_id) : null;
        if ($medico) {
            $matched_by_id++;
        } else {
            $match = tm_find_in_dump_by_name($doctor->first_name, $doctor->last_name);
            if ($match['medico']) {
                $medico = $match['medico'];
                $matched_by_name++;
            } elseif ($match['count'] > 1) {
                $ambiguous++;
                continue;
            }
        }

        if (!$medico) {
            $missing++;
            continue;
        }

        $result = tm_sync_doctor_with_estar($doctor->ID, $medico);
        if (!empty($result['error'])) {
            $errors[] = $doctor->user_email . ': ' . $result['error'];
            continue;
        }
        if ($result['changed']) {
            $updated++;
        } else {
            $unchanged++;
        }
        if ($result['unmatched_aft']) {
            $unmatched_afts++;
        }
    }

    return rest_ensure_response([
        'checked'         => $checked,
        'matched_by_id'   => $matched_by_id,
        'matched_by_name' => $matched_by_name,
        'updated'         => $updated,
        'unchanged'       => $unchanged,
        'missing'         => $missing,
        'ambiguous'       => $ambiguous,
        'unmatched_afts'  => $unmatched_afts,
        'errors'          => $errors,
    ]);
}

function tm_rest_estar_refresh() {
    if (!tm_download_estar_dump()) {
        return new WP_Error('estar_dump_failed', 'Download del dump ESTAR fallito.', ['status' => 502]);
    }
    return rest_ensure_response(['updated' => time(), 'count' => tm_estar_dump_count()]);
}

function tm_rest_estar_upload($request) {
    $data = $request->get_json_params();
    $medici = (is_array($data) && isset($data['medici'])) ? $data['medici'] : (is_array($data) ? $data : null);
    if (!is_array($medici) || empty($medici)) {
        return new WP_Error('estar_bad_dump', 'Il file non sembra un dump ESTAR valido (manca l\'elenco "medici").', ['status' => 400]);
    }
    if (!tm_store_estar_dump($medici)) {
        return new WP_Error('estar_store_failed', 'Salvataggio del dump fallito.', ['status' => 500]);
    }
    return rest_ensure_response(['updated' => time(), 'count' => tm_estar_dump_count()]);
}

function tm_rest_estar_status() {
    $info = get_option('tm_estar_dump');
    return rest_ensure_response([
        'updated' => $info['updated'] ?? 0,
        'count'   => tm_estar_dump_count(),
    ]);
}

add_action('init', 'tm_schedule_estar_cron');
function tm_schedule_estar_cron() {
    if (tm_demo_mode()) return;
    if (!wp_next_scheduled('tm_estar_dump_cron')) {
        wp_schedule_event(time() + 3600, 'daily', 'tm_estar_dump_cron');
    }
}
add_action('tm_estar_dump_cron', 'tm_download_estar_dump');

add_action('rest_api_init', function () {
    $permission = fn() => current_user_can('edit_users');

    register_rest_route('turnimedici/v1', '/estar-search', [
        'methods'             => 'GET',
        'callback'            => 'tm_rest_estar_search',
        'permission_callback' => $permission,
        'args'                => ['q' => ['type' => 'string', 'required' => true]],
    ]);
    register_rest_route('turnimedici/v1', '/estar-refresh', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_estar_refresh',
        'permission_callback' => $permission,
    ]);
    register_rest_route('turnimedici/v1', '/estar-upload', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_estar_upload',
        'permission_callback' => $permission,
    ]);
    register_rest_route('turnimedici/v1', '/estar-import', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_estar_import',
        'permission_callback' => $permission,
    ]);
    register_rest_route('turnimedici/v1', '/estar-sync-imported', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_estar_sync_imported',
        'permission_callback' => $permission,
    ]);
    register_rest_route('turnimedici/v1', '/estar-status', [
        'methods'             => 'GET',
        'callback'            => 'tm_rest_estar_status',
        'permission_callback' => $permission,
    ]);
});
