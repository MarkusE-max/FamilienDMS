(() => {
  'use strict';
  const frame = document.getElementById('document-preview-resizable');
  if (!frame) return;
  const orientationSelect = document.getElementById('preview-orientation-select');
  const fineSlider = document.getElementById('preview-fine-rotation');
  const rotationInput = document.getElementById('rotation-degrees-input');
  const rotationReadout = document.getElementById('preview-rotation-readout');
  const fineReadout = document.getElementById('preview-fine-readout');
  const iframe = frame.querySelector('.pdf-preview-frame');
  const image = frame.querySelector('.image-preview-image');
  const supportedOrientations = [0, 90, 180, 270];

  const applyRotation = () => {
    const base = orientationSelect ? Number(orientationSelect.value) : 0;
    const fine = fineSlider ? Number(fineSlider.value) : 0;
    if (!supportedOrientations.includes(base) || !Number.isInteger(fine) || fine < -15 || fine > 15) return;
    const angle = (base + fine + 360) % 360;
    frame.dataset.documentRotation = String(angle);
    if (rotationInput) rotationInput.value = String(angle);
    if (rotationReadout) rotationReadout.textContent = `Gesamt: ${angle}°`;
    if (fineReadout) fineReadout.textContent = `${fine > 0 ? '+' : ''}${fine}°`;
    if (iframe) {
      const width = frame.clientWidth;
      const height = frame.clientHeight;
      const radians = angle * Math.PI / 180;
      const absCos = Math.abs(Math.cos(radians));
      const absSin = Math.abs(Math.sin(radians));
      const scale = Math.min(1 / (absCos + (height / width) * absSin), 1 / (absCos + (width / height) * absSin));
      iframe.style.width = `${width * scale}px`;
      iframe.style.height = `${height * scale}px`;
      iframe.style.transform = `translate(-50%, -50%) rotate(${angle}deg)`;
    }
    if (image && image.naturalWidth && image.naturalHeight) {
      const boxWidth = Math.max(180, frame.clientWidth - 24);
      const boxHeight = Math.max(180, frame.clientHeight - 24);
      const radians = angle * Math.PI / 180;
      const absCos = Math.abs(Math.cos(radians));
      const absSin = Math.abs(Math.sin(radians));
      const scale = Math.min(boxWidth / (absCos * image.naturalWidth + absSin * image.naturalHeight), boxHeight / (absSin * image.naturalWidth + absCos * image.naturalHeight));
      image.style.width = `${image.naturalWidth * scale}px`;
      image.style.height = `${image.naturalHeight * scale}px`;
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
  if (orientationSelect) orientationSelect.addEventListener('change', applyRotation);
  if (fineSlider) fineSlider.addEventListener('input', applyRotation);
  if (image) image.addEventListener('load', applyRotation);
  if ('ResizeObserver' in window) new ResizeObserver(applyRotation).observe(frame);
  applyRotation();
})();
