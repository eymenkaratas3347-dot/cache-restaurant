# Guía de uso – Panel y Recepción

Cada persona entra con **su propio usuario**. El sistema registra quién hace cada cambio.

| Pantalla | Dirección | Quién |
|---|---|---|
| Panel de administración | `/gestion/` | Rol "Administración" |
| Recepción (puerta) | `/recepcion/` | Roles "Recepción" y "Administración" |

Ambas pantallas funcionan en el celular. Se puede "Agregar a pantalla de inicio" para abrirlas como una app.

---

## Recepción (puerta)

1. Abre `/recepcion/`. El cursor queda listo en el campo de búsqueda.
2. **Escanea el QR** del cliente:
   - con un **lector USB/Bluetooth**: basta con disparar, el lector "escribe" el código;
   - con el **celular**: toca "Cámara" y apunta al QR;
   - **sin QR**: escribe el código de reserva (6 letras), el nombre o el teléfono.
3. Lee el aviso grande:
   - **Verde**: la reserva es válida para hoy. Indica cuántas personas entran ahora (por ejemplo 2 de 4) y toca **Registrar ingreso**.
     Cuando llegue el resto del grupo, escanea el mismo QR otra vez.
   - **Rojo**: no permite ingreso. El motivo aparece escrito: *QR ya utilizado*, *es para otra fecha*,
     *pago pendiente*, *cancelada* o *conflicto*. En estos casos llama al encargado; no dejes pasar "por mientras".
4. **Promociones** compradas: aparecen en la misma ficha. Toca **Canjear 1** cada vez que entregas una.
5. ¿Te equivocaste en un ingreso? Usa **Corregir ingresos** con un número negativo y escribe el motivo.
   Solo administración puede revertir un canje de promoción.

La lista **"Reservas de hoy"** se actualiza sola cada 30 segundos y muestra quién falta por llegar.
Los shows que terminan después de medianoche siguen siendo válidos hasta las 6:00 del día siguiente.

Si dos recepcionistas escanean el mismo QR a la vez, el sistema nunca deja entrar más personas que las reservadas.

---

## Panel de administración

### Inicio
Muestra el resumen del día y el próximo evento: reservas, personas, pagos pendientes e incidencias abiertas.

### Eventos
- **Nuevo evento**: nombre, fecha, horario de inicio y término, precio por persona, afiche, video (YouTube o archivo) y descripción.
  Se guarda como **Borrador** y no aparece en el sitio hasta que lo publiques.
- Estados: **Publicado** (visible y a la venta) · **Oculto** (no aparece en la cartelera, pero el enlace directo funciona: sirve para preventas o pruebas) ·
  **Cancelado** · **Archivado**.
- No hace falta mover los eventos a "Realizados": pasan solos cuando termina el show.
- Si cambias la fecha o cancelas un evento **con reservas**, el panel te muestra a cuántos clientes afecta y te pide confirmar.
  Al cancelar, se avisa a los clientes y se crea una incidencia de devolución por cada pago.
- **Duplicar** sirve para eventos que se repiten (por ejemplo, karaoke todos los jueves).

### Reservas
- Busca por nombre, teléfono, código, fecha o estado. Toca una reserva para ver su ficha completa, su historial y sus mensajes.
- Estados: **Pago en curso** (puestos retenidos unos minutos mientras paga) · **Confirmada** · **No pagada** (no completó el pago y los puestos se liberaron) ·
  **Cancelada** · **Conflicto** (pagó tarde y sus puestos ya estaban vendidos: revisar en Incidencias).
- La columna **Ubicación** muestra la mesa y los puestos, por ejemplo "Mesa 3 (puestos 3 y 4)". Si el grupo ocupa la mesa entera, dice solo "Mesa 3".
- **+ Reserva manual** (por teléfono) y **+ Sin reserva (walk-in)** (llegan a la puerta): usan las mismas reglas.
  En **Puestos** escribe `mesa-silla` separados por coma (`12-1, 12-2`), o solo el número de la mesa (`12`) para tomarla completa.
  Es más fácil con **Elegir en el plano**: toca cada silla, o toca una mesa para tomar los puestos libres que faltan. **Sugerir** propone los mejores.
- **Reenviar correo** / **Reenviar WhatsApp** vuelven a mandar la confirmación con el mismo QR.
- **Exportar CSV** abre el listado filtrado en Excel.

### Plano
El plano de la noche muestra **cada silla** con su color: libre (blanca), pagada (roja), pago en curso (amarilla), llegaron (verde) y conflicto (morada).
Una mesa rosada tiene algunos puestos vendidos y otros libres; una mesa roja está completa.
Toca una silla para abrir la reserva de ese puesto. Si la mesa la comparten dos grupos, al tocar la mesa aparece la lista para elegir.
Con **Modo bloquear mesas** puedes sacar mesas enteras de la venta (por ejemplo, para prensa o el músico); solo se pueden bloquear mesas sin puestos vendidos.
**Imprimir plano** genera una hoja para la puerta con el plano por silla y la lista de ubicaciones.

### Promociones
Crea promociones (precio, stock, máximo por reserva y fechas en que se venden). El cliente las compra al reservar y en la puerta se canjean por cantidad.

### Votos "Quiero que vuelva"
Muestra los resultados por evento. La exportación incluye solo a quienes aceptaron ser contactados.

### Mensajes
Registro de todos los correos y WhatsApp enviados, con su estado. Si alguno falló, usa **Reintentar**.
"Omitido" significa que no correspondía enviarlo (por ejemplo, el canal no está configurado o la compra se hizo después de la hora del recordatorio).

### Incidencias
Son los casos que necesitan una persona: pagos tardíos con conflicto, montos que no coinciden y devoluciones.
Resuelve el caso (reasignar mesas desde la ficha de la reserva o devolver desde WooCommerce) y toca **Marcar resuelta** con una nota de lo que se hizo.

### Contenido
Textos de la portada, horarios, dirección, carta, galería, redes, **WhatsApp de atención** y alternativa de diseño.
Los cambios quedan en **borrador**: revisa con **Vista previa** y luego **Publicar**. Si algo sale mal, en **Versiones anteriores** puedes restaurar.

### Configuración (solo la cuenta de Administrador de WordPress)
El rol "Cacho e Cabra – Administración" no ve esta sección: contiene claves de pago y de WhatsApp.

Precio por defecto, minutos de retención, mínimo y máximo de personas, horarios de recordatorios,
correo de alertas, WhatsApp y editor de mesas del plano (puestos por mesa, sector, activa y vecinas).

**Qué se vende en los eventos**:
- **Puestos individuales** (por defecto): el cliente elige sillas y paga solo las que usa; dos grupos pueden compartir una mesa.
- **Mesas completas**: cada reserva toma mesas enteras. En este modo se aplican "sillas extra" y "puestos vacíos tolerados".

Cambiar esta opción no modifica las reservas ya hechas: cada una conserva sus puestos.

---

## Preguntas frecuentes

**Un cliente dice que pagó, pero la reserva no aparece confirmada.**
Búscala en Reservas. Si está en "Pago en curso", el pago aún no llega desde Webpay o Mercado Pago. Revisa el pedido en WooCommerce.
Si el pago está aprobado allá, la reserva se confirma sola. Si no se confirma, aparecerá una incidencia.

**A un cliente le rechazaron la tarjeta.**
Sus puestos quedan retenidos hasta que venza la retención. En su comprobante aparece el botón "Reintentar pago".

**Un cliente no recibió el QR.**
Ficha de la reserva > **Reenviar correo** o **Reenviar WhatsApp**. El cliente también puede dar su código de 6 letras en la puerta.

**Hay que cambiar a un grupo de lugar.**
Ficha de la reserva > Puestos > **Elegir en el plano** y elige los nuevos (o escríbelos, por ejemplo `5-1, 5-2`). El sistema impide asignar puestos ocupados.
Si dejas marcada la casilla "Avisar al cliente si cambian puestos, fecha u hora", el cliente recibe el aviso con su mismo QR.

**Dos grupos comparten una mesa. ¿Cómo sé quién es quién?**
En el plano, toca la silla: se abre la reserva de ese puesto. En recepción, la ficha del cliente muestra su ubicación ("Mesa 3 (puestos 3 y 4)").
