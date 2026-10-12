<?php
if (!defined('ABSPATH')) exit;
/** @var object $b */
$valid = $b->status === 'confirmed';
$retry = $b->status === 'pending_payment' && $b->payment_status === 'failed';
$pending = !$retry && ($b->status === 'pending_payment' || ($b->status === 'expired' && !empty($_GET['pedido'])));
$retry_url = '';
if ($retry && $b->wc_order_id && function_exists('wc_get_order') && ($order = wc_get_order($b->wc_order_id)) && $order->needs_payment()) {
    $retry_url = $order->get_checkout_payment_url();
}
?>
<section class="km-wrap km-voucher" <?php echo $pending ? 'data-refresh="5"' : ''; ?>>
    <?php if ($valid): ?>
        <p class="km-eyebrow">Reserva confirmada</p>
        <h1>¡Te esperamos!</h1>
        <div class="km-voucher-card">
            <div class="km-voucher-qr">
                <img src="<?php echo esc_attr(KM_QR::data_uri($b)); ?>" width="260" height="260" alt="Código QR de la reserva <?php echo esc_attr($b->reservation_code); ?>">
                <p class="km-code"><?php echo esc_html($b->reservation_code); ?></p>
                <p class="km-small">Muestra este QR en recepción. Es el mismo que recibiste por correo y WhatsApp.</p>
            </div>
            <dl class="km-facts">
                <?php foreach (KM_Notify::summary_lines($b) as $k => $v): ?>
                    <div><dt><?php echo esc_html($k); ?></dt><dd><?php echo esc_html($v); ?></dd></div>
                <?php endforeach; ?>
                <?php if ((int) $b->arrived_guests > 0): ?><div><dt>Ingresos</dt><dd><?php echo (int) $b->arrived_guests . ' de ' . (int) $b->guests; ?></dd></div><?php endif; ?>
            </dl>
        </div>
        <p class="km-voucher-actions"><button type="button" class="km-btn km-btn-ghost" onclick="window.print()">Imprimir</button>
            <?php if (KM_Util::site('maps_url')): ?><a class="km-btn km-btn-ghost" href="<?php echo esc_url(KM_Util::site('maps_url')); ?>" target="_blank" rel="noopener">Cómo llegar</a><?php endif; ?></p>
    <?php elseif ($b->status === 'conflict'): ?>
        <h1>Pago recibido – revisión pendiente</h1>
        <p class="km-lead">Recibimos tu pago, pero los puestos que elegiste fueron tomados mientras pagabas. Nuestro equipo te contactará para asignarte otra ubicación o devolver el dinero.</p>
    <?php elseif ($retry): ?>
        <h1>El pago fue rechazado</h1>
        <p class="km-lead">Tus puestos siguen reservados por unos minutos. Puedes intentarlo con otra tarjeta o medio de pago.</p>
        <?php if ($retry_url): ?><a class="km-btn km-btn-lg" href="<?php echo esc_url($retry_url); ?>">Reintentar el pago</a><?php endif; ?>
        <p class="km-small">Código de reserva: <strong><?php echo esc_html($b->reservation_code); ?></strong></p>
    <?php elseif ($pending): ?>
        <h1>Estamos confirmando tu pago…</h1>
        <p class="km-lead">Esto puede tardar unos segundos. No cierres esta página. Si el pago se aprueba recibirás el comprobante por correo y WhatsApp.</p>
        <div class="km-spinner" aria-hidden="true"></div>
        <p class="km-small">Código de reserva: <strong><?php echo esc_html($b->reservation_code); ?></strong></p>
    <?php else: ?>
        <h1>Esta reserva no está activa</h1>
        <p class="km-lead"><?php
            echo $b->payment_status === 'refunded' ? 'El pago fue devuelto y el código QR ya no es válido.' :
                ($b->status === 'expired' ? 'El pago no se completó a tiempo y los puestos se liberaron.' :
                ($b->payment_status === 'failed' ? 'El pago fue rechazado. Puedes intentarlo de nuevo.' : 'La reserva fue cancelada.')); ?></p>
        <a class="km-btn" href="<?php echo esc_url($b->event_id ? KM_Util::booking_url((int) $b->event_id) : KM_Util::booking_url()); ?>">Reservar nuevamente</a>
    <?php endif; ?>
</section>
