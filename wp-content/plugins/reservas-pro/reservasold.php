<?php
/**
 * Plugin Name: KaceMaster Elite Reservations (Mod V17 - Salon Logic)
 * Description: Sistema Full Screen. Corrección: Salta mapa si no es evento y permite selección de Salón.
 * Version: 17.0
 * Author: Tu Nombre
 * Text Domain: kacemaster-elite
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('KaceMaster_Elite')) {

    class KaceMaster_Elite {

        private $wpdb;
        private $table_name;
        private $table_events;
        private $tables_config;
        private $product_id;

        public function __construct() {
            global $wpdb;
            $this->wpdb = $wpdb;
            $this->table_name = $wpdb->prefix . 'km_bookings';
            $this->table_events = $wpdb->prefix . 'km_events';
            
            $this->load_tables_config();

            register_activation_hook(__FILE__, [$this, 'activate_plugin']);
            
            add_action('init', [$this, 'init_plugin'], 20);

            add_shortcode('km_reservas', [$this, 'render_frontend']);
            
            add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

            // Botón WhatsApp global en todo el sitio
            add_action('wp_footer', [$this, 'render_whatsapp_button']);

            $actions = ['km_fetch_occupied', 'km_initiate_reservation', 'km_admin_update_reservation', 'km_get_adjacent_tables', 'km_send_reminder', 'km_create_event_link', 'km_delete_event'];
            foreach($actions as $action) {
                add_action('wp_ajax_' . $action, [$this, 'ajax_' . str_replace('km_', '', $action)]);
                if(in_array($action, ['km_fetch_occupied', 'km_initiate_reservation'])) {
                    add_action('wp_ajax_nopriv_' . $action, [$this, 'ajax_' . str_replace('km_', '', $action)]);
                }
            }

            // WooCommerce Hooks
            if (class_exists('WooCommerce')) {
                add_action('woocommerce_before_calculate_totals', [$this, 'wc_override_price'], 99, 1);
                add_action('woocommerce_checkout_create_order_line_item', [$this, 'wc_add_reservation_meta'], 10, 4);
                add_action('woocommerce_order_status_processing', [$this, 'wc_confirm_reservation']);
                add_action('woocommerce_order_status_on-hold', [$this, 'wc_confirm_reservation']);
                add_action('woocommerce_order_status_completed', [$this, 'wc_confirm_reservation']);
                add_action('woocommerce_order_status_cancelled', [$this, 'wc_cancel_reservation']);
                add_action('woocommerce_order_status_failed', [$this, 'wc_cancel_reservation']);
                add_action('woocommerce_email_order_meta', [$this, 'wc_email_add_reservation_details'], 10, 3);
            }
        }

        public function init_plugin() {
            $this->check_create_product();
        }

        private function load_tables_config() {
            // Configuración de mesas igual que antes
            $this->tables_config = [
                ['id'=>1, 'x'=>198.7, 'y'=>396.4, 't'=>'t2', 'c'=>2], ['id'=>2, 'x'=>197.5, 'y'=>461.9, 't'=>'t2', 'c'=>2],
                ['id'=>3, 'x'=>361.1, 'y'=>395.3, 't'=>'t4', 'c'=>4], ['id'=>4, 'x'=>415.3, 'y'=>492.9, 't'=>'t4', 'c'=>4],
                ['id'=>5, 'x'=>495.9, 'y'=>583.8, 't'=>'t4', 'c'=>4], ['id'=>6, 'x'=>358.4, 'y'=>581.3, 't'=>'t2', 'c'=>2],
                ['id'=>7, 'x'=>196.5, 'y'=>526.1, 't'=>'t2', 'c'=>2], ['id'=>8, 'x'=>332.1, 'y'=>680.1, 't'=>'t2', 'c'=>2],
                ['id'=>9, 'x'=>197.1, 'y'=>593.4, 't'=>'t4', 'c'=>4], ['id'=>10, 'x'=>202.2, 'y'=>733.5, 't'=>'t4', 'c'=>4],
                ['id'=>11, 'x'=>199.6, 'y'=>663.2, 't'=>'t4', 'c'=>4], ['id'=>12, 'x'=>202.6, 'y'=>794.1, 't'=>'t2', 'c'=>2],
                ['id'=>13, 'x'=>203.2, 'y'=>925.0, 't'=>'t4', 'c'=>4], ['id'=>14, 'x'=>202.6, 'y'=>855.8, 't'=>'t4', 'c'=>4],
                ['id'=>15, 'x'=>203.8, 'y'=>987.9, 't'=>'t2', 'c'=>2], ['id'=>16, 'x'=>206.2, 'y'=>1051.8, 't'=>'t4', 'c'=>4],
                ['id'=>17, 'x'=>465.0, 'y'=>675.9, 't'=>'t4', 'c'=>4], ['id'=>18, 'x'=>465.0, 'y'=>745.0, 't'=>'t4', 'c'=>4],
                ['id'=>19, 'x'=>556.0, 'y'=>675.9, 't'=>'t4', 'c'=>4], ['id'=>20, 'x'=>556.5, 'y'=>743.8, 't'=>'t4', 'c'=>4],
                ['id'=>21, 'x'=>331.0, 'y'=>743.8, 't'=>'t2', 'c'=>2], ['id'=>22, 'x'=>328.6, 'y'=>886.8, 't'=>'t2', 'c'=>2],
                ['id'=>23, 'x'=>328.7, 'y'=>945.2, 't'=>'t2', 'c'=>2], ['id'=>24, 'x'=>445.0, 'y'=>808.6, 't'=>'t2', 'c'=>2],
                ['id'=>25, 'x'=>486.2, 'y'=>864.4, 't'=>'t4', 'c'=>4], ['id'=>26, 'x'=>582.4, 'y'=>873.0, 't'=>'t4', 'c'=>4],
                ['id'=>27, 'x'=>599.0, 'y'=>806.4, 't'=>'t2', 'c'=>2], ['id'=>28, 'x'=>485.6, 'y'=>937.6, 't'=>'t2', 'c'=>2],
                ['id'=>29, 'x'=>486.3, 'y'=>996.5, 't'=>'t2', 'c'=>2], ['id'=>30, 'x'=>549.2, 'y'=>936.4, 't'=>'t2', 'c'=>2],
                ['id'=>31, 'x'=>608.0, 'y'=>937.7, 't'=>'t2', 'c'=>2], ['id'=>32, 'x'=>551.7, 'y'=>996.6, 't'=>'t2', 'c'=>2],
                ['id'=>33, 'x'=>610.4, 'y'=>997.0, 't'=>'t2', 'c'=>2], ['id'=>34, 'x'=>548.4, 'y'=>1122.5, 't'=>'t2', 'c'=>2],
                ['id'=>35, 'x'=>609.6, 'y'=>1123.5, 't'=>'t2', 'c'=>2], ['id'=>36, 'x'=>727.2, 'y'=>796.1, 't'=>'t2', 'c'=>2],
                ['id'=>37, 'x'=>729.0, 'y'=>852.7, 't'=>'t2', 'c'=>2], ['id'=>38, 'x'=>725.8, 'y'=>435.5, 't'=>'t4', 'c'=>4],
                ['id'=>39, 'x'=>652.6, 'y'=>481.0, 't'=>'t4', 'c'=>4], ['id'=>40, 'x'=>722.6, 'y'=>526.4, 't'=>'t4', 'c'=>4]
            ];
        }

        public function activate_plugin() {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset_collate = $this->wpdb->get_charset_collate();
            
            // ACTUALIZACIÓN: Se agrega columna 'salon_type'
            $s1 = "CREATE TABLE $this->table_name ( 
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
                is_event tinyint(1) DEFAULT 0, 
                event_name varchar(150) DEFAULT NULL,
                status varchar(20) DEFAULT 'pending_payment', 
                advance_payment decimal(10,2) DEFAULT 0.00, 
                wc_order_id bigint(20) DEFAULT 0, 
                created_at datetime DEFAULT CURRENT_TIMESTAMP, 
                PRIMARY KEY (id), KEY booking_date (booking_date) 
            ) $charset_collate;";
            dbDelta($s1);

            $s2 = "CREATE TABLE $this->table_events ( 
                id mediumint(9) NOT NULL AUTO_INCREMENT, 
                event_name varchar(150) NOT NULL, 
                event_date date NOT NULL, 
                price_per_person decimal(10,2) DEFAULT 2000.00,
                popup_text text DEFAULT NULL,
                generated_link text NOT NULL, 
                created_at datetime DEFAULT CURRENT_TIMESTAMP, 
                PRIMARY KEY (id) 
            ) $charset_collate;";
            dbDelta($s2);
        }

        public function check_create_product() {
            if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) return;
            $pid = get_option('km_reservation_product_id');
            if (!$pid || !get_post($pid)) {
                $product = new WC_Product_Simple();
                $product->set_name('Anticipo Reserva - Cacho e Cabra');
                $product->set_slug('anticipo-reserva');
                $product->set_regular_price(0);
                $product->set_virtual(true);
                $product->set_catalog_visibility('hidden');
                $product->set_description('Anticipo consumible.');
                $pid = $product->save();
                update_option('km_reservation_product_id', $pid);
            } else {
                $product = wc_get_product($pid);
                if($product && $product->get_regular_price() != 0) {
                    $product->set_regular_price(0);
                    $product->save();
                }
            }
            $this->product_id = $pid;
        }
        
        public function enqueue_frontend_assets() {
            wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
            wp_enqueue_style('google-fonts-km', 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap');
            wp_enqueue_style('font-awesome-km', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', [], null);
        }

        // ==========================================
        // BOTÓN WHATSAPP GLOBAL (TODAS LAS PÁGINAS)
        // ==========================================
        public function render_whatsapp_button() {
            $wa_num = get_option('km_whatsapp_number', '');
            $wa_img = get_option('km_whatsapp_image', '');
            
            if (empty($wa_num)) return;

            $clean_num = preg_replace('/\D/', '', $wa_num);
            $wa_url = 'https://wa.me/' . $clean_num . '?text=Hola%2C%20quiero%20reservar%20una%20mesa%20en%20Cacho%20e%20Cabra';
            ?>
            <style>
                #km-wa-float-global { 
                    position: fixed; 
                    bottom: 25px; 
                    left: 25px; 
                    width: 65px; 
                    height: 65px; 
                    background: #25D366; 
                    border-radius: 50%; 
                    display: flex; 
                    align-items: center; 
                    justify-content: center; 
                    box-shadow: 0 5px 15px rgba(0,0,0,0.4); 
                    z-index: 100000; 
                    text-decoration: none; 
                    transition: all .3s ease; 
                    overflow: hidden; 
                }
                #km-wa-float-global:hover { transform: scale(1.1); }
                #km-wa-float-global img { width: 100%; height: 100%; object-fit: cover; }
                @media (max-width: 768px) { 
                    #km-wa-float-global { 
                        width: 55px; 
                        height: 55px; 
                        bottom: 20px; 
                        left: 20px; 
                    } 
                }
            </style>
            <div id="km-wa-float-global">
                <a href="<?php echo esc_url($wa_url); ?>" target="_blank" style="display:flex; align-items:center; justify-content:center; width:100%; height:100%;">
                    <?php if (!empty($wa_img)): ?>
                        <img src="<?php echo esc_url($wa_img); ?>" alt="WhatsApp">
                    <?php else: ?>
                        <i class="fab fa-whatsapp" style="font-size:32px; color:#fff;"></i>
                    <?php endif; ?>
                </a>
            </div>
            <?php
        }

        // ==========================================
        // FRONTEND
        // ==========================================
        public function render_frontend($atts) {
            $is_event = isset($_GET['evento']) && $_GET['evento'] === 'si';
            $event_name_get = isset($_GET['nombre_evento']) ? sanitize_text_field($_GET['nombre_evento']) : '';
            $pre_date = isset($_GET['fecha']) ? sanitize_text_field($_GET['fecha']) : '';
            $val_d = '';
            
            $price_pp = (int)get_option('km_price_per_person', 2000);
            $popup_txt = get_option('km_popup_text', 'El anticipo es consumible pero NO tiene devolución.');
            
            $raw_open = get_option('km_open_time');
            $raw_close = get_option('km_close_time');
            $open_time = !empty($raw_open) ? $raw_open : '12:00';
            $close_time = !empty($raw_close) ? $raw_close : '21:00';

            if ($is_event && !empty($pre_date) && !empty($event_name_get)) {
                $dt = DateTime::createFromFormat('Y-m-d', $pre_date);
                if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $pre_date);
                
                if ($dt) {
                    $val_d = $dt->format('Y-m-d');
                    $evt_data = $this->wpdb->get_row($this->wpdb->prepare(
                        "SELECT price_per_person, popup_text FROM $this->table_events WHERE event_date = %s AND event_name = %s", 
                        $val_d, $event_name_get
                    ));

                    if ($evt_data) {
                        if (!empty($evt_data->price_per_person)) $price_pp = (int)$evt_data->price_per_person;
                        if (!empty($evt_data->popup_text)) $popup_txt = $evt_data->popup_text;
                    }
                }
            }
            $ro = $val_d ? 'readonly' : ''; 
            $logo_url = trim(get_option('km_logo_url')); 
            
            ob_start();
            ?>
            <style>
                html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow-x: hidden; background: #000; }
                .elementor-section, .elementor-column, .elementor-widget { width: 100% !important; max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
                :root { --km-accent: #c0392b; --km-blue: #3498db; --km-glass: rgba(20, 20, 20, 0.85); --km-border: rgba(255,255,255,0.1); --km-wood: #8d6e63; }
                #km-app { font-family: 'Outfit', sans-serif; color: #fff; width: 100vw; min-height: 100vh; background: radial-gradient(circle at center, #1a1a1a 0%, #000 100%); display: flex; align-items: center; justify-content: center; position: relative; }
                #km-app .hidden { display: none !important; }
                .km-card-float { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 90%; max-width: 450px; background: var(--km-glass); border: 1px solid #333; border-radius: 20px; padding: 30px; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
                .km-input { background: rgba(255,255,255,0.1); border: 1px solid #333; border-radius: 8px; padding: 12px; color: white; width: 100%; font-family: inherit; margin-bottom: 10px; box-sizing: border-box; }
                select.km-input { background: rgba(255,255,255,0.1) !important; color: #fff !important; }
                .km-input option { background: #111 !important; color: #fff !important; }
                .km-input:focus { border-color: var(--km-accent); outline: none; }
                .km-input:disabled { opacity: 0.6; cursor: not-allowed; border-color: #555; }
                label { font-size: 0.75rem; color: #888; font-weight: 700; text-transform: uppercase; margin-bottom: 5px; display: block; }
                .btn-elite { background: var(--km-accent); color: white; border: none; border-radius: 12px; padding: 15px; font-weight: 800; cursor: pointer; width: 100%; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 5px 15px rgba(192,57,43,0.3); transition: 0.3s; }
                .btn-elite:hover { background: #e74c3c; transform: translateY(-2px); }
                #km-step-2 { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #000; z-index: 50; display: flex; flex-direction: column; }
                .km-map-container { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; overflow: hidden; }
                .km-svg-full { width: 100%; height: 100%; max-height: 85vh; display: block; touch-action: manipulation; }
                .w { stroke: #fff; stroke-width: 4; stroke-linecap: round; fill: none; opacity: 0.8; }
                .m-g { cursor: pointer; -webkit-tap-highlight-color: transparent; --mesa-fill: var(--km-wood); --mesa-stroke: #555; transition: transform 0.2s; }
                .mesa-shape { fill: var(--mesa-fill); stroke: var(--mesa-stroke); stroke-width: 1.5; transition: fill 0.3s, stroke 0.3s; }
                .silla-shape { fill: #222; stroke: #444; }
                .label-mesa { fill: white; font-weight: bold; font-size: 14px; pointer-events: none; text-anchor: middle; }
                .m-g.recommended { --mesa-fill: var(--km-blue); --mesa-stroke: rgba(255,255,255,0.5); animation: pulseBlue 2s infinite; }
                @keyframes pulseBlue { 0% { opacity: 1; } 50% { opacity: 0.8; } 100% { opacity: 1; } }
                .m-g.selected { --mesa-fill: var(--km-accent) !important; --mesa-stroke: white !important; opacity: 1 !important; animation: none !important; }
                .m-g.selected .mesa-shape { filter: drop-shadow(0 0 10px #c0392b); transform: scale(1.05); }
                .m-g.occupied { opacity: 0.2; pointer-events: none; filter: grayscale(1); }
                .km-bottom-bar { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); width: 90%; max-width: 500px; background: rgba(20,20,20,0.95); border: 1px solid var(--km-accent); border-radius: 40px; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; z-index: 9999; backdrop-filter: blur(10px); }
                .swal-terms-container { text-align: left; font-size: 0.9rem; margin-top: 15px; background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; border: 1px solid #444; }
                .swal-terms-container input { transform: scale(1.5); margin-right: 10px; }
                .km-logo-img { max-width: 180px; max-height: 80px; display: block; margin: 0 auto 15px auto; filter: drop-shadow(0 0 5px rgba(0,0,0,0.5)); object-fit: contain; }
                .km-event-title { text-align: center; color: #f39c12; margin-top: -10px; margin-bottom: 15px; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
                #km-country option { background: #000; color: #fff; }
            </style>
            <div id="km-full-screen-app" class="km-fullscreen-overlay">
                <div id="km-app">
                    <div id="km-step-1" class="km-card-float">
                        <?php if(!empty($logo_url)): ?>
                            <img src="<?php echo esc_url($logo_url); ?>" class="km-logo-img" alt="Logo">
                        <?php else: ?>
                            <h2 style="text-align:center; color:var(--km-accent); margin-top:0; letter-spacing: 2px;">RESERVAS ELITE</h2>
                        <?php endif; ?>

                        <?php if($event_name_get): ?>
                            <div class="km-event-title">Reservando para: <?php echo esc_html($event_name_get); ?></div>
                        <?php endif; ?>
                        
                        <div style="margin-bottom:15px;">
                            <label>Nombre</label><input type="text" id="km-name" class="km-input">
                            <label>WhatsApp</label>
                            <div style="display:flex; gap:10px;">
                                <select id="km-country" class="km-input" style="width:160px; font-size:0.9rem;">
                                    <option value="+56" selected>Chile (+56)</option>
                                    <option value="+54">Argentina (+54)</option>
                                    <option value="+591">Bolivia (+591)</option>
                                    <option value="+55">Brasil (+55)</option>
                                    <option value="+1">Canadá (+1)</option>
                                    <option value="+57">Colombia (+57)</option>
                                    <option value="+593">Ecuador (+593)</option>
                                    <option value="+1">EE.UU (+1)</option>
                                    <option value="+52">México (+52)</option>
                                    <option value="+595">Paraguay (+595)</option>
                                    <option value="+51">Perú (+51)</option>
                                    <option value="+598">Uruguay (+598)</option>
                                    <option value="+58">Venezuela (+58)</option>
                                    <option disabled>--- Europa ---</option>
                                    <option value="+49">Alemania (+49)</option>
                                    <option value="+34">España (+34)</option>
                                    <option value="+33">Francia (+33)</option>
                                    <option value="+39">Italia (+39)</option>
                                    <option value="+44">Reino Unido (+44)</option>
                                </select>
                                <input type="tel" id="km-phone" class="km-input" placeholder="9 1234 5678" style="flex:1;">
                            </div>
                            
                            <label>Email</label><input type="email" id="km-email" class="km-input">
                            <div style="display: flex; gap: 10px;">
                                <div style="flex: 1;"><label>Fecha</label><input type="date" id="km-date" value="<?php echo $val_d; ?>" class="km-input" <?php echo $ro; ?>></div>
                                <div style="flex: 1;">
                                    <label>Hora</label>
                                    <select id="km-time" class="km-input" <?php echo $is_event ? 'disabled' : ''; ?>>
                                    <?php 
                                    if($is_event): 
                                        echo '<option value="21:30" selected>21:30</option>'; 
                                    else: 
                                        echo '<option value="">--:--</option>'; 
                                        $base_date = '2000-01-01';
                                        $start_ts = strtotime("$base_date $open_time");
                                        $end_ts = strtotime("$base_date $close_time");
                                        if ($end_ts < $start_ts) $end_ts = strtotime("$base_date $close_time +1 day");
                                        $current = $start_ts;
                                        while($current <= $end_ts){ 
                                            $ts = date('H:i', $current); 
                                            echo "<option value='$ts'>$ts</option>"; 
                                            $current = strtotime('+30 minutes', $current); 
                                        } 
                                    endif; 
                                    ?>
                                    </select>
                                </div>
                            </div>
                            
                            <?php if (!$is_event): ?>
                            <label>Ubicación Preferida</label>
                            <select id="km-salon" class="km-input">
                                <option value="">Selecciona una opción...</option>
                                <option value="Salón de Vinos">Salón de Vinos</option>
                                <option value="Salón de Velas">Salón de Velas</option>
                            </select>
                            <?php else: ?>
                                <input type="hidden" id="km-salon" value="">
                            <?php endif; ?>

                            <label>Personas (<?php echo number_format($price_pp, 0, ',', '.'); ?> CLP / pers)</label>
                            <select id="km-guests" class="km-input"><?php for($i=1; $i<=10; $i++) echo "<option value='$i'>$i Personas</option>"; ?></select>
                        </div>
                        <input type="hidden" id="km-is-event" value="<?php echo $is_event ? '1' : '0'; ?>">
                        <input type="hidden" id="km-event-name" value="<?php echo esc_attr($event_name_get); ?>">
                        <button class="btn-elite" onclick="KM.init()">
                            <?php echo $is_event ? 'SELECCIONAR MESAS' : 'CONFIRMAR RESERVA'; ?>
                        </button>
                    </div>
                    
                    <div id="km-step-2" class="hidden" style="width:100%; height:100%;">
                        <button onclick="location.reload()" style="position:absolute; top:20px; left:20px; z-index:100; background:rgba(0,0,0,0.5); color:white; border:1px solid #555; padding:8px 15px; border-radius:20px; cursor:pointer;">&larr; Volver</button>
                        <div class="km-map-container">
                            <svg viewBox="120 320 760 1050" class="km-svg-full" preserveAspectRatio="xMidYMid meet">
                                <defs><g id="t2"><rect x="-17" y="-24" width="34" height="48" rx="4" class="mesa-shape"/><rect x="-24" y="-14" width="7" height="28" rx="2" class="silla-shape"/><rect x="17" y="-14" width="7" height="28" rx="2" class="silla-shape"/></g><g id="t4"><rect x="-24" y="-24" width="48" height="48" rx="4" class="mesa-shape"/><rect x="-14" y="-30" width="28" height="7" rx="2" class="silla-shape"/><rect x="-14" y="24" width="28" height="7" rx="2" class="silla-shape"/><rect x="-30" y="-14" width="7" height="28" rx="2" class="silla-shape"/><rect x="24" y="-14" width="7" height="28" rx="2" class="silla-shape"/></g></defs>
                                <g id="walls"><path d="M149 350 L486 349 M147 349 L146 1098 M145 1102 L497 1100 M497 1099 L652 1332" class="w"/><path d="M485 349 L578 476 M737 380 L579 477 M823 505 L736 380 M676 606 L824 505" class="w"/><path d="M753 720 L676 606 M753 923 L753 720 M792 925 L752 923 M796 1229 L794 927" class="w"/><path d="M690 740 L692 1233 M796 1229 L693 1232 M693 925 L750 922 M745 1330 L744 1233" class="w"/><path d="M652 1334 L745 1331 M419 352 L692 741 M298 352 L298 980 M255 977 L257 353" class="w"/><path d="M301 637 L617 639 M675 605 L579 476 M736 780 L773 778" class="w"/></g>
                                <g id="km-tables-group"></g>
                            </svg>
                        </div>
                        <div class="km-bottom-bar"><div style="color:white; font-size:0.9rem; line-height: 1.2;"><span id="km-status">Elige mesa</span><br><span id="km-count" style="color:var(--km-accent); font-weight:bold;">0/0</span></div><button id="km-confirm" disabled onclick="KM.preConfirm()" style="background:var(--km-accent); border:none; color:white; padding:8px 20px; border-radius:20px; font-weight:bold; opacity:0.5; cursor:not-allowed;">CONFIRMAR Y PAGAR</button></div>
                    </div>
                </div>
            </div>







            
            <script>
            const KM_CFG = { 
                ajax: "<?php echo admin_url('admin-ajax.php'); ?>", 
                tables: <?php echo json_encode($this->tables_config); ?>, 
                max_dist: 160,
                price_pp: <?php echo $price_pp; ?>,
                popup_text: `<?php echo addslashes($popup_txt); ?>`
            };
            
            const KM = {
                // Agregamos 'salon' al estado inicial
                st: { n:'', p:'', e:'', d:'', t:'', g:0, evt:0, evname:'', s:[], sd:[], cap:0, salon:'' },
                rec: [],
                
                init: function() {
                    const n = document.getElementById('km-name').value;
                    const e = document.getElementById('km-email').value;
                    const d = document.getElementById('km-date').value;
                    const t = document.getElementById('km-time').value;
                    const g = document.getElementById('km-guests').value;
                    const evt = document.getElementById('km-is-event').value;
                    const evname = document.getElementById('km-event-name').value;
                    
                    // NUEVO: Obtener valor del salón
                    const salonInput = document.getElementById('km-salon');
                    const salon = salonInput ? salonInput.value : '';

                    // CONCATENAR PAIS Y NUMERO
                    const code = document.getElementById('km-country').value;
                    const num = document.getElementById('km-phone').value;
                    
                    if(!num) return Swal.fire({icon:'error', title:'Faltan datos', text:'Ingresa tu teléfono', background:'#111', color:'#fff'});
                    const fullPhone = code + " " + num;

                    if(!n || !d || !t) return Swal.fire({icon:'error', title:'Faltan datos', text:'Revisa fecha y hora', background:'#111', color:'#fff'});
                    
                    // LÓGICA CONDICIONAL: EVENTO VS RESERVA NORMAL
                    if (evt !== '1') {
                        // === CASO RESERVA NORMAL (SIN MAPA) ===
                        if (!salon) return Swal.fire({icon:'warning', title:'Faltan datos', text:'Por favor selecciona si prefieres Salón de Vinos o Velas.', background:'#111', color:'#fff'});
                        
                        // Guardamos estado sin mesas (s:[])
                        this.st = { n:n, p:fullPhone, e:e, d:d, t:t, g:parseInt(g), evt:evt, evname:'', s:[], sd:[], cap:parseInt(g), salon: salon };
                        
                        // Saltamos directo a confirmar
                        this.preConfirm();

                    } else {
                        // === CASO EVENTO (CON MAPA) ===
                        this.st = { n:n, p:fullPhone, e:e, d:d, t:t, g:parseInt(g), evt:evt, evname:evname, s:[], sd:[], cap:0, salon: '' };
                        
                        const fd = new FormData(); fd.append('action','km_fetch_occupied'); fd.append('date',d);
                        
                        Swal.fire({title: 'Cargando mapa...', allowOutsideClick: false, didOpen:()=>{Swal.showLoading()}});
                        
                        fetch(KM_CFG.ajax, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{ 
                            Swal.close();
                            document.getElementById('km-step-1').classList.add('hidden'); 
                            document.getElementById('km-step-2').classList.remove('hidden'); 
                            this.recommend(res.occupied);
                            this.draw(res.occupied); 
                            this.upd(); 
                        });
                    }
                },

                recommend: function(occ) {
                    this.rec = []; const g = this.st.g; const free = KM_CFG.tables.filter(t => !occ.includes(t.id.toString()));
                    if (g <= 3) this.rec = free.filter(t => t.c === 2).map(t => t.id);
                    else if (g <= 5) this.rec = free.filter(t => t.c === 4).map(t => t.id);
                    else this.rec = free.map(t => t.id); 
                    
                    let msg = "Las mesas azules son las ideales para tu grupo.";
                    if(g === 3) msg = "Te recomendamos una mesa azul (para 2) y añadiremos una silla extra.";
                    if(g === 5) msg = "Te recomendamos una mesa azul (para 4) y añadiremos una silla extra.";
                    
                    Swal.fire({icon: 'info', title: 'Optimización de Espacio', text: msg, background: '#111', color: '#fff', confirmButtonColor: '#3498db'});
                },

                draw: function(occ) {
                    const c = document.getElementById('km-tables-group'); c.innerHTML = '';
                    KM_CFG.tables.forEach(t => {
                        const isOcc = occ.includes(t.id.toString());
                        const isRec = this.rec.includes(t.id);
                        const g = document.createElementNS("http://www.w3.org/2000/svg", "g");
                        g.setAttribute("class", `m-g ${isOcc?'occupied':''} \( {isRec?'recommended':''}`); g.setAttribute("transform", `translate( \){t.x},${t.y})`);
                        g.innerHTML = `<use href="#\( {t.t}"></use><text class="label-mesa" y="6"> \){t.id}</text>`;
                        if(!isOcc) g.onclick = () => this.tog(t, g);
                        c.appendChild(g);
                    });
                },

                tog: function(t, el) {
                    const idx = this.st.s.indexOf(t.id.toString());
                    if(idx > -1) { 
                        this.st.s.splice(idx,1); this.st.sd = this.st.sd.filter(x=>x.id!==t.id); this.st.cap -= this.getCap(t); el.classList.remove('selected'); 
                    } else { 
                        if (!this.rec.includes(t.id) && this.st.g < 6) return Swal.fire({icon:'warning', title:'Optimización', text:'Por favor selecciona una de las mesas azules asignadas.', background:'#111', color:'#fff'});
                        if(this.st.cap >= this.st.g) return Swal.fire({icon:'info', title:'Completo', text:'Suficientes puestos', background:'#111', color:'#fff'}); 
                        if(this.st.s.length > 0) { const near = this.st.sd.some(ex => Math.sqrt(Math.pow(ex.x-t.x,2)+Math.pow(ex.y-t.y,2)) <= KM_CFG.max_dist); if(!near) return Swal.fire({icon:'warning', title:'Mesas separadas', text:'Elige mesas juntas', background:'#111', color:'#fff'}); } 
                        this.st.s.push(t.id.toString()); this.st.sd.push(t); this.st.cap += this.getCap(t); el.classList.add('selected'); 
                    }
                    this.upd();
                },

                getCap: function(t) { if (this.st.g === 3 && t.c === 2) return 3; if (this.st.g === 5 && t.c === 4) return 5; return t.c; },
                
                upd: function() { const ok = this.st.cap >= this.st.g; const btn = document.getElementById('km-confirm'); btn.disabled = !ok; btn.style.opacity = ok ? '1' : '0.5'; btn.style.cursor = ok ? 'pointer' : 'not-allowed'; document.getElementById('km-count').innerText = `${this.st.cap} / ${this.st.g}`; document.getElementById('km-status').innerText = ok ? '¡Listo!' : 'Faltan sillas'; document.getElementById('km-status').style.color = ok ? '#27ae60' : '#fff'; },
                
                preConfirm: function() {
                    const total = this.st.g * KM_CFG.price_pp; 
                    const fmtTotal = total.toLocaleString('es-CL');
                    const fmtPrice = KM_CFG.price_pp.toLocaleString('es-CL');

                    // Personalizamos el mensaje según si es evento o normal
                    let detailHtml = '';
                    if (this.st.evt === '1') {
                         detailHtml = `<p>Reserva para el <strong>${this.st.d}</strong> (Evento).</p>`;
                    } else {
                         detailHtml = `
                            <p>Fecha: <strong>\( {this.st.d}</strong> | Hora: <strong> \){this.st.t}</strong></p>
                            <p style="color:var(--km-blue); font-weight:bold;">Ubicación: ${this.st.salon}</p>
                         `;
                    }

                    Swal.fire({
                        title: 'CONFIRMAR RESERVA',
                        html: `
                            <div style="text-align:left; color:#ccc; margin-bottom:10px;">
                                <p style="font-size:1.2rem; color:#f39c12; text-align:center;">Total a pagar: <strong>\[ {fmtTotal} CLP</strong></p>
                                <p style="font-size:0.8rem; text-align:center;">( \]{fmtPrice} x ${this.st.g} personas)</p>
                                <hr style="border-color:#333;">
                                ${detailHtml}
                            </div>
                            <div class="swal-terms-container">
                                <label style="display:flex; align-items:flex-start; cursor:pointer; color:#fff;">
                                    <input type="checkbox" id="km-terms" style="margin-top:4px;">
                                    <span>${KM_CFG.popup_text}</span>
                                </label>
                            </div>
                        `,
                        background: '#111', color: '#fff', confirmButtonText: 'IR A PAGAR', confirmButtonColor: '#27ae60', showCancelButton: true, cancelButtonText: 'Cancelar', cancelButtonColor: '#c0392b',
                        preConfirm: () => { if (!document.getElementById('km-terms').checked) return Swal.showValidationMessage('Debes aceptar los términos.'); }
                    }).then((result) => { if (result.isConfirmed) this.processPayment(); });
                },
                
                processPayment: function() {
                    Swal.fire({title: 'Procesando...', allowOutsideClick: false, didOpen:()=>{Swal.showLoading()}});
                    
                    const fd = new FormData(); 
                    fd.append('action','km_initiate_reservation'); 
                    fd.append('name',this.st.n); 
                    fd.append('phone',this.st.p); 
                    fd.append('email',this.st.e); 
                    fd.append('date',this.st.d); 
                    fd.append('time',this.st.t); 
                    fd.append('guests',this.st.g); 
                    fd.append('tables',JSON.stringify(this.st.s)); 
                    fd.append('is_event',this.st.evt);
                    fd.append('event_name',this.st.evname); 
                    // ENVIAMOS EL SALÓN
                    fd.append('salon_type', this.st.salon);

                    fetch(KM_CFG.ajax, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{ if(res.success && res.redirect) window.location.href = res.redirect; else Swal.fire('Error', 'No se pudo iniciar el pago', 'error'); }); 
                }
            };
            </script>
            <?php
        }

        // ==========================================
        // ADMIN 
        // ==========================================
        public function add_admin_menu() { 
            add_menu_page('Reservas', 'Reservas Elite', 'manage_options', 'km-reservas', [$this, 'render_admin_dashboard'], 'dashicons-calendar-alt', 6);
            add_submenu_page('km-reservas', 'Generador Links', 'Generador Links', 'manage_options', 'km-generador', [$this, 'render_admin_events']);
            add_submenu_page('km-reservas', 'Configuración', 'Configuración', 'manage_options', 'km-settings', [$this, 'render_admin_settings']);
        }
        public function enqueue_admin_assets($hook) { 
            if (strpos($hook, 'km-') === false) return; 
            wp_enqueue_media();
            wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css'); 
            wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js', ['jquery'], null, true); 
            wp_enqueue_script('sweetalert2-admin', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true); 
            wp_enqueue_script('html2pdf', 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js', [], null, true); 
            wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'); 
        }
        
        // TAB: CONFIGURACIÓN
        public function render_admin_settings() {
            if(isset($_POST['km_save_settings'])) {
                update_option('km_logo_url', sanitize_text_field($_POST['km_logo_url']));
                update_option('km_price_per_person', intval($_POST['km_price_per_person']));
                update_option('km_popup_text', sanitize_textarea_field($_POST['km_popup_text']));
                update_option('km_open_time', sanitize_text_field($_POST['km_open_time']));
                update_option('km_close_time', sanitize_text_field($_POST['km_close_time']));
                update_option('km_whatsapp_number', sanitize_text_field($_POST['km_whatsapp_number']));
                update_option('km_whatsapp_image', sanitize_text_field($_POST['km_whatsapp_image']));
                echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
            }
            $logo = get_option('km_logo_url');
            $price = get_option('km_price_per_person', 2000);
            $popup = get_option('km_popup_text', 'El anticipo es consumible pero NO tiene devolución.');
            $open = get_option('km_open_time', '12:00');
            $close = get_option('km_close_time', '21:00');
            $wa = get_option('km_whatsapp_number', '');
            $wa_img = get_option('km_whatsapp_image', '');
            ?>
            <div class="wrap km-wrap" style="padding:20px; background:#fff; margin-top:20px; border-radius:10px;">
                <h1 style="border-bottom:2px solid #c0392b; padding-bottom:15px;">Configuración Global</h1>
                <form method="post" action="">
                    <table class="form-table">
                        <tr><th scope="row">Precio por Persona (Default)</th><td><input type="number" name="km_price_per_person" value="<?php echo esc_attr($price); ?>" class="regular-text"> CLP</td></tr>
                        <tr><th scope="row">Horario Servicio</th><td>Apertura: <input type="time" name="km_open_time" value="<?php echo esc_attr($open); ?>"> Cierre: <input type="time" name="km_close_time" value="<?php echo esc_attr($close); ?>"></td></tr>
                        <tr><th scope="row">Texto Pop-up (Términos)</th><td><textarea name="km_popup_text" rows="3" class="large-text"><?php echo esc_textarea($popup); ?></textarea></td></tr>
                        <tr><th scope="row">Logo</th><td><input type="text" name="km_logo_url" id="km_logo_url" value="<?php echo esc_attr($logo); ?>" class="regular-text"><button type="button" class="button" id="upload_logo_btn">Elegir</button><br><img id="logo_preview" src="<?php echo $logo; ?>" style="max-width:150px; margin-top:10px;"></td></tr>
                        <tr><th scope="row">Número de WhatsApp (botón flotante)</th><td><input type="tel" name="km_whatsapp_number" value="<?php echo esc_attr($wa); ?>" class="regular-text" placeholder="+56912345678"> (ej: +56912345678)</td></tr>
                        <tr><th scope="row">Imagen del botón WhatsApp</th><td><input type="text" name="km_whatsapp_image" id="km_whatsapp_image" value="<?php echo esc_attr($wa_img); ?>" class="regular-text"><button type="button" class="button" id="upload_wa_btn">Elegir Imagen</button><br><img id="wa_preview" src="<?php echo esc_url($wa_img); ?>" style="max-width:150px; margin-top:10px; border-radius:8px;"></td></tr>
                    </table>
                    <p class="submit"><button type="submit" name="km_save_settings" class="button button-primary">Guardar</button></p>
                </form>
            </div>
            <script>
            jQuery(document).ready(function($){
                $('#upload_logo_btn').click(function(e){ 
                    e.preventDefault(); 
                    var i=wp.media({title:'Logo',multiple:false}).open().on('select',function(){ 
                        var u=i.state().get('selection').first().toJSON().url; 
                        $('#km_logo_url').val(u); 
                        $('#logo_preview').attr('src',u); 
                    }); 
                });
                $('#upload_wa_btn').click(function(e){ 
                    e.preventDefault(); 
                    var i=wp.media({title:'Imagen WhatsApp',multiple:false}).open().on('select',function(){ 
                        var u=i.state().get('selection').first().toJSON().url; 
                        $('#km_whatsapp_image').val(u); 
                        $('#wa_preview').attr('src',u); 
                    }); 
                });
            });
            </script>
            <?php
        }

        // TAB: GESTIÓN DE RESERVAS
        public function render_admin_dashboard() {
            $filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
            $sql = "SELECT * FROM $this->table_name WHERE booking_date = %s AND status != 'cancelled' ORDER BY created_at DESC";
            $reservations = $this->wpdb->get_results($this->wpdb->prepare($sql, $filter_date));
            ?>
            <div class="wrap km-wrap">
                <div class="km-toolbar">
                    <h1 style="margin:0; font-size: 1.5rem;">Gestión de Reservas</h1>
                    <div style="display:flex; gap: 15px;">
                        <form method="GET" action="admin.php" class="km-filter-form"><input type="hidden" name="page" value="km-reservas"><label>Ver día:</label><input type="date" name="filter_date" value="<?php echo $filter_date; ?>" style="padding: 8px; border-radius: 5px; border: 1px solid #ccc;"><button type="submit" class="button button-primary">Filtrar</button></form>
                        <button class="km-btn-pdf" onclick="downloadPDF()"><span class="dashicons dashicons-pdf"></span> Descargar Lista</button>
                    </div>
                </div>
                <div class="km-card-admin" style="background:white; padding:20px; border-radius:10px;">
                    <table id="km-table" class="display" style="width:100%">
                        <thead><tr><th>ID</th><th>Cliente</th><th>Fecha</th><th>Hora</th><th>Salón/Ubicación</th><th>Evento</th><th>Mesas</th><th>Pax</th><th>Pago Total</th><th>Estado</th><th>Acciones</th></tr></thead>
                        <tbody>
                            <?php foreach($reservations as $r): $tables = json_decode($r->tables_data); ?>
                            <tr>
                                <td>#<?php echo $r->reservation_code; ?></td>
                                <td><?php echo esc_html($r->client_name); ?><br><small><?php echo $r->phone; ?></small><br><small style="color:#c0392b;"><?php echo $r->client_email; ?></small></td>
                                <td><?php echo $r->booking_date; ?></td>
                                <td style="font-weight:bold; color:#2c3e50;"><?php echo $r->booking_time ? $r->booking_time : '-'; ?></td>
                                <td style="font-weight:bold; color:#3498db;"><?php echo $r->salon_type ? esc_html($r->salon_type) : '-'; ?></td>
                                <td style="color:#f39c12; font-weight:bold; font-size:0.9em;"><?php echo $r->is_event ? ($r->event_name ? esc_html($r->event_name) : 'Evento') : '-'; ?></td>
                                <td><?php if(is_array($tables)) foreach($tables as $t) echo "<span class='badge-table' style='background:#c0392b; color:white; padding:2px 8px; border-radius:10px;'>M$t</span> "; ?></td>
                                <td><?php echo $r->guests; ?></td>
                                <td style="font-weight:bold;">$<?php echo number_format($r->advance_payment, 0, ',', '.'); ?></td>
                                <td style="color: <?php echo $r->status=='confirmed'?'green':'orange'; ?>; font-weight: bold;"><?php echo $r->status == 'pending_payment' ? 'Pendiente Pago' : ucfirst($r->status); ?></td>
                                <td><button class="button button-primary" onclick='adminApp.openEdit(<?php echo json_encode($r); ?>)'>Editar</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div id="editModal" class="km-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; padding-top:50px;"><div class="km-modal" style="background:white; width:600px; padding:20px; border-radius:10px;"><h2 style="margin-top:0;">Editar Reserva</h2><input type="hidden" id="e_id"><div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;"><label>Nombre<input type="text" id="e_name" style="width:100%"></label><label>Teléfono<input type="text" id="e_phone" style="width:100%"></label><label>Email<input type="email" id="e_email" style="width:100%"></label><label>Fecha<input type="date" id="e_date" style="width:100%" onchange="adminApp.fetchAvailableTables()"></label><label>Hora<input type="time" id="e_time" style="width:100%"></label><label>Invitados<input type="number" id="e_guests" style="width:100%"></label>
            
            <label>Salón/Ubicación<select id="e_salon" style="width:100%"><option value="">-- Sin Salón --</option><option value="Salón de Vinos">Salón de Vinos</option><option value="Salón de Velas">Salón de Velas</option></select></label>
            
            <label>Pago ($)<input type="number" id="e_payment" style="width:100%"></label><label>Estado<select id="e_status" style="width:100%"><option value="confirmed">Confirmada</option><option value="pending_payment">Pendiente</option><option value="cancelled">Cancelada</option></select></label></div><div id="current-tables-list" style="margin:10px 0;"></div><select id="add-table-select" onchange="adminApp.addTable(this.value)" style="width:100%"><option>Añadir mesa...</option></select><div style="text-align:right; margin-top:15px;"><button class="button" onclick="adminApp.close()">Cerrar</button> <button id="btn-admin-save" class="button button-primary" onclick="adminApp.save()">Guardar</button></div></div></div>
            
            <div id="pdf-container" style="display:none;">
                <div style="padding: 40px; font-family: Arial, sans-serif; color: #000; background: #fff;">
                    <div style="text-align: center; margin-bottom: 30px; border-bottom: 2px solid #c0392b; padding-bottom: 15px;">
                        <h1 style="margin:0; font-size: 24px; color: #333; text-transform: uppercase;">Reporte de Asistentes</h1>
                        <p style="margin: 5px 0; color: #666;">Fecha: <strong><?php echo date('d/m/Y', strtotime($filter_date)); ?></strong></p>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead><tr style="background: #c0392b; color: white;"><th style="padding: 12px; border:1px solid #ccc;">Hora</th><th style="padding: 12px; border:1px solid #ccc;">Código</th><th style="padding: 12px; border:1px solid #ccc;">Cliente</th><th style="padding: 12px; border:1px solid #ccc;">Salón</th><th style="padding: 12px; border:1px solid #ccc;">Pax</th><th style="padding: 12px; border:1px solid #ccc;">Mesas</th></tr></thead>
                        <tbody>
                            <?php if(empty($reservations)): ?><tr><td colspan="6" style="text-align:center; padding:20px;">Sin reservas.</td></tr><?php else: foreach($reservations as $r): $tables = json_decode($r->tables_data); ?>
                            <tr><td style="padding:10px; border:1px solid #ccc; font-weight:bold;"><?php echo $r->booking_time; ?></td><td style="padding:10px; border:1px solid #ccc;"><?php echo $r->reservation_code; ?></td><td style="padding:10px; border:1px solid #ccc;"><?php echo esc_html($r->client_name); ?><br><small><?php echo $r->phone; ?></small></td><td style="padding:10px; border:1px solid #ccc;"><?php echo $r->salon_type; ?></td><td style="padding:10px; border:1px solid #ccc; text-align:center;"><?php echo $r->guests; ?></td><td style="padding:10px; border:1px solid #ccc; text-align:center; font-weight:bold;"><?php echo implode(', ', $tables); ?></td></tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) { $('#km-table').DataTable(); });
            function downloadPDF() { const element = document.getElementById('pdf-container'); element.style.display = 'block'; var opt = { margin: 0.5, filename: 'Reporte_Asistentes_<?php echo $filter_date; ?>.pdf', image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2 }, jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' } }; html2pdf().set(opt).from(element).save().then(function(){ element.style.display = 'none'; }); }
            
            const adminApp = {
                currentData: null, currentTables: [],
                openEdit: function(data) { 
                    this.currentData = data; 
                    try { this.currentTables = JSON.parse(data.tables_data).map(String); } catch(e) { this.currentTables = []; } 
                    document.getElementById('editModal').style.display = 'flex'; 
                    document.getElementById('e_id').value = data.id; 
                    document.getElementById('e_name').value = data.client_name; document.getElementById('e_phone').value = data.phone; 
                    document.getElementById('e_email').value = data.client_email || ''; document.getElementById('e_date').value = data.booking_date; 
                    document.getElementById('e_time').value = data.booking_time; 
                    document.getElementById('e_salon').value = data.salon_type || ''; // Cargar Salón
                    document.getElementById('e_guests').value = data.guests; document.getElementById('e_status').value = data.status; 
                    document.getElementById('e_payment').value = data.advance_payment; 
                    this.renderTables(); this.fetchAvailableTables(); 
                },
                close: function() { document.getElementById('editModal').style.display = 'none'; },
                renderTables: function() { const container = document.getElementById('current-tables-list'); container.innerHTML = ''; this.currentTables.forEach(tId => { const span = document.createElement('span'); span.style.cssText = "background:#c0392b; color:white; padding:2px 8px; border-radius:10px; margin-right:5px;"; span.innerHTML = `Mesa \( {tId} <span style='cursor:pointer; font-weight:bold;' onclick="adminApp.removeTable(' \){tId}')">x</span>`; container.appendChild(span); }); },
                removeTable: function(tId) { this.currentTables = this.currentTables.filter(id => id !== tId.toString()); this.renderTables(); this.fetchAvailableTables(); },
                addTable: function(tId) { if(!tId) return; if(!this.currentTables.includes(tId.toString())) { this.currentTables.push(tId.toString()); this.renderTables(); this.fetchAvailableTables(); document.getElementById('add-table-select').value = ""; } },
                fetchAvailableTables: function() { const date = document.getElementById('e_date').value, rId = document.getElementById('e_id').value; jQuery.post(ajaxurl, { action: 'km_get_adjacent_tables', date: date, current_tables: this.currentTables, reservation_id: rId }, (res) => { const select = document.getElementById('add-table-select'); select.innerHTML = '<option value="">Selecciona mesa cercana...</option>'; if(res.success && res.tables.length > 0) res.tables.forEach(t => { select.innerHTML += `<option value="${t.id}">Mesa ${t.id} (Cap: ${t.c})</option>`; }); else select.innerHTML += '<option disabled>No hay mesas cercanas libres</option>'; }, 'json'); },
                save: function() { 
                    const data = { 
                        action: 'km_admin_update_reservation', id: document.getElementById('e_id').value, 
                        name: document.getElementById('e_name').value, phone: document.getElementById('e_phone').value, 
                        email: document.getElementById('e_email').value, date: document.getElementById('e_date').value, 
                        time: document.getElementById('e_time').value, 
                        salon_type: document.getElementById('e_salon').value, // Guardar salón
                        guests: document.getElementById('e_guests').value, status: document.getElementById('e_status').value, 
                        payment: document.getElementById('e_payment').value, tables: JSON.stringify(this.currentTables) 
                    }; 
                    jQuery.post(ajaxurl, data, function(res) { if(res.success) { Swal.fire('Guardado', 'Datos actualizados', 'success').then(() => location.reload()); } }, 'json'); 
                },
                sendReminder: function() { const email = document.getElementById('e_email').value; if(!email) return Swal.fire('Falta Email', 'Escribe un correo.', 'warning'); Swal.fire({ title: 'Enviando...', text: 'Por favor espera', didOpen: () => { Swal.showLoading(); } }); const data = { action: 'km_send_reminder', email: email, name: document.getElementById('e_name').value, code: document.getElementById('e_code').value, date: document.getElementById('e_date').value, tables: JSON.stringify(this.currentTables) }; jQuery.post(ajaxurl, data, function(res) { if(res.success) Swal.fire('Enviado', 'Correo enviado.', 'success'); else Swal.fire('Error', 'Falló el envío.', 'error'); }, 'json'); }
            };
            </script>
            <?php
        }

        // TAB: GENERADOR DE EVENTOS
        public function render_admin_events() {
            $events = $this->wpdb->get_results("SELECT * FROM $this->table_events ORDER BY created_at DESC");
            ?>
            <div class="wrap km-wrap" style="padding:20px; background:#fff; margin-top:20px; border-radius:10px;">
                <h1 style="border-bottom:2px solid #c0392b; padding-bottom:15px;">Generador de Links para Eventos</h1>
                <div style="background:#f9f9f9; padding:20px; border-radius:10px; border:1px solid #ddd; margin-bottom:30px;">
                    <h3>Crear Nuevo Evento</h3>
                    <div style="display:grid; grid-template-columns: 2fr 1fr 1fr 2fr auto; gap:15px; align-items:end;">
                        <div><label style="font-weight:bold;">Nombre del Evento</label><input type="text" id="evt_name" class="widefat" placeholder="Ej: Cena de Año Nuevo"></div>
                        <div><label style="font-weight:bold;">Fecha</label><input type="date" id="evt_date" class="widefat"></div>
                        <div><label style="font-weight:bold;">Precio/Pers ($)</label><input type="number" id="evt_price" class="widefat" placeholder="Ej: 5000"></div>
                        <div><label style="font-weight:bold;">Términos (Pop-up)</label><input type="text" id="evt_popup" class="widefat" placeholder="Texto específico..."></div>
                        <button class="button button-primary" onclick="evtManager.create()" style="height:30px;">Generar Link</button>
                    </div>
                </div>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>ID</th><th>Evento</th><th>Fecha</th><th>Precio/Pax</th><th>Términos</th><th>Link</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php if(empty($events)): ?><tr><td colspan="7">No hay eventos creados.</td></tr><?php else: foreach($events as $ev): ?>
                        <tr>
                            <td><?php echo $ev->id; ?></td>
                            <td><strong><?php echo esc_html($ev->event_name); ?></strong></td>
                            <td><?php echo $ev->event_date; ?></td>
                            <td>$<?php echo number_format($ev->price_per_person,0,',','.'); ?></td>
                            <td><small><?php echo esc_html(substr($ev->popup_text,0,30)) . '...'; ?></small></td>
                            <td><input type="text" value="<?php echo esc_attr($ev->generated_link); ?>" readonly style="width:100%;" onclick="this.select()"></td>
                            <td><button class="button" onclick="evtManager.delete(<?php echo $ev->id; ?>)" style="color:#a00;">Borrar</button></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <script>
            const evtManager = {
                create: function() {
                    const n = document.getElementById('evt_name').value;
                    const d = document.getElementById('evt_date').value;
                    const p = document.getElementById('evt_price').value;
                    const t = document.getElementById('evt_popup').value;
                    if(!n || !d) return alert('Completa nombre y fecha');
                    jQuery.post(ajaxurl, {action:'km_create_event_link', name:n, date:d, price:p, popup:t}, function(res){ if(res.success) location.reload(); });
                },
                delete: function(id) {
                    if(confirm('¿Borrar este evento?')) jQuery.post(ajaxurl, {action:'km_delete_event', id:id}, function(res){ if(res.success) location.reload(); });
                }
            };
            </script>
            <?php
        }

        // ==========================================
        // LOGICA SERVIDOR (AJAX + WC)
        // ==========================================

        public function ajax_create_event_link() {
            if (!current_user_can('manage_options')) wp_die();
            $name = sanitize_text_field($_POST['name']);
            $date = sanitize_text_field($_POST['date']);
            $price = !empty($_POST['price']) ? floatval($_POST['price']) : get_option('km_price_per_person', 2000);
            $popup = !empty($_POST['popup']) ? sanitize_textarea_field($_POST['popup']) : get_option('km_popup_text');
            
            $link = site_url('/reservas-2') . '?fecha=' . $date . '&evento=si&nombre_evento=' . urlencode($name);
            
            $this->wpdb->insert($this->table_events, [
                'event_name'=>$name, 
                'event_date'=>$date, 
                'price_per_person' => $price,
                'popup_text' => $popup,
                'generated_link'=>$link
            ]);
            wp_send_json_success();
        }
        public function ajax_delete_event() { if (!current_user_can('manage_options')) wp_die(); $this->wpdb->delete($this->table_events, ['id'=>intval($_POST['id'])]); wp_send_json_success(); }

        public function ajax_fetch_occupied() {
            $d = sanitize_text_field($_POST['date']);
            $r = $this->wpdb->get_results($this->wpdb->prepare("SELECT tables_data FROM $this->table_name WHERE booking_date = %s AND status != 'cancelled'", $d), ARRAY_A);
            $o = []; foreach($r as $row) { $o = array_merge($o, json_decode($row['tables_data'],true)??[]); }
            wp_send_json(['occupied'=>array_values(array_unique($o))]);
        }

        public function ajax_initiate_reservation() {
            if (!class_exists('WooCommerce')) wp_send_json(['success'=>false, 'message'=>'WC Off']);
            
            $guests = intval($_POST['guests']);
            $evt_name = sanitize_text_field($_POST['event_name']);
            $evt_date = sanitize_text_field($_POST['date']);
            $is_evt = intval($_POST['is_event']);
            // CAPTURAR SALÓN
            $salon = isset($_POST['salon_type']) ? sanitize_text_field($_POST['salon_type']) : null;

            $price_pp = (int)get_option('km_price_per_person', 2000);
            
            if ($is_evt && $evt_name) {
                $evt_db = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT price_per_person FROM $this->table_events WHERE event_date = %s AND event_name = %s", 
                    $evt_date, $evt_name
                ));
                if ($evt_db && !empty($evt_db->price_per_person)) {
                    $price_pp = (int)$evt_db->price_per_person;
                }
            }

            $total_price = $guests * $price_pp;

            $c = strtoupper(substr(md5(uniqid()),0,6));
            $inserted = $this->wpdb->insert($this->table_name, [
                'reservation_code'=>$c, 'client_name'=>sanitize_text_field($_POST['name']),
                'phone'=>sanitize_text_field($_POST['phone']), 'client_email'=>sanitize_email($_POST['email']),
                'booking_date'=>$evt_date, 'booking_time'=>sanitize_text_field($_POST['time']), 
                'guests'=>$guests, 
                'salon_type' => $salon, // GUARDAR EN BD
                'tables_data'=>stripslashes($_POST['tables']), 
                'is_event'=>$is_evt, 'event_name'=>$evt_name, 
                'status' => 'pending_payment',
                'advance_payment' => $total_price 
            ]);
            if($inserted) {
                $reservation_id = $this->wpdb->insert_id;
                
                if(function_exists('WC') && WC()->cart) {
                    WC()->cart->empty_cart();
                    $pid = get_option('km_reservation_product_id');
                    WC()->cart->add_to_cart($pid, 1, 0, [], ['km_reservation_id' => $reservation_id, 'km_custom_price' => $total_price]);
                    wp_send_json(['success'=>true, 'redirect'=>wc_get_checkout_url(), 'code'=>$c]);
                } else {
                    wp_send_json(['success'=>false, 'message'=>'Error WC Cart']);
                }
            } else { wp_send_json(['success'=>false]); }
        }

        public function wc_add_reservation_meta($item, $cart_item_key, $values, $order) {
            if (isset($values['km_reservation_id'])) $item->add_meta_data('km_reservation_id', $values['km_reservation_id']);
            if (isset($values['km_custom_price'])) $item->add_meta_data('km_custom_price', $values['km_custom_price']);
        }

        public function wc_override_price($cart) {
            if (is_admin() && !defined('DOING_AJAX')) return;
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                if (isset($cart_item['km_custom_price'])) {
                    $cart_item['data']->set_price($cart_item['km_custom_price']);
                }
            }
        }

        public function wc_confirm_reservation($order_id) {
            $order = wc_get_order($order_id);
            if(!$order) return;
            foreach ($order->get_items() as $item) {
                $res_id = $item->get_meta('km_reservation_id');
                $paid = $item->get_total(); 
                if ($res_id) $this->wpdb->update($this->table_name, ['status' => 'confirmed', 'advance_payment' => $paid, 'wc_order_id' => $order_id], ['id' => $res_id]);
            }
        }
        public function wc_cancel_reservation($order_id) {
            $order = wc_get_order($order_id);
            if(!$order) return;
            foreach ($order->get_items() as $item) {
                $res_id = $item->get_meta('km_reservation_id');
                if ($res_id) $this->wpdb->update($this->table_name, ['status' => 'cancelled'], ['id' => $res_id]);
            }
        }
        public function wc_email_add_reservation_details($order, $sent_to_admin, $plain_text) {
            $res_id = false; foreach ($order->get_items() as $item) { if ($item->get_meta('km_reservation_id')) { $res_id = $item->get_meta('km_reservation_id'); break; } }
            if ($res_id) {
                $res = $this->wpdb->get_row("SELECT * FROM $this->table_name WHERE id = $res_id");
                if ($res) {
                    $tables = implode(', ', json_decode($res->tables_data));
                    echo '<div style="margin-top: 20px; padding: 20px; background-color: #fdf2f0; border: 1px solid #c0392b; border-radius: 5px; color: #333;">';
                    echo '<h2 style="color: #c0392b; margin-top:0;">Detalles de tu Reserva</h2>';
                    echo '<p><strong>Código:</strong> ' . $res->reservation_code . '</p>';
                    if($res->is_event && $res->event_name) echo '<p><strong>Evento:</strong> ' . $res->event_name . '</p>';
                    
                    // MOSTRAR SALÓN EN EMAIL
                    if($res->salon_type) echo '<p><strong>Ubicación:</strong> ' . $res->salon_type . '</p>';
                    
                    echo '<p><strong>Fecha:</strong> ' . date('d/m/Y', strtotime($res->booking_date)) . ' | ' . $res->booking_time . ' hrs</p>';
                    echo '<p><strong>Personas:</strong> ' . $res->guests . ($res->is_event ? ' (Mesas: ' . $tables . ')' : '') . '</p>';
                    echo '<hr style="border:0; border-top:1px solid #ccc; margin:15px 0;">';
                    echo '<p style="font-size:14px; color:#c0392b; font-weight:bold;">IMPORTANTE: El anticipo NO tiene devolución.</p>';
                    echo '</div>';
                }
            }
        }

        public function ajax_admin_update_reservation() {
            if (!current_user_can('manage_options')) wp_die();
            $id = intval($_POST['id']);
            $this->wpdb->update($this->table_name, [
                'client_name' => sanitize_text_field($_POST['name']), 'phone' => sanitize_text_field($_POST['phone']),
                'client_email' => sanitize_email($_POST['email']), 'booking_date' => sanitize_text_field($_POST['date']),
                'booking_time' => sanitize_text_field($_POST['time']), 
                'salon_type' => sanitize_text_field($_POST['salon_type']), // ACTUALIZAR SALÓN
                'guests' => intval($_POST['guests']), 'status' => sanitize_text_field($_POST['status']),
                'advance_payment' => floatval($_POST['payment']), 'tables_data' => stripslashes($_POST['tables'])
            ], ['id' => $id]);
            wp_send_json_success();
        }
        public function ajax_get_adjacent_tables() {
            $date = sanitize_text_field($_POST['date']); $current_reservation_id = intval($_POST['reservation_id']); $current_selected_ids = isset($_POST['current_tables']) ? array_map('intval', $_POST['current_tables']) : [];
            $sql = "SELECT tables_data FROM $this->table_name WHERE booking_date = %s AND id != %d AND status != 'cancelled'";
            $results = $this->wpdb->get_results($this->wpdb->prepare($sql, $date, $current_reservation_id), ARRAY_A);
            $occupied_ids = []; foreach ($results as $row) { $ids = json_decode($row['tables_data'], true) ?? []; $occupied_ids = array_merge($occupied_ids, $ids); }
            $available = []; foreach ($this->tables_config as $table) { if (in_array($table['id'], $occupied_ids)) continue; if (in_array($table['id'], $current_selected_ids)) continue; $available[] = $table; }
            wp_send_json(['success' => true, 'tables' => $available]);
        }
        public function ajax_send_reminder() {
            if (!current_user_can('manage_options')) wp_die();
            $to = sanitize_email($_POST['email']);
            $subject = "Recordatorio de Reserva - Cacho e Cabra";
            $message = "Hola, recordamos tu reserva: Código " . sanitize_text_field($_POST['code']) . " para el " . sanitize_text_field($_POST['date']);
            wp_mail($to, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
            wp_send_json_success();
        }
    }
}

new KaceMaster_Elite();