(() => {
 const form=document.querySelector('[data-bug-form]');
 if(form){let busy=false;form.addEventListener('submit',event=>{if(busy){event.preventDefault();return;}if(!form.reportValidity()){event.preventDefault();return;}busy=true;form.setAttribute('aria-busy','true');form.querySelector('[data-bug-status]').textContent='Отправляем отчёт…';form.querySelector('button[type=submit]').disabled=true;});window.addEventListener('pageshow',()=>{busy=false;form.removeAttribute('aria-busy');form.querySelector('button[type=submit]').disabled=false;form.querySelector('[data-bug-status]').textContent='';});}
 const button=document.querySelector('[data-copy-bug]');if(!button)return;
 const source=document.querySelector('[data-bug-export]'),status=document.querySelector('[data-copy-status]');
 button.addEventListener('click',async()=>{
  let copied=false;
  try{if(window.isSecureContext&&navigator.clipboard){await navigator.clipboard.writeText(source.value);copied=true;}}catch(_){}
  if(!copied){const temporary=document.createElement('textarea');temporary.value=source.value;temporary.setAttribute('readonly','');temporary.style.cssText='position:fixed;top:0;left:0;opacity:0;pointer-events:none';document.body.appendChild(temporary);temporary.focus();temporary.select();try{copied=document.execCommand('copy');}catch(_){}temporary.remove();button.focus();}
  status.textContent=copied?'Отчёт скопирован для Codex':'Браузер не разрешил копирование. Скопируйте выделенный текст вручную.';
  if(!copied){source.closest('details').open=true;source.focus();source.select();}
 });
})();
