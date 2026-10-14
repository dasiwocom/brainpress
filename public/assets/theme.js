(() => {
  let saved; try { saved=localStorage.getItem('brainpress-theme'); } catch {}
  const dark=window.matchMedia('(prefers-color-scheme: dark)');
  document.documentElement.dataset.theme=saved==='dark'||saved==='light' ? saved : (dark.matches?'dark':'light');
  dark.addEventListener('change',event=>{ let choice; try{choice=localStorage.getItem('brainpress-theme')}catch{} if(!choice){document.documentElement.dataset.theme=event.matches?'dark':'light';document.dispatchEvent(new Event('brainpress:theme'));} });
})();
