<?php

if (!defined('ABSPATH')) exit;

/* Gli ambulatori sono strutture (tm_facility) con modalità "ambulatorio" (tm_facility_modes).
   Il vecchio CPT tm_ambulatorio è stato fuso in tm_facility (migrazione 5 lug 2026, ID conservati). */

/* Orari ambulatoriali: UNICA FONTE DI VERITÀ = user meta sul medico */
add_action('init', function () {
    register_meta('user', 'tm_ambulatory_schedule', [
        'type' => 'array', 'single' => true,
        'show_in_rest' => ['schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'amb' => ['type' => 'integer'], 'day' => ['type' => 'string'],
            'start' => ['type' => 'string'], 'end' => ['type' => 'string'], 'booking' => ['type' => 'string'],
        ]]]],
        'auth_callback' => fn() => current_user_can('edit_users'),
    ]);
});

/* Blocco scheda ambulatorio: solo mappa (osm-map). La sigla si edita nel pannello
   "Modalità struttura" (document-panels.jsx): due input sullo stesso meta = doppioni e sovrascritture. */
add_action('init', 'tm_register_ambulatorio_block', 25);
function tm_register_ambulatorio_block() {
    if (!function_exists('define_block_type')) return;
    define_block_type('turnimedici/ambulatorio', [
        'title'    => 'Scheda ambulatorio',
        'icon'     => 'location',
        'category' => 'design',
        'schema'   => [[
            'scope'  => 'content',
            'fields' => [
                'pin' => ['type' => 'osm-map', 'label' => 'Indirizzo e posizione', 'saveInMeta' => 'tm_facility_pin'],
            ],
        ]],
        'render' => 'tm_render_ambulatorio',
    ]);
}

function tm_render_ambulatorio($attributes, $content, $block) {
    $id      = get_the_ID();
    $pin     = get_post_meta($id, 'tm_facility_pin', true);
    $addr    = is_array($pin) ? ($pin['formatted_address'] ?? '') : '';
    $doctors = tm_ambulatorio_doctors($id);
    ob_start();
    ?>
    <div class="tm-amb-card">
        <h3><?php echo esc_html(get_the_title($id)); ?></h3>
        <?php if ($addr) : ?><p class="tm-amb-card__addr"><?php echo esc_html($addr); ?></p><?php endif; ?>
        <?php if (!empty($pin['lat'])) : ?><p><a href="<?php echo esc_url('https://www.openstreetmap.org/?mlat=' . $pin['lat'] . '&mlon=' . $pin['lng'] . '#map=17/' . $pin['lat'] . '/' . $pin['lng']); ?>" target="_blank" rel="noopener">Apri nella mappa</a></p><?php endif; ?>
        <?php if ($doctors) : ?><p class="tm-amb-card__docs"><strong>Medici:</strong> <?php echo esc_html(implode(', ', array_column($doctors, 'name'))); ?></p><?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function tm_ambulatorio_doctors($amb_id) {
    $amb_id = (int) $amb_id;
    $out = [];
    foreach (get_users(['role' => 'tm_doctor', 'fields' => ['ID', 'display_name']]) as $u) {
        $sched = get_user_meta($u->ID, 'tm_ambulatory_schedule', true);
        if (!is_array($sched)) continue;
        foreach ($sched as $s) {
            if ((int) ($s['amb'] ?? 0) === $amb_id) {
                $last = get_user_meta($u->ID, 'last_name', true);
                $first = get_user_meta($u->ID, 'first_name', true);
                $out[] = ['id' => (int) $u->ID, 'name' => trim($first . ' ' . $last) ?: $u->display_name];
                break;
            }
        }
    }
    return $out;
}
