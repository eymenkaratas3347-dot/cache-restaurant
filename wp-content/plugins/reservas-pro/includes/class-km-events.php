<?php
if (!defined('ABSPATH')) exit;

/**
 * Events: single source for cartelera, event page, booking link and availability.
 * Statuses: draft, published, hidden, cancelled, archived.
 * "Realizado" is not a stored status: it is derived from the end time in Chile.
 */
class KM_Events {

    const STATUSES = ['draft', 'published', 'hidden', 'cancelled', 'archived'];

    public static function table() {
        return KM_Util::table('events');
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id));
    }

    public static function by_slug($slug) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE slug = %s', $slug));
    }

    /** V30 links used ?fecha=Y-m-d&evento=si&nombre_evento=Name */
    public static function by_legacy($name, $date) {
        global $wpdb;
        $t = self::table();
        if ($name !== '') {
            $e = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE event_date = %s AND event_name = %s ORDER BY id DESC LIMIT 1", $date, $name));
            if ($e) return $e;
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE event_date = %s AND status NOT IN ('draft','archived')", $date));
        return count($rows) === 1 ? $rows[0] : null;
    }

    public static function unique_slug($name, $date, $exclude_id = 0) {
        global $wpdb;
        $base = sanitize_title($name) ?: 'evento';
        $slug = $base;
        $i = 0;
        while ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE slug = %s AND id != %d', $slug, (int) $exclude_id))) {
            $i++;
            $slug = $i === 1 && $date ? $base . '-' . str_replace('-', '', substr($date, 5)) : $base . '-' . $i;
        }
        return $slug;
    }

    public static function arrival_slots($ev) {
        $raw = $ev->arrival_slots ?: KM_Util::setting('arrival_slots_default');
        $slots = array_filter(array_map('trim', explode(',', (string) $raw)), [KM_Util::class, 'valid_time']);
        sort($slots);
        return array_values($slots);
    }

    public static function show_time($ev) {
        return substr((string) $ev->event_time, 0, 5);
    }

    public static function start_dt($ev) {
        return KM_Util::local_dt($ev->event_date, self::show_time($ev));
    }

    /** End of the night. If the closing time is earlier than the show, it ends the next day. */
    public static function end_dt($ev) {
        $end = $ev->end_time ?: KM_Util::setting('end_time_default');
        $dt = KM_Util::local_dt($ev->event_date, $end);
        if (!$dt) $dt = KM_Util::local_dt($ev->event_date, '23:59');
        if (KM_Floor::minutes($end) <= KM_Floor::minutes(self::show_time($ev))) $dt = $dt->modify('+1 day');
        return $dt;
    }

    public static function is_past($ev, $now = null) {
        $now = $now ?: KM_Util::now();
        return $now >= self::end_dt($ev);
    }

    /** Online booking closes when the show starts. */
    public static function booking_open($ev) {
        if ($ev->status !== 'published') return false;
        $start = self::start_dt($ev);
        return $start && KM_Util::now() < $start;
    }

    public static function price($ev) {
        $p = (float) $ev->price_per_person;
        return $p >= 0 ? $p : (float) KM_Util::setting('price_default');
    }

    public static function max_guests($ev) {
        return (int) $ev->max_guests > 0 ? (int) $ev->max_guests : (int) KM_Util::setting('max_guests');
    }

    public static function availability($ev) {
        if (KM_Floor::seat_mode()) {
            $total = KM_Floor::total_seats();
            $free = KM_Floor::free_seat_count($ev->event_date);
            $sold_out = !empty($ev->sold_out) || $free < max(1, (int) KM_Util::setting('min_guests'));
            return ['total' => $total, 'free' => $free, 'sold_out' => $sold_out, 'low' => !$sold_out && $free <= 12,
                'unit' => 'seat', 'noun' => $free === 1 ? 'puesto' : 'puestos', 'low_label' => $free === 1 ? 'Último puesto' : 'Últimos puestos'];
        }
        $total = KM_Floor::total_tables();
        $free = count(KM_Floor::free_tables($ev->event_date));
        $sold_out = !empty($ev->sold_out) || $free === 0;
        return ['total' => $total, 'free' => $free, 'sold_out' => $sold_out, 'low' => !$sold_out && $free <= 5,
            'unit' => 'table', 'noun' => $free === 1 ? 'mesa' : 'mesas', 'low_label' => $free === 1 ? 'Última mesa' : 'Últimas mesas'];
    }

    /** Events listed under "Eventos por realizar". */
    public static function upcoming($limit = 50) {
        global $wpdb;
        $t = self::table();
        $from = KM_Util::now()->modify('-1 day')->format('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status = 'published' AND event_date >= %s ORDER BY event_date ASC, event_time ASC, sort_order ASC LIMIT %d", $from, $limit));
        $now = KM_Util::now();
        return array_values(array_filter($rows, function ($e) use ($now) { return !self::is_past($e, $now); }));
    }

    /** Events listed under "Eventos realizados" (cancelled and archived are excluded). */
    public static function past($limit = 24) {
        global $wpdb;
        $t = self::table();
        $to = KM_Util::now()->modify('+1 day')->format('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status = 'published' AND event_date <= %s ORDER BY event_date DESC, event_time DESC LIMIT %d", $to, $limit * 2));
        $now = KM_Util::now();
        $rows = array_values(array_filter($rows, function ($e) use ($now) { return self::is_past($e, $now); }));
        return array_slice($rows, 0, $limit);
    }

    public static function on_date($date) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE event_date = %s AND status IN ('published','hidden')", $date));
    }

    public static function all_for_panel() {
        global $wpdb;
        $t = self::table();
        $b = KM_Util::table('bookings');
        return $wpdb->get_results("SELECT e.*,
              (SELECT COUNT(*) FROM $b WHERE event_id = e.id AND status IN ('confirmed','conflict')) AS bookings_count,
              (SELECT COALESCE(SUM(guests),0) FROM $b WHERE event_id = e.id AND status IN ('confirmed','conflict')) AS guests_count
            FROM $t e WHERE e.status != 'archived' OR e.event_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
            ORDER BY e.event_date DESC, e.event_time DESC");
    }

    public static function to_array($ev, $with_availability = false) {
        $a = [
            'id'              => (int) $ev->id,
            'name'            => $ev->event_name,
            'slug'            => $ev->slug,
            'category'        => (string) ($ev->category ?? ''),
            'date'            => $ev->event_date,
            'date_human'      => KM_Util::date_human($ev->event_date),
            'show_time'       => self::show_time($ev),
            'arrival_slots'   => self::arrival_slots($ev),
            'arrival_raw'     => (string) $ev->arrival_slots,
            'end_time'        => $ev->end_time ?: '23:59',
            'description'     => (string) $ev->description,
            'conditions'      => (string) ($ev->conditions ?? ''),
            'terms'           => (string) ($ev->popup_text ?? ''),
            'poster'          => (string) ($ev->image_url ?? ''),
            'video'           => (string) ($ev->video_url ?? ''),
            'price'           => (float) $ev->price_per_person,
            'payment_mode'    => $ev->payment_mode ?: 'entrada',
            'min_consumption' => (float) $ev->min_consumption,
            'status'          => $ev->status,
            'featured'        => (int) $ev->featured,
            'sold_out'        => (int) $ev->sold_out,
            'max_guests'      => (int) $ev->max_guests,
            'tolerance_min'   => (int) $ev->tolerance_min,
            'release_min'     => (int) $ev->release_min,
            'is_past'         => self::is_past($ev),
            'url'             => KM_Util::event_url($ev),
            'booking_url'     => KM_Util::booking_url($ev),
        ];
        if (isset($ev->bookings_count)) {
            $a['bookings_count'] = (int) $ev->bookings_count;
            $a['guests_count'] = (int) $ev->guests_count;
        }
        if ($with_availability) $a['availability'] = self::availability($ev);
        return $a;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    private static function clean_input(array $in, $existing = null) {
        $d = [];
        if (isset($in['name'])) $d['event_name'] = sanitize_text_field($in['name']);
        if (isset($in['category'])) $d['category'] = mb_substr(trim(sanitize_text_field($in['category'])), 0, 40);
        if (isset($in['date'])) $d['event_date'] = KM_Util::parse_date($in['date']);
        if (isset($in['show_time'])) $d['event_time'] = KM_Util::valid_time($in['show_time']) ? substr($in['show_time'], 0, 5) . ':00' : '21:00:00';
        if (isset($in['end_time'])) $d['end_time'] = KM_Util::valid_time($in['end_time']) ? substr($in['end_time'], 0, 5) : '23:59';
        if (isset($in['arrival_slots'])) {
            $slots = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $in['arrival_slots'])), [KM_Util::class, 'valid_time']);
            $d['arrival_slots'] = implode(',', array_unique($slots));
        }
        if (isset($in['description'])) $d['description'] = wp_kses_post($in['description']);
        if (isset($in['conditions'])) $d['conditions'] = sanitize_textarea_field($in['conditions']);
        if (isset($in['terms'])) $d['popup_text'] = sanitize_textarea_field($in['terms']);
        if (isset($in['poster'])) $d['image_url'] = esc_url_raw($in['poster']);
        if (isset($in['video'])) $d['video_url'] = esc_url_raw($in['video']);
        if (isset($in['price'])) $d['price_per_person'] = max(0, KM_Util::to_int_amount($in['price']));
        if (isset($in['payment_mode'])) $d['payment_mode'] = in_array($in['payment_mode'], ['entrada', 'abono'], true) ? $in['payment_mode'] : 'entrada';
        if (isset($in['min_consumption'])) $d['min_consumption'] = max(0, KM_Util::to_int_amount($in['min_consumption']));
        if (isset($in['featured'])) $d['featured'] = empty($in['featured']) ? 0 : 1;
        if (isset($in['sold_out'])) $d['sold_out'] = empty($in['sold_out']) ? 0 : 1;
        if (isset($in['max_guests'])) $d['max_guests'] = max(0, min(60, (int) $in['max_guests']));
        if (isset($in['tolerance_min'])) $d['tolerance_min'] = max(0, min(240, (int) $in['tolerance_min']));
        if (isset($in['release_min'])) $d['release_min'] = max(0, min(240, (int) $in['release_min']));
        if (isset($in['sort_order'])) $d['sort_order'] = (int) $in['sort_order'];
        if (isset($in['status']) && in_array($in['status'], ['draft', 'published', 'hidden'], true)) $d['status'] = $in['status'];
        return $d;
    }

    /**
     * Creates or updates an event. Date changes with active bookings require $in['confirm_impact'].
     * @return int|WP_Error
     */
    public static function save(array $in, $id = 0) {
        global $wpdb;
        $t = self::table();
        $existing = $id ? self::get($id) : null;
        if ($id && !$existing) return new WP_Error('km_404', 'Evento no encontrado.');
        $d = self::clean_input($in, $existing);

        $name = $d['event_name'] ?? ($existing->event_name ?? '');
        $date = $d['event_date'] ?? ($existing->event_date ?? '');
        if ($name === '') return new WP_Error('km_name', 'El nombre del evento es obligatorio.');
        if (!KM_Util::valid_date($date)) return new WP_Error('km_date', 'La fecha no es válida.');
        if (!$existing && !isset($d['arrival_slots'])) $d['arrival_slots'] = KM_Util::setting('arrival_slots_default');
        if (!$existing && !isset($d['price_per_person'])) $d['price_per_person'] = (int) KM_Util::setting('price_default');
        if (!$existing && !isset($d['popup_text'])) $d['popup_text'] = KM_Util::setting('terms_default');

        $d['title'] = $name;
        $d['updated_at'] = KM_Util::utc_mysql();

        if (!$existing) {
            $d['slug'] = self::unique_slug($name, $date);
            $d['description'] = $d['description'] ?? '';
            $d['status'] = $d['status'] ?? 'draft';
            $d['generated_link'] = KM_Util::event_url($d['slug']);
            $wpdb->insert($t, $d);
            $new_id = (int) $wpdb->insert_id;
            if (!$new_id) return new WP_Error('km_db', 'No se pudo guardar el evento.');
            KM_Bookings::log(0, 'event_created', $name . ' (' . $date . ')', $new_id);
            self::purge_cache();
            return $new_id;
        }

        $date_changed = $date !== $existing->event_date;
        if ($date_changed) {
            $impact = self::impact($existing->id);
            if ($impact['active'] > 0) {
                if (empty($in['confirm_impact'])) {
                    return new WP_Error('km_impact', "Este evento tiene {$impact['active']} reserva(s) activas ({$impact['guests']} personas). Confirma el cambio de fecha: las reservas se moverán y se avisará a los clientes.", ['impact' => $impact]);
                }
                $conflicts = self::date_conflicts($existing->id, $date);
                if ($conflicts) {
                    return new WP_Error('km_conflict', 'En la nueva fecha ya están ocupadas las mesas ' . implode(', ', $conflicts) . '. Libera esas mesas o elige otra fecha.');
                }
            }
        }
        if (!empty($in['regenerate_slug']) || ($existing->event_name !== $name && !self::has_bookings($existing->id))) {
            $d['slug'] = self::unique_slug($name, $date, $existing->id);
        }
        $slug = $d['slug'] ?? $existing->slug;
        $d['generated_link'] = KM_Util::event_url($slug);
        $wpdb->update($t, $d, ['id' => $existing->id]);

        if ($date_changed) KM_Bookings::move_event_bookings($existing->id, $existing->event_date, $date);
        if ($existing->event_name !== $name) {
            $wpdb->update(KM_Util::table('bookings'), ['event_name' => $name], ['event_id' => $existing->id]);
        }
        $changes = array_keys(array_diff_assoc(array_map('strval', $d), array_map('strval', (array) $existing)));
        KM_Bookings::log(0, 'event_updated', implode(', ', array_diff($changes, ['updated_at', 'generated_link'])), $existing->id);
        self::purge_cache();
        return (int) $existing->id;
    }

    public static function duplicate($id) {
        $ev = self::get($id);
        if (!$ev) return new WP_Error('km_404', 'Evento no encontrado.');
        $in = self::to_array($ev);
        $in['name'] = $ev->event_name;
        $in['arrival_slots'] = $ev->arrival_slots;
        $in['date'] = KM_Util::now()->modify('+7 days')->format('Y-m-d');
        if ($ev->event_date > KM_Util::today()) $in['date'] = KM_Util::local_dt($ev->event_date)->modify('+7 days')->format('Y-m-d');
        $in['status'] = 'draft';
        $in['sold_out'] = 0;
        $in['featured'] = 0;
        $new = self::save($in);
        if (!is_wp_error($new)) KM_Bookings::log(0, 'event_duplicated', 'Copia del evento #' . $id, $new);
        return $new;
    }

    public static function has_bookings($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . KM_Util::table('bookings') . " WHERE event_id = %d AND status NOT IN ('expired')", (int) $id)) > 0;
    }

    public static function impact($id) {
        global $wpdb;
        $b = KM_Util::table('bookings');
        $r = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n, COALESCE(SUM(guests),0) g, SUM(payment_status = 'paid') paid FROM $b WHERE event_id = %d AND status IN ('confirmed','conflict','pending_payment')", (int) $id));
        return ['active' => (int) $r->n, 'guests' => (int) $r->g, 'paid' => (int) $r->paid];
    }

    private static function date_conflicts($event_id, $new_date) {
        global $wpdb;
        $b = KM_Util::table('bookings');
        $mine = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT tables_data, seats_data FROM $b WHERE event_id = %d AND " . KM_Floor::active_sql(), $event_id)) as $r) {
            $mine = array_merge($mine, KM_Floor::booking_seats($r));
        }
        $others = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT tables_data, seats_data FROM $b WHERE booking_date = %s AND event_id != %d AND " . KM_Floor::active_sql(), $new_date, $event_id)) as $r) {
            $others = array_merge($others, KM_Floor::booking_seats($r));
        }
        return KM_Floor::tables_of(array_unique(array_intersect($mine, $others)));
    }

    /**
     * publish | hide | draft | cancel | archive. Cancelling cancels its bookings, flags refunds and notifies clients.
     */
    public static function set_status($id, $action, $reason = '', $notify = true) {
        global $wpdb;
        $ev = self::get($id);
        if (!$ev) return new WP_Error('km_404', 'Evento no encontrado.');
        $map = ['publish' => 'published', 'hide' => 'hidden', 'draft' => 'draft', 'cancel' => 'cancelled', 'archive' => 'archived'];
        if (!isset($map[$action])) return new WP_Error('km_action', 'Acción no válida.');
        $status = $map[$action];
        if ($action === 'publish') {
            if (!$ev->image_url) return new WP_Error('km_poster', 'Agrega un afiche antes de publicar.');
            if (self::is_past($ev)) return new WP_Error('km_past', 'La fecha del evento ya pasó.');
        }
        if ($action === 'draft' && self::has_bookings($id)) return new WP_Error('km_has_bookings', 'El evento tiene reservas; usa "Ocultar" en lugar de borrador.');

        $wpdb->update(self::table(), ['status' => $status, 'cancel_reason' => $action === 'cancel' ? sanitize_textarea_field($reason) : $ev->cancel_reason, 'updated_at' => KM_Util::utc_mysql()], ['id' => $id]);
        KM_Bookings::log(0, 'event_' . $action, $reason, $id);

        $affected = 0;
        if ($action === 'cancel') $affected = KM_Bookings::cancel_event_bookings($id, $reason, $notify);
        self::purge_cache();
        return ['status' => $status, 'affected' => $affected];
    }

    public static function delete($id) {
        global $wpdb;
        $ev = self::get($id);
        if (!$ev) return new WP_Error('km_404', 'Evento no encontrado.');
        if (self::has_bookings($id)) return new WP_Error('km_has_bookings', 'No se puede borrar un evento con reservas: cancélalo o archívalo para conservar el historial.');
        $wpdb->delete(self::table(), ['id' => (int) $id]);
        KM_Bookings::log(0, 'event_deleted', $ev->event_name . ' (' . $ev->event_date . ')', $id);
        self::purge_cache();
        return true;
    }

    /** Clears page cache (LiteSpeed) so the cartelera reflects changes immediately. */
    public static function purge_cache() {
        do_action('litespeed_purge_all');
        do_action('km_events_changed');
    }

    /** Cron: purge cache once when an event crosses its end time so it moves to "Realizados". */
    public static function cron_transitions() {
        $last = (int) get_option('km_last_transition_check', 0);
        $now = KM_Util::now();
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE status = 'published' AND event_date BETWEEN %s AND %s",
            $now->modify('-2 days')->format('Y-m-d'), $now->format('Y-m-d')));
        $changed = false;
        foreach ($rows as $e) {
            $end = self::end_dt($e)->getTimestamp();
            if ($end > $last && $end <= $now->getTimestamp()) $changed = true;
        }
        update_option('km_last_transition_check', $now->getTimestamp(), false);
        if ($changed) self::purge_cache();
    }
}
