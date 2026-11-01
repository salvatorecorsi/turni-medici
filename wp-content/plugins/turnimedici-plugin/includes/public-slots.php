<?php

if (!defined('ABSPATH')) exit;

/* Calendario pubblico read-only di una struttura: link con token per-struttura da
   condividere (es. gruppo WhatsApp dei CA). Stessa griglia della dashboard, ma senza
   interazioni e senza nomi: gli slot presi mostrano solo «Occupato». */

function tm_facility_slots_token($facility_id) {
    $token = (string) get_post_meta((int) $facility_id, 'tm_facility_slots_token', true);
    if ($token === '') {
        $token = wp_generate_password(24, false);
        update_post_meta((int) $facility_id, 'tm_facility_slots_token', $token);
    }
    return $token;
}

function tm_facility_slots_public_url($facility_id, $month_value = '') {
    $url = home_url('/?tm_slots=' . rawurlencode(tm_facility_slots_token($facility_id)));
    if ($month_value !== '') $url .= '&mese=' . rawurlencode($month_value);
    return $url;
}

function tm_facility_by_slots_token($token) {
    if (!is_string($token) || $token === '' || strlen($token) > 64) return 0;
    $ids = get_posts([
        'post_type'   => 'tm_facility',
        'numberposts' => 1,
        'post_status' => ['publish', 'draft'],
        'meta_key'    => 'tm_facility_slots_token',
        'meta_value'  => $token,
        'fields'      => 'ids',
    ]);
    return $ids ? (int) $ids[0] : 0;
}

function tm_public_slots_days($schedule_id, $month_value) {
    global $wpdb;
    $week = tm_board_calendar($schedule_id);
    if (!$week) return [];

    $taken = [];
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT shift_date, slot FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND doctor_id > 0',
        (int) $schedule_id
    ));
    foreach ($rows as $row) {
        $taken[$row->shift_date . '|' . $row->slot] = true;
    }

    preg_match('/^(\d{4})-(\d{2})$/', $month_value, $parts);
    $year = (int) $parts[1];
    $month = (int) $parts[2];
    $days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

    $days = [];
    for ($day = 1; $day <= $days_in_month; $day++) {
        $iso = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $effective = tm_board_effective_day($week, $iso);
        $slots = [];
        foreach ($effective['slots'] as $slot) {
            $key = tm_slot_key($slot);
            if ($key === '-') continue;
            $slots[] = ['key' => $key, 'label' => tm_slot_label($key), 'taken' => isset($taken[$iso . '|' . $key])];
        }
        $days[] = [
            'day'     => $day,
            'weekday' => TM_BOARD_WEEKDAY_SHORT[TM_BOARD_WEEKDAYS[(int) date('N', strtotime($iso)) - 1]],
            'open'    => !empty($effective['open']) && $slots,
            'note'    => (string) ($effective['note'] ?? ''),
            'slots'   => $slots,
        ];
    }
    return $days;
}

/* Stessa logica colonne della board React: le fasce base sono le colonne, le fasce
   spezzate finiscono nella colonna con cui condividono più minuti */
function tm_public_slots_columns($week, $days) {
    $base = [];
    foreach (tm_clean_slots(is_array($week) ? ($week['defaults']['slots'] ?? []) : []) as $slot) {
        $key = tm_slot_key($slot);
        if ($key !== '-') $base[] = $key;
    }
    $month_keys = [];
    foreach ($days as $day) {
        foreach ($day['slots'] as $slot) $month_keys[$slot['key']] = true;
    }
    $column_for = [];
    foreach (array_keys($month_keys) as $key) {
        if (in_array($key, $base, true)) {
            $column_for[$key] = $key;
            continue;
        }
        $best = $key;
        $best_shared = 0;
        $key_range = tm_slot_range_minutes($key);
        foreach ($base as $base_key) {
            $base_range = tm_slot_range_minutes($base_key);
            if (!$key_range || !$base_range) continue;
            $shared = max(0, min($key_range[1], $base_range[1]) - max($key_range[0], $base_range[0]));
            if ($shared > $best_shared) {
                $best = $base_key;
                $best_shared = $shared;
            }
        }
        $column_for[$key] = $best;
    }
    $columns = array_values(array_unique(array_values($column_for)));
    usort($columns, fn($a, $b) => tm_slot_range_minutes($a) <=> tm_slot_range_minutes($b));
    return [$columns, $column_for];
}

function tm_public_slots_short_range($key) {
    [$from, $to] = array_pad(explode('-', (string) $key), 2, '');
    $short = fn($time) => substr($time, -3) === ':00' ? ltrim(substr($time, 0, 2), '0') : $time;
    return $short($from) . '–' . $short($to);
}

add_action('init', function () {
    if (empty($_GET['tm_slots'])) return;
    $token = sanitize_text_field(wp_unslash($_GET['tm_slots']));
    $facility_id = tm_facility_by_slots_token($token);
    if (!$facility_id) { status_header(404); exit; }

    $requested = sanitize_text_field(wp_unslash($_GET['mese'] ?? ''));
    $month_value = (function_exists('tm_valid_month_value') && tm_valid_month_value($requested))
        ? $requested
        : tm_month_value(tm_current_month());
    $month_label = tm_month_label($month_value);
    $facility_name = get_the_title($facility_id);

    $schedule = tm_facility_schedule_for_month($facility_id, $month_value);
    $week = $schedule ? tm_board_calendar($schedule->ID) : null;
    $days = $schedule ? tm_public_slots_days($schedule->ID, $month_value) : [];
    [$columns, $column_for] = $schedule ? tm_public_slots_columns($week, $days) : [[], []];
    $updated_label = wp_date('j F Y, H:i');
    $style_path = TM_DIR . 'app/build/style-dashboard.css';
    $style_url = TM_URL . 'app/build/style-dashboard.css?ver=' . (file_exists($style_path) ? filemtime($style_path) : TM_VERSION);

    $cal_icon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
    $sun_icon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    $dusk_icon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 18a5 5 0 0 0-10 0"/><line x1="12" y1="2" x2="12" y2="9"/><line x1="4.2" y1="10.2" x2="5.6" y2="11.6"/><line x1="1" y1="18" x2="3" y2="18"/><line x1="21" y1="18" x2="23" y2="18"/><line x1="18.4" y1="11.6" x2="19.8" y2="10.2"/><line x1="23" y1="22" x2="1" y2="22"/><polyline points="8 6 12 10 16 6"/></svg>';
    $lock_icon = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Turni <?php echo esc_html($facility_name); ?> · <?php echo esc_html($month_label); ?></title>
<link rel="stylesheet" href="<?php echo esc_url($style_url); ?>">
<style>
    body { margin: 0; background: #f2f6fa; color: var(--tm-ink, #16293a); font-family: -apple-system, "Segoe UI", Roboto, sans-serif; }
    .tm-public { max-width: 1080px; margin: 0 auto; padding: 20px 14px 44px; }
    .tm-public__head { margin: 0 0 14px; }
    .tm-public__head h1 { font-size: 22px; margin: 0 0 2px; }
    .tm-public__stamp { margin: 0; color: var(--tm-muted, #5b6b7b); font-size: 13px; }
    .tm-public .tmv-grid__cell.is-free,
    .tm-public .tmv-grid__cell.is-free:hover {
        cursor: default;
        background: #e7f4ec;
        color: #226744;
        font-weight: 700;
    }
    .tm-public .tmv-grid__cell.is-free .tmv-dot { background: var(--tm-green, #2e8b57); box-shadow: none; }
    .tm-public__empty { background: #fff; border: 1px solid var(--tm-line, #e2ebf3); border-radius: 12px; padding: 26px 18px; text-align: center; color: var(--tm-muted, #5b6b7b); }
    .tm-public__foot { margin-top: 16px; text-align: center; color: #8896a6; font-size: 12px; }
</style>
</head>
<body>
<div class="tmv tm-public">
    <div class="tm-public__head">
        <h1><?php echo esc_html($facility_name); ?></h1>
        <p class="tm-public__stamp">Calendario di sola consultazione · situazione al <?php echo esc_html($updated_label); ?></p>
    </div>

    <?php if (!$schedule) : ?>
        <div class="tm-public__empty">L'orario di <?php echo esc_html($month_label); ?> non è ancora stato pubblicato.</div>
    <?php else : ?>
    <div class="tmv-card">
        <div class="tmv-calhead">
            <div class="tmv-calhead__left">
                <span class="tmv-calhead__month"><?php echo $cal_icon; ?> <?php echo esc_html($month_label); ?></span>
            </div>
            <div class="tmv-legend">
                <span><i class="tmv-dot tmv-dot--free"></i> Libero</span>
                <span><i class="tmv-dot tmv-dot--taken"></i> Occupato</span>
                <span><i class="tmv-dot tmv-dot--closed"></i> Chiuso</span>
            </div>
        </div>
        <div class="tmv-gridwrap">
            <div class="tmv-grid" style="grid-template-columns: minmax(96px, auto) repeat(<?php echo count($columns); ?>, minmax(150px, 1fr))">
                <div class="tmv-grid__corner">GIORNO</div>
                <?php foreach ($columns as $column) : ?>
                    <?php $range = tm_slot_range_minutes($column); ?>
                    <div class="tmv-grid__colhead"><?php echo ($range && $range[0] < 14 * 60) ? $sun_icon : $dusk_icon; ?></div>
                <?php endforeach; ?>

                <?php foreach ($days as $day) : ?>
                    <div class="tmv-dayrow">
                        <div class="tmv-grid__rowhead<?php echo $day['open'] ? '' : ' is-closed'; ?>">
                            <?php echo $cal_icon; ?>
                            <strong><?php echo (int) $day['day']; ?></strong>
                            <span><?php echo esc_html($day['weekday']); ?></span>
                            <span class="tmv-rowhead__full"><?php echo esc_html($day['weekday'] . ' ' . $day['day'] . ' ' . $month_label); ?></span>
                            <?php if ($day['open'] && $day['note'] !== '') : ?>
                                <span class="tmv-rowhead__note" title="<?php echo esc_attr($day['note']); ?>"><?php echo esc_html($day['note']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$day['open']) : ?>
                            <div class="tmv-grid__closed" style="grid-column: 2 / -1">
                                <?php echo $lock_icon; ?> Chiuso<?php echo $day['note'] !== '' ? '<span class="tmv-grid__closed-note">· ' . esc_html($day['note']) . '</span>' : ''; ?>
                            </div>
                        <?php else : ?>
                            <?php foreach ($columns as $column) : ?>
                                <?php
                                $cell_slots = array_values(array_filter($day['slots'], fn($slot) => ($column_for[$slot['key']] ?? $slot['key']) === $column));
                                $is_split = count($cell_slots) > 1;
                                ?>
                                <?php if (!$cell_slots) : ?>
                                    <div class="tmv-grid__cell is-na"></div>
                                <?php else : ?>
                                    <?php if ($is_split) : ?><div class="tmv-grid__cellgroup"><?php endif; ?>
                                    <?php foreach ($cell_slots as $slot) : ?>
                                        <?php $label = $is_split ? tm_public_slots_short_range($slot['key']) : $slot['label']; ?>
                                        <?php if ($slot['taken']) : ?>
                                            <div class="tmv-grid__cell is-taken">
                                                <span class="tmv-cell__name">Occupato</span>
                                                <span class="tmv-cell__range"><?php echo esc_html($label); ?></span>
                                            </div>
                                        <?php else : ?>
                                            <div class="tmv-grid__cell is-free"><i class="tmv-dot"></i> <?php echo esc_html($label); ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if ($is_split) : ?></div><?php endif; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <p class="tm-public__foot">Gli slot possono cambiare: fa fede la conferma del coordinatore.</p>
</div>
</body>
</html>
    <?php
    exit;
});

add_action('rest_api_init', function () {
    register_rest_route('turnimedici/v1', '/structures/(?P<id>\d+)/public-slots-link', [
        'methods'             => 'GET',
        'permission_callback' => function ($request) {
            if (!function_exists('tm_can_manage_scheduling') || !function_exists('tm_user_manages_facility')) {
                return current_user_can('edit_users');
            }
            return tm_can_manage_scheduling() && tm_user_manages_facility((int) $request['id']);
        },
        'callback'            => function ($request) {
            $facility_id = (int) $request['id'];
            $schedule_id = absint($request->get_param('schedule'));
            $month_value = '';
            if ($schedule_id && (int) get_post_meta($schedule_id, 'tm_facility_id', true) === $facility_id) {
                $month_value = tm_month_value(tm_schedule_month($schedule_id));
            }
            return rest_ensure_response(['url' => tm_facility_slots_public_url($facility_id, $month_value)]);
        },
    ]);
});
