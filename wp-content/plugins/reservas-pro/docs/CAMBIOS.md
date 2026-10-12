# Cacho e Cabra – Informe de cambios (V30 → V31)

Plugin: **reservas-pro** ("KaceMaster Elite Reservations"), versión 30 → **31.1.0** (la sección 0 resume lo nuevo de la 31.1).
Alcance: se terminó y corrigió el sistema existente, **sin reconstruir el sitio**. Elementor,
el tema, las páginas actuales, WooCommerce, Webpay y Mercado Pago se mantienen.

Respaldos tomados antes de cambiar nada (fuera de la carpeta pública):
`km-backups/reservas-v30.php`, `km-tables-pre-v31.sql`, `km-options-pre-v31.sql`,
`store-options-pre-v31.json`.

Leyenda: **[C] Corrección** = algo que ya existía y fallaba. **[N] Nueva funcionalidad** = no existía.
**[+] Adicional** = no estaba pedido explícitamente, pero se agregó para que lo pedido funcione bien en la operación real.

---

## 0. Versión 31.1 – Venta por puestos y diseño nuevo

Respaldo de la V31 antes de estos cambios: `km-backups/reservas-v31-restored/`.

| | Antes (31.0) | Después (31.1) |
|---|---|---|
| [N] | Solo se vendían **mesas completas**: un grupo de 2 en una mesa de 4 bloqueaba los 4 puestos. | Se venden **puestos individuales** (cada silla del plano). Dos grupos pueden compartir una mesa y cada uno paga solo sus puestos. Cada puesto se identifica como *mesa-silla* (por ejemplo `12-3`). |
| [N] | — | Opción **Configuración > "Qué se vende en los eventos"**: *Puestos individuales* (por defecto) o *Mesas completas* (el modo anterior, con sus reglas de silla extra y puestos vacíos). |
| [+] | — | Las reservas anteriores siguen válidas: una reserva antigua ocupa todos los puestos de sus mesas. Se agregó la columna `seats_data` sin tocar los datos existentes. |
| [N] | Plano público con mesas. | Plano con **sillas que se tocan una a una**. Tocar una mesa toma de una vez los puestos libres que faltan. "Elegir por mí" prefiere una sola mesa y completa primero las mesas ya empezadas, para no desperdiciar lugares. |
| [C] | — | La retención, el pago tardío, los cambios desde el panel, los avisos de cambio y el plano imprimible trabajan por puesto. Nunca se vende dos veces el mismo puesto (mismo bloqueo por fecha que antes). |
| [N] | Panel: el plano dibujaba solo mesas. | Panel y plano imprimible muestran **cada silla con su estado** (pagado, en pago, llegaron, conflicto, bloqueado). Tocar una silla abre su reserva; tocar una mesa compartida muestra la lista de grupos. El selector de la reserva manual elige puestos. |
| [N] | Correos, WhatsApp, pedido y recepción decían "Mesas 3, 4". | Dicen **"Ubicación: Mesa 3 (puestos 3 y 4)"** o "Mesa 3" cuando es completa. |
| [N] | Diseño anterior. | **Diseño nuevo según la maqueta**: negro con brillo rojo, títulos con serifa, botones rojos, filtros por categoría, afiches con "Ver evento y reservar", franja de realizados con "Quiero que vuelva", franja de información, barra inferior en el celular (Eventos / Carta / WhatsApp) y botón rojo "WhatsApp – Hablemos". |
| [N] | La reserva estaba en una página aparte. | **La reserva está dentro de la página del evento**: afiche, video, contador de personas, hora de llegada, plano con escenario y leyenda, resumen ("Mesa 3 · 4 personas", total) y "Continuar con mi reserva". Los enlaces antiguos llevan al plano del evento. |
| [+] | — | Categoría por evento (para los filtros) y ciudad en Contenido. |
| [C] | En las páginas de marca, los estilos del tema pintaban botones y enlaces de rosado. | Esas páginas ya no cargan los estilos del tema (el resto del sitio no cambia). |

---

## 1. Seguridad (lo más urgente)

| | Antes (V30) | Después (V31) |
|---|---|---|
| [C] | **Cualquiera que escribiera el correo del administrador en el formulario de reserva quedaba conectado como administrador** (el formulario hacía `wp_set_auth_cookie` con la cuenta dueña de ese correo). Además, se creaba una cuenta de WordPress por cada cliente. | El cliente reserva sin cuenta y nunca se inicia sesión desde el formulario. Ya no se crean cuentas nuevas; las cuentas "fantasma" existentes se conservaron y no se borró nada. |
| [C] | Crear, editar y **borrar eventos** estaba abierto a visitantes anónimos (acciones AJAX `nopriv` sin permisos ni nonce). | Todas las acciones privadas pasan por una API REST con permisos por rol y nonce. Si se llama sin sesión, responde 401. |
| [C] | Las mesas venían del navegador y se guardaban tal cual. El precio se calculaba con datos del cliente. | El servidor valida cada mesa (existe, está libre, capacidad, cercanía) y calcula el precio desde el evento. Lo que envía el navegador solo se usa como propuesta. |
| [C] | Votos sin verificación: un mismo contacto podía votar sin límite, incluso por eventos que no existían. | El voto se verifica con un código enviado por correo o WhatsApp. Se permite un voto por contacto y evento, solo en eventos realizados. Los duplicados se detectan con una huella (hash) del contacto. |
| [+] | — | Formulario con trampa anti-bots (honeypot) y límite de intentos. El webhook de WhatsApp verifica la firma de Meta. En el panel, los tokens se muestran enmascarados. |
| [+] | Las pantallas antiguas de wp-admin no usaban nonces. | Se dejaron como respaldo, ahora con nonces, escape de datos y usando las mismas validaciones del sistema nuevo. |

## 2. Reservas, mesas y sobreventa

| | Antes | Después |
|---|---|---|
| [C] | Una reserva sin pagar quedaba en "pendiente" **para siempre** y bloqueaba sus mesas. | **Retención** de 15 minutos (configurable entre 5 y 60). Al vencer, el cron libera las mesas. |
| [C] | Dos personas podían comprar la misma mesa al mismo tiempo (la revisión se hacía solo en el navegador). | Bloqueo por fecha en la base de datos y actualizaciones atómicas. Probado con 4 compras simultáneas por la última mesa: solo 1 queda retenida. |
| [C] | No se validaba la relación entre personas y mesas. | Se validan capacidad, silla extra, asientos vacíos permitidos y que las mesas estén juntas, en el navegador **y** en el servidor. |
| [C] | Pago tardío (después de liberar la mesa): se confirmaba igual, con el riesgo de que la mesa ya estuviera vendida a otra persona. | Si las mesas siguen libres, se confirma. Si ya se vendieron, la reserva queda en **"Conflicto"** y se crea una incidencia para reasignar mesas o devolver el pago. |
| [+] | Una tarjeta rechazada cancelaba la reserva. | Si la retención sigue vigente, se mantienen las mesas y el comprobante muestra **"Reintentar pago"**. |
| [+] | — | El botón **"Elegir por mí"** sugiere la mejor combinación de mesas. En el celular, el plano parte ampliado y centrado en la mesa elegida. |
| [N] | — | **Reservas convencionales** (sin evento) por sector y horario, con capacidad por sector y opción de bloquearlas en noches con evento. |
| [N] | — | **Reservas manuales y "sin reserva"** (walk-in) desde el panel, usando las mismas reglas. |

## 3. Pagos (Webpay y Mercado Pago)

| | Antes | Después |
|---|---|---|
| [C] | El total se armaba como "cantidad = total ÷ precio del producto" más una línea "Ajuste al valor" (que podía ser negativa). Por eso cada pasarela podía ver montos distintos. | Una línea por reserva con cantidad = personas y precio exacto. Las promociones van como líneas propias. **Webpay y Mercado Pago reciben el mismo monto**, verificado en el checkout. |
| [C] | Se confirmaba al pasar el pedido a "procesando", sin revisar el monto y sin protección contra confirmaciones repetidas. | La confirmación es **idempotente**: si el aviso de pago llega varias veces, se confirma una sola vez y se envía un solo correo. Si el monto pagado no coincide, se crea una incidencia. "En espera" no confirma la reserva. |
| [N] | — | Devoluciones: la devolución total anula la reserva y su QR y avisa al cliente. La devolución parcial crea una incidencia para revisión. |
| [C] | La tienda estaba en país **Estados Unidos**, con **2 decimales** ("$18,000.00") y la zona horaria como desfase fijo. | Chile (Región Metropolitana), CLP sin decimales ("$18.000") y zona `America/Santiago`. Esto es solo configuración de la copia local; para producción ver la lista de puesta en marcha. |

## 4. Eventos y cartelera

| | Antes | Después |
|---|---|---|
| [C] | Los eventos eran solo un nombre, una fecha y un enlace `/reservas-2?fecha=…&nombre_evento=…`. | Cada evento tiene **estado** (borrador, publicado, oculto, cancelado, archivado), afiche, video, descripción, horario de inicio y término, precio y URL propia `/evento/nombre/`. **Los enlaces antiguos siguen funcionando** y redirigen al evento correcto. |
| [N] | — | Portada tipo cine: **"Por realizar"** y **"Realizados"** cambian solos según la hora de Chile, incluso en shows que terminan después de medianoche. |
| [N] | — | Página de evento con afiche, **video que carga solo al hacer clic** (no pesa en celulares), datos esenciales, botón Reservar y datos estructurados para Google (JSON-LD). |
| [N] | — | Voto **"Quiero que vuelva"** en eventos realizados, con resultados y exportación (solo de quienes aceptaron ser contactados). |
| [+] | — | Cambiar la fecha o cancelar un evento con reservas pide confirmación y muestra a cuántos clientes afecta. Al cancelar, se crean incidencias de devolución. |
| [+] | — | Se vincularon los afiches existentes de la biblioteca a sus eventos y se archivaron 3 eventos de prueba (no se borraron). |

## 5. Comunicación: QR, correo y WhatsApp

| | Antes | Después |
|---|---|---|
| [N] | No había confirmación ni QR. | **QR único por reserva**: es el mismo en el correo, en WhatsApp y en el comprobante web `/comprobante/`. |
| [N] | — | Correo de confirmación con diseño de marca. WhatsApp mediante la Cloud API oficial de Meta, con plantillas (ver `WHATSAPP-PLANTILLAS.md`). |
| [N] | — | **Recordatorios 2 días antes y el mismo día.** Nunca se duplican (clave única por reserva, canal y tipo). Si alguien compra después del horario del recordatorio, ese envío se omite y queda registrado. |
| [N] | — | **Registro de mensajes** con estado (enviado, entregado, leído, fallido, omitido) y botón para reintentar. |
| [C] | El número de WhatsApp de atención era el único número. | El **WhatsApp de atención** (botón flotante del sitio) queda separado del **número de notificaciones** automáticas. |

## 6. Panel privado (fuera de wp-admin) y recepción

| | Antes | Después |
|---|---|---|
| [C] | Administración solo dentro de wp-admin, para administradores. | Panel en **`/gestion/`**, pensado para celular y escritorio. Secciones: Inicio, Eventos, Reservas, Plano, Promociones, Votos, Mensajes, Incidencias, Contenido y Configuración. |
| [N] | — | **Roles separados**: *Administración* (todo) y *Recepción* (solo la pantalla de recepción y la búsqueda de reservas del día). |
| [N] | — | **Recepción** en **`/recepcion/`**: lector QR USB o cámara del celular, **llegadas parciales** ("ingresan 2 de 4"), bloqueo de reingreso, correcciones con motivo y canje de promociones. Si dos recepcionistas escanean a la vez, no se pasa del total. |
| [N] | — | **Historial (auditoría)** por reserva: quién cambió qué y cuándo. Hay una cola de **incidencias** (pagos tardíos, montos distintos, devoluciones). |
| [N] | — | **Contenido editable** (textos de portada, horarios, dirección, carta, galería, WhatsApp de atención) con **borrador, vista previa, publicación e historial para volver atrás**. |
| [+] | — | Exportación CSV, **plano imprimible** de la noche, bloqueo manual de mesas y una revisión técnica en wp-admin que avisa lo que falta configurar. |

## 7. Promociones

| | Antes | Después |
|---|---|---|
| [N] | — | Promociones que se compran junto con la reserva (precio, stock, máximo por reserva, fechas). Se pagan en el mismo pedido y **se canjean por cantidad** en recepción, con opción de deshacer el canje. |

## 8. Diseño (mobile-first)

| | Antes | Después |
|---|---|---|
| [N] | — | **Dos alternativas de diseño** de marca: "Noche de show" (oscura) y "Bodega" (cálida). Se elige en Panel > Contenido, con vista previa antes de publicar. Los colores de acento están validados por contraste. |
| [C] | Botón de WhatsApp gigante y sin estilo en páginas de Elementor. Logo del pie de página sobredimensionado. | Corregidos. Además, el botón no tapa la barra "Reservar" en el celular. |
| [+] | — | Accesibilidad: el plano se puede usar con teclado, los estados de las mesas se muestran también con ícono (no solo con color), hay enlace "saltar al contenido" y foco visible. |

## 9. Lo que NO se cambió (a propósito)

- La portada actual del sitio (página 348) **sigue siendo la portada**. La nueva cartelera quedó creada como página "Inicio – Cartelera" (676) y se activa con un clic desde Panel > Contenido **cuando el restaurante la apruebe**.
- No se borró ningún plugin, cuenta de usuario ni archivo antiguo. Solo se señalan en la revisión técnica: `reservasold.php` (V17), "Laboratorio Vincular" (plugin ajeno) y SeatReg (sin uso).
- No se hicieron pagos reales. Webpay está en ambiente de PRUEBA.
- No se publicó nada en el sitio en línea. Todo el trabajo está en la copia local.

## 10. Limitaciones conocidas

- El stock de una promoción se protege con el bloqueo de cada fecha. Si dos personas compran **la última unidad** de una misma promoción **para fechas distintas en el mismo segundo**, podría venderse una unidad de más. El riesgo es muy bajo. Si importa, se recomienda dar stock a las promociones por fecha.
- La verificación de votos por WhatsApp requiere la plantilla de autenticación aprobada. Mientras tanto, se verifica por correo.

## 11. Pruebas realizadas

- **115 verificaciones automáticas** (todas aprobadas), incluidas las de puestos: dos grupos en la misma mesa, puesto ya tomado, reserva antigua por mesa, cambio de puestos y modo "mesas completas". También: cambios de estado de eventos (incluido después de medianoche), reglas de mesas, retención y vencimiento, pago tardío, compras simultáneas, idempotencia, recordatorios sin duplicados, votos, recepción con dos personas a la vez, promociones, devoluciones y cambios de fecha. Los datos de prueba se eliminan solos al terminar.
- **Pruebas HTTP** de páginas, 404, video bajo demanda, accesos privados (401) y validaciones del servidor.
- **Pruebas en navegador** (Chrome automatizado, celular y escritorio): elegir puestos, compartir mesa, promoción y llegada al checkout con el mismo total ($21.000 en la página y en WooCommerce); panel (plano por silla, cambio de puestos, reserva manual con selector, mesa compartida), plano imprimible y recepción.
- Sin errores de sintaxis en PHP 7.4 y 8.2.
