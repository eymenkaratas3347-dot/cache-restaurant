/* Reception (/recepcion): QR reader (USB keyboard-wedge or camera), partial check-in, promo redemption, today's list. */
(function () {
  'use strict';
  var K = window.KP, U = window.KPU, h = U.h, api = U.api, toast = U.toast;
  var app = document.getElementById('kp-app');
  var current = null, camStream = null, camTimer = null, detector = null;

  var scan = h('input', { type: 'text', id: 'kr-scan', placeholder: 'Escanea el QR o escribe el código / nombre', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Código QR o código de reserva' });
  var camBtn = h('button', { class: 'kp-btn dark', type: 'button', text: '📷 Cámara' });
  var videoBox = h('div', { class: 'kr-video', hidden: true });
  var result = h('div', { 'aria-live': 'assertive' });
  var listSearch = h('input', { type: 'search', placeholder: 'Buscar en la lista de hoy…', style: 'min-height:44px;padding:6px 10px;border:1.5px solid #cfc7bf;border-radius:8px;flex:1' });
  var stats = h('div', { class: 'muted' });
  var list = h('div', { class: 'kr-list' });

  app.innerHTML = '';
  app.appendChild(h('div', { class: 'kr' }, [
    h('div', { class: 'kr-head' }, [
      h('h1', { text: 'Recepción' }),
      h('div', { class: 'kp-row' }, [
        h('span', { class: 'muted', text: K.user }),
        K.canManage ? h('a', { class: 'kp-btn sm ghost', href: K.panelUrl, text: 'Panel' }) : null,
        h('a', { class: 'kp-btn sm ghost', href: K.logout, text: 'Salir' })
      ])
    ]),
    h('form', { class: 'kr-scan', onsubmit: function (e) { e.preventDefault(); lookup(scan.value); } }, [scan, h('button', { class: 'kp-btn', type: 'submit', text: 'Buscar' }), camBtn]),
    videoBox,
    result,
    h('div', { class: 'kp-card', style: 'margin-top:16px' }, [
      h('h3', { text: 'Reservas de hoy' }), stats,
      h('div', { class: 'kp-row', style: 'margin:8px 0' }, [listSearch, h('button', { class: 'kp-btn ghost sm', type: 'button', text: 'Actualizar', onclick: function () { loadToday(); } })]),
      list
    ])
  ]));

  function feedback(ok) {
    if (navigator.vibrate) navigator.vibrate(ok ? 80 : [120, 80, 120]);
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      var o = ctx.createOscillator(); o.frequency.value = ok ? 880 : 220; o.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + (ok ? 0.12 : 0.35));
    } catch (e) {}
  }

  function lookup(raw) {
    var c = String(raw || '').trim();
    scan.value = '';
    if (!c) return;
    // QR URLs and tokens are definitive; a short word may be a reservation code or a name.
    var isQr = /[?&]c=/.test(c) || /^[a-f0-9]{16,64}$/i.test(c);
    var maybeCode = /^[A-Z0-9-]{4,20}$/i.test(c);
    var toList = function () { listSearch.value = c; loadToday(); scan.focus(); };
    if (!isQr && !maybeCode) { toList(); return; }
    result.innerHTML = '<p class="kp-loading">Buscando…</p>';
    api('GET', '/reception/lookup?c=' + encodeURIComponent(c)).then(function (r) {
      if (!r.ok && !isQr && r.code === 'km_invalid_qr') { result.innerHTML = ''; toList(); return; }
      if (!r.ok) {
        feedback(false);
        current = null;
        result.innerHTML = '';
        result.appendChild(h('div', { class: 'kr-result bad' }, [h('div', { class: 'kr-banner', text: '✕ NO VÁLIDO' }), h('p', { class: 'kr-big', text: r.message })]));
        scan.focus();
        return;
      }
      show(r.booking, true);
    });
  }

  function show(b, fromScan) {
    current = b;
    var v = b.validity, left = Math.max(0, b.guests - b.arrived);
    var cls = v.ok ? (b.arrived > 0 ? 'warn' : 'ok') : 'bad';
    var banner = v.ok ? (b.arrived > 0 ? '◐ INGRESO PARCIAL – faltan ' + left : '✓ VÁLIDO – puede ingresar') : '✕ ' + v.message;
    if (fromScan) feedback(v.ok);
    var box = h('div', { class: 'kr-result ' + cls }, [h('div', { class: 'kr-banner', text: banner })]);
    box.appendChild(h('p', { class: 'kr-big', text: b.name }));
    box.appendChild(h('p', {}, [b.is_event ? b.event_name : 'Reserva sin espectáculo · ' + b.sector, ' · ', b.date_human, b.time ? ' · ' + b.time + ' h' : '']));
    if (b.place) box.appendChild(h('p', {}, [h('span', { class: 'muted', text: 'Ubicación ' }), h('span', { class: 'kr-tables', text: b.place })]));
    box.appendChild(h('p', {}, [h('strong', { text: b.arrived + ' de ' + b.guests + ' personas ingresaron' })]));
    box.appendChild(h('div', { class: 'kr-progress' }, h('i', { style: 'width:' + Math.round(100 * b.arrived / Math.max(1, b.guests)) + '%' })));
    box.appendChild(h('div', { class: 'kp-row' }, [U.badge('status', b.status), U.badge('payment', b.payment_status), h('span', { class: 'kp-badge mute', text: b.code })]));
    if (b.payment_status === 'onsite') box.appendChild(h('p', { class: 'kp-badge warn', text: 'Paga en el local: cobrar ' + U.money(b.total) }));
    if (b.notes) box.appendChild(h('p', {}, [h('strong', { text: 'Nota: ' }), b.notes]));

    if (v.ok && left > 0) {
      var qty = left;
      var out = h('output', { text: qty });
      var go = h('button', { class: 'kp-btn green xl', type: 'button' });
      var upd = function () { out.textContent = qty; go.textContent = 'Registrar ingreso de ' + qty; };
      box.appendChild(h('div', { class: 'kr-count' }, [
        h('button', { type: 'button', 'aria-label': 'Menos', text: '−', onclick: function () { qty = Math.max(1, qty - 1); upd(); } }), out,
        h('button', { type: 'button', 'aria-label': 'Más', text: '+', onclick: function () { qty = Math.min(left, qty + 1); upd(); } })
      ]));
      go.addEventListener('click', function () {
        go.disabled = true;
        api('POST', '/reception/checkin', { id: b.id, qty: qty }).then(function (r) {
          go.disabled = false;
          if (!r.ok) { feedback(false); toast(r.message, 'err'); refresh(b.id); return; }
          feedback(true);
          toast(qty + ' ingreso(s) registrados.', 'ok');
          show(r.booking, false);
          loadToday();
          scan.focus();
        });
      });
      upd();
      box.appendChild(go);
    }

    if (b.promos && b.promos.length) {
      var pc = h('div', { class: 'kp-card', style: 'margin-top:12px;background:#faf8f6' }, [h('h3', { text: 'Promociones' })]);
      b.promos.forEach(function (p) {
        var pleft = p.qty - p.redeemed;
        pc.appendChild(h('div', { class: 'kp-row', style: 'justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee' }, [
          h('span', {}, [h('strong', { text: p.name }), ' · ' + p.redeemed + ' de ' + p.qty + ' canjeadas']),
          h('span', { class: 'kp-row' }, [
            pleft > 0 ? h('button', { class: 'kp-btn sm green', type: 'button', text: 'Canjear 1', onclick: function () { redeem(p.id, 1); } }) : h('span', { class: 'kp-badge mute', text: 'Todo canjeado' }),
            pleft > 1 ? h('button', { class: 'kp-btn sm ghost', type: 'button', text: 'Canjear ' + pleft, onclick: function () { redeem(p.id, pleft); } }) : null,
            K.canManage && p.redeemed > 0 ? h('button', { class: 'kp-btn sm ghost', type: 'button', text: 'Revertir 1', onclick: function () { redeem(p.id, -1); } }) : null
          ])
        ]));
      });
      box.appendChild(pc);
    }

    var tools = h('div', { class: 'kp-row', style: 'margin-top:12px' });
    if (b.arrived > 0) tools.appendChild(h('button', { class: 'kp-btn sm ghost', type: 'button', text: 'Corregir ingresos', onclick: function () { correct(b); } }));
    if (b.checkins && b.checkins.length) {
      var log = h('div', { class: 'kp-log', style: 'width:100%' });
      b.checkins.forEach(function (c) { log.appendChild(h('div', { text: c.time + ' · ' + (c.qty > 0 ? '+' : '') + c.qty + ' · ' + c.user_name + (c.note ? ' · ' + c.note : '') })); });
      tools.appendChild(log);
    }
    box.appendChild(tools);
    result.innerHTML = '';
    result.appendChild(box);
    result.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function refresh(id) {
    api('GET', '/panel/bookings/' + id).then(function (r) { if (r.ok) show(r.booking, false); });
  }

  function redeem(lineId, qty) {
    api('POST', '/reception/redeem', { line_id: lineId, qty: qty }).then(function (r) {
      if (!r.ok) { toast(r.message, 'err'); if (current) refresh(current.id); return; }
      toast(qty > 0 ? 'Canje registrado.' : 'Canje revertido.', 'ok');
      show(r.booking, false);
    });
  }

  function correct(b) {
    var delta = h('input', { type: 'number', value: -1, min: -b.arrived, max: b.guests - b.arrived, style: 'width:100px;min-height:44px' });
    var note = h('input', { type: 'text', placeholder: 'Motivo (obligatorio)', style: 'width:100%;min-height:44px' });
    var d = U.drawer('Corregir ingresos de ' + b.name, h('div', {}, [
      h('p', { text: 'Ingresaron ' + b.arrived + ' de ' + b.guests + '. Usa un número negativo para descontar ingresos marcados por error.' }),
      h('label', { text: 'Cantidad' }), delta, h('label', { text: 'Motivo', style: 'display:block;margin-top:8px' }), note
    ]), [h('button', { class: 'kp-btn', text: 'Guardar corrección', onclick: function () {
      api('POST', '/reception/correct', { id: b.id, delta: parseInt(delta.value, 10), note: note.value }).then(function (r) {
        if (!r.ok) return toast(r.message, 'err');
        d.close(); show(r.booking, false); loadToday();
      });
    } })]);
  }

  // Today's list
  var listTimer;
  function loadToday() {
    var q = listSearch.value.trim();
    api('GET', '/reception/today' + (q ? '?q=' + encodeURIComponent(q) : '')).then(function (r) {
      if (!r.ok) { list.innerHTML = ''; list.appendChild(h('p', { text: r.message })); return; }
      var s = r.stats;
      stats.textContent = s.bookings + ' reservas · ' + s.arrived + ' de ' + s.guests + ' personas ingresaron';
      list.innerHTML = '';
      if (!r.bookings.length) { list.appendChild(h('div', { class: 'kp-empty', text: q ? 'Sin resultados.' : 'No hay reservas para hoy.' })); return; }
      var tb = h('tbody');
      r.bookings.forEach(function (b) {
        tb.appendChild(h('tr', { class: 'click', tabindex: 0, onclick: function () { refresh(b.id); }, onkeydown: function (e) { if (e.key === 'Enter') refresh(b.id); } }, [
          h('td', { 'data-l': 'Ubicación' }, [h('strong', { text: b.place || b.sector || '—' })]),
          h('td', { 'data-l': 'Titular' }, [b.name, h('div', { class: 'muted', text: b.code })]),
          h('td', { 'data-l': 'Hora' }, b.time || ''),
          h('td', { 'data-l': 'Ingreso' }, b.arrived + ' / ' + b.guests),
          h('td', { 'data-l': 'Estado' }, [U.badge('status', b.status), ' ', U.badge('payment', b.payment_status)])
        ]));
      });
      list.appendChild(h('table', { class: 'kp-table' }, [h('thead', {}, h('tr', {}, ['Ubicación', 'Titular', 'Hora', 'Ingreso', 'Estado'].map(function (t) { return h('th', { text: t }); }))), tb]));
    });
  }
  listSearch.addEventListener('input', function () { clearTimeout(listTimer); listTimer = setTimeout(loadToday, 300); });
  setInterval(function () { if (!document.hidden) loadToday(); }, 30000);

  // Camera scanning: native BarcodeDetector when available, jsQR otherwise (loaded on demand).
  function loadJsQR() {
    return new Promise(function (resolve, reject) {
      if (window.jsQR) return resolve();
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js';
      s.onload = resolve; s.onerror = reject;
      document.head.appendChild(s);
    });
  }
  function stopCam() {
    if (camStream) camStream.getTracks().forEach(function (t) { t.stop(); });
    camStream = null; clearInterval(camTimer); videoBox.hidden = true; videoBox.innerHTML = ''; camBtn.textContent = '📷 Cámara';
  }
  function startCam() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { toast('Este navegador no permite usar la cámara (requiere HTTPS).', 'err'); return; }
    var ready = ('BarcodeDetector' in window) ? Promise.resolve().then(function () { detector = new window.BarcodeDetector({ formats: ['qr_code'] }); }) : loadJsQR();
    ready.then(function () {
      return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
    }).then(function (stream) {
      camStream = stream;
      var video = h('video', { playsinline: true, muted: true, autoplay: true });
      video.srcObject = stream;
      videoBox.innerHTML = ''; videoBox.appendChild(video); videoBox.hidden = false;
      camBtn.textContent = 'Cerrar cámara';
      var canvas = document.createElement('canvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
      var busy = false, last = '', lastAt = 0;
      camTimer = setInterval(function () {
        if (busy || video.readyState < 2) return;
        busy = true;
        var found = function (txt) {
          busy = false;
          if (!txt) return;
          if (txt === last && Date.now() - lastAt < 4000) return;
          last = txt; lastAt = Date.now();
          lookup(txt);
        };
        if (detector) {
          detector.detect(video).then(function (codes) { found(codes.length ? codes[0].rawValue : ''); }).catch(function () { busy = false; });
        } else {
          canvas.width = video.videoWidth; canvas.height = video.videoHeight;
          ctx.drawImage(video, 0, 0);
          var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
          var code = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
          found(code ? code.data : '');
        }
      }, 250);
    }).catch(function () { toast('No se pudo abrir la cámara. Revisa los permisos o usa el lector.', 'err'); stopCam(); });
  }
  camBtn.addEventListener('click', function () { if (camStream) stopCam(); else startCam(); });

  // A USB reader types the QR URL + Enter into the focused field; keep focus on it when idle.
  document.addEventListener('keydown', function (e) {
    var a = document.activeElement;
    if (a === scan || (a && /INPUT|TEXTAREA|SELECT/.test(a.tagName)) || document.querySelector('.kp-overlay')) return;
    if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) scan.focus();
  });

  loadToday();
  if (K.initialCode) lookup(K.initialCode); else scan.focus();
})();
