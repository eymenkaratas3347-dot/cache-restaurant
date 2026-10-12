<?php
if (!defined('ABSPATH')) exit;
/** @var object|null $ev @var bool $embedded @var array $cfg */
$is_event = (bool) $ev;
$open = $cfg['open'];
$seat = $cfg['unit'] === 'seat';
$slots = $is_event ? KM_Events::arrival_slots($ev) : [];
$price = $is_event ? (float) $ev->price_per_person : (float) $cfg['convDeposit'];
?>
<div id="km-booking" class="km-booking<?php echo $embedded ? ' is-embedded' : ' km-wrap'; ?>" data-mode="<?php echo $is_event ? 'event' : 'conventional'; ?>" data-unit="<?php echo esc_attr($cfg['unit']); ?>">

    <?php if (!$embedded): ?>
    <a class="km-back" href="<?php echo esc_url(KM_Front::home_url()); ?>#cartelera"><?php echo KM_Front::icon('back'); ?> Volver</a>
    <div class="km-book-head">
        <?php if ($is_event): ?>
            <?php echo KM_Front::img($ev->image_url, 'Afiche de ' . $ev->event_name, 'km-book-thumb', '96px'); ?>
            <div>
                <p class="km-eyebrow">Reserva para</p>
                <h1><?php echo esc_html($ev->event_name); ?></h1>
                <p><?php echo esc_html(KM_Util::date_human($ev->event_date)); ?> · Show <?php echo esc_html(KM_Events::show_time($ev)); ?> h</p>
            </div>
        <?php else: ?>
            <div>
                <p class="km-eyebrow">Reserva de mesa</p>
                <h1>Visita el restaurante</h1>
                <p><?php echo $cfg['convDeposit'] > 0 ? 'Abono de ' . esc_html(KM_Util::money($cfg['convDeposit'])) . ' por persona, descontable de tu consumo.' : 'Reserva sin costo.'; ?></p>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$open): ?>
        <div class="km-alert">Las reservas en línea para este evento están cerradas o agotadas.
            <a href="<?php echo esc_url(KM_Front::home_url()); ?>#cartelera">Ver otros eventos</a></div>
    <?php else: ?>

    <ol class="km-steps" aria-label="Pasos de la reserva">
        <li class="is-active" data-step-ind="1"><?php echo $is_event ? ($seat ? 'Puestos' : 'Mesa') : 'Fecha'; ?></li>
        <li data-step-ind="2">Datos y pago</li>
    </ol>

    <?php if ($is_event): ?>
    <section class="km-step" data-step="1" aria-labelledby="km-map-title">
        <div class="km-box km-box-row">
            <span id="km-guests-label">¿Cuántas personas vienen?</span>
            <div class="km-stepper" role="group" aria-labelledby="km-guests-label">
                <button type="button" data-guests="-1" aria-label="Una persona menos">−</button>
                <output id="km-guests-out" aria-live="polite">2</output>
                <button type="button" data-guests="1" aria-label="Una persona más">+</button>
            </div>
            <input type="hidden" id="km-guests" value="2" data-min="<?php echo (int) $cfg['minGuests']; ?>" data-max="<?php echo (int) $cfg['maxGuests']; ?>">
        </div>
        <?php if ($cfg['whatsapp']): ?><p class="km-small km-max-note" hidden>¿Son más de <?php echo (int) $cfg['maxGuests']; ?>? <a href="<?php echo esc_url($cfg['whatsapp']); ?>" target="_blank" rel="noopener">Escríbenos por WhatsApp</a>.</p><?php endif; ?>

        <?php if (count($slots) > 1): ?>
        <div class="km-box km-box-row km-arrival">
            <span id="km-time-label">Hora de llegada</span>
            <div class="km-chips km-chips-sm" role="radiogroup" aria-labelledby="km-time-label">
                <?php foreach ($slots as $i => $s): ?><label class="km-chip"><input type="radio" name="km-time" value="<?php echo esc_attr($s); ?>"<?php checked($i, 0); ?>><span><?php echo esc_html($s); ?></span></label><?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="km-time" value="<?php echo esc_attr($slots[0] ?? KM_Events::show_time($ev)); ?>">
        <?php endif; ?>

        <div class="km-box km-map-box">
            <div class="km-map-top">
                <h2 id="km-map-title"><?php echo $seat ? 'Elige tus puestos' : 'Selecciona tu mesa'; ?></h2>
                <div class="km-map-tools">
                    <button type="button" class="km-tool" data-zoom="-1" aria-label="Alejar plano">−</button>
                    <button type="button" class="km-tool" data-zoom="1" aria-label="Acercar plano">+</button>
                    <button type="button" class="km-tool km-tool-txt" data-act="suggest">Elegir por mí</button>
                </div>
            </div>
            <p class="km-hint" id="km-map-hint"><?php echo $seat ? 'Toca una mesa para tomar sus puestos libres, o toca cada silla.' : 'Toca una mesa para elegirla.'; ?></p>
            <div class="km-map-scroll" tabindex="-1"><?php echo KM_Front::floor_svg('km-floor'); ?></div>
            <ul class="km-legend">
                <li><i class="lg-free"></i> Disponible</li>
                <li><i class="lg-sel"></i> <?php echo $seat ? 'Seleccionado' : 'Seleccionada'; ?></li>
                <li><i class="lg-occ"></i> <?php echo $seat ? 'Ocupado' : 'Ocupada'; ?></li>
                <li><i class="lg-rec"></i> Recomendada para tu grupo</li>
            </ul>
            <div class="km-sel" aria-live="polite">
                <div class="km-sel-place"><strong id="km-place">Elige tus puestos</strong><span id="km-seat-count" class="km-seat-count">0 / 2</span></div>
                <div class="km-row"><span id="km-line-label"><?php echo $is_event && $ev->payment_mode === 'abono' ? 'Abono' : 'Entradas'; ?>: 2 × <?php echo esc_html(KM_Util::money($price)); ?></span><span id="km-line-amount"><?php echo esc_html(KM_Util::money($price * 2)); ?></span></div>
                <div class="km-row km-row-total"><span>Total</span><span id="km-line-total"><?php echo esc_html(KM_Util::money($price * 2)); ?></span></div>
            </div>
        </div>
        <p class="km-msg" role="alert" aria-live="assertive"></p>
        <div class="km-continue">
            <button type="button" class="km-btn km-btn-lg km-btn-block" data-act="to-details" disabled>Continuar con mi reserva <?php echo KM_Front::icon('arrow'); ?></button>
            <p class="km-small">Promociones opcionales en el siguiente paso.</p>
        </div>
    </section>
    <?php else: ?>
    <form class="km-step" data-step="1" novalidate>
        <div class="km-box">
            <div class="km-fields">
                <div class="km-field"><label for="km-date">Fecha</label><input id="km-date" type="date" min="<?php echo esc_attr($cfg['today']); ?>" max="<?php echo esc_attr($cfg['maxDate']); ?>" required></div>
                <div class="km-field"><label for="km-time">Hora</label><select id="km-time" required disabled><option value="">Elige una fecha primero</option></select></div>
                <div class="km-field"><label for="km-sector">Sector</label><select id="km-sector" required><option value="">Selecciona…</option></select></div>
                <div class="km-field"><label for="km-guests">Personas</label>
                    <select id="km-guests" required><?php for ($i = $cfg['minGuests']; $i <= $cfg['maxGuests']; $i++): ?><option value="<?php echo $i; ?>"<?php selected($i, 2); ?>><?php echo $i; ?> persona<?php echo $i > 1 ? 's' : ''; ?></option><?php endfor; ?></select>
                    <?php if ($cfg['whatsapp']): ?><small>¿Más de <?php echo (int) $cfg['maxGuests']; ?>? <a href="<?php echo esc_url($cfg['whatsapp']); ?>" target="_blank" rel="noopener">Escríbenos</a></small><?php endif; ?>
                </div>
                <p class="km-hint km-field-full" id="km-date-note" hidden></p>
            </div>
        </div>
        <p class="km-msg" role="alert" aria-live="assertive"></p>
        <button type="submit" class="km-btn km-btn-lg km-btn-block">Continuar <?php echo KM_Front::icon('arrow'); ?></button>
    </form>
    <?php endif; ?>

    <form class="km-step" data-step="2" hidden novalidate aria-labelledby="km-sum-title">
        <button type="button" class="km-back km-back-btn" data-act="back"><?php echo KM_Front::icon('back'); ?> <?php echo $is_event ? ($seat ? 'Cambiar puestos' : 'Cambiar mesa') : 'Cambiar fecha'; ?></button>
        <h2 id="km-sum-title" class="km-step-title">Tus datos</h2>
        <div class="km-box km-summary" id="km-summary"></div>
        <div class="km-box">
            <div class="km-fields">
                <div class="km-field km-field-full"><label for="km-name">Nombre y apellido</label><input id="km-name" type="text" autocomplete="name" required></div>
                <div class="km-field"><label for="km-email">Correo (recibirás el comprobante)</label><input id="km-email" type="email" autocomplete="email" inputmode="email" required></div>
                <div class="km-field"><label for="km-phone">WhatsApp</label>
                    <div class="km-phone"><select id="km-country" aria-label="Código de país">
                        <option value="+56" selected>🇨🇱 +56</option><option value="+54">🇦🇷 +54</option><option value="+51">🇵🇪 +51</option><option value="+57">🇨🇴 +57</option>
                        <option value="+52">🇲🇽 +52</option><option value="+55">🇧🇷 +55</option><option value="+58">🇻🇪 +58</option><option value="+593">🇪🇨 +593</option>
                        <option value="+591">🇧🇴 +591</option><option value="+598">🇺🇾 +598</option><option value="+595">🇵🇾 +595</option><option value="+1">🇺🇸 +1</option><option value="+34">🇪🇸 +34</option>
                    </select><input id="km-phone" type="tel" autocomplete="tel-national" inputmode="tel" placeholder="9 1234 5678" required></div>
                </div>
            </div>
            <input type="text" name="website" id="km-website" tabindex="-1" autocomplete="off" class="km-hp" aria-hidden="true">
        </div>
        <div class="km-promos" id="km-promos" hidden>
            <h3>Promociones opcionales</h3>
            <p class="km-small">Agrégalas si quieres; se canjean en el local con tu QR.</p>
            <div id="km-promo-list"></div>
        </div>
        <div class="km-total" id="km-total"></div>
        <label class="km-check"><input type="checkbox" id="km-terms"> <span><?php echo esc_html($cfg['terms']); ?></span></label>
        <label class="km-check"><input type="checkbox" id="km-consent"> <span>Quiero recibir novedades de eventos (opcional).</span></label>
        <p class="km-small"><?php echo $is_event ? ($seat ? 'Tus puestos quedan' : 'Tu mesa queda') : 'Tu mesa queda'; ?> reservad<?php echo $is_event && $seat ? 'os' : 'a'; ?> por <?php echo (int) $cfg['holdMinutes']; ?> minutos mientras pagas en línea. No necesitas crear una cuenta.</p>
        <p class="km-msg" role="alert" aria-live="assertive"></p>
        <button type="submit" class="km-btn km-btn-lg km-btn-block" data-act="pay">Ir a pagar</button>
    </form>
    <?php endif; ?>
</div>
