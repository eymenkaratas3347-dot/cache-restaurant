<?php
/**
 * Plugin Name: KaceMaster Elite Reservations (V31 – Cacho e Cabra)
 * Description: Cartelera, fichas de eventos, reservas con plano y reglas de capacidad, pagos WooCommerce (Webpay / Mercado Pago), comprobante QR por correo y WhatsApp, recordatorios, recepción con lector QR, promociones, votaciones verificadas y panel privado fuera de wp-admin.
 * Version: 31.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Cacho e Cabra
 * Text Domain: kacemaster-elite
 */

if (!defined('ABSPATH')) exit;

define('KM_VERSION', '31.1.0');
define('KM_FILE', __FILE__);
define('KM_PATH', plugin_dir_path(__FILE__));
define('KM_URL', plugin_dir_url(__FILE__));

foreach (['util', 'install', 'floor', 'events', 'bookings', 'promos', 'woo', 'qr', 'notify', 'votes', 'rest', 'front', 'panel', 'admin'] as $km_part) {
    require_once KM_PATH . 'includes/class-km-' . $km_part . '.php';
}

add_filter('cron_schedules', function ($s) {
    $s['km_five_minutes'] = ['interval' => 300, 'display' => 'Cada 5 minutos (reservas)'];
    return $s;
});

register_activation_hook(__FILE__, function () {
    KM_Install::maybe_upgrade();
    KM_Front::rewrites();
    flush_rewrite_rules(false);
});
register_deactivation_hook(__FILE__, ['KM_Install', 'deactivate']);

add_action('plugins_loaded', function () {
    KM_Install::maybe_upgrade();
    KM_Notify::init();
    KM_Rest::init();
    KM_Front::init();
    KM_Panel::init();
    if (is_admin()) KM_Admin::init();
}, 20);

add_action('woocommerce_init', ['KM_Woo', 'init']);
add_action('init', ['KM_Install', 'schedule_cron'], 30);

add_action('km_cron_tick', function () {
    if (!KM_Util::lock('cron', 0)) return;
    try {
        KM_Bookings::expire_holds();
        KM_Events::cron_transitions();
        KM_Notify::cron_reminders();
        KM_Notify::cron_retries();
        update_option('km_cron_last', time(), false);
    } finally {
        KM_Util::unlock('cron');
    }
});
