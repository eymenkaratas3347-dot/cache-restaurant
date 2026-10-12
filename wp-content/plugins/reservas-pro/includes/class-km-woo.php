<?php
if (!defined('ABSPATH')) exit;

/**
 * WooCommerce bridge (works with the block checkout and the classic checkout).
 * - No WordPress accounts are created or logged in: guest checkout with prefilled data.
 * - The cart line quantity is the number of people; the unit price is the event price.
 * - Order <-> booking link is stored on both sides; gateway notifications are idempotent.
 */
class KM_Woo {

    public static function init() {
        if (!class_exists('WooCommerce')) return;
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_prices'], 20);
        add_filter('woocommerce_cart_item_quantity', [__CLASS__, 'classic_qty'], 10, 3);
        add_filter('woocommerce_store_api_product_quantity_editable', [__CLASS__, 'block_qty_editable'], 10, 3);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'item_data'], 10, 2);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'check_cart']);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'line_item_meta'], 10, 4);
        add_action('woocommerce_checkout_order_created', [__CLASS__, 'link_order']);
        add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'link_order']);
        add_filter('woocommerce_get_country_locale', [__CLASS__, 'short_checkout_fields']);

        add_action('woocommerce_payment_complete', [__CLASS__, 'on_paid']);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'on_paid']);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'on_paid']);
        add_action('woocommerce_order_status_failed', [__CLASS__, 'on_failed']);
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'on_cancelled']);
        add_action('woocommerce_order_status_refunded', [__CLASS__, 'on_refunded']);
        add_action('woocommerce_order_partially_refunded', [__CLASS__, 'on_partial_refund']);
        add_action('woocommerce_order_status_on-hold', [__CLASS__, 'on_hold']);

        add_filter('woocommerce_get_checkout_order_received_url', [__CLASS__, 'thankyou_url'], 20, 2);
        add_action('woocommerce_thankyou', [__CLASS__, 'thankyou_box'], 5);
        add_action('woocommerce_email_order_meta', [__CLASS__, 'email_details'], 10, 3);
        add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'admin_order_box']);
    }

    public static function product_id() {
        return (int) KM_Util::setting('product_id');
    }

    /**
     * Puts the booking in a fresh cart and returns the checkout URL.
     * @return string|WP_Error redirect URL
     */
    public static function begin_checkout($booking) {
        if ((float) $booking->total_amount <= 0) {
            KM_Bookings::confirm_payment($booking->id, 0, 0);
            global $wpdb;
            $wpdb->update(KM_Bookings::table(), ['payment_status' => 'none'], ['id' => $booking->id]);
            return KM_Util::voucher_url($booking);
        }
        if (!function_exists('WC')) return new WP_Error('km_wc', 'El sistema de pagos no está disponible.');
        $product = wc_get_product(self::product_id());
        if (!$product || !$product->is_purchasable()) {
            KM_Bookings::incident($booking->id, 0, 'product_missing', 'El producto de reservas configurado no existe o no se puede comprar.');
            return new WP_Error('km_product', 'No se pudo iniciar el pago. Avisamos al restaurante; intenta más tarde.');
        }
        if (function_exists('wc_load_cart')) wc_load_cart();
        if (!WC()->cart || !WC()->session) return new WP_Error('km_cart', 'No se pudo iniciar el carro de compra.');
        WC()->session->set_customer_session_cookie(true);
        WC()->cart->empty_cart();

        $names = explode(' ', trim($booking->client_name), 2);
        $c = WC()->customer;
        if ($c) {
            $c->set_billing_first_name($names[0]);
            $c->set_billing_last_name($names[1] ?? '');
            $c->set_billing_email($booking->client_email);
            $c->set_billing_phone('+' . $booking->phone);
            if (!$c->get_billing_country()) $c->set_billing_country('CL');
            $c->save();
        }

        $base = ['km_booking_id' => (int) $booking->id];
        WC()->cart->add_to_cart($product->get_id(), max(1, (int) $booking->guests), 0, [], $base + ['km_kind' => 'people', 'km_unit' => (float) $booking->unit_price]);
        foreach (KM_Bookings::promos($booking->id) as $line) {
            WC()->cart->add_to_cart($product->get_id(), (int) $line->qty, 0, [], $base + ['km_kind' => 'promo', 'km_line' => (int) $line->id, 'km_unit' => (float) $line->unit_price, 'km_name' => $line->name]);
        }
        WC()->cart->calculate_totals();
        $cart_total = (float) WC()->cart->get_total('edit');
        if (abs($cart_total - (float) $booking->total_amount) > 1) {
            KM_Bookings::incident($booking->id, 0, 'cart_mismatch', 'El total del carro (' . KM_Util::money($cart_total) . ') no coincide con la reserva (' . KM_Util::money($booking->total_amount) . '). Revisar impuestos o cupones de WooCommerce.');
        }
        return wc_get_checkout_url();
    }

    private static function booking_label($b) {
        if ($b->is_event) return $b->event_name . ' · ' . KM_Util::date_human($b->booking_date, false);
        return 'Reserva ' . $b->salon_type . ' · ' . KM_Util::date_human($b->booking_date, false) . ' ' . $b->booking_time;
    }

    public static function apply_prices($cart) {
        if (is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)) return;
        static $cache = [];
        foreach ($cart->get_cart() as $key => $item) {
            if (empty($item['km_booking_id'])) continue;
            $id = (int) $item['km_booking_id'];
            if (!isset($cache[$id])) $cache[$id] = KM_Bookings::get($id);
            $b = $cache[$id];
            if (!$b || !in_array($b->status, ['pending_payment', 'expired'], true)) {
                $cart->remove_cart_item($key);
                continue;
            }
            if ($item['km_kind'] === 'people') {
                $item['data']->set_price((float) $b->unit_price);
                $item['data']->set_name(self::booking_label($b) . ' – ' . $b->guests . ' persona' . ($b->guests > 1 ? 's' : ''));
                if ((int) $item['quantity'] !== (int) $b->guests) $cart->cart_contents[$key]['quantity'] = (int) $b->guests;
            } else {
                $item['data']->set_price((float) $item['km_unit']);
                $item['data']->set_name('Promoción: ' . $item['km_name']);
            }
        }
    }

    public static function classic_qty($html, $key, $item) {
        return !empty($item['km_booking_id']) ? '<span>' . (int) $item['quantity'] . '</span>' : $html;
    }

    public static function block_qty_editable($editable, $product, $item) {
        return !empty($item['km_booking_id']) ? false : $editable;
    }

    public static function item_data($data, $item) {
        if (empty($item['km_booking_id']) || $item['km_kind'] !== 'people') return $data;
        $b = KM_Bookings::get($item['km_booking_id']);
        if (!$b) return $data;
        $data[] = ['key' => 'Reserva', 'value' => $b->reservation_code];
        if ($b->booking_time) $data[] = ['key' => $b->is_event ? 'Llegada' : 'Hora', 'value' => $b->booking_time];
        $place = KM_Floor::place_label(KM_Floor::booking_seats($b));
        if ($place) $data[] = ['key' => 'Ubicación', 'value' => $place];
        return $data;
    }

    /** Before paying: the hold must still be valid, otherwise try to renew it if the tables are free. */
    public static function check_cart() {
        if (!WC()->cart) return;
        $done = [];
        foreach (WC()->cart->get_cart() as $item) {
            if (empty($item['km_booking_id']) || isset($done[$item['km_booking_id']])) continue;
            $id = (int) $item['km_booking_id'];
            $done[$id] = 1;
            $err = self::renew_hold($id);
            if (is_wp_error($err)) wc_add_notice($err->get_error_message(), 'error');
        }
    }

    public static function renew_hold($id) {
        global $wpdb;
        $b = KM_Bookings::get($id);
        if (!$b) return new WP_Error('km_404', 'La reserva ya no existe. Vuelve a reservar.');
        if ($b->status === 'pending_payment' && strtotime($b->hold_expires . ' UTC') > time() + 60) return true;
        if (!in_array($b->status, ['pending_payment', 'expired'], true)) return new WP_Error('km_state', 'Esta reserva ya no está disponible para pago.');
        if (!KM_Util::lock('date_' . $b->booking_date, 10)) return new WP_Error('km_busy', 'Intenta nuevamente en unos segundos.');
        try {
            $seats = KM_Floor::booking_seats($b);
            if ($seats) {
                $v = KM_Floor::validate($b->booking_date, $seats, $b->guests, $b->id, false);
                if (is_wp_error($v)) return new WP_Error('km_lost', 'El tiempo para pagar terminó y otra persona tomó tus puestos. Vuelve a la reserva para elegir otros.');
            }
            $wpdb->update(KM_Bookings::table(), ['status' => 'pending_payment', 'payment_status' => 'pending',
                'hold_expires' => KM_Util::utc_mysql((new DateTimeImmutable())->modify('+' . max(5, (int) KM_Util::setting('hold_minutes')) . ' minutes'))], ['id' => $b->id]);
        } finally {
            KM_Util::unlock('date_' . $b->booking_date);
        }
        KM_Bookings::log($b->id, 'hold_renewed', 'Retención renovada al ir a pagar');
        return true;
    }

    public static function line_item_meta($item, $key, $values, $order) {
        if (empty($values['km_booking_id'])) return;
        $item->add_meta_data('km_reservation_id', (int) $values['km_booking_id'], true);
        $item->add_meta_data('km_kind', $values['km_kind'], true);
        if ($values['km_kind'] === 'people') {
            $b = KM_Bookings::get($values['km_booking_id']);
            if ($b) {
                $item->add_meta_data('Reserva', $b->reservation_code, true);
                $item->add_meta_data('Personas', (int) $b->guests, true);
                $place = KM_Floor::place_label(KM_Floor::booking_seats($b));
                if ($place) $item->add_meta_data('Ubicación', $place, true);
            }
        }
        $order->update_meta_data('_km_booking_id', (int) $values['km_booking_id']);
    }

    public static function link_order($order) {
        $ids = self::booking_ids($order);
        global $wpdb;
        foreach ($ids as $id) {
            $wpdb->update(KM_Bookings::table(), ['wc_order_id' => $order->get_id()], ['id' => $id]);
            KM_Bookings::log($id, 'order_created', 'Pedido #' . $order->get_id() . ' · ' . $order->get_payment_method_title());
        }
    }

    public static function booking_ids($order) {
        if (!$order) return [];
        $ids = [];
        foreach ($order->get_items() as $item) {
            $id = (int) $item->get_meta('km_reservation_id');
            if ($id) $ids[$id] = $id;
        }
        return array_values($ids);
    }

    private static function paid_for($order, $booking_id) {
        $sum = 0;
        foreach ($order->get_items() as $item) {
            if ((int) $item->get_meta('km_reservation_id') === (int) $booking_id) $sum += (float) $item->get_total() + (float) $item->get_total_tax();
        }
        return $sum;
    }

    public static function on_paid($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->is_paid()) return;
        foreach (self::booking_ids($order) as $id) {
            KM_Bookings::confirm_payment($id, $order_id, self::paid_for($order, $id));
        }
    }

    public static function on_failed($order_id) {
        $order = wc_get_order($order_id);
        foreach (self::booking_ids($order) as $id) KM_Bookings::payment_failed($id, $order_id, 'failed');
    }

    public static function on_cancelled($order_id) {
        $order = wc_get_order($order_id);
        foreach (self::booking_ids($order) as $id) KM_Bookings::payment_failed($id, $order_id, 'cancelled');
    }

    public static function on_refunded($order_id) {
        $order = wc_get_order($order_id);
        foreach (self::booking_ids($order) as $id) KM_Bookings::refunded($id, $order_id, true);
    }

    public static function on_partial_refund($order_id) {
        $order = wc_get_order($order_id);
        foreach (self::booking_ids($order) as $id) KM_Bookings::refunded($id, $order_id, false);
    }

    public static function on_hold($order_id) {
        $order = wc_get_order($order_id);
        foreach (self::booking_ids($order) as $id) KM_Bookings::log($id, 'payment_on_hold', "Pedido #$order_id en espera de confirmación del medio de pago.");
    }

    /** Reservation-only checkout asks for name, email and phone; the postal address is not needed. */
    public static function short_checkout_fields($locale) {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) return $locale;
        foreach (WC()->cart->get_cart() as $item) {
            if (empty($item['km_booking_id'])) return $locale;
        }
        foreach (['address_1', 'address_2', 'city', 'state', 'postcode', 'company'] as $f) {
            $locale['CL'][$f] = array_merge($locale['CL'][$f] ?? [], ['required' => false, 'hidden' => true]);
        }
        return $locale;
    }

    public static function thankyou_url($url, $order) {
        $ids = self::booking_ids($order);
        if (!$ids) return $url;
        $b = KM_Bookings::get($ids[0]);
        return $b ? add_query_arg('pedido', $order->get_id(), KM_Util::voucher_url($b)) : $url;
    }

    public static function thankyou_box($order_id) {
        $order = wc_get_order($order_id);
        foreach (self::booking_ids($order) as $id) {
            $b = KM_Bookings::get($id);
            if ($b) echo '<p><a class="button" href="' . esc_url(KM_Util::voucher_url($b)) . '">Ver comprobante de la reserva ' . esc_html($b->reservation_code) . '</a></p>';
        }
    }

    public static function email_details($order, $sent_to_admin, $plain_text) {
        foreach (self::booking_ids($order) as $id) {
            $b = KM_Bookings::get($id);
            if (!$b) continue;
            $place = KM_Floor::place_label(KM_Floor::booking_seats($b));
            $lines = ['Reserva: ' . $b->reservation_code, self::booking_label($b), 'Personas: ' . $b->guests];
            if ($place) $lines[] = 'Ubicación: ' . $place;
            if ($plain_text) echo "\n" . implode("\n", $lines) . "\n";
            else echo '<h2>Reserva</h2><p>' . implode('<br>', array_map('esc_html', $lines)) . '</p>';
        }
    }

    public static function admin_order_box($order) {
        foreach (self::booking_ids($order) as $id) {
            $b = KM_Bookings::get($id);
            if (!$b) continue;
            echo '<p class="form-field form-field-wide"><strong>Reserva ' . esc_html($b->reservation_code) . '</strong><br>' .
                esc_html(self::booking_label($b)) . '<br>Estado: ' . esc_html($b->status) . ' / pago: ' . esc_html($b->payment_status) .
                '<br><a href="' . esc_url(KM_Util::panel_url('reserva-' . $b->id)) . '">Abrir en el panel</a></p>';
        }
    }
}
