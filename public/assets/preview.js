(() => {
  'use strict';
  const frame = document.getElementById('document-preview-resizable');
  if (!frame) return;
  document.querySelectorAll('[data-preview-height]').forEach(button => {
    button.addEventListener('click', () => {
      const height = Number(button.dataset.previewHeight);
      if (![58, 75, 86].includes(height)) return;
      frame.style.height = `${height}vh`;
      document.querySelectorAll('[data-preview-height]').forEach(option => option.setAttribute('aria-pressed', String(option === button)));
    });
  });
})();
