(() => {const button=document.querySelector('[data-error-back]');if(button&&history.length>1){button.hidden=false;button.addEventListener('click',()=>history.back());}})();
