<?php

if (!defined('ABSPATH')) exit;

const TM_FACILITY_MODES = [
    'ambulatorio' => 'Ambulatorio',
    'cdc'         => 'Casa della Comunità',
    'hub'         => 'Hub',
    'spoke'       => 'Spoke',
    'ca'          => 'Continuità assistenziale',
];

function tm_facility_modes($facility_id) {
    $modes = get_post_meta($facility_id, 'tm_facility_modes', true);
    return is_array($modes) ? array_values(array_intersect(array_keys(TM_FACILITY_MODES), $modes)) : [];
}

function tm_sanitize_facility_modes($value) {
    return array_values(array_intersect(array_keys(TM_FACILITY_MODES), array_map('sanitize_key', (array) $value)));
}

/* Senza modalità = turnazione (default legacy per le strutture pre-unificazione) */
function tm_facility_in_turnazione($facility_id) {
    $modes = tm_facility_modes($facility_id);
    return !$modes || (bool) array_intersect($modes, ['cdc', 'hub', 'spoke', 'ca']);
}

/* Graduatoria imposta dall'alto per la struttura (delibere per CdC): chi è in lista
   precede tutti nell'ordine dato, gli altri accodano con le regole globali. Vale solo
   se il blocco «Graduatoria struttura» è presente nel post: rimuoverlo la disattiva
   senza perdere la lista salvata nel meta */
function tm_facility_ranking_ids($facility_id) {
    $post = get_post((int) $facility_id);
    if (!$post || !has_block('turnimedici/facility-ranking', $post)) return [];
    $raw = get_post_meta((int) $facility_id, 'tm_facility_ranking', true);
    if (!is_array($raw)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
    return array_values(array_filter($ids, fn($id) => function_exists('tm_user_is_turnazione_doctor') ? tm_user_is_turnazione_doctor($id) : (bool) get_userdata($id)));
}

function tm_remove_doctors_from_facility_rankings($doctor_ids) {
    $doctor_ids = array_map('intval', (array) $doctor_ids);
    if (!$doctor_ids) return;
    $facility_ids = get_posts(['post_type' => 'tm_facility', 'numberposts' => -1, 'post_status' => ['publish', 'draft'], 'fields' => 'ids']);
    foreach ($facility_ids as $facility_id) {
        $raw = get_post_meta($facility_id, 'tm_facility_ranking', true);
        if (!is_array($raw) || !$raw) continue;
        $current = array_map('intval', $raw);
        $filtered = array_values(array_diff($current, $doctor_ids));
        if ($filtered !== $current) update_post_meta($facility_id, 'tm_facility_ranking', $filtered);
    }
}

function tm_register_facility() {
    register_post_type('tm_facility', [
        'labels' => [
            'name'               => 'Strutture',
            'singular_name'      => 'Struttura',
            'add_new'            => 'Aggiungi struttura',
            'add_new_item'       => 'Aggiungi struttura',
            'edit_item'          => 'Modifica struttura',
            'new_item'           => 'Nuova struttura',
            'view_item'          => 'Vedi struttura',
            'search_items'       => 'Cerca strutture',
            'not_found'          => 'Nessuna struttura',
            'not_found_in_trash' => 'Nessuna struttura nel cestino',
            'all_items'          => 'Strutture',
            'menu_name'          => 'Strutture',
        ],
        'public'       => true,
        'has_archive'  => true,
        'show_in_menu' => false,
        'show_in_rest' => true,
        'menu_icon'    => 'dashicons-building',
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
        'rewrite'      => ['slug' => 'struttura'],
        'template'     => [['turnimedici/facility-pin']],
    ]);
}

function tm_register_aft() {
    register_post_type('tm_aft', [
        'labels' => [
            'name'               => 'AFT',
            'singular_name'      => 'AFT',
            'add_new'            => 'Aggiungi AFT',
            'add_new_item'       => 'Aggiungi AFT',
            'edit_item'          => 'Modifica AFT',
            'new_item'           => 'Nuova AFT',
            'view_item'          => 'Vedi AFT',
            'search_items'       => 'Cerca AFT',
            'not_found'          => 'Nessuna AFT',
            'not_found_in_trash' => 'Nessuna AFT nel cestino',
            'all_items'          => 'AFT',
            'menu_name'          => 'AFT',
        ],
        'public'       => true,
        'has_archive'  => true,
        'show_in_rest' => true,
        'menu_icon'    => 'dashicons-networking',
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt'],
        'rewrite'      => ['slug' => 'aft'],
    ]);
}

function tm_register_schedule() {
    register_post_type('tm_schedule', [
        'labels' => [
            'name'          => 'Turni',
            'singular_name' => 'Turno',
            'add_new'       => 'Aggiungi turno',
            'add_new_item'  => 'Aggiungi turno',
            'edit_item'     => 'Modifica turno',
            'new_item'      => 'Nuovo turno',
            'search_items'  => 'Cerca turni',
            'not_found'     => 'Nessun turno',
            'all_items'     => 'Turni',
            'menu_name'     => 'Turni',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'show_in_rest' => true,
        'menu_icon'     => 'dashicons-clock',
        'supports'      => ['title', 'editor', 'revisions'],
        'template'      => [['turnimedici/schedule-admin']],
        'template_lock' => 'all',
        'has_archive'   => false,
        'rewrite'       => false,
        'capability_type' => ['tm_schedule', 'tm_schedules'],
        'map_meta_cap'    => true,
        'capabilities'    => ['create_posts' => 'do_not_allow'],
    ]);
}
