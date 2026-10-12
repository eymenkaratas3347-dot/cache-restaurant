<?php
if (!defined('ABSPATH')) exit;

/**
 * "Quiero que vuelva": one verified vote per contact (email or phone) and event.
 * A verified contact is not the same as a unique person: someone with an email and a phone
 * could vote twice if both channels are enabled. Default channel is email (no per-message cost).
 */
class KM_Votes {

    const CODE_MINUTES = 15;
    const MAX_ATTEMPTS = 5;

    public static function table() {
        return KM_Util::table('votes');
    }

    private static function code_hash($code, $id) {
        return hash_hmac('sha256', $id . '|' . $code, wp_salt('nonce'));
    }

    private static function ticket($id) {
        return $id . '.' . substr(hash_hmac('sha256', 'vote|' . $id, wp_salt('nonce')), 0, 16);
    }

    private static function ticket_id($ticket) {
        $p = explode('.', (string) $ticket);
        if (count($p) !== 2) return 0;
        return hash_equals(self::ticket((int) $p[0]), $ticket) ? (int) $p[0] : 0;
    }

    public static function channels() {
        $c = KM_Util::setting('vote_channel');
        if ($c === 'both' && KM_Notify::wa_ready()) return ['email', 'phone'];
        if ($c === 'phone' && KM_Notify::wa_ready()) return ['phone'];
        return ['email'];
    }

    private static function rate_limited() {
        $ip = substr(sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $key = 'km_vote_rl_' . md5($ip);
        $n = (int) get_transient($key);
        if ($n >= 10) return true;
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
        return false;
    }

    /** Step 1: register the intention and send a verification code. */
    public static function request(array $in) {
        global $wpdb;
        $ev = KM_Events::get((int) ($in['event_id'] ?? 0));
        if (!$ev || $ev->status !== 'published' || !KM_Events::is_past($ev)) return new WP_Error('km_event', 'Este evento no admite votos.');
        $name = sanitize_text_field($in['name'] ?? '');
        if (mb_strlen($name) < 2) return new WP_Error('km_name', 'Ingresa tu nombre.');
        $channel = ($in['channel'] ?? 'email') === 'phone' ? 'phone' : 'email';
        if (!in_array($channel, self::channels(), true)) return new WP_Error('km_channel', 'Canal de verificación no disponible.');

        if ($channel === 'email') {
            $contact = strtolower(sanitize_email($in['email'] ?? ''));
            if (!is_email($contact)) return new WP_Error('km_email', 'Ingresa un correo válido.');
        } else {
            $contact = KM_Util::normalize_phone($in['phone'] ?? '', $in['country'] ?? '+56');
            if (!KM_Util::valid_phone($contact)) return new WP_Error('km_phone', 'Ingresa un número válido.');
        }
        if (self::rate_limited()) return new WP_Error('km_rate', 'Demasiados intentos. Prueba más tarde.');

        $hash = KM_Util::contact_hash($channel . ':' . $contact);
        $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE event_id = %d AND contact_hash = %s", $ev->id, $hash));
        if ($row && $row->verified) return new WP_Error('km_dup', 'Este contacto ya votó por este evento. ¡Gracias por tu apoyo!');

        $code = (string) random_int(100000, 999999);
        $data = [
            'event_id' => $ev->id, 'event_name' => $ev->event_name, 'voter_name' => $name,
            'voter_email' => $channel === 'email' ? $contact : '', 'voter_whatsapp' => $channel === 'phone' ? $contact : '',
            'contact_type' => $channel, 'contact_hash' => $hash, 'verified' => 0, 'attempts' => 0,
            'code_expires' => KM_Util::utc_mysql((new DateTimeImmutable())->modify('+' . self::CODE_MINUTES . ' minutes')),
            'consent_notify' => empty($in['consent']) ? 0 : 1,
            'ip' => substr(sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ];
        if ($row) {
            $wpdb->update($t, $data, ['id' => $row->id]);
            $id = (int) $row->id;
        } else {
            $data['created_at'] = current_time('mysql');
            if (!$wpdb->insert($t, $data)) return new WP_Error('km_dup', 'Este contacto ya votó por este evento.');
            $id = (int) $wpdb->insert_id;
        }
        $wpdb->update($t, ['code_hash' => self::code_hash($code, $id)], ['id' => $id]);

        if ($channel === 'email') {
            $sent = wp_mail($contact, 'Tu código para votar – Cacho e Cabra', "Hola $name,\n\nTu código para confirmar tu voto por \"{$ev->event_name}\" es: $code\n\nVence en " . self::CODE_MINUTES . " minutos. Si no lo pediste, ignora este correo.");
            if (!$sent) return new WP_Error('km_mail', 'No pudimos enviar el correo. Revisa la dirección o intenta más tarde.');
        } else {
            $r = KM_Notify::send_otp_whatsapp($contact, $code);
            if (is_wp_error($r)) return $r;
        }
        return ['ticket' => self::ticket($id), 'channel' => $channel];
    }

    /** Step 2: verify the code. */
    public static function verify($ticket, $code) {
        global $wpdb;
        $id = self::ticket_id($ticket);
        $t = self::table();
        $row = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id)) : null;
        if (!$row) return new WP_Error('km_ticket', 'Solicitud no válida. Vuelve a empezar.');
        if ($row->verified) return ['count' => self::count($row->event_id), 'already' => true];
        if ((int) $row->attempts >= self::MAX_ATTEMPTS) return new WP_Error('km_attempts', 'Demasiados intentos. Solicita un nuevo código.');
        if (!$row->code_expires || strtotime($row->code_expires . ' UTC') < time()) return new WP_Error('km_expired', 'El código venció. Solicita uno nuevo.');
        $wpdb->query($wpdb->prepare("UPDATE $t SET attempts = attempts + 1 WHERE id = %d", $id));
        if (!hash_equals((string) $row->code_hash, self::code_hash(preg_replace('/\D/', '', (string) $code), $id))) {
            return new WP_Error('km_code', 'Código incorrecto.');
        }
        $wpdb->update($t, ['verified' => 1, 'code_hash' => null, 'verified_at' => KM_Util::utc_mysql()], ['id' => $id]);
        return ['count' => self::count($row->event_id), 'already' => false];
    }

    public static function count($event_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . ' WHERE event_id = %d AND verified = 1', (int) $event_id));
    }

    public static function results() {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results("SELECT event_id, event_name, SUM(verified = 1) AS verified, SUM(verified = 0) AS unverified,
            SUM(verified = 1 AND consent_notify = 1) AS consent, MAX(created_at) AS last_vote
            FROM $t GROUP BY event_id, event_name ORDER BY verified DESC, last_vote DESC");
    }

    public static function voters($event_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT voter_name, voter_email, voter_whatsapp, contact_type, verified, consent_notify, created_at FROM ' . self::table() . ' WHERE event_id = %d ORDER BY id DESC', (int) $event_id));
    }
}
