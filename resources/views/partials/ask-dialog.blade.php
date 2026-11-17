{{-- In-page confirmation, instead of the browser's confirm(): await ask(text, yesLabel) → true | false. Uses the page's CSS variables. --}}
<style>
  .ask{border:1px solid var(--line);border-radius:18px;background:var(--panel);color:var(--text);padding:22px 24px;width:min(520px,calc(100vw - 32px));
    font-family:inherit;box-shadow:var(--pop-shadow)}
  .ask::backdrop{background:var(--overlay);backdrop-filter:blur(2px)}
  .ask p{margin:0 0 20px;line-height:1.9;font-size:15px;white-space:pre-line}
  .ask .ask-btns{display:flex;gap:10px;justify-content:flex-start}
  .ask button{font-family:inherit;font-weight:700;font-size:14px;border-radius:12px;padding:9px 20px;cursor:pointer}
  .ask .ask-yes{background:var(--brand);color:var(--on-brand);border:1px solid var(--brand)}
  .ask .ask-no{background:transparent;color:var(--muted);border:1px solid var(--line)}
</style>
<dialog class="ask" id="ask" data-yes="{{ __('financial_reports.ui.confirm_yes') }}">
  <p id="askText"></p>
  <div class="ask-btns"><button class="ask-yes" id="askYes">{{ __('financial_reports.ui.confirm_yes') }}</button><button class="ask-no" id="askNo">{{ __('financial_reports.ui.cancel') }}</button></div>
</dialog>
<script>
function ask(text, yes = document.getElementById('ask').dataset.yes){
  const d = document.getElementById('ask');
  document.getElementById('askText').textContent = text;
  document.getElementById('askYes').textContent = yes;
  return new Promise(resolve => {
    const done = v => { d.onclose = null; d.close(); resolve(v); };
    document.getElementById('askYes').onclick = () => done(true);
    document.getElementById('askNo').onclick = () => done(false);
    d.onclose = () => resolve(false);   // Esc
    d.showModal();
    document.getElementById('askNo').focus();
  });
}
</script>
