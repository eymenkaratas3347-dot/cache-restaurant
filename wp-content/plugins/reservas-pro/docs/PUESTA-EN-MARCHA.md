# Puesta en marcha en el sitio real

Nada de esto se ha hecho en el sitio en línea. Cada paso debe acordarse con el restaurante.
Las cuentas (Meta, Transbank, Mercado Pago, correo, hosting) deben quedar **a nombre y bajo
control del restaurante**. El proveedor no debe usar sus propias cuentas ni sus correos.

La pantalla **wp-admin > Reservas Elite** muestra una revisión técnica en verde, amarillo o rojo
con la mayoría de estos puntos. Antes de abrir las ventas, debe quedar todo en verde.

## Antes de subir

1. **Respaldo completo** del sitio en línea (archivos y base de datos). El sitio tiene All-in-One WP Migration.
2. Subir la carpeta `wp-content/plugins/reservas-pro/` completa, **excepto** `reservasold.php`.
3. Activar o reactivar el plugin. La migración de base de datos es automática y versionada: conserva las reservas,
   eventos y votos existentes y solo agrega columnas y tablas (en la 31.1: puestos de cada reserva y categoría del evento).
4. Vaciar la caché (LiteSpeed u otra) para que los visitantes reciban el diseño y el plano nuevos.
5. Decidir con el restaurante qué se vende en los eventos: **puestos individuales** (por defecto) o **mesas completas**
   (Panel > Configuración). Las reservas existentes conservan sus mesas en cualquiera de los dos modos.

## Configuración de la tienda (WooCommerce > Ajustes)

En la copia local ya está hecho. En producción hay que repetirlo:

| Ajuste | Valor |
|---|---|
| País / región de la tienda | Chile – Región Metropolitana |
| Moneda | Peso chileno (CLP) |
| Posición de la moneda | Izquierda |
| Separador de miles / decimales | `.` / `,` |
| Número de decimales | **0** |
| Permitir pedidos sin cuenta | Sí |
| Ajustes > Generales > Zona horaria | **Santiago** (`America/Santiago`), no "UTC-3" |

El producto de reservas debe ser **virtual**. Se elige en Panel > Configuración.

## Pagos

- **Webpay (Transbank):** hoy está en ambiente **PRUEBA**. Para cobrar de verdad hay que pasarlo a **PRODUCCIÓN**
  con el código de comercio y la API key del restaurante, que entrega Transbank tras su proceso de validación.
- **Mercado Pago:** usar las credenciales de producción de la cuenta del restaurante.
- Probar con pagos reales **solo con el dueño presente y montos acordados** (ver "Plan de pruebas").

## Correo

Hoy los correos salen con la función `mail()` del servidor y tienen un riesgo alto de caer en spam.
Configurar **WP Mail SMTP** (ya instalado) con un proveedor: SMTP del dominio, Brevo, SendGrid o
Amazon SES. Agregar los registros SPF/DKIM del dominio. Probar con el botón "Probar correo"
(Panel > Configuración > Pruebas de envío).

## WhatsApp

Seguir `WHATSAPP-PLANTILLAS.md`. Hasta que esté listo, el sistema funciona solo con correo.

## Tareas automáticas (cron)

Los recordatorios y la liberación de mesas dependen del cron de WordPress. En producción conviene
un **cron real del hosting cada 5 minutos**:

```
*/5 * * * * wget -q -O - https://restaurantcachoecabra.cl/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Luego agregar `define('DISABLE_WP_CRON', true);` en `wp-config.php`.

## Usuarios del personal

Crear en wp-admin > Usuarios una cuenta **por persona** (no compartir cuentas) con el rol:

- **Cacho e Cabra – Administración**: dueño o encargado.
- **Cacho e Cabra – Recepción**: puerta.

Cada persona entra en `/gestion/` o `/recepcion/` con su usuario. El historial registra quién hizo cada cambio.

## Contenido

1. Subir los afiches que faltan: **Noche de pop** y **Noche de romance** muestran hoy una imagen genérica.
2. Revisar textos, horarios, dirección, carta y **WhatsApp de atención** en Panel > Contenido.
3. Elegir la alternativa de diseño ("Noche de show" o "Bodega") con la vista previa, y revisar la ciudad que aparece en la portada.
   Asignar una **categoría** a cada evento (Tributos, Música en vivo…): los filtros de la cartelera aparecen cuando hay más de una.
4. **Cuando el restaurante lo apruebe**, hacer clic en "Usar como página de inicio" (Panel > Contenido) para que la
   cartelera nueva sea la portada. La página anterior no se borra: para volver a ella, ir a wp-admin >
   Ajustes > Lectura y elegirla como página de inicio.

## Limpieza (con aprobación)

- Borrar `reservas-pro/reservasold.php` (versión V17 antigua).
- Desactivar **Laboratorio Vincular**: es de otro proyecto (chat con IA) y agrega carga y riesgo sin uso.
- Desactivar **SeatReg** si no se usa (el sistema nuevo no lo necesita).
- Revisar las cuentas de cliente creadas automáticamente por la V30 (rol suscriptor). No se borraron.

## Plan de pruebas en producción (con el dueño)

Con Webpay y Mercado Pago en producción, y un evento de prueba **oculto** con precio bajo acordado:

1. Reservar 2 personas y pagar con Webpay. Revisar que el correo y el WhatsApp traigan el mismo QR, y que el monto
   sea igual en el pedido, en Transbank y en el panel.
2. Repetir con Mercado Pago.
3. Rechazar una tarjeta a propósito. Debe aparecer "Reintentar pago" y los puestos deben seguir retenidos.
   Con otro celular, revisar que esos puestos aparezcan ocupados y que los demás puestos de la misma mesa sigan a la venta.
4. Escanear el QR en `/recepcion/`: ingresar 1 persona y luego la otra. Un tercer escaneo debe decir "QR ya utilizado".
5. Hacer la devolución desde WooCommerce. El QR debe quedar anulado y el cliente debe recibir el aviso.
6. Archivar el evento de prueba.

## Costos recurrentes (referenciales, a confirmar con cada proveedor)

| Servicio | Costo |
|---|---|
| Hosting y dominio | El plan actual (sin cambios) |
| Transbank Webpay / Mercado Pago | Comisión por transacción, según el contrato del restaurante. Sin mensualidad adicional por este sistema. |
| WhatsApp Cloud API | Meta cobra por mensaje de plantilla entregado, según la tarifa vigente para Chile. Las de "Utilidad" son más baratas que las de "Marketing", y en general hay un precio por categoría. Con ~4 mensajes por reserva (confirmación, 2 recordatorios y eventual cambio), el costo es bajo. Revisar la tabla oficial de precios de Meta. |
| Correo transaccional | SMTP del dominio: incluido en el hosting. Brevo/SendGrid: plan gratuito suficiente para el volumen esperado. |
| Licencias | Ninguna nueva. El plugin es propio; la librería QR (phpqrcode) es LGPL y el lector de cámara alternativo (jsQR) es Apache 2.0. |

## Nota sobre la copia local

En este equipo, Apache tiene Xdebug activo en modo desarrollo y cada página tarda unos 5 segundos.
Eso es solo del entorno local y no afecta al sitio real.
