const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const stored=new Map([['uii-theme','dark'],['uii-density','compact']]);
function control(){return {value:'',textContent:'',handlers:{},addEventListener(name,fn){this.handlers[name]=fn;}};}
const span=control(),use={setAttribute(){}},button=control(),theme=control(),density=control(),status=control();button.querySelector=key=>key==='span'?span:use;button.setAttribute=()=>{};
const root={dataset:{}},media={matches:false,addEventListener(name,fn){this.change=fn;}};
const document={documentElement:root,getElementById:key=>key==='uii-theme'?button:null,querySelector:key=>({'[data-theme-choice]':theme,'[data-density-choice]':density,'[data-preference-status]':status}[key]||null),querySelectorAll:()=>[]};
vm.runInNewContext(fs.readFileSync('app/styles/uii_modern/theme/portal.js','utf8'),{document,window:{addEventListener(){}},location:{href:'http://127.0.0.1:8088/'},URL,matchMedia:()=>media,localStorage:{getItem:key=>stored.get(key)||null,setItem:(key,value)=>stored.set(key,value),removeItem:key=>stored.delete(key)}});
assert.equal(root.dataset.theme,'dark');assert.equal(root.dataset.density,'compact');assert.equal(theme.value,'dark');
button.handlers.click();assert.equal(root.dataset.theme,'light');assert.equal(stored.get('uii-theme'),'light');assert.equal(span.textContent,'Тёмная тема');
media.matches=true;theme.value='system';theme.handlers.change();assert.equal(root.dataset.theme,'dark');assert.equal(stored.has('uii-theme'),false);
media.matches=false;media.change();assert.equal(root.dataset.theme,'light');
density.value='comfortable';density.handlers.change();assert.equal(root.dataset.density,'comfortable');assert.equal(stored.get('uii-density'),'comfortable');assert.equal(status.textContent,'Настройки сохранены');
console.log('PASS preferences: initial state, sidebar toggle, system theme changes, density, persistence and confirmation');
