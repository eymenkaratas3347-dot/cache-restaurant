<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared helpers: time zone, settings, money, phones, permissions and table names.
 */
class KM_Util {

    const TZ = 'America/Santiago';

    private static $settings_cache = null;
    private static $site_cache = null;

    public static function tz() {
        static $tz = null;
        if (!$tz) $tz = new DateTimeZone(self::TZ);
        return $tz;
    }

    /** Current time in Chile. */
    public static function now() {
        return new DateTimeImmutable('now', self::tz());
    }

    public static function today() {
        return self::now()->format('Y-m-d');
    }

    /** Builds a Chile-local datetime from "Y-m-d" + "H:i[:s]". */
    public static function local_dt($date, $time = '00:00') {
        $time = substr((string) $time, 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '00:00';
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time, self::tz());
        return $dt ?: null;
    }

    public static function utc_mysql($dt = null) {
        $dt = $dt ?: new DateTimeImmutable('now');
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function from_utc_mysql($s) {
        if (!$s || $s === '0000-00-00 00:00:00') return null;
        try {
            return (new DateTimeImmutable($s, new DateTimeZone('UTC')))->setTimezone(self::tz());
        } catch (Exception $e) {
            return null;
        }
    }

    public static function valid_date($d) {
        if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        $p = explode('-', $d);
        return checkdate((int) $p[1], (int) $p[2], (int) $p[0]);
    }

    public static function valid_time($t) {
        return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', substr($t, 0, 5));
    }

    /** Accepts Y-m-d or d/m/Y. */
    public static function parse_date($d) {
        $d = trim((string) $d);
        if (self::valid_date($d)) return $d;
        $dt = DateTime::createFromFormat('d/m/Y', $d);
        return $dt ? $dt->format('Y-m-d') : '';
    }

    /** "VIE 23 OCT" (or "23 OCT" without weekday) for posters and cards. */
    public static function date_short($date, $with_weekday = true) {
        $dt = self::local_dt($date, '12:00');
        if (!$dt) return $date;
        $days = ['DOM', 'LUN', 'MAR', 'MIÉ', 'JUE', 'VIE', 'SÁB'];
        $months = ['', 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
        $s = (int) $dt->format('j') . ' ' . $months[(int) $dt->format('n')];
        return $with_weekday ? $days[(int) $dt->format('w')] . ' ' . $s : $s;
    }

    public static function date_human($date, $with_weekday = true) {
        $dt = self::local_dt($date, '12:00');
        if (!$dt) return $date;
        $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $s = (int) $dt->format('j') . ' de ' . $months[(int) $dt->format('n')];
        if ($dt->format('Y') !== self::now()->format('Y')) $s .= ' de ' . $dt->format('Y');
        return $with_weekday ? ucfirst($days[(int) $dt->format('w')]) . ' ' . $s : $s;
    }

    public static function money($n) {
        return '$' . number_format((float) $n, 0, ',', '.');
    }

    public static function status_label($status) {
        $l = ['pending_payment' => 'Pago en curso', 'confirmed' => 'Confirmada', 'conflict' => 'Conflicto', 'cancelled' => 'Cancelada', 'expired' => 'No pagada (vencida)'];
        return $l[$status] ?? $status;
    }

    public static function payment_label($status) {
        $l = ['pending' => 'Pendiente', 'paid' => 'Pagado', 'failed' => 'Rechazado', 'abandoned' => 'Abandonado', 'cancelled' => 'Cancelado',
            'refunded' => 'Devuelto', 'partial_refund' => 'Devolución parcial', 'onsite' => 'Paga en local', 'courtesy' => 'Cortesía', 'none' => 'Sin costo', 'manual' => 'Pagado (manual)'];
        return $l[$status] ?? $status;
    }

    public static function to_int_amount($n) {
        return (int) round((float) $n);
    }

    /**
     * Normalizes a phone to digits with country code (E.164 without "+").
     * Chilean mobiles typed as "9 1234 5678" become "56912345678".
     */
    public static function normalize_phone($phone, $country = '+56') {
        $phone = trim((string) $phone);
        $country = preg_replace('/\D/', '', (string) $country) ?: '56';
        if ($phone === '') return '';
        $plus = strpos($phone, '+') === 0;
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '') return '';
        if ($plus) return $digits;
        if (strpos($digits, '00') === 0) return substr($digits, 2);
        if (strpos($digits, $country) === 0 && strlen($digits) > 9) return $digits;
        $digits = ltrim($digits, '0');
        return $country . $digits;
    }

    public static function valid_phone($digits) {
        return (bool) preg_match('/^\d{10,15}$/', (string) $digits);
    }

    public static function phone_display($digits) {
        $digits = preg_replace('/\D/', '', (string) $digits);
        if (strpos($digits, '56') === 0 && strlen($digits) === 11) {
            return '+56 ' . substr($digits, 2, 1) . ' ' . substr($digits, 3, 4) . ' ' . substr($digits, 7);
        }
        return $digits ? '+' . $digits : '';
    }

    /** Keyed hash so contact data used for de-duplication is not stored in clear for the index. */
    public static function contact_hash($value) {
        return hash_hmac('sha256', strtolower(trim((string) $value)), wp_salt('auth'));
    }

    public static function random_token($bytes = 16) {
        return bin2hex(random_bytes($bytes));
    }

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'km_' . $name;
    }

    // ------------------------------------------------------------------
    // Settings (operational) and site content (public texts / branding)
    // ------------------------------------------------------------------

    public static function setting_defaults() {
        return [
            'product_id'          => (int) get_option('km_custom_product_id', 0),
            'price_default'       => (int) get_option('km_price_per_person', 2000),
            'terms_default'       => (string) get_option('km_popup_text', 'El abono es consumible pero no tiene devolución.'),
            'hold_minutes'        => 15,
            'booking_unit'        => 'seat',
            'min_guests'          => 1,
            'max_guests'          => 12,
            'extra_chair'         => 1,
            'max_empty_seats'     => 1,
            'adjacency_distance'  => 160,
            'open_time'           => (string) get_option('km_open_time', '13:00'),
            'close_time'          => (string) get_option('km_close_time', '23:30'),
            'slot_minutes'        => 30,
            'conv_duration'       => 120,
            'conv_deposit_pp'     => (int) get_option('km_price_per_person', 2000),
            'conv_sectors'        => [
                ['name' => 'Salón de Vinos', 'capacity' => 0],
                ['name' => 'Salón de Velas', 'capacity' => 0],
                ['name' => 'Terraza techada', 'capacity' => 0],
            ],
            'conv_block_event_nights' => 1,
            'arrival_slots_default' => '19:30,20:00,20:30,21:00',
            'end_time_default'    => '23:59',
            'reminder_2d_time'    => '12:00',
            'reminder_day_time'   => '11:00',
            'wa_enabled'          => 0,
            'wa_api_version'      => 'v21.0',
            'wa_phone_id'         => '',
            'wa_token'            => '',
            'wa_lang'             => 'es',
            'wa_tpl_confirm'      => 'reserva_confirmada',
            'wa_tpl_reminder'     => 'recordatorio_reserva',
            'wa_tpl_cancel'       => 'reserva_cancelada',
            'wa_tpl_change'       => 'reserva_modificada',
            'wa_tpl_otp'          => 'codigo_verificacion',
            'wa_webhook_verify'   => '',
            'wa_app_secret'       => '',
            'wa_notify_number'    => '',
            'email_enabled'       => 1,
            'alerts_email'        => (string) get_option('admin_email'),
            'vote_channel'        => 'email',
            'home_page_id'        => 0,
        ];
    }

    public static function settings() {
        if (self::$settings_cache === null) {
            $saved = get_option('km_settings', []);
            self::$settings_cache = array_merge(self::setting_defaults(), is_array($saved) ? $saved : []);
        }
        return self::$settings_cache;
    }

    public static function setting($key) {
        $s = self::settings();
        return isset($s[$key]) ? $s[$key] : null;
    }

    public static function update_settings(array $values) {
        $current = get_option('km_settings', []);
        $current = is_array($current) ? $current : [];
        update_option('km_settings', array_merge($current, $values), false);
        self::$settings_cache = null;
    }

    public static function palettes() {
        return [
            'noche'  => ['label' => 'Noche de show (negro y rojo cine)', 'bg' => '#0a0808', 'surface' => '#151112', 'text' => '#f6f0ea', 'muted' => '#b5a9a2', 'glow' => '#7a0d0a', 'accents' => ['#e0241b', '#e8a33d', '#2f9e44']],
            'bodega' => ['label' => 'Bodega (vino y madera)', 'bg' => '#140c0a', 'surface' => '#21140f', 'text' => '#f8efe4', 'muted' => '#c9b8a6', 'glow' => '#5c1a12', 'accents' => ['#b3262e', '#c98b2b', '#5b8c3a']],
        ];
    }

    public static function site_defaults() {
        return [
            'palette'        => 'noche',
            'accent'         => '#e0241b',
            'logo'           => (string) get_option('km_logo_url', ''),
            'hero_title'     => 'Tu próxima gran noche está aquí.',
            'hero_subtitle'  => 'Elige el evento. Reserva tu puesto. Disfruta.',
            'hero_image'     => '',
            'about_title'    => 'Cacho e Cabra',
            'about_text'     => 'Restaurante con espectáculos en vivo. Tres ambientes: terraza techada, Salón de Vinos y Salón de Velas.',
            'gallery'        => [],
            'services'       => [
                ['title' => 'Espectáculos en vivo', 'text' => 'Tributos, música en vivo y karaoke con reserva de mesa.'],
                ['title' => 'Eventos privados', 'text' => 'Cumpleaños, empresas y celebraciones. Escríbenos para cotizar.'],
                ['title' => 'Carta y coctelería', 'text' => 'Cocina, vinos y tragos para compartir.'],
            ],
            'private_events_text' => '¿Quieres celebrar con nosotros? Cotiza tu evento privado por WhatsApp.',
            'hours'          => "Martes a jueves: 13:00 – 23:30\nViernes y sábado: 13:00 – 02:00\nDomingo: 13:00 – 18:00",
            'address'        => '',
            'city'           => 'Los Andes',
            'maps_url'       => '',
            'carta_url'      => '',
            'instagram'      => '',
            'whatsapp_attention' => (string) get_option('km_whatsapp_number', ''),
            'contact_email'  => '',
            'contact_phone'  => '',
            'footer_text'    => '',
            'seo_description'=> 'Cartelera de espectáculos y reservas en Cacho e Cabra.',
            'conventional_cta' => 'Reservar una mesa sin espectáculo',
        ];
    }

    public static function site($key = null) {
        if (self::$site_cache === null) {
            $saved = get_option('km_site', []);
            $data = array_merge(self::site_defaults(), is_array($saved) ? $saved : []);
            $data = apply_filters('km_site_content', $data);
            self::$site_cache = $data;
        }
        if ($key === null) return self::$site_cache;
        return isset(self::$site_cache[$key]) ? self::$site_cache[$key] : null;
    }

    public static function reset_site_cache() {
        self::$site_cache = null;
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public static function can_manage() {
        return current_user_can('km_manage') || current_user_can('manage_options');
    }

    public static function can_reception() {
        return current_user_can('km_reception') || self::can_manage();
    }

    public static function can_configure() {
        return current_user_can('manage_options');
    }

    public static function operator_name() {
        $u = wp_get_current_user();
        return $u && $u->ID ? ($u->display_name ?: $u->user_login) : 'sistema';
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public static function event_url($event) {
        $slug = is_object($event) ? $event->slug : (string) $event;
        return home_url('/evento/' . rawurlencode($slug) . '/');
    }

    /** Event bookings happen on the event page; without an event, the conventional booking page. */
    public static function booking_url($event = 0) {
        $ev = is_object($event) ? $event : ($event ? KM_Events::get((int) $event) : null);
        if ($ev && $ev->status !== 'hidden') return self::event_url($ev) . '#reservar';
        $page = get_page_by_path('reservas-2');
        $base = $page ? get_permalink($page) : home_url('/reservas-2/');
        return $ev ? add_query_arg('evento_id', (int) $ev->id, $base) : $base;
    }

    public static function panel_url($section = '') {
        return home_url('/gestion/' . ($section ? '#' . $section : ''));
    }

    public static function reception_url($token = '') {
        $u = home_url('/recepcion/');
        return $token ? add_query_arg('c', $token, $u) : $u;
    }

    public static function voucher_url($booking) {
        return add_query_arg(['r' => (int) $booking->id, 'k' => self::voucher_key($booking)], home_url('/comprobante/'));
    }

    public static function voucher_key($booking) {
        return substr(hash_hmac('sha256', 'voucher|' . $booking->id . '|' . $booking->reservation_code, wp_salt('nonce')), 0, 24);
    }

    public static function whatsapp_link($number, $text = '') {
        $digits = preg_replace('/\D/', '', (string) $number);
        if (!$digits) return '';
        return 'https://wa.me/' . $digits . ($text !== '' ? '?text=' . rawurlencode($text) : '');
    }

    public static function json_attr($data) {
        return esc_attr(wp_json_encode($data));
    }

    public static function lock($name, $timeout = 10) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'km_' . $name . '_' . $wpdb->prefix, $timeout)) === 1;
    }

    public static function unlock($name) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'km_' . $name . '_' . $wpdb->prefix));
    }
}
