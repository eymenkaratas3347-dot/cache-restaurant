# Plantillas de WhatsApp (Cloud API de Meta)

El sistema envía por WhatsApp solo **plantillas aprobadas por Meta**. Los nombres
de abajo son los valores por defecto. Si Meta obliga a usar otro nombre, se cambia
en **Panel > Configuración > WhatsApp**, sin tocar código.

> El **orden** de las variables `{{1}}`, `{{2}}`, … debe ser exactamente el indicado.
> Si una plantilla se crea con otro orden, el mensaje llegará con los datos cruzados.

Idioma por defecto: `es` (Español). Si la plantilla se aprueba como `es_CL` o `es_ES`,
actualizar el campo "Idioma" en la configuración.

---

## 1. `reserva_confirmada` (categoría: Utilidad)

**Encabezado:** Imagen (el sistema adjunta el **QR único** de la reserva).

| Variable | Contenido | Ejemplo |
|---|---|---|
| `{{1}}` | Nombre del cliente | Camila Rojas |
| `{{2}}` | Evento o tipo de reserva | Tributo a Luis Miguel |
| `{{3}}` | Fecha | viernes 27 de febrero |
| `{{4}}` | Hora | 21:00 |
| `{{5}}` | Personas | 4 |
| `{{6}}` | Mesas o salón | Mesa(s) 12, 13 |
| `{{7}}` | Código de reserva | K7Q2MD |
| `{{8}}` | Monto pagado | $72.000 |
| `{{9}}` | Enlace al comprobante (mismo QR) | https://restaurantcachoecabra.cl/comprobante/… |
| `{{10}}` | WhatsApp de atención al cliente | +56 9 … |

Texto sugerido:

```
Hola {{1}}, ¡tu reserva está confirmada! 🎶
{{2}} – {{3}} a las {{4}}.
Personas: {{5}} · {{6}}
Código: {{7}} · Pagado: {{8}}
Presenta el QR de este mensaje al llegar. Comprobante: {{9}}
¿Dudas? Escríbenos a {{10}}.
```

## 2. `recordatorio_reserva` (Utilidad)

**Encabezado:** Imagen (QR).

| Variable | Contenido |
|---|---|
| `{{1}}` | Nombre |
| `{{2}}` | Evento o tipo de reserva |
| `{{3}}` | Cuándo ("en dos días (viernes 27 de febrero)" u "hoy") |
| `{{4}}` | Hora |
| `{{5}}` | Mesas o salón |
| `{{6}}` | Código |
| `{{7}}` | Enlace al comprobante |

```
Hola {{1}}, te esperamos {{3}} para {{2}} a las {{4}}.
{{5}} · Código {{6}}.
Presenta este QR al llegar: {{7}}
```

## 3. `reserva_cancelada` (Utilidad)

Sin encabezado.

| Variable | Contenido |
|---|---|
| `{{1}}` | Nombre |
| `{{2}}` | Evento o tipo de reserva |
| `{{3}}` | Fecha |
| `{{4}}` | Motivo (ej. "Tu pago fue devuelto.") |
| `{{5}}` | WhatsApp de atención |

```
Hola {{1}}, tu reserva para {{2}} del {{3}} fue cancelada. {{4}}
Para cualquier consulta escríbenos a {{5}}.
```

## 4. `reserva_modificada` (Utilidad)

**Encabezado:** Imagen (QR; es el mismo QR de siempre).

| Variable | Contenido |
|---|---|
| `{{1}}` | Nombre |
| `{{2}}` | Evento o tipo de reserva |
| `{{3}}` | Nueva fecha |
| `{{4}}` | Nueva hora |
| `{{5}}` | Mesas o salón |
| `{{6}}` | Enlace al comprobante |

```
Hola {{1}}, tu reserva para {{2}} cambió: {{3}} a las {{4}}, {{5}}.
Tu QR sigue siendo el mismo: {{6}}
```

## 5. `codigo_verificacion` (categoría: **Autenticación**)

Se usa para verificar el voto "Quiero que vuelva" cuando la persona elige WhatsApp.
Crear con la plantilla estándar de autenticación de Meta, con el botón **"Copiar código"**.

| Componente | Contenido |
|---|---|
| Cuerpo `{{1}}` | Código de 6 dígitos |
| Botón (URL/copiar código), índice 0 | El mismo código |

---

## Configuración en Meta (resumen)

1. Una cuenta de Meta Business **a nombre del restaurante** (no del proveedor).
2. Crear la app en developers.facebook.com con el producto WhatsApp y registrar el
   **número de notificaciones**. Se recomienda un número distinto del WhatsApp de atención que
   usa el personal en su teléfono: un número registrado en la Cloud API, en general, deja de poder
   usarse en la app de WhatsApp (salvo con el modo "coexistencia" de WhatsApp Business, que
   tiene limitaciones). El número de atención se configura aparte, en Panel > Contenido.
3. Crear un **usuario del sistema** con un token permanente (permiso `whatsapp_business_messaging`).
4. En el panel: Configuración > WhatsApp, ingresar el *Phone number ID*, el token,
   el *App secret* y una palabra de verificación del webhook. El panel muestra la URL del webhook
   (`/wp-json/km/v1/whatsapp`). Pegarla en Meta y suscribir el campo `messages`.
5. Enviar las 5 plantillas a revisión. La aprobación suele tardar desde minutos hasta 24 horas.
6. Probar con el botón "Probar WhatsApp" (Panel > Configuración > Pruebas de envío).

Mientras WhatsApp no esté configurado, el sistema sigue enviando correos y registra
los mensajes de WhatsApp como "omitido" en Panel > Mensajes, así que no se pierde ninguno sin dejar registro.
