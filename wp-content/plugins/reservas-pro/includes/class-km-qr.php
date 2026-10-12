<?php
if (!defined('ABSPATH')) exit;

/**
 * QR images generated locally (PHP QR Code, LGPL). The QR only contains a random token
 * inside the reception URL: no personal data. Only authenticated staff can see the booking.
 */
class KM_QR {

    private static function load_lib() {
        if (!class_exists('QRcode')) require_once KM_PATH . 'lib/phpqrcode.php';
    }

    public static function content($booking) {
        return KM_Util::reception_url($booking->qr_token);
    }

    private static function dir() {
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'km-qr';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/index.php', "<?php // Silence\n");
        }
        return ['path' => $dir, 'url' => trailingslashit($up['baseurl']) . 'km-qr'];
    }

    private static function filename($booking) {
        return substr(hash('sha256', 'qr|' . $booking->qr_token . '|' . wp_salt('auth')), 0, 32) . '.png';
    }

    /** @return string|false absolute file path */
    public static function file($booking) {
        if (empty($booking->qr_token)) return false;
        $d = self::dir();
        $file = $d['path'] . '/' . self::filename($booking);
        if (!file_exists($file)) {
            self::load_lib();
            QRcode::png(self::content($booking), $file, QR_ECLEVEL_M, 8, 2);
        }
        return file_exists($file) ? $file : false;
    }

    public static function url($booking) {
        return self::file($booking) ? self::dir()['url'] . '/' . self::filename($booking) : '';
    }

    public static function data_uri($booking) {
        $f = self::file($booking);
        return $f ? 'data:image/png;base64,' . base64_encode(file_get_contents($f)) : '';
    }
}
