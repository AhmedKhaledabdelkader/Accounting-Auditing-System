// The class picker of the classification pages: a search box over the taxonomy's sub-classes, grouped by statement and
// main class. ClassPicker.open(anchor, options) → Promise of the chosen value, or null when closed without a choice.
// options: taxonomy (sub_class => [statement, main_class, side]), current (marked), extra ([{value, label}] listed first,
// e.g. «this class is right»), words ({search, none, debit, credit}).
(() => {
  // Arabic letters that are written several ways count as one: alef forms, ta marbuta / ha, alef maqsura / ya, no diacritics.
  const norm = s => String(s ?? '').toLowerCase()
    .replace(/[ً-ٰٟـ]/g, '')
    .replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
    .replace(/\s+/g, ' ').trim();
  const esc = s => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

  const css = `
  .cp{position:fixed;z-index:1000;width:min(420px,calc(100vw - 24px));max-height:min(460px,70vh);display:flex;flex-direction:column;
    background:var(--panel,#fff);color:var(--text,#222);border:1px solid var(--line,#ddd);border-radius:14px;box-shadow:0 18px 44px rgba(20,35,36,.22);overflow:hidden}
  .cp input{margin:10px;padding:9px 12px;border-radius:10px;border:1px solid var(--brand,#0a7);font:inherit;font-size:14px;background:var(--panel2,#fafafa);color:inherit}
  .cp .cp-list{overflow:auto;padding:0 6px 8px}
  .cp .cp-group{font-size:12px;color:var(--muted,#777);padding:8px 8px 3px;font-weight:700;position:sticky;top:0;background:var(--panel,#fff)}
  .cp .cp-item{display:flex;justify-content:space-between;gap:10px;padding:7px 10px;border-radius:8px;cursor:pointer;font-size:14px}
  .cp .cp-item small{color:var(--muted,#777);font-size:11.5px;white-space:nowrap}
  .cp .cp-item.on{background:var(--brand-soft,#e6f6f1)}
  .cp .cp-item.cur{font-weight:700}
  .cp .cp-item.extra{color:var(--ok,#1a7)}
  .cp .cp-none{padding:14px;color:var(--muted,#777);font-size:13.5px;text-align:center}`;
  let styled = false, closeOpen = null;

  function open(anchor, o){
    if(closeOpen) closeOpen(null);
    if(!styled){ const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st); styled = true; }
    const w = o.words || {};
    const all = Object.entries(o.taxonomy).map(([sub, [statement, main, side]]) => ({ value: sub, main, statement, side,
      key: norm(`${sub} ${main}`) }));
    const box = document.createElement('div');
    box.className = 'cp';
    box.innerHTML = `<input type="search" placeholder="${esc(w.search || '')}" aria-label="${esc(w.search || '')}"><div class="cp-list" role="listbox"></div>`;
    document.body.appendChild(box);
    const input = box.querySelector('input'), list = box.querySelector('.cp-list');

    // Under the anchor, or above it when there's no room; kept on screen.
    const r = anchor.getBoundingClientRect(), h = box.offsetHeight;
    box.style.top = (r.bottom + h + 8 < innerHeight ? r.bottom + 4 : Math.max(8, r.top - h - 4)) + 'px';
    const left = document.documentElement.dir === 'rtl' ? r.right - box.offsetWidth : r.left;
    box.style.left = Math.min(Math.max(8, left), innerWidth - box.offsetWidth - 8) + 'px';

    let shown = [], at = 0;
    function draw(){
      const words = norm(input.value).split(' ').filter(Boolean);
      const hits = all.filter(c => words.every(x => c.key.includes(x)));
      const extra = words.length ? [] : (o.extra || []);
      shown = [...extra.map(e => ({ ...e, extra: true })), ...hits];
      at = Math.max(0, shown.findIndex(c => c.value === o.current && !c.extra));
      if(words.length || !o.current) at = 0;
      let html = '', group = null;
      shown.forEach((c, i) => {
        const g = c.extra ? '' : `${c.statement} › ${c.main}`;
        if(!c.extra && g !== group){ html += `<div class="cp-group">${esc(g)}</div>`; group = g; }
        html += `<div class="cp-item${c.extra ? ' extra' : ''}${c.value === o.current && !c.extra ? ' cur' : ''}" data-i="${i}" role="option">`
          + `<span>${esc(c.label ?? c.value)}</span>${c.side ? `<small>${esc(w[c.side] || '')}</small>` : ''}</div>`;
      });
      list.innerHTML = html || `<div class="cp-none">${esc(w.none || '')}</div>`;
      mark();
    }
    function mark(){
      list.querySelectorAll('.cp-item').forEach(el => el.classList.toggle('on', Number(el.dataset.i) === at));
      list.querySelector(`.cp-item[data-i="${at}"]`)?.scrollIntoView({ block: 'nearest' });
    }

    return new Promise(resolve => {
      const done = v => {
        closeOpen = null;
        document.removeEventListener('mousedown', outside, true);
        box.remove();
        resolve(v);
      };
      closeOpen = done;
      const outside = e => { if(!box.contains(e.target)) done(null); };
      document.addEventListener('mousedown', outside, true);
      input.oninput = draw;
      input.onkeydown = e => {
        if(e.key === 'ArrowDown'){ e.preventDefault(); at = Math.min(shown.length - 1, at + 1); mark(); }
        else if(e.key === 'ArrowUp'){ e.preventDefault(); at = Math.max(0, at - 1); mark(); }
        else if(e.key === 'Enter'){ e.preventDefault(); if(shown[at]) done(shown[at].value); }
        else if(e.key === 'Escape'){ e.preventDefault(); done(null); }
      };
      list.onclick = e => { const el = e.target.closest('.cp-item'); if(el) done(shown[Number(el.dataset.i)].value); };
      draw();
      input.focus();
    });
  }

  window.ClassPicker = { open, norm };
})();
