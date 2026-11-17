// Each user's own column order (partials/theme-head): a table marked data-columns="<name>" gets headers that can be
// dragged to another place; the order is saved on the account (PUT /account/preferences, `columns.<name>`) and applied
// on every visit, also to rows a page draws later (a MutationObserver re-orders new rows as they come).
//
// Columns are the cells of the first header row; one spanning several columns (colspan) moves with its own cells in the
// second header row and in every row, where a cell may span a column's own columns but never two columns. A header with data-col names the column (else its position does); one with
// data-fixed, a checkbox or no text (a row's buttons) stays where it is, the others take the remaining places.
(() => {
  const C = window.TableColumnsConfig || { prefs: {}, url: null, words: {} };
  const saved = name => C.prefs?.columns?.[name] ?? null;

  function headerRows(table){
    const rows = [...table.rows];
    const head = table.tHead ? [...table.tHead.rows] : [];
    if(head.length) return head;
    const lead = [];
    for(const r of rows){ if(r.cells.length && [...r.cells].every(c => c.tagName === 'TH')) lead.push(r); else break; }
    return lead;
  }

  // The table's columns as they are drawn: [{key, span, fixed, cell, subs}], or null when its header can't be read.
  function readUnits(table){
    const [top, second] = headerRows(table);
    if(!top || headerRows(table).length > 2) return null;
    let leaf = 0;
    const subs = second ? [...second.cells] : [];
    const units = [...top.cells].map((cell, i) => {
      const span = cell.colSpan || 1;
      const u = { key: cell.dataset.col || `c${i}`, span, start: leaf, cell, subs: span > 1 ? subs.splice(0, span) : [],
        fixed: 'fixed' in cell.dataset || !!cell.querySelector('input[type=checkbox]') || !cell.textContent.trim() };
      leaf += span;
      return u;
    });
    if(subs.length) return null;   // a second header row that doesn't match the first
    units.leaves = leaf;
    return units;
  }

  // The order to draw: the movable columns in the user's order (new ones after them), each in a movable place.
  function arrange(units, order){
    const movable = units.filter(u => !u.fixed);
    const byKey = new Map(movable.map(u => [u.key, u]));
    const wanted = (order || []).filter(k => byKey.has(k)).map(k => byKey.get(k));
    const rest = movable.filter(u => !wanted.includes(u));
    const queue = [...wanted, ...rest];
    return units.map(u => u.fixed ? u : queue.shift());
  }

  function attach(table){
    const name = table.dataset.columns;
    let units = null, final = null, sig = '';
    const observer = new MutationObserver(() => apply());

    function apply(){
      observer.disconnect();
      try{
        const top = headerRows(table)[0];
        if(top && top.cells[0] && top.cells[0].dataset.u === undefined){   // a header drawn (again): read it
          units = readUnits(table);
          if(units) units.forEach((u, i) => { u.cell.dataset.u = i; });
          if(units) wire();
        }
        if(!units) return;
        final = arrange(units, saved(name));
        sig = final.map(u => u.key).join('|');
        // The header: the top row's cells, then the second row's, in the order to draw.
        const [row1, row2] = headerRows(table);
        if(row1.dataset.cols !== sig){
          final.forEach(u => row1.appendChild(u.cell));
          if(row2) final.forEach(u => u.subs.forEach(c => row2.appendChild(c)));
          row1.dataset.cols = sig;
        }
        const leafOrder = final.flatMap(u => Array.from({ length: u.span }, (_, k) => u.start + k));
        const natural = leafOrder.every((n, i) => n === i);
        const heads = new Set(headerRows(table));
        const unitAt = [];
        units.forEach(u => { for(let k = 0; k < u.span; k++) unitAt[u.start + k] = u; });
        for(const tr of table.rows){
          if(heads.has(tr) || tr.dataset.cols === sig) continue;
          const cells = [...tr.cells];
          tr.dataset.cols = sig;
          // Each cell's first column, counted as drawn (a cell may span a column's own columns, never two columns).
          let leaf = 0;
          cells.forEach(c => { if(c.dataset.c === undefined) c.dataset.c = leaf; leaf += c.colSpan || 1; });
          if(leaf !== units.leaves || cells.some(c => unitAt[Number(c.dataset.c)] !== unitAt[Number(c.dataset.c) + (c.colSpan || 1) - 1])) continue;   // an empty-state row, a sum
          const start = c => Number(c.dataset.c);
          if(natural && cells.every((c, i) => i === 0 || start(c) > start(cells[i - 1]))) continue;
          const pos = new Map(leafOrder.map((n, i) => [n, i]));
          cells.slice().sort((a, b) => pos.get(start(a)) - pos.get(start(b))).forEach(c => tr.appendChild(c));
        }
      } finally {
        observer.observe(table, { childList: true, subtree: true });
      }
    }

    // Dragging a header onto another: before it or after it, by the half of it the pointer is over.
    let dragged = null;
    function wire(){
      for(const u of units){
        if(u.fixed) continue;
        u.cell.draggable = true;
        u.cell.classList.add('col-drag');
        if(!u.cell.title) u.cell.title = C.words.drag || '';
        u.cell.ondragstart = e => { dragged = u.key; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', u.key); u.cell.classList.add('col-moving'); };
        u.cell.ondragend = () => { dragged = null; table.querySelectorAll('.col-moving,.col-before,.col-after').forEach(el => el.classList.remove('col-moving', 'col-before', 'col-after')); };
        u.cell.ondragover = e => {
          if(!dragged || dragged === u.key) return;
          e.preventDefault();
          const after = side(u.cell, e);
          u.cell.classList.toggle('col-after', after); u.cell.classList.toggle('col-before', !after);
        };
        u.cell.ondragleave = () => u.cell.classList.remove('col-before', 'col-after');
        u.cell.ondrop = e => {
          e.preventDefault();
          if(!dragged || dragged === u.key) return;
          const keys = final.filter(x => !x.fixed).map(x => x.key).filter(k => k !== dragged);
          keys.splice(keys.indexOf(u.key) + (side(u.cell, e) ? 1 : 0), 0, dragged);
          save(name, keys);
          apply();
        };
      }
    }
    // «After» is the side the reading goes on: the left half in Arabic, the right in English.
    function side(cell, e){
      const r = cell.getBoundingClientRect(), right = e.clientX > r.left + r.width / 2;
      return getComputedStyle(cell).direction === 'rtl' ? !right : right;
    }

    apply();
  }

  let toastTimer = null;
  function toast(text, bad){
    let el = document.getElementById('colToast');
    if(!el){ el = document.createElement('div'); el.id = 'colToast'; el.setAttribute('role', 'status'); document.body.appendChild(el); }
    el.textContent = text; el.className = bad ? 'on bad' : 'on';
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { el.className = ''; }, 1800);
  }

  async function save(name, keys){
    C.prefs = C.prefs && !Array.isArray(C.prefs) ? C.prefs : {};
    (C.prefs.columns ??= {})[name] = keys;
    if(!C.url) return;
    try{
      const res = await fetch(C.url, { method: 'PUT', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: `columns.${name}`, value: keys }) });
      if(!res.ok) throw new Error(res.status);
      toast(C.words.saved || '✓');
    }catch(e){ toast(C.words.failed || '⚠', true); }
  }

  // Tables already on the page and those a script adds later.
  const seen = new WeakSet();
  const scan = () => document.querySelectorAll('table[data-columns]').forEach(t => { if(!seen.has(t)){ seen.add(t); attach(t); } });
  const start = () => { scan(); new MutationObserver(scan).observe(document.body, { childList: true, subtree: true }); };
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
})();
