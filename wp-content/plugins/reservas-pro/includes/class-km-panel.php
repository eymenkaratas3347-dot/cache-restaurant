<?php
if (!defined('ABSPATH')) exit;

/**
 * Private panel (/gestion) and reception screen (/recepcion): standalone pages outside wp-admin,
 * working on the same data and rules through the REST API.
 */
class KM_Panel {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'keep_staff_out_of_wpadmin']);
        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 10, 3);
        add_filter('show_admin_bar', function ($show) {
            return (current_user_can('km_reception') && !current_user_can('edit_posts')) ? false : $show;
        });
    }

    public static function keep_staff_out_of_wpadmin() {
        if (wp_doing_ajax() || current_user_can('edit_posts') || !is_user_logged_in()) return;
        $script = basename(sanitize_text_field($_SERVER['SCRIPT_NAME'] ?? ''));
        if (in_array($script, ['async-upload.php', 'admin-post.php', 'media-upload.php'], true)) return;
        if (current_user_can('km_manage')) { wp_safe_redirect(KM_Util::panel_url()); exit; }
        if (current_user_can('km_reception')) { wp_safe_redirect(KM_Util::reception_url()); exit; }
    }

    public static function login_redirect($to, $requested, $user) {
        if (!$user || is_wp_error($user) || $user->has_cap('edit_posts')) return $to;
        if ($user->has_cap('km_manage')) return KM_Util::panel_url();
        if ($user->has_cap('km_reception')) return KM_Util::reception_url();
        return $to;
    }

    public static function render($route) {
        header('X-Robots-Tag: noindex, nofollow');
        $self = $route === 'panel' ? KM_Util::panel_url() : KM_Util::reception_url(isset($_GET['c']) ? sanitize_text_field(wp_unslash($_GET['c'])) : '');
        if (!is_user_logged_in()) {
            self::login_screen($route, $self);
            return;
        }
        $allowed = $route === 'panel' ? KM_Util::can_reception() : KM_Util::can_reception();
        if (!$allowed) {
            status_header(403);
            self::shell('Acceso restringido', '<div class="kp-login"><h1>Acceso restringido</h1><p>Tu usuario no tiene permisos para esta sección.</p><p><a href="' . esc_url(wp_logout_url($self)) . '">Cambiar de usuario</a></p></div>');
            return;
        }
        if ($route === 'panel' && !KM_Util::can_manage()) {
            wp_safe_redirect(KM_Util::reception_url());
            exit;
        }
        if ($route === 'panel' && isset($_GET['km_export'])) { self::export(); return; }
        if ($route === 'panel' && isset($_GET['km_print'])) { self::print_plan(); return; }

        $boot = [
            'rest' => esc_url_raw(rest_url(KM_Rest::NS)),
            'nonce' => wp_create_nonce('wp_rest'),
            'route' => $route,
            'user' => KM_Util::operator_name(),
            'canManage' => KM_Util::can_manage(),
            'canConfigure' => KM_Util::can_configure(),
            'today' => KM_Util::today(),
            'logout' => wp_logout_url(home_url('/')),
            'panelUrl' => KM_Util::panel_url(),
            'receptionUrl' => KM_Util::reception_url(),
            'homeUrl' => KM_Front::home_url(),
            'exportUrl' => add_query_arg(['km_export' => 'bookings', '_wpnonce' => wp_create_nonce('km_export')], KM_Util::panel_url()),
            'printUrl' => add_query_arg(['km_print' => 'plan', '_wpnonce' => wp_create_nonce('km_export')], KM_Util::panel_url()),
            'sectors' => array_column(KM_Floor::sectors(), 'name'),
            'tables' => array_map(function ($t) { return $t + KM_Floor::layout($t['c']); }, KM_Floor::plan()),
            'unit' => KM_Floor::seat_mode() ? 'seat' : 'table',
            'initialCode' => isset($_GET['c']) ? sanitize_text_field(wp_unslash($_GET['c'])) : '',
        ];
        $title = $route === 'panel' ? 'Panel – Cacho e Cabra' : 'Recepción – Cacho e Cabra';
        if ($route === 'panel' && current_user_can('upload_files')) wp_enqueue_media();
        $js = $route === 'panel' ? 'panel.js' : 'reception.js';
        ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($title); ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap">
<link rel="stylesheet" href="<?php echo esc_url(KM_URL . 'assets/css/panel.css?v=' . KM_VERSION); ?>">
<?php if ($route === 'panel' && current_user_can('upload_files')) { wp_print_styles(['media-views', 'imgareaselect']); } ?>
</head>
<body class="kp-body kp-<?php echo esc_attr($route); ?>">
<div id="kp-app" class="kp-app" aria-live="polite"><p class="kp-loading">Cargando…</p></div>
<div id="kp-floor-template" hidden><?php echo KM_Front::floor_svg('kp-floor', true); ?></div>
<script>window.KP = <?php echo wp_json_encode($boot); ?>;</script>
<?php if ($route === 'panel' && current_user_can('upload_files')) { wp_print_media_templates(); wp_print_footer_scripts(); } ?>
<script src="<?php echo esc_url(KM_URL . 'assets/js/panel-common.js?v=' . KM_VERSION); ?>"></script>
<script src="<?php echo esc_url(KM_URL . 'assets/js/' . $js . '?v=' . KM_VERSION); ?>"></script>
</body>
</html><?php
    }

    private static function shell($title, $html) {
        ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?php echo esc_html($title); ?></title><link rel="stylesheet" href="<?php echo esc_url(KM_URL . 'assets/css/panel.css?v=' . KM_VERSION); ?>"></head>
<body class="kp-body"><?php echo $html; ?></body></html><?php
    }

    private static function login_screen($route, $redirect) {
        $form = wp_login_form(['echo' => false, 'redirect' => $redirect, 'label_username' => 'Usuario o correo', 'label_password' => 'Contraseña', 'label_remember' => 'Recordarme en este equipo', 'label_log_in' => 'Ingresar']);
        $logo = KM_Util::site('logo');
        self::shell('Ingresar', '<div class="kp-login">' . ($logo ? '<img src="' . esc_url($logo) . '" alt="" class="kp-login-logo">' : '') .
            '<h1>' . ($route === 'panel' ? 'Panel de administración' : 'Recepción') . '</h1>' . $form .
            '<p><a href="' . esc_url(wp_lostpassword_url($redirect)) . '">¿Olvidaste tu contraseña?</a></p></div>');
    }

    private static function check_nonce() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'km_export')) wp_die('Enlace vencido. Vuelve al panel.', 403);
    }

    /** CSV (Excel friendly, UTF-8 BOM, semicolon separated). */
    private static function export() {
        self::check_nonce();
        $f = array_map('sanitize_text_field', wp_unslash($_GET));
        $rows = KM_Bookings::search($f, 5000);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reservas-' . KM_Util::today() . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Código', 'Fecha', 'Hora', 'Evento/Sector', 'Ubicación', 'Personas', 'Ingresaron', 'Titular', 'Teléfono', 'Correo', 'Estado reserva', 'Estado pago', 'Total', 'Pagado', 'Promociones', 'Pedido', 'Origen', 'Notas'], ';');
        foreach ($rows as $b) {
            $promos = implode(' | ', array_map(function ($p) { return $p->qty . 'x ' . $p->name . ' (canje ' . $p->redeemed . ')'; }, KM_Bookings::promos($b->id)));
            fputcsv($out, [$b->reservation_code, $b->booking_date, $b->booking_time, $b->is_event ? $b->event_name : $b->salon_type,
                KM_Floor::place_label(KM_Floor::booking_seats($b)), $b->guests, $b->arrived_guests, $b->client_name, KM_Util::phone_display($b->phone),
                $b->client_email, KM_Util::status_label($b->status), KM_Util::payment_label($b->payment_status), (int) $b->total_amount, (int) $b->advance_payment, $promos, $b->wc_order_id,
                ['web' => 'Web', 'manual' => 'Manual', 'walkin' => 'Sin reserva'][$b->source] ?? $b->source, $b->notes], ';');
        }
        fclose($out);
        KM_Bookings::log(0, 'export', count($rows) . ' reservas');
        exit;
    }

    /** Printable plan: date, event and every table number visible, plus the list of holders. */
    private static function print_plan() {
        self::check_nonce();
        $date = KM_Util::parse_date(sanitize_text_field(wp_unslash($_GET['date'] ?? ''))) ?: KM_Util::today();
        $occ = KM_Floor::occupancy($date);
        $blocked = KM_Floor::blocked($date);
        $events = KM_Events::on_date($date);
        $rows = KM_Bookings::search(['date' => $date, 'status' => 'active']);
        $plan = KM_Floor::plan();
        $sc_map = [];
        foreach (KM_Floor::seat_occupancy($date) as $info) $sc_map[$info['booking']] = $info['state'] === 'hold' ? 'hold' : 'paid';
        ?><!doctype html><html lang="es"><head><meta charset="utf-8"><title>Plano <?php echo esc_html($date); ?></title>
<style>
body{font-family:Arial,sans-serif;color:#000;margin:16px}h1{font-size:20px;margin:0}p{margin:4px 0 12px}
.wrap{display:grid;grid-template-columns:1.1fr 1fr;gap:16px;align-items:start}
svg{width:100%;height:auto;max-height:95vh}.muro{stroke:#000;stroke-width:3;fill:none}.escenario rect{fill:#fff;stroke:#000;stroke-width:2}.escenario text{font-size:34px;font-weight:800}
.mesa{fill:#fff;stroke:#000;stroke-width:2}.full .mesa{fill:#bbb}.blk .mesa{fill:#fff;stroke-dasharray:2 3}
.lbl{font-size:16px;font-weight:800;text-anchor:middle}.blk .lbl{text-decoration:line-through}
.silla{fill:#fff;stroke:#555;stroke-width:1.5}.silla.paid{fill:#222;stroke:#000}.silla.hold{fill:#999;stroke:#000;stroke-dasharray:2 2}
table{border-collapse:collapse;width:100%;font-size:12px}td,th{border:1px solid #999;padding:4px;text-align:left}th{background:#eee}
.legend{font-size:12px}@media print{button{display:none}@page{size:A4 landscape;margin:8mm}}
</style></head><body>
<button onclick="window.print()">Imprimir</button>
<h1>Plano de ocupación – <?php echo esc_html(KM_Util::date_human($date)); ?></h1>
<p><?php echo $events ? esc_html(implode(' · ', array_map(function ($e) { return $e->event_name . ' (show ' . KM_Events::show_time($e) . ')'; }, $events))) : 'Sin evento'; ?> · Impreso <?php echo esc_html(KM_Util::now()->format('d/m/Y H:i')); ?></p>
<p class="legend">● Puesto vendido · ◌ Puesto en pago · ○ Puesto libre · Mesa gris = completa · Número tachado = mesa bloqueada</p>
<div class="wrap"><div>
<svg viewBox="120 320 760 1050">
<path class="muro" d="M149 350 L486 349 M147 349 L146 1098 M145 1102 L497 1100 M497 1099 L652 1332 M485 349 L578 476 M737 380 L579 477 M823 505 L736 380 M676 606 L824 505 M753 720 L676 606 M753 923 L753 720 M792 925 L752 923 M796 1229 L794 927 M690 740 L692 1233 M796 1229 L693 1232 M693 925 L750 922 M745 1330 L744 1233 M652 1334 L745 1331 M419 352 L692 741 M298 352 L298 980 M255 977 L257 353 M301 637 L617 639 M675 605 L579 476 M736 780 L773 778"/>
<g class="escenario"><rect x="696" y="930" width="94" height="295" rx="15"/><text x="743" y="1077" transform="rotate(90, 743, 1077)" text-anchor="middle">ESCENARIO</text></g>
<?php foreach ($plan as $t):
    if (!$t['active']) continue;
    $o = $occ[$t['id']] ?? null;
    $cls = in_array($t['id'], $blocked, true) ? 'blk' : (!empty($o['full']) ? 'full' : '');
    $l = KM_Floor::layout($t['c']); ?>
<g class="<?php echo esc_attr($cls); ?>" transform="translate(<?php echo (float) $t['x']; ?>,<?php echo (float) $t['y']; ?>)">
<?php foreach ($l['seats'] as $i => $p):
    $sc = $o && isset($o['seats'][$i + 1]) ? ($sc_map[$o['seats'][$i + 1]] ?? 'paid') : ''; ?>
<circle class="silla <?php echo esc_attr($sc); ?>" cx="<?php echo (float) $p[0]; ?>" cy="<?php echo (float) $p[1]; ?>" r="6"/>
<?php endforeach; ?>
<rect class="mesa" x="<?php echo -$l['w'] / 2; ?>" y="<?php echo -$l['h'] / 2; ?>" width="<?php echo (float) $l['w']; ?>" height="<?php echo (float) $l['h']; ?>" rx="6"/><text class="lbl" y="6"><?php echo (int) $t['id']; ?></text></g>
<?php endforeach; ?>
</svg></div><div>
<table><thead><tr><th>Ubicación</th><th>Titular</th><th>Pers.</th><th>Llegada</th><th>Pago</th><th>Código</th></tr></thead><tbody>
<?php foreach ($rows as $b): ?>
<tr><td><?php echo esc_html(KM_Floor::booking_place($b)); ?></td><td><?php echo esc_html($b->client_name); ?></td><td><?php echo (int) $b->guests; ?></td>
<td><?php echo esc_html($b->booking_time); ?></td><td><?php echo esc_html(KM_Util::payment_label($b->payment_status)); ?></td><td><?php echo esc_html($b->reservation_code); ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div></body></html><?php
        exit;
    }
}
