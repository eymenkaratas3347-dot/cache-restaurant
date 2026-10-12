<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional discounted products sold together with a booking.
 * Stock is computed from active bookings, so expired holds and cancellations release units automatically.
 */
class KM_Promos {

    public static function table() {
        return KM_Util::table('promos');
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id));
    }

    public static function all() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . self::table() . " WHERE status != 'deleted' ORDER BY sort_order ASC, id DESC");
    }

    public static function sold($promo_id) {
        global $wpdb;
        $bp = KM_Util::table('booking_promos');
        $b = KM_Util::table('bookings');
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(bp.qty),0) FROM $bp bp JOIN $b b ON b.id = bp.booking_id WHERE bp.promo_id = %d AND " . KM_Floor::active_sql('b'), (int) $promo_id));
    }

    public static function redeemed($promo_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(redeemed),0) FROM ' . KM_Util::table('booking_promos') . ' WHERE promo_id = %d', (int) $promo_id));
    }

    public static function applies($p, $event_id, $date) {
        if (!$p || $p->status !== 'active') return false;
        if ($p->valid_from && $date < $p->valid_from) return false;
        if ($p->valid_to && $date > $p->valid_to) return false;
        if ($event_id) {
            $ids = array_filter(array_map('intval', explode(',', (string) $p->event_ids)));
            return !$ids || in_array((int) $event_id, $ids, true);
        }
        return (bool) $p->applies_conventional;
    }

    public static function check_available($p, $qty, $event_id, $date) {
        if (!self::applies($p, $event_id, $date)) return new WP_Error('km_promo', 'Una promoción seleccionada ya no está disponible.');
        if ($qty > (int) $p->max_per_booking) return new WP_Error('km_promo_max', "Máximo {$p->max_per_booking} unidades de \"{$p->name}\" por reserva.");
        if ($p->stock !== null && $p->stock !== '') {
            $left = (int) $p->stock - self::sold($p->id);
            if ($qty > $left) return new WP_Error('km_promo_stock', $left > 0 ? "Solo quedan $left unidades de \"{$p->name}\"." : "\"{$p->name}\" está agotada.");
        }
        return true;
    }

    public static function for_booking($event_id, $date) {
        $out = [];
        foreach (self::all() as $p) {
            if (!self::applies($p, $event_id, $date)) continue;
            $left = ($p->stock === null || $p->stock === '') ? null : max(0, (int) $p->stock - self::sold($p->id));
            if ($left === 0) continue;
            $out[] = self::to_array($p, false) + ['left' => $left];
        }
        return $out;
    }

    public static function to_array($p, $with_stats = true) {
        $a = [
            'id' => (int) $p->id, 'name' => $p->name, 'description' => (string) $p->description, 'conditions' => (string) $p->conditions,
            'image' => (string) $p->image_url, 'price' => (float) $p->price, 'regular_price' => (float) $p->regular_price,
            'max_per_booking' => (int) $p->max_per_booking,
        ];
        if ($with_stats) {
            $a += [
                'stock' => $p->stock === null ? '' : (int) $p->stock, 'valid_from' => (string) $p->valid_from, 'valid_to' => (string) $p->valid_to,
                'event_ids' => (string) $p->event_ids, 'applies_conventional' => (int) $p->applies_conventional, 'status' => $p->status,
                'sort_order' => (int) $p->sort_order, 'sold' => self::sold($p->id), 'redeemed' => self::redeemed($p->id),
            ];
        }
        return $a;
    }

    public static function save(array $in, $id = 0) {
        global $wpdb;
        $name = sanitize_text_field($in['name'] ?? '');
        if ($name === '') return new WP_Error('km_name', 'El nombre es obligatorio.');
        $price = max(0, KM_Util::to_int_amount($in['price'] ?? 0));
        $d = [
            'name' => $name,
            'description' => sanitize_textarea_field($in['description'] ?? ''),
            'conditions' => sanitize_textarea_field($in['conditions'] ?? ''),
            'image_url' => esc_url_raw($in['image'] ?? ''),
            'price' => $price,
            'regular_price' => max(0, KM_Util::to_int_amount($in['regular_price'] ?? 0)),
            'stock' => isset($in['stock']) && $in['stock'] !== '' ? max(0, (int) $in['stock']) : null,
            'max_per_booking' => max(1, min(50, (int) ($in['max_per_booking'] ?? 10))),
            'valid_from' => KM_Util::parse_date($in['valid_from'] ?? '') ?: null,
            'valid_to' => KM_Util::parse_date($in['valid_to'] ?? '') ?: null,
            'event_ids' => implode(',', array_filter(array_map('intval', preg_split('/[\s,]+/', (string) ($in['event_ids'] ?? ''))))),
            'applies_conventional' => empty($in['applies_conventional']) ? 0 : 1,
            'status' => in_array($in['status'] ?? 'active', ['active', 'paused'], true) ? $in['status'] : 'active',
            'sort_order' => (int) ($in['sort_order'] ?? 0),
            'updated_at' => KM_Util::utc_mysql(),
        ];
        if ($id) {
            $wpdb->update(self::table(), $d, ['id' => (int) $id]);
            KM_Bookings::log(0, 'promo_updated', $name . ' #' . $id);
            return (int) $id;
        }
        $d['created_at'] = KM_Util::utc_mysql();
        if (!$wpdb->insert(self::table(), $d)) return new WP_Error('km_db', 'No se pudo guardar la promoción.');
        $new_id = (int) $wpdb->insert_id;
        KM_Bookings::log(0, 'promo_created', $name . ' #' . $new_id);
        return $new_id;
    }

    /** Promotions already sold are kept for history; they are only hidden. */
    public static function delete($id) {
        global $wpdb;
        $sold = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . KM_Util::table('booking_promos') . ' WHERE promo_id = %d', (int) $id));
        if ($sold) $wpdb->update(self::table(), ['status' => 'deleted'], ['id' => (int) $id]);
        else $wpdb->delete(self::table(), ['id' => (int) $id]);
        KM_Bookings::log(0, 'promo_deleted', '#' . $id);
        return true;
    }
}
