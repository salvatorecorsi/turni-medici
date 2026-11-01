<?php

if (!defined('ABSPATH')) exit;

function tm_register_facility_meta() {
    register_post_meta('tm_facility', 'tm_aft_id', [
        'type'              => 'integer',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'absint',
        'auth_callback'     => fn() => current_user_can('edit_posts'),
    ]);

    register_post_meta('tm_facility', 'tm_facility_short', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback'     => fn() => current_user_can('edit_posts'),
    ]);

    register_post_meta('tm_facility', 'tm_facility_modes', [
        'type'              => 'array',
        'single'            => true,
        'show_in_rest'      => [
            'schema' => [
                'type'  => 'array',
                'items' => ['type' => 'string', 'enum' => array_keys(TM_FACILITY_MODES)],
            ],
        ],
        'sanitize_callback' => 'tm_sanitize_facility_modes',
        'auth_callback'     => fn() => current_user_can('edit_posts'),
    ]);

    register_post_meta('tm_facility', 'tm_facility_pin', [
        'type'          => 'object',
        'single'        => true,
        'show_in_rest'  => [
            'schema' => [
                'type'       => 'object',
                'properties' => [
                    'lat'               => ['type' => 'number'],
                    'lng'               => ['type' => 'number'],
                    'formatted_address' => ['type' => 'string'],
                    'query'             => ['type' => 'string'],
                ],
            ],
        ],
        'auth_callback' => fn() => current_user_can('edit_posts'),
    ]);

    register_post_meta('tm_facility', 'tm_facility_ranking', [
        'type'              => 'array',
        'single'            => true,
        'default'           => [],
        'show_in_rest'      => [
            'schema' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ],
        'sanitize_callback' => fn($value) => array_values(array_unique(array_filter(array_map('absint', (array) $value)))),
        'auth_callback'     => fn() => current_user_can('edit_users'),
    ]);
}

function tm_register_schedule_meta() {
    $scheduling_auth = fn($allowed, $meta_key, $object_id) => tm_can_manage_scheduling() && tm_user_manages_schedule($object_id);

    register_post_meta('tm_schedule', 'tm_schedule_closed', [
        'type'          => 'boolean',
        'single'        => true,
        'default'       => false,
        'show_in_rest'  => false,
        'auth_callback' => $scheduling_auth,
    ]);

    register_post_meta('tm_schedule', 'tm_facility_id', [
        'type'              => 'integer',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => 'absint',
        'auth_callback'     => $scheduling_auth,
    ]);

    register_post_meta('tm_schedule', 'tm_schedule_month', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback'     => $scheduling_auth,
    ]);

    register_post_meta('tm_schedule', 'tm_schedule_week', [
        'type'          => 'object',
        'single'        => true,
        'show_in_rest'  => false,
        'auth_callback' => $scheduling_auth,
    ]);
}

function tm_register_aft_meta() {
    register_post_meta('tm_aft', 'tm_estar_aft_name', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback'     => fn() => current_user_can('edit_posts'),
    ]);

    foreach (['tm_aft_area', 'tm_aft_manager', 'tm_aft_phone'] as $key) {
        register_post_meta('tm_aft', $key, [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => fn() => current_user_can('edit_posts'),
        ]);
    }

    register_post_meta('tm_aft', 'tm_aft_email', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_email',
        'auth_callback'     => fn() => current_user_can('edit_posts'),
    ]);
}
