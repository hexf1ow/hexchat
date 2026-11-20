/**
 * HexChat widget.
 *
 * Vanilla JS, no jQuery, no build step. Everything lives inside a shadow root so
 * the host theme cannot break it and it cannot break the theme. The browser only
 * ever talks to the plugin's own REST endpoint — the API key stays server-side.
 */
(function () {
  'use strict';

  var CFG = window.HexChatConfig || null;
  var root = document.getElementById('hexchat-root');
  if (!CFG || !root || !CFG.endpoint) return;

  var SESSION_KEY = 'hexchat_session';
  var state = { open: false, busy: false, session: '', messages: [] };

  function sessionId() {
    try {
      var s = sessionStorage.getItem(SESSION_KEY);
      if (!s) {
        s = 'hx' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
        sessionStorage.setItem(SESSION_KEY, s);
      }
      return s;
    } catch (e) {
      return 'hx' + Math.random().toString(36).slice(2, 12);
    }
  }

  state.session = sessionId();

  var host = document.createElement('div');
  host.setAttribute('data-hexchat', '1');
  root.appendChild(host);
  var shadow = host.attachShadow({ mode: 'open' });

  var link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = CFG.cssUrl || '';
  shadow.appendChild(link);

  if (CFG.position === 'left') host.classList.add('hc-pos-left');
  if (CFG.brand) {
    host.style.setProperty('--hc-brand', CFG.brand);
    host.style.setProperty('--hc-brand-soft', CFG.brand + '22');
  }

  var wrap = document.createElement('div');
  wrap.className = 'hc-wrap';
  wrap.innerHTML = [
    '<button class="hc-launcher" type="button" aria-label="Open chat">',
    '  <svg class="hc-icon-chat" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3C6.9 3 3 6.6 3 11c0 2.2 1 4.2 2.6 5.6-.2 1.2-.8 2.4-1.7 3.3-.2.2 0 .6.3.6 2 0 3.8-.8 5-1.9 1 .3 2 .5 3.1.5 5.1 0 9-3.6 9-8s-3.9-8-8.3-8z"/></svg>',
    '  <svg class="hc-icon-close" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    '</button>',
    '<section class="hc-panel" role="dialog" aria-modal="false" aria-label="' + escapeAttr(CFG.title || 'Chat') + '">',
    '  <header class="hc-head">',
    '    <div class="hc-head-main">',
    '      <span class="hc-avatar" aria-hidden="true">',
    '        <svg viewBox="0 0 24 24"><path d="M12 2l2.4 5.6L20 10l-5.6 2.4L12 18l-2.4-5.6L4 10l5.6-2.4z"/></svg>',
    '      </span>',
    '      <div>',
    '        <strong class="hc-title"></strong>',
    '        <span class="hc-status"><i></i> AI assistant · answers from this site</span>',
    '      </div>',
    '    </div>',
    '    <button class="hc-min" type="button" aria-label="Minimise">&minus;</button>',
    '  </header>',
    '  <div class="hc-body" role="log" aria-live="polite"></div>',
    '  <form class="hc-compose">',
    '    <textarea class="hc-input" rows="1" placeholder="Type your question…" maxlength="' + (CFG.maxLength || 1500) + '"></textarea>',
    '    <button class="hc-send" type="submit" aria-label="Send">',
    '      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l16-7-7 16-2.2-6.8z"/></svg>',
    '    </button>',
    '  </form>',
    '  <footer class="hc-foot">Powered by HexChat · free &amp; open source</footer>',
    '</section>'
  ].join('');

  shadow.appendChild(wrap);

  var launcher = shadow.querySelector('.hc-launcher');
  var panel = shadow.querySelector('.hc-panel');
  var body = shadow.querySelector('.hc-body');
  var form = shadow.querySelector('.hc-compose');
  var input = shadow.querySelector('.hc-input');
  var send = shadow.querySelector('.hc-send');
  var minBtn = shadow.querySelector('.hc-min');

  shadow.querySelector('.hc-title').textContent = CFG.title || 'Chat with us';

  function escapeAttr(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function bubble(role, text) {
    var el = document.createElement('div');
    el.className = 'hc-msg hc-' + role;
    el.textContent = text;
    body.appendChild(el);
    body.scrollTop = body.scrollHeight;
    return el;
  }

  function typing(on) {
    var existing = shadow.querySelector('.hc-typing');
    if (!on) {
      if (existing) existing.remove();
      return;
    }
    if (existing) return;
    var el = document.createElement('div');
    el.className = 'hc-msg hc-bot hc-typing';
    el.innerHTML = '<span></span><span></span><span></span>';
    body.appendChild(el);
    body.scrollTop = body.scrollHeight;
  }

  function note(text) {
    var el = document.createElement('div');
    el.className = 'hc-note';
    el.textContent = text;
    body.appendChild(el);
    body.scrollTop = body.scrollHeight;
  }

  function open() {
    state.open = true;
    host.classList.add('hc-open');
    launcher.setAttribute('aria-expanded', 'true');
    if (!state.messages.length) {
      bubble('bot', CFG.greeting || 'Hi! Ask me anything about this site.');
      state.messages.push({ role: 'assistant', content: CFG.greeting || '' });
    }
    setTimeout(function () {
      input.focus();
    }, 120);
  }

  function close() {
    state.open = false;
    host.classList.remove('hc-open');
    launcher.setAttribute('aria-expanded', 'false');
  }

  launcher.addEventListener('click', function () {
    state.open ? close() : open();
  });
  minBtn.addEventListener('click', close);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && state.open) close();
  });

  input.addEventListener('input', function () {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || state.busy) return;

    bubble('user', text);
    state.messages.push({ role: 'user', content: text });
    input.value = '';
    input.style.height = 'auto';
    state.busy = true;
    send.disabled = true;
    typing(true);

    fetch(CFG.endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': CFG.nonce
      },
      body: JSON.stringify({ message: text, session: state.session })
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { ok: res.ok, data: data };
        });
      })
      .then(function (out) {
        typing(false);
        if (!out.ok) {
          var msg = (out.data && out.data.message) || 'Something went wrong. Please try again.';
          bubble('bot', msg);
          return;
        }
        bubble('bot', out.data.reply);
        state.messages.push({ role: 'assistant', content: out.data.reply });
        if (out.data.escalated && CFG.handover) {
          note('You can also email ' + CFG.handover + ' and a person will pick this up.');
        }
      })
      .catch(function () {
        typing(false);
        bubble('bot', 'I could not reach the server. Check your connection and try again.');
      })
      .then(function () {
        state.busy = false;
        send.disabled = false;
        input.focus();
      });
  });
})();
