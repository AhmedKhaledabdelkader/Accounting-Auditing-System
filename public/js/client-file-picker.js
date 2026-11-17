// The accountant picks a file the client already uploaded in the portal, instead of uploading it again: the files of one client
// that can serve a purpose (GET /clients/{company}/files?purpose=), the likeliest first, the suggested one marked. One module for
// the classifier page and the report wizard; every word comes from `labels` (each page's own language file), text is set with
// textContent only.
//
//   const node = await clientFilePicker({ companyId, purpose: 'trial_balance', labels, onPick: (file) => ... });
//
// `file` is {id, name, size, ...}; the caller sends `client_file_id` with the id to the endpoint that takes the upload.

const fmt = (iso) => (iso ? new Intl.DateTimeFormat(document.documentElement.lang || undefined, { dateStyle: 'medium' }).format(new Date(iso)) : '');

export async function clientFilePicker({ companyId, purpose, labels, onPick, selectedId = null, year = null }) {
  const box = document.createElement('div');
  box.className = 'client-files';
  box.dataset.clientFiles = purpose;
  const title = document.createElement('div');
  title.className = 'client-files-title';
  title.textContent = labels.title;
  box.append(title);
  const note = (text) => {
    const p = document.createElement('p');
    p.className = 'note';
    p.textContent = text;
    return p;
  };
  const loading = note(labels.loading);
  box.append(loading);

  let data;
  try {
    const r = await fetch(`/clients/${encodeURIComponent(companyId)}/files?purpose=${encodeURIComponent(purpose)}${year ? `&year=${year}` : ''}`, { headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error(String(r.status));
    data = await r.json();
  } catch {
    loading.replaceWith(note(labels.error));
    return box;
  }
  loading.remove();
  if (!data.files.length) {
    box.append(note(labels.none));
    return box;
  }

  const list = document.createElement('div');
  list.className = 'client-files-list';
  let chosen = selectedId ?? null;
  const rows = new Map();
  const mark = () => rows.forEach((row, id) => row.classList.toggle('on', id === chosen));
  for (const f of data.files) {
    const row = document.createElement('button');
    row.type = 'button';
    row.className = 'client-file';
    row.dataset.clientFile = String(f.id);
    row.style.cssText = 'display:block;width:100%;text-align:start;margin:4px 0;padding:8px 10px;border:1px solid var(--line,#ccc);border-radius:8px;background:transparent;cursor:pointer';
    const name = document.createElement('b');
    name.textContent = f.name;
    const meta = document.createElement('span');
    meta.style.cssText = 'display:block;font-size:.85em;opacity:.8';
    meta.textContent = [f.request ? `${f.request.title} · ${f.request.year}` : null, f.type, fmt(f.uploaded_at),
      f.review === 'ok' ? labels.fine : (f.review && f.review !== 'unavailable' ? labels.remarks : null), f.id === data.suggested ? labels.suggested : null].filter(Boolean).join(' · ');
    row.append(name, meta);
    row.onclick = () => {
      chosen = f.id;
      mark();
      onPick(f);
    };
    rows.set(f.id, row);
    list.append(row);
  }
  box.append(list);
  if (chosen === null && data.suggested !== null) {
    // The likeliest file is only offered: the accountant confirms it by clicking.
    rows.get(data.suggested)?.style.setProperty('outline', '2px solid var(--accent,#2b6cb0)');
  }
  mark();

  return box;
}
