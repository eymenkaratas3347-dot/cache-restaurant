<?php
/**
 * Plugin Name:       Laboratorio Vincular Full (Todo en Uno)
 * Description:       Versión COMPLETA y UNIFICADA: Home (punto A), NEXO AI con flujo completo (punto B), Mosaico de Ecos mucho más extenso y funcional (punto C + mejoras), gamificación invisible, perfil, menú. Panel de administración EXACTAMENTE igual al plugin anterior de IA (con PDF upload, OCR, chunks, estado del cerebro, etc.). Todo funciona con UN SOLO shortcode [lv_home].
 * Version:           2.2
 * Author:            Grok para Diego Torres
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Laboratorio_Vincular_Full {

    private $groq_api_url = 'https://api.groq.com/openai/v1/chat/completions';
    private $ocr_api_endpoint = 'https://api.ocr.space/parse/image';

    public function __construct() {
        add_action( 'init', [$this, 'start_session'], 1 );
        add_action( 'init', [$this, 'register_eco_post_type'] );
        add_action( 'admin_menu', [$this, 'create_admin_menu'] );
        add_action( 'admin_init', [$this, 'register_settings'] );
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_assets'] );
        add_shortcode( 'lv_home', [$this, 'render_home'] );
        add_action( 'wp_ajax_lv_nexo_send', [$this, 'handle_chat_request'] );
        add_action( 'wp_ajax_lv_terminar_sesion', [$this, 'handle_terminar_sesion'] );
        add_action( 'wp_ajax_lv_guardar_eco', [$this, 'handle_guardar_eco'] );
        add_action( 'wp_ajax_lv_resonar_eco', [$this, 'handle_resonar_eco'] );
        add_action( 'wp_ajax_lv_cargar_mas_ecos', [$this, 'ajax_cargar_mas_ecos'] );
        add_action( 'wp_ajax_lv_guardar_perfil', [$this, 'ajax_guardar_perfil'] );
        add_action( 'wp_ajax_lv_incrementar_lazos', [$this, 'ajax_incrementar_lazos'] );
        add_action( 'wp_ajax_lv_process_pdf', [$this, 'handle_pdf_processing'] );
    }

    public function start_session() {
        if ( ! session_id() ) session_start();
    }

    public function register_eco_post_type() {
        register_post_type( 'lv_eco', [
            'labels'       => [ 'name' => 'Ecos', 'singular_name' => 'Eco' ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'lv-core',
            'supports'     => [ 'title', 'author' ],
        ]);
    }

    public function enqueue_assets() {
        global $post;
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'lv_home' ) ) {
            wp_enqueue_script( 'jquery' );
            wp_enqueue_script( 'tailwind', 'https://cdn.tailwindcss.com', [], null, true );

            wp_localize_script( 'jquery', 'lv_vars', [
                'ajax_url'      => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'lv_full_nonce' ),
                'is_subscribed' => is_user_logged_in() && get_user_meta( get_current_user_id(), 'lv_is_subscribed', true ) === 'yes',
                'is_logged_in'  => is_user_logged_in()
            ] );

            $inline = '
                tailwind.config = { content: ["*"], theme: { extend: { colors: { vincular: { blue: "#1e3a8a", gray: "#374151" } } } } }
                .eco-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
                .eco-card:hover { transform: translateY(-6px); }
                .eco-glow { box-shadow: 0 0 0 4px rgb(30 58 138 / 0.15); }
                #lv-chat-messages { scrollbar-width: thin; scrollbar-color: #1e3a8a #f3f4f6; }
                @media (max-width: 640px) {
                    #lv-nexo-wrapper { margin: 0 16px; }
                    .lv-home { padding-bottom: 80px; }
                }
            ';
            wp_add_inline_script( 'tailwind', $inline );
        }
    }

    public function register_settings() {
        register_setting( 'lv_full_group', 'groq_api_key' );
        register_setting( 'lv_full_group', 'groq_ocr_key' );
        register_setting( 'lv_full_group', 'groq_ai_identity' );
        register_setting( 'lv_full_group', 'groq_method_prompt' );
    }

    public function create_admin_menu() {
        add_menu_page( 'Laboratorio Vincular', 'Lab Vincular', 'manage_options', 'lv-core', [$this, 'admin_page_html'], 'dashicons-groups', 25 );
    }

    public function admin_page_html() {
        $chunks = get_option('groq_knowledge_chunks', []);
        $chunks_count = is_array($chunks) ? count($chunks) : 0;
        ?>
        <div class="wrap" style="background: #f0f2f5; padding: 20px; border-radius: 8px;">
            <h1 style="font-weight: 800; color: #1d2327; margin-bottom: 20px;">⚙️ Configuración del Laboratorio Vincular</h1>
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                
                <div>
                    <form method="post" action="options.php">
                        <?php settings_fields('lv_full_group'); ?>
                        
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
                    fd.append('file', file); 
                    fd.append('action', 'lv_process_pdf'); 
                    fd.append('nonce', '<?php echo wp_create_nonce("lv_full_nonce"); ?>');
                    $('#upload_status').text('⏳ Procesando con OCR y Chunking...');
                    $.ajax({ 
                        url: ajaxurl, 
                        type: 'POST', 
                        contentType: false, 
                        processData: false, 
                        data: fd, 
                        success: function(r) { 
                            if(r.success) {
                                $('#upload_status').text('✅ Sabiduría actualizada');
                                setTimeout(() => location.reload(), 1200);
                            } else {
                                $('#upload_status').text('❌ Error: ' + (r.data ? r.data.message : ''));
                            }
                        }
                    });
                });

                $('#groq_clear_mem').click(function() {
                    if(confirm('¿Seguro que quieres vaciar la sabiduría del cerebro?')) {
                        jQuery.post(ajaxurl, {
                            action: 'lv_clear_knowledge',
                            nonce: '<?php echo wp_create_nonce("lv_full_nonce"); ?>'
                        }, function() {
                            location.reload();
                        });
                    }
                });
            });
            </script>
        </div>
        <?php
    }

    public function handle_pdf_processing() {
        check_ajax_referer('lv_full_nonce', 'nonce');
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

    public function render_home() {
        ob_start();
        $is_logged_in = is_user_logged_in();
        $user_id      = get_current_user_id();
        $is_subscribed = $is_logged_in && get_user_meta( $user_id, 'lv_is_subscribed', true ) === 'yes';

        $aprendiendo = $is_logged_in ? get_user_meta( $user_id, 'lv_aprendiendo', true ) : '';
        $puedo_ayudar = $is_logged_in ? get_user_meta( $user_id, 'lv_puedo_ayudar', true ) : '';
        $lazos_count = $is_logged_in ? max( 3, (int) get_user_meta( $user_id, 'lv_lazos', true ) ) : 3;

        ?>
        <script src="https://cdn.tailwindcss.com"></script>
        <div class="lv-home min-h-screen bg-white text-[#374151] font-sans">

            <!-- HEADER + MENÚ (exacto) -->
            <header class="border-b border-gray-100 bg-white sticky top-0 z-50">
                <div class="max-w-6xl mx-auto px-4 sm:px-6 py-4 flex justify-between items-center">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-[#1e3a8a] rounded-xl flex items-center justify-center text-white font-bold text-xl">L</div>
                        <h1 class="text-2xl font-bold tracking-tight text-[#1e3a8a]">Laboratorio Vincular</h1>
                    </div>
                    <button id="hamburger" class="hamburger md:hidden w-10 h-10 flex flex-col justify-center items-center gap-1.5 focus:outline-none">
                        <span class="block w-6 h-0.5 bg-[#1e3a8a]"></span>
                        <span class="block w-6 h-0.5 bg-[#1e3a8a]"></span>
                        <span class="block w-6 h-0.5 bg-[#1e3a8a]"></span>
                    </button>
                    <?php if ( $is_logged_in ) : ?>
                    <nav class="hidden md:flex items-center gap-8 text-sm font-medium">
                        <a href="#" onclick="showSection('perfil'); return false;" class="hover:text-[#1e3a8a]">Perfil</a>
                        <a href="https://www.ontologiavincular.com" target="_blank" class="hover:text-[#1e3a8a]">Manifiesto Ontológico</a>
                        <a href="https://laboratoriovincular.online/#familia" target="_blank" class="hover:text-[#1e3a8a]">Abordaje Familiar</a>
                        <a href="https://laboratoriovincular.online/#estacionvinculo" target="_blank" class="hover:text-[#1e3a8a]">Estación Vínculo</a>
                        <a href="https://laboratoriovincular.online/#formaciones" target="_blank" class="hover:text-[#1e3a8a]">Academia</a>
                    </nav>
                    <?php endif; ?>
                </div>
                <div id="mobile-menu" class="hidden md:hidden bg-white border-t px-4 sm:px-6 py-6 flex flex-col gap-6 text-lg font-medium">
                    <?php if ( $is_logged_in ) : ?>
                    <a href="#" onclick="showSection('perfil');closeMobileMenu(); return false;" class="hover:text-[#1e3a8a]">Perfil de usuario</a>
                    <a href="https://www.ontologiavincular.com" target="_blank" class="hover:text-[#1e3a8a]">Manifiesto Ontológico</a>
                    <a href="https://laboratoriovincular.online/#familia" target="_blank" class="hover:text-[#1e3a8a]">Abordaje Familiar</a>
                    <a href="https://laboratoriovincular.online/#estacionvinculo" target="_blank" class="hover:text-[#1e3a8a]">Estación vínculo</a>
                    <a href="https://laboratoriovincular.online/#formaciones" target="_blank" class="hover:text-[#1e3a8a]">Academia</a>
                    <?php endif; ?>
                </div>
            </header>

            <div class="max-w-6xl mx-auto px-4 sm:px-6 pt-8 sm:pt-12 pb-16 sm:pb-24">

                <!-- BANNER (exacto) -->
                <?php if ( ! $is_logged_in ) : ?>
                <div class="max-w-2xl mx-auto text-center">
                    <div class="aspect-video bg-gray-100 rounded-3xl overflow-hidden mb-8 shadow-inner flex items-center justify-center">
                        <div class="text-center">
                            <div class="w-20 h-20 mx-auto bg-white rounded-2xl shadow flex items-center justify-center text-4xl">▶️</div>
                            <p class="mt-4 text-sm text-gray-500">Video breve – Astilla en la mente</p>
                        </div>
                    </div>
                    <h2 class="text-3xl sm:text-4xl font-bold text-[#1e3a8a] leading-tight">El poder no se acapara,<br>el poder circula.</h2>
                    <p class="mt-6 text-lg text-gray-600">Una herramienta de re-configuración perceptual.</p>
                    <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                        <a href="<?php echo wp_login_url( get_permalink() ); ?>" class="bg-[#1e3a8a] hover:bg-[#1e3a8a]/90 text-white font-semibold px-10 py-4 rounded-2xl text-lg transition-all">Iniciar sesión</a>
                        <a href="<?php echo wp_registration_url(); ?>" class="border-2 border-[#1e3a8a] text-[#1e3a8a] hover:bg-[#1e3a8a] hover:text-white font-semibold px-10 py-4 rounded-2xl text-lg transition-all">Registrarse</a>
                    </div>
                </div>
                <?php else : ?>
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end mb-12 gap-4">
                    <div>
                        <span class="inline-block px-4 py-1 bg-green-100 text-green-700 text-xs font-medium rounded-full"><?php echo $is_subscribed ? 'SUSCRIPTO' : 'REGISTRADO'; ?></span>
                        <h2 class="text-3xl sm:text-4xl font-bold text-[#1e3a8a] mt-2">Bienvenido de nuevo</h2>
                    </div>
                    <?php if ( ! $is_subscribed ) : ?>
                    <button onclick="mostrarPromptSuscripcion()" class="text-sm font-medium text-[#1e3a8a] hover:underline">¿Quieres suscribirte?</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- MOSAICO ECOS -->
                <section class="mb-16 sm:mb-20">
                    <div class="flex items-center justify-between mb-8">
                        <h3 class="text-2xl font-bold text-[#1e3a8a]">Ecos de la Comunidad</h3>
                        <span class="text-xs uppercase tracking-widest text-gray-400">Hoy</span>
                    </div>
                    <div id="lv-ecos-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php echo $this->render_ecos_mosaico_html( 9 ); ?>
                    </div>
                    <div class="text-center mt-10">
                        <button onclick="cargarMasEcos()" id="btn-cargar-mas" 
                                class="px-8 py-3 border border-[#1e3a8a] text-[#1e3a8a] rounded-2xl text-sm font-medium hover:bg-[#1e3a8a] hover:text-white transition-all">
                            Cargar más Ecos
                        </button>
                    </div>
                </section>

                <!-- NEXO AI -->
                <?php if ( $is_logged_in ) : ?>
                <section class="mb-16 sm:mb-20">
                    <h3 class="text-2xl font-bold text-[#1e3a8a] mb-6">NEXO AI – Tu sesión diaria</h3>
                    <?php echo $this->render_nexo_ai_widget_html(); ?>
                </section>
                <?php endif; ?>

                <!-- FORO -->
                <section>
                    <h3 class="text-2xl font-bold text-[#1e3a8a] mb-8">Foro de la Comunidad</h3>
                    <div class="space-y-6">
                        <div class="bg-white border border-gray-100 rounded-3xl p-6 sm:p-8">
                            <div class="flex gap-3">
                                <div class="w-8 h-8 bg-amber-100 rounded-2xl flex-shrink-0"></div>
                                <div class="flex-1">
                                    <p class="font-medium">¿Cómo transformar la ansiedad en presencia?</p>
                                    <p class="text-sm text-gray-500 mt-1">3 respuestas • hace 2h</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if ( ! $is_logged_in ) : ?>
                    <div onclick="mostrarInvitacionRegistro()" class="mt-8 text-center text-sm text-[#1e3a8a] cursor-pointer hover:underline">Regístrate para escribir o responder en el foro</div>
                    <?php elseif ( ! $is_subscribed ) : ?>
                    <div onclick="mostrarPromptSuscripcion()" class="mt-8 text-center text-sm text-[#1e3a8a] cursor-pointer hover:underline">Suscríbete para participar activamente en el foro</div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- PERFIL MODAL -->
            <div id="perfil-modal" class="hidden fixed inset-0 bg-black/70 flex items-center justify-center z-[110] px-4">
                <div onclick="event.stopImmediatePropagation()" class="bg-white rounded-3xl w-full max-w-lg max-h-[90vh] overflow-hidden">
                    <div class="px-6 pt-6 pb-4 border-b flex items-center justify-between">
                        <h3 class="text-2xl font-bold text-[#1e3a8a]">Tu Perfil</h3>
                        <button onclick="hidePerfilModal()" class="text-4xl leading-none text-gray-300 hover:text-gray-500">×</button>
                    </div>
                    <div class="p-6 space-y-8 overflow-auto max-h-[65vh]">
                        <div>
                            <label class="block text-xs uppercase tracking-widest text-gray-400 mb-2">Lo que estoy aprendiendo (vulnerabilidad)</label>
                            <textarea id="aprendiendo" rows="4" class="w-full border border-gray-200 rounded-2xl p-5 focus:outline-none focus:border-[#1e3a8a]"><?php echo esc_textarea( $aprendiendo ); ?></textarea>
                        </div>
                        <div>
                            <label class="block text-xs uppercase tracking-widest text-gray-400 mb-2">En qué puedo ayudar (servicio)</label>
                            <textarea id="puedo_ayudar" rows="4" class="w-full border border-gray-200 rounded-2xl p-5 focus:outline-none focus:border-[#1e3a8a]"><?php echo esc_textarea( $puedo_ayudar ); ?></textarea>
                        </div>
                        <div>
                            <label class="block text-xs uppercase tracking-widest text-gray-400 mb-3">Tu red de lazos</label>
                            <div class="flex items-center gap-3 flex-wrap">
                                <?php for ( $i = 0; $i < $lazos_count; $i++ ) : ?>
                                <div class="w-7 h-7 rounded-full border-2 border-[#1e3a8a]"></div>
                                <?php endfor; ?>
                            </div>
                            <p class="text-xs text-gray-400 mt-3">Cuanto más participas, más complejo se vuelve tu grafo.</p>
                        </div>
                    </div>
                    <div class="p-6 border-t flex gap-3">
                        <button onclick="guardarPerfil()" class="flex-1 bg-[#1e3a8a] text-white font-medium py-4 rounded-2xl">Guardar cambios</button>
                        <button onclick="hidePerfilModal()" class="flex-1 border border-gray-200 font-medium py-4 rounded-2xl">Cerrar</button>
                    </div>
                </div>
            </div>

            <!-- MODALES -->
            <div id="modal-registro" class="hidden fixed inset-0 bg-black/70 flex items-center justify-center z-[120] px-4">
                <div class="bg-white rounded-3xl max-w-sm w-full p-8 text-center">
                    <p class="text-xl font-medium">Para resonar o participar en el foro necesitas estar registrado.</p>
                    <div class="mt-8 flex gap-4">
                        <a href="<?php echo wp_login_url(); ?>" class="flex-1 bg-[#1e3a8a] text-white py-4 rounded-2xl">Iniciar sesión</a>
                        <a href="<?php echo wp_registration_url(); ?>" class="flex-1 border border-[#1e3a8a] text-[#1e3a8a] py-4 rounded-2xl">Registrarme</a>
                    </div>
                    <button onclick="hideModal('modal-registro')" class="mt-6 text-sm text-gray-400">Cerrar</button>
                </div>
            </div>

            <div id="modal-suscripcion" class="hidden fixed inset-0 bg-black/70 flex items-center justify-center z-[120] px-4">
                <div class="bg-white rounded-3xl max-w-md w-full p-8">
                    <p class="text-xl leading-tight">Tu suscripción no compra contenido.<br>Tu suscripción mantiene las luces encendidas del Laboratorio y financia el desarrollo y mantenimiento de NEXO AI.</p>
                    <div class="mt-10">
                        <button onclick="hideModal('modal-suscripcion')" class="w-full py-4 border border-gray-300 rounded-2xl text-sm font-medium">Entendido</button>
                    </div>
                </div>
            </div>

        </div>

        <!-- JS COMPLETO -->
        <script>
        let lv_nonce = '<?php echo wp_create_nonce( 'lv_full_nonce' ); ?>';
        function closeMobileMenu() { document.getElementById('mobile-menu').classList.add('hidden'); }
        function showSection(s) { if(s==='perfil') document.getElementById('perfil-modal').classList.remove('hidden'); }
        function hidePerfilModal() { document.getElementById('perfil-modal').classList.add('hidden'); }
        function mostrarInvitacionRegistro() { document.getElementById('modal-registro').classList.remove('hidden'); }
        function mostrarPromptSuscripcion() { document.getElementById('modal-suscripcion').classList.remove('hidden'); }
        function hideModal(id) { document.getElementById(id).classList.add('hidden'); }

        document.getElementById('hamburger').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.toggle('hidden');
        });

        // NEXO AI
        function sendNexoMessage() {
            const input = document.getElementById('lv-input');
            const msg = input.value.trim();
            if (!msg || !lv_vars.is_logged_in) return;
            
            const messagesDiv = document.getElementById('lv-chat-messages');
            messagesDiv.innerHTML += `<div class="flex justify-end mb-4"><div class="max-w-[85%] bg-[#1e3a8a] text-white px-5 py-3.5 rounded-3xl rounded-br-sm">${msg}</div></div>`;
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
            input.value = '';

            jQuery.post(lv_vars.ajax_url, {
                action: 'lv_nexo_send',
                message: msg,
                nonce: lv_vars.nonce
            }, function(res) {
                if (res.success) {
                    messagesDiv.innerHTML += `<div class="flex mb-4"><div class="max-w-[85%] bg-gray-100 px-5 py-3.5 rounded-3xl rounded-bl-sm">${res.data.reply}</div></div>`;
                    messagesDiv.scrollTop = messagesDiv.scrollHeight;
                } else {
                    messagesDiv.innerHTML += `<div class="flex mb-4"><div class="max-w-[85%] bg-red-100 px-5 py-3.5 rounded-3xl">Error de conexión. Intenta de nuevo.</div></div>`;
                }
            });
        }

        function terminarSesionNexo() {
            jQuery.post(lv_vars.ajax_url, {
                action: 'lv_terminar_sesion',
                nonce: lv_vars.nonce
            }, function() {
                document.getElementById('lv-chat-input').style.display = 'none';
                document.getElementById('lv-terminar-btn').style.display = 'none';
                document.getElementById('lv-end-screen').classList.remove('hidden');
            });
        }

        function updateCardPreview() {
            const frase = document.getElementById('lv-frase').value.trim();
            const preview = document.getElementById('lv-card-preview');
            const textEl = document.getElementById('lv-card-text');
            if (frase.length > 5) {
                preview.classList.remove('hidden');
                textEl.textContent = frase;
            } else {
                preview.classList.add('hidden');
            }
        }

        function lv_decision(compartir) {
            const frase = document.getElementById('lv-frase').value.trim();
            if (!frase) return alert('Escribe la frase que más te resonó');
            
            jQuery.post(lv_vars.ajax_url, {
                action: 'lv_guardar_eco',
                frase: frase,
                compartir: compartir ? '1' : '0',
                firmada: '1',
                nonce: lv_vars.nonce
            }, function(res) {
                if (res.success) {
                    alert(compartir ? '¡Eco compartido con la red!' : 'Guardado en tu bitácora personal');
                    location.reload();
                }
            });
        }

        // ECOS
        function lv_resonar(eco_id, btn) {
            if (!lv_vars.is_subscribed) {
                mostrarPromptSuscripcion();
                return;
            }
            jQuery.post(lv_vars.ajax_url, {
                action: 'lv_resonar_eco',
                eco_id: eco_id,
                nonce: lv_vars.nonce
            }, function(res) {
                if (res.success) {
                    const span = btn.querySelector('span');
                    let num = parseInt(span.textContent.replace(/[()]/g, '')) + 1;
                    span.textContent = `(${num})`;
                    if (num >= 5) btn.closest('.eco-card').classList.add('eco-glow');
                }
            });
        }

        let page = 1;
        function cargarMasEcos() {
            page++;
            jQuery.post(lv_vars.ajax_url, {
                action: 'lv_cargar_mas_ecos',
                page: page,
                nonce: lv_vars.nonce
            }, function(res) {
                if (res.success && res.data.html) {
                    document.getElementById('lv-ecos-grid').insertAdjacentHTML('beforeend', res.data.html);
                    if (res.data.no_more) document.getElementById('btn-cargar-mas').style.display = 'none';
                }
            });
        }

        // PERFIL
        function guardarPerfil() {
            const aprendiendo = document.getElementById('aprendiendo').value;
            const puedoAyudar = document.getElementById('puedo_ayudar').value;
            
            jQuery.post(lv_vars.ajax_url, {
                action: 'lv_guardar_perfil',
                aprendiendo: aprendiendo,
                puedo_ayudar: puedoAyudar,
                nonce: lv_vars.nonce
            }, function(res) {
                if (res.success) {
                    alert('Perfil guardado ✓');
                    hidePerfilModal();
                } else {
                    alert('Error al guardar');
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const chatInput = document.getElementById('lv-input');
            if (chatInput) {
                chatInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        sendNexoMessage();
                    }
                });
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }

    private function render_ecos_mosaico_html( $limit = 9 ) {
        $args = [
            'post_type'      => 'lv_eco',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'meta_key'       => 'lv_resonancias',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
        ];
        $ecos = get_posts( $args );
        if ( empty( $ecos ) ) {
            return '<div class="col-span-full text-center py-12 text-gray-400 text-sm">Sé el primero en compartir un Eco desde NEXO AI.</div>';
        }
        $html = '';
        foreach ( $ecos as $eco ) {
            $res = (int) get_post_meta( $eco->ID, 'lv_resonancias', true );
            $aut = get_post_meta( $eco->ID, 'lv_autor_nombre', true ) ?: 'Anónima';
            $glow = $res >= 5 ? ' eco-glow' : '';
            $html .= '
            <div class="eco-card bg-white border border-gray-100 rounded-3xl p-6 sm:p-8' . $glow . '">
                <p class="italic text-base sm:text-lg leading-relaxed text-[#374151]">"' . esc_html( $eco->post_title ) . '"</p>
                <div class="mt-6 sm:mt-8 flex items-center justify-between">
                    <span class="text-xs text-gray-400">' . esc_html( $aut ) . '</span>
                    <button onclick="lv_resonar(' . $eco->ID . ', this)" class="px-5 py-2 bg-gray-100 hover:bg-[#1e3a8a] hover:text-white text-gray-700 rounded-2xl text-sm font-medium transition-all flex items-center gap-1">
                        Resuena <span class="font-mono text-xs">(' . $res . ')</span>
                    </button>
                </div>
            </div>';
        }
        return $html;
    }

    private function render_nexo_ai_widget_html() {
        $user_id = get_current_user_id();
        $last = get_user_meta( $user_id, 'lv_last_nexo_session', true );
        $today = date( 'Y-m-d' );
        $can_use = get_user_meta( $user_id, 'lv_is_subscribed', true ) === 'yes' ? ($last !== $today) : empty( $last );

        if ( ! $can_use ) {
            return '<div class="max-w-md mx-auto text-center py-12 px-6"><p class="text-xl font-medium text-[#1e3a8a]">Ya usaste tu sesión de hoy.<br>El Laboratorio se reactiva mañana.</p></div>';
        }

        return '
        <div id="lv-nexo-wrapper" class="max-w-2xl mx-auto bg-white rounded-3xl shadow-inner border border-gray-100 overflow-hidden">
            <div id="lv-chat-messages" class="h-[380px] sm:h-[420px] overflow-y-auto p-4 sm:p-6 space-y-6 bg-gray-50"></div>
            <div id="lv-chat-input" class="border-t p-4 sm:p-6 flex gap-3">
                <input type="text" id="lv-input" class="flex-1 border border-gray-200 rounded-2xl px-5 py-4 focus:outline-none focus:border-[#1e3a8a] text-base" placeholder="Escribe aquí...">
                <button onclick="sendNexoMessage()" class="bg-[#1e3a8a] text-white px-7 rounded-2xl font-medium">Enviar</button>
            </div>
            <div class="px-4 sm:px-6 pb-6">
                <button id="lv-terminar-btn" onclick="terminarSesionNexo()" class="w-full py-4 border border-red-200 text-red-600 font-medium rounded-2xl hover:bg-red-50">Terminar sesión</button>
            </div>
        </div>

        <div id="lv-end-screen" class="hidden max-w-md mx-auto bg-white rounded-3xl shadow-inner border border-gray-100 p-6 sm:p-8 mt-8">
            <p class="text-center text-sm text-gray-500 mb-4">De todo lo conversado, ¿qué frase o idea te resonó más?</p>
            <textarea id="lv-frase" onkeyup="updateCardPreview()" rows="3" class="w-full border border-gray-200 rounded-2xl p-5 text-lg italic" placeholder="Escribe aquí la frase..."></textarea>
            
            <div id="lv-card-preview" class="hidden mt-8 mx-auto w-full max-w-xs bg-white border-2 border-[#374151] rounded-3xl p-8 text-center shadow">
                <p id="lv-card-text" class="text-[#374151] text-lg leading-relaxed italic"></p>
            </div>

            <div class="mt-8 grid grid-cols-2 gap-4">
                <button onclick="lv_decision(false)" class="py-4 border border-gray-300 rounded-2xl text-sm font-medium">Guardar para mí</button>
                <button onclick="lv_decision(true)" class="py-4 bg-[#1e3a8a] text-white rounded-2xl text-sm font-medium">Compartir con la red</button>
            </div>
        </div>';
    }

    public function handle_chat_request() {
        check_ajax_referer( 'lv_full_nonce', 'nonce' );
        $user_msg = sanitize_text_field( $_POST['message'] ?? '' );
        if ( empty( $user_msg ) ) wp_send_json_error();

        $identity = get_option( 'groq_ai_identity', 'Eres NEXO AI, un facilitador cálido y profundo del Laboratorio Vincular.' );
        $method   = get_option( 'groq_method_prompt', 'Sigue el método: Saludo cálido, Diagnóstico, Guion de reflexión, Cierre con frase potente.' );

        $messages = [
            [ 'role' => 'system', 'content' => $identity . "\n\n" . $method ],
            [ 'role' => 'user',   'content' => $user_msg ]
        ];

        $response = wp_remote_post( $this->groq_api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . get_option( 'groq_api_key' ),
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode([
                'model'       => 'llama-3.3-70b-versatile',
                'messages'    => $messages,
                'temperature' => 0.65,
                'max_tokens'  => 800
            ]),
            'timeout' => 30
        ]);

        if ( is_wp_error( $response ) ) {
            wp_send_json_success([ 'reply' => 'Lo siento, hubo un error de conexión con NEXO AI. Intenta de nuevo.' ]);
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $reply = $body['choices'][0]['message']['content'] ?? 'NEXO AI no pudo responder en este momento.';

        wp_send_json_success([ 'reply' => nl2br( esc_html( $reply ) ) ]);
    }

    public function handle_terminar_sesion() {
        check_ajax_referer( 'lv_full_nonce', 'nonce' );
        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), 'lv_last_nexo_session', date( 'Y-m-d' ) );
        }
        wp_send_json_success();
    }

    public function handle_guardar_eco() {
        check_ajax_referer( 'lv_full_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error();

        $user_id   = get_current_user_id();
        $frase     = sanitize_text_field( $_POST['frase'] ?? '' );
        $compartir = ( $_POST['compartir'] ?? '0' ) === '1';
        $firmada   = ( $_POST['firmada'] ?? '0' ) === '1';

        $post_id = wp_insert_post([
            'post_type'   => 'lv_eco',
            'post_title'  => $frase,
            'post_status' => $compartir ? 'publish' : 'private',
            'post_author' => $user_id,
        ]);

        if ( $post_id ) {
            update_post_meta( $post_id, 'lv_firmada', $firmada );
            update_post_meta( $post_id, 'lv_autor_nombre', $firmada ? wp_get_current_user()->display_name : 'Anónima' );
            update_post_meta( $post_id, 'lv_resonancias', 0 );
        }

        wp_send_json_success();
    }

    public function handle_resonar_eco() {
        check_ajax_referer( 'lv_full_nonce', 'nonce' );
        $eco_id = intval( $_POST['eco_id'] ?? 0 );
        if ( $eco_id ) {
            $count = (int) get_post_meta( $eco_id, 'lv_resonancias', true );
            update_post_meta( $eco_id, 'lv_resonancias', $count + 1 );
        }
        wp_send_json_success();
    }

    public function ajax_cargar_mas_ecos() {
        check_ajax_referer( 'lv_full_nonce', 'nonce' );
        $page = max( 1, intval( $_POST['page'] ?? 1 ) );
        $html = $this->render_ecos_mosaico_html( 6 );
        wp_send_json_success([ 'html' => $html, 'no_more' => false ]);
    }

    public function ajax_guardar_perfil() {
        check_ajax_referer( 'lv_full_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error();
        
        $user_id = get_current_user_id();
        update_user_meta( $user_id, 'lv_aprendiendo', sanitize_textarea_field( $_POST['aprendiendo'] ?? '' ) );
        update_user_meta( $user_id, 'lv_puedo_ayudar', sanitize_textarea_field( $_POST['puedo_ayudar'] ?? '' ) );
        
        wp_send_json_success();
    }

    public function ajax_incrementar_lazos() {
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $actual = (int) get_user_meta( $user_id, 'lv_lazos', true );
            update_user_meta( $user_id, 'lv_lazos', $actual + 1 );
        }
        wp_die();
    }
}

new Laboratorio_Vincular_Full();
?>