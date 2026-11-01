<?php

if (!defined('ABSPATH')) exit;

function tm_register_service() {
    register_taxonomy('tm_service', 'tm_schedule', [
        'labels' => [
            'name'          => 'Servizi',
            'singular_name' => 'Servizio',
            'add_new_item'  => 'Aggiungi servizio',
            'edit_item'     => 'Modifica servizio',
            'search_items'  => 'Cerca servizi',
            'all_items'     => 'Tutti i servizi',
            'menu_name'     => 'Servizi',
        ],
        'hierarchical'      => false,
        'public'            => false,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'show_admin_column' => true,
    ]);
}

