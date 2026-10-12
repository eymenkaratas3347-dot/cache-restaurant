<?php
if (!defined('ABSPATH')) exit;

/**
 * Bookings: creation with temporary holds, payment confirmation (idempotent),
 * cancellations, check-in, promotions and audit trail.
 *
 * status:          pending_payment | confirmed | conflict | cancelled | expired
 * payment_status:  pending | paid | failed | abandoned | cancelled | refunded | partial_refund |
 *                  onsite | courtesy | none | manual
 */
class KM_Bookings {

    public static function table() {
        return KM_Util::table('bookings');
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id));
    }

    public static function by_token($token) {
        global $wpdb;
        $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) $token));
        if (strlen($token) < 16) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE qr_token = %s', $token));
    }

    public static function by_code($code) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE reservation_code = %s ORDER BY id DESC LIMIT 1', strtoupper(trim($code))));
    }

    private static function new_code() {
        global $wpdb;
        do {
            $code = strtoupper(substr(str_replace(['0', 'O', '1', 'I'], '', wp_generate_password(12, false, false)), 0, 6));
        } while (strlen($code) < 6 || $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE reservation_code = %s', $code)));
        return $code;
    }

    // ------------------------------------------------------------------
    // Audit log and incidents
    // ------------------------------------------------------------------

    public static function log($booking_id, $action, $details = '', $event_id = 0) {
        global $wpdb;
        $wpdb->insert(KM_Util::table('log'), [
            'booking_id' => (int) $booking_id,
            'event_id'   => (int) $event_id,
            'action'     => substr($action, 0, 40),
            'details'    => is_scalar($details) ? (string) $details : wp_json_encode($details),
            'user_id'    => get_current_user_id(),
            'user_name'  => KM_Util::operator_name(),
            'created_at' => KM_Util::utc_mysql(),
        ]);
    }

    public static function incident($booking_id, $order_id, $type, $message) {
        global $wpdb;
        $wpdb->insert(KM_Util::table('incidents'), [
            'booking_id' => (int) $booking_id, 'order_id' => (int) $order_id, 'type' => $type,
            'message' => $message, 'created_at' => KM_Util::utc_mysql(),
        ]);
        self::log($booking_id, 'incident', $type . ': ' . $message);
        $to = KM_Util::setting('alerts_email');
        if ($to) {
            wp_mail($to, '[Cacho e Cabra] Incidencia en reserva #' . $booking_id, $message . "\n\nRevisar en: " . KM_Util::panel_url('incidencias'));
        }
    }

    public static function history($booking_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT action, details, user_name, created_at FROM ' . KM_Util::table('log') . ' WHERE booking_id = %d ORDER BY id DESC LIMIT 100', (int) $booking_id));
        foreach ($rows as $r) {
            $dt = KM_Util::from_utc_mysql($r->created_at);
            $r->created_at = $dt ? $dt->format('d/m/Y H:i') : $r->created_at;
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Pricing
    // ------------------------------------------------------------------

    /**
     * @param array $promos promo_id => qty
     * @return array|WP_Error
     */
    public static function quote($event, $guests, array $promos = [], $is_event = true, $date = '') {
        $guests = (int) $guests;
        $unit = $is_event ? KM_Events::price($event) : (float) KM_Util::setting('conv_deposit_pp');
        $mode = $is_event ? ($event->payment_mode ?: 'entrada') : 'abono';
        $min = $is_event ? (float) $event->min_consumption : 0;
        $lines = [];
        $promo_total = 0;
        foreach ($promos as $pid => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) continue;
            $p = KM_Promos::get($pid);
            $ok = KM_Promos::check_available($p, $qty, $is_event ? (int) $event->id : 0, $is_event ? $event->event_date : $date);
            if (is_wp_error($ok)) return $ok;
            $lines[] = ['id' => (int) $p->id, 'name' => $p->name, 'qty' => $qty, 'unit' => (float) $p->price, 'total' => $qty * (float) $p->price];
            $promo_total += $qty * (float) $p->price;
        }
        $people_total = $unit * $guests;
        $balance = $mode === 'abono' && $min > 0 ? max(0, $min * $guests - $people_total) : 0;
        return [
            'unit'          => $unit,
            'guests'        => $guests,
            'people_total'  => $people_total,
            'promos'        => $lines,
            'promos_total'  => $promo_total,
            'total'         => $people_total + $promo_total,
            'payment_mode'  => $mode,
            'min_consumption' => $min,
            'balance'       => $balance,
        ];
    }

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    /**
     * Public booking: validates everything server side and creates a temporary hold.
     * @return object|WP_Error booking row
     */
    public static function start_web_booking(array $in) {
        $name = sanitize_text_field($in['name'] ?? '');
        $email = sanitize_email($in['email'] ?? '');
        $phone = KM_Util::normalize_phone($in['phone'] ?? '', $in['country'] ?? '+56');
        $guests = (int) ($in['guests'] ?? 0);
        if (mb_strlen($name) < 3) return new WP_Error('km_name', 'Ingresa tu nombre y apellido.');
        if (!is_email($email)) return new WP_Error('km_email', 'Ingresa un correo válido para recibir tu comprobante.');
        if (!KM_Util::valid_phone($phone)) return new WP_Error('km_phone', 'Ingresa un número de WhatsApp válido.');
        if (empty($in['terms'])) return new WP_Error('km_terms', 'Debes aceptar las condiciones de la reserva.');

        $event = null;
        $is_event = !empty($in['event_id']);
        if ($is_event) {
            $event = KM_Events::get((int) $in['event_id']);
            if (!$event || !KM_Events::booking_open($event)) return new WP_Error('km_closed', 'Las reservas en línea para este evento están cerradas.');
            if (!empty($event->sold_out)) return new WP_Error('km_soldout', 'Este evento está agotado.');
            $date = $event->event_date;
            $time = sanitize_text_field($in['time'] ?? '');
            $slots = KM_Events::arrival_slots($event);
            if ($slots && !in_array($time, $slots, true)) return new WP_Error('km_time', 'Selecciona una hora de llegada válida.');
            $max = KM_Events::max_guests($event);
        } else {
            $date = KM_Util::parse_date($in['date'] ?? '');
            $time = sanitize_text_field($in['time'] ?? '');
            if (!$date || $date < KM_Util::today()) return new WP_Error('km_date', 'Selecciona una fecha válida.');
            $max = (int) KM_Util::setting('max_guests');
        }
        if ($guests < max(1, (int) KM_Util::setting('min_guests')) || $guests > $max) {
            return new WP_Error('km_guests', "Las reservas en línea son de 1 a $max personas. Para grupos mayores escríbenos por WhatsApp.");
        }

        $promos = [];
        foreach ((array) ($in['promos'] ?? []) as $pid => $qty) if ((int) $qty > 0) $promos[(int) $pid] = (int) $qty;

        if (!KM_Util::lock('date_' . $date, 10)) return new WP_Error('km_busy', 'Hay muchas reservas en este momento. Intenta de nuevo en unos segundos.');
        try {
            $tables = [];
            $seats = [];
            $sector = '';
            if ($is_event) {
                $v = KM_Floor::validate($date, self::selection($in), $guests);
                if (is_wp_error($v)) return $v;
                $tables = $v['ids'];
                $seats = $v['seats'];
                $sector = 'Evento';
            } else {
                $sector = sanitize_text_field($in['sector'] ?? '');
                $v = KM_Floor::validate_conventional($date, $time, $sector, $guests);
                if (is_wp_error($v)) return $v;
            }
            $q = self::quote($event, $guests, $promos, $is_event, $date);
            if (is_wp_error($q)) return $q;

            $id = self::insert([
                'client_name' => $name, 'client_email' => $email, 'phone' => $phone,
                'booking_date' => $date, 'booking_time' => $time, 'guests' => $guests,
                'salon_type' => $sector, 'tables_data' => wp_json_encode(array_map('strval', $tables)), 'seats_data' => wp_json_encode($seats),
                'is_event' => $is_event ? 1 : 0, 'event_id' => $is_event ? (int) $event->id : 0,
                'event_name' => $is_event ? $event->event_name : '',
                'status' => 'pending_payment', 'payment_status' => 'pending', 'source' => 'web',
                'unit_price' => $q['unit'], 'promos_total' => $q['promos_total'], 'total_amount' => $q['total'],
                'advance_payment' => 0, 'payment_mode' => $q['payment_mode'], 'min_consumption' => $q['min_consumption'],
                'hold_expires' => KM_Util::utc_mysql((new DateTimeImmutable())->modify('+' . max(5, (int) KM_Util::setting('hold_minutes')) . ' minutes')),
                'consent_marketing' => empty($in['consent']) ? 0 : 1,
            ], $q['promos']);
        } finally {
            KM_Util::unlock('date_' . $date);
        }
        if (is_wp_error($id)) return $id;
        self::log($id, 'created_web', ($seats ? KM_Floor::place_label($seats) . ' · ' : '') . 'Retención hasta ' . KM_Util::setting('hold_minutes') . ' min. Total ' . KM_Util::money($q['total']), $is_event ? $event->id : 0);
        return self::get($id);
    }

    private static function insert(array $data, array $promo_lines) {
        global $wpdb;
        $data['reservation_code'] = self::new_code();
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = KM_Util::utc_mysql();
        if (!$wpdb->insert(self::table(), $data)) return new WP_Error('km_db', 'No se pudo guardar la reserva.');
        $id = (int) $wpdb->insert_id;
        foreach ($promo_lines as $l) {
            $wpdb->insert(KM_Util::table('booking_promos'), ['booking_id' => $id, 'promo_id' => $l['id'], 'name' => $l['name'], 'qty' => $l['qty'], 'unit_price' => $l['unit']]);
        }
        return $id;
    }

    /**
     * Manual booking or walk-in from the panel. Conflicts are always checked;
     * optimisation rules can be skipped by the operator.
     */
    public static function create_manual(array $in) {
        $walkin = !empty($in['walkin']);
        $guests = (int) ($in['guests'] ?? 0);
        $event = !empty($in['event_id']) ? KM_Events::get((int) $in['event_id']) : null;
        $date = $event ? $event->event_date : KM_Util::parse_date($in['date'] ?? '');
        if (!$date) return new WP_Error('km_date', 'Fecha no válida.');
        if ($guests < 1) return new WP_Error('km_guests', 'Indica el número de personas.');
        $name = sanitize_text_field($in['name'] ?? '') ?: ($walkin ? 'Cliente sin reserva' : '');
        if ($name === '') return new WP_Error('km_name', 'Indica el nombre del titular.');
        $phone = KM_Util::normalize_phone($in['phone'] ?? '', '+56');
        $email = sanitize_email($in['email'] ?? '');
        $pay = in_array($in['payment_status'] ?? '', ['paid', 'onsite', 'courtesy', 'pending'], true) ? $in['payment_status'] : 'onsite';
        $promos = [];
        foreach ((array) ($in['promos'] ?? []) as $pid => $qty) if ((int) $qty > 0) $promos[(int) $pid] = (int) $qty;

        if (!KM_Util::lock('date_' . $date, 10)) return new WP_Error('km_busy', 'Sistema ocupado, reintenta.');
        try {
            $tables = [];
            $seats = [];
            if (self::selection($in)) {
                $v = KM_Floor::validate($date, self::selection($in), $guests, 0, empty($in['override_rules']));
                if (is_wp_error($v)) return $v;
                $tables = $v['ids'];
                $seats = $v['seats'];
            } elseif ($event) {
                return new WP_Error('km_tables', 'Asigna puestos o mesas para la reserva del evento.');
            }
            $q = self::quote($event, $guests, $promos, (bool) $event, $date);
            if (is_wp_error($q)) return $q;
            $amount = isset($in['amount']) && $in['amount'] !== '' ? max(0, KM_Util::to_int_amount($in['amount'])) : $q['total'];
            $id = self::insert([
                'client_name' => $name, 'client_email' => $email, 'phone' => $phone ?: '',
                'booking_date' => $date, 'booking_time' => sanitize_text_field(($in['time'] ?? '') ?: ($event ? KM_Events::show_time($event) : '')),
                'guests' => $guests, 'salon_type' => $event ? 'Evento' : sanitize_text_field($in['sector'] ?? ''),
                'tables_data' => wp_json_encode(array_map('strval', $tables)), 'seats_data' => wp_json_encode($seats),
                'is_event' => $event ? 1 : 0, 'event_id' => $event ? (int) $event->id : 0, 'event_name' => $event ? $event->event_name : '',
                'status' => 'confirmed', 'payment_status' => $pay, 'source' => $walkin ? 'walkin' : 'manual',
                'unit_price' => $q['unit'], 'promos_total' => $q['promos_total'], 'total_amount' => $amount,
                'advance_payment' => $pay === 'paid' ? $amount : 0, 'payment_mode' => $q['payment_mode'], 'min_consumption' => $q['min_consumption'],
                'confirmed_at' => KM_Util::utc_mysql(), 'qr_token' => KM_Util::random_token(),
                'arrived_guests' => $walkin ? $guests : 0, 'notes' => sanitize_textarea_field($in['notes'] ?? ''),
            ], $q['promos']);
        } finally {
            KM_Util::unlock('date_' . $date);
        }
        if (is_wp_error($id)) return $id;
        self::log($id, $walkin ? 'created_walkin' : 'created_manual', 'Pago: ' . $pay . (!empty($in['override_rules']) ? ' · reglas de optimización omitidas' : ''), $event ? $event->id : 0);
        if ($walkin) self::record_checkin_row($id, $guests, 'Ingreso sin reserva');
        if (!$walkin && !empty($in['notify'])) KM_Notify::booking_confirmed($id);
        return self::get($id);
    }

    // ------------------------------------------------------------------
    // Payment lifecycle
    // ------------------------------------------------------------------

    /**
     * Called for every "paid" signal from the gateway. Repeated calls do nothing.
     * Late payments (hold expired) are confirmed only if the tables are still free.
     */
    public static function confirm_payment($id, $order_id = 0, $paid_amount = null) {
        global $wpdb;
        $b = self::get($id);
        if (!$b) return false;
        if ($b->payment_status === 'paid' && in_array($b->status, ['confirmed', 'conflict'], true)) return true;

        if (!KM_Util::lock('date_' . $b->booking_date, 15)) {
            self::incident($id, $order_id, 'lock_timeout', 'No se pudo confirmar automáticamente por bloqueo. Revisar el pago.');
            return false;
        }
        try {
            $b = self::get($id);
            if ($b->payment_status === 'paid' && in_array($b->status, ['confirmed', 'conflict'], true)) return true;

            $hold_valid = $b->status === 'pending_payment' && $b->hold_expires && strtotime($b->hold_expires . ' UTC') > time();
            $status = 'confirmed';
            $note = 'Pago aprobado';
            if (!$hold_valid) {
                $ok = true;
                $seats = KM_Floor::booking_seats($b);
                if ($seats) {
                    $v = KM_Floor::validate($b->booking_date, $seats, $b->guests, $b->id, false);
                    $ok = !is_wp_error($v);
                } elseif (!$b->is_event && $b->salon_type) {
                    $found = null;
                    foreach (KM_Floor::sectors() as $s) if ($s['name'] === $b->salon_type) $found = $s;
                    if ($found && (int) $found['capacity'] > 0) {
                        $ok = KM_Floor::sector_load($b->booking_date, $b->booking_time, $b->salon_type, $b->id) + $b->guests <= (int) $found['capacity'];
                    }
                }
                if ($ok) {
                    $note = 'Pago tardío aprobado: las mesas seguían libres';
                } else {
                    $status = 'conflict';
                    $note = 'Pago tardío: las mesas ya fueron vendidas a otro cliente';
                }
            }
            $amount = $paid_amount !== null ? (float) $paid_amount : (float) $b->total_amount;
            $updated = $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::table() . " SET status = %s, payment_status = 'paid', advance_payment = %f, wc_order_id = %d, confirmed_at = %s,
                 qr_token = IF(qr_token IS NULL OR qr_token = '', %s, qr_token), hold_expires = NULL, updated_at = %s
                 WHERE id = %d AND NOT (payment_status = 'paid' AND status IN ('confirmed','conflict'))",
                $status, $amount, (int) $order_id, KM_Util::utc_mysql(), KM_Util::random_token(), KM_Util::utc_mysql(), $id
            ));
        } finally {
            KM_Util::unlock('date_' . $b->booking_date);
        }
        if (!$updated) return true;

        self::log($id, $status === 'confirmed' ? 'payment_confirmed' : 'payment_conflict', $note . ' · ' . KM_Util::money($amount) . ($order_id ? " · pedido #$order_id" : ''), $b->event_id);
        if (abs($amount - (float) $b->total_amount) > 1) {
            self::incident($id, $order_id, 'amount_mismatch', 'El monto pagado (' . KM_Util::money($amount) . ') no coincide con el total de la reserva (' . KM_Util::money($b->total_amount) . ').');
        }
        if ($status === 'conflict') {
            self::incident($id, $order_id, 'late_payment_conflict', "Reserva {$b->reservation_code} ({$b->client_name}) pagó después de vencer la retención y sus mesas ya no están libres. Reasignar mesas o devolver el pago.");
            return true;
        }
        KM_Notify::booking_confirmed($id);
        return true;
    }

    public static function payment_failed($id, $order_id, $payment_status = 'failed') {
        global $wpdb;
        $b = self::get($id);
        if (!$b || $b->payment_status === 'paid') {
            if ($b) self::log($id, 'payment_signal_ignored', "Señal '$payment_status' ignorada: la reserva ya estaba pagada (pedido #$order_id).");
            return;
        }
        $hold_valid = $b->status === 'pending_payment' && $b->hold_expires && strtotime($b->hold_expires . ' UTC') > time();
        if ($payment_status === 'failed' && $hold_valid) {
            // A declined card keeps the tables until the hold expires, so the customer can retry.
            $wpdb->update(self::table(), ['payment_status' => 'failed', 'wc_order_id' => (int) $order_id, 'updated_at' => KM_Util::utc_mysql()], ['id' => $id]);
            $until = KM_Util::from_utc_mysql($b->hold_expires);
            self::log($id, 'payment_failed', "Pedido #$order_id rechazado. Puestos retenidos hasta las " . ($until ? $until->format('H:i') : '—') . ' para reintentar.', $b->event_id);
            return;
        }
        $wpdb->update(self::table(), ['status' => 'cancelled', 'payment_status' => $payment_status, 'hold_expires' => null, 'wc_order_id' => (int) $order_id, 'updated_at' => KM_Util::utc_mysql()], ['id' => $id]);
        self::log($id, 'payment_' . $payment_status, "Pedido #$order_id. Puestos liberados.", $b->event_id);
    }

    /** Full refund: booking and QR become invalid, reminders stop. */
    public static function refunded($id, $order_id, $full = true) {
        global $wpdb;
        $b = self::get($id);
        if (!$b) return;
        if ($full) {
            $wpdb->update(self::table(), ['status' => 'cancelled', 'payment_status' => 'refunded', 'updated_at' => KM_Util::utc_mysql()], ['id' => $id]);
            self::log($id, 'refunded', "Devolución total del pedido #$order_id. QR anulado.", $b->event_id);
            KM_Notify::booking_cancelled($id, 'Tu pago fue devuelto.');
        } else {
            $wpdb->update(self::table(), ['payment_status' => 'partial_refund', 'updated_at' => KM_Util::utc_mysql()], ['id' => $id]);
            self::incident($id, $order_id, 'partial_refund', "Devolución parcial en pedido #$order_id. Revisar personas, mesas y promociones de la reserva {$b->reservation_code}.");
        }
    }

    /** Cron: releases unpaid holds. */
    public static function expire_holds() {
        global $wpdb;
        $t = self::table();
        $ids = $wpdb->get_col("SELECT id FROM $t WHERE status = 'pending_payment' AND hold_expires IS NOT NULL AND hold_expires <= UTC_TIMESTAMP()");
        foreach ($ids as $id) {
            $n = $wpdb->query($wpdb->prepare("UPDATE $t SET status = 'expired', payment_status = 'abandoned', updated_at = %s WHERE id = %d AND status = 'pending_payment'", KM_Util::utc_mysql(), $id));
            if ($n) self::log($id, 'hold_expired', 'Pago no completado a tiempo; mesas liberadas.');
        }
        return count($ids);
    }

    // ------------------------------------------------------------------
    // Changes from the panel
    // ------------------------------------------------------------------

    public static function update(array $in, $id) {
        global $wpdb;
        $b = self::get($id);
        if (!$b) return new WP_Error('km_404', 'Reserva no encontrada.');
        $d = [];
        if (isset($in['name'])) $d['client_name'] = sanitize_text_field($in['name']);
        if (isset($in['email'])) $d['client_email'] = sanitize_email($in['email']);
        if (isset($in['phone'])) $d['phone'] = KM_Util::normalize_phone($in['phone'], '+56');
        if (isset($in['time'])) $d['booking_time'] = sanitize_text_field($in['time']);
        if (isset($in['notes'])) $d['notes'] = sanitize_textarea_field($in['notes']);
        if (isset($in['sector']) && !$b->is_event) $d['salon_type'] = sanitize_text_field($in['sector']);
        if (isset($in['payment_status']) && in_array($in['payment_status'], ['paid', 'onsite', 'courtesy', 'pending', 'refunded'], true) && $in['payment_status'] !== $b->payment_status) {
            $d['payment_status'] = $in['payment_status'];
        }
        $guests = isset($in['guests']) ? max(1, (int) $in['guests']) : (int) $b->guests;
        if ($guests !== (int) $b->guests) $d['guests'] = $guests;
        $date = isset($in['date']) && !$b->is_event ? KM_Util::parse_date($in['date']) : $b->booking_date;
        if ($date && $date !== $b->booking_date) $d['booking_date'] = $date;

        $sel_given = isset($in['seats']) || isset($in['tables']);
        $current = KM_Floor::booking_seats($b);
        $wanted = $sel_given ? KM_Floor::parse_seats(self::selection($in)) : $current;
        $need_check = $wanted !== $current || isset($d['guests']) || isset($d['booking_date']);
        $active = in_array($b->status, ['confirmed', 'conflict', 'pending_payment'], true);

        if (!KM_Util::lock('date_' . $date, 10)) return new WP_Error('km_busy', 'Sistema ocupado, reintenta.');
        try {
            if ($need_check && $active) {
                if ($wanted) {
                    $v = KM_Floor::validate($date, $wanted, $guests, $b->id, empty($in['override_rules']));
                    if (is_wp_error($v)) return $v;
                    if ($v['seats'] !== $current) {
                        $d['tables_data'] = wp_json_encode(array_map('strval', $v['ids']));
                        $d['seats_data'] = wp_json_encode($v['seats']);
                    }
                } elseif ($b->is_event) {
                    return new WP_Error('km_tables', 'La reserva del evento necesita al menos un puesto.');
                } elseif ($current) {
                    $d['tables_data'] = '[]';
                    $d['seats_data'] = '[]';
                }
                if ($guests < (int) $b->arrived_guests) return new WP_Error('km_guests', 'Ya ingresaron ' . $b->arrived_guests . ' personas; no se puede reducir por debajo de eso.');
            }
            if (isset($in['status']) && $in['status'] !== $b->status) {
                if ($in['status'] === 'confirmed' && !$active) {
                    $seats = isset($d['seats_data']) ? KM_Floor::parse_seats($d['seats_data']) : $current;
                    if ($seats) {
                        $v = KM_Floor::validate($date, $seats, $guests, $b->id, false);
                        if (is_wp_error($v)) return $v;
                    }
                }
                if (in_array($in['status'], ['confirmed', 'cancelled'], true)) $d['status'] = $in['status'];
                if (($d['status'] ?? '') === 'confirmed') {
                    $d['qr_token'] = $b->qr_token ?: KM_Util::random_token();
                    $d['confirmed_at'] = $b->confirmed_at ?: KM_Util::utc_mysql();
                    $d['hold_expires'] = null;
                }
            }
            if (!$d) return $b;
            $d['updated_at'] = KM_Util::utc_mysql();
            $wpdb->update(self::table(), $d, ['id' => $b->id]);
        } finally {
            KM_Util::unlock('date_' . $date);
        }

        $changes = [];
        foreach ($d as $k => $v) {
            if (in_array($k, ['updated_at', 'qr_token', 'confirmed_at', 'hold_expires'], true)) continue;
            $changes[] = $k . ': ' . (is_null($b->$k) ? '—' : $b->$k) . ' → ' . $v;
        }
        self::log($b->id, 'updated', implode(' | ', $changes), $b->event_id);

        if (($d['status'] ?? '') === 'cancelled') KM_Notify::booking_cancelled($b->id, sanitize_text_field($in['reason'] ?? ''));
        elseif (($d['status'] ?? '') === 'confirmed') KM_Notify::booking_confirmed($b->id);
        elseif (!empty($in['notify']) && (isset($d['tables_data']) || isset($d['booking_date']) || isset($d['booking_time']))) KM_Notify::booking_changed($b->id);
        return self::get($b->id);
    }

    public static function move_event_bookings($event_id, $old_date, $new_date) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE event_id = %d', $event_id));
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET booking_date = %s, updated_at = %s WHERE event_id = %d', $new_date, KM_Util::utc_mysql(), $event_id));
        foreach ($ids as $id) {
            self::log($id, 'event_date_changed', "$old_date → $new_date", $event_id);
            $b = self::get($id);
            if ($b->status === 'confirmed') KM_Notify::booking_changed($id);
        }
    }

    public static function cancel_event_bookings($event_id, $reason, $notify) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE event_id = %d AND status IN ('confirmed','conflict','pending_payment')", $event_id));
        foreach ($rows as $b) {
            $wpdb->update(self::table(), ['status' => 'cancelled', 'hold_expires' => null, 'updated_at' => KM_Util::utc_mysql()], ['id' => $b->id]);
            self::log($b->id, 'cancelled_by_event', $reason, $event_id);
            if ($b->payment_status === 'paid') {
                self::incident($b->id, $b->wc_order_id, 'refund_pending', "Evento cancelado: devolver " . KM_Util::money($b->advance_payment) . " a {$b->client_name} (reserva {$b->reservation_code}, pedido #{$b->wc_order_id}).");
            }
            if ($notify && $b->status === 'confirmed') KM_Notify::booking_cancelled($b->id, $reason);
        }
        return count($rows);
    }

    // ------------------------------------------------------------------
    // Reception
    // ------------------------------------------------------------------

    private static function record_checkin_row($id, $qty, $note) {
        global $wpdb;
        $wpdb->insert(KM_Util::table('checkins'), [
            'booking_id' => $id, 'qty' => $qty, 'note' => $note,
            'user_id' => get_current_user_id(), 'user_name' => KM_Util::operator_name(), 'created_at' => KM_Util::utc_mysql(),
        ]);
    }

    /**
     * Registers arrivals atomically: two receptionists scanning the same QR cannot exceed the group size.
     */
    public static function checkin($id, $qty, $note = '') {
        global $wpdb;
        $qty = (int) $qty;
        $b = self::get($id);
        if (!$b) return new WP_Error('km_404', 'Reserva no encontrada.');
        $v = self::entry_validity($b);
        if (!$v['ok']) return new WP_Error('km_invalid', $v['message']);
        if ($qty < 1) return new WP_Error('km_qty', 'Indica cuántas personas ingresan.');
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET arrived_guests = arrived_guests + %d, updated_at = %s WHERE id = %d AND status = 'confirmed' AND arrived_guests + %d <= guests",
            $qty, KM_Util::utc_mysql(), $id, $qty
        ));
        if (!$n) {
            $b = self::get($id);
            $left = max(0, $b->guests - $b->arrived_guests);
            return new WP_Error('km_full', $left ? "Solo faltan $left persona(s) por ingresar." : 'Este QR ya registró el ingreso de todo el grupo.');
        }
        self::record_checkin_row($id, $qty, $note);
        self::log($id, 'checkin', "+$qty ingreso(s)" . ($note ? " · $note" : ''), $b->event_id);
        return self::get($id);
    }

    /** Corrects an arrival mistake (negative delta) with traceability. */
    public static function checkin_correct($id, $delta, $note) {
        global $wpdb;
        $delta = (int) $delta;
        if ($delta === 0 || trim($note) === '') return new WP_Error('km_note', 'Indica la cantidad y el motivo de la corrección.');
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET arrived_guests = arrived_guests + %d, updated_at = %s WHERE id = %d AND arrived_guests + %d BETWEEN 0 AND guests',
            $delta, KM_Util::utc_mysql(), $id, $delta
        ));
        if (!$n) return new WP_Error('km_range', 'La corrección deja un número de ingresos fuera de rango.');
        self::record_checkin_row($id, $delta, 'Corrección: ' . $note);
        self::log($id, 'checkin_correction', "$delta · $note");
        return self::get($id);
    }

    public static function checkins($id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT qty, note, user_name, created_at FROM ' . KM_Util::table('checkins') . ' WHERE booking_id = %d ORDER BY id ASC', (int) $id));
        foreach ($rows as $r) {
            $dt = KM_Util::from_utc_mysql($r->created_at);
            $r->time = $dt ? $dt->format('d/m H:i') : '';
        }
        return $rows;
    }

    /** Whether a QR may be used for entry today. */
    public static function entry_validity($b) {
        if ($b->status === 'cancelled') return ['ok' => false, 'code' => 'cancelled', 'message' => 'Reserva CANCELADA' . ($b->payment_status === 'refunded' ? ' (pago devuelto)' : '') . '. No permite ingreso.'];
        if ($b->status === 'expired') return ['ok' => false, 'code' => 'expired', 'message' => 'Reserva no pagada (retención vencida). No permite ingreso.'];
        if ($b->status === 'pending_payment') return ['ok' => false, 'code' => 'pending', 'message' => 'Pago PENDIENTE. No confirmar ingreso hasta verificar el pago.'];
        if ($b->status === 'conflict') return ['ok' => false, 'code' => 'conflict', 'message' => 'Reserva con CONFLICTO de mesas (pago tardío). Consultar con administración.'];
        $today = KM_Util::now();
        $d = $b->booking_date;
        $yesterday = $today->modify('-1 day')->format('Y-m-d');
        $valid_day = $d === $today->format('Y-m-d') || ($d === $yesterday && (int) $today->format('H') < 6);
        if (!$valid_day) return ['ok' => false, 'code' => 'other_date', 'message' => 'Este QR es para el ' . KM_Util::date_human($d) . ($b->event_name ? ' (' . $b->event_name . ')' : '') . ', no para hoy.'];
        if ((int) $b->arrived_guests >= (int) $b->guests) return ['ok' => false, 'code' => 'used', 'message' => 'QR YA UTILIZADO: ingresó todo el grupo.'];
        return ['ok' => true, 'code' => 'ok', 'message' => ''];
    }

    // ------------------------------------------------------------------
    // Promotions attached to a booking
    // ------------------------------------------------------------------

    public static function promos($id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . KM_Util::table('booking_promos') . ' WHERE booking_id = %d', (int) $id));
    }

    public static function redeem_promo($line_id, $qty) {
        global $wpdb;
        $qty = (int) $qty;
        $t = KM_Util::table('booking_promos');
        $line = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $line_id));
        if (!$line) return new WP_Error('km_404', 'Promoción no encontrada.');
        $b = self::get($line->booking_id);
        if (!$b || $b->status !== 'confirmed' || !in_array($b->payment_status, ['paid', 'manual', 'onsite', 'courtesy', 'none'], true)) {
            return new WP_Error('km_invalid', 'La reserva no está confirmada y pagada; no se puede canjear.');
        }
        if ($qty === 0) return new WP_Error('km_qty', 'Cantidad no válida.');
        if ($qty < 0 && !KM_Util::can_manage()) return new WP_Error('km_perm', 'Solo administración puede revertir canjes.');
        $n = $wpdb->query($wpdb->prepare("UPDATE $t SET redeemed = redeemed + %d WHERE id = %d AND redeemed + %d BETWEEN 0 AND qty", $qty, $line->id, $qty));
        if (!$n) return new WP_Error('km_full', 'Cantidad mayor a la disponible para canjear (' . ($line->qty - $line->redeemed) . ').');
        self::log($b->id, $qty > 0 ? 'promo_redeemed' : 'promo_unredeemed', "{$line->name}: " . ($qty > 0 ? '+' : '') . $qty);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $line->id));
    }

    // ------------------------------------------------------------------
    // Queries for panel and exports
    // ------------------------------------------------------------------

    public static function search(array $f, $limit = 500) {
        global $wpdb;
        $t = self::table();
        $where = ['1=1'];
        $args = [];
        if (!empty($f['date'])) { $where[] = 'booking_date = %s'; $args[] = KM_Util::parse_date($f['date']); }
        if (!empty($f['from'])) { $where[] = 'booking_date >= %s'; $args[] = KM_Util::parse_date($f['from']); }
        if (!empty($f['to'])) { $where[] = 'booking_date <= %s'; $args[] = KM_Util::parse_date($f['to']); }
        if (!empty($f['event_id'])) { $where[] = 'event_id = %d'; $args[] = (int) $f['event_id']; }
        if (!empty($f['sector'])) { $where[] = 'salon_type = %s'; $args[] = sanitize_text_field($f['sector']); }
        if (!empty($f['status'])) {
            if ($f['status'] === 'active') $where[] = "status IN ('confirmed','conflict','pending_payment')";
            else { $where[] = 'status = %s'; $args[] = sanitize_key($f['status']); }
        } else {
            $where[] = "status != 'expired'";
        }
        if (!empty($f['payment_status'])) { $where[] = 'payment_status = %s'; $args[] = sanitize_key($f['payment_status']); }
        if (!empty($f['q'])) {
            $q = '%' . $wpdb->esc_like(trim($f['q'])) . '%';
            $digits = preg_replace('/\D/', '', $f['q']);
            $where[] = '(client_name LIKE %s OR client_email LIKE %s OR reservation_code LIKE %s' . ($digits ? ' OR phone LIKE %s' : '') . ')';
            array_push($args, $q, $q, $q);
            if ($digits) $args[] = '%' . $digits . '%';
        }
        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where) . ' ORDER BY booking_date DESC, booking_time ASC, id DESC LIMIT ' . (int) $limit;
        return $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql);
    }

    public static function attendance_label($b) {
        $a = (int) $b->arrived_guests;
        if ($a <= 0) return 'Sin llegada';
        if ($a >= (int) $b->guests) return 'Llegaron todos';
        return "Parcial $a/{$b->guests}";
    }

    /** Seat/table selection from request input ("seats" wins over the V30 "tables" key). */
    private static function selection(array $in) {
        $s = $in['seats'] ?? ($in['tables'] ?? []);
        return is_array($s) ? array_map('strval', $s) : (string) $s;
    }

    public static function to_array($b, $full = false) {
        $seats = KM_Floor::booking_seats($b);
        $tables = KM_Floor::tables_of($seats);
        $a = [
            'seats' => $seats, 'place' => KM_Floor::booking_place($b),
            'id' => (int) $b->id, 'code' => $b->reservation_code, 'name' => $b->client_name,
            'email' => (string) $b->client_email, 'phone' => (string) $b->phone, 'phone_display' => KM_Util::phone_display($b->phone),
            'date' => $b->booking_date, 'date_human' => KM_Util::date_human($b->booking_date), 'time' => (string) $b->booking_time,
            'guests' => (int) $b->guests, 'arrived' => (int) $b->arrived_guests, 'attendance' => self::attendance_label($b),
            'tables' => $tables, 'sector' => (string) $b->salon_type, 'is_event' => (int) $b->is_event,
            'event_id' => (int) $b->event_id, 'event_name' => (string) $b->event_name,
            'status' => $b->status, 'payment_status' => $b->payment_status, 'source' => $b->source,
            'total' => (float) $b->total_amount, 'paid' => (float) $b->advance_payment, 'unit_price' => (float) $b->unit_price,
            'promos_total' => (float) $b->promos_total, 'payment_mode' => $b->payment_mode, 'min_consumption' => (float) $b->min_consumption,
            'order_id' => (int) $b->wc_order_id, 'notes' => (string) $b->notes,
            'order_url' => $b->wc_order_id && current_user_can('edit_shop_orders') ? admin_url('post.php?post=' . (int) $b->wc_order_id . '&action=edit') : '',
        ];
        if ($full) {
            $a['promos'] = array_map(function ($p) {
                return ['id' => (int) $p->id, 'name' => $p->name, 'qty' => (int) $p->qty, 'redeemed' => (int) $p->redeemed, 'unit' => (float) $p->unit_price];
            }, self::promos($b->id));
            $a['checkins'] = self::checkins($b->id);
            $a['history'] = self::history($b->id);
            $a['validity'] = self::entry_validity($b);
            $a['voucher_url'] = $b->qr_token ? KM_Util::voucher_url($b) : '';
            $a['messages'] = KM_Notify::messages_for($b->id);
        }
        return $a;
    }

    public static function stats_for_date($date) {
        global $wpdb;
        $t = self::table();
        $r = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n, COALESCE(SUM(guests),0) g, COALESCE(SUM(arrived_guests),0) a FROM $t WHERE booking_date = %s AND status IN ('confirmed','conflict')", $date));
        return ['bookings' => (int) $r->n, 'guests' => (int) $r->g, 'arrived' => (int) $r->a, 'free_tables' => count(KM_Floor::free_tables($date)), 'total_tables' => KM_Floor::total_tables(),
            'free_seats' => KM_Floor::free_seat_count($date), 'total_seats' => KM_Floor::total_seats()];
    }
}
