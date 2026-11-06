(() => {
  'use strict';
  const frame = document.getElementById('document-preview-resizable');
  if (!frame) return;
  const rotationSelect = document.getElementById('preview-rotation-select');
  const rotationReadout = document.getElementById('preview-rotation-readout');
  const iframe = frame.querySelector('.pdf-preview-frame');
  const image = frame.querySelector('.image-preview-image');
  const supportedAngles = [0, 90, 180, 270];

  const applyRotation = () => {
    const angle = rotationSelect ? Number(rotationSelect.value) : Number(frame.dataset.documentRotation || 0);
    if (!supportedAngles.includes(angle)) return;
    frame.dataset.documentRotation = String(angle);
    if (rotationReadout) rotationReadout.textContent = `${angle}°`;
    const sideways = angle === 90 || angle === 270;
    if (iframe) {
      const width = frame.clientWidth;
      const height = frame.clientHeight;
      iframe.style.width = `${sideways ? height : width}px`;
      iframe.style.height = `${sideways ? width : height}px`;
      iframe.style.transform = `translate(-50%, -50%) rotate(${angle}deg)`;
    }
    if (image) {
      const innerWidth = Math.max(180, frame.clientWidth - 24);
      const innerHeight = Math.max(180, frame.clientHeight - 24);
      image.style.maxWidth = `${sideways ? innerHeight : innerWidth}px`;
      image.style.maxHeight = `${sideways ? innerWidth : innerHeight}px`;
      image.style.transform = `rotate(${angle}deg)`;
    }
  };

  document.querySelectorAll('[data-preview-height]').forEach(button => {
    button.addEventListener('click', () => {
      const height = Number(button.dataset.previewHeight);
      if (![58, 75, 86].includes(height)) return;
      frame.style.height = `${height}vh`;
      document.querySelectorAll('[data-preview-height]').forEach(option => option.setAttribute('aria-pressed', String(option === button)));
      applyRotation();
    });
  });

  if (rotationSelect) {
    rotationSelect.addEventListener('change', applyRotation);
    document.querySelectorAll('[data-rotate-step]').forEach(button => {
      button.addEventListener('click', () => {
        const current = Number(rotationSelect.value);
        const step = Number(button.dataset.rotateStep);
        if (!supportedAngles.includes(current) || ![-90, 90].includes(step)) return;
        rotationSelect.value = String((current + step + 360) % 360);
        rotationSelect.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  }

  if ('ResizeObserver' in window) new ResizeObserver(applyRotation).observe(frame);
  applyRotation();
})();
