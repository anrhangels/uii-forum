const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function element(){return {listeners:{},hidden:false,disabled:false,value:'',textContent:'',src:'',files:[],classList:{add(){},remove(){}},addEventListener(name,fn){this.listeners[name]=fn;},removeAttribute(name){delete this[name];}};}
const input=element(),zone=element(),selection=element(),error=element(),preview=element(),submit=element();
const fields={'[data-photo-input]':input,'[data-photo-dropzone]':zone,'[data-photo-selection]':selection,'[data-photo-error]':error,'[data-photo-preview]':preview,'[data-photo-submit]':submit};
const form=element();form.querySelector=key=>fields[key];const root={dataset:{}};const revoked=[];
class TestURL extends URL {static createObjectURL(){return 'blob:photo';}static revokeObjectURL(url){revoked.push(url);}}
const document={documentElement:root,getElementById(){return null;},querySelector(){return null;},querySelectorAll(selector){return selector==='[data-photo-form]'?[form]:[];}};
vm.runInNewContext(fs.readFileSync('app/styles/uii_modern/theme/portal.js','utf8'),{document,window:{addEventListener(){}},matchMedia:()=>({matches:false,addEventListener(){}}),localStorage:{getItem(){return null;}},location:{href:'http://127.0.0.1:8088/'},URL:TestURL,console});
assert.equal(submit.disabled,true);
function choose(file){input.files=[file];input.listeners.change();}
choose({name:'Протокол.png',type:'image/png',size:100});assert.equal(submit.disabled,false);assert.equal(preview.hidden,false);assert.match(selection.textContent,/Протокол/);
choose({name:'large.png',type:'image/png',size:2097153});assert.equal(submit.disabled,true);assert.match(error.textContent,/2 МБ/);assert.equal(preview.hidden,true);assert.ok(revoked.length);
choose({name:'script.svg',type:'image/svg+xml',size:100});assert.equal(submit.disabled,true);assert.match(error.textContent,/JPG/);
let prevented=false;zone.listeners.drop({preventDefault(){prevented=true;},dataTransfer:{files:[{name:'Начало.webp',type:'image/webp',size:2048}]}});assert.equal(prevented,true);assert.equal(submit.disabled,false);assert.match(selection.textContent,/Начало/);
zone.listeners.drop({preventDefault(){},dataTransfer:{files:[{},{}]}});assert.equal(submit.disabled,true);assert.match(error.textContent,/одно фото/);
input.files=[];let blocked=false;form.listeners.submit({preventDefault(){blocked=true;}});assert.equal(blocked,true);
console.log('PASS photo chooser/drop: preview, original filename, size/type validation, multiple-file rejection, empty submit and blob cleanup');
