(() => {
  let theme;
  try {document.documentElement.dataset.density=localStorage.getItem('uii-density')==='compact'?'compact':'comfortable';} catch (_) {}
  try { theme = localStorage.getItem('uii-theme'); } catch (_) {}
  document.documentElement.dataset.theme = theme === 'light' || theme === 'dark'
    ? theme : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
})();
