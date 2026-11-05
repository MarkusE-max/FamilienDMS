(() => {
  'use strict';
  const userId = document.body.dataset.preferenceUser;
  if (!userId || userId === '0') return;
  const key = `familienDmsUiPrefs:v1:${userId}`;
  const fonts = {
    system: 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    arial: 'Arial, Helvetica, sans-serif',
    verdana: 'Verdana, Geneva, sans-serif',
    georgia: 'Georgia, "Times New Roman", serif'
  };
  const defaultPrefs = { green: '#315e4a', font: 'system' };
  const colorInput = document.getElementById('preference-green');
  const fontInput = document.getElementById('preference-font');
  const status = document.getElementById('preference-status');
  const validColor = value => typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value);
  const readPrefs = () => {
    try {
      const stored = JSON.parse(localStorage.getItem(key) || '{}');
      return { green: validColor(stored.green) ? stored.green : defaultPrefs.green, font: Object.hasOwn(fonts, stored.font) ? stored.font : defaultPrefs.font };
    } catch (_) { return { ...defaultPrefs }; }
  };
  const mix = (hex, target, amount) => {
    const from = [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16));
    return `#${from.map(channel => Math.round(channel * (1 - amount) + target * amount).toString(16).padStart(2, '0')).join('')}`;
  };
  const luminance = hex => {
    const channels = [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16) / 255).map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2);
    return .2126 * channels[0] + .7152 * channels[1] + .0722 * channels[2];
  };
  const apply = prefs => {
    const root = document.documentElement;
    const dark = mix(prefs.green, 0, .22);
    root.style.setProperty('--green', prefs.green);
    root.style.setProperty('--green-dark', dark);
    root.style.setProperty('--green-soft', mix(prefs.green, 255, .90));
    root.style.setProperty('--sidebar-bg', mix(prefs.green, 0, .52));
    root.style.setProperty('--accent-foreground', luminance(prefs.green) > .43 ? '#17231c' : '#ffffff');
    root.style.setProperty('--font-body', fonts[prefs.font]);
    if (colorInput) colorInput.value = prefs.green;
    if (fontInput) fontInput.value = prefs.font;
    const preview = document.querySelector('.settings-preview');
    if (preview) preview.style.fontFamily = fonts[prefs.font];
  };
  let prefs = readPrefs();
  apply(prefs);
  const save = () => {
    prefs = { green: validColor(colorInput.value) ? colorInput.value : defaultPrefs.green, font: Object.hasOwn(fonts, fontInput.value) ? fontInput.value : defaultPrefs.font };
    apply(prefs);
    try {
      localStorage.setItem(key, JSON.stringify(prefs));
      if (status) status.textContent = 'Einstellungen gespeichert.';
    } catch (_) {
      if (status) status.textContent = 'Speichern im Browser ist nicht möglich.';
    }
  };
  colorInput?.addEventListener('input', save);
  fontInput?.addEventListener('change', save);
  document.getElementById('preference-reset')?.addEventListener('click', () => {
    prefs = { ...defaultPrefs };
    apply(prefs);
    try { localStorage.removeItem(key); } catch (_) {}
    if (status) status.textContent = 'Standardgestaltung wiederhergestellt.';
  });
})();
