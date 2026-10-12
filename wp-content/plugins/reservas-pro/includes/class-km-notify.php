<?php
if (!defined('ABSPATH')) exit;

/**
 * Transactional messages by email and WhatsApp (Meta WhatsApp Cloud API, official).
 * Every message is logged with a unique de-duplication key, so repeated payment
 * notifications, cron overlaps or double clicks never send the same message twice.
 */
class KM_Notify {

    private static $embed_file = null;

    public static function init() {
        add_action('phpmailer_init', [__CLASS__, 'embed_qr']);
        add_action('wp_mail_failed', [__CLASS__, 'capture_mail_error']);
    }

    // ------------------------------------------------------------------
    // Public triggers
    // ------------------------------------------------------------------

    public static function booking_confirmed($id) {
        $b = KM_Bookings::get($id);
        if (!$b || $b->status !== 'confirmed') return;
        self::send($b, 'email', 'confirm');
        self::send($b, 'whatsapp', 'confirm');
    }

    /** Manual resend: same QR, new log entry, no new entry right. */
    public static function resend($id, $channel) {
        $b = KM_Bookings::get($id);
        if (!$b || $b->status !== 'confirmed') return new WP_Error('km_state', 'Solo se reenvían reservas confirmadas.');
        return self::send($b, $channel, 'confirm', 'resend-' . time());
    }

    public static function booking_cancelled($id, $reason = '') {
        $b = KM_Bookings::get($id);
        if (!$b) return;
        self::send($b, 'email', 'cancel', '', ['reason' => $reason]);
        self::send($b, 'whatsapp', 'cancel', '', ['reason' => $reason]);
    }

    public static function booking_changed($id) {
        $b = KM_Bookings::get($id);
        if (!$b || $b->status !== 'confirmed') return;
        $sig = substr(md5($b->booking_date . $b->booking_time . implode(',', KM_Floor::booking_seats($b))), 0, 8);
        self::send($b, 'email', 'change', $sig);
        self::send($b, 'whatsapp', 'change', $sig);
    }

    // ------------------------------------------------------------------
    // Core send with de-duplication
    // ------------------------------------------------------------------

    private static function reserve_slot($b, $channel, $type, $suffix, $recipient) {
        global $wpdb;
        $key = $b->id . '|' . $channel . '|' . $type . ($suffix !== '' ? '|' . $suffix : '');
        $t = KM_Util::table('messages');
        $n = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $t (booking_id, channel, type, dedupe_key, recipient, status, created_at) VALUES (%d, %s, %s, %s, %s, 'queued', %s)",
            $b->id, $channel, $type, substr($key, 0, 120), $recipient, KM_Util::utc_mysql()
        ));
        return $n ? (int) $wpdb->insert_id : 0;
    }

    private static function mark($msg_id, $status, $error = '', $provider_id = '', $payload = null) {
        global $wpdb;
        $d = ['status' => $status, 'error' => $error, 'updated_at' => KM_Util::utc_mysql()];
        if ($provider_id) $d['provider_id'] = $provider_id;
        if ($payload !== null) $d['payload'] = wp_json_encode($payload);
        $wpdb->update(KM_Util::table('messages'), $d, ['id' => $msg_id]);
        $wpdb->query($wpdb->prepare('UPDATE ' . KM_Util::table('messages') . ' SET attempts = attempts + 1 WHERE id = %d', $msg_id));
    }

    public static function send($b, $channel, $type, $suffix = '', $extra = []) {
        if ($channel === 'email') {
            $to = $b->client_email;
            if (!is_email($to)) return false;
            $msg_id = self::reserve_slot($b, 'email', $type, $suffix, $to);
            if (!$msg_id) return false;
            if (!KM_Util::setting('email_enabled')) { self::mark($msg_id, 'skipped', 'Correo desactivado en configuración'); return false; }
            return self::deliver_email($msg_id, $b, $type, $extra);
        }
        $to = preg_replace('/\D/', '', (string) $b->phone);
        if (!KM_Util::valid_phone($to)) return false;
        $msg_id = self::reserve_slot($b, 'whatsapp', $type, $suffix, $to);
        if (!$msg_id) return false;
        if (!self::wa_ready()) { self::mark($msg_id, 'skipped', 'WhatsApp no configurado'); return false; }
        $payload = self::wa_payload($b, $type, $to, $extra);
        return self::deliver_whatsapp($msg_id, $payload);
    }

    // ------------------------------------------------------------------
    // Email
    // ------------------------------------------------------------------

    private static $last_mail_error = '';

    public static function capture_mail_error($err) {
        self::$last_mail_error = is_wp_error($err) ? $err->get_error_message() : 'Error de envío';
    }

    public static function embed_qr($phpmailer) {
        if (self::$embed_file && file_exists(self::$embed_file)) {
            $phpmailer->AddEmbeddedImage(self::$embed_file, 'km-qr', 'qr-reserva.png', 'base64', 'image/png');
        }
    }

    private static function deliver_email($msg_id, $b, $type, $extra) {
        $subjects = [
            'confirm'  => 'Tu reserva está confirmada – ' . $b->reservation_code,
            'reminder' => 'Recordatorio de tu reserva – ' . $b->reservation_code,
            'cancel'   => 'Tu reserva fue cancelada – ' . $b->reservation_code,
            'change'   => 'Cambios en tu reserva – ' . $b->reservation_code,
        ];
        $with_qr = in_array($type, ['confirm', 'reminder', 'change'], true);
        self::$embed_file = $with_qr ? KM_QR::file($b) : null;
        self::$last_mail_error = '';
        $html = self::email_html($b, $type, $extra);
        $ok = wp_mail($b->client_email, $subjects[$type] ?? 'Tu reserva', $html, ['Content-Type: text/html; charset=UTF-8']);
        self::$embed_file = null;
        self::mark($msg_id, $ok ? 'sent' : 'failed', $ok ? '' : (self::$last_mail_error ?: 'wp_mail devolvió error'), '', ['type' => $type, 'extra' => $extra]);
        return $ok;
    }

    public static function summary_lines($b) {
        $place = KM_Floor::place_label(KM_Floor::booking_seats($b));
        $l = [];
        $l['Titular'] = $b->client_name;
        $l[$b->is_event ? 'Evento' : 'Reserva'] = $b->is_event ? $b->event_name : 'Mesa en ' . $b->salon_type;
        $l['Fecha'] = KM_Util::date_human($b->booking_date);
        if ($b->booking_time) $l[$b->is_event ? 'Hora de llegada' : 'Hora'] = $b->booking_time;
        if ($b->is_event) {
            $ev = KM_Events::get($b->event_id);
            if ($ev) $l['Inicio del show'] = KM_Events::show_time($ev);
        }
        $l['Personas'] = $b->guests;
        $l[$place ? 'Ubicación' : 'Sector'] = $place ?: $b->salon_type;
        foreach (KM_Bookings::promos($b->id) as $p) $l['Promoción'] = (isset($l['Promoción']) ? $l['Promoción'] . ' · ' : '') . $p->qty . ' × ' . $p->name;
        $l['Código'] = $b->reservation_code;
        $l[$b->payment_mode === 'abono' ? 'Abono pagado' : 'Total pagado'] = KM_Util::money($b->advance_payment ?: $b->total_amount);
        if ($b->payment_mode === 'abono' && (float) $b->min_consumption > 0) {
            $l['Consumo mínimo'] = KM_Util::money($b->min_consumption) . ' por persona (el abono se descuenta del consumo)';
        }
        return $l;
    }

    public static function email_html($b, $type, $extra = []) {
        $site = KM_Util::site();
        $accent = $site['accent'] ?: '#d7261e';
        $titles = ['confirm' => '¡Reserva confirmada!', 'reminder' => 'Te esperamos pronto', 'cancel' => 'Reserva cancelada', 'change' => 'Tu reserva cambió'];
        $intro = [
            'confirm'  => 'Gracias por tu compra. Presenta este código QR en recepción (desde el celular o impreso). Es el mismo código que te enviamos por WhatsApp.',
            'reminder' => 'Te recordamos tu reserva. Presenta este QR al llegar.',
            'cancel'   => 'Tu reserva fue cancelada y el código QR ya no es válido.' . (!empty($extra['reason']) ? ' Motivo: ' . esc_html($extra['reason']) : ''),
            'change'   => 'Actualizamos los datos de tu reserva. Tu código QR sigue siendo el mismo.',
        ];
        $rows = '';
        foreach (self::summary_lines($b) as $k => $v) {
            $rows .= '<tr><td style="padding:6px 0;color:#777;font-size:13px;width:40%">' . esc_html($k) . '</td><td style="padding:6px 0;font-size:14px;font-weight:600;color:#222">' . esc_html($v) . '</td></tr>';
        }
        $qr = in_array($type, ['confirm', 'reminder', 'change'], true) && $b->qr_token
            ? '<div style="text-align:center;margin:24px 0"><img src="cid:km-qr" width="220" height="220" alt="Código QR de la reserva" style="border:8px solid #fff"><div style="font-size:22px;letter-spacing:4px;font-weight:800;margin-top:8px">' . esc_html($b->reservation_code) . '</div></div>'
            : '';
        $wa = $site['whatsapp_attention'] ? '<p style="font-size:13px;color:#555">¿Necesitas hablar con una persona? Escríbenos al WhatsApp de atención: <a href="' . esc_url(KM_Util::whatsapp_link($site['whatsapp_attention'])) . '">' . esc_html($site['whatsapp_attention']) . '</a>. Este correo es automático.</p>' : '';
        $btn = $b->qr_token && $type !== 'cancel' ? '<p style="text-align:center"><a href="' . esc_url(KM_Util::voucher_url($b)) . '" style="background:' . esc_attr($accent) . ';color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:700;display:inline-block">Ver comprobante en línea</a></p>' : '';
        $logo = $site['logo'] ? '<img src="' . esc_url($site['logo']) . '" alt="Cacho e Cabra" style="max-height:70px">' : '<strong style="color:#fff;font-size:20px">Cacho e Cabra</strong>';
        $addr = $site['address'] ? '<p style="font-size:12px;color:#888">' . esc_html($site['address']) . ($site['maps_url'] ? ' · <a href="' . esc_url($site['maps_url']) . '">Cómo llegar</a>' : '') . '</p>' : '';

        return '<!doctype html><html><body style="margin:0;background:#f2eee9;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f2eee9;padding:24px 0"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#fff;border-radius:12px;overflow:hidden">
<tr><td style="background:#0e0c0b;padding:20px;text-align:center">' . $logo . '</td></tr>
<tr><td style="padding:24px 28px">
<h1 style="margin:0 0 8px;font-size:22px;color:' . esc_attr($accent) . '">' . esc_html($titles[$type] ?? 'Tu reserva') . '</h1>
<p style="font-size:14px;color:#444;line-height:1.5">' . $intro[$type] . '</p>' . $qr . '
<table width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #eee;margin-top:8px">' . $rows . '</table>' . $btn . $wa . $addr . '
</td></tr></table></td></tr></table></body></html>';
    }

    // ------------------------------------------------------------------
    // WhatsApp Cloud API
    // ------------------------------------------------------------------

    public static function wa_ready() {
        return KM_Util::setting('wa_enabled') && KM_Util::setting('wa_token') && KM_Util::setting('wa_phone_id');
    }

    private static function text_param($v) {
        $v = trim(preg_replace('/\s+/', ' ', (string) $v));
        return ['type' => 'text', 'text' => $v === '' ? '-' : mb_substr($v, 0, 200)];
    }

    /** Template parameter order is documented in docs/WHATSAPP-PLANTILLAS.md and must match Meta. */
    public static function wa_payload($b, $type, $to, $extra = []) {
        $s = KM_Util::settings();
        $label = $b->is_event ? $b->event_name : 'Reserva ' . $b->salon_type;
        $where = KM_Floor::booking_place($b);
        $attention = KM_Util::site('whatsapp_attention');
        $voucher = $b->qr_token ? KM_Util::voucher_url($b) : home_url('/');
        $tpl = [
            'confirm'  => $s['wa_tpl_confirm'],
            'reminder' => $s['wa_tpl_reminder'],
            'cancel'   => $s['wa_tpl_cancel'],
            'change'   => $s['wa_tpl_change'],
        ][$type];
        $params = [];
        switch ($type) {
            case 'confirm':
                $params = [$b->client_name, $label, KM_Util::date_human($b->booking_date), $b->booking_time, $b->guests, $where, $b->reservation_code, KM_Util::money($b->advance_payment ?: $b->total_amount), $voucher, $attention];
                break;
            case 'reminder':
                $params = [$b->client_name, $label, $extra['when'] ?? KM_Util::date_human($b->booking_date), $b->booking_time, $where, $b->reservation_code, $voucher];
                break;
            case 'cancel':
                $params = [$b->client_name, $label, KM_Util::date_human($b->booking_date), $extra['reason'] ?? '', $attention];
                break;
            case 'change':
                $params = [$b->client_name, $label, KM_Util::date_human($b->booking_date), $b->booking_time, $where, $voucher];
                break;
        }
        $components = [];
        if (in_array($type, ['confirm', 'reminder', 'change'], true) && $b->qr_token) {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => KM_QR::url($b)]]]];
        }
        $components[] = ['type' => 'body', 'parameters' => array_map([__CLASS__, 'text_param'], $params)];
        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => ['name' => $tpl, 'language' => ['code' => $s['wa_lang']], 'components' => $components],
        ];
    }

    public static function wa_request($payload) {
        $s = KM_Util::settings();
        $url = 'https://graph.facebook.com/' . rawurlencode($s['wa_api_version']) . '/' . rawurlencode($s['wa_phone_id']) . '/messages';
        $res = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $s['wa_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($res)) return ['ok' => false, 'error' => $res->get_error_message(), 'retry' => true];
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 200 && $code < 300 && !empty($body['messages'][0]['id'])) return ['ok' => true, 'id' => $body['messages'][0]['id']];
        $err = $body['error']['message'] ?? ('HTTP ' . $code);
        if (!empty($body['error']['error_data']['details'])) $err .= ' – ' . $body['error']['error_data']['details'];
        return ['ok' => false, 'error' => $err, 'retry' => $code >= 500 || $code === 429];
    }

    private static function deliver_whatsapp($msg_id, $payload) {
        $r = self::wa_request($payload);
        if ($r['ok']) self::mark($msg_id, 'sent', '', $r['id'], $payload);
        else self::mark($msg_id, $r['retry'] ? 'retry' : 'failed', $r['error'], '', $payload);
        return $r['ok'];
    }

    /** One-time code for vote verification by phone (authentication template). */
    public static function send_otp_whatsapp($phone, $code) {
        if (!self::wa_ready()) return new WP_Error('km_wa', 'WhatsApp no está configurado.');
        $payload = [
            'messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'template',
            'template' => ['name' => KM_Util::setting('wa_tpl_otp'), 'language' => ['code' => KM_Util::setting('wa_lang')], 'components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
            ]],
        ];
        $r = self::wa_request($payload);
        return $r['ok'] ? true : new WP_Error('km_wa', 'No se pudo enviar el código por WhatsApp.');
    }

    /** Webhook status updates from Meta: sent, delivered, read, failed. */
    public static function webhook_status(array $statuses) {
        global $wpdb;
        $t = KM_Util::table('messages');
        foreach ($statuses as $st) {
            $id = sanitize_text_field($st['id'] ?? '');
            $status = sanitize_key($st['status'] ?? '');
            if (!$id || !in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) continue;
            $err = $status === 'failed' ? sanitize_text_field($st['errors'][0]['title'] ?? 'Fallo de entrega') : '';
            $wpdb->query($wpdb->prepare("UPDATE $t SET status = %s, error = %s, updated_at = %s WHERE provider_id = %s AND status != 'read'", $status, $err, KM_Util::utc_mysql(), $id));
        }
    }

    // ------------------------------------------------------------------
    // Cron: reminders and retries
    // ------------------------------------------------------------------

    public static function cron_reminders() {
        global $wpdb;
        $now = KM_Util::now();
        $today = $now->format('Y-m-d');
        $until = $now->modify('+2 days')->format('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . KM_Bookings::table() . " WHERE status = 'confirmed' AND booking_date BETWEEN %s AND %s AND arrived_guests = 0", $today, $until
        ));
        $s = KM_Util::settings();
        foreach ($rows as $b) {
            $start = KM_Util::local_dt($b->booking_date, $b->booking_time ?: '21:00');
            $plan = [
                'rem2' => [KM_Util::local_dt($b->booking_date, $s['reminder_2d_time'])->modify('-2 days'), 'en dos días (' . KM_Util::date_human($b->booking_date) . ')'],
                'rem0' => [KM_Util::local_dt($b->booking_date, $s['reminder_day_time']), 'hoy'],
            ];
            $confirmed = KM_Util::from_utc_mysql($b->confirmed_at);
            foreach ($plan as $kind => $p) {
                list($at, $when) = $p;
                if (!$at || $now < $at || ($start && $now >= $start)) continue;
                $suffix = $kind . '|' . $b->booking_date;
                if (($confirmed && $confirmed > $at) || $now->getTimestamp() - $at->getTimestamp() > 6 * 3600) {
                    // Purchased after the reminder time (or cron was down): do not send a late reminder.
                    self::skip($b, 'whatsapp', 'reminder', $suffix);
                    self::skip($b, 'email', 'reminder', $suffix);
                    continue;
                }
                self::send($b, 'whatsapp', 'reminder', $suffix, ['when' => $when]);
                self::send($b, 'email', 'reminder', $suffix, ['when' => $when]);
            }
        }
    }

    private static function skip($b, $channel, $type, $suffix) {
        $id = self::reserve_slot($b, $channel, $type, $suffix, '');
        if ($id) self::mark($id, 'skipped', 'Compra posterior al horario del recordatorio');
    }

    public static function cron_retries() {
        global $wpdb;
        $t = KM_Util::table('messages');
        $rows = $wpdb->get_results("SELECT * FROM $t WHERE status = 'retry' AND attempts < 4 ORDER BY id ASC LIMIT 20");
        foreach ($rows as $m) self::retry($m->id);
    }

    public static function retry($msg_id) {
        global $wpdb;
        $t = KM_Util::table('messages');
        $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $msg_id));
        if (!$m || in_array($m->status, ['sent', 'delivered', 'read'], true)) return new WP_Error('km_state', 'El mensaje ya fue entregado.');
        $b = KM_Bookings::get($m->booking_id);
        if (!$b) return new WP_Error('km_404', 'Reserva no encontrada.');
        if ($m->type !== 'cancel' && $b->status !== 'confirmed') {
            self::mark($m->id, 'skipped', 'La reserva ya no está confirmada');
            return new WP_Error('km_state', 'La reserva ya no está confirmada.');
        }
        $payload = json_decode((string) $m->payload, true) ?: [];
        if ($m->channel === 'email') {
            return self::deliver_email($m->id, $b, $m->type, $payload['extra'] ?? []);
        }
        if (!self::wa_ready()) return new WP_Error('km_wa', 'WhatsApp no está configurado.');
        if (!$payload) $payload = self::wa_payload($b, $m->type, $m->recipient);
        return self::deliver_whatsapp($m->id, $payload);
    }

    public static function messages_for($booking_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, channel, type, status, error, attempts, created_at FROM ' . KM_Util::table('messages') . ' WHERE booking_id = %d ORDER BY id DESC', (int) $booking_id));
        foreach ($rows as $r) {
            $dt = KM_Util::from_utc_mysql($r->created_at);
            $r->created_at = $dt ? $dt->format('d/m H:i') : '';
        }
        return $rows;
    }

    public static function test_email($to) {
        self::$last_mail_error = '';
        $ok = wp_mail($to, 'Prueba de correo – Cacho e Cabra', 'Si recibes este mensaje, el correo transaccional funciona.');
        return $ok ? true : new WP_Error('km_mail', self::$last_mail_error ?: 'No se pudo enviar.');
    }
}
