// Arabic names of what the state refers to by key (lines, cells, missing figures), and the step where each problem is fixed.
import { ui, label, name } from './core.js';
import { NOTE_FIELDS } from './fields.js';

/** Every row of the statements with a key: key → row. */
function rowsByKey(state) {
  const out = {};
  for (const s of ['financial_position', 'comprehensive_income', 'cash_flows']) {
    for (const r of state.statements[s] ?? []) if (r.key) out[r.key] = r;
  }
  return out;
}

export function lineLabel(state, key) {
  if (!key) return null;
  return rowsByKey(state)[key]?.label_ar ?? label(`notes.${key}`) ?? null;
}

/** A movement-table cell `<schedule>.<row>[.<column>]`, or a schedule side `<schedule>.cost|accumulated`. */
export function cellLabel(state, key) {
  const [sk, row, col] = key.split('.');
  const s = (state.schedules ?? []).find((x) => x.key === sk);
  if (!s || !row) return null;
  if (row === 'cost' || row === 'accumulated') return `${s.title_ar} — ${ui(row === 'cost' ? 'side_cost' : 'side_accumulated')}`;
  const r = s.rows.find((x) => x.key === row);
  const c = col ? s.columns.find((x) => x.key === col) : null;
  return [s.title_ar, r?.label_ar ?? label(`schedule_rows.${row}`), c?.label_ar].filter(Boolean).join(' — ');
}

/** An asset note's category (a column of its movement table). */
export function categoryLabel(state, key) {
  for (const sch of state.schedules ?? []) {
    const c = sch.columns?.find((x) => x.key === key);
    if (c && sch.kind === 'asset') return c.label_ar;
  }
  return ui('unknown');
}

export function missingLabel(state, m) {
  const k = m.key;
  let what;
  // The company's details still needed, each by its field's name (the profile's fields, never codes).
  const COMPANY_FIELDS = { name_ar: 'company_name_ar', cr_number: 'company_cr_number', cr_date_hijri: 'company_cr_date_hijri', cr_date: 'company_cr_date',
    unified_number: 'company_unified_number', city: 'company_city', head_office: 'company_head_office', activity: 'company_activity' };
  if (k === 'notes.company') {
    const fields = (m.fields ?? []).map((f) => COMPANY_FIELDS[f]).filter(Boolean);
    what = fields.length ? `${ui('missing_company_details')}: ${fields.map((f) => ui(f)).join('، ')}` : ui('missing_company_details');
  }
  else if (k.startsWith('notes.')) what = ui('missing_note', { note: name(`notes.${k.slice(6)}`) });
  else if (k.startsWith('comparative.')) what = `${name(`statements.${k.slice(12)}`)} — ${name('scopes.comparative')}`;
  else if (['comprehensive_income', 'changes_in_equity', 'cash_flows'].includes(k)) what = name(`statements.${k}`);
  else what = cellLabel(state, k) ?? lineLabel(state, k) ?? ui('unknown');
  return { what, why: name(`missing_reasons.${m.reason}`) };
}


/** The wizard step where a check is dealt with. */
export function stepForCheck(c) {
  if (c.scope === 'comparative' && !['sofp_unbalanced', 'rounding_difference'].includes(c.code)) return 'prior_year';
  if (c.code === 'period_invalid') return c.kind === 'approval_date' ? 'notes' : (c.kind === 'comparative_gap' ? 'prior_year' : 'company');
  if (c.input_key) return NOTE_FIELDS.test(c.input_key) ? 'notes' : 'movements';
  const by = {
    accounts: ['unclassified_rows', 'rows_need_review', 'unmapped_accounts', 'row_reconciliation', 'wrong_side_balance', 'periodic_inventory_pair',
      'adjustment_unmapped_row', 'tb_unbalanced', 'tb_partial', 'empty_group', 'disposal_unmatched', 'disposal_pending', 'ppe_additions_depreciation'],
    opening: ['opening_vs_prior_closing', 'soce_opening_mismatch', 'cash_flow_opening_mismatch', 'approved_opening', 'approved_opening_sign', 'approved_opening_zero',
      'approved_opening_unavailable'],
    notes: ['note_total_mismatch', 'capital_note_mismatch', 'going_concern_indicator', 'same_party_multiple_accounts', 'note_reference_missing', 'edited_text_outdated'],
    review: ['sofp_unbalanced', 'net_income_mismatch', 'post_closing_tb', 'pl_from_movements', 'rounding_difference', 'run_changed'],
  };
  for (const [step, codes] of Object.entries(by)) if (codes.includes(c.code)) return step;
  // A check on a note (the partners' table, the related parties, …) is dealt with on the notes step.
  return c.note ? 'notes' : 'movements';
}

export function stepForMissing(m) {
  if (m.key === 'notes.company') return 'company';
  if (['prior_year_source_required', 'prior_year_incomplete'].includes(m.reason)) return 'prior_year';
  if (m.reason === 'category_split_required') return 'accounts';
  if (m.reason === 'note_incomplete' || m.reason === 'company_required') return 'notes';
  if (m.reason === 'post_closing_tb') return 'accounts';
  return 'movements';
}
