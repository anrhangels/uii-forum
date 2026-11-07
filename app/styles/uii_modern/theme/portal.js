(() => {
 const root=document.documentElement,media=matchMedia('(prefers-color-scheme: dark)');
 const button=document.getElementById('uii-theme'),themeChoice=document.querySelector('[data-theme-choice]'),densityChoice=document.querySelector('[data-density-choice]');
 let theme='system',density='comfortable';
 try {const stored=localStorage.getItem('uii-theme');if(['light','dark'].includes(stored))theme=stored;density=localStorage.getItem('uii-density')==='compact'?'compact':'comfortable';}catch(_){}
 function apply(){const dark=theme==='dark'||(theme==='system'&&media.matches);root.dataset.theme=dark?'dark':'light';root.dataset.density=density;if(button){button.querySelector('span').textContent=dark?'Светлая тема':'Тёмная тема';button.querySelector('use').setAttribute('href',dark?'#icon-sun':'#icon-moon');button.setAttribute('aria-pressed',String(dark));}if(themeChoice)themeChoice.value=theme;if(densityChoice)densityChoice.value=density;}
 function save(){const status=document.querySelector('[data-preference-status]');try{if(theme==='system')localStorage.removeItem('uii-theme');else localStorage.setItem('uii-theme',theme);localStorage.setItem('uii-density',density);if(status)status.textContent='Настройки сохранены';}catch(_){if(status)status.textContent='Выбор применён. Браузер не разрешает сохранить его между посещениями.';}apply();}
 apply();button?.addEventListener('click',()=>{theme=root.dataset.theme==='dark'?'light':'dark';save();});
 themeChoice?.addEventListener('change',()=>{theme=themeChoice.value;save();});densityChoice?.addEventListener('change',()=>{density=densityChoice.value;save();});media.addEventListener('change',()=>{if(theme==='system')apply();});
 const menu=document.getElementById('uii-menu');if(menu)menu.addEventListener('click',()=>{const sidebar=menu.closest('.uii-sidebar');const opened=sidebar.dataset.open!=='true';sidebar.dataset.open=String(opened);menu.setAttribute('aria-expanded',String(opened));});
})();
(() => {
 document.querySelectorAll('[data-open-create]').forEach(link=>link.addEventListener('click',()=>{const panel=document.getElementById('manage');if(panel){panel.open=true;panel.querySelector('input[name=nickname]')?.focus();}}));
 function markNavigation(){const current=new URL(location.href);document.querySelectorAll('#uii-navigation a').forEach(link=>{const target=new URL(link.href);link.removeAttribute('aria-current');if(target.pathname.replace(/\/index\.php$/, '/')===current.pathname.replace(/\/index\.php$/, '/')&&target.search===current.search&&target.hash===current.hash)link.setAttribute('aria-current','page');});}
 markNavigation();window.addEventListener('hashchange',markNavigation);
 document.querySelectorAll('.employee-actions').forEach(panel=>panel.addEventListener('toggle',()=>{if(panel.open)document.querySelectorAll('.employee-actions').forEach(other=>{if(other!==panel)other.open=false;});}));
})();
(() => {
 const profile=document.querySelector('.profile-menu');if(profile){document.addEventListener('click',event=>{if(!profile.contains(event.target))profile.open=false;});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&profile.open){profile.open=false;profile.querySelector('summary')?.focus();}});}
})();
(() => {
 document.querySelectorAll('[data-photo-form]').forEach(form=>{
  const input=form.querySelector('[data-photo-input]'),zone=form.querySelector('[data-photo-dropzone]'),selection=form.querySelector('[data-photo-selection]'),error=form.querySelector('[data-photo-error]'),preview=form.querySelector('[data-photo-preview]'),submit=form.querySelector('[data-photo-submit]');
  let previewUrl=null;submit.disabled=true;
  function clearPreview(){if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl=null;preview.removeAttribute('src');preview.hidden=true;}
  function reject(message){input.value='';submit.disabled=true;clearPreview();selection.textContent='Файл не выбран';error.textContent=message;error.hidden=false;}
  function selected(){clearPreview();error.hidden=true;const file=input.files?.[0];if(!file){submit.disabled=true;selection.textContent='Файл не выбран';return;}if(input.files.length!==1)return reject('Выберите одно фото за раз.');if(file.size===0||file.size>5242880)return reject('Фото должно быть не больше 5 МиБ и не пустым.');if(!/\.(jpe?g|png|webp)$/i.test(file.name)|| (file.type&&!['image/jpeg','image/png','image/webp'].includes(file.type)))return reject('Выберите фото JPG, PNG или WebP.');selection.textContent=file.name+' · '+Math.ceil(file.size/1024)+' КБ';previewUrl=URL.createObjectURL(file);preview.src=previewUrl;preview.hidden=false;submit.disabled=false;}
  input.addEventListener('change',selected);
  let entered=0;zone.addEventListener('dragenter',event=>{event.preventDefault();entered++;zone.classList.add('is-dragging');});
  zone.addEventListener('dragover',event=>{event.preventDefault();event.dataTransfer.dropEffect='copy';});
  zone.addEventListener('dragleave',()=>{if(--entered<=0){entered=0;zone.classList.remove('is-dragging');}});
  zone.addEventListener('drop',event=>{event.preventDefault();entered=0;zone.classList.remove('is-dragging');const files=event.dataTransfer.files;if(files.length!==1)return reject('Перетащите одно фото.');try{input.files=files;selected();}catch(_){reject('Нажмите «Выбрать фото», чтобы загрузить файл.');}});
  form.addEventListener('submit',event=>{if(!input.files?.length){event.preventDefault();reject('Сначала выберите фото.');}});
  window.addEventListener('pagehide',clearPreview);
  window.addEventListener('pageshow',selected);
 });
})();

(() => {
 document.querySelectorAll('[data-staff-filters] select').forEach(select=>select.addEventListener('change',()=>select.form.requestSubmit()));
 document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!confirm(form.dataset.confirm))event.preventDefault();}));
})();
