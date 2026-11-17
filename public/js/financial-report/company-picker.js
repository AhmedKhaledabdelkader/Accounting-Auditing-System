// Picks a company (search the saved ones) or creates one with its Arabic name (PR 13a). Used by the new-report page and step 1.
import { api, el, ui, field, showErrors } from './core.js';

/** @param {(company: object) => void} onPick */
export function companyPicker(onPick) {
  const results = el('div');
  const search = el('input', { type: 'search', placeholder: ui('company_search'), oninput: () => find() });
  let timer = null;

  function find() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const q = search.value.trim();
      try {
        const list = await api('GET', `/companies?per_page=10${q ? `&search=${encodeURIComponent(q)}` : ''}`);
        results.replaceChildren(list.data.length === 0 ? el('p', { class: 'note' }, ui('company_none_found')) : el('table', {},
          list.data.map((c) => el('tr', { class: 'clickable', onclick: () => onPick(c) },
            el('td', {}, c.name_ar), el('td', { class: 'note' }, [c.cr_number, c.city].filter(Boolean).join(' · ')),
            el('td', {}, el('button', { class: 'btn small', type: 'button' }, ui('choose')))))));
      } catch (e) {
        results.replaceChildren(el('div', { class: 'msg bad' }, e.message));
      }
    }, 250);
  }

  const name = el('input', { 'data-field': 'name_ar', placeholder: ui('company_name_ar') });
  const errors = el('div');
  const form = el('form', { onsubmit: async (e) => {
    e.preventDefault();
    if (!name.value.trim()) { showErrors(form, { message: ui('company_name_required'), errors: {} }, errors); return; }
    try {
      onPick(await api('POST', '/companies', { name_ar: name.value.trim() }));
    } catch (err) { showErrors(form, err, errors); }
  } }, el('div', { class: 'row' }, field(ui('company_new'), name), el('button', { class: 'btn ghost', type: 'submit' }, ui('company_create'))), errors);

  find();
  return el('div', {}, field(ui('company_existing'), search), results, el('h3', {}, ui('or_new_company')), form);
}
