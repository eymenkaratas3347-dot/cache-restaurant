/* Private panel (/gestion). Every change goes through the REST API, which validates and audits it. */
(function () {
  'use strict';
  var K = window.KP, U = window.KPU, h = U.h, api = U.api, toast = U.toast;
  var app = document.getElementById('kp-app');
  var main, sideLinks = {}, side;
  var cache = { events: null };

  var SECTIONS = [
    ['inicio', 'Inicio'], ['eventos', 'Eventos'], ['reservas', 'Reservas'], ['plano', 'Plano y mesas'],
    ['promociones', 'Promociones'], ['votos', 'Quiero que vuelva'], ['mensajes', 'Mensajes'], ['incidencias', 'Incidencias'],
    ['contenido', 'Contenido del sitio']
  ];
  if (K.canConfigure) SECTIONS.push(['configuracion', 'Configuración']);

  function shell() {
    app.innerHTML = '';
    side = h('nav', { class: 'kp-side', 'aria-label': 'Secciones' }, [h('h1', { text: 'Cacho e Cabra' })]);
    SECTIONS.forEach(function (s) {
      var a = h('a', { href: '#' + s[0] }, [s[1]]);
      sideLinks[s[0]] = a;
      side.appendChild(a);
    });
    side.appendChild(h('a', { href: K.receptionUrl }, ['Recepción ↗']));
    side.appendChild(h('a', { href: K.homeUrl, target: '_blank', rel: 'noopener' }, ['Ver sitio ↗']));
    side.appendChild(h('a', { href: K.logout }, ['Salir']));
    side.appendChild(h('small', { text: K.user }));
    main = h('main', { class: 'kp-main' });
    app.appendChild(side);
    app.appendChild(main);
    side.addEventListener('click', function (e) { if (e.target.closest('a')) side.classList.remove('is-open'); });
  }

  function top(title, buttons) {
    main.innerHTML = '';
    var menu = h('button', { class: 'kp-btn ghost kp-menu-btn', 'aria-label': 'Menú', text: '☰', onclick: function () { side.classList.add('is-open'); } });
    main.appendChild(h('div', { class: 'kp-top' }, [h('div', { class: 'kp-row' }, [menu, h('h2', { text: title })]), h('div', { class: 'kp-row' }, buttons || [])]));
  }

  function fail(r) { toast(r.message || 'No se pudo completar la acción.', 'err'); if (r.code === 'auth') setTimeout(function () { location.reload(); }, 1500); }

  function td(label, content) { return h('td', { 'data-l': label }, [].concat(content)); }

  function badges(d) {
    var inc = sideLinks.incidencias, msg = sideLinks.mensajes;
    [[inc, d.incidents_open], [msg, d.messages_error]].forEach(function (p) {
      var old = p[0].querySelector('.kp-badge');
      if (old) old.remove();
      if (p[1]) p[0].appendChild(h('span', { class: 'kp-badge bad', text: p[1] }));
    });
  }

  function loadEvents(force) {
    if (cache.events && !force) return Promise.resolve(cache.events);
    return api('GET', '/panel/events').then(function (r) { cache.events = r.ok ? r.events : []; return cache.events; });
  }

  function eventStatus(e) {
    if (e.status === 'published' && e.is_past) return h('span', { class: 'kp-badge info', text: 'Realizado' });
    return U.badge('event', e.status);
  }

  // =========================================================== Dashboard
  function viewInicio() {
    top('Inicio', [
      h('button', { class: 'kp-btn', text: '+ Nuevo evento', onclick: function () { editEvent(null); } }),
      h('button', { class: 'kp-btn ghost', text: '+ Reserva manual', onclick: function () { manualBooking(); } }),
      h('a', { class: 'kp-btn green', href: K.receptionUrl, text: 'Abrir recepción' })
    ]);
    api('GET', '/panel/dashboard').then(function (d) {
      if (!d.ok) return fail(d);
      badges(d);
      var grid = h('div', { class: 'kp-grid' });
      function stat(n, label, alert) { grid.appendChild(h('div', { class: 'kp-stat' + (alert ? ' alert' : '') }, [h('b', { text: n }), h('span', { text: label })])); }
      var t = d.today_stats;
      stat(t.bookings, 'Reservas hoy (' + d.today_human + ')');
      stat(t.guests, 'Personas esperadas hoy');
      stat(t.arrived + ' / ' + t.guests, 'Ingresaron hoy');
      if (K.unit === 'seat') stat(t.free_seats + ' / ' + t.total_seats, 'Puestos libres hoy');
      else stat(t.free_tables + ' / ' + t.total_tables, 'Mesas libres hoy');
      stat(d.incidents_open, 'Incidencias abiertas', d.incidents_open > 0);
      stat(d.messages_error, 'Mensajes con error (14 días)', d.messages_error > 0);
      main.appendChild(grid);
      var ev = d.next_event;
      var card = h('div', { class: 'kp-card', style: 'margin-top:14px' }, [h('h3', { text: 'Próximo evento' })]);
      if (ev) {
        var a = ev.availability;
        card.appendChild(h('div', { class: 'kp-row', style: 'align-items:flex-start' }, [
          ev.poster ? h('img', { src: ev.poster, alt: '', style: 'width:110px;border-radius:8px' }) : null,
          h('div', {}, [
            h('p', { style: 'margin:0;font-size:20px;font-weight:800', text: ev.name }),
            h('p', { style: 'margin:4px 0', text: ev.date_human + ' · show ' + ev.show_time + ' h' }),
            h('p', { style: 'margin:4px 0', text: (a.unit === 'seat' ? 'Puestos libres: ' : 'Mesas libres: ') + a.free + ' de ' + a.total + (a.sold_out ? ' · AGOTADO' : '') }),
            h('div', { class: 'kp-row' }, [
              h('a', { class: 'kp-btn sm ghost', href: '#reservas?event_id=' + ev.id, text: 'Ver reservas' }),
              h('a', { class: 'kp-btn sm ghost', href: '#plano?date=' + ev.date, text: 'Ver plano' }),
              h('a', { class: 'kp-btn sm ghost', href: ev.url, target: '_blank', rel: 'noopener', text: 'Ver página' })
            ])
          ])
        ]));
      } else {
        card.appendChild(h('p', { text: 'No hay eventos publicados a futuro. Crea uno desde "Eventos".' }));
      }
      main.appendChild(card);
    });
  }

  // =========================================================== Events
  function viewEventos() {
    top('Eventos', [h('button', { class: 'kp-btn', text: '+ Nuevo evento', onclick: function () { editEvent(null); } })]);
    var filter = h('select', {}, [h('option', { value: 'upcoming', text: 'Por realizar y borradores' }), h('option', { value: 'past', text: 'Realizados' }), h('option', { value: 'all', text: 'Todos' })]);
    var q = h('input', { type: 'search', placeholder: 'Buscar por nombre…' });
    main.appendChild(h('div', { class: 'kp-filters' }, [filter, q]));
    var box = h('div');
    main.appendChild(box);
    function render(list) {
      var f = filter.value, s = q.value.toLowerCase();
      list = list.filter(function (e) {
        if (s && e.name.toLowerCase().indexOf(s) === -1) return false;
        if (f === 'upcoming') return !e.is_past && e.status !== 'archived';
        if (f === 'past') return e.is_past || e.status === 'archived';
        return true;
      });
      box.innerHTML = '';
      if (!list.length) { box.appendChild(h('div', { class: 'kp-empty', text: 'No hay eventos en esta vista.' })); return; }
      var tb = h('tbody');
      list.forEach(function (e) {
        tb.appendChild(h('tr', {}, [
          td('Afiche', e.poster ? h('img', { src: e.poster, alt: '', style: 'width:48px;height:64px;object-fit:cover;border-radius:4px' }) : h('span', { class: 'kp-badge warn', text: 'Sin afiche' })),
          td('Evento', [h('strong', { text: e.name }), h('div', { class: 'muted', text: e.date_human + ' · ' + e.show_time + ' h' })]),
          td('Estado', [eventStatus(e), e.featured ? h('span', { class: 'kp-badge info', text: 'Destacado', style: 'margin-left:4px' }) : null, e.sold_out ? h('span', { class: 'kp-badge bad', text: 'Agotado', style: 'margin-left:4px' }) : null]),
          td('Reservas', (e.bookings_count || 0) + ' (' + (e.guests_count || 0) + ' pers.)'),
          td('Precio', U.money(e.price) + (e.payment_mode === 'abono' ? ' abono' : '')),
          td('', h('div', { class: 'kp-row' }, eventActions(e)))
        ]));
      });
      box.appendChild(h('table', { class: 'kp-table' }, [h('thead', {}, h('tr', {}, ['', 'Evento', 'Estado', 'Reservas', 'Precio', 'Acciones'].map(function (t) { return h('th', { text: t }); }))), tb]));
    }
    var load = function (force) { loadEvents(force).then(render); };
    filter.addEventListener('change', function () { load(); });
    q.addEventListener('input', function () { load(); });
    load(true);
  }

  function eventActions(e) {
    var b = [h('button', { class: 'kp-btn sm', text: 'Editar', onclick: function () { editEvent(e); } })];
    b.push(h('a', { class: 'kp-btn sm ghost', href: e.url + (e.status !== 'published' ? '?km_preview=1' : ''), target: '_blank', rel: 'noopener', text: e.status === 'published' ? 'Ver' : 'Vista previa' }));
    var more = h('select', { class: 'kp-btn sm ghost', 'aria-label': 'Más acciones' }, [h('option', { value: '', text: 'Más…' })]);
    var opts = [['duplicate', 'Duplicar']];
    if (e.status !== 'published' && !e.is_past) opts.push(['publish', 'Publicar']);
    if (e.status === 'published' && !e.is_past) opts.push(['hide', 'Ocultar']);
    if (e.status !== 'draft' && !e.bookings_count) opts.push(['draft', 'Pasar a borrador']);
    if (e.status !== 'cancelled' && !e.is_past) opts.push(['cancel', 'Cancelar evento']);
    if (e.status !== 'archived') opts.push(['archive', 'Archivar']);
    opts.push(['bookings', 'Ver reservas']);
    if (!e.bookings_count) opts.push(['delete', 'Borrar']);
    opts.forEach(function (o) { more.appendChild(h('option', { value: o[0], text: o[1] })); });
    more.addEventListener('change', function () { var v = more.value; more.value = ''; eventAction(e, v); });
    b.push(more);
    return b;
  }

  function eventAction(e, act) {
    if (!act) return;
    var done = function (r, txt) { if (!r.ok) return fail(r); toast(txt, 'ok'); cache.events = null; route(); };
    if (act === 'bookings') { location.hash = 'reservas?event_id=' + e.id; return; }
    if (act === 'duplicate') { api('POST', '/panel/events/' + e.id + '/duplicate').then(function (r) { if (!r.ok) return fail(r); cache.events = null; toast('Copia creada como borrador (+7 días). Revisa la fecha.', 'ok'); editEvent(r.event); }); return; }
    if (act === 'delete') { U.confirm('¿Borrar definitivamente "' + e.name + '"?', 'Borrar').then(function (ok) { if (ok) api('DELETE', '/panel/events/' + e.id).then(function (r) { done(r, 'Evento borrado.'); }); }); return; }
    if (act === 'cancel') {
      api('GET', '/panel/events/' + e.id + '/impact').then(function (r) {
        var i = r.impact || { active: 0, guests: 0, paid: 0 };
        U.prompt('Cancelar "' + e.name + '". Tiene ' + i.active + ' reserva(s) activas (' + i.guests + ' personas, ' + i.paid + ' pagadas). Las reservas se cancelan, se avisa a los clientes y las devoluciones quedan como incidencias.', 'Motivo (se envía al cliente)').then(function (reason) {
          if (reason === null) return;
          api('POST', '/panel/events/' + e.id + '/action', { action: 'cancel', reason: reason, notify: 1 }).then(function (r2) { done(r2, 'Evento cancelado. ' + (r2.data ? r2.data.affected : 0) + ' reserva(s) afectadas.'); });
        });
      });
      return;
    }
    var labels = { publish: 'Evento publicado.', hide: 'Evento oculto: ya no aparece en la cartelera.', draft: 'Evento en borrador.', archive: 'Evento archivado.' };
    api('POST', '/panel/events/' + e.id + '/action', { action: act }).then(function (r) { done(r, labels[act]); });
  }

  function editEvent(e) {
    var isNew = !e || !e.id;
    e = e || {};
    var f = U.form([
      { type: 'section', label: 'Datos principales' },
      { k: 'name', label: 'Nombre del evento', full: true },
      { k: 'category', label: 'Categoría (filtro de la portada)', help: 'Ej: Tributos, Música en vivo, Humor. Escríbela siempre igual para que se agrupen.' },
      { k: 'date', label: 'Fecha', type: 'date' },
      { k: 'show_time', label: 'Hora del show', type: 'time', def: '21:00' },
      { k: 'arrival_raw', label: 'Horarios de llegada', help: 'Separados por coma, ej: 19:30, 20:00, 20:30. Si queda vacío se usa la hora del show.' },
      { k: 'end_time', label: 'Hora de término', type: 'time', def: '23:59', help: 'Si es menor que la hora del show, se entiende que termina pasada la medianoche. Al terminar, pasa a "Realizados".' },
      { type: 'section', label: 'Afiche y video' },
      { k: 'poster', label: 'Afiche (vertical)', type: 'media', full: true },
      { k: 'video', label: 'Video (YouTube, Vimeo, Instagram o archivo .mp4)', type: 'url', full: true, help: 'Se carga solo cuando el cliente toca "Ver video".' },
      { k: 'description', label: 'Descripción', type: 'textarea' },
      { type: 'section', label: 'Precio y condiciones' },
      { k: 'price', label: 'Valor por persona (CLP)', type: 'number', attrs: { min: 0, step: 1 } },
      { k: 'payment_mode', label: 'El pago es', type: 'select', options: [['entrada', 'Entrada / cover'], ['abono', 'Abono consumible']] },
      { k: 'min_consumption', label: 'Consumo mínimo por persona (opcional)', type: 'number', attrs: { min: 0, step: 1 } },
      { k: 'max_guests', label: 'Máximo de personas por reserva (0 = general)', type: 'number', attrs: { min: 0, max: 60 } },
      { k: 'tolerance_min', label: 'Tolerancia de llegada (min)', type: 'number', attrs: { min: 0 } },
      { k: 'release_min', label: 'Liberar mesa tras (min, informativo)', type: 'number', attrs: { min: 0 } },
      { k: 'conditions', label: 'Condiciones visibles (edad, dress code, etc.)', type: 'textarea', rows: 3 },
      { k: 'terms', label: 'Texto que el cliente debe aceptar', type: 'textarea', rows: 3 },
      { type: 'section', label: 'Visibilidad' },
      { k: 'featured', label: 'Destacar en la portada', type: 'check' },
      { k: 'sold_out', label: 'Marcar como agotado manualmente', type: 'check' }
    ], Object.assign({ price: '', payment_mode: 'entrada' }, e));
    // Publishing goes through the status action so the server can require a poster and a future date.
    var save = function (opts, confirmImpact) {
      opts = opts || {};
      var v = f.values();
      v.arrival_slots = v.arrival_raw; delete v.arrival_raw;
      if (isNew) v.status = 'draft';
      if (confirmImpact) v.confirm_impact = 1;
      api('POST', '/panel/events' + (isNew ? '' : '/' + e.id), v).then(function (r) {
        if (r.code === 'km_impact') {
          U.confirm(r.message, 'Mover reservas y avisar').then(function (ok) { if (ok) save(opts, true); });
          return;
        }
        if (!r.ok) return fail(r);
        cache.events = null;
        var finish = function () { d.close(); route(); };
        if (!opts.publish) { toast(isNew ? 'Evento guardado como borrador.' : 'Cambios guardados.', 'ok'); return finish(); }
        api('POST', '/panel/events/' + r.event.id + '/action', { action: 'publish' }).then(function (p) {
          if (!p.ok) { fail(p); if (isNew) { isNew = false; e = r.event; } return; }
          toast('Evento publicado en la cartelera.', 'ok');
          finish();
        });
      });
    };
    var acts = [h('button', { class: 'kp-btn ghost', text: 'Cancelar', onclick: function () { d.close(); } })];
    acts.push(h('button', { class: 'kp-btn ghost', text: isNew || e.status === 'draft' ? 'Guardar borrador' : 'Guardar', onclick: function () { save(); } }));
    if (!isNew) acts.push(h('a', { class: 'kp-btn ghost', href: e.url + '?km_preview=1', target: '_blank', rel: 'noopener', text: 'Vista previa' }));
    if (isNew || e.status !== 'published') acts.push(h('button', { class: 'kp-btn', text: 'Guardar y publicar', onclick: function () {
      if (!f.refs.poster.value) { toast('Agrega un afiche antes de publicar.', 'err'); return; }
      save({ publish: true });
    } }));
    var d = U.drawer(isNew ? 'Nuevo evento' : 'Editar: ' + e.name, f.el, acts);
  }

  // =========================================================== Bookings
  function viewReservas(params) {
    top('Reservas', [
      h('button', { class: 'kp-btn', text: '+ Reserva manual', onclick: function () { manualBooking(); } }),
      h('button', { class: 'kp-btn ghost', text: '+ Sin reserva (walk-in)', onclick: function () { manualBooking({ walkin: 1 }); } })
    ]);
    var F = {
      q: h('input', { type: 'search', placeholder: 'Nombre, correo, teléfono o código', style: 'min-width:240px' }),
      date: h('input', { type: 'date', 'aria-label': 'Fecha' }),
      event_id: h('select', { 'aria-label': 'Evento' }, [h('option', { value: '', text: 'Todos los eventos' })]),
      sector: h('select', { 'aria-label': 'Sector' }, [h('option', { value: '', text: 'Todos los sectores' })].concat(K.sectors.map(function (s) { return h('option', { value: s, text: s }); }))),
      status: h('select', { 'aria-label': 'Estado' }, [['', 'Todas (sin vencidas)'], ['active', 'Activas'], ['confirmed', 'Confirmadas'], ['pending_payment', 'Pago en curso'], ['conflict', 'Conflicto'], ['cancelled', 'Canceladas'], ['expired', 'No pagadas (vencidas)']].map(function (o) { return h('option', { value: o[0], text: o[1] }); })),
      payment_status: h('select', { 'aria-label': 'Pago' }, [h('option', { value: '', text: 'Cualquier pago' })].concat(Object.keys(U.LABELS.payment).map(function (k) { return h('option', { value: k, text: U.LABELS.payment[k][0] }); })))
    };
    Object.keys(params || {}).forEach(function (k) { if (F[k]) F[k].value = params[k]; });
    var exportBtn = h('a', { class: 'kp-btn ghost', text: 'Exportar CSV' });
    main.appendChild(h('div', { class: 'kp-filters' }, [F.q, F.date, F.event_id, F.sector, F.status, F.payment_status, exportBtn]));
    var box = h('div');
    main.appendChild(box);
    loadEvents().then(function (evs) {
      evs.forEach(function (e) { F.event_id.appendChild(h('option', { value: e.id, text: e.date + ' · ' + e.name })); });
      if (params && params.event_id) F.event_id.value = params.event_id;
    });
    var timer;
    function load() {
      var qs = Object.keys(F).filter(function (k) { return F[k].value; }).map(function (k) { return k + '=' + encodeURIComponent(F[k].value); }).join('&');
      exportBtn.href = K.exportUrl + (qs ? '&' + qs : '');
      box.innerHTML = '<p class="kp-loading">Cargando…</p>';
      api('GET', '/panel/bookings' + (qs ? '?' + qs : '')).then(function (r) {
        box.innerHTML = '';
        if (!r.ok) return fail(r);
        if (!r.bookings.length) { box.appendChild(h('div', { class: 'kp-empty', text: 'Sin reservas para estos filtros.' })); return; }
        var guests = 0, arrived = 0;
        var tb = h('tbody');
        r.bookings.forEach(function (b) {
          if (b.status === 'confirmed') { guests += b.guests; arrived += b.arrived; }
          tb.appendChild(h('tr', { class: 'click', tabindex: 0, onclick: function () { bookingDetail(b.id, load); }, onkeydown: function (e) { if (e.key === 'Enter') bookingDetail(b.id, load); } }, [
            td('Código', [h('strong', { text: b.code }), h('div', { class: 'muted', text: U.LABELS.source[b.source] || b.source })]),
            td('Fecha', [b.date_human, h('div', { class: 'muted', text: b.time ? b.time + ' h' : '' })]),
            td('Titular', [b.name, h('div', { class: 'muted', text: b.phone_display })]),
            td('Evento / sector', b.is_event ? b.event_name : b.sector),
            td('Ubicación', b.place || '—'),
            td('Pers.', b.guests + (b.arrived ? ' (' + b.arrived + ' ✓)' : '')),
            td('Estado', [U.badge('status', b.status), ' ', U.badge('payment', b.payment_status)]),
            td('Total', U.money(b.total))
          ]));
        });
        box.appendChild(h('p', { class: 'muted', text: r.bookings.length + ' reserva(s) · ' + guests + ' personas confirmadas · ' + arrived + ' ingresaron' }));
        box.appendChild(h('table', { class: 'kp-table' }, [h('thead', {}, h('tr', {}, ['Código', 'Fecha', 'Titular', 'Evento / sector', 'Ubicación', 'Pers.', 'Estado', 'Total'].map(function (t) { return h('th', { text: t }); }))), tb]));
      });
    }
    Object.keys(F).forEach(function (k) { F[k].addEventListener(k === 'q' ? 'input' : 'change', function () { clearTimeout(timer); timer = setTimeout(load, k === 'q' ? 350 : 0); }); });
    load();
  }

  function bookingDetail(id, onChange) {
    api('GET', '/panel/bookings/' + id).then(function (r) {
      if (!r.ok) return fail(r);
      var b = r.booking;
      var content = h('div');
      var head = h('div', { class: 'kp-row', style: 'margin-bottom:10px' }, [U.badge('status', b.status), U.badge('payment', b.payment_status), h('span', { class: 'kp-badge mute', text: b.attendance })]);
      content.appendChild(head);
      if (!b.validity.ok && b.status === 'confirmed' && b.validity.code !== 'other_date') content.appendChild(h('p', { class: 'kp-badge warn', text: b.validity.message }));

      var f = U.form([
        { k: 'name', label: 'Titular' }, { k: 'phone', label: 'WhatsApp' }, { k: 'email', label: 'Correo', type: 'email' },
        { k: 'time', label: 'Hora de llegada' },
        { k: 'guests', label: 'Personas', type: 'number', attrs: { min: 1, max: 60 } },
        b.is_event ? { k: 'seats', label: 'Puestos', help: SEAT_HELP } : { k: 'sector', label: 'Sector', type: 'select', options: [['', '—']].concat(K.sectors.map(function (s) { return [s, s]; })) },
        b.is_event ? null : { k: 'date', label: 'Fecha', type: 'date' },
        { k: 'payment_status', label: 'Estado de pago', type: 'select', options: [[b.payment_status, U.LABELS.payment[b.payment_status] ? U.LABELS.payment[b.payment_status][0] : b.payment_status], ['paid', 'Pagado'], ['onsite', 'Paga en local'], ['courtesy', 'Cortesía'], ['pending', 'Pendiente'], ['refunded', 'Devuelto (fuera de línea)']] },
        { k: 'notes', label: 'Notas internas', type: 'textarea', rows: 2 },
        { k: 'notify', label: 'Avisar al cliente si cambian puestos, fecha u hora', type: 'check', def: 1 },
        { k: 'override_rules', label: 'Omitir reglas de optimización (nunca permite doble venta)', type: 'check' }
      ].filter(Boolean), Object.assign({}, b, { seats: (b.seats || []).join(', '), phone: b.phone ? '+' + b.phone : '' }));
      if (b.is_event) {
        var pick = h('button', { class: 'kp-btn ghost sm', type: 'button', text: 'Elegir en el plano', onclick: function () {
          pickSeats(b.date, parseInt(f.refs.guests.value, 10), seatList(f.refs.seats.value), b.id, function (ids) { f.refs.seats.value = ids.join(', '); });
        } });
        f.refs.seats.parentNode.appendChild(pick);
      }
      content.appendChild(h('div', { class: 'kp-card' }, [h('h3', { text: 'Datos de la reserva' }), f.el]));

      var info = h('dl', { class: 'kp-dl' });
      [['Código', b.code], ['Fecha', b.date_human + (b.time ? ' · ' + b.time + ' h' : '')], ['Evento / sector', b.is_event ? b.event_name : b.sector],
        ['Origen', U.LABELS.source[b.source] || b.source], ['Total', U.money(b.total) + (b.promos_total ? ' (promos ' + U.money(b.promos_total) + ')' : '')],
        ['Pagado', U.money(b.paid)], ['Modalidad', b.payment_mode === 'abono' ? 'Abono consumible' : b.payment_mode === 'entrada' ? 'Entrada' : b.payment_mode || '—'],
        ['Consumo mínimo', b.min_consumption ? U.money(b.min_consumption) + ' p/p' : '—'], ['Pedido', b.order_id ? '#' + b.order_id : '—']].forEach(function (p) {
        info.appendChild(h('dt', { text: p[0] })); info.appendChild(h('dd', { text: p[1] }));
      });
      var links = h('div', { class: 'kp-row', style: 'margin-top:10px' }, [
        b.voucher_url ? h('a', { class: 'kp-btn sm ghost', href: b.voucher_url, target: '_blank', rel: 'noopener', text: 'Ver comprobante y QR' }) : null,
        b.order_url ? h('a', { class: 'kp-btn sm ghost', href: b.order_url, target: '_blank', rel: 'noopener', text: 'Ver pedido WooCommerce' }) : null,
        b.status === 'confirmed' ? h('button', { class: 'kp-btn sm ghost', text: 'Reenviar correo', onclick: function () { resend('email'); } }) : null,
        b.status === 'confirmed' ? h('button', { class: 'kp-btn sm ghost', text: 'Reenviar WhatsApp', onclick: function () { resend('whatsapp'); } }) : null
      ]);
      content.appendChild(h('div', { class: 'kp-card' }, [h('h3', { text: 'Resumen y pago' }), info, links]));

      if (b.promos.length) {
        var pl = h('div');
        b.promos.forEach(function (p) { pl.appendChild(h('p', {}, [h('strong', { text: p.qty + '× ' + p.name }), ' · canjeadas ' + p.redeemed + ' · ' + U.money(p.unit) + ' c/u'])); });
        content.appendChild(h('div', { class: 'kp-card' }, [h('h3', { text: 'Promociones' }), pl]));
      }
      var ck = h('div', { class: 'kp-log' });
      b.checkins.forEach(function (c) { ck.appendChild(h('div', { text: c.time + ' · ' + (c.qty > 0 ? '+' : '') + c.qty + ' · ' + c.user_name + (c.note ? ' · ' + c.note : '') })); });
      if (!b.checkins.length) ck.appendChild(h('div', { text: 'Sin ingresos registrados.' }));
      content.appendChild(h('div', { class: 'kp-card' }, [h('h3', { text: 'Ingresos (' + b.arrived + ' / ' + b.guests + ')' }), ck]));

      var ml = h('div', { class: 'kp-log' });
      b.messages.forEach(function (m) {
        ml.appendChild(h('div', {}, [m.created_at + ' · ' + m.channel + ' · ' + m.type + ' · ', U.badge('msg', m.status), m.error ? ' ' + m.error : '',
          m.status === 'failed' ? h('button', { class: 'kp-btn sm ghost', style: 'margin-left:6px', text: 'Reintentar', onclick: function () { api('POST', '/panel/messages/' + m.id + '/retry').then(function (x) { if (!x.ok) return fail(x); toast('Reenviado.', 'ok'); d.close(); bookingDetail(id, onChange); }); } }) : null]));
      });
      if (!b.messages.length) ml.appendChild(h('div', { text: 'Sin mensajes enviados.' }));
      content.appendChild(h('div', { class: 'kp-card' }, [h('h3', { text: 'Mensajes' }), ml]));

      var hl = h('div', { class: 'kp-log' });
      b.history.forEach(function (x) { hl.appendChild(h('div', { text: x.created_at + ' · ' + (x.user_name || 'sistema') + ' · ' + x.action + (x.details ? ' · ' + x.details : '') })); });
      content.appendChild(h('div', { class: 'kp-card' }, [h('h3', { text: 'Historial (auditoría)' }), hl]));

      function save(extra) {
        var v = Object.assign(f.values(), extra || {});
        if (v.phone === (b.phone ? '+' + b.phone : '')) delete v.phone;
        api('POST', '/panel/bookings/' + id, v).then(function (x) {
          if (!x.ok) return fail(x);
          toast('Reserva actualizada.', 'ok');
          d.close();
          if (onChange) onChange();
        });
      }
      function resend(ch) {
        api('POST', '/panel/bookings/' + id + '/resend', { channel: ch }).then(function (x) { if (!x.ok) return fail(x); toast(x.sent ? 'Enviado.' : 'No se pudo enviar; revisa "Mensajes".', x.sent ? 'ok' : 'err'); });
      }
      var acts = [];
      if (b.status !== 'cancelled' && b.status !== 'expired') acts.push(h('button', { class: 'kp-btn ghost', text: 'Cancelar reserva', onclick: function () {
        U.prompt('Cancelar la reserva ' + b.code + ' de ' + b.name + '. ' + (b.payment_status === 'paid' ? 'Está PAGADA: la devolución se hace en la pasarela.' : ''), 'Motivo (se envía al cliente)').then(function (reason) { if (reason !== null) save({ status: 'cancelled', reason: reason }); });
      } }));
      if (b.status === 'conflict' || b.status === 'cancelled') acts.push(h('button', { class: 'kp-btn ghost', text: 'Confirmar reserva', onclick: function () { save({ status: 'confirmed' }); } }));
      acts.push(h('button', { class: 'kp-btn', text: 'Guardar cambios', onclick: function () { save(); } }));
      var d = U.drawer('Reserva ' + b.code + ' · ' + b.name, content, acts);
    });
  }

  var SEAT_HELP = 'Puesto = mesa-silla (12-1, 12-2). Escribe solo el número de la mesa (12) para tomarla completa.';
  function seatSort(a, b) { var x = a.split('-'), y = b.split('-'); return (x[0] - y[0]) || (x[1] - y[1]); }
  /** "12-1, 12-2, 13" → ["12-1", "12-2", "13-1", …] using the plan capacities. */
  function seatList(text) {
    var caps = {};
    K.tables.forEach(function (t) { caps[t.id] = t.c; });
    var out = [];
    String(text || '').split(/[\s,;]+/).forEach(function (tok) {
      var m = tok.match(/^(\d+)(?:-(\d+))?$/);
      if (!m || !caps[m[1]]) return;
      if (m[2]) { if (+m[2] >= 1 && +m[2] <= caps[m[1]]) out.push(+m[1] + '-' + +m[2]); return; }
      for (var i = 1; i <= caps[m[1]]; i++) out.push(+m[1] + '-' + i);
    });
    return out.filter(function (s, i) { return out.indexOf(s) === i; }).sort(seatSort);
  }
  /** Occupancy without the seats of the booking being edited. */
  function occupancyWithout(occupancy, exclude) {
    var out = {};
    Object.keys(occupancy).forEach(function (k) {
      var o = occupancy[k], seats = {}, states = {}, taken = 0;
      Object.keys(o.seats || {}).forEach(function (n) { if (o.seats[n] !== exclude) { seats[n] = o.seats[n]; states[n] = o.seat_states[n]; taken++; } });
      if (!taken) return;
      out[k] = Object.assign({}, o, { seats: seats, seat_states: states, taken: taken, full: taken >= o.capacity, who: (o.who || []).filter(function (w) { return w.booking !== exclude; }) });
      if (out[k].who.length) out[k].state = out[k].who[0].state;
    });
    return out;
  }

  function pickSeats(date, guests, selected, exclude, cb) {
    api('GET', '/panel/floor?date=' + date).then(function (r) {
      if (!r.ok) return fail(r);
      var seatMode = r.unit === 'seat';
      var occ = occupancyWithout(r.occupancy, exclude);
      var byId = {};
      r.plan.forEach(function (t) { byId[t.id] = t; });
      var sel = selected.slice();
      var count = h('strong');
      function isTaken(sid) { var p = sid.split('-'), o = occ[p[0]]; return !!(o && o.seats[p[1]]); }
      function tableSeats(tid) { var a = []; for (var i = 1; i <= byId[tid].c; i++) a.push(tid + '-' + i); return a; }
      function onTable(tid) { return sel.filter(function (s) { return +s.split('-')[0] === tid; }); }
      var fl = U.floor({ plan: r.plan, occupancy: occ, blocked: r.blocked, selected: sel, onClick: function (tid, sid) {
        if (!byId[tid] || !byId[tid].active || r.blocked.indexOf(tid) > -1) { toast('Mesa ' + tid + ' bloqueada o inactiva.', 'err'); return; }
        var mine = onTable(tid);
        if (!seatMode) {
          if (mine.length) sel = sel.filter(function (s) { return mine.indexOf(s) === -1; });
          else if (occ[tid]) { toast('La mesa ' + tid + ' ya tiene puestos vendidos; en modo "mesa completa" no se puede compartir.', 'err'); return; }
          else sel = sel.concat(tableSeats(tid));
        } else if (sid) {
          if (isTaken(sid)) { toast('El puesto ' + sid + ' ya está reservado.', 'err'); return; }
          var i = sel.indexOf(sid);
          if (i > -1) sel.splice(i, 1); else sel.push(sid);
        } else if (mine.length) {
          sel = sel.filter(function (s) { return mine.indexOf(s) === -1; });
        } else {
          var free = tableSeats(tid).filter(function (s) { return !isTaken(s); });
          var need = guests - sel.length;
          if (!free.length) { toast('La mesa ' + tid + ' está completa.', 'err'); return; }
          if (need <= 0) { toast('Ya hay ' + sel.length + ' puestos para ' + guests + ' personas. Suelta alguno o toca cada silla.', 'err'); return; }
          sel = sel.concat(free.slice(0, need));
        }
        sel.sort(seatSort);
        fl.opts.selected = sel; fl.render(); upd();
      } });
      function upd() {
        var diff = sel.length - guests;
        count.textContent = 'Puestos: ' + (sel.join(', ') || '—') + ' · ' + sel.length + ' para ' + guests + ' personas' + (diff ? (diff > 0 ? ' (sobran ' + diff + ')' : ' (faltan ' + (-diff) + ')') : ' ✓');
      }
      upd();
      var sug = h('button', { class: 'kp-btn ghost', text: 'Sugerir', onclick: function () {
        api('GET', '/panel/floor/suggest?date=' + date + '&guests=' + guests + '&exclude=' + (exclude || 0)).then(function (s) {
          if (!s.best || !s.best.length) { toast('Sin lugares juntos libres para ' + guests + ' personas.', 'err'); return; }
          sel = s.best.slice().sort(seatSort); fl.opts.selected = sel; fl.opts.recommended = s.recommended; fl.render(); upd();
        });
      } });
      var help = h('p', { class: 'muted', text: seatMode ? 'Toca una silla para elegirla o soltarla. Tocar una mesa toma sus puestos libres que falten; tocarla de nuevo los suelta.' : 'Modo "mesa completa": cada toque elige o suelta la mesa entera.' });
      var d = U.drawer('Elegir puestos – ' + date, h('div', {}, [help, U.legend(), count, fl.el]), [
        sug, h('button', { class: 'kp-btn', text: 'Usar estos puestos', onclick: function () { cb(sel.slice().sort(seatSort)); d.close(); } })
      ]);
    });
  }

  function manualBooking(preset) {
    preset = preset || {};
    loadEvents().then(function (evs) {
      var upcoming = evs.filter(function (e) { return !e.is_past && (e.status === 'published' || e.status === 'hidden'); });
      var f = U.form([
        { k: 'event_id', label: 'Tipo', type: 'select', full: true, options: [['', 'Reserva sin espectáculo (convencional)']].concat(upcoming.map(function (e) { return [e.id, 'Evento: ' + e.date + ' · ' + e.name]; })) },
        { k: 'date', label: 'Fecha (convencional)', type: 'date', def: K.today },
        { k: 'time', label: 'Hora', type: 'time' },
        { k: 'sector', label: 'Sector (convencional)', type: 'select', options: [['', '—']].concat(K.sectors.map(function (s) { return [s, s]; })) },
        { k: 'guests', label: 'Personas', type: 'number', def: 2, attrs: { min: 1, max: 60 } },
        { k: 'seats', label: 'Puestos', help: 'Obligatorio en eventos. Usa "Elegir en el plano". ' + SEAT_HELP },
        { k: 'name', label: 'Titular' + (preset.walkin ? ' (opcional)' : '') },
        { k: 'phone', label: 'WhatsApp', type: 'tel' },
        { k: 'email', label: 'Correo', type: 'email' },
        { k: 'payment_status', label: 'Pago', type: 'select', options: [['onsite', 'Paga en el local'], ['paid', 'Ya pagó (transferencia, etc.)'], ['courtesy', 'Cortesía'], ['pending', 'Pendiente']] },
        { k: 'amount', label: 'Monto (vacío = precio del evento)', type: 'number', attrs: { min: 0 } },
        { k: 'notes', label: 'Notas', type: 'textarea', rows: 2 },
        { k: 'notify', label: 'Enviar confirmación y QR al cliente', type: 'check', def: preset.walkin ? 0 : 1 },
        { k: 'override_rules', label: 'Omitir reglas de optimización (nunca permite doble venta)', type: 'check' }
      ], preset);
      var pick = h('button', { class: 'kp-btn ghost sm', type: 'button', text: 'Elegir en el plano', onclick: function () {
        var ev = upcoming.filter(function (e) { return String(e.id) === f.refs.event_id.value; })[0];
        var date = ev ? ev.date : f.refs.date.value;
        if (!date) { toast('Elige la fecha o el evento primero.', 'err'); return; }
        pickSeats(date, parseInt(f.refs.guests.value, 10) || 1, seatList(f.refs.seats.value), 0, function (ids) { f.refs.seats.value = ids.join(', '); });
      } });
      f.refs.seats.parentNode.appendChild(pick);
      var d = U.drawer(preset.walkin ? 'Cliente sin reserva (walk-in)' : 'Nueva reserva manual', f.el, [
        h('button', { class: 'kp-btn ghost', text: 'Cancelar', onclick: function () { d.close(); } }),
        h('button', { class: 'kp-btn', text: preset.walkin ? 'Registrar ingreso' : 'Crear reserva', onclick: function () {
          var v = f.values();
          if (!v.seats.trim()) delete v.seats;
          if (preset.walkin) v.walkin = 1;
          api('POST', '/panel/bookings', v).then(function (r) {
            if (!r.ok) return fail(r);
            toast('Reserva ' + r.booking.code + ' creada.', 'ok');
            d.close();
            route();
          });
        } })
      ]);
    });
  }

  // =========================================================== Floor
  function viewPlano(params) {
    top('Plano y mesas', []);
    var date = h('input', { type: 'date', value: (params && params.date) || K.today, 'aria-label': 'Fecha' });
    var blockMode = h('input', { type: 'checkbox', id: 'kp-block' });
    var printBtn = h('a', { class: 'kp-btn ghost', target: '_blank', rel: 'noopener', text: 'Imprimir plano' });
    main.appendChild(h('div', { class: 'kp-filters' }, [date, h('label', { class: 'kp-btn ghost', for: 'kp-block' }, [blockMode, ' Modo bloquear mesas']), printBtn]));
    var info = h('div'), box = h('div');
    main.appendChild(info); main.appendChild(U.legend()); main.appendChild(box);
    function load() {
      printBtn.href = K.printUrl + '&date=' + date.value;
      api('GET', '/panel/floor?date=' + date.value).then(function (r) {
        if (!r.ok) return fail(r);
        info.innerHTML = '';
        var s = r.stats;
        info.appendChild(h('p', {}, [h('strong', { text: r.events.length ? r.events.map(function (e) { return e.name + ' (show ' + e.show_time + ')'; }).join(' · ') : 'Sin evento esta fecha' }),
          ' · ' + s.bookings + ' reservas · ' + s.guests + ' personas · ' + s.arrived + ' ingresaron · ' +
          (r.unit === 'seat' ? s.free_seats + '/' + s.total_seats + ' puestos libres · ' : '') + s.free_tables + '/' + s.total_tables + ' mesas sin reservas']));
        var blocked = r.blocked.slice();
        box.innerHTML = '';
        var fl = U.floor({ plan: r.plan, occupancy: r.occupancy, blocked: blocked, onClick: function (tid, sid) {
          var o = r.occupancy[tid];
          if (blockMode.checked) {
            if (o) { toast('La mesa ' + tid + ' tiene puestos reservados; no se puede bloquear.', 'err'); return; }
            var i = blocked.indexOf(tid);
            if (i > -1) blocked.splice(i, 1); else blocked.push(tid);
            api('POST', '/panel/floor/blocked', { date: date.value, ids: blocked }).then(function (x) {
              if (!x.ok) return fail(x);
              fl.opts.blocked = x.blocked; blocked = x.blocked.slice(); fl.render();
              toast('Mesa ' + tid + (blocked.indexOf(tid) > -1 ? ' bloqueada para la venta.' : ' disponible nuevamente.'), 'ok');
            });
            return;
          }
          var seat = sid ? U.seatInfo(o, sid.split('-')[1]) : null;
          if (seat) return bookingDetail(seat.booking, load);
          if (o && o.who.length === 1) return bookingDetail(o.who[0].booking, load);
          if (o) {
            var list = h('div', {}, o.who.map(function (w) {
              var mine = Object.keys(o.seats).filter(function (n) { return o.seats[n] === w.booking; }).map(function (n) { return tid + '-' + n; });
              return h('button', { class: 'kp-btn ghost', style: 'display:block;width:100%;text-align:left;margin-bottom:8px', onclick: function () { dd.close(); bookingDetail(w.booking, load); } },
                [h('strong', { text: w.name }), ' · ' + w.guests + ' p. · puestos ' + mine.join(', ') + (w.arrived ? ' · ' + w.arrived + ' ✓' : '')]);
            }));
            var dd = U.drawer('Mesa ' + tid + ' · ' + o.taken + '/' + o.capacity + ' puestos ocupados', list, []);
            return;
          }
          toast('Mesa ' + tid + ' libre. Activa "Modo bloquear mesas" para sacarla de la venta.');
        } });
        box.appendChild(fl.el);
      });
    }
    date.addEventListener('change', load);
    load();
  }

  // =========================================================== Promotions
  function viewPromociones() {
    top('Promociones', [h('button', { class: 'kp-btn', text: '+ Nueva promoción', onclick: function () { editPromo(null); } })]);
    var box = h('div');
    main.appendChild(h('p', { class: 'muted', text: 'Se ofrecen opcionalmente al reservar, se pagan junto con la reserva y en recepción se canjean por cantidad.' }));
    main.appendChild(box);
    api('GET', '/panel/promos').then(function (r) {
      if (!r.ok) return fail(r);
      if (!r.promos.length) { box.appendChild(h('div', { class: 'kp-empty', text: 'Aún no hay promociones.' })); return; }
      var tb = h('tbody');
      r.promos.forEach(function (p) {
        tb.appendChild(h('tr', { class: 'click', onclick: function () { editPromo(p); } }, [
          td('Promoción', [h('strong', { text: p.name }), h('div', { class: 'muted', text: p.description })]),
          td('Precio', U.money(p.price)),
          td('Estado', h('span', { class: 'kp-badge ' + (p.status === 'active' ? 'ok' : 'mute'), text: p.status === 'active' ? 'Activa' : 'Pausada' })),
          td('Vendidas', String(p.sold) + (p.stock !== '' ? ' / ' + p.stock : '')),
          td('Canjeadas', String(p.redeemed)),
          td('Vigencia', (p.valid_from || '—') + ' → ' + (p.valid_to || '—'))
        ]));
      });
      box.appendChild(h('table', { class: 'kp-table' }, [h('thead', {}, h('tr', {}, ['Promoción', 'Precio', 'Estado', 'Vendidas', 'Canjeadas', 'Vigencia'].map(function (t) { return h('th', { text: t }); }))), tb]));
    });
  }

  function editPromo(p) {
    var isNew = !p;
    loadEvents().then(function (evs) {
      var f = U.form([
        { k: 'name', label: 'Nombre', full: true },
        { k: 'description', label: 'Descripción', type: 'textarea', rows: 2 },
        { k: 'conditions', label: 'Condiciones', type: 'textarea', rows: 2 },
        { k: 'image', label: 'Imagen', type: 'media', full: true },
        { k: 'price', label: 'Precio promoción (CLP)', type: 'number', attrs: { min: 0 } },
        { k: 'regular_price', label: 'Precio normal (opcional, se muestra tachado)', type: 'number', attrs: { min: 0 } },
        { k: 'stock', label: 'Cupos totales (vacío = sin límite)', type: 'number', attrs: { min: 0 } },
        { k: 'max_per_booking', label: 'Máximo por reserva', type: 'number', def: 10, attrs: { min: 1, max: 50 } },
        { k: 'valid_from', label: 'Válida desde', type: 'date' },
        { k: 'valid_to', label: 'Válida hasta', type: 'date' },
        { k: 'event_ids', label: 'Solo para eventos (IDs separados por coma; vacío = todos)', full: true, help: evs.filter(function (e) { return !e.is_past; }).slice(0, 8).map(function (e) { return e.id + ' = ' + e.name; }).join(' · ') },
        { k: 'applies_conventional', label: 'También en reservas sin espectáculo', type: 'check' },
        { k: 'status', label: 'Estado', type: 'select', options: [['active', 'Activa'], ['paused', 'Pausada']] }
      ], p || {});
      var acts = [h('button', { class: 'kp-btn ghost', text: 'Cancelar', onclick: function () { d.close(); } })];
      if (!isNew) acts.unshift(h('button', { class: 'kp-btn ghost', text: 'Eliminar', onclick: function () {
        U.confirm('¿Eliminar la promoción? Si ya se vendió, solo se oculta y se conserva el historial.', 'Eliminar').then(function (ok) { if (ok) api('DELETE', '/panel/promos/' + p.id).then(function (r) { if (!r.ok) return fail(r); d.close(); route(); }); });
      } }));
      acts.push(h('button', { class: 'kp-btn', text: 'Guardar', onclick: function () {
        api('POST', '/panel/promos' + (isNew ? '' : '/' + p.id), f.values()).then(function (r) { if (!r.ok) return fail(r); toast('Promoción guardada.', 'ok'); d.close(); route(); });
      } }));
      var d = U.drawer(isNew ? 'Nueva promoción' : p.name, f.el, acts);
    });
  }

  // =========================================================== Votes
  function viewVotos() {
    top('Quiero que vuelva', []);
    var box = h('div');
    main.appendChild(h('p', { class: 'muted', text: 'Un voto por contacto verificado y por evento. Solo se cuentan los votos verificados con código.' }));
    main.appendChild(box);
    api('GET', '/panel/votes').then(function (r) {
      if (!r.ok) return fail(r);
      if (!r.results.length) { box.appendChild(h('div', { class: 'kp-empty', text: 'Aún no hay votos.' })); return; }
      var tb = h('tbody');
      r.results.forEach(function (v) {
        tb.appendChild(h('tr', { class: 'click', onclick: function () { voters(v); } }, [
          td('Evento', h('strong', { text: v.event_name })), td('Votos verificados', String(v.verified)), td('Sin verificar / antiguos', String(v.unverified)), td('Aceptan aviso', String(v.consent))
        ]));
      });
      box.appendChild(h('table', { class: 'kp-table' }, [h('thead', {}, h('tr', {}, ['Evento', 'Votos verificados', 'Sin verificar / antiguos', 'Aceptan aviso'].map(function (t) { return h('th', { text: t }); }))), tb]));
    });
  }

  function voters(v) {
    if (!v.event_id) { toast('Votos antiguos sin evento asociado.', 'err'); return; }
    api('GET', '/panel/votes/' + v.event_id).then(function (r) {
      if (!r.ok) return fail(r);
      var tb = h('tbody');
      r.voters.forEach(function (x) {
        tb.appendChild(h('tr', {}, [td('Nombre', x.voter_name), td('Contacto', (x.voter_email || '') + ' ' + (x.voter_whatsapp || '')), td('Verificado', x.verified === '1' ? 'Sí' : 'No'), td('Acepta aviso', x.consent_notify === '1' ? 'Sí' : 'No'), td('Fecha', x.created_at)]));
      });
      var csv = h('button', { class: 'kp-btn ghost', text: 'Descargar CSV (solo quienes aceptan aviso)', onclick: function () {
        var rows = [['Nombre', 'Correo', 'WhatsApp']].concat(r.voters.filter(function (x) { return x.verified === '1' && x.consent_notify === '1'; }).map(function (x) { return [x.voter_name, x.voter_email, x.voter_whatsapp]; }));
        var blob = new Blob(['\ufeff' + rows.map(function (row) { return row.map(function (c) { return '"' + String(c || '').replace(/"/g, '""') + '"'; }).join(';'); }).join('\n')], { type: 'text/csv' });
        var a = h('a', { href: URL.createObjectURL(blob), download: 'votos-' + v.event_id + '.csv' }); document.body.appendChild(a); a.click(); a.remove();
      } });
      U.drawer('Votos: ' + v.event_name, h('table', { class: 'kp-table' }, [h('thead', {}, h('tr', {}, ['Nombre', 'Contacto', 'Verificado', 'Acepta aviso', 'Fecha'].map(function (t) { return h('th', { text: t }); }))), tb]), [csv]);
    });
  }

  // =========================================================== Messages and incidents
  function viewMensajes() {
    top('Mensajes enviados', []);
    var st = h('select', {}, [['', 'Todos'], ['error', 'Con error / reintentando'], ['sent', 'Enviados'], ['delivered', 'Entregados'], ['read', 'Leídos']].map(function (o) { return h('option', { value: o[0], text: o[1] }); }));
    main.appendChild(h('div', { class: 'kp-filters' }, [st]));
    var box = h('div');
    main.appendChild(box);
    function load() {
      api('GET', '/panel/messages' + (st.value ? '?status=' + st.value : '')).then(function (r) {
        box.innerHTML = '';
        if (!r.ok) return fail(r);
        if (!r.wa_ready) box.appendChild(h('p', { class: 'kp-badge warn', text: 'WhatsApp aún no está configurado: solo se envían correos.' }));
        if (!r.messages.length) { box.appendChild(h('div', { class: 'kp-empty', text: 'Sin mensajes.' })); return; }
        var tb = h('tbody');
        r.messages.forEach(function (m) {
          tb.appendChild(h('tr', {}, [
            td('Fecha', m.created_at), td('Reserva', h('a', { href: '#', text: (m.reservation_code || '#' + m.booking_id) + ' ' + (m.client_name || ''), onclick: function (e) { e.preventDefault(); bookingDetail(m.booking_id, load); } })),
            td('Canal', m.channel), td('Tipo', m.type), td('Estado', [U.badge('msg', m.status), m.error ? h('div', { class: 'muted', text: m.error }) : null]),
            td('', m.status === 'failed' || m.status === 'retry' ? h('button', { class: 'kp-btn sm ghost', text: 'Reintentar', onclick: function () { api('POST', '/panel/messages/' + m.id + '/retry').then(function (x) { if (!x.ok) return fail(x); toast('Reenviado.', 'ok'); load(); }); } }) : '')
          ]));
        });
        box.appendChild(h('table', { class: 'kp-table' }, [h('thead', {}, h('tr', {}, ['Fecha', 'Reserva', 'Canal', 'Tipo', 'Estado', ''].map(function (t) { return h('th', { text: t }); }))), tb]));
      });
    }
    st.addEventListener('change', load);
    load();
  }

  function viewIncidencias() {
    top('Incidencias', []);
    main.appendChild(h('p', { class: 'muted', text: 'Pagos tardíos con mesas ya vendidas, montos que no coinciden, devoluciones pendientes y errores que requieren revisión manual.' }));
    var box = h('div');
    main.appendChild(box);
    function load() {
      api('GET', '/panel/incidents').then(function (r) {
        box.innerHTML = '';
        if (!r.ok) return fail(r);
        if (!r.incidents.length) { box.appendChild(h('div', { class: 'kp-empty', text: 'Sin incidencias. 👍' })); return; }
        var tb = h('tbody');
        r.incidents.forEach(function (i) {
          tb.appendChild(h('tr', {}, [
            td('Fecha', i.created_at), td('Tipo', h('span', { class: 'kp-badge ' + (i.resolved === '1' ? 'mute' : 'bad'), text: i.type })),
            td('Detalle', [i.message, i.resolved === '1' ? h('div', { class: 'muted', text: 'Resuelta por ' + i.resolved_by }) : null]),
            td('Reserva', i.booking_id !== '0' ? h('a', { href: '#', text: 'Ver #' + i.booking_id, onclick: function (e) { e.preventDefault(); bookingDetail(parseInt(i.booking_id, 10), load); } }) : '—'),
            td('', i.resolved === '1' ? '' : h('button', { class: 'kp-btn sm', text: 'Marcar resuelta', onclick: function () {
              U.prompt('¿Qué se hizo para resolverla?', 'Nota').then(function (note) { if (note === null) return; api('POST', '/panel/incidents/' + i.id + '/resolve', { note: note }).then(function (x) { if (!x.ok) return fail(x); load(); }); });
            } }))
          ]));
        });
        box.appendChild(h('table', { class: 'kp-table' }, [h('thead', {}, h('tr', {}, ['Fecha', 'Tipo', 'Detalle', 'Reserva', ''].map(function (t) { return h('th', { text: t }); }))), tb]));
      });
    }
    load();
  }

  // =========================================================== Site content
  function viewContenido() {
    top('Contenido del sitio', []);
    api('GET', '/panel/content').then(function (r) {
      if (!r.ok) return fail(r);
      var data = r.draft || r.live;
      if (r.draft) main.appendChild(h('p', { class: 'kp-badge warn', text: 'Hay un borrador sin publicar. Lo que ves abajo es el borrador.' }));
      var palettes = Object.keys(r.palettes).map(function (k) { return [k, r.palettes[k].label]; });
      var accents = [];
      Object.keys(r.palettes).forEach(function (k) { r.palettes[k].accents.forEach(function (a) { accents.push([a, k + ' · ' + a]); }); });
      var f = U.form([
        { type: 'section', label: 'Marca y diseño' },
        { k: 'palette', label: 'Alternativa de diseño', type: 'select', options: palettes },
        { k: 'accent', label: 'Color de acento', type: 'select', options: accents, help: 'Colores validados con contraste suficiente para cada alternativa.' },
        { k: 'logo', label: 'Logo', type: 'media', full: true },
        { type: 'section', label: 'Portada' },
        { k: 'hero_title', label: 'Título principal', full: true },
        { k: 'hero_subtitle', label: 'Subtítulo', full: true },
        { k: 'hero_image', label: 'Imagen de fondo (opcional)', type: 'media', full: true },
        { k: 'conventional_cta', label: 'Texto del botón de reserva sin espectáculo', full: true },
        { type: 'section', label: 'El restaurante' },
        { k: 'about_title', label: 'Título' },
        { k: 'about_text', label: 'Texto', type: 'textarea' },
        { k: 'services_txt', label: 'Servicios (uno por línea: Título | descripción)', type: 'textarea', rows: 5 },
        { k: 'private_events_text', label: 'Eventos privados', type: 'textarea', rows: 2 },
        { k: 'gallery_txt', label: 'Galería (una URL de imagen por línea)', type: 'textarea', rows: 3 },
        { type: 'section', label: 'Información y contacto' },
        { k: 'carta_url', label: 'Enlace a la carta (Fudo)', type: 'url', full: true },
        { k: 'hours', label: 'Horarios', type: 'textarea', rows: 4 },
        { k: 'address', label: 'Dirección', full: true },
        { k: 'city', label: 'Ciudad (se muestra en la portada y en cada evento)' },
        { k: 'maps_url', label: 'Enlace de Google Maps ("Cómo llegar")', type: 'url', full: true },
        { k: 'instagram', label: 'Instagram (URL)', type: 'url' },
        { k: 'whatsapp_attention', label: 'WhatsApp de ATENCIÓN al cliente', help: 'Distinto del número que envía las notificaciones automáticas.' },
        { k: 'contact_email', label: 'Correo de contacto', type: 'email' },
        { k: 'contact_phone', label: 'Teléfono' },
        { k: 'footer_text', label: 'Texto del pie de página', type: 'textarea', rows: 2 },
        { k: 'seo_description', label: 'Descripción para Google y redes', type: 'textarea', rows: 2 }
      ], Object.assign({}, data, {
        services_txt: (data.services || []).map(function (s) { return s.title + ' | ' + s.text; }).join('\n'),
        gallery_txt: (data.gallery || []).join('\n')
      }));
      function payload() {
        var v = f.values();
        v.services = v.services_txt.split('\n').map(function (l) { var p = l.split('|'); return { title: (p[0] || '').trim(), text: p.slice(1).join('|').trim() }; }).filter(function (s) { return s.title; });
        v.gallery = v.gallery_txt.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
        delete v.services_txt; delete v.gallery_txt;
        return v;
      }
      function act(action, extra) {
        return api('POST', '/panel/content', Object.assign({ action: action }, extra || {})).then(function (x) { if (!x.ok) { fail(x); return null; } return x; });
      }
      main.appendChild(h('div', { class: 'kp-card' }, [f.el, h('div', { class: 'kp-actions' }, [
        r.draft ? h('button', { class: 'kp-btn ghost', text: 'Descartar borrador', onclick: function () { act('discard').then(function (x) { if (x) { toast('Borrador descartado.', 'ok'); route(); } }); } }) : null,
        h('button', { class: 'kp-btn ghost', text: 'Guardar borrador', onclick: function () { act('draft', { data: payload() }).then(function (x) { if (x) { toast('Borrador guardado (no visible al público).', 'ok'); route(); } }); } }),
        h('button', { class: 'kp-btn ghost', text: 'Vista previa', onclick: function () { var w = window.open('about:blank', '_blank'); act('draft', { data: payload() }).then(function (x) { if (x && w) w.location = r.preview_url; }); } }),
        h('button', { class: 'kp-btn', text: 'Publicar', onclick: function () { act('publish', { data: payload() }).then(function (x) { if (x) { toast('Cambios publicados.', 'ok'); route(); } }); } })
      ])]));
      var hist = h('div', { class: 'kp-log' });
      r.history.forEach(function (v, i) {
        hist.appendChild(h('div', { class: 'kp-row', style: 'justify-content:space-between' }, [v.saved_at + ' · ' + v.user, h('button', { class: 'kp-btn sm ghost', text: 'Restaurar esta versión', onclick: function () {
          U.confirm('¿Restaurar la versión publicada el ' + v.saved_at + '? La actual queda guardada en el historial.', 'Restaurar').then(function (ok) { if (ok) act('restore', { index: i }).then(function (x) { if (x) { toast('Versión restaurada.', 'ok'); route(); } }); });
        } })]));
      });
      if (!r.history.length) hist.appendChild(h('div', { text: 'Aún no hay versiones anteriores.' }));
      main.appendChild(h('div', { class: 'kp-card' }, [h('h3', { text: 'Versiones anteriores' }), hist]));
      var front = h('div', { class: 'kp-card' }, [h('h3', { text: 'Página de inicio' })]);
      if (r.is_front) front.appendChild(h('p', { text: 'La cartelera nueva ya es la página de inicio del sitio.' }));
      else front.appendChild(h('div', {}, [
        h('p', { text: 'La página de inicio actual sigue siendo la de Elementor. Revisa la nueva cartelera en vista previa y actívala cuando el restaurante lo apruebe. La página anterior no se borra.' }),
        h('div', { class: 'kp-row' }, [
          h('a', { class: 'kp-btn ghost', href: r.preview_url, target: '_blank', rel: 'noopener', text: 'Ver nueva portada' }),
          h('button', { class: 'kp-btn', text: 'Usar como página de inicio', onclick: function () {
            U.confirm('¿Cambiar la página de inicio del sitio por la nueva cartelera?', 'Activar').then(function (ok) { if (ok) act('set_front').then(function (x) { if (x) { toast('Nueva portada activada.', 'ok'); route(); } }); });
          } })
        ])
      ]));
      main.appendChild(front);
    });
  }

  // =========================================================== Settings (administrator)
  function viewConfiguracion() {
    top('Configuración', []);
    api('GET', '/panel/settings').then(function (r) {
      if (!r.ok) return fail(r);
      var s = r.settings;
      var f = U.form([
        { type: 'section', label: 'Reservas' },
        { k: 'product_id', label: 'Producto WooCommerce usado para cobrar', type: 'select', options: r.products.map(function (p) { return [p.id, '#' + p.id + ' ' + p.name]; }) },
        { k: 'price_default', label: 'Precio por persona por defecto (nuevos eventos)', type: 'number' },
        { k: 'hold_minutes', label: 'Minutos de retención mientras paga (5–60)', type: 'number' },
        { k: 'min_guests', label: 'Mínimo de personas', type: 'number' },
        { k: 'max_guests', label: 'Máximo de personas por reserva web', type: 'number' },
        { k: 'booking_unit', label: 'Qué se vende en los eventos', type: 'select', options: [['seat', 'Puestos individuales (varios grupos pueden compartir mesa)'], ['table', 'Mesas completas']],
          help: 'Con "puestos" el cliente elige sillas sueltas y paga solo las que usa. Las reservas ya hechas mantienen sus puestos al cambiar esta opción.' },
        { k: 'extra_chair', label: 'Sillas extra permitidas por combinación (solo mesas completas)', type: 'number' },
        { k: 'max_empty_seats', label: 'Puestos vacíos tolerados (solo mesas completas)', type: 'number' },
        { k: 'adjacency_distance', label: 'Distancia máxima entre mesas "juntas" (en el plano)', type: 'number', help: 'Solo se usa si no definiste vecinas en el editor de mesas.' },
        { k: 'arrival_slots_default', label: 'Horarios de llegada por defecto (eventos)' },
        { k: 'end_time_default', label: 'Hora de término por defecto', type: 'time' },
        { k: 'terms_default', label: 'Condiciones por defecto', type: 'textarea', rows: 2 },
        { type: 'section', label: 'Reservas sin espectáculo' },
        { k: 'open_time', label: 'Apertura', type: 'time' }, { k: 'close_time', label: 'Cierre', type: 'time' },
        { k: 'slot_minutes', label: 'Intervalo de horarios (min)', type: 'number' }, { k: 'conv_duration', label: 'Duración de la reserva (min)', type: 'number' },
        { k: 'conv_deposit_pp', label: 'Abono por persona (0 = sin pago)', type: 'number' },
        { k: 'conv_block_event_nights', label: 'Limitar horarios en noches de evento', type: 'check' },
        { k: 'sectors_txt', label: 'Sectores (uno por línea: Nombre | capacidad; 0 = sin límite)', type: 'textarea', rows: 3 },
        { type: 'section', label: 'Correo y recordatorios' },
        { k: 'email_enabled', label: 'Enviar correos de confirmación', type: 'check' },
        { k: 'alerts_email', label: 'Correo para alertas internas', type: 'email' },
        { k: 'reminder_2d_time', label: 'Hora del recordatorio 2 días antes', type: 'time' },
        { k: 'reminder_day_time', label: 'Hora del recordatorio el mismo día', type: 'time' },
        { k: 'vote_channel', label: 'Verificación de votos', type: 'select', options: [['email', 'Correo'], ['phone', 'WhatsApp'], ['both', 'El cliente elige']] },
        { type: 'section', label: 'WhatsApp Cloud API (número de NOTIFICACIONES)' },
        { k: 'wa_enabled', label: 'Activar WhatsApp', type: 'check' },
        { k: 'wa_phone_id', label: 'Phone number ID' },
        { k: 'wa_token', label: 'Token de acceso permanente', type: 'password', help: 'Se guarda en el servidor y nunca se muestra completo.' },
        { k: 'wa_app_secret', label: 'App secret (firma del webhook)', type: 'password' },
        { k: 'wa_api_version', label: 'Versión de la API' }, { k: 'wa_lang', label: 'Idioma de las plantillas' },
        { k: 'wa_notify_number', label: 'Número de notificaciones (referencia)' },
        { k: 'wa_tpl_confirm', label: 'Plantilla confirmación' }, { k: 'wa_tpl_reminder', label: 'Plantilla recordatorio' },
        { k: 'wa_tpl_cancel', label: 'Plantilla cancelación' }, { k: 'wa_tpl_change', label: 'Plantilla cambio' }, { k: 'wa_tpl_otp', label: 'Plantilla código de verificación' },
        { k: 'wa_webhook_verify', label: 'Token de verificación del webhook', help: 'URL del webhook: ' + r.webhook_url }
      ], Object.assign({}, s, { sectors_txt: (s.conv_sectors || []).map(function (x) { return x.name + ' | ' + x.capacity; }).join('\n') }));
      main.appendChild(h('div', { class: 'kp-card' }, [f.el, h('div', { class: 'kp-actions' }, [
        h('a', { class: 'kp-btn ghost', href: r.gateways_url, target: '_blank', rel: 'noopener', text: 'Pasarelas de pago' }),
        h('a', { class: 'kp-btn ghost', href: r.mail_url, target: '_blank', rel: 'noopener', text: 'Configurar SMTP' }),
        h('button', { class: 'kp-btn', text: 'Guardar configuración', onclick: function () {
          var v = f.values();
          v.conv_sectors = v.sectors_txt.split('\n').map(function (l) { var p = l.split('|'); return { name: (p[0] || '').trim(), capacity: parseInt(p[1], 10) || 0 }; }).filter(function (x) { return x.name; });
          delete v.sectors_txt;
          api('POST', '/panel/settings', v).then(function (x) { if (!x.ok) return fail(x); toast('Configuración guardada.', 'ok'); });
        } })
      ])]));
      var to = h('input', { type: 'text', placeholder: 'correo o +569…', style: 'min-height:42px;padding:6px 10px;border:1.5px solid #cfc7bf;border-radius:8px' });
      main.appendChild(h('div', { class: 'kp-card' }, [h('h3', { text: 'Pruebas de envío' }), h('div', { class: 'kp-row' }, [to,
        h('button', { class: 'kp-btn ghost', text: 'Probar correo', onclick: function () { api('POST', '/panel/settings/test', { type: 'email', to: to.value }).then(function (x) { if (!x.ok) return fail(x); toast('Correo enviado.', 'ok'); }); } }),
        h('button', { class: 'kp-btn ghost', text: 'Probar WhatsApp', onclick: function () { api('POST', '/panel/settings/test', { type: 'whatsapp', to: to.value }).then(function (x) { if (!x.ok) return fail(x); toast('WhatsApp enviado.', 'ok'); }); } })
      ])]));
      floorEditor();
    });
  }

  function floorEditor() {
    var card = h('div', { class: 'kp-card' }, [h('h3', { text: 'Mesas: capacidad, sector, estado y vecinas' }),
      h('p', { class: 'muted', text: 'Vecinas: números de mesas que se pueden juntar con esta. Si defines vecinas en alguna mesa, se usan en lugar de la distancia.' })]);
    var tb = h('tbody'), rows = [];
    K.tables.forEach(function (t) {
      var c = h('input', { type: 'number', min: 1, max: 12, value: t.c, style: 'width:70px' });
      var sec = h('select', {}, [h('option', { value: '', text: '—' })].concat(K.sectors.map(function (s) { var o = h('option', { value: s, text: s }); if (s === t.sector) o.selected = true; return o; })));
      var act = h('input', { type: 'checkbox', checked: !!t.active });
      var nb = h('input', { type: 'text', value: (t.neighbors || []).join(', '), style: 'width:140px' });
      rows.push({ id: t.id, c: c, sector: sec, active: act, neighbors: nb });
      tb.appendChild(h('tr', {}, [td('Mesa', h('strong', { text: t.id })), td('Puestos', c), td('Sector', sec), td('Activa', act), td('Vecinas', nb)]));
    });
    card.appendChild(h('div', { style: 'max-height:420px;overflow:auto' }, h('table', { class: 'kp-table' }, [h('thead', {}, h('tr', {}, ['Mesa', 'Puestos', 'Sector', 'Activa', 'Vecinas'].map(function (t) { return h('th', { text: t }); }))), tb])));
    card.appendChild(h('div', { class: 'kp-actions' }, [h('button', { class: 'kp-btn', text: 'Guardar mesas', onclick: function () {
      var data = rows.map(function (r) { return { id: r.id, c: r.c.value, sector: r.sector.value, active: r.active.checked ? 1 : 0, neighbors: r.neighbors.value }; });
      api('POST', '/panel/floor/plan', { tables: data }).then(function (x) { if (!x.ok) return fail(x); toast('Mesas guardadas. Recarga para ver el plano actualizado.', 'ok'); });
    } })]));
    main.appendChild(card);
  }

  // =========================================================== Router
  var VIEWS = { inicio: viewInicio, eventos: viewEventos, reservas: viewReservas, plano: viewPlano, promociones: viewPromociones, votos: viewVotos, mensajes: viewMensajes, incidencias: viewIncidencias, contenido: viewContenido, configuracion: viewConfiguracion };
  function route() {
    var hash = location.hash.replace(/^#/, '') || 'inicio';
    var parts = hash.split('?'), name = parts[0], params = {};
    (parts[1] || '').split('&').forEach(function (kv) { if (!kv) return; var p = kv.split('='); params[p[0]] = decodeURIComponent(p[1] || ''); });
    if (!VIEWS[name] || (name === 'configuracion' && !K.canConfigure)) name = 'inicio';
    Object.keys(sideLinks).forEach(function (k) { sideLinks[k].classList.toggle('is-active', k === name); });
    document.querySelectorAll('.kp-overlay').forEach(function (o) { o.remove(); });
    VIEWS[name](params);
    window.scrollTo(0, 0);
  }
  shell();
  window.addEventListener('hashchange', route);
  route();
  api('GET', '/panel/dashboard').then(function (d) { if (d.ok) badges(d); });
})();
