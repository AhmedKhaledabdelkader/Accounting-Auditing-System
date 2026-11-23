// The PPE movement by last year's categories (state.ppe_prior), as a plain table of numbers: one column per category of last
// year's note that has cost in either year, then the total of those columns (so it always ties to them), each row of the
// movement. No DOM here: movements.js draws it, and it can be checked on its own.

export const PPE_ROWS = ['cost_opening', 'additions', 'disposals', 'cost_closing', 'acc_opening', 'depreciation', 'acc_disposals', 'acc_closing', 'nbv_closing', 'nbv_opening'];

/** One category's figure of a row: this year's cells; the net book values from cost less accumulated depreciation. */
function cell(current, key) {
  const v = current ?? {};
  const net = (cost, acc) => (v[cost] == null || v[acc] == null ? null : v[cost] - v[acc]);
  if (key === 'nbv_opening') return net('cost_opening', 'acc_opening');
  if (key === 'nbv_closing') return v.nbv_closing ?? net('cost_closing', 'acc_closing');
  return v[key] ?? null;
}

/**
 * @param {object} prior state.ppe_prior
 * @param {object} schedule the PPE schedule (state.schedules, key `ppe`): its rows' total cells, to compare with
 * @returns {{columns: {key: string, label: string}[], rows: {key: string, cells: (number|null)[], total: number|null, schedule_total: number|null, ties: boolean}[]}}
 */
export function ppeByPrior(prior, schedule) {
  const columns = (prior?.categories ?? []).filter((c) => [c.current?.cost_closing, c.current?.cost_opening, c.last?.cost_closing]
    .some((x) => Math.abs(x ?? 0) > 0.5));
  const byKey = Object.fromEntries((schedule?.rows ?? []).map((r) => [r.key, r]));
  const rows = PPE_ROWS.filter((k) => byKey[k] || k === 'nbv_opening').map((key) => {
    const cells = columns.map((c) => cell(c.current, key));
    const total = cells.some((x) => x === null) ? null : Math.round(cells.reduce((a, b) => a + b, 0) * 100) / 100;
    const scheduleTotal = byKey[key]?.cells?.total?.amount ?? null;
    return { key, cells, total, schedule_total: scheduleTotal,
      ties: total === null || scheduleTotal === null || Math.abs(total - scheduleTotal) < 2 };
  });
  return { columns: columns.map((c) => ({ key: c.key, label: c.label })), rows };
}
