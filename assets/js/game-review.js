const reviewSelector = document.getElementById('review-selector');
const gameFilter=document.getElementById('review-game-filter');
const examples=document.getElementById('review-example');
const groups=[...(examples?.querySelectorAll('optgroup')||[])];
function filterExamples(){if(!examples||!gameFilter)return;examples.replaceChildren(...groups.filter(g=>g.label===gameFilter.value));}
filterExamples();
gameFilter?.addEventListener('change',()=>{filterExamples();examples.selectedIndex=0;reviewSelector.requestSubmit();});
for (const select of reviewSelector?.querySelectorAll('select:not(#review-game-filter)') || []) {
  select.addEventListener('change', () => reviewSelector.requestSubmit());
}

let actionGeneration = 0;
for (const form of document.querySelectorAll('.review-action')) form.addEventListener('submit', async event => {
  event.preventDefault();
  const button = form.querySelector('button');
  const status = form.querySelector('.action-status') || document.getElementById('review-status');
  if (form.dataset.busy) return;
  actionGeneration++;
  button.disabled = true; form.dataset.busy = "true";
  let complete = false;
  try {
    const body = new FormData(form); body.set('ajax', '1');
    const response = await fetch(location.href, {method:'POST', body, credentials:'same-origin'});
    const data = await response.json();
    if (!response.ok || data.error) throw new Error(data.error || 'The example action failed.');
    complete = data.complete === true;
    form.elements.step.value = String(data.step);
    status.textContent = form.elements.operation.value === 'reference-step' ? 'Reference action played.' : 'Action played. Watch the live game, then Reset to repeat.';
  } catch(error) { status.textContent = error.message; }
  finally { button.disabled = complete; delete form.dataset.busy; }
});

// Keep the prepared-action buttons in sync when a real board action or trick timer advances.
let readingProgress = false;
const progressTimer = setInterval(async () => {
  const forms = [...document.querySelectorAll('.review-action')];
  if (readingProgress || document.hidden || !forms.length || forms.some(form => form.dataset.busy)) return;
  const id = forms[0].elements.review_id.value;
  if (!id) return;
  readingProgress = true;
  const generation = actionGeneration;
  try {
    const url = new URL(location.href); url.searchParams.set('review', id); url.searchParams.set('progress', '1');
    const response = await fetch(url, {credentials:'same-origin',cache:'no-store'});
    if (!response.ok) return;
    const data = await response.json();
    if (generation !== actionGeneration) return;
    for (const form of forms) {
      if (form.dataset.busy) continue;
      const state = data[form.elements.operation.value === 'reference-step' ? 'reference' : 'live'];
      if (!state) continue;
      form.elements.step.value = String(state.step); form.querySelector('button').disabled = state.complete;
    }
  } catch { /* The next successful read restores button state. */ }
  finally { readingProgress = false; }
}, 1500);
window.addEventListener('pagehide', () => clearInterval(progressTimer), {once:true});

// This example has a scripted opponent, not a running practice bot. Wait for
// the actual board before playing its one prepared move through the same
// admin/CSRF/step-checked action as the visible manual button.
const opponentForm = document.getElementById('review-step');
const opponentFrame = document.getElementById('review-game');
if (opponentForm?.dataset.autoOpponent === 'true' && opponentFrame) {
  let visibleSince = null;
  const opponentTimer = setInterval(() => {
    if (opponentForm.elements.step.value !== '0') { clearInterval(opponentTimer); return; }
    let ready = false;
    try { ready = opponentFrame.contentDocument?.querySelectorAll('.dm-player').length >= 2; } catch { /* Wait for the same-origin board. */ }
    if (!ready || document.hidden) { visibleSince = null; return; }
    visibleSince ??= Date.now();
    if (Date.now() - visibleSince < 1500 || opponentForm.dataset.busy) return;
    clearInterval(opponentTimer);
    if (!opponentForm.querySelector('button').disabled) opponentForm.requestSubmit();
    // A failed request leaves the manual button available; never retry a move
    // repeatedly or advance the frozen reference automatically.
  }, 250);
  window.addEventListener('pagehide', () => clearInterval(opponentTimer), {once:true});
}
