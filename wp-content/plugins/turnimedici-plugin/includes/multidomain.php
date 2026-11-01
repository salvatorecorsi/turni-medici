<?php

if (!defined('ABSPATH')) exit;

/**
 * Un solo host (turnimedici.example.invalid), due "facce" scelte dal login, non dall'hostname:
 *  - utente autorizzato (medico o manager) -> app turnazione ("turni")
 *  - chiunque altro (non loggato o senza ruolo) -> pagina di presentazione ("vetrina")
 * In locale si può forzare la faccia con TM_LOCAL_FACE ('turni'|'vetrina') in wp-config
 * (attivo solo con WP_DEBUG, così non ha effetto in produzione).
 */

function tm_is_turni_face() {
    if (defined('TM_LOCAL_FACE') && defined('WP_DEBUG') && WP_DEBUG) {
        return TM_LOCAL_FACE === 'turni';
    }
    if (!is_user_logged_in()) return false;
    if (function_exists('tm_current_doctor') && tm_current_doctor()) return true;
    if (function_exists('tm_coordinator_aft_id') && tm_coordinator_aft_id() > 0) return true;
    return current_user_can('edit_users');
}

function tm_turni_url($path = '') {
    return home_url('/' . ltrim((string) $path, '/'));
}

function tm_vetrina_url($path = '') {
    return home_url('/' . ltrim((string) $path, '/'));
}