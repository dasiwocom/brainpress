'use strict';
document.querySelectorAll('[data-admin-action]').forEach(form=>form.addEventListener('submit',async event=>{
  event.preventDefault(); const button=event.submitter||form.querySelector('button[type=submit]');if(button)button.disabled=true;
  const message=form.querySelector('[role=status]')||document.querySelector('#admin-message');message.textContent='Saving…';
  try{const data=new FormData(form);const action=button?.dataset.action||form.dataset.adminAction;const result=await request(action,data);if(form.hasAttribute('data-stay')){message.textContent=result.message||'Saved.';}else location.reload();}
  catch(error){message.textContent=error.message;}finally{if(button)button.disabled=false;}
}));
const menuEditor=document.querySelector('#menu-editor');
function reindexMenu(){menuEditor.querySelectorAll('.menu-item').forEach((row,index)=>{row.querySelector('[type=checkbox]').value=index;row.querySelector('[name="label[]"]').setAttribute('aria-label',`Menu label ${index+1}`);row.querySelector('[name="url[]"]').setAttribute('aria-label',`Menu URL ${index+1}`);});}
function wireMenuRow(row){row.querySelector('[data-remove]').addEventListener('click',()=>{row.remove();reindexMenu();});for(const dir of ['up','down'])row.querySelector(`[data-${dir}]`).addEventListener('click',()=>{const sibling=dir==='up'?row.previousElementSibling:row.nextElementSibling;if(sibling){if(dir==='up')sibling.before(row);else sibling.after(row);reindexMenu();}});}
if(menuEditor){menuEditor.querySelectorAll('.menu-item').forEach(wireMenuRow);document.querySelector('#add-menu-item').addEventListener('click',()=>{if(menuEditor.children.length>=12){toast('Use up to 12 menu items.');return;}const row=document.querySelector('#menu-template').content.firstElementChild.cloneNode(true);menuEditor.append(row);wireMenuRow(row);reindexMenu();row.querySelector('input[name="label[]"]').focus();});menuEditor.closest('form').addEventListener('submit',reindexMenu,{capture:true});}
document.querySelectorAll('[data-copy-media]').forEach(button=>button.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(button.dataset.copyMedia);toast('Markdown copied.');}catch{const input=button.closest('.media-card').querySelector('.media-markdown');input.focus();input.select();toast('Select and copy the Markdown below.');}}));
const mediaUpload=document.querySelector('#library-upload');
if(mediaUpload)mediaUpload.addEventListener('change',async()=>{if(!mediaUpload.files[0])return;const data=new FormData();data.set('image',mediaUpload.files[0]);mediaUpload.disabled=true;try{await request('upload',data);location.reload();}catch(error){document.querySelector('#admin-message').textContent=error.message;mediaUpload.disabled=false;}});
