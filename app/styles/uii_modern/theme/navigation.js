(() => {
 const form=document.getElementById('navigation-form');if(!form)return;
 const field=document.getElementById('navigation-layout'),editor=document.getElementById('navigation-editor'),status=document.getElementById('navigation-status'),save=document.getElementById('navigation-save'),add=document.getElementById('navigation-add');
 const catalog=new Map(Array.from(document.querySelectorAll('#navigation-catalog [data-id]'),node=>[node.dataset.id,{label:node.dataset.label,level:Number(node.dataset.level)}]));
 let groups=JSON.parse(field.value);
 function node(tag,cls,text){const element=document.createElement(tag);if(cls)element.className=cls;if(text!==undefined)element.textContent=text;return element;}
 function changed(){field.value=JSON.stringify(groups);status.textContent='Есть несохранённые изменения';}
 function button(text,label,action,disabled=false){const result=node('button','button',text);result.type='button';result.setAttribute('aria-label',label);result.disabled=disabled;result.addEventListener('click',action);return result;}
 function focusGroup(id){Array.from(editor.querySelectorAll('[data-group-id]')).find(element=>element.dataset.groupId===id)?.querySelector('input')?.focus();}
 function moveItem(id,destination,before){if(before===id)return;for(const group of groups)group.items=group.items.filter(item=>item!==id);const target=groups.find(group=>group.id===destination);if(!target)return;const index=before?target.items.indexOf(before):-1;target.items.splice(index<0?target.items.length:index,0,id);changed();render();}
 function drag(handle,data){handle.draggable=true;handle.title='Перетащить';handle.addEventListener('dragstart',event=>{event.dataTransfer.setData('text/plain',JSON.stringify(data));event.dataTransfer.effectAllowed='move';});}
 function dropped(event,group,before){event.preventDefault();event.stopPropagation();let value;try{value=JSON.parse(event.dataTransfer.getData('text/plain'));}catch(_){return;}if(!value||typeof value!=='object')return;if(value.item&&catalog.has(value.item)){moveItem(value.item,group.id,before);}else if(value.group&&value.group!==group.id){const old=groups.findIndex(item=>item.id===value.group);if(old<0)return;const [moving]=groups.splice(old,1);groups.splice(groups.findIndex(item=>item.id===group.id),0,moving);changed();render();focusGroup(moving.id);}}
 function render(){
  editor.replaceChildren();add.disabled=groups.length>=8;
  groups.forEach((group,index)=>{
   const section=node('section','portal-card navigation-group');section.dataset.groupId=group.id;
   const heading=node('div','navigation-group-heading'),grip=node('span','navigation-grip','⋮⋮');drag(grip,{group:group.id});grip.setAttribute('aria-hidden','true');heading.append(grip);
   const label=node('label','portal-field');label.append(node('span',null,'Название группы'));const name=node('input','inputbox');name.value=group.title;name.required=true;name.maxLength=40;name.pattern='[^<>]{1,40}';name.addEventListener('input',()=>{group.title=name.value;changed();});label.append(name);heading.append(label);
   heading.append(button('↑','Группа '+group.title+': выше',()=>{[groups[index-1],groups[index]]=[groups[index],groups[index-1]];changed();render();focusGroup(group.id);},index===0));
   heading.append(button('↓','Группа '+group.title+': ниже',()=>{[groups[index],groups[index+1]]=[groups[index+1],groups[index]];changed();render();focusGroup(group.id);},index===groups.length-1));
   const remove=button('Удалить группу','Удалить группу '+group.title,()=>{groups=groups.filter(item=>item.id!==group.id);changed();render();},groups.length===1||group.items.length>0);if(group.items.length)remove.title='Сначала перенесите все пункты в другие группы';heading.append(remove);section.append(heading);
   const list=node('div','navigation-items');list.addEventListener('dragover',event=>event.preventDefault());list.addEventListener('drop',event=>dropped(event,group));
   if(!group.items.length)list.append(node('p','field-hint','Пустая группа. Перенесите сюда пункты из других групп.'));
   group.items.forEach((id,itemIndex)=>{
    const item=catalog.get(id),row=node('div','navigation-item');row.dataset.itemId=id;const grip=node('span','navigation-grip','⋮⋮');drag(grip,{item:id});grip.setAttribute('aria-hidden','true');row.append(grip);
    const info=node('div','navigation-item-info');info.append(node('strong',null,item.label),node('small','field-hint',({1:'Все сотрудники',2:'Руководители',3:'Начальник и руководство',4:'Администратор форума'})[item.level]));row.append(info);
    row.append(button('↑',item.label+': выше',()=>{[group.items[itemIndex-1],group.items[itemIndex]]=[group.items[itemIndex],group.items[itemIndex-1]];changed();render();},itemIndex===0));
    row.append(button('↓',item.label+': ниже',()=>{[group.items[itemIndex],group.items[itemIndex+1]]=[group.items[itemIndex+1],group.items[itemIndex]];changed();render();},itemIndex===group.items.length-1));
    const select=node('select');select.setAttribute('aria-label','Группа для '+item.label);for(const destination of groups){const option=node('option',null,destination.title);option.value=destination.id;option.selected=destination.id===group.id;select.append(option);}select.addEventListener('change',()=>moveItem(id,select.value));row.append(select);
    row.addEventListener('dragover',event=>event.preventDefault());row.addEventListener('drop',event=>dropped(event,group,id));list.append(row);
   });section.append(list);editor.append(section);
  });
 }
 add.addEventListener('click',()=>{if(groups.length>=8)return;const group={id:'group_'+Date.now().toString(36),title:'Новая группа',items:[]};groups.push(group);changed();render();focusGroup(group.id);});
 document.getElementById('navigation-reset').addEventListener('click',()=>{if(!confirm('Вернуть стандартное меню? Оно будет применено после сохранения.'))return;groups=JSON.parse(document.getElementById('navigation-defaults').value);changed();render();});
 form.addEventListener('submit',()=>{field.value=JSON.stringify(groups);});render();save.disabled=false;
})();
