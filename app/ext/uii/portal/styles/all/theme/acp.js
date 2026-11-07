(() => {
 const root=document.documentElement, media=matchMedia('(prefers-color-scheme:dark)'), button=document.getElementById('acp-theme');
 let theme;
 function read(){try{theme=localStorage.getItem('uii-theme');}catch(_){theme=null;}}
 function apply(){const dark=theme==='dark'||(theme!=='light'&&media.matches);root.dataset.theme=dark?'dark':'light';if(button){button.textContent=dark?'Светлая тема':'Тёмная тема';button.setAttribute('aria-pressed',String(dark));}}
 read();apply();media.addEventListener('change',apply);window.addEventListener('storage',event=>{if(event.key==='uii-theme'){read();apply();}});
 button?.addEventListener('click',()=>{theme=root.dataset.theme==='dark'?'light':'dark';try{localStorage.setItem('uii-theme',theme);}catch(_){}apply();});
 document.querySelectorAll('#main table').forEach(table=>{if(table.parentElement.classList.contains('uii-acp-table-scroll'))return;const wrap=document.createElement('div');wrap.className='uii-acp-table-scroll';table.before(wrap);wrap.append(table);});
 // Reuse native phpBB explanations as accessible field descriptions.
 document.querySelectorAll('#main dl').forEach((row,index)=>{const hint=row.querySelector('dt .explain, dt span');if(!hint)return;hint.id=hint.id||'uii-acp-hint-'+index;row.querySelectorAll('dd input,dd select,dd textarea').forEach(field=>{const ids=new Set((field.getAttribute('aria-describedby')||'').split(/\s+/).filter(Boolean));ids.add(hint.id);field.setAttribute('aria-describedby',Array.from(ids).join(' '));});});
})();
