// One loader for everything that takes time: a card over the page with a ring and the percentage, what is going on, the time
// elapsed, and a hint. Loaded on every page (partials/theme-head); words come from window.BusyConfig (lang/*/busy.php).
//
//   Busy.request(url, {method, body, headers, overlay, title, onUpload})  → Promise<{ok, status, text, json, url}>
//       sends with the real UPLOAD percentage (XMLHttpRequest), then, while the server works, a PROCESSING percentage that
//       eases toward 95% and reaches 100% when the answer arrives (the server's work cannot be measured: the time shown is).
//   Busy.start({title, hint})  → handle {set(pct, text), phase(text), done(), fail(), hide()}   for any other wait
//
// Automatic: a form that sends a file (and any form or button marked data-busy) shows the loader and is sent with
// Busy.request; the page the server answers with replaces this one, as if it had been sent normally. A link that downloads
// (a[download]) is fetched with its progress and then saved.
(() => {
  const W = () => window.BusyConfig || {};
  const word = (k, vars = {}) => String(W().words?.[k] ?? '').replace(/:([a-z_]+)/g, (m, n) => (n in vars ? vars[n] : m));
  const fmt = (ms) => { const s = Math.floor(ms / 1000); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; };
  const digits = (n) => (document.documentElement.lang === 'ar' ? String(n).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[d]) : String(n));
  const RING = 2 * Math.PI * 52;

  let current = null;   // one loader at a time

  function start(opts = {}) {
    current?.hide();
    const root = document.createElement('div');
    root.className = 'busy';
    root.setAttribute('role', 'alertdialog');
    root.setAttribute('aria-live', 'polite');
    root.setAttribute('aria-busy', 'true');
    root.innerHTML = `<div class="busy-card">
      <div class="busy-ring"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="t" cx="60" cy="60" r="52"/><circle class="v" cx="60" cy="60" r="52" stroke-dasharray="${RING}" stroke-dashoffset="${RING}"/></svg><b class="busy-pct">٠%</b></div>
      <div class="busy-title"></div>
      <div class="busy-text"></div>
      <div class="busy-bar"><i></i></div>
      <div class="busy-time"><span>${word('elapsed')} <b class="busy-elapsed">0:00</b></span></div>
      <p class="busy-hint"></p></div>`;
    const q = (s) => root.querySelector(s);
    q('.busy-title').textContent = opts.title || word('working');
    q('.busy-hint').textContent = opts.hint ?? word('hint');
    document.body.append(root);
    document.documentElement.classList.add('busy-on');

    const began = Date.now();
    let pct = 0;
    const draw = () => {
      q('.busy-pct').textContent = `${digits(Math.round(pct))}%`;
      q('.v').style.strokeDashoffset = String(RING * (1 - pct / 100));
      q('.busy-bar i').style.width = `${pct}%`;
    };
    const tick = setInterval(() => { q('.busy-elapsed').textContent = digits(fmt(Date.now() - began)); }, 1000);
    let creeper = null;
    const handle = {
      /** Shows a percentage (never backwards) and, if given, what is going on. */
      set(p, text) { pct = Math.max(pct, Math.min(100, p)); if (text) q('.busy-text').textContent = text; draw(); },
      /** The server is working: the percentage eases from `from` toward 95% (about `tau` seconds to 63% of the way). */
      process(text, from = pct, tau = 18) {
        handle.set(from, text);
        const t0 = Date.now(), base = pct;
        clearInterval(creeper);
        creeper = setInterval(() => handle.set(base + (95 - base) * (1 - Math.exp(-(Date.now() - t0) / 1000 / tau))), 400);
      },
      done() { clearInterval(creeper); handle.set(100, word('done')); setTimeout(handle.hide, 250); },
      fail() { handle.hide(); },
      hide() {
        clearInterval(tick); clearInterval(creeper); root.remove();
        document.documentElement.classList.remove('busy-on');
        if (current === handle) current = null;
      },
    };
    current = handle;
    handle.set(opts.percent ?? 0, opts.text || '');
    return handle;
  }

  /** XMLHttpRequest, for the upload progress fetch cannot give. Never rejects for an HTTP status: {ok, status}. */
  function request(url, o = {}) {
    const overlay = o.overlay !== false;
    const h = overlay ? start({ title: o.title || word('sending'), hint: o.hint }) : null;
    // The upload is the first third of the bar when the server then works on it; all of it when nothing follows.
    const UP = o.processing === false ? 100 : 35;
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open(o.method || 'POST', url);
      const headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept-Language': document.documentElement.lang, ...(o.headers || {}) };
      const token = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) || [])[1] || '');
      if (token) headers['X-XSRF-TOKEN'] = token;
      Object.entries(headers).forEach(([k, v]) => xhr.setRequestHeader(k, v));
      xhr.upload.onprogress = (e) => {
        if (!e.lengthComputable) return;
        const p = (e.loaded / e.total) * UP;
        h?.set(p, word('uploading', { done: (e.loaded / 1048576).toFixed(1), total: (e.total / 1048576).toFixed(1) }));
        o.onUpload?.(e.loaded / e.total);
      };
      xhr.upload.onload = () => { if (o.processing !== false) h?.process(o.processText || word('processing'), UP); o.onSent?.(); };
      xhr.onerror = xhr.ontimeout = () => { h?.fail(); reject(new Error(word('network'))); };
      xhr.onload = () => {
        if (xhr.status === 402 && xhr.getResponseHeader('X-AI-Limit')) { h?.hide(); location.href = '/ai-limit'; return; }   // the monthly AI limit is used up
        h?.done();
        let json = null;
        try { json = JSON.parse(xhr.responseText); } catch { /* not JSON */ }
        resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, text: xhr.responseText, json, url: xhr.responseURL, type: xhr.getResponseHeader('content-type') || '' });
      };
      xhr.send(o.body ?? null);
    });
  }

  /** The page the server answered with replaces this one (the address too), as a normal form post would. */
  function show(res) {
    if (res.url) history.replaceState(null, '', res.url);
    document.open();
    document.write(res.text);
    document.close();
  }

  function onSubmit(e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || e.defaultPrevented || form.dataset.busy === 'off') return;
    if ((form.method || 'get').toLowerCase() !== 'post') return;
    const files = [...form.querySelectorAll('input[type=file]')].some((i) => i.files?.length);
    const marked = form.hasAttribute('data-busy') || e.submitter?.hasAttribute('data-busy');
    if (!files && !marked) return;
    if (!form.checkValidity()) return;   // the browser shows what is missing
    e.preventDefault();
    const body = new FormData(form, e.submitter);
    const title = form.dataset.busyTitle || e.submitter?.dataset.busyTitle || (files ? word('sending') : word('working'));
    request(form.action, { method: 'POST', body, title, hint: form.dataset.busyHint, processing: true, headers: { Accept: 'text/html' } })
      .then((res) => { if (res.type.includes('html')) show(res); else location.reload(); })
      .catch((err) => { alert(err.message); });
  }

  /** A link that downloads: fetched with its progress (the file is made on the server first), then saved. */
  async function onClick(e) {
    const a = e.target.closest?.('a[download][href]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || a.dataset.busy === 'off') return;
    const url = new URL(a.href, location.href);
    if (url.origin !== location.origin || a.href.endsWith('#')) return;
    e.preventDefault();
    const h = start({ title: a.dataset.busyTitle || word('preparing'), hint: word('download_hint') });
    h.process(word('generating'), 0, 8);
    try {
      const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept-Language': document.documentElement.lang } });
      if (!res.ok) throw new Error(word('failed'));
      const total = Number(res.headers.get('content-length')) || 0;
      const reader = res.body?.getReader();
      const chunks = [];
      let got = 0;
      if (reader) {
        h.set(60, word('receiving'));
        for (;;) {
          const { done, value } = await reader.read();
          if (done) break;
          chunks.push(value); got += value.length;
          if (total) h.set(60 + 38 * (got / total));
        }
      }
      const blob = reader ? new Blob(chunks, { type: res.headers.get('content-type') || '' }) : await res.blob();
      const disp = res.headers.get('content-disposition') || '';
      const name = decodeURIComponent((disp.match(/filename\*=UTF-8''([^;]+)/i) || [])[1] || (disp.match(/filename="?([^";]+)"?/i) || [])[1] || a.getAttribute('download') || 'file');
      const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: name });
      document.body.append(link); link.click(); link.remove();
      setTimeout(() => URL.revokeObjectURL(link.href), 10000);
      h.done();
    } catch (err) {
      h.fail();
      alert(err.message || word('failed'));
    }
  }

  window.Busy = { start, request, show };
  // Before a page is replaced by document.write the old listeners go; adding them again is harmless either way.
  if (window.__busySubmit) document.removeEventListener('submit', window.__busySubmit);
  if (window.__busyClick) document.removeEventListener('click', window.__busyClick);
  document.addEventListener('submit', (window.__busySubmit = onSubmit));
  document.addEventListener('click', (window.__busyClick = onClick));
  // The page was left with the loader up (back button from the next page): drop it.
  addEventListener('pageshow', (e) => { if (e.persisted) current?.hide(); });
})();
