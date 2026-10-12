(function () {
  'use strict';
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  // Mobile menu
  var burger = $('.km-burger'), nav = $('#km-nav');
  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    $$('a', nav).forEach(function (a) { a.addEventListener('click', function () { nav.classList.remove('is-open'); burger.setAttribute('aria-expanded', 'false'); }); });
  }

  if ($('.km-tabbar')) document.body.classList.add('km-has-tabbar');

  // Event page: the sticky "Elegir puestos" bar hides while the booking card is on screen.
  var sticky = $('.km-sticky-cta'), book = $('#reservar');
  if (sticky) {
    document.body.classList.add('km-has-sticky');
    if (book && 'IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        sticky.classList.toggle('is-hidden', entries[0].isIntersecting);
        document.body.classList.toggle('km-booking-in-view', entries[0].isIntersecting);
      }, { rootMargin: '0px 0px -30% 0px' }).observe(book);
    }
  }

  // Cartelera filters (Todos / Tributos / Música en vivo …)
  $$('.km-chips [data-filter]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var f = chip.getAttribute('data-filter'), shown = 0;
      $$('[data-filter]', chip.parentNode).forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
      $$('[data-filter-grid] .km-card').forEach(function (card) {
        var on = !f || card.getAttribute('data-cat') === f;
        card.hidden = !on;
        if (on) shown++;
      });
      var empty = $('.km-filter-empty');
      if (empty) empty.hidden = shown > 0;
    });
  });

  // "Ver más eventos" in Eventos realizados
  $$('[data-more]').forEach(function (b) {
    b.addEventListener('click', function () {
      var grid = $(b.getAttribute('data-more'));
      var open = grid.classList.toggle('is-collapsed') === false;
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
      b.firstChild.textContent = open ? 'Ver menos ' : 'Ver más eventos ';
    });
  });

  // Click-to-load video (no autoplay with sound, no heavy embeds on page load)
  $$('.km-video').forEach(function (box) {
    var btn = $('.km-video-play', box);
    if (!btn) return;
    btn.addEventListener('click', function () {
      var src = box.getAttribute('data-src'), type = box.getAttribute('data-type'), el;
      if (type === 'file') {
        el = document.createElement('video');
        el.src = src; el.controls = true; el.autoplay = true; el.playsInline = true; el.setAttribute('preload', 'metadata');
      } else {
        el = document.createElement('iframe');
        el.src = src; el.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen'; el.allowFullscreen = true;
        el.title = btn.getAttribute('aria-label') || 'Video';
      }
      box.innerHTML = '';
      box.appendChild(el);
      var media = box.closest('.km-event-media');
      if (media) media.classList.add('is-playing');
    });
  });

  // Google map only when requested
  $$('[data-load-map]').forEach(function (b) {
    b.addEventListener('click', function () {
      var box = b.closest('.km-map');
      var f = document.createElement('iframe');
      f.src = box.getAttribute('data-map'); f.loading = 'lazy'; f.title = 'Mapa de ubicación'; f.referrerPolicy = 'no-referrer-when-downgrade';
      box.innerHTML = ''; box.appendChild(f);
    });
  });

  // Voucher: refresh while payment is being confirmed (max ~3 minutes)
  var v = $('.km-voucher[data-refresh]');
  if (v) {
    var n = parseInt(sessionStorage.getItem('km_vr') || '0', 10);
    if (n < 36) setTimeout(function () { sessionStorage.setItem('km_vr', String(n + 1)); location.reload(); }, 5000);
  } else {
    try { sessionStorage.removeItem('km_vr'); } catch (e) {}
  }

  // Votes: "Quiero que vuelva"
  var modal = $('#km-vote-modal');
  if (!modal || !window.KM_FRONT) return;
  var state = { event: 0, ticket: '' };
  var msg = $('.km-msg', modal);
  var step = function (n) { $$('.km-vote-step', modal).forEach(function (s) { s.hidden = s.getAttribute('data-step') !== String(n); }); };
  var channel = function () {
    var r = $('input[name=km-v-ch]:checked', modal);
    return r ? r.value : (KM_FRONT.voteChannels[0] === 'phone' ? 'phone' : 'email');
  };
  $$('input[name=km-v-ch]', modal).forEach(function (r) {
    r.addEventListener('change', function () { $$('[data-ch]', modal).forEach(function (d) { d.hidden = d.getAttribute('data-ch') !== channel(); }); });
  });
  var post = function (path, body) {
    return fetch(KM_FRONT.rest + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  };
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-vote]');
    if (!b) return;
    state.event = parseInt(b.getAttribute('data-vote'), 10);
    $('.km-vote-event', modal).textContent = b.getAttribute('data-name');
    msg.textContent = ''; msg.classList.remove('is-ok');
    step(1);
    if (modal.showModal) modal.showModal(); else modal.setAttribute('open', '');
    setTimeout(function () { $('#km-v-name').focus(); }, 50);
  });
  $('[data-act=request]', modal).addEventListener('click', function (e) {
    var btn = e.currentTarget;
    msg.textContent = '';
    btn.disabled = true;
    post('/vote/request', {
      event_id: state.event, name: $('#km-v-name').value, channel: channel(),
      email: $('#km-v-email').value, phone: $('#km-v-phone').value, consent: $('#km-v-consent').checked ? 1 : 0
    }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) { msg.textContent = r.message || 'No se pudo enviar el código.'; return; }
      state.ticket = r.data.ticket;
      step(2);
      msg.classList.add('is-ok');
      msg.textContent = r.data.channel === 'phone' ? 'Te enviamos un código por WhatsApp.' : 'Te enviamos un código a tu correo.';
      $('#km-v-code').focus();
    }).catch(function () { btn.disabled = false; msg.textContent = 'Error de conexión. Intenta de nuevo.'; });
  });
  $('[data-act=verify]', modal).addEventListener('click', function (e) {
    var btn = e.currentTarget;
    btn.disabled = true;
    msg.classList.remove('is-ok');
    post('/vote/verify', { ticket: state.ticket, code: $('#km-v-code').value }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) { msg.textContent = r.message || 'Código incorrecto.'; return; }
      msg.textContent = '';
      step(3);
      $$('[data-vote-count="' + state.event + '"]').forEach(function (c) {
        var p = c.parentNode;
        p.textContent = '';
        c.textContent = r.data.count;
        p.appendChild(c);
        p.appendChild(document.createTextNode(r.data.count === 1 ? ' persona quiere que vuelva' : ' personas quieren que vuelva'));
      });
    }).catch(function () { btn.disabled = false; msg.textContent = 'Error de conexión.'; });
  });
})();
