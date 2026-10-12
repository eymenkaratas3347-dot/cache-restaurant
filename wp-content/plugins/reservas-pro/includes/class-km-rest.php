<?php
if (!defined('ABSPATH')) exit;

/**
 * REST API (namespace km/v1) used by the public booking flow, the private panel and reception.
 * Panel routes require a logged-in user with the right capability and the wp_rest nonce.
 */
class KM_Rest {

    const NS = 'km/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function route($path, $methods, $cb, $perm) {
        register_rest_route(self::NS, $path, ['methods' => $methods, 'callback' => $cb, 'permission_callback' => $perm]);
    }

    public static function routes() {
        $public = '__return_true';
        $manage = function () { return KM_Util::can_manage(); };
        $recep = function () { return KM_Util::can_reception(); };
        $config = function () { return KM_Util::can_configure(); };
        $c = __CLASS__;

        self::route('/availability', 'GET', [$c, 'availability'], $public);
        self::route('/booking', 'POST', [$c, 'booking'], $public);
        self::route('/vote/request', 'POST', [$c, 'vote_request'], $public);
        self::route('/vote/verify', 'POST', [$c, 'vote_verify'], $public);
        self::route('/whatsapp', 'GET', [$c, 'wa_verify'], $public);
        self::route('/whatsapp', 'POST', [$c, 'wa_webhook'], $public);

        self::route('/panel/dashboard', 'GET', [$c, 'dashboard'], $recep);
        self::route('/panel/events', 'GET', [$c, 'events_list'], $manage);
        self::route('/panel/events', 'POST', [$c, 'event_save'], $manage);
        self::route('/panel/events/(?P<id>\d+)', 'POST', [$c, 'event_save'], $manage);
        self::route('/panel/events/(?P<id>\d+)', 'DELETE', [$c, 'event_delete'], $manage);
        self::route('/panel/events/(?P<id>\d+)/action', 'POST', [$c, 'event_action'], $manage);
        self::route('/panel/events/(?P<id>\d+)/duplicate', 'POST', [$c, 'event_duplicate'], $manage);
        self::route('/panel/events/(?P<id>\d+)/impact', 'GET', [$c, 'event_impact'], $manage);

        self::route('/panel/bookings', 'GET', [$c, 'bookings_list'], $manage);
        self::route('/panel/bookings', 'POST', [$c, 'booking_manual'], $manage);
        self::route('/panel/bookings/(?P<id>\d+)', 'GET', [$c, 'booking_get'], $recep);
        self::route('/panel/bookings/(?P<id>\d+)', 'POST', [$c, 'booking_update'], $manage);
        self::route('/panel/bookings/(?P<id>\d+)/resend', 'POST', [$c, 'booking_resend'], $manage);

        self::route('/panel/floor', 'GET', [$c, 'floor_get'], $recep);
        self::route('/panel/floor/suggest', 'GET', [$c, 'floor_suggest'], $manage);
        self::route('/panel/floor/blocked', 'POST', [$c, 'floor_blocked'], $manage);
        self::route('/panel/floor/plan', 'POST', [$c, 'floor_plan'], $config);

        self::route('/panel/promos', 'GET', [$c, 'promos_list'], $manage);
        self::route('/panel/promos', 'POST', [$c, 'promo_save'], $manage);
        self::route('/panel/promos/(?P<id>\d+)', 'POST', [$c, 'promo_save'], $manage);
        self::route('/panel/promos/(?P<id>\d+)', 'DELETE', [$c, 'promo_delete'], $manage);

        self::route('/panel/votes', 'GET', [$c, 'votes'], $manage);
        self::route('/panel/votes/(?P<id>\d+)', 'GET', [$c, 'votes_event'], $manage);
        self::route('/panel/messages', 'GET', [$c, 'messages'], $manage);
        self::route('/panel/messages/(?P<id>\d+)/retry', 'POST', [$c, 'message_retry'], $manage);
        self::route('/panel/incidents', 'GET', [$c, 'incidents'], $manage);
        self::route('/panel/incidents/(?P<id>\d+)/resolve', 'POST', [$c, 'incident_resolve'], $manage);

        self::route('/panel/content', 'GET', [$c, 'content_get'], $manage);
        self::route('/panel/content', 'POST', [$c, 'content_save'], $manage);
        self::route('/panel/settings', 'GET', [$c, 'settings_get'], $config);
        self::route('/panel/settings', 'POST', [$c, 'settings_save'], $config);
        self::route('/panel/settings/test', 'POST', [$c, 'settings_test'], $config);

        self::route('/reception/lookup', 'GET', [$c, 'reception_lookup'], $recep);
        self::route('/reception/today', 'GET', [$c, 'reception_today'], $recep);
        self::route('/reception/checkin', 'POST', [$c, 'reception_checkin'], $recep);
        self::route('/reception/correct', 'POST', [$c, 'reception_correct'], $recep);
        self::route('/reception/redeem', 'POST', [$c, 'reception_redeem'], $recep);
    }

    private static function err($e, $status = 400) {
        return new WP_REST_Response(['ok' => false, 'code' => $e->get_error_code(), 'message' => $e->get_error_message(), 'data' => $e->get_error_data()], $status);
    }

    private static function ok($data = []) {
        return new WP_REST_Response(['ok' => true] + (array) $data, 200);
    }

    private static function out($result, $key = 'data') {
        if (is_wp_error($result)) return self::err($result);
        return self::ok([$key => $result]);
    }

    private static function rate($bucket, $limit) {
        $ip = substr(sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $key = 'km_rl_' . $bucket . '_' . md5($ip);
        $n = (int) get_transient($key);
        if ($n >= $limit) return false;
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
        return true;
    }

    // ------------------------------------------------------------------
    // Public
    // ------------------------------------------------------------------

    public static function availability(WP_REST_Request $r) {
        $guests = max(1, (int) $r->get_param('guests'));
        $event_id = (int) $r->get_param('event_id');
        nocache_headers();
        if ($event_id) {
            $ev = KM_Events::get($event_id);
            if (!$ev || !in_array($ev->status, ['published', 'hidden'], true)) return self::err(new WP_Error('km_404', 'Evento no encontrado.'), 404);
            $sug = KM_Floor::suggest($ev->event_date, $guests);
            return self::ok([
                'open' => KM_Events::booking_open($ev) && empty($ev->sold_out),
                'unit' => KM_Floor::seat_mode() ? 'seat' : 'table',
                'taken' => KM_Floor::taken_seat_ids($ev->event_date),
                'unavailable' => KM_Floor::unavailable_ids($ev->event_date),
                'recommended' => $sug['recommended'], 'best' => $sug['best'], 'best_tables' => $sug['best_tables'],
                'availability' => KM_Events::availability($ev),
                'promos' => KM_Promos::for_booking($ev->id, $ev->event_date),
            ]);
        }
        $date = KM_Util::parse_date($r->get_param('date'));
        if (!$date || $date < KM_Util::today()) return self::err(new WP_Error('km_date', 'Fecha no válida.'));
        $slots = KM_Floor::conventional_slots($date);
        $time = sanitize_text_field((string) $r->get_param('time'));
        $sectors = [];
        foreach (KM_Floor::sectors() as $s) {
            $cap = (int) $s['capacity'];
            $free = $cap > 0 && $time ? max(0, $cap - KM_Floor::sector_load($date, $time, $s['name'])) : null;
            $sectors[] = ['name' => $s['name'], 'free' => $free, 'available' => $free === null || $free >= $guests];
        }
        $events = array_map(function ($e) { return ['name' => $e->event_name, 'url' => KM_Util::event_url($e)]; }, KM_Events::on_date($date));
        return self::ok(['slots' => $slots, 'sectors' => $sectors, 'events' => $events, 'promos' => KM_Promos::for_booking(0, $date)]);
    }

    public static function booking(WP_REST_Request $r) {
        if (!self::rate('booking', 40)) return self::err(new WP_Error('km_rate', 'Demasiados intentos. Espera unos minutos.'), 429);
        $in = $r->get_json_params() ?: $r->get_body_params();
        if (!empty($in['website'])) return self::err(new WP_Error('km_spam', 'Solicitud rechazada.'));
        $b = KM_Bookings::start_web_booking((array) $in);
        if (is_wp_error($b)) return self::err($b, $b->get_error_code() === 'km_taken' ? 409 : 400);
        $url = KM_Woo::begin_checkout($b);
        if (is_wp_error($url)) return self::err($url);
        return self::ok(['redirect' => $url, 'code' => $b->reservation_code]);
    }

    public static function vote_request(WP_REST_Request $r) {
        return self::out(KM_Votes::request((array) $r->get_json_params()));
    }

    public static function vote_verify(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        return self::out(KM_Votes::verify($p['ticket'] ?? '', $p['code'] ?? ''));
    }

    public static function wa_verify(WP_REST_Request $r) {
        $token = KM_Util::setting('wa_webhook_verify');
        if ($token && $r->get_param('hub_mode') === 'subscribe' && hash_equals($token, (string) $r->get_param('hub_verify_token'))) {
            header('Content-Type: text/plain');
            echo esc_html((string) $r->get_param('hub_challenge'));
            exit;
        }
        return new WP_REST_Response('forbidden', 403);
    }

    public static function wa_webhook(WP_REST_Request $r) {
        $secret = KM_Util::setting('wa_app_secret');
        if ($secret) {
            $sig = (string) $r->get_header('x-hub-signature-256');
            if (!hash_equals('sha256=' . hash_hmac('sha256', $r->get_body(), $secret), $sig)) return new WP_REST_Response('bad signature', 403);
        }
        $body = $r->get_json_params();
        foreach ((array) ($body['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $ch) {
                if (!empty($ch['value']['statuses'])) KM_Notify::webhook_status((array) $ch['value']['statuses']);
            }
        }
        return new WP_REST_Response('ok', 200);
    }

    // ------------------------------------------------------------------
    // Panel: dashboard
    // ------------------------------------------------------------------

    public static function dashboard() {
        global $wpdb;
        $today = KM_Util::today();
        $next = KM_Events::upcoming(1);
        $next = $next ? $next[0] : null;
        $focus_date = $next && $next->event_date === $today ? $today : ($next ? $next->event_date : $today);
        $msg_err = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . KM_Util::table('messages') . " WHERE status IN ('failed','retry') AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)");
        $inc = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . KM_Util::table('incidents') . ' WHERE resolved = 0');
        return self::ok([
            'today' => $today, 'today_human' => KM_Util::date_human($today),
            'next_event' => $next ? KM_Events::to_array($next, true) : null,
            'today_stats' => KM_Bookings::stats_for_date($today),
            'focus_date' => $focus_date, 'focus_stats' => KM_Bookings::stats_for_date($focus_date),
            'messages_error' => $msg_err, 'incidents_open' => $inc,
            'can_manage' => KM_Util::can_manage(), 'can_configure' => KM_Util::can_configure(),
        ]);
    }

    // ------------------------------------------------------------------
    // Panel: events
    // ------------------------------------------------------------------

    public static function events_list() {
        return self::ok(['events' => array_map(function ($e) { return KM_Events::to_array($e); }, KM_Events::all_for_panel())]);
    }

    public static function event_save(WP_REST_Request $r) {
        $id = (int) $r->get_param('id');
        $res = KM_Events::save((array) $r->get_json_params(), $id);
        if (is_wp_error($res)) return self::err($res, $res->get_error_code() === 'km_impact' ? 409 : 400);
        return self::ok(['event' => KM_Events::to_array(KM_Events::get($res))]);
    }

    public static function event_action(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        $res = KM_Events::set_status((int) $r->get_param('id'), sanitize_key($p['action'] ?? ''), sanitize_textarea_field($p['reason'] ?? ''), !empty($p['notify']));
        return self::out($res);
    }

    public static function event_duplicate(WP_REST_Request $r) {
        $res = KM_Events::duplicate((int) $r->get_param('id'));
        if (is_wp_error($res)) return self::err($res);
        return self::ok(['event' => KM_Events::to_array(KM_Events::get($res))]);
    }

    public static function event_delete(WP_REST_Request $r) {
        return self::out(KM_Events::delete((int) $r->get_param('id')));
    }

    public static function event_impact(WP_REST_Request $r) {
        return self::ok(['impact' => KM_Events::impact((int) $r->get_param('id'))]);
    }

    // ------------------------------------------------------------------
    // Panel: bookings
    // ------------------------------------------------------------------

    public static function bookings_list(WP_REST_Request $r) {
        $rows = KM_Bookings::search($r->get_params());
        return self::ok(['bookings' => array_map(function ($b) { return KM_Bookings::to_array($b); }, $rows)]);
    }

    public static function booking_get(WP_REST_Request $r) {
        $b = KM_Bookings::get((int) $r->get_param('id'));
        if (!$b) return self::err(new WP_Error('km_404', 'Reserva no encontrada.'), 404);
        return self::ok(['booking' => KM_Bookings::to_array($b, true)]);
    }

    public static function booking_manual(WP_REST_Request $r) {
        $b = KM_Bookings::create_manual((array) $r->get_json_params());
        if (is_wp_error($b)) return self::err($b);
        return self::ok(['booking' => KM_Bookings::to_array($b, true)]);
    }

    public static function booking_update(WP_REST_Request $r) {
        $b = KM_Bookings::update((array) $r->get_json_params(), (int) $r->get_param('id'));
        if (is_wp_error($b)) return self::err($b);
        return self::ok(['booking' => KM_Bookings::to_array($b, true)]);
    }

    public static function booking_resend(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        $channel = ($p['channel'] ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email';
        $res = KM_Notify::resend((int) $r->get_param('id'), $channel);
        if (is_wp_error($res)) return self::err($res);
        KM_Bookings::log((int) $r->get_param('id'), 'resend', $channel);
        return self::ok(['sent' => (bool) $res]);
    }

    // ------------------------------------------------------------------
    // Panel: floor plan
    // ------------------------------------------------------------------

    public static function floor_get(WP_REST_Request $r) {
        $date = KM_Util::parse_date($r->get_param('date')) ?: KM_Util::today();
        $occ = KM_Floor::occupancy($date);
        foreach ($occ as &$o) { $o['seats'] = (object) $o['seats']; $o['seat_states'] = (object) $o['seat_states']; }
        unset($o);
        $plan = array_map(function ($t) { return $t + KM_Floor::layout($t['c']); }, KM_Floor::plan());
        return self::ok(['date' => $date, 'unit' => KM_Floor::seat_mode() ? 'seat' : 'table', 'plan' => $plan, 'occupancy' => (object) $occ, 'blocked' => KM_Floor::blocked($date),
            'events' => array_map(function ($e) { return KM_Events::to_array($e); }, KM_Events::on_date($date)), 'stats' => KM_Bookings::stats_for_date($date)]);
    }

    public static function floor_suggest(WP_REST_Request $r) {
        $date = KM_Util::parse_date($r->get_param('date'));
        return self::ok(KM_Floor::suggest($date, (int) $r->get_param('guests'), (int) $r->get_param('exclude')));
    }

    public static function floor_blocked(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        $date = KM_Util::parse_date($p['date'] ?? '');
        if (!$date) return self::err(new WP_Error('km_date', 'Fecha no válida.'));
        $ids = array_map('intval', (array) ($p['ids'] ?? []));
        $occ = array_intersect($ids, array_keys(KM_Floor::occupancy($date)));
        if ($occ) return self::err(new WP_Error('km_taken', 'No se pueden bloquear mesas con reservas: ' . implode(', ', $occ)));
        KM_Floor::set_blocked($date, $ids);
        KM_Bookings::log(0, 'tables_blocked', $date . ': ' . implode(', ', $ids));
        return self::ok(['blocked' => KM_Floor::blocked($date)]);
    }

    public static function floor_plan(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        KM_Floor::save_plan((array) ($p['tables'] ?? []));
        KM_Bookings::log(0, 'floor_plan_updated', count((array) ($p['tables'] ?? [])) . ' mesas');
        return self::ok(['plan' => get_option('km_floor_plan')]);
    }

    // ------------------------------------------------------------------
    // Panel: promotions, votes, messages, incidents
    // ------------------------------------------------------------------

    public static function promos_list() {
        return self::ok(['promos' => array_map(function ($p) { return KM_Promos::to_array($p); }, KM_Promos::all())]);
    }

    public static function promo_save(WP_REST_Request $r) {
        return self::out(KM_Promos::save((array) $r->get_json_params(), (int) $r->get_param('id')), 'id');
    }

    public static function promo_delete(WP_REST_Request $r) {
        return self::out(KM_Promos::delete((int) $r->get_param('id')));
    }

    public static function votes() {
        return self::ok(['results' => KM_Votes::results(), 'channels' => KM_Votes::channels()]);
    }

    public static function votes_event(WP_REST_Request $r) {
        return self::ok(['voters' => KM_Votes::voters((int) $r->get_param('id'))]);
    }

    public static function messages(WP_REST_Request $r) {
        global $wpdb;
        $t = KM_Util::table('messages');
        $b = KM_Bookings::table();
        $where = '1=1';
        $st = sanitize_key((string) $r->get_param('status'));
        if ($st === 'error') $where = "m.status IN ('failed','retry')";
        elseif ($st) $where = $wpdb->prepare('m.status = %s', $st);
        $rows = $wpdb->get_results("SELECT m.id, m.booking_id, m.channel, m.type, m.recipient, m.status, m.error, m.attempts, m.created_at, b.reservation_code, b.client_name
            FROM $t m LEFT JOIN $b b ON b.id = m.booking_id WHERE $where ORDER BY m.id DESC LIMIT 300");
        foreach ($rows as $row) {
            $dt = KM_Util::from_utc_mysql($row->created_at);
            $row->created_at = $dt ? $dt->format('d/m/Y H:i') : '';
        }
        return self::ok(['messages' => $rows, 'wa_ready' => KM_Notify::wa_ready()]);
    }

    public static function message_retry(WP_REST_Request $r) {
        return self::out(KM_Notify::retry((int) $r->get_param('id')), 'sent');
    }

    public static function incidents() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . KM_Util::table('incidents') . ' ORDER BY resolved ASC, id DESC LIMIT 200');
        foreach ($rows as $row) {
            $dt = KM_Util::from_utc_mysql($row->created_at);
            $row->created_at = $dt ? $dt->format('d/m/Y H:i') : '';
        }
        return self::ok(['incidents' => $rows]);
    }

    public static function incident_resolve(WP_REST_Request $r) {
        global $wpdb;
        $id = (int) $r->get_param('id');
        $wpdb->update(KM_Util::table('incidents'), ['resolved' => 1, 'resolved_by' => KM_Util::operator_name(), 'resolved_at' => KM_Util::utc_mysql()], ['id' => $id]);
        $inc = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . KM_Util::table('incidents') . ' WHERE id = %d', $id));
        if ($inc && $inc->booking_id) KM_Bookings::log($inc->booking_id, 'incident_resolved', $inc->type . ' ' . sanitize_text_field($r->get_param('note') ?? ''));
        return self::ok();
    }

    // ------------------------------------------------------------------
    // Panel: content with draft, preview and version history
    // ------------------------------------------------------------------

    public static function content_get() {
        $draft = get_option('km_site_draft');
        return self::ok([
            'live' => KM_Util::site(), 'draft' => is_array($draft) ? $draft : null,
            'history' => array_map(function ($h) { return ['saved_at' => $h['saved_at'], 'user' => $h['user']]; }, (array) get_option('km_site_history', [])),
            'palettes' => KM_Util::palettes(), 'preview_url' => add_query_arg('km_preview', '1', KM_Front::home_url()),
            'home_page_id' => (int) KM_Util::setting('home_page_id'), 'is_front' => (int) get_option('page_on_front') === (int) KM_Util::setting('home_page_id'),
        ]);
    }

    private static function clean_site(array $in) {
        $def = KM_Util::site_defaults();
        $out = [];
        $palettes = KM_Util::palettes();
        foreach ($def as $k => $v) {
            if (!array_key_exists($k, $in)) continue;
            $val = $in[$k];
            switch ($k) {
                case 'palette': $out[$k] = isset($palettes[$val]) ? $val : 'noche'; break;
                case 'accent': $out[$k] = sanitize_hex_color($val) ?: '#e0241b'; break;
                case 'logo': case 'hero_image': case 'maps_url': case 'carta_url': case 'instagram': $out[$k] = esc_url_raw($val); break;
                case 'gallery': $out[$k] = array_values(array_filter(array_map('esc_url_raw', (array) $val))); break;
                case 'services':
                    $out[$k] = [];
                    foreach ((array) $val as $s) {
                        $t = sanitize_text_field($s['title'] ?? '');
                        if ($t !== '') $out[$k][] = ['title' => $t, 'text' => sanitize_textarea_field($s['text'] ?? '')];
                    }
                    break;
                case 'about_text': case 'hours': case 'private_events_text': case 'footer_text': case 'seo_description': $out[$k] = sanitize_textarea_field($val); break;
                case 'contact_email': $out[$k] = sanitize_email($val); break;
                default: $out[$k] = sanitize_text_field($val);
            }
        }
        if (isset($out['palette'], $out['accent']) && !in_array($out['accent'], $palettes[$out['palette']]['accents'], true)) {
            $out['accent'] = $palettes[$out['palette']]['accents'][0];
        }
        return $out;
    }

    public static function content_save(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        $action = sanitize_key($p['action'] ?? 'draft');
        $live = get_option('km_site', []);
        $live = is_array($live) ? $live : [];
        $push_history = function ($data) {
            $h = (array) get_option('km_site_history', []);
            array_unshift($h, ['saved_at' => KM_Util::now()->format('d/m/Y H:i'), 'user' => KM_Util::operator_name(), 'data' => $data]);
            update_option('km_site_history', array_slice($h, 0, 10), false);
        };
        switch ($action) {
            case 'draft':
                update_option('km_site_draft', array_merge(KM_Util::site(), self::clean_site((array) ($p['data'] ?? []))), false);
                break;
            case 'publish':
                $draft = isset($p['data']) ? array_merge(KM_Util::site(), self::clean_site((array) $p['data'])) : get_option('km_site_draft');
                if (!is_array($draft)) return self::err(new WP_Error('km_draft', 'No hay cambios para publicar.'));
                $push_history($live);
                update_option('km_site', $draft, true);
                delete_option('km_site_draft');
                KM_Bookings::log(0, 'content_published', '');
                KM_Events::purge_cache();
                break;
            case 'discard':
                delete_option('km_site_draft');
                break;
            case 'restore':
                $h = (array) get_option('km_site_history', []);
                $i = (int) ($p['index'] ?? -1);
                if (!isset($h[$i])) return self::err(new WP_Error('km_404', 'Versión no encontrada.'));
                $push_history($live);
                update_option('km_site', $h[$i]['data'], true);
                KM_Bookings::log(0, 'content_restored', $h[$i]['saved_at']);
                KM_Events::purge_cache();
                break;
            case 'set_front':
                $pid = KM_Front::ensure_home_page();
                update_option('show_on_front', 'page');
                update_option('page_on_front', $pid);
                KM_Bookings::log(0, 'home_switched', 'Nueva portada activada (página #' . $pid . ')');
                KM_Events::purge_cache();
                break;
            default:
                return self::err(new WP_Error('km_action', 'Acción no válida.'));
        }
        KM_Util::reset_site_cache();
        return self::content_get();
    }

    // ------------------------------------------------------------------
    // Settings (administrator only)
    // ------------------------------------------------------------------

    public static function settings_get() {
        $s = KM_Util::settings();
        $s['wa_token'] = $s['wa_token'] ? '••••••••' . substr($s['wa_token'], -4) : '';
        $s['wa_app_secret'] = !empty($s['wa_app_secret']) ? '••••••••' : '';
        $products = [];
        if (function_exists('wc_get_products')) {
            foreach (wc_get_products(['limit' => 50, 'status' => 'publish']) as $p) $products[] = ['id' => $p->get_id(), 'name' => $p->get_name(), 'price' => $p->get_price()];
        }
        return self::ok(['settings' => $s, 'products' => $products, 'webhook_url' => rest_url(self::NS . '/whatsapp'),
            'gateways_url' => admin_url('admin.php?page=wc-settings&tab=checkout'), 'mail_url' => admin_url('admin.php?page=wp-mail-smtp')]);
    }

    public static function settings_save(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        $def = KM_Util::setting_defaults();
        $out = [];
        $ints = ['product_id', 'price_default', 'hold_minutes', 'min_guests', 'max_guests', 'extra_chair', 'max_empty_seats', 'adjacency_distance', 'slot_minutes', 'conv_duration', 'conv_deposit_pp', 'conv_block_event_nights', 'wa_enabled', 'email_enabled', 'home_page_id'];
        foreach ($p as $k => $v) {
            if (!array_key_exists($k, $def) && $k !== 'wa_app_secret') continue;
            if (in_array($k, ['wa_token', 'wa_app_secret'], true)) {
                if (strpos((string) $v, '••') === 0) continue;
                $out[$k] = trim(sanitize_text_field($v));
            } elseif (in_array($k, $ints, true)) {
                $out[$k] = max(0, (int) $v);
            } elseif ($k === 'conv_sectors') {
                $out[$k] = [];
                foreach ((array) $v as $s) {
                    $n = sanitize_text_field($s['name'] ?? '');
                    if ($n !== '') $out[$k][] = ['name' => $n, 'capacity' => max(0, (int) ($s['capacity'] ?? 0))];
                }
            } elseif ($k === 'alerts_email') {
                $out[$k] = sanitize_email($v);
            } elseif ($k === 'terms_default') {
                $out[$k] = sanitize_textarea_field($v);
            } elseif (in_array($k, ['open_time', 'close_time', 'reminder_2d_time', 'reminder_day_time', 'end_time_default'], true)) {
                if (KM_Util::valid_time($v)) $out[$k] = substr($v, 0, 5);
            } else {
                $out[$k] = sanitize_text_field($v);
            }
        }
        if (isset($out['booking_unit'])) $out['booking_unit'] = $out['booking_unit'] === 'table' ? 'table' : 'seat';
        if (isset($out['hold_minutes'])) $out['hold_minutes'] = max(5, min(60, $out['hold_minutes']));
        if (isset($out['max_guests'])) $out['max_guests'] = max(1, min(60, $out['max_guests']));
        KM_Util::update_settings($out);
        KM_Bookings::log(0, 'settings_updated', implode(', ', array_keys($out)));
        return self::settings_get();
    }

    public static function settings_test(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        if (($p['type'] ?? '') === 'email') {
            return self::out(KM_Notify::test_email(sanitize_email($p['to'] ?? '')), 'sent');
        }
        if (!KM_Notify::wa_ready()) return self::err(new WP_Error('km_wa', 'Completa y activa la configuración de WhatsApp primero.'));
        $to = KM_Util::normalize_phone($p['to'] ?? '');
        $res = KM_Notify::wa_request(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => 'hello_world', 'language' => ['code' => 'en_US']]]);
        return $res['ok'] ? self::ok(['sent' => true]) : self::err(new WP_Error('km_wa', $res['error']));
    }

    // ------------------------------------------------------------------
    // Reception
    // ------------------------------------------------------------------

    private static function reception_payload($b) {
        $a = KM_Bookings::to_array($b, true);
        unset($a['history']);
        return $a;
    }

    public static function reception_lookup(WP_REST_Request $r) {
        $c = trim((string) $r->get_param('c'));
        if (preg_match('/[?&]c=([a-f0-9]{16,64})/i', $c, $m)) $c = $m[1];
        $b = strlen($c) >= 16 ? KM_Bookings::by_token($c) : KM_Bookings::by_code($c);
        if (!$b) return self::err(new WP_Error('km_invalid_qr', 'QR o código NO VÁLIDO. No corresponde a ninguna reserva.'), 404);
        KM_Bookings::log($b->id, 'scanned', '');
        return self::ok(['booking' => self::reception_payload($b)]);
    }

    public static function reception_today(WP_REST_Request $r) {
        $date = KM_Util::parse_date($r->get_param('date')) ?: KM_Util::today();
        $f = ['date' => $date, 'status' => 'active'];
        if ($r->get_param('q')) $f['q'] = $r->get_param('q');
        $rows = KM_Bookings::search($f);
        return self::ok(['date' => $date, 'bookings' => array_map(function ($b) { return KM_Bookings::to_array($b); }, $rows), 'stats' => KM_Bookings::stats_for_date($date)]);
    }

    public static function reception_checkin(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        $b = KM_Bookings::checkin((int) ($p['id'] ?? 0), (int) ($p['qty'] ?? 0), sanitize_text_field($p['note'] ?? ''));
        if (is_wp_error($b)) return self::err($b, 409);
        return self::ok(['booking' => self::reception_payload($b)]);
    }

    public static function reception_correct(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        $b = KM_Bookings::checkin_correct((int) ($p['id'] ?? 0), (int) ($p['delta'] ?? 0), sanitize_text_field($p['note'] ?? ''));
        if (is_wp_error($b)) return self::err($b);
        return self::ok(['booking' => self::reception_payload($b)]);
    }

    public static function reception_redeem(WP_REST_Request $r) {
        $p = (array) $r->get_json_params();
        $line = KM_Bookings::redeem_promo((int) ($p['line_id'] ?? 0), (int) ($p['qty'] ?? 0));
        if (is_wp_error($line)) return self::err($line, 409);
        return self::ok(['booking' => self::reception_payload(KM_Bookings::get($line->booking_id))]);
    }
}
