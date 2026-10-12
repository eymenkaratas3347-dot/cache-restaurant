/* Shared helpers for the private panel and reception. */
(function () {
  'use strict';
  var K = window.KP;

  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k === 'html') el.innerHTML = v;
      else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), v);
      else if (k === 'value') el.value = v;
      else if (k === 'checked') el.checked = !!v;
      else el.setAttribute(k, v === true ? '' : v);
    });
    [].concat(children || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    });
    return el;
  }

  function api(method, path, body) {
    var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': K.nonce } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(K.rest + path, opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: 'Respuesta no válida del servidor (' + r.status + ')' }; }).then(function (j) {
        if (r.status === 401 || r.status === 403) j = { ok: false, code: 'auth', message: j.message || 'Sesión vencida. Vuelve a ingresar.' };
        if (j.ok === undefined && r.ok) j.ok = true;
        return j;
      });
    }).catch(function () { return { ok: false, message: 'Sin conexión con el servidor.' }; });
  }

  var toastTimer;
  function toast(text, type) {
    var t = document.querySelector('.kp-toast');
    if (!t) { t = h('div', { class: 'kp-toast', role: 'status' }); document.body.appendChild(t); }
    t.className = 'kp-toast ' + (type || '');
    t.textContent = text;
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, type === 'err' ? 6000 : 3000);
  }

  function drawer(title, content, actions) {
    var ov = h('div', { class: 'kp-overlay' });
    var close = function () { ov.remove(); document.removeEventListener('keydown', esc); };
    var esc = function (e) { if (e.key === 'Escape') close(); };
    var box = h('div', { class: 'kp-drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': title }, [
      h('div', { class: 'kp-drawer-head' }, [h('h2', { text: title }), h('button', { class: 'kp-x', 'aria-label': 'Cerrar', onclick: close, text: '×' })]),
      content,
      actions ? h('div', { class: 'kp-actions' }, actions) : null
    ]);
    ov.appendChild(box);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    document.addEventListener('keydown', esc);
    document.body.appendChild(ov);
    var f = box.querySelector('input,select,textarea');
    if (f) setTimeout(function () { f.focus(); }, 30);
    return { close: close, el: box };
  }

  function confirmBox(text, okLabel) {
    return new Promise(function (resolve) {
      var d = drawer('Confirmar', h('p', { text: text, style: 'font-size:17px' }), [
        h('button', { class: 'kp-btn ghost', text: 'Volver', onclick: function () { d.close(); resolve(false); } }),
        h('button', { class: 'kp-btn', text: okLabel || 'Confirmar', onclick: function () { d.close(); resolve(true); } })
      ]);
    });
  }

  function promptBox(text, label) {
    return new Promise(function (resolve) {
      var inp = h('textarea', { rows: 3, style: 'width:100%;padding:8px' });
      var d = drawer('Confirmar', h('div', {}, [h('p', { text: text }), h('label', { text: label || 'Motivo' }), inp]), [
        h('button', { class: 'kp-btn ghost', text: 'Volver', onclick: function () { d.close(); resolve(null); } }),
        h('button', { class: 'kp-btn', text: 'Confirmar', onclick: function () { d.close(); resolve(inp.value); } })
      ]);
    });
  }

  /** Form builder. fields: [{k,label,type,options,help,full,attrs}] */
  function form(fields, values) {
    values = values || {};
    var refs = {};
    var el = h('div', { class: 'kp-form' });
    fields.forEach(function (f) {
      if (f.type === 'section') { el.appendChild(h('div', { class: 'kp-fieldset', text: f.label })); return; }
      var id = 'f-' + f.k + '-' + Math.random().toString(36).slice(2, 7);
      var v = values[f.k] !== undefined && values[f.k] !== null ? values[f.k] : (f.def !== undefined ? f.def : '');
      var input;
      if (f.type === 'select') {
        input = h('select', { id: id });
        (f.options || []).forEach(function (o) { var op = h('option', { value: o[0], text: o[1] }); if (String(o[0]) === String(v)) op.selected = true; input.appendChild(op); });
      } else if (f.type === 'textarea') {
        input = h('textarea', { id: id, rows: f.rows || 4 }); input.value = v;
      } else if (f.type === 'check') {
        input = h('input', { id: id, type: 'checkbox', checked: !!parseInt(v, 10) || v === true });
      } else if (f.type === 'media') {
        input = h('input', { id: id, type: 'url', value: v, placeholder: 'https://…' });
      } else {
        input = h('input', Object.assign({ id: id, type: f.type || 'text', value: v }, f.attrs || {}));
      }
      refs[f.k] = input;
      var wrap;
      if (f.type === 'check') {
        wrap = h('div', { class: f.full ? 'full' : '' }, [h('label', { class: 'check', for: id }, [input, ' ' + f.label])]);
      } else if (f.type === 'media') {
        var img = h('img', { alt: '', src: v || '' });
        input.addEventListener('input', function () { img.src = input.value; });
        var pick = h('button', { class: 'kp-btn ghost sm', type: 'button', text: 'Elegir', onclick: function () { media(function (url) { input.value = url; img.src = url; }); } });
        wrap = h('div', { class: f.full ? 'full' : '' }, [h('label', { for: id, text: f.label }), h('div', { class: 'kp-media' }, [img, input, window.wp && wp.media ? pick : null])]);
      } else {
        wrap = h('div', { class: f.full || f.type === 'textarea' ? 'full' : '' }, [h('label', { for: id, text: f.label }), input]);
      }
      if (f.help) wrap.appendChild(h('div', { class: 'help', text: f.help }));
      el.appendChild(wrap);
    });
    return {
      el: el, refs: refs,
      values: function () {
        var out = {};
        Object.keys(refs).forEach(function (k) { var r = refs[k]; out[k] = r.type === 'checkbox' ? (r.checked ? 1 : 0) : r.value; });
        return out;
      }
    };
  }

  function media(cb) {
    if (!window.wp || !wp.media) { toast('Pega la URL de la imagen o video.', 'err'); return; }
    var frame = wp.media({ title: 'Elegir archivo', multiple: false });
    frame.on('select', function () { cb(frame.state().get('selection').first().toJSON().url); });
    frame.open();
  }

  function money(n) { return '$' + Math.round(Number(n) || 0).toLocaleString('es-CL'); }

  var LABELS = {
    status: { pending_payment: ['Pago en curso', 'warn'], confirmed: ['Confirmada', 'ok'], conflict: ['Conflicto', 'bad'], cancelled: ['Cancelada', 'mute'], expired: ['No pagada', 'mute'] },
    payment: { pending: ['Pendiente', 'warn'], paid: ['Pagado', 'ok'], failed: ['Rechazado', 'bad'], abandoned: ['Abandonado', 'mute'], cancelled: ['Cancelado', 'mute'], refunded: ['Devuelto', 'info'], partial_refund: ['Devolución parcial', 'warn'], onsite: ['Paga en local', 'info'], courtesy: ['Cortesía', 'info'], none: ['Sin costo', 'info'], manual: ['Pagado (manual)', 'ok'] },
    event: { draft: ['Borrador', 'mute'], published: ['Publicado', 'ok'], hidden: ['Oculto', 'warn'], cancelled: ['Cancelado', 'bad'], archived: ['Archivado', 'mute'] },
    msg: { queued: ['En cola', 'mute'], sent: ['Enviado', 'ok'], delivered: ['Entregado', 'ok'], read: ['Leído', 'ok'], failed: ['Error', 'bad'], retry: ['Reintentando', 'warn'], skipped: ['Omitido', 'mute'] },
    source: { web: 'Web', manual: 'Manual', walkin: 'Sin reserva' }
  };
  function badge(group, key) {
    var l = (LABELS[group] || {})[key] || [key, 'mute'];
    return h('span', { class: 'kp-badge ' + l[1], text: l[0] });
  }

  /**
   * Renders the floor plan with every chair.
   * opts: {plan, occupancy (by table, from /panel/floor), blocked (table ids), selected (seat ids "12-3"),
   *        recommended (table ids), onClick(tableId, seatId|null)}
   */
  var floorSeq = 0;
  function seatInfo(o, n) {
    if (!o || !o.seats || !o.seats[n]) return null;
    var id = o.seats[n];
    var who = (o.who || []).filter(function (w) { return w.booking === id; })[0] || {};
    return { booking: id, state: who.arrived > 0 ? 'arrived' : (o.seat_states[n] || 'paid'), name: who.name, guests: who.guests, arrived: who.arrived };
  }
  function floor(opts) {
    var tpl = document.getElementById('kp-floor-template');
    var wrap = h('div', { class: 'kp-floor-wrap' });
    var uid = 'kpf' + (++floorSeq);
    wrap.innerHTML = tpl.innerHTML.replace(/kp-floor/g, uid);
    var svg = wrap.querySelector('svg');
    var NS = 'http://www.w3.org/2000/svg';
    var g = svg.querySelector('.km-tables');
    function el(name, attrs) {
      var e = document.createElementNS(NS, name);
      Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
      return e;
    }
    function render() {
      g.innerHTML = '';
      var sel = opts.selected || [];
      (opts.plan || K.tables).forEach(function (t) {
        var o = opts.occupancy && opts.occupancy[t.id];
        var blocked = (opts.blocked || []).indexOf(t.id) > -1;
        var mine = sel.filter(function (s) { return String(s).split('-')[0] === String(t.id); }).length;
        var cls = 't';
        if (!t.active) cls += ' inactive';
        else if (blocked) cls += ' blocked';
        else if (o && o.full) cls += ' ' + ((o.who || []).every(function (w) { return w.arrived > 0; }) ? 'arrived' : o.state);
        else if (o) cls += ' part';
        if (mine) cls += ' sel';
        if ((opts.recommended || []).indexOf(t.id) > -1) cls += ' rec';
        var grp = el('g', { 'class': cls, transform: 'translate(' + t.x + ',' + t.y + ')', 'data-id': t.id });
        var names = o ? (o.who || []).map(function (w) { return w.name + ' (' + w.guests + ' p.' + (w.arrived ? ', ' + w.arrived + ' ✓' : '') + ')'; }) : [];
        var title = el('title', {});
        title.textContent = 'Mesa ' + t.id + ' · ' + (o ? o.taken : 0) + '/' + t.c + ' puestos ocupados' + (t.sector ? ' · ' + t.sector : '') + (blocked ? ' · bloqueada' : '') + (names.length ? '\n' + names.join('\n') : '');
        grp.appendChild(title);
        (t.seats || []).forEach(function (p, i) {
          var n = i + 1, sid = t.id + '-' + n, info = seatInfo(o, n);
          var sc = 'silla' + (info ? ' ' + info.state : '') + (sel.indexOf(sid) > -1 ? ' sel' : '') + (blocked || !t.active ? ' blk' : '');
          grp.appendChild(el('circle', { 'class': sc, cx: p[0], cy: p[1], r: 6.5 }));
          var hit = el('circle', { 'class': 'hit', cx: p[0], cy: p[1], r: 10, 'data-seat': sid });
          var st = el('title', {});
          st.textContent = 'Puesto ' + sid + (info ? ' · ' + info.name : ' · libre');
          hit.appendChild(st);
          grp.appendChild(hit);
        });
        grp.appendChild(el('rect', { 'class': 'mesa', x: -t.w / 2, y: -t.h / 2, width: t.w, height: t.h, rx: 6 }));
        var lbl = el('text', { 'class': 'lbl', y: 1 });
        lbl.textContent = t.id;
        grp.appendChild(lbl);
        g.appendChild(grp);
      });
    }
    render();
    svg.addEventListener('click', function (e) {
      var t = e.target.closest('.t');
      if (!t || !opts.onClick) return;
      var hit = e.target.closest('[data-seat]');
      opts.onClick(parseInt(t.getAttribute('data-id'), 10), hit ? hit.getAttribute('data-seat') : null);
    });
    return { el: wrap, render: render, opts: opts };
  }

  function legend() {
    var dot = function (bg, extra) { return h('i', { style: 'background:' + bg + ';border-radius:50%' + (extra || '') }); };
    return h('div', { class: 'kp-legend' }, [
      h('span', {}, [dot('#fff'), 'Puesto libre']),
      h('span', {}, [dot('#c62a22'), 'Reservado y pagado']),
      h('span', {}, [dot('#f2b84b'), 'Pago en curso']),
      h('span', {}, [dot('#1f7a3a'), 'Llegaron']),
      h('span', {}, [dot('#7b2cbf'), 'Conflicto']),
      h('span', {}, [dot('#163e78'), 'Elegido ahora']),
      h('span', {}, [h('i', { style: 'background:#ddd;border-style:dashed' }), 'Mesa bloqueada'])
    ]);
  }

  window.KPU = { h: h, api: api, toast: toast, drawer: drawer, confirm: confirmBox, prompt: promptBox, form: form, media: media, money: money, badge: badge, floor: floor, seatInfo: seatInfo, legend: legend, LABELS: LABELS };
})();
