'use strict';
document.querySelectorAll('[data-admin-action]').forEach(form=>form.addEventListener('submit',async event=>{
  event.preventDefault(); const button=event.submitter||form.querySelector('button[type=submit]');if(button)button.disabled=true;
  const message=form.querySelector('[role=status]')||document.querySelector('#admin-message');message.textContent=tr('Saving…');
  try{const data=new FormData(form);const action=button?.dataset.action||form.dataset.adminAction;const result=await request(action,data);if(action==='link_save'||action==='user_save'){location.href=result.url;return;}if(action==='settings_save'){location.reload();return;}if(form.hasAttribute('data-stay')){message.textContent=result.message||tr('Saved.');}else location.reload();}
  catch(error){message.textContent=error.message;}finally{if(button)button.disabled=false;}
}));
const menuEditor=document.querySelector('#menu-editor');
function reindexMenu(){
 const rows=[...menuEditor.querySelectorAll('.menu-item')];
 rows.forEach(row=>{const id=row.querySelector('[name="menu_id[]"]');if(!id.value)id.value=crypto.randomUUID();});
 const records=rows.map(row=>({row,id:row.querySelector('[name="menu_id[]"]').value,label:row.querySelector('[name="label[]"]').value,parent:row.querySelector('[name="parent[]"]').value||row.querySelector('[name="parent[]"]').dataset.selected||''}));
 records.forEach(({row,id,parent},index)=>{row.querySelector('[type=checkbox]').value=index;row.querySelector('[name="label[]"]').setAttribute('aria-label',tr('Menu label')+' '+(index+1));row.querySelector('[name="url[]"]').setAttribute('aria-label',tr('Menu URL')+' '+(index+1));const select=row.querySelector('[name="parent[]"]');select.replaceChildren(new Option(tr('Top level'),''));for(const record of records)if(record.id!==id&&!record.parent)select.add(new Option(record.label||tr('Label'),record.id));select.value=records.some(record=>record.id===parent)?parent:'';select.dataset.selected=select.value;const content=row.querySelector('.submenu-content');if(content)content.hidden=!select.value;});
}
function wireMenuRow(row){wireMenuContent(row);row.querySelector('[name="parent[]"]').addEventListener('change',event=>{event.target.dataset.selected=event.target.value;reindexMenu();});row.querySelector('[name="label[]"]').addEventListener('change',reindexMenu);row.querySelector('[data-remove]').addEventListener('click',()=>{row.remove();reindexMenu();});for(const dir of ['up','down'])row.querySelector(`[data-${dir}]`).addEventListener('click',()=>{const sibling=dir==='up'?row.previousElementSibling:row.nextElementSibling;if(sibling){if(dir==='up')sibling.before(row);else sibling.after(row);reindexMenu();}});}
if(menuEditor){menuEditor.querySelectorAll('.menu-item').forEach(wireMenuRow);reindexMenu();document.querySelector('#add-menu-item').addEventListener('click',()=>{if(menuEditor.children.length>=12){toast(tr('Use up to 12 menu items.'));return;}const row=document.querySelector('#menu-template').content.firstElementChild.cloneNode(true);menuEditor.append(row);wireMenuRow(row);reindexMenu();row.querySelector('input[name="label[]"]').focus();});menuEditor.closest('form').addEventListener('submit',reindexMenu,{capture:true});}
document.querySelectorAll('[data-copy-media]').forEach(button=>button.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(button.dataset.copyMedia);toast(tr(button.dataset.copyTarget?'Link copied.':'Markdown copied.'));}catch{const input=button.closest('.media-card').querySelector(button.dataset.copyTarget||'.media-markdown');input.focus();input.select();toast(tr(button.dataset.copyTarget?'Select and copy the link below.':'Select and copy the Markdown below.'));}}));
const mediaUpload=document.querySelector('#library-upload');
if(mediaUpload)mediaUpload.addEventListener('change',async()=>{if(!mediaUpload.files[0])return;const data=new FormData();data.set('image',mediaUpload.files[0]);mediaUpload.disabled=true;try{await request('upload',data);location.reload();}catch(error){document.querySelector('#admin-message').textContent=error.message;mediaUpload.disabled=false;}});

const tabEditor=document.querySelector('#tab-editor');
function reindexTabs(){tabEditor.querySelectorAll('.tab-item').forEach((row,index)=>{row.querySelectorAll('input[type=checkbox]').forEach(input=>input.value=index);});}
function wireTabRow(row){row.querySelector('[data-remove]').addEventListener('click',()=>{row.remove();reindexTabs();});for(const dir of ['up','down'])row.querySelector('[data-'+dir+']').addEventListener('click',()=>{const sibling=dir==='up'?row.previousElementSibling:row.nextElementSibling;if(sibling){if(dir==='up')sibling.before(row);else sibling.after(row);reindexTabs();}});}
if(tabEditor){tabEditor.querySelectorAll('.tab-item').forEach(wireTabRow);document.querySelector('#add-tab-item').addEventListener('click',()=>{if(tabEditor.children.length>=5){toast(tr('Use up to 5 mobile tabs.'));return;}const row=document.querySelector('#tab-template').content.firstElementChild.cloneNode(true);tabEditor.append(row);wireTabRow(row);reindexTabs();row.querySelector('[name="label[]"]').focus();});tabEditor.closest('form').addEventListener('submit',reindexTabs,{capture:true});}


function wireMenuContent(row){
 const hidden=row.querySelector('[name="content[]"]'),container=row.querySelector('.submenu-content-rows');if(!hidden||!container)return;
 let entries;try{entries=JSON.parse(hidden.value||'[]');}catch{entries=[];}
 const sync=()=>{hidden.value=JSON.stringify(entries);};
 function drawContent(){container.replaceChildren();entries.forEach((entry,index)=>{const line=document.createElement('div');line.className='submenu-text-row';for(const [field,label] of [['label','Text'],['url','URL (optional)']]){const wrap=document.createElement('label');wrap.textContent=tr(label);const input=document.createElement('input');input.value=entry[field]||'';input.maxLength=field==='label'?120:1000;input.placeholder=field==='url'?tr('Empty means plain text'):'';input.addEventListener('input',()=>{entry[field]=input.value;sync();});wrap.append(input);line.append(wrap);}const remove=document.createElement('button');remove.type='button';remove.className='text-button';remove.textContent=tr('Remove');remove.addEventListener('click',()=>{entries.splice(index,1);sync();drawContent();});line.append(remove);container.append(line);});}
 row.querySelector('[data-add-content]').addEventListener('click',()=>{if(entries.length>=20){toast(tr('Use up to 20 text items per submenu.'));return;}entries.push({label:'',url:''});sync();drawContent();container.lastElementChild.querySelector('input').focus();});drawContent();
}

const footerLinksEditor=document.querySelector('#footer-links-editor');
function wireFooterLink(row){row.querySelector('[data-remove]').addEventListener('click',()=>row.remove());for(const dir of ['up','down'])row.querySelector('[data-'+dir+']').addEventListener('click',()=>{const sibling=dir==='up'?row.previousElementSibling:row.nextElementSibling;if(sibling){if(dir==='up')sibling.before(row);else sibling.after(row);}});}
if(footerLinksEditor){footerLinksEditor.querySelectorAll('.footer-link-row').forEach(wireFooterLink);document.querySelector('#add-footer-link').addEventListener('click',()=>{if(footerLinksEditor.children.length>=12){toast(tr('Use up to 12 footer links.'));return;}const row=document.querySelector('#footer-link-template').content.firstElementChild.cloneNode(true);footerLinksEditor.append(row);wireFooterLink(row);row.querySelector('input').focus();});}

// Selection is scoped to this list and this visible page, never to hidden results.
document.querySelectorAll('[data-bulk]').forEach(toolbar=>{
 const entity=toolbar.dataset.bulk,scope=entity==='media'?document.querySelector('.admin-main'):toolbar.closest('.admin-panel');
 const items=()=>[...scope.querySelectorAll('[data-select-item]')],all=scope.querySelector('[data-select-all]'),operation=toolbar.querySelector('[data-bulk-operation]'),category=toolbar.querySelector('[data-bulk-category]'),apply=toolbar.querySelector('[data-bulk-apply]');
 const sync=()=>{const rows=items(),selected=rows.filter(input=>input.checked);if(all){all.checked=!!rows.length&&selected.length===rows.length;all.indeterminate=selected.length>0&&selected.length<rows.length;}toolbar.querySelector('[data-selection-count]').textContent=selected.length?tr('Selected')+' '+selected.length:'';apply.disabled=!selected.length||!operation.value;};
 all?.addEventListener('change',()=>{items().forEach(input=>input.checked=all.checked);sync();});items().forEach(input=>input.addEventListener('change',sync));
 operation.addEventListener('change',()=>{if(category)category.hidden=operation.value!=='category';sync();});
 apply.addEventListener('click',async()=>{const data=new FormData();data.set('entity',entity);data.set('operation',operation.value);if(category)data.set('category_id',category.value);items().filter(input=>input.checked).forEach(input=>data.append('ids[]',input.value));apply.disabled=true;try{await request('bulk',data);location.reload();}catch(error){document.querySelector('#admin-message').textContent=error.message;sync();}});sync();
});
