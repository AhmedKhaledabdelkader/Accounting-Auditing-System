// The chat between the office and a client: one widget, the general thread or one request's. New messages are fetched
// every few seconds while the page is open; what the other side wrote is marked read when it is shown. Text goes in with
// textContent only, never as HTML.
(() => {
  const root = document.getElementById('chat');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const log = root.querySelector('.log');
  const form = root.querySelector('form');
  const box = form.querySelector('textarea');
  const title = root.querySelector('[data-title]');
  const sub = root.querySelector('[data-sub]');
  const tabs = root.querySelectorAll('[data-tab]');
  const POLL_MS = 4000;

  let thread = null;          // a request's id; null = the general chat
  let last = 0;               // the last message id on screen
  let timer = null;
  let busy = false;

  const fmt = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { dateStyle: 'short', timeStyle: 'short' });
  const url = (path, params) => {
    const u = new URL(path, location.origin);
    Object.entries(params).forEach(([k, v]) => v !== null && v !== undefined && u.searchParams.set(k, v));
    return u.toString();
  };

  function draw(messages) {
    const stick = log.scrollTop + log.clientHeight >= log.scrollHeight - 40 || last === 0;
    log.querySelector('.none')?.remove();
    for (const m of messages) {
      const node = document.createElement('div');
      if (m.kind === 'event') {
        node.className = 'event';
        node.textContent = m.body;
      } else {
        node.className = 'bubble' + (m.mine ? ' mine' : '');
        const who = document.createElement('span');
        who.className = 'who';
        who.textContent = m.name;
        const body = document.createElement('span');
        body.textContent = m.body;
        const time = document.createElement('time');
        time.textContent = m.at ? fmt.format(new Date(m.at)) : '';
        node.append(who, body, time);
      }
      log.append(node);
      last = Math.max(last, m.id);
    }
    if (!log.children.length) {
      const none = document.createElement('p');
      none.className = 'none';
      none.textContent = cfg.ui.empty;
      log.append(none);
    }
    if (stick) log.scrollTop = log.scrollHeight;
  }

  function badges(unread) {
    tabs.forEach((t) => {
      const key = t.dataset.tab === 'general' ? 'general' : t.dataset.tab;
      const n = key === (thread === null ? 'general' : String(thread)) ? 0 : (unread[key] || 0);
      let dot = t.querySelector('.dot');
      if (n && !dot) { dot = document.createElement('span'); dot.className = 'dot'; t.append(dot); }
      if (dot) { if (n) dot.textContent = n; else dot.remove(); }
    });
    document.querySelectorAll('[data-chat-for]').forEach((b) => {
      const n = b.dataset.chatFor === String(thread) ? 0 : (unread[b.dataset.chatFor] || 0);
      let dot = b.querySelector('.dot');
      if (n && !dot) { dot = document.createElement('span'); dot.className = 'dot'; b.append(dot); }
      if (dot) { if (n) dot.textContent = n; else dot.remove(); }
    });
  }

  async function poll() {
    if (busy || document.hidden) return;
    busy = true;
    const mine = thread;
    try {
      const res = await fetch(url(cfg.messages, { request: thread, after: last }), { headers: { Accept: 'application/json' } });
      if (res.ok && mine === thread) {
        const data = await res.json();
        if (data.messages.length) draw(data.messages);
        badges(data.unread || {});
      }
    } catch (e) { /* the next poll tries again */ } finally { busy = false; }
  }

  function open(id, label) {
    thread = id;
    last = 0;
    log.replaceChildren();
    title.textContent = id === null ? cfg.ui.general : label;
    sub.textContent = id === null ? cfg.ui.general_hint : cfg.ui.request_hint;
    tabs.forEach((t) => t.classList.toggle('on', id === null ? t.dataset.tab === 'general' : t.dataset.tab === 'request'));
    const requestTab = root.querySelector('[data-tab="request"]');
    if (requestTab) { requestTab.hidden = id === null; }
    poll();
    box.focus({ preventScroll: true });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = box.value.trim();
    if (!body) return;
    box.disabled = true;
    try {
      const res = await fetch(cfg.send, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ body, request: thread }) });
      if (res.ok) { box.value = ''; busy = false; await poll(); } else { alert(cfg.ui.failed); }
    } catch (err) { alert(cfg.ui.failed); } finally { box.disabled = false; box.focus(); }
  });
  box.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });

  tabs.forEach((t) => t.addEventListener('click', () => { if (t.dataset.tab === 'general') open(null); }));
  document.querySelectorAll('[data-chat-for]').forEach((b) => b.addEventListener('click', () => {
    open(Number(b.dataset.chatFor), b.dataset.chatTitle);
    root.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }));

  open(cfg.start ?? null, cfg.startTitle || '');
  timer = setInterval(poll, POLL_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  window.addEventListener('pagehide', () => clearInterval(timer));
})();
