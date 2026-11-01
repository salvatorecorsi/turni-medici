<?php

if (!defined('ABSPATH')) exit;

const TM_RANKING_RULES_VERSION = 4;

function tm_default_ranking() {
    return [
        'categories' => [
            ['id' => 'ruap_vinc_int', 'label' => 'RUAP vincolato (interni)',  'qualifications' => ['ruap_vincolato'],                  'scope' => 'interno'],
            ['id' => 'ruap_cors_int', 'label' => 'Corsista RUAP (interni)',    'qualifications' => ['ruap_corsista'],                   'scope' => 'interno'],
            ['id' => 'ruap_vol_int',  'label' => 'RUAP volontario (interni)',  'qualifications' => ['ruap_volontario'],                 'scope' => 'interno'],
            ['id' => 'ca_tit_int',    'label' => 'CA Titolare (interni)',      'qualifications' => ['ca_titolare'],                     'scope' => 'interno'],
            ['id' => 'mmg_int',       'label' => 'MMG (interni)',              'qualifications' => ['mmg'],                             'scope' => 'interno'],
            ['id' => 'ruap_ext',      'label' => 'RUAP (esterni)',             'qualifications' => ['ruap_vincolato', 'ruap_volontario', 'ruap_corsista'], 'scope' => 'esterno'],
            ['id' => 'ca_tit_ext',    'label' => 'CA Titolare (esterni)',      'qualifications' => ['ca_titolare'],                     'scope' => 'esterno'],
            ['id' => 'mmg_ext',       'label' => 'MMG (esterni)',              'qualifications' => ['mmg'],                             'scope' => 'esterno'],
            ['id' => 'ca_temp',       'label' => 'CA Temporaneo',              'qualifications' => ['ca_temporaneo'],                   'scope' => null],
        ],
        'tiebreakers'  => ['role_start', 'last_name', 'patients'],
        'ca_zones'     => ['Demo Nord', 'Demo Centro', 'Demo Sud'],
        'ca_order'     => 'rank',
        'queue_expiry' => ['enabled' => false, 'days' => 2],
    ];
}

function tm_migrate_ranking_rules() {
    $version = (int) get_option('tm_ranking_rules_version');
    if ($version >= TM_RANKING_RULES_VERSION) return;

    if ($version < 2) {
        $saved = get_option('tm_ranking_rules');
        if (is_array($saved) && !empty($saved['categories'])) {
            $labels = [];
            $extra = [];
            $known_ids = ['ruap_vinc_int', 'ruap_vol_int', 'ruap_cors_int', 'mmg_int', 'ruap_ext', 'mmg_ext', 'ca_tit', 'ca_tit_int', 'ca_tit_ext', 'ca_temp'];
            foreach ($saved['categories'] as $category) {
                $id = sanitize_key($category['id'] ?? '');
                if ($id) $labels[$id] = sanitize_text_field($category['label'] ?? '');
                if ($id && !in_array($id, $known_ids, true)) $extra[] = $category;
            }

            $defaults = tm_default_ranking();
            foreach ($defaults['categories'] as &$category) {
                if (!empty($labels[$category['id']])) $category['label'] = $labels[$category['id']];
            }
            unset($category);
            $saved['categories'] = array_merge($defaults['categories'], $extra);
            update_option('tm_ranking_rules', $saved, false);
        }
    }

    if ($version < 3) {
        tm_ranking_rules_insert_corsista();
    }

    if ($version < 4) {
        tm_ranking_rules_reposition_corsista();
    }

    update_option('tm_ranking_rules_version', TM_RANKING_RULES_VERSION, false);
}

/* I corsisti vanno dopo i vincolati e prima dei volontari (indicazione del coordinamento attività oraria) */
function tm_position_corsista_category($categories, $corsista) {
    $vol_index = null;
    $vinc_index = null;
    foreach ($categories as $index => $category) {
        if ($vol_index === null && ($category['id'] ?? '') === 'ruap_vol_int') $vol_index = $index;
        if (($category['id'] ?? '') === 'ruap_vinc_int') $vinc_index = $index;
    }
    if ($vol_index !== null) {
        array_splice($categories, $vol_index, 0, [$corsista]);
    } elseif ($vinc_index !== null) {
        array_splice($categories, $vinc_index + 1, 0, [$corsista]);
    } else {
        $categories[] = $corsista;
    }
    return array_values($categories);
}

/* Inserisce la categoria corsista nelle regole salvate senza toccare l'ordine scelto dall'admin */
function tm_ranking_rules_insert_corsista() {
    $saved = get_option('tm_ranking_rules');
    if (!is_array($saved) || empty($saved['categories'])) return;

    foreach ($saved['categories'] as $category) {
        if (in_array('ruap_corsista', (array) ($category['qualifications'] ?? []), true)) return;
    }

    $corsista = ['id' => 'ruap_cors_int', 'label' => 'Corsista RUAP (interni)', 'qualifications' => ['ruap_corsista'], 'scope' => 'interno'];
    $categories = tm_position_corsista_category($saved['categories'], $corsista);

    foreach ($categories as &$category) {
        if (($category['id'] ?? '') === 'ruap_ext' && !in_array('ruap_corsista', (array) $category['qualifications'], true)) {
            $category['qualifications'][] = 'ruap_corsista';
        }
    }
    unset($category);

    $saved['categories'] = $categories;
    update_option('tm_ranking_rules', $saved, false);
}

/* La prima versione della migrazione metteva il corsista dopo i volontari: lo riporta tra vincolati e volontari */
function tm_ranking_rules_reposition_corsista() {
    $saved = get_option('tm_ranking_rules');
    if (!is_array($saved) || empty($saved['categories'])) return;

    $corsista = null;
    $others = [];
    foreach ($saved['categories'] as $category) {
        if (($category['id'] ?? '') === 'ruap_cors_int') {
            $corsista = $category;
            continue;
        }
        $others[] = $category;
    }
    if (!$corsista) return;

    $saved['categories'] = tm_position_corsista_category($others, $corsista);
    update_option('tm_ranking_rules', $saved, false);
}
add_action('init', 'tm_migrate_ranking_rules', 5);

function tm_get_ranking() {
    tm_migrate_ranking_rules();
    $saved = get_option('tm_ranking_rules');
    $ranking = (is_array($saved) && !empty($saved['categories'])) ? $saved : tm_default_ranking();
    if (empty($ranking['ca_zones']) || !is_array($ranking['ca_zones'])) {
        $ranking['ca_zones'] = tm_default_ranking()['ca_zones'];
    }
    if (!is_array($ranking['queue_expiry'] ?? null)) {
        $ranking['queue_expiry'] = tm_default_ranking()['queue_expiry'];
    }
    if (!in_array($ranking['ca_order'] ?? '', ['rank', 'zone'], true)) {
        $ranking['ca_order'] = 'rank';
    }
    return $ranking;
}

function tm_doctor_ranking_category($doctor_id, $aft_id = 0, $ranking = null) {
    $ranking = $ranking ?: tm_get_ranking();
    $qualification = get_user_meta((int) $doctor_id, 'tm_qualification', true);
    if ($aft_id && function_exists('tm_doctor_rank')) {
        $index = tm_doctor_rank((int) $doctor_id, (int) $aft_id, $ranking);
        return $ranking['categories'][$index]['label'] ?? 'Non in graduatoria';
    }
    foreach ($ranking['categories'] as $index => $category) {
        if (in_array($qualification, (array) $category['qualifications'], true)) {
            return $ranking['categories'][$index]['label'] ?? tm_qualification_label($qualification);
        }
    }
    return 'Non in graduatoria';
}

add_action('admin_menu', 'tm_ranking_menu');
function tm_ranking_menu() {
    add_submenu_page('users.php', 'Graduatoria', 'Graduatoria', 'edit_users', 'tm-ranking', 'tm_ranking_page');
}

function tm_ranking_page() {
    echo '<div class="wrap"><h1>Graduatoria</h1><div id="tm-ranking-app"></div></div>';
}

add_action('admin_enqueue_scripts', 'tm_ranking_enqueue');
function tm_ranking_enqueue($hook) {
    if ($hook !== 'users_page_tm-ranking') return;

    $asset_path = TM_DIR . 'app/build/ranking.asset.php';
    if (!file_exists($asset_path)) return;

    $asset = include $asset_path;
    wp_enqueue_script('tm-ranking', TM_URL . 'app/build/ranking.js', $asset['dependencies'], $asset['version'], true);
    wp_enqueue_style('wp-components');

    $css = TM_DIR . 'assets/ranking.css';
    if (file_exists($css)) {
        wp_enqueue_style('tm-ranking', TM_URL . 'assets/ranking.css', ['wp-components'], filemtime($css));
    }
    wp_localize_script('tm-ranking', 'tmRanking', [
        'restUrl' => rest_url('turnimedici/v1/'),
        'nonce'   => wp_create_nonce('wp_rest'),
    ]);
}

function tm_current_month() {
    return ['year' => (int) current_time('Y'), 'month' => (int) current_time('n')];
}

function tm_month_value($month) {
    return sprintf('%04d-%02d', (int) $month['year'], (int) $month['month']);
}

function tm_month_period($month) {
    $start = sprintf('%04d-%02d-01', (int) $month['year'], (int) $month['month']);
    return ['start' => $start, 'end' => date('Y-m-t', strtotime($start))];
}

function tm_requested_ranking_month($schedule_id) {
    if ($schedule_id && get_post_type($schedule_id) === 'tm_schedule') {
        return tm_schedule_month($schedule_id);
    }
    return tm_current_month();
}

/* Graduatoria completa calcolata server-side: categoria per qualifica, poi
   spareggio unico tm_rank_tiebreak (graduatoria CA da XLS prima dei criteri).
   Con $month i CA Temporanei sono filtrati sui contratti che intersecano il mese. */
function tm_global_ranking_list($aft_id = 0, $hide_without_aft = false, $month = null) {
    $ranking = tm_get_ranking();
    $category_of = function ($doctor_id, $qualification) use ($ranking, $aft_id) {
        if ($aft_id && function_exists('tm_doctor_rank')) {
            return tm_doctor_rank($doctor_id, $aft_id, $ranking);
        }
        foreach ($ranking['categories'] as $index => $category) {
            if (in_array($qualification, (array) $category['qualifications'], true)) return $index;
        }
        return 999;
    };
    $user_ids = function_exists('tm_turnazione_doctor_ids') ? tm_turnazione_doctor_ids() : get_users(['role' => 'tm_doctor', 'fields' => 'ID']);
    $users = array_values(array_filter(array_map('get_userdata', $user_ids)));
    $period = $month ? tm_month_period($month) : null;
    if ($hide_without_aft) {
        $users = array_values(array_filter($users, fn($user) => tm_doctor_is_visible_in_frontend($user->ID, $period ?: '')));
    } elseif ($period) {
        $users = array_values(array_filter($users, fn($user) => tm_doctor_is_active_for_turnazione($user->ID, $period)));
    }
    $quals = [];
    foreach ($users as $user) $quals[$user->ID] = get_user_meta($user->ID, 'tm_qualification', true);
    $ids = array_map(fn($user) => (int) $user->ID, $users);
    usort($ids, function ($a, $b) use ($category_of, $quals, $ranking) {
        $diff = $category_of($a, $quals[$a]) <=> $category_of($b, $quals[$b]);
        if ($diff !== 0) return $diff;
        return tm_rank_tiebreak($a, $b, $ranking['tiebreakers'], $ranking);
    });
    $by_id = [];
    foreach ($users as $user) $by_id[$user->ID] = $user;
    $out = [];
    $position = 1;
    $share_all = current_user_can('edit_users');
    $share_aft = (!$share_all && function_exists('tm_coordinator_aft_id')) ? tm_coordinator_aft_id() : 0;
    foreach ($ids as $id) {
        $user = $by_id[$id];
        $index = $category_of($id, $quals[$id]);
        $home_aft = function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($id) : (int) get_user_meta($id, 'tm_home_aft', true);
        $aft = $home_aft ? get_post($home_aft) : null;
        $aft_name = $aft ? $aft->post_title : '';
        $ca_zone = get_user_meta($id, 'tm_ca_zone', true);
        $is_ca_temporaneo = function_exists('tm_doctor_is_ca_temporaneo') && tm_doctor_is_ca_temporaneo($id);
        $scope = $aft_id && function_exists('tm_doctor_scope') ? tm_doctor_scope($id, $aft_id) : null;
        $out[] = [
            'position'      => $position++,
            'id'            => $id,
            'name'          => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
            'qualification' => $quals[$id],
            'category'      => $ranking['categories'][$index]['label'] ?? 'Non in graduatoria',
            'scope'         => $scope,
            'aft_id'        => $home_aft,
            'aft_name'      => $aft_name,
            'loc'           => $is_ca_temporaneo ? $ca_zone : $aft_name,
            'ca_rank'       => tm_ca_rank_value($id),
            'ca_zone'       => $ca_zone,
            'ca_cot'        => (bool) get_user_meta($id, 'tm_ca_cot', true),
            'email'         => $user->user_email,
            'phone'         => get_user_meta($id, 'tm_phone', true),
            'can_share_link' => $share_all || ($share_aft > 0 && ($is_ca_temporaneo || $home_aft === $share_aft)),
        ];
    }
    return $out;
}

function tm_rest_ranking_list($request) {
    $aft_id = absint($request->get_param('aft'));
    $facility_id = absint($request->get_param('facility'));
    if (!$aft_id && $facility_id && get_post_type($facility_id) === 'tm_facility') {
        $aft_id = (int) get_post_meta($facility_id, 'tm_aft_id', true);
    }
    if (!$aft_id && function_exists('tm_coordinator_aft_id')) {
        $aft_id = tm_coordinator_aft_id();
    }
    if (!$aft_id && function_exists('tm_current_doctor')) {
        $doctor = tm_current_doctor();
        $aft_id = $doctor ? (function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($doctor->ID) : (int) get_user_meta($doctor->ID, 'tm_home_aft', true)) : 0;
    }
    $month = tm_requested_ranking_month(absint($request->get_param('schedule')));
    $list = tm_global_ranking_list($aft_id, true, $month);
    if (!function_exists('tm_can_manage_scheduling') || !tm_can_manage_scheduling()) {
        $list = array_map(function ($row) {
            unset($row['email'], $row['phone']);
            return $row;
        }, $list);
    }
    return rest_ensure_response([
        'month'       => $month,
        'month_label' => tm_month_label(tm_month_value($month)),
        'list'        => $list,
    ]);
}

/* Segnalibro personale «sono arrivato qui» della graduatoria: per utente, sincronizzato
   tra dispositivi; la chiave identifica la graduatoria vista (aft/struttura/mese) */
function tm_rest_get_ranking_bookmark($request) {
    $key = sanitize_text_field((string) $request->get_param('key'));
    if ($key === '' || strlen($key) > 120) {
        return new WP_Error('tm_bad_bookmark_key', 'Chiave segnalibro non valida.', ['status' => 400]);
    }
    $bookmarks = get_user_meta(get_current_user_id(), 'tm_ranking_bookmarks', true);
    $entry = is_array($bookmarks) ? ($bookmarks[$key] ?? null) : null;
    return rest_ensure_response(['doctor_id' => (int) ($entry['id'] ?? 0)]);
}

function tm_rest_save_ranking_bookmark($request) {
    $params = (array) $request->get_json_params();
    $key = sanitize_text_field((string) ($params['key'] ?? ''));
    $doctor_id = absint($params['doctor_id'] ?? 0);
    if ($key === '' || strlen($key) > 120) {
        return new WP_Error('tm_bad_bookmark_key', 'Chiave segnalibro non valida.', ['status' => 400]);
    }
    $user_id = get_current_user_id();
    $bookmarks = get_user_meta($user_id, 'tm_ranking_bookmarks', true);
    if (!is_array($bookmarks)) $bookmarks = [];
    if ($doctor_id) {
        $bookmarks[$key] = ['id' => $doctor_id, 't' => time()];
    } else {
        unset($bookmarks[$key]);
    }
    if (count($bookmarks) > 50) {
        uasort($bookmarks, fn($a, $b) => (int) ($a['t'] ?? 0) <=> (int) ($b['t'] ?? 0));
        $bookmarks = array_slice($bookmarks, -50, null, true);
    }
    update_user_meta($user_id, 'tm_ranking_bookmarks', $bookmarks);
    return rest_ensure_response(['doctor_id' => $doctor_id]);
}

function tm_ranking_public_url() {
    $token = get_option('tm_ranking_public_token');
    if (!$token) {
        $token = wp_generate_password(24, false);
        update_option('tm_ranking_public_token', $token, false);
    }
    return home_url('/?tm_graduatoria=' . $token);
}

function tm_public_ranking_month_options($selected_value) {
    $current = tm_current_month();
    $base = mktime(12, 0, 0, $current['month'] - 1, 1, $current['year']);
    $options = [];
    for ($offset = 0; $offset < 5; $offset++) {
        $value = date('Y-m', strtotime("+$offset months", $base));
        $options[$value] = tm_month_label($value);
    }
    if (!isset($options[$selected_value])) {
        $options[$selected_value] = tm_month_label($selected_value);
        ksort($options);
    }
    return $options;
}

/* Graduatoria pubblica tokenizzata: posizione, nome e numero aziendale — nessun dato personale */
add_action('init', function () {
    if (empty($_GET['tm_graduatoria'])) return;
    $token = sanitize_text_field(wp_unslash($_GET['tm_graduatoria']));
    if (!hash_equals((string) get_option('tm_ranking_public_token'), $token)) { status_header(404); exit; }

    $requested = sanitize_text_field(wp_unslash($_GET['mese'] ?? ''));
    if (tm_valid_month_value($requested) && preg_match('/^(\d{4})-(\d{2})$/', $requested, $m)) {
        $month = ['year' => (int) $m[1], 'month' => (int) $m[2]];
    } else {
        $month = tm_current_month();
    }
    $month_value = tm_month_value($month);
    $month_label = tm_month_label($month_value);
    $month_options = tm_public_ranking_month_options($month_value);

    $list = tm_global_ranking_list(0, false, $month);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Graduatoria <?php echo esc_html($month_label); ?> — <?php echo esc_html(get_bloginfo('name')); ?></title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #f6f7f8; font-family: ui-sans-serif, system-ui, "Segoe UI", Roboto, Arial, sans-serif; color: #1f2328; }
  .sheet { max-width: 780px; margin: 0 auto; padding: 28px 20px 48px; }
  h1 { font-size: 22px; font-weight: 800; text-transform: uppercase; letter-spacing: -0.01em; margin: 0; }
  .subtitle { color: #6b7280; font-size: 13px; margin: 4px 0 20px; }
  .monthpick { margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }
  .monthpick label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; font-weight: 700; }
  .monthpick select { font: inherit; font-size: 14px; padding: 6px 10px; border: 1px solid #d9dde2; border-radius: 6px; background: #fff; color: inherit; }
  table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #d9dde2; }
  th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #e8ebee; font-size: 14px; }
  th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; background: #fafbfc; }
  td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; width: 70px; }
  tr:last-child td { border-bottom: 0; }
  .cat { color: #6b7280; font-size: 12px; }
  @media print { body { background: #fff; } .sheet { padding: 0; } .monthpick { display: none; } }
</style>
</head>
<body>
<div class="sheet">
  <h1>Graduatoria di chiamata · mese di <?php echo esc_html($month_label); ?></h1>
  <div class="subtitle"><?php echo esc_html(get_bloginfo('name')); ?> · aggiornata al <?php echo esc_html(wp_date('d/m/Y')); ?></div>
  <form class="monthpick" method="get" action="<?php echo esc_url(home_url('/')); ?>">
    <input type="hidden" name="tm_graduatoria" value="<?php echo esc_attr($token); ?>">
    <label for="tm-mese">Mese</label>
    <select id="tm-mese" name="mese" onchange="this.form.submit()">
      <?php foreach ($month_options as $value => $label) : ?>
      <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $month_value); ?>><?php echo esc_html($label); ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <table>
    <thead>
      <tr><th class="num">Pos.</th><th>Medico</th><th>Categoria</th><th class="num">N. graduatoria aziendale</th></tr>
    </thead>
    <tbody>
      <?php foreach ($list as $row) : ?>
      <tr>
        <td class="num"><?php echo (int) $row['position']; ?></td>
        <td><?php echo esc_html($row['name']); ?></td>
        <td class="cat"><?php echo esc_html($row['category']); ?></td>
        <td class="num"><?php echo $row['ca_rank'] !== null ? (int) $row['ca_rank'] : '—'; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
    <?php
    exit;
}, 0);

add_action('rest_api_init', 'tm_register_ranking_routes');
function tm_register_ranking_routes() {
    $read_permission = fn() => function_exists('tm_current_user_can_manage_doctors') ? tm_current_user_can_manage_doctors() : current_user_can('edit_users');
    $write_permission = fn() => current_user_can('edit_users');
    register_rest_route('turnimedici/v1', '/ranking', [
        ['methods' => 'GET', 'callback' => 'tm_rest_get_ranking', 'permission_callback' => $read_permission],
        ['methods' => 'POST', 'callback' => 'tm_rest_save_ranking', 'permission_callback' => $write_permission],
    ]);
    register_rest_route('turnimedici/v1', '/ranking/list', [
        ['methods' => 'GET', 'callback' => 'tm_rest_ranking_list', 'permission_callback' => fn() => tm_can_access_turni()],
    ]);
    register_rest_route('turnimedici/v1', '/ranking/bookmark', [
        ['methods' => 'GET', 'callback' => 'tm_rest_get_ranking_bookmark', 'permission_callback' => fn() => tm_can_access_turni()],
        ['methods' => 'POST', 'callback' => 'tm_rest_save_ranking_bookmark', 'permission_callback' => fn() => tm_can_access_turni()],
    ]);
}

function tm_qualification_counts() {
    $counts = [];
    $user_ids = function_exists('tm_turnazione_doctor_ids') ? tm_turnazione_doctor_ids() : get_users(['role' => 'tm_doctor', 'fields' => 'ID']);
    foreach ($user_ids as $id) {
        $qualification = get_user_meta($id, 'tm_qualification', true);
        if ($qualification) {
            $counts[$qualification] = ($counts[$qualification] ?? 0) + 1;
        }
    }
    return $counts;
}

function tm_ca_zone_counts($ranking = null) {
    $ranking = $ranking ?: tm_get_ranking();
    $zone_labels = [];
    foreach ((array) ($ranking['ca_zones'] ?? []) as $zone) {
        $zone_labels[tm_normalize_ca_zone($zone)] = $zone;
    }

    $counts = [];
    $user_ids = function_exists('tm_turnazione_doctor_ids') ? tm_turnazione_doctor_ids() : get_users(['role' => 'tm_doctor', 'fields' => 'ID']);
    foreach ($user_ids as $id) {
        $qualification = get_user_meta($id, 'tm_qualification', true);
        if ($qualification !== 'ca_temporaneo') continue;

        $zone = trim((string) get_user_meta($id, 'tm_ca_zone', true));
        if ($zone) {
            $key = $zone_labels[tm_normalize_ca_zone($zone)] ?? $zone;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
    }
    return $counts;
}

function tm_rest_get_ranking() {
    global $wpdb;
    $ranking = tm_get_ranking();
    $ranking['counts'] = tm_qualification_counts();
    $known = array_map('tm_normalize_ca_zone', $ranking['ca_zones']);
    foreach ($wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'tm_ca_zone' AND meta_value <> ''") as $zone) {
        $normalized_zone = tm_normalize_ca_zone($zone);
        if (!in_array($normalized_zone, $known, true)) {
            $ranking['ca_zones'][] = $zone;
            $known[] = $normalized_zone;
        }
    }
    $ranking['ca_zone_counts'] = tm_ca_zone_counts($ranking);
    $ranking['public_url'] = tm_ranking_public_url();
    return rest_ensure_response($ranking);
}

function tm_rest_save_ranking($request) {
    $data = $request->get_json_params();
    $allowed_tiebreakers = ['role_start', 'last_name', 'patients'];

    $categories = [];
    foreach ($data['categories'] ?? [] as $category) {
        $scope = $category['scope'] ?? null;
        $categories[] = [
            'id'             => sanitize_key($category['id'] ?? ''),
            'label'          => sanitize_text_field($category['label'] ?? ''),
            'qualifications' => array_map('sanitize_key', (array) ($category['qualifications'] ?? [])),
            'scope'          => in_array($scope, ['interno', 'esterno'], true) ? $scope : null,
        ];
    }

    $tiebreakers = array_values(array_filter(
        array_map('sanitize_key', (array) ($data['tiebreakers'] ?? [])),
        fn($t) => in_array($t, $allowed_tiebreakers, true)
    ));

    $ca_zones = [];
    $seen_zones = [];
    foreach ((array) ($data['ca_zones'] ?? []) as $zone) {
        $zone = sanitize_text_field($zone);
        $normalized_zone = tm_normalize_ca_zone($zone);
        if (!$zone || isset($seen_zones[$normalized_zone])) continue;

        $ca_zones[] = $zone;
        $seen_zones[$normalized_zone] = true;
    }
    if (!$ca_zones) $ca_zones = tm_default_ranking()['ca_zones'];

    $expiry_raw = (array) ($data['queue_expiry'] ?? []);
    $queue_expiry = [
        'enabled' => !empty($expiry_raw['enabled']),
        'days'    => max(1, (int) ($expiry_raw['days'] ?? 2)),
    ];

    $ca_order = in_array($data['ca_order'] ?? '', ['rank', 'zone'], true) ? $data['ca_order'] : 'rank';

    update_option('tm_ranking_rules', ['categories' => $categories, 'tiebreakers' => $tiebreakers, 'ca_zones' => $ca_zones, 'ca_order' => $ca_order, 'queue_expiry' => $queue_expiry]);

    $ranking = tm_get_ranking();
    $ranking['counts'] = tm_qualification_counts();
    $ranking['ca_zone_counts'] = tm_ca_zone_counts($ranking);
    return rest_ensure_response($ranking);
}
