<?php
if (!defined('ABSPATH')) exit;

/**
 * Floor plan, seats, availability and selection rules.
 *
 * Bookings hold individual seats ("12-3" = table 12, seat 3). In "table" mode a booking
 * still stores seats, but always every seat of each table it uses. V30 bookings that only
 * stored tables are read as holding all seats of those tables.
 */
class KM_Floor {

    const MAX_TABLES_PER_BOOKING = 4;

    /** Original V30 coordinates (40 tables). */
    public static function default_plan() {
        $raw = [
            [1,198.7,396.4,2],[2,197.5,461.9,2],[3,361.1,395.3,4],[4,415.3,492.9,4],[5,495.9,583.8,4],[6,358.4,581.3,2],
            [7,196.5,526.1,2],[8,332.1,680.1,2],[9,197.1,593.4,4],[10,202.2,733.5,4],[11,199.6,663.2,4],[12,202.6,794.1,2],
            [13,203.2,925.0,4],[14,202.6,855.8,4],[15,203.8,987.9,2],[16,206.2,1051.8,4],[17,465.0,675.9,4],[18,465.0,745.0,4],
            [19,556.0,675.9,4],[20,556.5,743.8,4],[21,331.0,743.8,2],[22,328.6,886.8,2],[23,328.7,945.2,2],[24,445.0,808.6,2],
            [25,486.2,864.4,4],[26,582.4,873.0,4],[27,599.0,806.4,2],[28,485.6,937.6,2],[29,486.3,996.5,2],[30,549.2,936.4,2],
            [31,608.0,937.7,2],[32,551.7,996.6,2],[33,610.4,997.0,2],[34,548.4,1122.5,2],[35,609.6,1123.5,2],[36,727.2,796.1,2],
            [37,729.0,852.7,2],[38,725.8,435.5,4],[39,652.6,481.0,4],[40,722.6,526.4,4],
        ];
        $plan = [];
        foreach ($raw as $r) {
            $plan[] = ['id' => $r[0], 'x' => $r[1], 'y' => $r[2], 't' => $r[3] === 2 ? 't2' : 't4', 'c' => $r[3], 'sector' => '', 'active' => 1, 'neighbors' => []];
        }
        return $plan;
    }

    public static function plan($only_active = false) {
        static $cache = null;
        if ($cache === null) {
            $saved = get_option('km_floor_plan');
            $cache = (is_array($saved) && $saved) ? $saved : self::default_plan();
            foreach ($cache as &$t) {
                $t['id'] = (int) $t['id'];
                $t['c'] = max(1, (int) $t['c']);
                $t['t'] = $t['c'] <= 2 ? 't2' : 't4';
                $t['active'] = isset($t['active']) ? (int) $t['active'] : 1;
                $t['sector'] = isset($t['sector']) ? (string) $t['sector'] : '';
                $t['neighbors'] = isset($t['neighbors']) ? array_values(array_map('intval', (array) $t['neighbors'])) : [];
            }
            unset($t);
        }
        if (!$only_active) return $cache;
        return array_values(array_filter($cache, function ($t) { return $t['active']; }));
    }

    public static function save_plan(array $tables) {
        $current = [];
        foreach (self::plan() as $t) $current[$t['id']] = $t;
        foreach ($tables as $in) {
            $id = (int) ($in['id'] ?? 0);
            if (!isset($current[$id])) continue;
            $current[$id]['c'] = max(1, min(12, (int) ($in['c'] ?? $current[$id]['c'])));
            $current[$id]['sector'] = sanitize_text_field($in['sector'] ?? '');
            $current[$id]['active'] = empty($in['active']) ? 0 : 1;
            $n = is_array($in['neighbors'] ?? null) ? $in['neighbors'] : preg_split('/[\s,;]+/', (string) ($in['neighbors'] ?? ''));
            $current[$id]['neighbors'] = array_values(array_filter(array_map('intval', $n), function ($v) use ($id, $current) { return $v !== $id && isset($current[$v]); }));
        }
        update_option('km_floor_plan', array_values($current), false);
    }

    public static function table_map() {
        $m = [];
        foreach (self::plan() as $t) $m[$t['id']] = $t;
        return $m;
    }

    public static function total_tables() {
        return count(self::plan(true));
    }

    public static function total_seats() {
        return array_sum(array_column(self::plan(true), 'c'));
    }

    public static function seat_mode() {
        return KM_Util::setting('booking_unit') !== 'table';
    }

    // ------------------------------------------------------------------
    // Seat geometry (single source for the public map, panel and print)
    // ------------------------------------------------------------------

    /**
     * Table size and chair offsets for a table of $c seats. Seat 1 is the first chair clockwise
     * from the top (or the left chair on two-seat tables).
     * @return array ['w' => float, 'h' => float, 'seats' => [[dx, dy], ...]]
     */
    public static function layout($c) {
        $c = max(1, (int) $c);
        $gap = 7;
        if ($c <= 2) {
            $w = 30; $h = 42;
            $seats = $c === 1 ? [[$w / 2 + $gap, 0]] : [[-$w / 2 - $gap, 0], [$w / 2 + $gap, 0]];
            return ['w' => $w, 'h' => $h, 'seats' => $seats];
        }
        if ($c <= 4) {
            $w = 42; $h = 42;
            $all = [[0, -$h / 2 - $gap], [$w / 2 + $gap, 0], [0, $h / 2 + $gap], [-$w / 2 - $gap, 0]];
            if ($c === 3) $all = [$all[0], $all[1], $all[3]];
            return ['w' => $w, 'h' => $h, 'seats' => $all];
        }
        $side = (int) ceil(($c - 2) / 2);
        $w = max(42, 22 * $side + 8);
        $h = 42;
        $seats = [];
        $step = $w / $side;
        for ($i = 0; $i < $side; $i++) $seats[] = [-$w / 2 + $step * ($i + 0.5), -$h / 2 - $gap];
        $seats[] = [$w / 2 + $gap, 0];
        $bottom = $c - 2 - $side;
        $stepB = $bottom ? $w / $bottom : 0;
        for ($i = $bottom - 1; $i >= 0; $i--) $seats[] = [-$w / 2 + $stepB * ($i + 0.5), $h / 2 + $gap];
        $seats[] = [-$w / 2 - $gap, 0];
        return ['w' => $w, 'h' => $h, 'seats' => $seats];
    }

    /** Plan with geometry for the JavaScript maps. */
    public static function plan_for_js($only_active = true) {
        return array_map(function ($t) {
            $l = self::layout($t['c']);
            return ['id' => $t['id'], 'x' => $t['x'], 'y' => $t['y'], 'c' => $t['c'], 'w' => $l['w'], 'h' => $l['h'],
                'seats' => array_map(function ($p) { return [round($p[0], 1), round($p[1], 1)]; }, $l['seats']),
                'n' => $t['neighbors'], 'sector' => $t['sector'], 'active' => $t['active']];
        }, self::plan($only_active));
    }

    // ------------------------------------------------------------------
    // Seat ids
    // ------------------------------------------------------------------

    public static function seat_id($table, $n) {
        return (int) $table . '-' . (int) $n;
    }

    public static function split_seat($seat) {
        $p = explode('-', (string) $seat);
        return [(int) $p[0], (int) ($p[1] ?? 0)];
    }

    public static function table_seats($table) {
        $map = self::table_map();
        if (!isset($map[(int) $table])) return [];
        $out = [];
        for ($n = 1; $n <= $map[(int) $table]['c']; $n++) $out[] = self::seat_id($table, $n);
        return $out;
    }

    /**
     * Normalises a selection. Accepts seat ids ("12-3") and table numbers (12 = every seat of table 12),
     * as an array, a JSON string or a comma separated string. Unknown tables and seats are dropped.
     */
    public static function parse_seats($in) {
        if (!is_array($in)) {
            $s = trim((string) $in);
            $d = $s !== '' && ($s[0] === '[') ? json_decode($s, true) : null;
            $in = is_array($d) ? $d : preg_split('/[\s,;]+/', $s);
        }
        $map = self::table_map();
        $out = [];
        foreach ($in as $item) {
            $item = trim((string) $item);
            if ($item === '') continue;
            if (preg_match('/^(\d+)-(\d+)$/', $item, $m)) {
                $t = (int) $m[1]; $n = (int) $m[2];
                if (isset($map[$t]) && $n >= 1 && $n <= $map[$t]['c']) $out[] = self::seat_id($t, $n);
            } elseif (ctype_digit($item) && isset($map[(int) $item])) {
                $out = array_merge($out, self::table_seats((int) $item));
            }
        }
        $out = array_values(array_unique($out));
        usort($out, function ($a, $b) { $x = self::split_seat($a); $y = self::split_seat($b); return $x[0] <=> $y[0] ?: $x[1] <=> $y[1]; });
        return $out;
    }

    public static function parse_tables($json) {
        $arr = is_array($json) ? $json : json_decode((string) $json, true);
        if (!is_array($arr)) return [];
        return array_values(array_unique(array_filter(array_map('intval', $arr))));
    }

    public static function tables_of(array $seats) {
        $t = [];
        foreach ($seats as $s) $t[self::split_seat($s)[0]] = true;
        $ids = array_keys($t);
        sort($ids);
        return $ids;
    }

    /** Seats held by a booking row (V30 rows only have tables: every seat of them). */
    public static function booking_seats($b) {
        $seats = !empty($b->seats_data) ? self::parse_seats($b->seats_data) : [];
        if ($seats) return $seats;
        return self::parse_seats(array_map('strval', self::parse_tables($b->tables_data ?? '')));
    }

    /** "Mesa 12 (puestos 1 y 2)", "Mesas 12 y 13", "Mesa 12 completa + Mesa 13 (puesto 2)". */
    public static function place_label($seats) {
        $seats = is_array($seats) ? self::parse_seats($seats) : self::parse_seats((string) $seats);
        if (!$seats) return '';
        $map = self::table_map();
        $by = [];
        foreach ($seats as $s) { list($t, $n) = self::split_seat($s); $by[$t][] = $n; }
        $whole = [];
        $parts = [];
        foreach ($by as $t => $ns) {
            if (count($ns) >= ($map[$t]['c'] ?? 0)) { $whole[] = $t; continue; }
            $parts[] = 'Mesa ' . $t . ' (' . (count($ns) > 1 ? 'puestos ' : 'puesto ') . self::join_human($ns) . ')';
        }
        if ($whole) array_unshift($parts, (count($whole) > 1 ? 'Mesas ' : 'Mesa ') . self::join_human($whole));
        return implode(' + ', $parts);
    }

    private static function join_human(array $items) {
        if (count($items) < 2) return (string) reset($items);
        $last = array_pop($items);
        return implode(', ', $items) . ' y ' . $last;
    }

    /** Label for a booking row; falls back to the sector for conventional bookings. */
    public static function booking_place($b) {
        return self::place_label(self::booking_seats($b)) ?: (string) $b->salon_type;
    }

    // ------------------------------------------------------------------
    // Blocked (out of service) tables per date
    // ------------------------------------------------------------------

    public static function blocked($date) {
        $all = get_option('km_blocked_tables', []);
        return isset($all[$date]) ? array_map('intval', (array) $all[$date]) : [];
    }

    public static function set_blocked($date, array $ids) {
        $all = get_option('km_blocked_tables', []);
        $all = is_array($all) ? $all : [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids) $all[$date] = $ids; else unset($all[$date]);
        $today = KM_Util::today();
        foreach (array_keys($all) as $d) if ($d < $today) unset($all[$d]);
        update_option('km_blocked_tables', $all, false);
    }

    // ------------------------------------------------------------------
    // Occupancy
    // ------------------------------------------------------------------

    /** SQL condition for bookings that currently hold seats. */
    public static function active_sql($alias = '') {
        $p = $alias ? $alias . '.' : '';
        return "({$p}status IN ('confirmed','conflict') OR ({$p}status = 'pending_payment' AND {$p}hold_expires > UTC_TIMESTAMP()))";
    }

    private static function active_rows($date, $exclude_booking) {
        global $wpdb;
        $b = KM_Util::table('bookings');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, tables_data, seats_data, status, payment_status, client_name, guests, arrived_guests, event_name FROM $b WHERE booking_date = %s AND id != %d AND " . self::active_sql(),
            $date, (int) $exclude_booking
        ));
    }

    private static function row_info($r) {
        return [
            'state'   => $r->status === 'pending_payment' ? 'hold' : ($r->status === 'conflict' ? 'conflict' : 'paid'),
            'booking' => (int) $r->id,
            'name'    => $r->client_name,
            'guests'  => (int) $r->guests,
            'arrived' => (int) $r->arrived_guests,
            'payment' => $r->payment_status,
        ];
    }

    /** @return array seat id => booking info */
    public static function seat_occupancy($date, $exclude_booking = 0) {
        $out = [];
        foreach (self::active_rows($date, $exclude_booking) as $r) {
            $info = self::row_info($r);
            foreach (self::booking_seats($r) as $s) $out[$s] = $info;
        }
        return $out;
    }

    /**
     * Tables with at least one held seat.
     * @return array id => booking info of the first booking + taken, capacity, full, seats (n => booking id), bookings (list)
     */
    public static function occupancy($date, $exclude_booking = 0) {
        $map = self::table_map();
        $out = [];
        foreach (self::seat_occupancy($date, $exclude_booking) as $seat => $info) {
            list($t, $n) = self::split_seat($seat);
            if (!isset($map[$t])) continue;
            if (!isset($out[$t])) $out[$t] = $info + ['taken' => 0, 'capacity' => $map[$t]['c'], 'full' => false, 'seats' => [], 'seat_states' => [], 'bookings' => [], 'who' => []];
            $out[$t]['taken']++;
            $out[$t]['seats'][$n] = $info['booking'];
            $out[$t]['seat_states'][$n] = $info['state'];
            if (!in_array($info['booking'], $out[$t]['bookings'], true)) {
                $out[$t]['bookings'][] = $info['booking'];
                $out[$t]['who'][] = $info;
            }
            $out[$t]['full'] = $out[$t]['taken'] >= $out[$t]['capacity'];
        }
        return $out;
    }

    /** Free seat numbers per bookable table (active, not blocked). */
    public static function free_seats($date, $exclude_booking = 0) {
        $taken = self::seat_occupancy($date, $exclude_booking);
        $blocked = array_flip(self::blocked($date));
        $out = [];
        foreach (self::plan(true) as $t) {
            if (isset($blocked[$t['id']])) continue;
            $free = [];
            for ($n = 1; $n <= $t['c']; $n++) if (!isset($taken[self::seat_id($t['id'], $n)])) $free[] = $n;
            if ($free) $out[$t['id']] = $free;
        }
        return $out;
    }

    public static function free_seat_count($date) {
        $n = 0;
        foreach (self::free_seats($date) as $free) $n += count($free);
        return $n;
    }

    /** Seat ids nobody can take: held, blocked tables and inactive tables. */
    public static function taken_seat_ids($date, $exclude_booking = 0) {
        $ids = array_keys(self::seat_occupancy($date, $exclude_booking));
        $blocked = array_flip(self::blocked($date));
        foreach (self::plan() as $t) {
            if (!$t['active'] || isset($blocked[$t['id']])) $ids = array_merge($ids, self::table_seats($t['id']));
        }
        return array_values(array_unique($ids));
    }

    /** Tables that cannot be booked: full (seat mode), any seat held (table mode), blocked or inactive. */
    public static function unavailable_ids($date, $exclude_booking = 0) {
        $free = self::free_seats($date, $exclude_booking);
        $map = self::table_map();
        $ids = [];
        foreach ($map as $id => $t) {
            if (!isset($free[$id])) { $ids[] = $id; continue; }
            if (!self::seat_mode() && count($free[$id]) < $t['c']) $ids[] = $id;
        }
        return $ids;
    }

    /** Completely free tables (no seat held). */
    public static function free_tables($date, $exclude_booking = 0) {
        $free = self::free_seats($date, $exclude_booking);
        return array_values(array_filter(self::plan(true), function ($t) use ($free) { return isset($free[$t['id']]) && count($free[$t['id']]) === $t['c']; }));
    }

    // ------------------------------------------------------------------
    // Adjacency
    // ------------------------------------------------------------------

    private static function explicit_mode() {
        foreach (self::plan() as $t) if (!empty($t['neighbors'])) return true;
        return false;
    }

    public static function adjacent($a, $b) {
        if ($a['id'] === $b['id']) return false;
        if (self::explicit_mode()) {
            return in_array($b['id'], $a['neighbors'], true) || in_array($a['id'], $b['neighbors'], true);
        }
        $d = sqrt(pow($a['x'] - $b['x'], 2) + pow($a['y'] - $b['y'], 2));
        return $d <= (float) KM_Util::setting('adjacency_distance');
    }

    public static function connected(array $tables) {
        if (count($tables) <= 1) return true;
        $tables = array_values($tables);
        $seen = [0 => true];
        $queue = [0];
        while ($queue) {
            $i = array_shift($queue);
            foreach ($tables as $j => $t) {
                if (!isset($seen[$j]) && self::adjacent($tables[$i], $t)) {
                    $seen[$j] = true;
                    $queue[] = $j;
                }
            }
        }
        return count($seen) === count($tables);
    }

    /** Small connected groups of 1–3 tables from a candidate list. */
    private static function groups(array $tables, $max_size) {
        $n = count($tables);
        $out = [];
        foreach ($tables as $t) $out[] = [$t];
        if ($max_size >= 2) {
            for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) {
                if (self::adjacent($tables[$i], $tables[$j])) $out[] = [$tables[$i], $tables[$j]];
            }
        }
        if ($max_size >= 3 && $n <= 60) {
            for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) {
                if (!self::adjacent($tables[$i], $tables[$j])) continue;
                for ($k = $j + 1; $k < $n; $k++) {
                    if (self::adjacent($tables[$k], $tables[$i]) || self::adjacent($tables[$k], $tables[$j])) $out[] = [$tables[$i], $tables[$j], $tables[$k]];
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Suggestions
    // ------------------------------------------------------------------

    /**
     * Best free place for a group.
     * @return array ['best' => seat ids, 'best_tables' => int[], 'recommended' => table ids, 'min_waste' => int|null]
     */
    public static function suggest($date, $guests, $exclude_booking = 0) {
        $guests = max(1, (int) $guests);
        return self::seat_mode() ? self::suggest_seats($date, $guests, $exclude_booking) : self::suggest_tables($date, $guests, $exclude_booking);
    }

    /**
     * Seat mode: one table if possible (fewest seats left empty there, partly used tables first so
     * whole tables stay free for bigger groups); otherwise two or three adjacent tables.
     */
    private static function suggest_seats($date, $guests, $exclude_booking) {
        $free = self::free_seats($date, $exclude_booking);
        $map = self::table_map();
        $cands = [];
        foreach ($free as $id => $f) $cands[] = $map[$id] + ['free' => $f];
        $best = null;
        $rec = [];
        $min_waste = null;
        foreach ([1, 2, 3] as $size) {
            foreach (self::groups($cands, $size) as $g) {
                if (count($g) !== $size) continue;
                $avail = 0;
                foreach ($g as $t) $avail += count($t['free']);
                if ($avail < $guests) continue;
                $waste = $avail - $guests;
                $partial = 0;
                foreach ($g as $t) if (count($t['free']) < $t['c']) $partial++;
                $score = $size * 1000 + $waste * 10 - $partial;
                if ($best === null || $score < $best['score']) $best = ['score' => $score, 'tables' => $g];
                if ($size === 1) {
                    if ($min_waste === null || $waste < $min_waste) $min_waste = $waste;
                    $rec[$g[0]['id']] = $waste;
                }
            }
            if ($best) break;
        }
        if (!$best) return ['best' => [], 'best_tables' => [], 'recommended' => [], 'min_waste' => null];
        $seats = [];
        $need = $guests;
        foreach ($best['tables'] as $t) {
            foreach ($t['free'] as $n) {
                if ($need <= 0) break;
                $seats[] = self::seat_id($t['id'], $n);
                $need--;
            }
        }
        $allowed = max((int) KM_Util::setting('max_empty_seats'), (int) $min_waste);
        $recommended = [];
        foreach ($rec as $id => $w) if ($w <= $allowed) $recommended[] = (int) $id;
        if (!$recommended) $recommended = array_column($best['tables'], 'id');
        sort($recommended);
        return ['best' => $seats, 'best_tables' => array_column($best['tables'], 'id'), 'recommended' => $recommended, 'min_waste' => $min_waste];
    }

    private static function table_capacity(array $tables) {
        $base = 0;
        foreach ($tables as $t) $base += $t['c'];
        $extra = KM_Util::setting('extra_chair') ? 1 : 0;
        return ['base' => $base, 'max' => $base + $extra];
    }

    /** True when the combination seats the group without a superfluous table. */
    private static function combo_fits(array $tables, $guests) {
        $s = self::table_capacity($tables);
        if ($s['max'] < $guests) return false;
        $min = PHP_INT_MAX;
        foreach ($tables as $t) $min = min($min, $t['c']);
        return count($tables) === 1 || ($s['max'] - $min) < $guests;
    }

    /** Table mode: the V30 optimizer (2-tops for small groups, +1 chair, nearby tables only). */
    private static function suggest_tables($date, $guests, $exclude_booking) {
        $free = self::free_tables($date, $exclude_booking);
        $best_waste = null;
        $combos = [];
        $size = $guests > 8 ? 3 : ($guests > 4 ? 2 : 1);
        foreach ([1, 2, 3] as $max) {
            foreach (self::groups($free, $max) as $combo) {
                if (count($combo) !== $max || !self::combo_fits($combo, $guests) || !self::connected($combo)) continue;
                $s = self::table_capacity($combo);
                $waste = max(0, $s['base'] - $guests);
                $combos[] = ['ids' => array_column($combo, 'id'), 'waste' => $waste, 'score' => $waste * 10 + count($combo)];
                if ($best_waste === null || $waste < $best_waste) $best_waste = $waste;
            }
            if ($combos && $max >= $size) break;
        }
        if (!$combos) return ['best' => [], 'best_tables' => [], 'recommended' => [], 'min_waste' => null];
        usort($combos, function ($a, $b) { return $a['score'] <=> $b['score']; });
        $allowed = max((int) KM_Util::setting('max_empty_seats'), (int) $best_waste);
        $rec = [];
        foreach ($combos as $c) if ($c['waste'] <= $allowed) $rec = array_merge($rec, $c['ids']);
        $best = $combos[0]['ids'];
        return ['best' => self::parse_seats(array_map('strval', $best)), 'best_tables' => $best, 'recommended' => array_values(array_unique($rec)), 'min_waste' => (int) $best_waste];
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Validates a selection (seat ids and/or table numbers) for a date.
     * $enforce_rules=false lets managers skip the seating rules, never the double booking checks.
     * @return array|WP_Error ['ids' => tables, 'seats' => seat ids, 'capacity' => int]
     */
    public static function validate($date, $selection, $guests, $exclude_booking = 0, $enforce_rules = true) {
        $guests = (int) $guests;
        $seats = self::parse_seats($selection);
        if (!$seats) return new WP_Error('km_no_tables', self::seat_mode() ? 'Elige tus puestos en el plano.' : 'Selecciona al menos una mesa.');
        $map = self::table_map();
        $ids = self::tables_of($seats);
        foreach ($ids as $id) {
            if (empty($map[$id]['active'])) return new WP_Error('km_bad_table', "La mesa $id no existe o está fuera de servicio.");
        }
        if (count($ids) > self::MAX_TABLES_PER_BOOKING && $enforce_rules) {
            return new WP_Error('km_too_many', 'Máximo ' . self::MAX_TABLES_PER_BOOKING . ' mesas por reserva. Para grupos grandes contáctanos por WhatsApp.');
        }
        $blocked = array_values(array_intersect($ids, self::blocked($date)));
        if ($blocked) return new WP_Error('km_blocked', 'Mesa(s) ' . implode(', ', $blocked) . ' bloqueada(s) para esta fecha.');

        $occ = self::seat_occupancy($date, $exclude_booking);
        $taken = array_values(array_intersect($seats, array_keys($occ)));
        if ($taken) {
            return new WP_Error('km_taken', self::place_label($taken) . ' acaba de ser reservada por otra persona. Elige otra opción.', ['taken' => $taken, 'tables' => self::tables_of($taken)]);
        }

        $sel = array_map(function ($id) use ($map) { return $map[$id]; }, $ids);
        $whole = true;
        foreach ($ids as $id) {
            if (count(array_filter($seats, function ($s) use ($id) { return self::split_seat($s)[0] === $id; })) < $map[$id]['c']) $whole = false;
        }
        $capacity = $whole ? self::table_capacity($sel)['max'] : count($seats);

        if (!$enforce_rules) return ['ids' => $ids, 'seats' => $seats, 'capacity' => max($capacity, count($seats))];

        if (!self::connected($sel)) return new WP_Error('km_apart', self::seat_mode() ? 'Los puestos elegidos deben estar en mesas juntas.' : 'Las mesas elegidas deben estar juntas.');

        if (self::seat_mode()) {
            $n = count($seats);
            if ($n < $guests) return new WP_Error('km_capacity', "Elegiste $n " . ($n === 1 ? 'puesto' : 'puestos') . " y tu grupo es de $guests " . ($guests === 1 ? 'persona' : 'personas') . '.');
            if ($n > $guests) return new WP_Error('km_extra_seats', 'Elegiste ' . ($n - $guests) . ' ' . ($n - $guests === 1 ? 'puesto' : 'puestos') . ' de más. Quítalos para liberarlos a otros clientes.');
            return ['ids' => $ids, 'seats' => $seats, 'capacity' => $n];
        }

        if (!$whole) return new WP_Error('km_whole_table', 'Las reservas son por mesa completa.');
        $s = self::table_capacity($sel);
        if ($s['max'] < $guests) return new WP_Error('km_capacity', "Las mesas elegidas tienen {$s['max']} puestos y tu grupo es de $guests personas.");
        if (!self::combo_fits($sel, $guests)) return new WP_Error('km_extra_table', 'Sobra una mesa en tu selección. Quita una para liberar espacio a otros clientes.');
        $waste = max(0, $s['base'] - $guests);
        if ($waste > (int) KM_Util::setting('max_empty_seats')) {
            $sug = self::suggest_tables($date, $guests, $exclude_booking);
            if ($sug['min_waste'] !== null && $waste > $sug['min_waste']) {
                return new WP_Error('km_waste', 'Hay mesas más adecuadas para tu grupo (marcadas en azul).', ['recommended' => $sug['recommended']]);
            }
        }
        return ['ids' => $ids, 'seats' => $seats, 'capacity' => $s['max']];
    }

    // ------------------------------------------------------------------
    // Conventional (non-event) reservations by sector
    // ------------------------------------------------------------------

    public static function sectors() {
        $s = KM_Util::setting('conv_sectors');
        return is_array($s) ? $s : [];
    }

    public static function sector_load($date, $time, $sector, $exclude_booking = 0) {
        global $wpdb;
        $b = KM_Util::table('bookings');
        $dur = max(30, (int) KM_Util::setting('conv_duration'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT booking_time, guests FROM $b WHERE booking_date = %s AND is_event = 0 AND salon_type = %s AND id != %d AND " . self::active_sql(),
            $date, $sector, (int) $exclude_booking
        ));
        $t0 = self::minutes($time);
        $load = 0;
        foreach ($rows as $r) {
            if (abs(self::minutes($r->booking_time) - $t0) < $dur) $load += (int) $r->guests;
        }
        return $load;
    }

    public static function minutes($hhmm) {
        $p = explode(':', substr((string) $hhmm, 0, 5));
        return ((int) ($p[0] ?? 0)) * 60 + (int) ($p[1] ?? 0);
    }

    /** Time slots for conventional reservations on a date (Chile time). */
    public static function conventional_slots($date) {
        $open = self::minutes(KM_Util::setting('open_time'));
        $close = self::minutes(KM_Util::setting('close_time'));
        if ($close <= $open) $close += 24 * 60;
        $step = max(15, (int) KM_Util::setting('slot_minutes'));
        $dur = max(30, (int) KM_Util::setting('conv_duration'));

        $limit = null;
        if (KM_Util::setting('conv_block_event_nights')) {
            foreach (KM_Events::on_date($date) as $ev) {
                $first = KM_Events::arrival_slots($ev);
                $start = $first ? self::minutes($first[0]) : self::minutes($ev->event_time);
                $limit = $limit === null ? $start - $dur : min($limit, $start - $dur);
            }
        }
        $now = KM_Util::now();
        $is_today = $date === $now->format('Y-m-d');
        $now_min = (int) $now->format('H') * 60 + (int) $now->format('i') + 30;

        $slots = [];
        for ($m = $open; $m <= $close - 30; $m += $step) {
            if ($limit !== null && $m > $limit) break;
            if ($is_today && $m < $now_min) continue;
            if ($m >= 24 * 60) break;
            $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
        return $slots;
    }

    public static function validate_conventional($date, $time, $sector, $guests, $exclude_booking = 0) {
        if (!in_array($time, self::conventional_slots($date), true)) {
            return new WP_Error('km_slot', 'Ese horario no está disponible para reservas.');
        }
        $found = null;
        foreach (self::sectors() as $s) if ($s['name'] === $sector) $found = $s;
        if (!$found) return new WP_Error('km_sector', 'Selecciona un sector válido.');
        $cap = (int) $found['capacity'];
        if ($cap > 0 && self::sector_load($date, $time, $sector, $exclude_booking) + $guests > $cap) {
            return new WP_Error('km_full', "El $sector está completo en ese horario. Prueba otro horario o sector.");
        }
        return true;
    }
}
