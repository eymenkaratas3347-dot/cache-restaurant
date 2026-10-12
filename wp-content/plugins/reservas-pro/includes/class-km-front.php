<?php
if (!defined('ABSPATH')) exit;

/**
 * Public site: routes (/evento/slug, /comprobante, /gestion, /recepcion), shortcodes,
 * brand layout, cartelera and restaurant information.
 */
class KM_Front {

    public static function init() {
        add_action('init', [__CLASS__, 'rewrites']);
        add_filter('query_vars', function ($v) { $v[] = 'km_route'; $v[] = 'km_slug'; return $v; });
        add_action('template_redirect', [__CLASS__, 'route'], 5);
        add_filter('template_include', [__CLASS__, 'canvas_template'], 99);
        add_filter('theme_page_templates', function ($t) { $t['km-canvas'] = 'Cacho e Cabra – Diseño de marca'; return $t; });
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'drop_theme_styles'], 100);
        add_action('wp_footer', [__CLASS__, 'whatsapp_button']);
        add_filter('km_site_content', [__CLASS__, 'preview_content']);
        add_filter('body_class', function ($c) { if (self::$in_canvas) $c[] = 'km-canvas-page'; return $c; });

        add_shortcode('km_inicio', [__CLASS__, 'sc_home']);
        add_shortcode('km_cartelera', [__CLASS__, 'sc_cartelera']);
        add_shortcode('km_reservas', [__CLASS__, 'sc_booking']);
        add_shortcode('km_reservas_app', [__CLASS__, 'sc_booking']);
        add_shortcode('km_votar', [__CLASS__, 'sc_votes']);
        add_shortcode('km_info', [__CLASS__, 'sc_info']);
    }

    public static $in_canvas = false;

    public static function rewrites() {
        add_rewrite_rule('^evento/([^/]+)/?$', 'index.php?km_route=event&km_slug=$matches[1]', 'top');
        add_rewrite_rule('^gestion/?$', 'index.php?km_route=panel', 'top');
        add_rewrite_rule('^recepcion/?$', 'index.php?km_route=reception', 'top');
        add_rewrite_rule('^comprobante/?$', 'index.php?km_route=voucher', 'top');
        if (get_option('km_flush_rewrite')) {
            flush_rewrite_rules(false);
            delete_option('km_flush_rewrite');
        }
    }

    public static function home_url() {
        $id = (int) KM_Util::setting('home_page_id');
        if ($id && get_post_status($id) === 'publish') return get_permalink($id);
        return home_url('/');
    }

    /** Creates (once) the new home page that uses [km_inicio]. Not set as front page until approved. */
    public static function ensure_home_page() {
        $id = (int) KM_Util::setting('home_page_id');
        if ($id && get_post($id)) return $id;
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio – Cartelera', 'post_name' => 'inicio', 'post_content' => '[km_inicio]']);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_wp_page_template', 'km-canvas');
            KM_Util::update_settings(['home_page_id' => $id]);
        }
        return (int) $id;
    }

    public static function preview_content($data) {
        if (!empty($_GET['km_preview']) && is_user_logged_in() && KM_Util::can_manage()) {
            $draft = get_option('km_site_draft');
            if (is_array($draft)) return array_merge($data, $draft);
        }
        return $data;
    }

    // ------------------------------------------------------------------
    // Assets
    // ------------------------------------------------------------------

    public static function is_km_page() {
        if (get_query_var('km_route')) return true;
        if (!is_singular()) return false;
        $post = get_post();
        if (!$post) return false;
        if (get_post_meta($post->ID, '_wp_page_template', true) === 'km-canvas') return true;
        foreach (['km_inicio', 'km_cartelera', 'km_reservas', 'km_reservas_app', 'km_votar', 'km_info'] as $sc) {
            if (has_shortcode($post->post_content, $sc)) return true;
        }
        return false;
    }

    public static function css_vars() {
        $s = KM_Util::site();
        $pal = KM_Util::palettes()[$s['palette']] ?? KM_Util::palettes()['noche'];
        return sprintf(':root{--km-bg:%s;--km-surface:%s;--km-text:%s;--km-muted:%s;--km-accent:%s;--km-gold:%s;--km-green:%s;--km-glow:%s}',
            $pal['bg'], $pal['surface'], $pal['text'], $pal['muted'], esc_attr($s['accent']), $pal['accents'][1], $pal['accents'][2], $pal['glow'] ?? '#7a0d0a');
    }

    public static function assets() {
        if (!self::is_km_page()) return;
        wp_enqueue_style('km-fonts', 'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&family=Outfit:wght@300;400;500;600;700;800&display=swap', [], null);
        wp_enqueue_style('km-front', KM_URL . 'assets/css/front.css', [], KM_VERSION);
        wp_add_inline_style('km-front', self::css_vars());
        wp_enqueue_script('km-front', KM_URL . 'assets/js/front.js', [], KM_VERSION, true);
        wp_localize_script('km-front', 'KM_FRONT', [
            'rest' => esc_url_raw(rest_url(KM_Rest::NS)),
            'voteChannels' => KM_Votes::channels(),
        ]);
    }

    /** Brand pages print their own header and footer; the theme reset (pink buttons and links) only fights front.css there. */
    public static function drop_theme_styles() {
        if (!self::$in_canvas) return;
        foreach (['hello-elementor', 'hello-elementor-theme-style', 'hello-elementor-header-footer'] as $h) {
            wp_dequeue_style($h);
        }
    }

    // ------------------------------------------------------------------
    // Routing
    // ------------------------------------------------------------------

    public static function route() {
        $route = get_query_var('km_route');
        if (!$route) {
            self::redirect_legacy_booking();
            return;
        }
        nocache_headers();
        switch ($route) {
            case 'event':
                $ev = KM_Events::by_slug(sanitize_title(get_query_var('km_slug')));
                $preview = $ev && in_array($ev->status, ['draft', 'hidden'], true) && KM_Util::can_manage();
                if (!$ev || (!in_array($ev->status, ['published', 'cancelled'], true) && !$preview)) {
                    status_header(404);
                    self::render_layout('Evento no encontrado', '<section class="km-wrap km-empty"><h1>Evento no encontrado</h1><p><a class="km-btn" href="' . esc_url(self::home_url()) . '#cartelera">Ver cartelera</a></p></section>');
                    exit;
                }
                status_header(200);
                self::render_layout($ev->event_name, self::event_page($ev, $preview), $ev);
                exit;
            case 'voucher':
                status_header(200);
                self::render_layout('Comprobante de reserva', self::voucher_page(), null, true);
                exit;
            case 'panel':
            case 'reception':
                KM_Panel::render($route);
                exit;
        }
    }

    /** Old links (/reservas-2/?evento_id=…, V30 ?evento=si&nombre_evento=…) open the seat map on the event page. */
    private static function redirect_legacy_booking() {
        if (!is_singular('page') || (empty($_GET['evento_id']) && empty($_GET['evento']))) return;
        $post = get_post();
        if (!$post || (!has_shortcode($post->post_content, 'km_reservas') && !has_shortcode($post->post_content, 'km_reservas_app'))) return;
        $ev = self::requested_event();
        if ($ev && $ev->status === 'published') {
            wp_safe_redirect(KM_Util::booking_url($ev), 302);
            exit;
        }
    }

    private static function requested_event() {
        $ev = null;
        if (!empty($_GET['evento_id'])) {
            $ev = KM_Events::get((int) $_GET['evento_id']);
        } elseif (isset($_GET['evento']) && $_GET['evento'] === 'si') {
            $ev = KM_Events::by_legacy(sanitize_text_field(wp_unslash($_GET['nombre_evento'] ?? '')), KM_Util::parse_date(wp_unslash($_GET['fecha'] ?? '')));
        }
        return $ev && in_array($ev->status, ['published', 'hidden'], true) ? $ev : null;
    }

    public static function canvas_template($template) {
        if (!is_singular('page')) return $template;
        $post = get_post();
        $is_canvas = get_post_meta($post->ID, '_wp_page_template', true) === 'km-canvas'
            || has_shortcode($post->post_content, 'km_reservas') || has_shortcode($post->post_content, 'km_reservas_app');
        if ($is_canvas) {
            self::$in_canvas = true;
            return KM_PATH . 'templates/canvas.php';
        }
        return $template;
    }

    /** Outputs a full page with the brand header and footer. */
    public static function render_layout($title, $content, $event = null, $noindex = false) {
        self::$in_canvas = true;
        $GLOBALS['km_layout'] = ['title' => $title, 'content' => $content, 'event' => $event, 'noindex' => $noindex];
        add_filter('pre_get_document_title', function () use ($title) { return $title . ' | ' . get_bloginfo('name'); }, 99);
        include KM_PATH . 'templates/layout.php';
    }

    public static function icon($name) {
        $p = [
            'wa'   => '<svg viewBox="0 0 448 512" aria-hidden="true"><path fill="currentColor" d="M380.9 97.1A222 222 0 0 0 223.9 32C101.5 32 1.9 131.6 1.9 254c0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9a221.6 221.6 0 0 0 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zM223.9 414.8c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3 18.6-68.1-4.4-7a184.3 184.3 0 0 1-28.2-98.2c0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1a183 183 0 0 1 54 130.5c-.1 101.8-84.9 184.6-184.5 184.6zm101.1-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2s-9.7 1.4-14.8 6.9c-5.1 5.6-19.4 19-19.4 46.3s19.9 53.7 22.6 57.4c2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>',
            'cal'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M4 6.5h16v13H4zM4 10.5h16M8 3.5v5M16 3.5v5"/></svg>',
            'fork' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M7 3v8M4.5 3v5a2.5 2.5 0 0 0 5 0V3M7 11v10M17 21V3c-2.5 1.5-3.5 4-3.5 8h3.5"/></svg>',
            'pin'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>',
            'glass'=> '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M6 3h12l-1 6a5 5 0 0 1-10 0zM12 14v7M8 21h8"/></svg>',
            'ig'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="5" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17.3" cy="6.7" r="1.2" fill="currentColor"/></svg>',
            'seat' => '<svg viewBox="0 0 40 24" aria-hidden="true"><rect x="12" y="4" width="16" height="16" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="6" cy="12" r="3" fill="currentColor"/><circle cx="34" cy="12" r="3" fill="currentColor"/><circle cx="20" cy="1.5" r="1.5" fill="currentColor"/></svg>',
            'arrow'=> '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>',
            'back' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M15 5l-7 7 7 7"/></svg>',
            'heart'=> '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M12 20s-7.5-4.6-9.2-9.4C1.6 7 3.9 4 7.2 4c2 0 3.6 1.1 4.8 2.8C13.2 5.1 14.8 4 16.8 4c3.3 0 5.6 3 4.4 6.6C19.5 15.4 12 20 12 20z"/></svg>',
        ];
        return '<span class="km-ico">' . ($p[$name] ?? '') . '</span>';
    }

    public static function header_html() {
        $s = KM_Util::site();
        $home = self::home_url();
        $route = get_query_var('km_route');
        $nav = [
            ['Eventos', $home . '#cartelera', !$route || $route === 'event'],
            ['Carta', $s['carta_url'] ?: $home . '#carta', false],
            ['Servicios', $home . '#servicios', false],
            ['Cómo llegar', $home . '#ubicacion', false],
            ['Reservar mesa', KM_Util::booking_url(), false],
        ];
        $wa = KM_Util::whatsapp_link($s['whatsapp_attention'], 'Hola, tengo una consulta');
        ob_start(); ?>
        <header class="km-header">
            <div class="km-header-in">
                <a class="km-logo" href="<?php echo esc_url($home); ?>" aria-label="Cacho e Cabra – inicio">
                    <?php if ($s['logo']): ?><img src="<?php echo esc_url($s['logo']); ?>" alt="Cacho e Cabra" width="120" height="80"><?php else: ?><span class="km-wordmark">Cacho e Cabra</span><?php endif; ?>
                </a>
                <nav id="km-nav" class="km-nav" aria-label="Principal">
                    <?php foreach ($nav as $n): $ext = strpos($n[1], home_url()) !== 0; ?>
                        <a href="<?php echo esc_url($n[1]); ?>"<?php echo $n[2] ? ' class="is-active" aria-current="page"' : ''; echo $ext ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html($n[0]); ?></a>
                    <?php endforeach; ?>
                    <?php if ($wa): ?><a class="km-nav-wa" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo self::icon('wa'); ?> WhatsApp</a><?php endif; ?>
                </nav>
                <?php if ($wa): ?><a class="km-wa-pill" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo self::icon('wa'); ?> Escríbenos por WhatsApp</a><?php endif; ?>
                <button class="km-burger" aria-expanded="false" aria-controls="km-nav" aria-label="Abrir menú"><span></span><span></span><span></span></button>
            </div>
        </header>
        <?php return ob_get_clean();
    }

    public static function footer_html() {
        $s = KM_Util::site();
        $home = self::home_url();
        $wa = KM_Util::whatsapp_link($s['whatsapp_attention'], 'Hola, tengo una consulta');
        $tabbar = get_query_var('km_route') !== 'event';
        ob_start(); ?>
        <footer class="km-footer" id="contacto">
            <div class="km-wrap km-footer-grid">
                <div class="km-footer-brand">
                    <?php if ($s['logo']): ?><img src="<?php echo esc_url($s['logo']); ?>" alt="Cacho e Cabra" class="km-footer-logo" loading="lazy" width="140" height="94"><?php else: ?><p class="km-wordmark">Cacho e Cabra</p><?php endif; ?>
                    <p class="km-tagline">Restaurante · Música en vivo<?php echo $s['city'] ? ' · ' . esc_html($s['city']) : ''; ?></p>
                    <?php if ($s['footer_text']): ?><p><?php echo nl2br(esc_html($s['footer_text'])); ?></p><?php endif; ?>
                </div>
                <div>
                    <h3>Contacto</h3>
                    <ul class="km-list">
                        <?php if ($s['whatsapp_attention']): ?><li><a href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener">WhatsApp atención: <?php echo esc_html($s['whatsapp_attention']); ?></a></li><?php endif; ?>
                        <?php if ($s['contact_phone']): ?><li><a href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', $s['contact_phone'])); ?>">Teléfono: <?php echo esc_html($s['contact_phone']); ?></a></li><?php endif; ?>
                        <?php if ($s['contact_email']): ?><li><a href="mailto:<?php echo esc_attr($s['contact_email']); ?>"><?php echo esc_html($s['contact_email']); ?></a></li><?php endif; ?>
                    </ul>
                </div>
                <div>
                    <h3>Horarios</h3>
                    <p><?php echo nl2br(esc_html($s['hours'])); ?></p>
                    <?php if ($s['address']): ?><p><?php echo esc_html($s['address']); ?></p><?php endif; ?>
                </div>
            </div>
            <div class="km-wrap km-footer-bar">
                <p><strong>Cacho e Cabra</strong> <span>Restaurante · Música en vivo<?php echo $s['city'] ? ' · ' . esc_html($s['city']) : ''; ?></span></p>
                <p class="km-social">
                    <?php if ($s['instagram']): ?><a href="<?php echo esc_url($s['instagram']); ?>" target="_blank" rel="noopener" aria-label="Instagram"><?php echo self::icon('ig'); ?></a><?php endif; ?>
                    <?php if ($wa): ?><a href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?php echo self::icon('wa'); ?></a><?php endif; ?>
                </p>
                <p class="km-copy">© <?php echo esc_html(KM_Util::now()->format('Y')); ?> Cacho e Cabra</p>
            </div>
        </footer>
        <?php if ($tabbar): ?>
        <nav class="km-tabbar" aria-label="Accesos rápidos">
            <a href="<?php echo esc_url($home); ?>#cartelera"><?php echo self::icon('cal'); ?>Eventos</a>
            <a href="<?php echo esc_url($s['carta_url'] ?: $home . '#carta'); ?>"<?php echo $s['carta_url'] ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo self::icon('fork'); ?>Carta</a>
            <?php if ($wa): ?><a href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo self::icon('wa'); ?>WhatsApp</a>
            <?php else: ?><a href="<?php echo esc_url($home); ?>#ubicacion"><?php echo self::icon('pin'); ?>Cómo llegar</a><?php endif; ?>
        </nav>
        <?php endif;
        return ob_get_clean();
    }

    public static function whatsapp_button() {
        if (get_query_var('km_route') === 'panel' || get_query_var('km_route') === 'reception' || is_admin()) return;
        $n = KM_Util::site('whatsapp_attention');
        if (!$n) return;
        $accent = KM_Util::site('accent') ?: '#e0241b';
        // Inline styles: this button is printed on every page, including those without front.css.
        echo '<a class="km-wa-float" style="position:fixed;right:18px;bottom:18px;display:flex;align-items:center;gap:10px;padding:10px 18px 10px 12px;border-radius:999px;background:' . esc_attr($accent) . ';color:#fff;text-decoration:none;font:600 14px/1.1 Outfit,system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.45);z-index:9990" href="' . esc_url(KM_Util::whatsapp_link($n, 'Hola, tengo una consulta')) . '" target="_blank" rel="noopener" aria-label="Escríbenos por WhatsApp">'
            . '<svg viewBox="0 0 448 512" width="28" height="28" style="width:28px;height:28px;flex:0 0 28px" aria-hidden="true"><path fill="currentColor" d="M380.9 97.1A222 222 0 0 0 223.9 32C101.5 32 1.9 131.6 1.9 254c0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9a221.6 221.6 0 0 0 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zM223.9 414.8c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3 18.6-68.1-4.4-7a184.3 184.3 0 0 1-28.2-98.2c0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1a183 183 0 0 1 54 130.5c-.1 101.8-84.9 184.6-184.5 184.6zm101.1-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2s-9.7 1.4-14.8 6.9c-5.1 5.6-19.4 19-19.4 46.3s19.9 53.7 22.6 57.4c2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>'
            . '<span class="km-wa-txt" style="display:flex;flex-direction:column"><small style="font-size:11px;opacity:.85">WhatsApp</small><strong style="font-size:15px">Hablemos</strong></span></a>';
    }

    // ------------------------------------------------------------------
    // Images and video
    // ------------------------------------------------------------------

    public static function img($url, $alt, $class = '', $sizes = '(max-width: 600px) 50vw, 300px', $eager = false) {
        if (!$url) return '<div class="km-noimg ' . esc_attr($class) . '" role="img" aria-label="' . esc_attr($alt) . '">' . esc_html($alt) . '</div>';
        $id = attachment_url_to_postid($url);
        $attrs = 'alt="' . esc_attr($alt) . '" class="' . esc_attr($class) . '" decoding="async"' . ($eager ? ' fetchpriority="high"' : ' loading="lazy"');
        if ($id) {
            $src = wp_get_attachment_image_src($id, 'large');
            $srcset = wp_get_attachment_image_srcset($id, 'large');
            return '<img src="' . esc_url($src ? $src[0] : $url) . '"' . ($srcset ? ' srcset="' . esc_attr($srcset) . '" sizes="' . esc_attr($sizes) . '"' : '') . ($src ? ' width="' . (int) $src[1] . '" height="' . (int) $src[2] . '"' : '') . ' ' . $attrs . '>';
        }
        return '<img src="' . esc_url($url) . '" ' . $attrs . '>';
    }

    /** Returns ['type' => youtube|vimeo|instagram|file, 'embed' => url] or null. */
    public static function video_info($url) {
        $url = trim((string) $url);
        if (!$url) return null;
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([\w-]{11})~', $url, $m)) {
            return ['type' => 'youtube', 'embed' => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&rel=0&playsinline=1', 'thumb' => 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg'];
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return ['type' => 'vimeo', 'embed' => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1', 'thumb' => ''];
        }
        if (preg_match('~instagram\.com/(?:reel|p)/([\w-]+)~', $url, $m)) {
            return ['type' => 'instagram', 'embed' => 'https://www.instagram.com/reel/' . $m[1] . '/embed', 'thumb' => ''];
        }
        if (preg_match('~\.(mp4|webm|mov)(\?|$)~i', $url)) return ['type' => 'file', 'embed' => $url, 'thumb' => ''];
        return null;
    }

    /** Click-to-load video: nothing heavy is downloaded until the visitor presses play. */
    public static function video_html($ev) {
        $v = self::video_info($ev->video_url);
        if (!$v) return '';
        $bg = $v['thumb'] ?: $ev->image_url;
        return '<div class="km-video" data-type="' . esc_attr($v['type']) . '" data-src="' . esc_url($v['embed']) . '">'
            . '<button type="button" class="km-video-play" style="background-image:url(' . esc_url($bg) . ')" aria-label="Reproducir video de ' . esc_attr($ev->event_name) . '">'
            . '<span class="km-play-ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path fill="currentColor" d="M8 5.5v13l10.5-6.5z"/></svg></span><span class="km-play-txt">Ver video</span></button></div>';
    }

    // ------------------------------------------------------------------
    // Cartelera
    // ------------------------------------------------------------------

    private static function badge($ev, $av) {
        if ($ev->status === 'cancelled') return '<span class="km-badge km-badge-off">Cancelado</span>';
        if ($av['sold_out']) return '<span class="km-badge km-badge-off">Agotado</span>';
        if ($av['low']) return '<span class="km-badge km-badge-warn">' . esc_html($av['low_label']) . '</span>';
        return '';
    }

    private static function price_label($ev) {
        $p = (float) $ev->price_per_person;
        if ($p <= 0) return 'Entrada liberada';
        return KM_Util::money($p) . ' p/p' . ($ev->payment_mode === 'abono' ? ' (abono)' : '');
    }

    /** "<strong>$8.000</strong> por persona" with the abono wording when it applies. */
    private static function price_html($ev) {
        $p = (float) $ev->price_per_person;
        if ($p <= 0) return '<strong>Entrada liberada</strong>';
        return ($ev->payment_mode === 'abono' ? 'Abono ' : '') . '<strong>' . esc_html(KM_Util::money($p)) . '</strong> por persona';
    }

    private static function cat_key($cat) {
        return sanitize_title($cat);
    }

    public static function card($ev) {
        $av = KM_Events::availability($ev);
        $url = KM_Util::event_url($ev);
        $cta = $av['sold_out']
            ? '<a class="km-btn km-btn-ghost km-btn-block" href="' . esc_url($url) . '">Agotado · Ver evento</a>'
            : '<a class="km-btn km-btn-block" href="' . esc_url(KM_Util::booking_url($ev)) . '">Ver evento y reservar</a>';
        $cat = (string) ($ev->category ?? '');
        return '<article class="km-card' . ($ev->featured ? ' is-featured' : '') . '" data-cat="' . esc_attr(self::cat_key($cat)) . '">'
            . '<a class="km-card-poster" href="' . esc_url($url) . '" tabindex="-1" aria-hidden="true">' . self::img($ev->image_url, 'Afiche de ' . $ev->event_name, '', '(max-width: 600px) 92vw, (max-width: 1100px) 45vw, 280px')
            . ($ev->featured ? '<span class="km-ribbon">Destacado</span>' : '') . self::badge($ev, $av) . '</a>'
            . '<div class="km-card-body">'
            . '<p class="km-card-meta"><time datetime="' . esc_attr($ev->event_date . 'T' . KM_Events::show_time($ev)) . '">' . esc_html(KM_Util::date_short($ev->event_date)) . ' · ' . esc_html(KM_Events::show_time($ev)) . '</time>' . ($cat ? ' <span class="km-card-cat">' . esc_html($cat) . '</span>' : '') . '</p>'
            . '<h3><a href="' . esc_url($url) . '">' . esc_html($ev->event_name) . '</a></h3>'
            . '<p class="km-card-price">' . self::price_html($ev) . '</p>'
            . $cta . '</div></article>';
    }

    public static function past_card($ev) {
        $count = KM_Votes::count($ev->id);
        return '<article class="km-past">'
            . '<div class="km-past-img">' . self::img($ev->image_url, 'Afiche de ' . $ev->event_name, '', '(max-width: 600px) 90vw, 280px') . '</div>'
            . '<div class="km-past-body"><h3>' . esc_html($ev->event_name) . '</h3>'
            . '<button type="button" class="km-pill-vote" data-vote="' . (int) $ev->id . '" data-name="' . esc_attr($ev->event_name) . '">' . self::icon('heart') . ' Quiero que vuelva</button>'
            . '<p class="km-vote-count">' . ($count ? '<span data-vote-count="' . (int) $ev->id . '">' . $count . '</span> ' . ($count === 1 ? 'persona quiere' : 'personas quieren') . ' que vuelva' : '<span data-vote-count="' . (int) $ev->id . '"></span>') . '</p>'
            . '</div></article>';
    }

    public static function sc_cartelera($atts = []) {
        $atts = shortcode_atts(['realizados' => 'si'], $atts);
        $up = KM_Events::upcoming();
        $cats = [];
        foreach ($up as $e) if (!empty($e->category)) $cats[self::cat_key($e->category)] = $e->category;
        ob_start(); ?>
        <section class="km-section km-section-events" id="cartelera" aria-labelledby="km-h-up">
            <div class="km-wrap">
                <div class="km-head-row">
                    <div>
                        <p class="km-eyebrow">Eventos por realizar</p>
                        <h2 id="km-h-up" class="km-h2">Próximos eventos</h2>
                    </div>
                    <?php if (count($cats) > 1): ?>
                    <div class="km-chips" role="group" aria-label="Filtrar por tipo de evento">
                        <button type="button" class="km-chip" data-filter="" aria-pressed="true">Todos</button>
                        <?php foreach ($cats as $k => $label): ?><button type="button" class="km-chip" data-filter="<?php echo esc_attr($k); ?>" aria-pressed="false"><?php echo esc_html($label); ?></button><?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($up): ?>
                    <div class="km-grid" data-filter-grid><?php foreach ($up as $e) echo self::card($e); ?></div>
                    <p class="km-empty km-filter-empty" hidden>No hay eventos de este tipo por ahora.</p>
                <?php else: ?>
                    <p class="km-empty">Pronto anunciaremos nuevos espectáculos. Síguenos en Instagram para enterarte primero.</p>
                <?php endif; ?>
                <div class="km-divider"><?php echo self::icon('seat'); ?><span><?php echo KM_Floor::seat_mode() ? 'Elige tus puestos en el mapa al reservar.' : 'Selecciona tu mesa en el mapa al reservar.'; ?></span></div>
                <p class="km-conv"><a class="km-link" href="<?php echo esc_url(KM_Util::booking_url()); ?>"><?php echo esc_html(KM_Util::site('conventional_cta')); ?> <?php echo self::icon('arrow'); ?></a></p>
            </div>
        </section>
        <?php if ($atts['realizados'] === 'si') echo self::past_section();
        echo self::vote_modal();
        return ob_get_clean();
    }

    public static function past_section() {
        $past = KM_Events::past();
        if (!$past) return '';
        ob_start(); ?>
        <section class="km-section km-section-past" id="realizados" aria-labelledby="km-h-past">
            <div class="km-wrap">
                <div class="km-head-row">
                    <div class="km-head-inline">
                        <h2 id="km-h-past" class="km-h2">Eventos realizados</h2>
                        <p class="km-head-sub">¿Cuál te gustaría que vuelva?</p>
                    </div>
                    <?php if (count($past) > 4): ?><button type="button" class="km-link" data-more="#km-past-grid" aria-expanded="false">Ver más eventos <?php echo self::icon('arrow'); ?></button><?php endif; ?>
                </div>
                <div class="km-past-grid is-collapsed" id="km-past-grid"><?php foreach ($past as $e) echo self::past_card($e); ?></div>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    public static function vote_modal() {
        static $done = false;
        if ($done) return '';
        $done = true;
        $channels = KM_Votes::channels();
        ob_start(); ?>
        <dialog class="km-modal" id="km-vote-modal" aria-labelledby="km-vote-title">
            <form method="dialog" class="km-modal-x"><button aria-label="Cerrar">×</button></form>
            <h2 id="km-vote-title">Quiero que vuelva</h2>
            <p class="km-vote-event"></p>
            <div class="km-vote-step" data-step="1">
                <label for="km-v-name">Nombre</label><input id="km-v-name" type="text" autocomplete="name" required>
                <?php if (count($channels) > 1): ?>
                    <fieldset class="km-radio"><legend>Verificar con</legend>
                        <label><input type="radio" name="km-v-ch" value="email" checked> Correo</label>
                        <label><input type="radio" name="km-v-ch" value="phone"> WhatsApp</label>
                    </fieldset>
                <?php endif; ?>
                <div data-ch="email" <?php echo in_array('email', $channels, true) ? '' : 'hidden'; ?>><label for="km-v-email">Correo</label><input id="km-v-email" type="email" autocomplete="email" inputmode="email"></div>
                <div data-ch="phone" <?php echo $channels === ['phone'] ? '' : 'hidden'; ?>><label for="km-v-phone">WhatsApp</label><input id="km-v-phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="9 1234 5678"></div>
                <label class="km-check"><input id="km-v-consent" type="checkbox"> Avísenme si este espectáculo vuelve (opcional)</label>
                <p class="km-small">Un voto por contacto verificado y evento. Te enviaremos un código para confirmar.</p>
                <button type="button" class="km-btn km-btn-block" data-act="request">Enviar código</button>
            </div>
            <div class="km-vote-step" data-step="2" hidden>
                <label for="km-v-code">Código de 6 dígitos</label><input id="km-v-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6">
                <button type="button" class="km-btn km-btn-block" data-act="verify">Confirmar voto</button>
            </div>
            <div class="km-vote-step" data-step="3" hidden><p class="km-ok">¡Gracias! Tu voto quedó registrado.</p></div>
            <p class="km-msg" role="alert" aria-live="assertive"></p>
        </dialog>
        <?php return ob_get_clean();
    }

    public static function sc_votes() {
        return self::past_section() . self::vote_modal();
    }

    // ------------------------------------------------------------------
    // Restaurant information
    // ------------------------------------------------------------------

    /** Three quick links under the cartelera: menu, private events, location. */
    public static function info_strip() {
        $s = KM_Util::site();
        $home = self::home_url();
        $g = array_values($s['gallery']);
        $items = [
            ['Nuestra carta', 'Sabores que acompañan grandes noches.', $s['carta_url'] ?: $home . '#carta', $g[0] ?? '', 'fork', (bool) $s['carta_url']],
            ['Eventos privados', 'Celebraciones que se viven distinto.', $s['whatsapp_attention'] ? KM_Util::whatsapp_link($s['whatsapp_attention'], 'Hola, quiero cotizar un evento privado') : $home . '#servicios', $g[1] ?? '', 'glass', (bool) $s['whatsapp_attention']],
            [$s['city'] ?: 'Ubicación', 'Ver ubicación y horarios.', $home . '#ubicacion', $g[2] ?? '', 'pin', false],
        ];
        ob_start(); ?>
        <section class="km-strip" aria-label="Más de Cacho e Cabra">
            <div class="km-wrap km-strip-in">
                <?php foreach ($items as $it): ?>
                <a class="km-strip-item" href="<?php echo esc_url($it[2]); ?>"<?php echo $it[5] ? ' target="_blank" rel="noopener"' : ''; ?>>
                    <span class="km-strip-img"><?php echo $it[3] ? self::img($it[3], '', '', '120px') : self::icon($it[4]); ?></span>
                    <span class="km-strip-txt"><strong><?php echo esc_html($it[0]); ?></strong><span><?php echo esc_html($it[1]); ?></span></span>
                    <?php echo self::icon('arrow'); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    public static function sc_info() {
        $s = KM_Util::site();
        $map_q = $s['address'] ? rawurlencode($s['address']) : '';
        $directions = $s['maps_url'] ?: ($map_q ? 'https://www.google.com/maps/dir/?api=1&destination=' . $map_q : '');
        ob_start(); ?>
        <section class="km-section km-section-about" id="nosotros">
            <div class="km-wrap km-about">
                <div>
                    <p class="km-eyebrow">Restaurante · Música en vivo<?php echo $s['city'] ? ' · ' . esc_html($s['city']) : ''; ?></p>
                    <h2 class="km-h2"><?php echo esc_html($s['about_title']); ?></h2>
                    <p class="km-lead"><?php echo nl2br(esc_html($s['about_text'])); ?></p>
                </div>
                <?php if ($s['gallery']): ?>
                    <div class="km-gallery"><?php foreach (array_slice($s['gallery'], 0, 6) as $g) echo self::img($g, 'Ambiente de Cacho e Cabra', '', '(max-width: 600px) 50vw, 240px'); ?></div>
                <?php endif; ?>
            </div>
        </section>
        <section class="km-section km-section-alt" id="servicios">
            <div class="km-wrap">
                <h2 class="km-h2">Servicios</h2>
                <div class="km-services">
                    <?php foreach ($s['services'] as $i => $sv): ?>
                        <div class="km-service"><span class="km-service-n"><?php echo sprintf('%02d', $i + 1); ?></span><h3><?php echo esc_html($sv['title']); ?></h3><p><?php echo esc_html($sv['text']); ?></p></div>
                    <?php endforeach; ?>
                </div>
                <?php if ($s['private_events_text']): ?>
                    <div class="km-private"><p><?php echo esc_html($s['private_events_text']); ?></p>
                    <?php if ($s['whatsapp_attention']): ?><a class="km-btn" href="<?php echo esc_url(KM_Util::whatsapp_link($s['whatsapp_attention'], 'Hola, quiero cotizar un evento privado')); ?>" target="_blank" rel="noopener">Cotizar evento privado</a><?php endif; ?></div>
                <?php endif; ?>
            </div>
        </section>
        <section class="km-section" id="carta">
            <div class="km-wrap km-carta">
                <h2 class="km-h2">Carta</h2>
                <?php if ($s['carta_url']): ?>
                    <p class="km-lead">Cocina, vinos y coctelería para acompañar cada show.</p>
                    <a class="km-btn" href="<?php echo esc_url($s['carta_url']); ?>" target="_blank" rel="noopener">Ver carta</a>
                <?php else: ?><p class="km-lead">Carta disponible en el local.</p><?php endif; ?>
            </div>
        </section>
        <section class="km-section km-section-alt" id="ubicacion">
            <div class="km-wrap km-location">
                <div>
                    <h2 class="km-h2">Ubicación y horarios</h2>
                    <?php if ($s['address']): ?><p class="km-lead"><?php echo esc_html($s['address']); ?></p><?php endif; ?>
                    <p class="km-hours"><?php echo nl2br(esc_html($s['hours'])); ?></p>
                    <?php if ($directions): ?><a class="km-btn" href="<?php echo esc_url($directions); ?>" target="_blank" rel="noopener">Cómo llegar</a><?php endif; ?>
                </div>
                <?php if ($map_q): ?>
                    <div class="km-map" data-map="<?php echo esc_attr('https://www.google.com/maps?q=' . $map_q . '&output=embed'); ?>">
                        <button type="button" class="km-btn km-btn-ghost" data-load-map>Mostrar mapa</button>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    public static function sc_home() {
        $s = KM_Util::site();
        $preview = !empty($_GET['km_preview']) && KM_Util::can_manage();
        ob_start();
        if ($preview) echo '<div class="km-preview-bar">Vista previa del borrador – los visitantes aún ven la versión publicada.</div>'; ?>
        <section class="km-hero<?php echo $s['hero_image'] ? ' has-image' : ''; ?>"<?php echo $s['hero_image'] ? ' style="--km-hero:url(' . esc_url($s['hero_image']) . ')"' : ''; ?>>
            <div class="km-wrap km-hero-in">
                <p class="km-hero-kicker">Música · Comida · Amigos<?php echo $s['city'] ? ' · ' . esc_html($s['city']) : ''; ?></p>
                <h1><?php echo esc_html($s['hero_title']); ?></h1>
                <p class="km-hero-sub"><?php echo esc_html($s['hero_subtitle']); ?></p>
                <div class="km-hero-actions">
                    <a class="km-btn km-btn-lg" href="#cartelera">Ver cartelera</a>
                    <a class="km-btn km-btn-lg km-btn-ghost" href="<?php echo esc_url(KM_Util::booking_url()); ?>"><?php echo esc_html($s['conventional_cta']); ?></a>
                </div>
            </div>
            <div class="km-neon" aria-hidden="true"><span>Aquí</span><span>la música</span><span>sabe mejor</span></div>
            <p class="km-hero-script" aria-hidden="true">Buena comida,<br>mejores historias</p>
        </section>
        <?php
        echo self::sc_cartelera();
        echo self::info_strip();
        echo self::sc_info();
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Event page
    // ------------------------------------------------------------------

    public static function event_page($ev, $preview = false) {
        $av = KM_Events::availability($ev);
        $past = KM_Events::is_past($ev);
        $open = KM_Events::booking_open($ev) && !$av['sold_out'];
        $slots = KM_Events::arrival_slots($ev);
        $video = self::video_html($ev);
        $city = KM_Util::site('city');
        $home = self::home_url();
        $meta = KM_Util::date_short($ev->event_date, false) . ' · ' . KM_Events::show_time($ev) . ($city ? ' · ' . $city : '');
        ob_start();
        if ($preview) echo '<div class="km-preview-bar">Vista previa (' . esc_html($ev->status === 'draft' ? 'borrador' : 'oculto') . ') – no visible para el público.</div>';

        if ($past || $ev->status === 'cancelled') { ?>
            <section class="km-wrap km-event-wrap">
                <a class="km-back" href="<?php echo esc_url($home); ?>#cartelera"><?php echo self::icon('back'); ?> Volver a eventos</a>
                <div class="km-event-past">
                    <div class="km-event-poster"><?php echo self::img($ev->image_url, 'Afiche de ' . $ev->event_name, '', '(max-width: 800px) 90vw, 420px', true); ?></div>
                    <div>
                        <?php if ($ev->status === 'cancelled'): ?>
                            <p class="km-badge km-badge-off">Evento cancelado</p>
                            <h1 class="km-event-title"><?php echo esc_html($ev->event_name); ?></h1>
                            <?php if ($ev->cancel_reason): ?><p><?php echo esc_html($ev->cancel_reason); ?></p><?php endif; ?>
                            <p>Si tenías una reserva te contactaremos por correo y WhatsApp.</p>
                        <?php else: ?>
                            <p class="km-eyebrow">Evento realizado</p>
                            <h1 class="km-event-title"><?php echo esc_html($ev->event_name); ?></h1>
                            <p class="km-event-meta"><?php echo esc_html($meta); ?></p>
                            <button type="button" class="km-pill-vote km-pill-lg" data-vote="<?php echo (int) $ev->id; ?>" data-name="<?php echo esc_attr($ev->event_name); ?>"><?php echo self::icon('heart'); ?> Quiero que vuelva</button>
                        <?php endif; ?>
                        <p><a class="km-btn km-btn-ghost" href="<?php echo esc_url($home); ?>#cartelera">Ver próximos eventos</a></p>
                    </div>
                </div>
            </section>
            <?php echo self::vote_modal();
            return ob_get_clean();
        } ?>
        <div class="km-wrap km-event-wrap">
            <a class="km-back" href="<?php echo esc_url($home); ?>#cartelera"><?php echo self::icon('back'); ?> Volver a eventos</a>
            <div class="km-event-grid">
                <div class="km-event-head">
                    <?php if (!empty($ev->category)): ?><p class="km-eyebrow"><?php echo esc_html($ev->category); ?></p><?php endif; ?>
                    <h1 class="km-event-title"><?php echo esc_html($ev->event_name); ?></h1>
                    <p class="km-event-meta"><?php echo esc_html($meta); ?></p>
                    <div class="km-event-media<?php echo $video ? ' has-video' : ''; ?>">
                        <figure class="km-event-poster"><?php echo self::img($ev->image_url, 'Afiche de ' . $ev->event_name, '', '(max-width: 800px) 60vw, 360px', true); ?></figure>
                        <?php if ($video): ?><div class="km-event-video"><?php echo $video; ?></div><?php endif; ?>
                    </div>
                    <?php if (trim($ev->description)): ?><div class="km-event-lead"><?php echo wp_kses_post(wpautop(wp_trim_words(wp_strip_all_tags($ev->description), 28))); ?></div><?php endif; ?>
                    <ul class="km-facts">
                        <li><span>Fecha</span><strong><?php echo esc_html(KM_Util::date_human($ev->event_date)); ?></strong></li>
                        <li><span>Show</span><strong><?php echo esc_html(KM_Events::show_time($ev)); ?> h</strong></li>
                        <?php if ($slots): ?><li><span>Llegada</span><strong><?php echo esc_html(count($slots) > 1 ? $slots[0] . ' a ' . end($slots) : $slots[0]); ?> h</strong></li><?php endif; ?>
                        <li><span><?php echo $ev->payment_mode === 'abono' ? 'Abono' : 'Entrada'; ?></span><strong><?php echo esc_html(self::price_label($ev)); ?></strong></li>
                        <?php if ($ev->payment_mode === 'abono' && $ev->min_consumption > 0): ?><li><span>Consumo mínimo</span><strong><?php echo esc_html(KM_Util::money($ev->min_consumption)); ?> p/p</strong></li><?php endif; ?>
                        <li><span>Disponibilidad</span><strong><?php echo $av['sold_out'] ? 'Agotado' : ($av['low'] ? ($av['free'] === 1 ? 'Queda 1 ' : 'Quedan ' . (int) $av['free'] . ' ') . esc_html($av['noun']) : ($av['unit'] === 'seat' ? 'Puestos disponibles' : 'Mesas disponibles')); ?></strong></li>
                    </ul>
                </div>
                <aside class="km-event-book" id="reservar" aria-label="Reserva">
                    <?php if ($open): echo self::booking_app($ev, true);
                    elseif ($av['sold_out']): ?>
                        <div class="km-panel-box km-closed">
                            <p class="km-badge km-badge-off">Agotado</p>
                            <h2>No quedan <?php echo esc_html($av['unit'] === 'seat' ? 'puestos' : 'mesas'); ?> en línea</h2>
                            <?php if (KM_Util::site('whatsapp_attention')): ?><a class="km-btn km-btn-block" href="<?php echo esc_url(KM_Util::whatsapp_link(KM_Util::site('whatsapp_attention'), 'Hola, ¿quedan cupos para ' . $ev->event_name . '?')); ?>" target="_blank" rel="noopener">Consultar por WhatsApp</a><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="km-panel-box km-closed"><h2>Reservas en línea cerradas</h2><p class="km-small">Las reservas para este evento cierran al comenzar el show.</p></div>
                    <?php endif; ?>
                </aside>
                <div class="km-event-details">
                    <?php if (trim($ev->description)): ?><div class="km-prose"><h2>Sobre el espectáculo</h2><?php echo wpautop(wp_kses_post($ev->description)); ?></div><?php endif; ?>
                    <?php if (trim((string) $ev->conditions) || trim((string) $ev->popup_text) || (int) $ev->tolerance_min): ?>
                        <details class="km-details" open><summary>Condiciones</summary>
                            <?php if (trim((string) $ev->conditions)) echo wpautop(esc_html($ev->conditions)); ?>
                            <?php if (trim((string) $ev->popup_text)) echo wpautop(esc_html($ev->popup_text)); ?>
                            <?php if ((int) $ev->tolerance_min): ?><p>Tolerancia de llegada: <?php echo (int) $ev->tolerance_min; ?> minutos después de la hora elegida.</p><?php endif; ?>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($open): ?>
            <div class="km-sticky-cta"><span><?php echo esc_html(self::price_label($ev)); ?></span><a class="km-btn" href="#reservar"><?php echo KM_Floor::seat_mode() ? 'Elegir puestos' : 'Elegir mesa'; ?></a></div>
        <?php endif;
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Booking app
    // ------------------------------------------------------------------

    /** [km_reservas]: conventional reservations, or a hidden event reached by its private link. */
    public static function sc_booking() {
        return self::booking_app(self::requested_event(), false);
    }

    public static function booking_app($ev, $embedded) {
        static $done = false;
        if ($done) return '';
        $done = true;
        wp_enqueue_script('km-booking', KM_URL . 'assets/js/booking.js', [], KM_VERSION, true);
        $cfg = [
            'rest' => esc_url_raw(rest_url(KM_Rest::NS)),
            'event' => $ev ? KM_Events::to_array($ev, true) : null,
            'open' => $ev ? (KM_Events::booking_open($ev) && !KM_Events::availability($ev)['sold_out']) : true,
            'unit' => KM_Floor::seat_mode() ? 'seat' : 'table',
            'tables' => KM_Floor::plan_for_js(true),
            'extraChair' => (int) KM_Util::setting('extra_chair'),
            'distance' => (float) KM_Util::setting('adjacency_distance'),
            'explicitNeighbors' => (bool) array_filter(array_column(KM_Floor::plan(), 'neighbors')),
            'maxGuests' => $ev ? KM_Events::max_guests($ev) : (int) KM_Util::setting('max_guests'),
            'minGuests' => max(1, (int) KM_Util::setting('min_guests')),
            'convDeposit' => (int) KM_Util::setting('conv_deposit_pp'),
            'terms' => $ev ? ($ev->popup_text ?: KM_Util::setting('terms_default')) : KM_Util::setting('terms_default'),
            'holdMinutes' => (int) KM_Util::setting('hold_minutes'),
            'today' => KM_Util::today(),
            'maxDate' => KM_Util::now()->modify('+90 days')->format('Y-m-d'),
            'whatsapp' => KM_Util::whatsapp_link(KM_Util::site('whatsapp_attention'), 'Hola, quiero reservar para un grupo grande'),
            'home' => self::home_url(),
        ];
        wp_localize_script('km-booking', 'KM_BOOKING', $cfg);
        ob_start();
        include KM_PATH . 'templates/booking.php';
        return ob_get_clean();
    }

    /** Floor plan SVG shared by the public map and the panel (tables and chairs are drawn in JS). */
    public static function floor_svg($id = 'km-floor', $light = false) {
        $walls = 'M149 350 L486 349 M147 349 L146 1098 M145 1102 L497 1100 M497 1099 L652 1332 M485 349 L578 476 M737 380 L579 477 M823 505 L736 380 M676 606 L824 505 M753 720 L676 606 M753 923 L753 720 M792 925 L752 923 M796 1229 L794 927 M690 740 L692 1233 M796 1229 L693 1232 M693 925 L750 922 M745 1330 L744 1233 M652 1334 L745 1331 M419 352 L692 741 M298 352 L298 980 M255 977 L257 353 M301 637 L617 639 M675 605 L579 476 M736 780 L773 778';
        return '<svg id="' . esc_attr($id) . '" class="km-floor' . ($light ? ' km-floor-light' : '') . '" viewBox="120 320 760 1050" preserveAspectRatio="xMidYMid meet" role="group" aria-label="Plano del salón">'
            . '<path d="' . $walls . '" class="muro"/>'
            . '<g class="escenario"><rect x="700" y="934" width="86" height="287" rx="43"/><text x="743" y="1077" transform="rotate(90, 743, 1077)" text-anchor="middle" dominant-baseline="middle">ESCENARIO</text></g>'
            . '<g class="km-tables"></g></svg>';
    }

    // ------------------------------------------------------------------
    // Voucher
    // ------------------------------------------------------------------

    public static function voucher_page() {
        $b = KM_Bookings::get((int) ($_GET['r'] ?? 0));
        if (!$b || !hash_equals(KM_Util::voucher_key($b), (string) ($_GET['k'] ?? ''))) {
            return '<section class="km-wrap km-empty"><h1>Comprobante no encontrado</h1><p>Revisa el enlace que recibiste por correo o WhatsApp.</p></section>';
        }
        ob_start();
        include KM_PATH . 'templates/voucher.php';
        return ob_get_clean();
    }
}
