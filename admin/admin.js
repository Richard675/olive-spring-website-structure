/* Olive Sprigs admin: small progressive enhancements. Everything works without JavaScript. */
(() => {
  'use strict';

  // Ask before destructive actions (forms marked data-confirm).
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  // Status filter applies as soon as it changes.
  document.querySelectorAll('select[data-autosubmit]').forEach((select) => {
    select.addEventListener('change', () => select.form.requestSubmit());
  });

  // Prevent double submits (e.g. double-clicking "Delete").
  document.querySelectorAll('form[method="post"]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (event.defaultPrevented) return;
      const button = form.querySelector('button[type="submit"]');
      if (button) window.setTimeout(() => { button.disabled = true; }, 0);
    });
  });

  // Unsaved notes warning.
  const notes = document.querySelector('textarea[name="notes"]');
  if (notes) {
    const initial = notes.value;
    let saving = false;
    notes.form.addEventListener('submit', () => { saving = true; });
    window.addEventListener('beforeunload', (event) => {
      if (!saving && notes.value !== initial) { event.preventDefault(); event.returnValue = ''; }
    });
  }
})();
