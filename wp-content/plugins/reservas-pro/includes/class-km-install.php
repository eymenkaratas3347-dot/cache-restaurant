<?php
if (!defined('ABSPATH')) exit;

/**
 * Schema, data migration from V30 and roles. Runs once per DB version.
 */
class KM_Install {

    const DB_VERSION = '31.1.1';

    public static function maybe_upgrade() {
        if (get_option('km_db_version') === self::DB_VERSION) return;
        if (!KM_Util::lock('install', 5)) return;
        try {
            self::create_tables();
            self::migrate_events();
            self::migrate_bookings();
            self::migrate_votes();
            self::add_roles();
            update_option('km_db_version', self::DB_VERSION, true);
            update_option('km_flush_rewrite', 1, true);
        } finally {
            KM_Util::unlock('install');
        }
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $t = function ($n) { return KM_Util::table($n); };

        dbDelta("CREATE TABLE {$t('bookings')} (
  id mediumint(9) NOT NULL AUTO_INCREMENT,
  reservation_code varchar(10) NOT NULL,
  client_name varchar(100) NOT NULL,
  client_email varchar(100) DEFAULT NULL,
  phone varchar(50) NOT NULL,
  booking_date date NOT NULL,
  booking_time varchar(10) DEFAULT NULL,
  guests tinyint(4) NOT NULL,
  salon_type varchar(50) DEFAULT NULL,
  tables_data text NOT NULL,
  seats_data text,
  is_event tinyint(1) DEFAULT 0,
  event_id bigint(20) NOT NULL DEFAULT 0,
  event_name varchar(150) DEFAULT NULL,
  status varchar(20) DEFAULT 'pending_payment',
  payment_status varchar(20) NOT NULL DEFAULT 'pending',
  source varchar(20) NOT NULL DEFAULT 'web',
  advance_payment decimal(10,2) DEFAULT 0.00,
  unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
  promos_total decimal(10,2) NOT NULL DEFAULT 0.00,
  total_amount decimal(10,2) NOT NULL DEFAULT 0.00,
  payment_mode varchar(20) NOT NULL DEFAULT 'entrada',
  min_consumption decimal(10,2) NOT NULL DEFAULT 0.00,
  wc_order_id bigint(20) DEFAULT 0,
  hold_expires datetime DEFAULT NULL,
  confirmed_at datetime DEFAULT NULL,
  qr_token varchar(64) DEFAULT NULL,
  arrived_guests tinyint(4) NOT NULL DEFAULT 0,
  consent_marketing tinyint(1) NOT NULL DEFAULT 0,
  notes text,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY booking_date (booking_date),
  KEY event_id (event_id),
  KEY status (status),
  KEY qr_token (qr_token),
  KEY wc_order_id (wc_order_id)
) $c;");

        dbDelta("CREATE TABLE {$t('events')} (
  id mediumint(9) NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL DEFAULT '',
  event_name varchar(150) NOT NULL,
  slug varchar(190) NOT NULL DEFAULT '',
  category varchar(40) NOT NULL DEFAULT '',
  description text NOT NULL,
  conditions text,
  image_url varchar(500) DEFAULT NULL,
  video_url varchar(500) DEFAULT NULL,
  event_date date NOT NULL,
  event_time time NOT NULL DEFAULT '21:00:00',
  arrival_slots varchar(255) NOT NULL DEFAULT '',
  end_time varchar(5) NOT NULL DEFAULT '23:59',
  price decimal(10,2) NOT NULL DEFAULT 0.00,
  price_per_person decimal(10,2) DEFAULT 2000.00,
  payment_mode varchar(20) NOT NULL DEFAULT 'entrada',
  min_consumption decimal(10,2) NOT NULL DEFAULT 0.00,
  popup_text text,
  status varchar(50) NOT NULL DEFAULT 'draft',
  featured tinyint(1) NOT NULL DEFAULT 0,
  sold_out tinyint(1) NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  max_guests tinyint(4) NOT NULL DEFAULT 0,
  tolerance_min smallint(6) NOT NULL DEFAULT 15,
  release_min smallint(6) NOT NULL DEFAULT 30,
  cancel_reason text,
  generated_link text NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY event_date (event_date),
  KEY slug (slug),
  KEY status (status)
) $c;");

        dbDelta("CREATE TABLE {$t('votes')} (
  id mediumint(9) NOT NULL AUTO_INCREMENT,
  event_id bigint(20) NOT NULL DEFAULT 0,
  event_name varchar(150) NOT NULL,
  voter_name varchar(100) NOT NULL,
  voter_email varchar(100) NOT NULL DEFAULT '',
  voter_whatsapp varchar(50) NOT NULL DEFAULT '',
  contact_type varchar(10) NOT NULL DEFAULT 'email',
  contact_hash char(64) NOT NULL DEFAULT '',
  verified tinyint(1) NOT NULL DEFAULT 0,
  code_hash varchar(64) DEFAULT NULL,
  code_expires datetime DEFAULT NULL,
  attempts tinyint(4) NOT NULL DEFAULT 0,
  consent_notify tinyint(1) NOT NULL DEFAULT 0,
  ip varchar(45) DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  verified_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY event_id (event_id)
) $c;");

        dbDelta("CREATE TABLE {$t('log')} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) NOT NULL DEFAULT 0,
  event_id bigint(20) NOT NULL DEFAULT 0,
  action varchar(40) NOT NULL,
  details text,
  user_id bigint(20) NOT NULL DEFAULT 0,
  user_name varchar(100) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY event_id (event_id)
) $c;");

        dbDelta("CREATE TABLE {$t('checkins')} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) NOT NULL,
  qty smallint(6) NOT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  user_id bigint(20) NOT NULL DEFAULT 0,
  user_name varchar(100) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id)
) $c;");

        dbDelta("CREATE TABLE {$t('messages')} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) NOT NULL DEFAULT 0,
  channel varchar(12) NOT NULL,
  type varchar(30) NOT NULL,
  dedupe_key varchar(120) NOT NULL,
  recipient varchar(120) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'queued',
  provider_id varchar(120) NOT NULL DEFAULT '',
  error text,
  attempts tinyint(4) NOT NULL DEFAULT 0,
  payload longtext,
  created_at datetime NOT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY booking_id (booking_id),
  KEY status (status),
  KEY provider_id (provider_id)
) $c;");

        dbDelta("CREATE TABLE {$t('promos')} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  name varchar(150) NOT NULL,
  description text,
  conditions text,
  image_url varchar(500) DEFAULT NULL,
  price decimal(10,2) NOT NULL DEFAULT 0.00,
  regular_price decimal(10,2) NOT NULL DEFAULT 0.00,
  stock int(11) DEFAULT NULL,
  max_per_booking smallint(6) NOT NULL DEFAULT 10,
  valid_from date DEFAULT NULL,
  valid_to date DEFAULT NULL,
  event_ids varchar(500) NOT NULL DEFAULT '',
  applies_conventional tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id)
) $c;");

        dbDelta("CREATE TABLE {$t('booking_promos')} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) NOT NULL,
  promo_id bigint(20) NOT NULL,
  name varchar(150) NOT NULL,
  qty smallint(6) NOT NULL,
  unit_price decimal(10,2) NOT NULL,
  redeemed smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY promo_id (promo_id)
) $c;");

        dbDelta("CREATE TABLE {$t('incidents')} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) NOT NULL DEFAULT 0,
  order_id bigint(20) NOT NULL DEFAULT 0,
  type varchar(40) NOT NULL,
  message text,
  resolved tinyint(1) NOT NULL DEFAULT 0,
  resolved_by varchar(100) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  resolved_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY resolved (resolved)
) $c;");
    }

    /** Fills slugs, statuses and defaults on events created by V30. */
    private static function migrate_events() {
        global $wpdb;
        $t = KM_Util::table('events');
        $rows = $wpdb->get_results("SELECT * FROM $t");
        foreach ($rows as $e) {
            $upd = [];
            if ($e->status === 'active' || $e->status === '') $upd['status'] = 'published';
            if (empty($e->slug)) $upd['slug'] = KM_Events::unique_slug($e->event_name, $e->event_date, (int) $e->id);
            if (empty($e->title)) $upd['title'] = $e->event_name;
            if (empty($e->arrival_slots)) $upd['arrival_slots'] = KM_Util::setting('arrival_slots_default');
            if ($e->event_time === '00:00:00') $upd['event_time'] = '21:00:00';
            if ($upd) {
                $upd['updated_at'] = KM_Util::utc_mysql();
                $wpdb->update($t, $upd, ['id' => $e->id]);
            }
        }
        $wpdb->query("UPDATE $t SET generated_link = '' WHERE generated_link IS NULL");
    }

    /** Links V30 bookings to events and separates payment/booking status. */
    private static function migrate_bookings() {
        global $wpdb;
        $b = KM_Util::table('bookings');
        $e = KM_Util::table('events');

        $wpdb->query("UPDATE $b SET total_amount = advance_payment WHERE total_amount = 0 AND advance_payment > 0");
        $wpdb->query("UPDATE $b SET unit_price = ROUND(advance_payment / guests) WHERE unit_price = 0 AND guests > 0");

        $wpdb->query("UPDATE $b bk JOIN $e ev ON ev.event_date = bk.booking_date AND LOWER(ev.event_name) = LOWER(bk.event_name)
            SET bk.event_id = ev.id WHERE bk.is_event = 1 AND bk.event_id = 0");
        $wpdb->query("UPDATE $b bk JOIN (SELECT event_date, MIN(id) id, COUNT(*) n FROM $e GROUP BY event_date HAVING n = 1) ev ON ev.event_date = bk.booking_date
            SET bk.event_id = ev.id WHERE bk.is_event = 1 AND bk.event_id = 0");

        $wpdb->query("UPDATE $b SET payment_status = IF(wc_order_id > 0, 'paid', 'manual') WHERE status = 'confirmed' AND payment_status = 'pending'");
        $wpdb->query("UPDATE $b SET payment_status = 'cancelled' WHERE status = 'cancelled' AND payment_status = 'pending'");
        // V30 never released unpaid holds: they blocked tables forever.
        $wpdb->query("UPDATE $b SET status = 'expired', payment_status = 'abandoned' WHERE status = 'pending_payment' AND hold_expires IS NULL");

        $missing = $wpdb->get_col("SELECT id FROM $b WHERE status = 'confirmed' AND (qr_token IS NULL OR qr_token = '')");
        foreach ($missing as $id) {
            $wpdb->update($b, ['qr_token' => KM_Util::random_token()], ['id' => $id]);
        }
        $wpdb->query("UPDATE $b SET confirmed_at = created_at WHERE status = 'confirmed' AND confirmed_at IS NULL");
    }

    /** Hashes contacts of existing votes and adds the one-vote-per-contact-and-event constraint. */
    private static function migrate_votes() {
        global $wpdb;
        $v = KM_Util::table('votes');
        $e = KM_Util::table('events');
        $wpdb->query("UPDATE $v vt JOIN $e ev ON LOWER(ev.event_name) = LOWER(vt.event_name) SET vt.event_id = ev.id WHERE vt.event_id = 0");
        $rows = $wpdb->get_results("SELECT id, voter_email, event_id FROM $v WHERE contact_hash = ''");
        $seen = [];
        foreach ($rows as $r) {
            $hash = KM_Util::contact_hash($r->voter_email);
            $key = $r->event_id . '|' . $hash;
            if ($r->event_id == 0 || isset($seen[$key])) $hash = KM_Util::contact_hash($r->voter_email . '|legacy|' . $r->id);
            $seen[$key] = 1;
            // Legacy votes were not verified; they remain visible to the admin but flagged.
            $wpdb->update($v, ['contact_hash' => $hash, 'contact_type' => 'email', 'verified' => 0], ['id' => $r->id]);
        }
        $has = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM $v WHERE Key_name = %s", 'event_contact'));
        if (!$has) {
            $wpdb->query("ALTER TABLE $v ADD UNIQUE KEY event_contact (event_id, contact_hash)");
        }
    }

    public static function add_roles() {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('km_manage');
            $admin->add_cap('km_reception');
        }
        if (!get_role('km_manager')) {
            add_role('km_manager', 'Cacho e Cabra – Administración', ['read' => true, 'upload_files' => true, 'km_manage' => true, 'km_reception' => true]);
        }
        if (!get_role('km_reception')) {
            add_role('km_reception', 'Cacho e Cabra – Recepción', ['read' => true, 'km_reception' => true]);
        }
    }

    public static function schedule_cron() {
        if (!wp_next_scheduled('km_cron_tick')) {
            wp_schedule_event(time() + 60, 'km_five_minutes', 'km_cron_tick');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('km_cron_tick');
    }
}
