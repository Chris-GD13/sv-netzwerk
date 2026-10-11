(() => {
  const choices = [...document.querySelectorAll('[data-bk-method]')];
  const panels = [...document.querySelectorAll('[data-bk-panel]')];
  if (!choices.length) return;
  const update = () => {
    const selected = new Set(choices.filter(choice => choice.checked).map(choice => choice.dataset.bkMethod));
    panels.forEach(panel => { panel.hidden = !selected.has(panel.dataset.bkPanel); });
  };
  choices.forEach(choice => choice.addEventListener('change', update));
  document.getElementById('bk-clear-lines')?.addEventListener('click', () => window.__bkiCalcBridge?.setLines?.([]));
  update();
})();
