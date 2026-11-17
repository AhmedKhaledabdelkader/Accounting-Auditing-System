// Every dropdown can be searched (R2), on every page (partials/theme-head), also the ones a page draws later. The
// <select> stays the real field (its value, its change event, the tests): a click or a key on it opens a panel with a
// search box and the options instead of the browser's list; picking one sets the select's value and fires input and
// change as the browser would. A list box (multiple, or size > 1) keeps its own look.
(() => {
  const W = window.SelectSearchConfig?.words || {};
  let open = null;   // {select, panel, input, list, items, active}

  // Arabic letters that are written several ways match each other; diacritics and tatweel are ignored.
  const norm = s => String(s ?? '').toLowerCase()
    .replace(/[ً-ْـ]/g, '').replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
    .replace(/\s+/g, ' ').trim();

  const searchable = s => s instanceof HTMLSelectElement && !s.multiple && !(s.size > 1) && !s.disabled
    && !('nativeSelect' in s.dataset);

  function close(focusSelect = true){
    if(!open) return;
    const { select, panel } = open;
    panel.remove();
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);
    open = null;
    if(focusSelect) select.focus({ preventScroll: true });
  }

  function place(){
    if(!open) return;
    const { select, panel } = open;
    const r = select.getBoundingClientRect();
    if(!r.width && !r.height){ close(false); return; }
    const width = Math.min(Math.max(r.width, 240), window.innerWidth - 16);
    const below = window.innerHeight - r.bottom, above = r.top;
    const up = below < 260 && above > below;
    panel.style.width = width + 'px';
    panel.style.maxHeight = Math.max(160, Math.min(360, (up ? above : below) - 12)) + 'px';
    // Right-aligned with the field in a right-to-left page, kept on the screen.
    const rtl = getComputedStyle(select).direction === 'rtl';
    let left = rtl ? r.right - width : r.left;
    left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
    panel.style.left = left + 'px';
    panel.style.top = up ? '' : (r.bottom + 4) + 'px';
    panel.style.bottom = up ? (window.innerHeight - r.top + 4) + 'px' : '';
  }

  function draw(){
    const { select, list, input } = open;
    const q = norm(input.value);
    list.replaceChildren();
    open.items = [];
    let group = null;
    for(const opt of select.options){
      if(opt.hidden) continue;
      const label = opt.textContent.trim();
      const g = opt.parentElement instanceof HTMLOptGroupElement ? opt.parentElement : null;
      if(q && !norm(label).includes(q) && !(g && norm(g.label).includes(q))) continue;
      if(g !== group){
        group = g;
        if(g){ const h = document.createElement('div'); h.className = 'ss-group'; h.textContent = g.label; list.append(h); }
      }
      const item = document.createElement('div');
      item.className = 'ss-option' + (opt.selected ? ' selected' : '') + (opt.disabled ? ' disabled' : '');
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
      item.textContent = label || ' ';
      if(!opt.disabled){
        const at = open.items.length;
        item.addEventListener('mousedown', e => e.preventDefault());
        item.addEventListener('click', () => pick(opt));
        item.addEventListener('mousemove', () => mark(at));
        open.items.push({ item, opt });
      }
      list.append(item);
    }
    if(!open.items.length){
      const none = document.createElement('div');
      none.className = 'ss-empty';
      none.textContent = W.empty || '—';
      list.append(none);
    }
    const selected = open.items.findIndex(x => x.opt.selected);
    mark(q ? 0 : Math.max(0, selected));
  }

  function mark(i){
    if(!open || !open.items.length) return;
    open.active = Math.max(0, Math.min(i, open.items.length - 1));
    open.items.forEach((x, n) => x.item.classList.toggle('active', n === open.active));
    open.items[open.active].item.scrollIntoView({ block: 'nearest' });
  }

  function pick(opt){
    const { select } = open;
    close();
    if(select.value === opt.value && opt.selected) return;
    select.value = opt.value;
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function show(select, typed = ''){
    close(false);
    const panel = document.createElement('div');
    panel.className = 'ss-panel';
    panel.dir = getComputedStyle(select).direction;
    const input = document.createElement('input');
    input.type = 'search';
    input.className = 'ss-search';
    input.placeholder = W.placeholder || '';
    input.setAttribute('aria-label', W.placeholder || '');
    input.value = typed;
    const list = document.createElement('div');
    list.className = 'ss-list';
    list.setAttribute('role', 'listbox');
    panel.append(input, list);
    document.body.append(panel);
    open = { select, panel, input, list, items: [], active: 0 };
    input.addEventListener('input', draw);
    input.addEventListener('keydown', e => {
      if(e.key === 'ArrowDown'){ e.preventDefault(); mark(open.active + 1); }
      else if(e.key === 'ArrowUp'){ e.preventDefault(); mark(open.active - 1); }
      else if(e.key === 'Enter'){ e.preventDefault(); const x = open.items[open.active]; if(x) pick(x.opt); }
      else if(e.key === 'Escape'){ e.preventDefault(); close(); }
      else if(e.key === 'Tab'){ close(); }
    });
    draw();
    place();
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    input.focus({ preventScroll: true });
  }

  // A click on the field opens the panel in place of the browser's list.
  document.addEventListener('mousedown', e => {
    const select = e.target.closest?.('select');
    if(open && !open.panel.contains(e.target) && select !== open.select) close(false);
    if(!searchable(select) || e.button !== 0) return;
    e.preventDefault();
    if(open && open.select === select){ close(); return; }
    select.focus({ preventScroll: true });
    show(select);
  }, true);

  // The keys that open the browser's list open the panel; a letter opens it with the search started.
  document.addEventListener('keydown', e => {
    const select = e.target;
    if(!searchable(select) || open) return;
    const opens = e.key === ' ' || e.key === 'Enter' || (e.altKey && e.key === 'ArrowDown') || e.key === 'F4';
    const letter = e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey && e.key !== ' ';
    if(!opens && !letter) return;
    e.preventDefault();
    show(select, letter ? e.key : '');
  }, true);
})();
