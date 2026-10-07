<?php
/**
 * Plugin Name: WP Groq Chat Pro - Laboratorio Vincular v5
 * Description: Panel administrativo avanzado para control de identidad, método y conocimiento PDF.
 * Version: 5.0
 * Author: Gemini AI
 */

if (!defined('ABSPATH')) exit;

class WP_Groq_Chat_Pro {

    private $groq_api_url = 'https://api.groq.com/openai/v1/chat/completions';
    private $ocr_api_endpoint = 'https://api.ocr.space/parse/image';

    public function __construct() {
        add_action('init', [$this, 'start_session'], 1);
        add_action('admin_menu', [$this, 'create_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_shortcode('groq_chat', [$this, 'render_chat_widget']);
        add_action('wp_ajax_groq_chat_send', [$this, 'handle_chat_request']);
        add_action('wp_ajax_groq_process_pdf', [$this, 'handle_pdf_processing']);
    }

    public function start_session() {
        if (!session_id()) session_start();
    }

    public function admin_assets($hook) {
        if ('toplevel_page_groq-chat-pro' !== $hook) return;
        wp_enqueue_style('groq-admin-css', 'https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css');
    }

    public function enqueue_assets() {
        wp_enqueue_style('groq-chat-css', plugin_dir_url(__FILE__) . 'chat-style.css');
        wp_enqueue_script('groq-chat-js', plugin_dir_url(__FILE__) . 'chat-script.js', ['jquery'], '5.0', true);
        wp_localize_script('groq-chat-js', 'groq_vars', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('groq_chat_nonce')]);
    }

    // --- REGISTRO DE CONFIGURACIONES ---
    public function register_settings() {
        register_setting('groq_vincular_group', 'groq_api_key');
        register_setting('groq_vincular_group', 'groq_ocr_key');
        register_setting('groq_vincular_group', 'groq_ai_identity'); // Personalidad
        register_setting('groq_vincular_group', 'groq_method_prompt'); // El "Cómo" actuar
    }

    public function create_admin_menu() {
        add_menu_page('Laboratorio Vincular', 'Lab Vincular', 'manage_options', 'groq-chat-pro', [$this, 'admin_page_html'], 'dashicons-forms', 30);
    }

    // --- PANEL ADMINISTRATIVO ---
    public function admin_page_html() {
        $chunks = get_option('groq_knowledge_chunks', []);
        $chunks_count = is_array($chunks) ? count($chunks) : 0;
        ?>
        <div class="wrap" style="background: #f0f2f5; padding: 20px; border-radius: 8px;">
            <h1 style="font-weight: 800; color: #1d2327; margin-bottom: 20px;">⚙️ Configuración del Laboratorio Vincular</h1>
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                
                <div>
                    <form method="post" action="options.php">
                        <?php settings_fields('groq_vincular_group'); ?>
                        
                        <div style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 20px;">
                            <h3 style="margin-top:0;">🎭 Identidad y Personalidad</h3>
                            <p class="description">Define quién es la IA, su nombre y cómo debe dirigirse al usuario.</p>
                            <textarea name="groq_ai_identity" style="width:100%; height:120px; font-family: monospace;" placeholder="Ej: Eres un facilitador cálido, experto en psicología social, usas un lenguaje poético pero clínico..."><?php echo esc_textarea(get_option('groq_ai_identity')); ?></textarea>
                        </div>

                        <div style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 20px;">
                            <h3>🧠 Prompt Maestro del Método</h3>
                            <p class="description">Describe las fases (Saludo, Diagnóstico, Guion y Cierre). Este es el motor del comportamiento.</p>
                            <textarea name="groq_method_prompt" style="width:100%; height:200px; font-family: monospace;"><?php echo esc_textarea(get_option('groq_method_prompt')); ?></textarea>
                        </div>

                        <div style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                            <h3>🔑 API Keys</h3>
                            <label>Groq API Key</label>
                            <input type="password" name="groq_api_key" value="<?php echo esc_attr(get_option('groq_api_key')); ?>" style="width:100%;">
                            <label style="margin-top:10px; display:block;">OCR.space API Key</label>
                            <input type="text" name="groq_ocr_key" value="<?php echo esc_attr(get_option('groq_ocr_key')); ?>" style="width:100%;">
                        </div>

                        <?php submit_button('Guardar Configuración Maestra'); ?>
                    </form>
                </div>

                <div>
                    <div style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 20px;">
                        <h3 style="margin-top:0;">📚 Biblioteca (PDF)</h3>
                        <p>Carga el libro base para que la IA extraiga conceptos.</p>
                        <input type="file" id="groq_pdf_input" style="width:100%; margin-bottom:15px;">
                        <button type="button" id="groq_btn_upload" class="button button-primary" style="width:100%;">Actualizar Sabiduría</button>
                        <div id="upload_status" style="margin-top:10px; font-weight:bold; color:#2271b1;"></div>
                    </div>

                    <div style="background: #2c3338; color: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                        <h4 style="color:#fff; margin-top:0;">📊 Estado del Cerebro</h4>
                        <ul style="list-style:none; padding:0;">
                            <li>✅ Fragmentos: <strong><?php echo $chunks_count; ?></strong></li>
                            <li>📖 Documento: <strong><?php echo $chunks_count > 0 ? 'Cargado' : 'Vacío'; ?></strong></li>
                            <li>🔄 Memoria: <strong>RAG Activo</strong></li>
                        </ul>
                        <?php if($chunks_count > 0): ?>
                            <button type="button" id="groq_clear_mem" class="button" style="background:#d63638; border-color:#d63638; color:#fff;">Borrar Conocimiento</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                $('#groq_btn_upload').click(function() {
                    var file = $('#groq_pdf_input').prop('files')[0];
                    if(!file) return alert('Sube un archivo primero.');
                    var fd = new FormData();
                    fd.append('file', file); fd.append('action', 'groq_process_pdf'); fd.append('nonce', '<?php echo wp_create_nonce("groq_chat_nonce"); ?>');
                    $('#upload_status').text('⏳ Procesando con OCR y Chunking...');
                    $.ajax({ url: ajaxurl, type: 'POST', contentType: false, processData: false, data: fd, success: function(r) { location.reload(); } });
                });

                $('#groq_clear_mem').click(function() {
                    if(confirm('¿Seguro que quieres vaciar la sabiduría del cerebro?')) {
                        // Lógica de limpieza rápida vía AJAX o reload con flag
                        window.location.href += '&clear_all=1';
                    }
                });
            });
            </script>
        </div>
        <?php
        if(isset($_GET['clear_all'])) {
            update_option('groq_knowledge_chunks', []);
            update_option('groq_doc_header', '');
        }
    }

    // --- PROCESAMIENTO PDF OCR ---
    public function handle_pdf_processing() {
        check_ajax_referer('groq_chat_nonce', 'nonce');
        $ocr_key = get_option('groq_ocr_key') ?: 'helloworld';
        $upload = wp_handle_upload($_FILES['file'], ['test_form' => false]);
        
        $response = wp_remote_post($this->ocr_api_endpoint, [
            'body' => ['apikey' => $ocr_key, 'url' => $upload['url'], 'language' => 'spa', 'OCREngine' => '2'],
            'timeout' => 150
        ]);
        
        wp_delete_file($upload['file']);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $full_text = "";
        if(isset($body['ParsedResults'])) {
            foreach ($body['ParsedResults'] as $page) $full_text .= $page['ParsedText'] . "\n";
            update_option('groq_doc_header', substr($full_text, 0, 2000));
            update_option('groq_knowledge_chunks', $this->create_chunks($full_text, 2500, 300));
            wp_send_json_success();
        }
        wp_send_json_error(['message' => 'Error en el OCR']);
    }

    private function create_chunks($text, $size, $overlap) {
        $text = preg_replace('/\s+/', ' ', $text);
        $chunks = [];
        for ($i=0; $i<strlen($text); $i+=($size-$overlap)) $chunks[] = substr($text, $i, $size);
        return $chunks;
    }

    // --- CHAT WIDGET ---
    public function render_chat_widget() {
        $_SESSION['groq_chat_history'] = [];
        return '<div id="groq-chat-wrapper"><div id="groq-chat-header">Laboratorio Vincular</div><div id="groq-chat-messages"><div class="bot-msg">Bienvenido al Laboratorio Vincular. Estoy aquí para ayudarte a sintonizar una tensión. Para empezar, cuéntame en pocas palabras: ¿qué nudo relacional sientes hoy?</div></div><div id="groq-chat-input-area"><input type="text" id="groq-input" placeholder="Tu respuesta..."><button id="groq-send-btn">➤</button></div></div>';
    }

    // --- LÓGICA DE RESPUESTA ---
    public function handle_chat_request() {
        check_ajax_referer('groq_chat_nonce', 'nonce');
        $user_msg = sanitize_text_field($_POST['message']);
        
        if (!isset($_SESSION['groq_chat_history'])) $_SESSION['groq_chat_history'] = [];
        
        // Verificación de bloqueo post-cierre
        foreach($_SESSION['groq_chat_history'] as $msg) {
            if(strpos($msg['content'], 'Que la red te sostenga') !== false) {
                wp_send_json_success(['reply' => 'El Laboratorio se volverá a activar para ti mañana. Que la red te sostenga.']);
                return;
            }
        }

        // Búsqueda de Contexto (RAG)
        $chunks = get_option('groq_knowledge_chunks', []);
        $header = get_option('groq_doc_header', '');
        $context = "INFO LIBRO:\n" . $header . "\n";
        $keywords = array_filter(explode(' ', strtolower($user_msg)), fn($w) => strlen($w) > 4);
        foreach(array_slice($chunks, 0, 5) as $c) { // Búsqueda simple para velocidad
            foreach($keywords as $k) { if(stripos($c, $k) !== false) { $context .= $c . "\n"; break; } }
        }

        // Construcción del Prompt
        $identity = get_option('groq_ai_identity');
        $method = get_option('groq_method_prompt');
        $history_count = count($_SESSION['groq_chat_history']) / 2;

        $system_prompt = "IDENTIDAD:\n$identity\n\nMÉTODO Y FASES:\n$method\n\nCONOCIMIENTO DEL LIBRO:\n$context\n\nESTADO ACTUAL: Llevas $history_count interacciones.";

        $messages = [['role' => 'system', 'content' => $system_prompt]];
        $messages = array_merge($messages, array_slice($_SESSION['groq_chat_history'], -6));
        $messages[] = ['role' => 'user', 'content' => $user_msg];

        $response = wp_remote_post($this->groq_api_url, [
            'headers' => ['Authorization' => 'Bearer ' . get_option('groq_api_key'), 'Content-Type' => 'application/json'],
            'body' => json_encode(['model' => 'llama-3.3-70b-versatile', 'messages' => $messages, 'temperature' => 0.5]),
            'timeout' => 25
        ]);

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $reply = $body['choices'][0]['message']['content'] ?? 'Error de conexión.';

        $_SESSION['groq_chat_history'][] = ['role' => 'user', 'content' => $user_msg];
        $_SESSION['groq_chat_history'][] = ['role' => 'assistant', 'content' => $reply];

        wp_send_json_success(['reply' => nl2br(esc_html($reply))]);
    }
}
new WP_Groq_Chat_Pro();
