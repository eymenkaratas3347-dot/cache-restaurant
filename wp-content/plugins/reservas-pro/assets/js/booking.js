(function () {
  'use strict';
  var C = window.KM_BOOKING, root = document.getElementById('km-booking');
  if (!C || !root) return;
  var $ = function (s, c) { return (c || root).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || root).querySelectorAll(s)); };
  var isEvent = !!C.event, seatMode = C.unit === 'seat';
  var NS = 'http://www.w3.org/2000/svg';
  var money = function (n) { return '$' + Math.round(n).toLocaleString('es-CL'); };
  var plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : many); };
  var S = { guests: 2, time: '', date: '', sector: '', seats: [], promos: {}, avail: null, taken: {}, promoList: [], zoom: 1, focused: false };
  var byId = {};
  (C.tables || []).forEach(function (t) { byId[t.id] = t; });

  function api(path, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = { 'Content-Type': 'application/json' };
    return fetch(C.rest + path, opts).then(function (r) { return r.json(); });
  }
  function msg(stepEl, text, ok) {
    var m = stepEl && $('.km-msg', stepEl);
    if (!m) return;
    m.textContent = text || '';
    m.classList.toggle('is-ok', !!ok);
  }
  function go(n) {
    $$('.km-step').forEach(function (s) { s.hidden = s.getAttribute('data-step') !== String(n); });
    $$('[data-step-ind]').forEach(function (li) {
      var i = parseInt(li.getAttribute('data-step-ind'), 10);
      li.classList.toggle('is-active', i === n);
      li.classList.toggle('is-done', i < n);
    });
    root.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  function price() { return isEvent ? C.event.price : C.convDeposit; }
  function isAbono() { return isEvent ? C.event.payment_mode === 'abono' : true; }

  // ---------------- Seats ----------------
  function split(s) { var p = String(s).split('-'); return [parseInt(p[0], 10), parseInt(p[1], 10)]; }
  function sid(t, n) { return t + '-' + n; }
  function sortSeats(arr) {
    return arr.slice().sort(function (a, b) { var x = split(a), y = split(b); return x[0] - y[0] || x[1] - y[1]; });
  }
  function tablesOf(seats) {
    var seen = {}, out = [];
    seats.forEach(function (s) { var t = split(s)[0]; if (!seen[t]) { seen[t] = 1; out.push(t); } });
    return out.sort(function (a, b) { return a - b; });
  }
  function tableSeats(id) { var out = []; for (var n = 1; n <= byId[id].c; n++) out.push(sid(id, n)); return out; }
  function freeSeats(id) { return tableSeats(id).filter(function (s) { return !S.taken[s]; }); }
  function mineOn(id) { return S.seats.filter(function (s) { return split(s)[0] === id; }); }
  function unavailable(id) { return !S.avail || S.avail.unavailable.indexOf(id) > -1; }
  function joinHuman(items) {
    if (items.length < 2) return String(items[0]);
    return items.slice(0, -1).join(', ') + ' y ' + items[items.length - 1];
  }
  function placeLabel(seats) {
    var by = {}, order = [];
    sortSeats(seats).forEach(function (s) { var p = split(s); if (!by[p[0]]) { by[p[0]] = []; order.push(p[0]); } by[p[0]].push(p[1]); });
    var whole = [], parts = [];
    order.forEach(function (t) {
      if (by[t].length >= byId[t].c) whole.push(t);
      else parts.push('Mesa ' + t + ' (' + (by[t].length > 1 ? 'puestos ' : 'puesto ') + joinHuman(by[t]) + ')');
    });
    if (whole.length) parts.unshift((whole.length > 1 ? 'Mesas ' : 'Mesa ') + joinHuman(whole));
    return parts.join(' + ');
  }
  function capacity(ids) {
    var base = 0;
    ids.forEach(function (id) { base += byId[id].c; });
    return { base: base, max: base + (C.extraChair ? 1 : 0) };
  }
  function adjacent(a, b) {
    if (C.explicitNeighbors) return (a.n || []).indexOf(b.id) > -1 || (b.n || []).indexOf(a.id) > -1;
    return Math.sqrt(Math.pow(a.x - b.x, 2) + Math.pow(a.y - b.y, 2)) <= C.distance;
  }
  function connected(ids) {
    if (ids.length < 2) return true;
    var seen = {}, q = [ids[0]]; seen[ids[0]] = 1;
    while (q.length) {
      var cur = byId[q.shift()];
      ids.forEach(function (id) { if (!seen[id] && adjacent(cur, byId[id])) { seen[id] = 1; q.push(id); } });
    }
    return Object.keys(seen).length === ids.length;
  }

  // ---------------- Step 1 (event): people, arrival, map ----------------
  var step1 = $('[data-step="1"]');
  var svg = $('#km-floor');
  var reloadTimer = null;

  function selectionReady() {
    if (!S.seats.length) return false;
    if (seatMode) return S.seats.length === S.guests;
    var cap = capacity(tablesOf(S.seats));
    return cap.max >= S.guests;
  }

  function updateSummary() {
    if (!isEvent) return;
    var n = S.seats.length, ready = selectionReady();
    var count = $('#km-seat-count');
    if (seatMode) count.textContent = n + ' / ' + plural(S.guests, 'puesto', 'puestos');
    else count.textContent = n ? plural(capacity(tablesOf(S.seats)).base, 'puesto', 'puestos') : '';
    count.classList.toggle('is-ok', ready);
    $('#km-place').textContent = n
      ? placeLabel(S.seats) + ' · ' + plural(S.guests, 'persona', 'personas')
      : (seatMode ? 'Elige ' + plural(S.guests, 'puesto', 'puestos') : 'Elige tu mesa');
    var p = price();
    $('#km-line-label').textContent = p > 0 ? (isAbono() ? 'Abono' : 'Entradas') + ': ' + S.guests + ' × ' + money(p) : 'Entrada liberada';
    $('#km-line-amount').textContent = money(p * S.guests);
    $('#km-line-total').textContent = money(p * S.guests);
    $('[data-act="to-details"]', step1).disabled = !ready;
  }

  function after(text, ok) {
    drawMap();
    updateSummary();
    msg(step1, text || '', ok);
  }

  function setGuests(n) {
    var input = $('#km-guests');
    var min = parseInt(input.getAttribute('data-min'), 10) || 1, max = parseInt(input.getAttribute('data-max'), 10) || 12;
    n = Math.max(min, Math.min(max, n));
    var note = $('.km-max-note');
    if (note) note.hidden = n < max;
    if (n === S.guests && S.avail) return;
    S.guests = n;
    input.value = n;
    $('#km-guests-out').textContent = n;
    if (seatMode && S.seats.length > n) S.seats = S.seats.slice(0, n);
    if (S.avail) after();
    clearTimeout(reloadTimer);
    reloadTimer = setTimeout(loadAvail, 300);
  }

  function loadAvail() {
    var guests = S.guests, seq = (S.seq = (S.seq || 0) + 1);
    return api('/availability?event_id=' + C.event.id + '&guests=' + guests).then(function (r) {
      if (seq !== S.seq) return;
      if (!r.ok) { msg(step1, r.message || 'No pudimos cargar el plano.'); return; }
      if (!r.open) { msg(step1, 'Las reservas para este evento están cerradas o agotadas.'); $('[data-act="to-details"]', step1).disabled = true; return; }
      S.avail = r;
      S.availFor = guests;
      S.taken = {};
      r.taken.forEach(function (s) { S.taken[s] = 1; });
      S.promoList = r.promos || [];
      var before = S.seats.length;
      S.seats = S.seats.filter(function (s) { return !S.taken[s]; });
      if (!seatMode) S.seats = S.seats.filter(function (s) { return !unavailable(split(s)[0]); });
      after(before > S.seats.length ? 'Algunos puestos que tenías elegidos acaban de ser tomados. Elige otros.' : '');
      $('#km-map-hint').textContent = !r.best.length
        ? 'No quedan lugares juntos para ' + plural(S.guests, 'persona', 'personas') + '. Prueba con menos personas o escríbenos por WhatsApp.'
        : (seatMode ? 'Toca una mesa para tomar sus puestos libres, o toca cada silla. Las mesas con borde dorado son las ideales para tu grupo.' : 'Toca una mesa para elegirla. Las de borde dorado son las ideales para tu grupo.');
      if (!S.focused) { S.focused = true; focusMap(); }
    }).catch(function () { msg(step1, 'Error de conexión. Revisa tu internet e intenta de nuevo.'); });
  }

  // On phones the whole plan is too small to tap: start zoomed in, centred on the best place.
  function focusMap() {
    if (!svg) return;
    var box = svg.parentNode;
    if (S.zoom === 1 && window.innerWidth < 600) setZoom(2);
    var target = (S.seats.length ? tablesOf(S.seats)[0] : null) || (S.avail && S.avail.best_tables && S.avail.best_tables[0]) || (S.avail && S.avail.recommended[0]);
    var el = target && svg.querySelector('[data-id="' + target + '"]');
    if (!el || S.zoom === 1) return;
    requestAnimationFrame(function () {
      var r = el.getBoundingClientRect(), b = box.getBoundingClientRect();
      box.scrollLeft += r.left - b.left - b.width / 2 + r.width / 2;
      box.scrollTop += r.top - b.top - b.height / 2 + r.height / 2;
    });
  }
  function setZoom(z) {
    S.zoom = Math.min(3, Math.max(1, z));
    svg.style.width = (S.zoom * 100) + '%';
  }

  function el(name, attrs) {
    var e = document.createElementNS(NS, name);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    return e;
  }

  function drawMap() {
    if (!svg || !S.avail) return;
    var g = svg.querySelector('.km-tables');
    g.innerHTML = '';
    var rec = S.avail.recommended || [];
    C.tables.forEach(function (t) {
      var full = unavailable(t.id), mine = mineOn(t.id).length, free = freeSeats(t.id).length;
      var cls = 't' + (full ? ' full' : '') + (mine ? ' sel' : '') + (!mine && !full && rec.indexOf(t.id) > -1 ? ' rec' : '') + (!full && free < t.c ? ' part' : '');
      var grp = el('g', { 'class': cls, transform: 'translate(' + t.x + ',' + t.y + ')', 'data-id': t.id });
      t.seats.forEach(function (p, i) {
        var s = sid(t.id, i + 1), state = S.taken[s] ? ' occ' : (S.seats.indexOf(s) > -1 ? ' sel' : '');
        grp.appendChild(el('circle', { 'class': 'silla' + state, cx: p[0], cy: p[1], r: 6.5 }));
        if (seatMode && !S.taken[s] && !full) grp.appendChild(el('circle', { 'class': 'hit', cx: p[0], cy: p[1], r: 10, 'data-seat': s }));
      });
      var label = 'Mesa ' + t.id + ', ' + (full ? 'no disponible' : plural(free, 'puesto libre', 'puestos libres') + ' de ' + t.c) + (mine ? ', tienes ' + plural(mine, 'puesto elegido', 'puestos elegidos') : '');
      grp.appendChild(el('rect', { 'class': 'mesa', x: -t.w / 2, y: -t.h / 2, width: t.w, height: t.h, rx: 7, role: 'button', tabindex: full ? '-1' : '0', 'aria-label': label, 'aria-pressed': mine ? 'true' : 'false' }));
      var lbl = el('text', { 'class': 'lbl', y: 1 });
      lbl.textContent = t.id;
      grp.appendChild(lbl);
      g.appendChild(grp);
    });
  }

  function toggleSeat(s) {
    var t = split(s)[0];
    if (S.taken[s] || unavailable(t)) return after('Ese puesto ya está ocupado.');
    var i = S.seats.indexOf(s);
    if (i > -1) { S.seats.splice(i, 1); return after(); }
    if (S.seats.length >= S.guests) {
      if (S.guests === 1) { S.seats = [s]; return after(); }
      return after('Ya elegiste ' + plural(S.guests, 'puesto', 'puestos') + '. Toca uno de los tuyos para soltarlo.');
    }
    var next = S.seats.concat([s]);
    if (!connected(tablesOf(next))) return after('Elige puestos en mesas que estén juntas.');
    S.seats = sortSeats(next);
    after();
  }

  function tapTable(id) {
    var t = byId[id];
    if (!t) return;
    if (unavailable(id)) return after('La mesa ' + id + ' no está disponible.');
    var mine = mineOn(id);
    if (mine.length) {
      S.seats = S.seats.filter(function (s) { return split(s)[0] !== id; });
      return after();
    }
    var free = freeSeats(id);
    if (!seatMode) {
      var ids = tablesOf(S.seats);
      if (ids.length && capacity(ids).max >= S.guests) return after('Ya tienes puestos suficientes. Toca una mesa elegida para soltarla.');
      if (!ids.length && S.avail.recommended.length && S.avail.recommended.indexOf(id) === -1 && t.c - S.guests > 1) return after('Esa mesa es grande para tu grupo. Elige una con borde dorado.');
      var nextIds = ids.concat([id]);
      if (!connected(nextIds)) return after('Elige mesas que estén juntas.');
      S.seats = sortSeats(S.seats.concat(tableSeats(id)));
      return after();
    }
    var need = S.guests - S.seats.length;
    var together = !S.seats.length || connected(tablesOf(S.seats).concat([id]));
    if (need <= 0 || !together) {
      if (free.length >= S.guests) { S.seats = free.slice(0, S.guests); return after(); }
      return after(need <= 0 ? 'Ya elegiste tus ' + plural(S.guests, 'puesto', 'puestos') + '. Toca una mesa elegida para soltarla.' : 'Elige puestos en mesas que estén juntas.');
    }
    S.seats = sortSeats(S.seats.concat(free.slice(0, need)));
    var left = S.guests - S.seats.length;
    after(left > 0 ? 'Faltan ' + plural(left, 'puesto', 'puestos') + ': toca una mesa vecina.' : '');
  }

  if (isEvent && step1) {
    $$('[data-guests]', step1).forEach(function (b) {
      b.addEventListener('click', function () { setGuests(S.guests + parseInt(b.getAttribute('data-guests'), 10)); });
    });
    svg.addEventListener('click', function (e) {
      var hit = e.target.closest('[data-seat]');
      if (hit) { toggleSeat(hit.getAttribute('data-seat')); return; }
      var g = e.target.closest('.t');
      if (g) tapTable(parseInt(g.getAttribute('data-id'), 10));
    });
    svg.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var g = e.target.closest('.t');
      if (!g) return;
      e.preventDefault();
      var id = parseInt(g.getAttribute('data-id'), 10);
      tapTable(id);
      var again = svg.querySelector('[data-id="' + id + '"] .mesa');
      if (again) again.focus();
    });
    $$('[data-zoom]', step1).forEach(function (b) {
      b.addEventListener('click', function () { setZoom(S.zoom + parseInt(b.getAttribute('data-zoom'), 10) * 0.5); });
    });
    $('[data-act="suggest"]', step1).addEventListener('click', function () {
      var btn = this;
      function pick() {
        if (!S.avail || !S.avail.best.length) return after('No encontramos lugares juntos para tu grupo.');
        S.seats = sortSeats(S.avail.best);
        after('Elegimos ' + placeLabel(S.seats) + ' para ti.', true);
        focusMap();
      }
      if (S.avail && S.availFor === S.guests) return pick();
      clearTimeout(reloadTimer);
      btn.disabled = true;
      var p = loadAvail();
      (p && p.then ? p : Promise.resolve()).then(function () {
        btn.disabled = false;
        if (S.availFor === S.guests) pick();
      });
    });
    $('[data-act="to-details"]', step1).addEventListener('click', function () {
      if (!selectionReady()) return;
      if (!connected(tablesOf(S.seats))) return after('Elige puestos en mesas que estén juntas.');
      var r = root.querySelector('input[name="km-time"]:checked') || root.querySelector('input[name="km-time"]');
      S.time = r ? r.value : C.event.show_time;
      S.date = C.event.date;
      summary();
    });
    var g0 = parseInt($('#km-guests').value, 10) || 2;
    S.guests = g0;
    loadAvail();
  }

  // ---------------- Step 1 (conventional): date, time, sector ----------------
  var convForm = !isEvent ? $('form[data-step="1"]') : null;
  if (convForm) {
    var fGuests = $('#km-guests'), fTime = $('#km-time'), fDate = $('#km-date'), fSector = $('#km-sector');
    var fillConventional = function (data) {
      var prev = fTime.value;
      fTime.innerHTML = '';
      if (!data.slots.length) {
        fTime.innerHTML = '<option value="">Sin horarios disponibles</option>';
        fTime.disabled = true;
      } else {
        fTime.disabled = false;
        fTime.appendChild(new Option('Selecciona…', ''));
        data.slots.forEach(function (s) { fTime.appendChild(new Option(s + ' h', s)); });
        if (data.slots.indexOf(prev) > -1) fTime.value = prev;
      }
      var prevS = fSector.value;
      fSector.innerHTML = '<option value="">Selecciona…</option>';
      data.sectors.forEach(function (s) {
        var o = new Option(s.name + (s.available ? '' : ' (completo)'), s.name);
        o.disabled = !s.available;
        fSector.appendChild(o);
      });
      if (prevS) fSector.value = prevS;
      var note = $('#km-date-note');
      if (data.events && data.events.length) {
        note.hidden = false;
        note.innerHTML = '';
        note.appendChild(document.createTextNode('Esa noche hay espectáculo: '));
        data.events.forEach(function (e, i) {
          var a = document.createElement('a'); a.href = e.url; a.textContent = e.name;
          if (i) note.appendChild(document.createTextNode(', '));
          note.appendChild(a);
        });
        note.appendChild(document.createTextNode('. Las reservas sin espectáculo son hasta antes del show.'));
      } else { note.hidden = true; }
      S.promoList = data.promos || [];
    };
    var loadConventional = function () {
      if (!fDate.value) return;
      var q = '/availability?date=' + encodeURIComponent(fDate.value) + '&guests=' + fGuests.value + (fTime.value ? '&time=' + encodeURIComponent(fTime.value) : '');
      api(q).then(function (r) {
        if (!r.ok) { msg(convForm, r.message); return; }
        msg(convForm, '');
        fillConventional(r);
      });
    };
    fDate.addEventListener('change', loadConventional);
    fTime.addEventListener('change', loadConventional);
    fGuests.addEventListener('change', loadConventional);
    convForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var errors = [];
      if (invalid(fDate, !fDate.value)) errors.push('la fecha');
      if (invalid(fTime, !fTime.value)) errors.push('la hora');
      if (invalid(fSector, !fSector.value)) errors.push('el sector');
      if (errors.length) { msg(convForm, 'Falta ' + errors.join(', ') + '.'); return; }
      msg(convForm, '');
      S.guests = parseInt(fGuests.value, 10);
      S.time = fTime.value;
      S.date = fDate.value;
      S.sector = fSector.value;
      summary();
    });
  }

  // ---------------- Step 2: contact, promotions, payment ----------------
  var step2 = $('[data-step="2"]');
  function field(id) { return $('#' + id); }
  function invalid(input, bad) { input.setAttribute('aria-invalid', bad ? 'true' : 'false'); return bad; }
  function row(dl, k, v) {
    var d = document.createElement('div'), dt = document.createElement('dt'), dd = document.createElement('dd');
    dt.textContent = k; dd.textContent = v; d.appendChild(dt); d.appendChild(dd); dl.appendChild(d);
  }
  function renderPromos() {
    var box = $('#km-promos'), list = $('#km-promo-list');
    list.innerHTML = '';
    if (!S.promoList.length) { box.hidden = true; return; }
    box.hidden = false;
    S.promoList.forEach(function (p) {
      var q = S.promos[p.id] || 0;
      var max = Math.min(p.max_per_booking, p.left === null ? 99 : p.left);
      var item = document.createElement('div');
      item.className = 'km-promo';
      item.innerHTML = (p.image ? '<img alt="" loading="lazy">' : '<span class="km-promo-ph"></span>') +
        '<div><h4></h4><p class="km-p-desc"></p><p><strong class="km-p-price"></strong><s></s></p></div>' +
        '<div class="km-qty"><button type="button" aria-label="Quitar">−</button><output aria-live="polite">' + q + '</output><button type="button" aria-label="Agregar">+</button></div>';
      if (p.image) item.querySelector('img').src = p.image;
      item.querySelector('h4').textContent = p.name;
      item.querySelector('.km-p-desc').textContent = p.description + (p.conditions ? ' · ' + p.conditions : '');
      item.querySelector('.km-p-price').textContent = money(p.price);
      item.querySelector('s').textContent = p.regular_price > p.price ? money(p.regular_price) : '';
      var btns = item.querySelectorAll('button');
      btns[0].addEventListener('click', function () { S.promos[p.id] = Math.max(0, (S.promos[p.id] || 0) - 1); renderPromos(); renderTotal(); });
      btns[1].addEventListener('click', function () { S.promos[p.id] = Math.min(max, (S.promos[p.id] || 0) + 1); renderPromos(); renderTotal(); });
      list.appendChild(item);
    });
  }
  function renderTotal() {
    var people = price() * S.guests, promos = 0;
    S.promoList.forEach(function (p) { promos += (S.promos[p.id] || 0) * p.price; });
    var box = $('#km-total');
    var abono = isAbono();
    var html = '<div class="km-row"><span>' + (abono ? 'Abono' : 'Entradas') + ' (' + S.guests + ' × ' + money(price()) + ')</span><span>' + money(people) + '</span></div>';
    if (promos) html += '<div class="km-row"><span>Promociones</span><span>' + money(promos) + '</span></div>';
    html += '<div class="km-row km-row-total"><span>Total a pagar</span><span>' + money(people + promos) + '</span></div>';
    if (isEvent && abono && C.event.min_consumption > 0) {
      var bal = Math.max(0, C.event.min_consumption * S.guests - people);
      html += '<p class="km-small">Consumo mínimo ' + money(C.event.min_consumption) + ' por persona. El abono se descuenta de tu cuenta; saldo mínimo a consumir en el local: ' + money(bal) + '. Las promociones se pagan aparte y no se descuentan del abono.</p>';
    } else if (abono && people > 0) {
      html += '<p class="km-small">El abono se descuenta de tu consumo en el local.</p>';
    }
    box.innerHTML = html;
    $('[data-act="pay"]', step2).textContent = people + promos > 0 ? 'Ir a pagar ' + money(people + promos) : 'Confirmar reserva';
  }
  function summary() {
    var box = $('#km-summary');
    box.innerHTML = '';
    var dl = document.createElement('dl');
    if (isEvent) {
      row(dl, 'Evento', C.event.name);
      row(dl, 'Fecha', C.event.date_human);
      row(dl, 'Llegada', S.time + ' h (show ' + C.event.show_time + ' h)');
      row(dl, 'Ubicación', placeLabel(S.seats));
    } else {
      row(dl, 'Fecha', new Date(S.date + 'T12:00:00').toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' }));
      row(dl, 'Hora', S.time + ' h');
      row(dl, 'Sector', S.sector);
    }
    row(dl, 'Personas', S.guests);
    box.appendChild(dl);
    renderPromos();
    renderTotal();
    msg(step2, '');
    go(2);
  }

  if (step2) {
    $('[data-act="back"]', step2).addEventListener('click', function () { go(1); });
    step2.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('[data-act="pay"]', step2);
      var errors = [];
      var name = field('km-name'), email = field('km-email'), phone = field('km-phone');
      if (invalid(name, name.value.trim().length < 3)) errors.push('tu nombre y apellido');
      if (invalid(email, !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.value.trim()))) errors.push('un correo válido');
      if (invalid(phone, phone.value.replace(/\D/g, '').length < 8)) errors.push('tu WhatsApp');
      if (errors.length) { msg(step2, 'Falta ' + errors.join(', ') + '.'); var first = $('[aria-invalid=true]', step2); if (first) first.focus(); return; }
      if (!$('#km-terms').checked) { msg(step2, 'Debes aceptar las condiciones para continuar.'); $('#km-terms').focus(); return; }
      btn.disabled = true;
      msg(step2, isEvent ? 'Reservando tus puestos…' : 'Reservando tu mesa…', true);
      api('/booking', {
        method: 'POST',
        body: JSON.stringify({
          event_id: isEvent ? C.event.id : 0, date: S.date, time: S.time, sector: S.sector, guests: S.guests, seats: isEvent ? S.seats : [],
          name: name.value, email: email.value, phone: phone.value, country: field('km-country').value,
          promos: S.promos, terms: 1, consent: $('#km-consent').checked ? 1 : 0, website: field('km-website').value
        })
      }).then(function (r) {
        if (r.ok && r.redirect) { msg(step2, 'Redirigiendo al pago…', true); window.location.href = r.redirect; return; }
        btn.disabled = false;
        if (isEvent && /^km_(taken|waste|extra_table|extra_seats|apart|capacity|whole_table|blocked|bad_table|no_tables|too_many)$/.test(r.code || '')) {
          S.seats = [];
          go(1);
          loadAvail().then(function () { msg(step1, r.message); });
          return;
        }
        msg(step2, r.message || 'No pudimos completar la reserva.');
      }).catch(function () { btn.disabled = false; msg(step2, 'Error de conexión. Intenta de nuevo.'); });
    });
  }
})();
