<?php
if (!defined('ABSPATH')) exit;

/**
 * WP-admin entry point: links to the panel and a technical health check.
 * Day-to-day operation happens in /gestion and /recepcion.
 */
class KM_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_notices', [__CLASS__, 'notices']);
    }

    public static function menu() {
        add_menu_page('Reservas Elite', 'Reservas Elite', 'km_manage', 'km-reservas', [__CLASS__, 'page'], 'dashicons-calendar-alt', 6);
    }

    public static function checks() {
        $c = [];
        $add = function ($status, $label, $detail) use (&$c) { $c[] = compact('status', 'label', 'detail'); };

        $pid = (int) KM_Util::setting('product_id');
        $p = function_exists('wc_get_product') ? wc_get_product($pid) : null;
        $add($p && $p->is_virtual() ? 'ok' : 'error', 'Producto de reservas', $p ? '#' . $pid . ' ' . $p->get_name() . ($p->is_virtual() ? '' : ' (debe ser virtual)') : 'No configurado o inexistente (#' . $pid . ')');

        $cur = get_option('woocommerce_currency');
        $dec = (int) get_option('woocommerce_price_num_decimals');
        $add($cur === 'CLP' && $dec === 0 ? 'ok' : 'warn', 'Moneda', $cur . ' con ' . $dec . ' decimales' . ($dec ? ' – el peso chileno no usa decimales; poner 0 en WooCommerce > Ajustes.' : ''));
        $add(get_option('woocommerce_enable_guest_checkout') === 'yes' ? 'ok' : 'error', 'Compra sin cuenta', get_option('woocommerce_enable_guest_checkout') === 'yes' ? 'Activada' : 'Activar "Permitir pedidos sin cuenta" en WooCommerce.');

        if (function_exists('WC') && WC()->payment_gateways()) {
            foreach (WC()->payment_gateways()->payment_gateways() as $gw) {
                if ($gw->enabled !== 'yes') continue;
                $env = method_exists($gw, 'get_option') ? $gw->get_option('environment') : '';
                $add($env === 'TEST' ? 'warn' : 'ok', 'Medio de pago: ' . wp_strip_all_tags($gw->get_title()), $env === 'TEST' ? 'Habilitado en ambiente de PRUEBA (no cobra dinero real).' : 'Habilitado' . ($env ? ' (' . $env . ')' : ''));
            }
        }

        $smtp = get_option('wp_mail_smtp');
        $mailer = is_array($smtp) ? ($smtp['mail']['mailer'] ?? 'mail') : 'mail';
        $add($mailer === 'mail' ? 'warn' : 'ok', 'Correo transaccional', $mailer === 'mail' ? 'Sin servicio de envío configurado (usa la función mail() del servidor; alto riesgo de spam o no entrega). Configurar WP Mail SMTP con un proveedor (Brevo, SendGrid, Amazon SES o SMTP del dominio).' : 'Proveedor: ' . $mailer);

        $add(KM_Notify::wa_ready() ? 'ok' : 'warn', 'WhatsApp (Cloud API)', KM_Notify::wa_ready() ? 'Configurado' : 'No configurado: las confirmaciones solo se envían por correo.');

        $tz = get_option('timezone_string');
        $add($tz === 'America/Santiago' ? 'ok' : 'warn', 'Zona horaria de WordPress', ($tz ?: 'UTC' . get_option('gmt_offset')) . ' – el sistema de reservas usa America/Santiago igualmente; se recomienda configurarla también en Ajustes > Generales.');

        $next = wp_next_scheduled('km_cron_tick');
        $last = (int) get_option('km_cron_last', 0);
        $late = !$last || time() - $last > 30 * 60;
        $add($next && !$late ? 'ok' : 'warn', 'Tareas automáticas (cron)', ($last ? 'Última ejecución: ' . wp_date('d/m/Y H:i', $last, KM_Util::tz()) : 'Aún sin ejecutar') . '. En producción conviene un cron real del hosting cada 5 minutos llamando a wp-cron.php.');

        $home = (int) KM_Util::setting('home_page_id');
        $add($home && (int) get_option('page_on_front') === $home ? 'ok' : 'warn', 'Portada', $home ? ((int) get_option('page_on_front') === $home ? 'Nueva cartelera activa como portada' : 'La nueva cartelera existe pero aún no es la portada (activar desde el panel > Contenido).') : 'Nueva portada no creada');

        if (file_exists(KM_PATH . 'reservasold.php')) $add('warn', 'Archivo antiguo', 'reservas-pro/reservasold.php es la versión V17 y aparece como plugin aparte; no activarlo. Se recomienda eliminarlo tras la aprobación.');
        if (is_plugin_active('vincularplug/vincular.php')) $add('warn', 'Plugin ajeno activo', '"Laboratorio Vincular" pertenece a otro proyecto (chat IA) y está activo en este sitio. Desactivar si no se usa.');
        if (is_plugin_active('seatreg/seatreg.php')) $add('warn', 'Plugin sin uso', 'SeatReg está activo pero el sistema de reservas no lo utiliza. Desactivar si no se usa.');
        return $c;
    }

    public static function page() {
        $icons = ['ok' => '✅', 'warn' => '⚠️', 'error' => '⛔']; ?>
        <div class="wrap">
            <h1>Reservas Elite <small>v<?php echo esc_html(KM_VERSION); ?></small></h1>
            <p>La operación diaria se realiza fuera del escritorio de WordPress:</p>
            <p>
                <a class="button button-primary button-hero" href="<?php echo esc_url(KM_Util::panel_url()); ?>" target="_blank">Abrir panel de administración</a>
                <a class="button button-hero" href="<?php echo esc_url(KM_Util::reception_url()); ?>" target="_blank">Abrir recepción</a>
            </p>
            <h2>Revisión técnica</h2>
            <table class="widefat striped" style="max-width:1000px"><tbody>
            <?php foreach (self::checks() as $c): ?>
                <tr><td style="width:30px"><?php echo $icons[$c['status']]; ?></td><td style="width:240px"><strong><?php echo esc_html($c['label']); ?></strong></td><td><?php echo esc_html($c['detail']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <h2>Usuarios del personal</h2>
            <p>Crea usuarios en <a href="<?php echo esc_url(admin_url('user-new.php')); ?>">Usuarios &gt; Añadir nuevo</a> con el perfil <strong>Cacho e Cabra – Administración</strong> (panel completo, sin claves de pago) o <strong>Cacho e Cabra – Recepción</strong> (solo recepción y consulta).</p>
        </div>
        <?php
    }

    public static function notices() {
        $screen = get_current_screen();
        if (!$screen || $screen->id === 'toplevel_page_km-reservas' || !current_user_can('manage_options')) return;
        global $wpdb;
        $n = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . KM_Util::table('incidents') . ' WHERE resolved = 0');
        if ($n) echo '<div class="notice notice-warning"><p>Reservas: hay ' . $n . ' incidencia(s) de pago o mesas por revisar. <a href="' . esc_url(KM_Util::panel_url('incidencias')) . '">Revisar</a></p></div>';
    }
}
