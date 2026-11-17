// Step 7 (PR 13c): the report's versions, each a frozen copy of the report when it was saved (Q20). A draft can always be
// saved; a final only when nothing blocks it. `#versions/<n>` shows version n as it was, read-only. Each version downloads
// as an Excel file of the statements (PR 14), a Word file of the notes (PR 15) and a PDF of both (PR 16).
import { C, api, el, ui, name, box, when, runRows } from '../core.js';
import { statementsView } from '../statements-view.js';

const base = () => `/financial-reports/${encodeURIComponent(C.report_id)}/versions`;

/** The version's Excel (PR 14), Word (PR 15) and PDF (PR 16) files, made from the version as it was saved. */
const xlsxLink = (n, cls = 'btn ghost small') => el('a', { class: cls, href: `${C.api}${base()}/${encodeURIComponent(n)}/export.xlsx`, download: '', 'data-download': n }, ui('download_xlsx'));
const docxLink = (n, cls = 'btn ghost small') => el('a', { class: cls, href: `${C.api}${base()}/${encodeURIComponent(n)}/export.docx`, download: '', 'data-download-docx': n }, ui('download_docx'));
const pdfLink = (n, cls = 'btn ghost small') => el('a', { class: cls, href: `${C.api}${base()}/${encodeURIComponent(n)}/export.pdf`, download: '', 'data-download-pdf': n }, ui('download_pdf'));

/** «Save a draft» and «Issue a final»; the final is disabled while something blocks it. */
export function versionButtons(ctx, status) {
  const create = async (kind) => {
    if (kind === 'final' && !window.confirm(ui('final_confirm'))) return;
    status.replaceChildren();
    try {
      const v = await api('POST', base(), { kind });
      await ctx.reload();
      ctx.flash = ui('version_saved', { n: v.version, kind: name(`version_kinds.${v.kind}`) });
      if (ctx.step === 'versions') ctx.redraw(); else ctx.go('versions');
    } catch (err) {
      status.append(box('bad', err.all().join(' ')));
      status.scrollIntoView({ block: 'center' });
    }
  };
  const s = ctx.state;
  return el('div', { class: 'toolbar' },
    el('button', { class: 'btn ghost', type: 'button', 'data-version': 'draft', onclick: () => create('draft') }, ui('save_draft')),
    el('button', { class: 'btn', type: 'button', 'data-version': 'final', disabled: !s.can_finalize, onclick: () => create('final') }, ui('issue_final')),
    s.can_finalize ? null : el('span', { class: 'note' }, ui('final_blocked_hint')));
}

async function one(ctx, number) {
  const back = el('div', { class: 'toolbar' }, el('a', { class: 'btn ghost', href: '#versions' }, ui('back_to_versions')));
  let v;
  try {
    v = await api('GET', `${base()}/${encodeURIComponent(number)}`);
  } catch (err) {
    if (err.status !== 404) throw err;
    return el('div', {}, back, box('bad', err.message));
  }
  const byRow = await runRows();
  back.append(xlsxLink(v.version, 'btn'), docxLink(v.version, 'btn'), pdfLink(v.version, 'btn'));
  return el('div', {}, back,
    box(v.kind === 'final' ? 'ok' : 'warn', el('b', {}, ui('version_header', { n: v.version, kind: name(`version_kinds.${v.kind}`), date: when(v.created_at) })),
      v.superseded_by ? ` ${ui('superseded', { n: v.superseded_by })}` : '', el('div', {}, ui('version_readonly'))),
    statementsView(v.payload, byRow));
}

export async function render(ctx) {
  if (ctx.sub) return one(ctx, ctx.sub);
  const s = ctx.state;
  const list = (await api('GET', base())).data;
  const status = el('div');
  const wrap = el('div', {}, status);

  if (s.versions.final) {
    wrap.append(s.changed_since_final
      ? box('warn', ui('changed_since_final', { n: s.versions.final.version }))
      : box('ok', ui('unchanged_since_final', { n: s.versions.final.version })));
  }
  wrap.append(el('div', { class: 'panel' }, el('h2', {}, ui('versions_title')), el('p', { class: 'note' }, ui('versions_hint')),
    s.can_finalize ? null : el('ul', {}, s.blocking.map((b) => el('li', { class: 'note' }, `${b.label_ar} (${b.count})`))),
    versionButtons(ctx, status),
    list.length
      ? el('table', {}, el('tr', {}, el('th', {}, ui('col_version')), el('th', {}, ui('col_kind')), el('th', {}, ui('col_created')), el('th')),
        list.map((v) => el('tr', { 'data-version-row': v.version },
          el('td', {}, v.version),
          el('td', {}, el('span', { class: `badge ${v.kind === 'final' ? 'b-ok' : 'b-info'}` }, name(`version_kinds.${v.kind}`)),
            v.superseded_by ? el('span', { class: 'note' }, ' ', ui('superseded', { n: v.superseded_by })) : null),
          el('td', {}, when(v.created_at)),
          el('td', {}, el('div', { class: 'toolbar', style: 'margin:0' }, el('a', { class: 'btn ghost small', href: `#versions/${v.version}` }, ui('view')), xlsxLink(v.version), docxLink(v.version), pdfLink(v.version))))))
      : el('p', { class: 'note' }, ui('no_versions'))));
  wrap.append(el('div', { class: 'toolbar' }, el('button', { class: 'btn ghost', onclick: () => ctx.go('review') }, ui('prev_step'))));
  return wrap;
}
