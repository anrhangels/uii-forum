(() => {
 const form=document.querySelector('[data-case-form]');if(!form)return;
 const max=5242880;let sending=false;
 form.querySelectorAll('[data-case-file-field]').forEach(field=>{
  const input=field.querySelector('[data-case-file]');if(!input)return;
  const zone=field.querySelector('[data-case-dropzone]'),name=field.querySelector('[data-case-file-name]'),error=field.querySelector('[data-case-file-error]'),preview=field.querySelector('[data-case-file-preview]'),remove=field.querySelector('[data-case-file-remove]');let objectUrl=null;
  function clear(){if(objectUrl)URL.revokeObjectURL(objectUrl);objectUrl=null;preview.removeAttribute('src');preview.hidden=true;}
  function reset(){input.value='';clear();remove.hidden=true;name.textContent='Файл не выбран';error.hidden=true;input.setCustomValidity('');}
  function reject(message){reset();remove.hidden=false;error.textContent=message;error.hidden=false;input.setCustomValidity(message);}
  function selected(){clear();error.hidden=true;input.setCustomValidity('');const file=input.files?.[0];if(!file){reset();return;}if(input.files.length!==1)return reject('Выберите одно изображение для этого поля.');if(!file.size||file.size>max)return reject('Изображение должно быть не пустым и не больше 5 МиБ.');if(!/\.(jpe?g|png|webp)$/i.test(file.name)||(file.type&&!['image/jpeg','image/png','image/webp'].includes(file.type)))return reject('Допустимы JPG, JPEG, PNG и WebP.');name.textContent=file.name+' · '+Math.ceil(file.size/1024)+' КБ';objectUrl=URL.createObjectURL(file);preview.src=objectUrl;preview.hidden=false;remove.hidden=false;}
  zone.tabIndex=0;zone.setAttribute('role','group');zone.setAttribute('aria-label','Выбор изображения. Нажмите Ctrl+V, чтобы вставить скриншот.');
  field.addEventListener('paste',event=>{
   const files=Array.from(event.clipboardData?.items||[]).filter(item=>item.kind==='file').map(item=>item.getAsFile()).filter(Boolean);
   if(!files.length)return;event.preventDefault();
   if(files.length!==1)return reject('Вставьте одно изображение для этого поля.');
   const file=files[0],extension={'image/png':'png','image/jpeg':'jpg','image/webp':'webp'}[file.type];
   if(!extension)return reject('Из буфера можно вставить JPG, PNG или WebP.');
   try{const transfer=new DataTransfer();transfer.items.add(new File([file],'screenshot.'+extension,{type:file.type}));input.files=transfer.files;selected();}catch(_){reject('Браузер не разрешил вставку. Сохраните скриншот и выберите файл.');}
  });
  zone.addEventListener('keydown',event=>{if(event.target===zone&&(event.key==='Enter'||event.key===' ')){event.preventDefault();input.click();}});
  input.addEventListener('change',selected);remove.addEventListener('click',reset);
  zone.addEventListener('dragover',e=>{e.preventDefault();zone.classList.add('is-dragging');});zone.addEventListener('dragleave',()=>zone.classList.remove('is-dragging'));
  zone.addEventListener('drop',e=>{e.preventDefault();zone.classList.remove('is-dragging');if(e.dataTransfer.files.length!==1)return reject('Перетащите одно изображение.');try{input.files=e.dataTransfer.files;selected();}catch(_){reject('Выберите файл кнопкой.');}});
  window.addEventListener('pagehide',clear);window.addEventListener('pageshow',selected);
 });
 form.addEventListener('submit',e=>{if(sending){e.preventDefault();return;}if(!form.reportValidity()){e.preventDefault();return;}sending=true;form.setAttribute('aria-busy','true');form.querySelector('[data-case-submit-status]').textContent='Сохраняем дело…';/* Keep the clicked action in the submitted form data. */setTimeout(()=>form.querySelectorAll('button[type=submit]').forEach(b=>b.disabled=true),0);});
 window.addEventListener('pageshow',()=>{sending=false;form.removeAttribute('aria-busy');form.querySelectorAll('button[type=submit]').forEach(b=>b.disabled=false);form.querySelector('[data-case-submit-status]').textContent='';});
})();
