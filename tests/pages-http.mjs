import assert from 'node:assert/strict';
const base='http://127.0.0.1:8082';let response=await fetch(base),html=await response.text();assert.equal(response.status,200,html.slice(0,200));
const cookie=response.headers.get('set-cookie').split(';')[0],csrf=html.match(/name="csrf" content="([^"]+)/)[1];
async function send(action,fields={}){const r=await fetch(base,{method:'POST',headers:{cookie},body:new URLSearchParams({action,csrf,...fields})});const text=await r.text();try{return[r.status,JSON.parse(text)];}catch{throw Error(text);}}
for(const url of ['/admin/pages','/admin/system','/?categories','/?edit_layout=1'])assert.equal((await fetch(base+url)).status,200,url);
for(const view of ['ai','messages','profile'])assert.equal((await fetch(base+'/?view='+view)).status,404);
assert.ok(!html.includes('feed-nav'));assert.ok(!html.includes('view=ai'));
const layout=[{type:'categories',zone:'main'},{type:'profile',zone:'sidebar',name:'Preview name'}];
let [status,result]=await send('layout_preview',{context:'home',widgets:JSON.stringify(layout),route:'?edit_layout=1'});assert.equal(status,200);assert.ok(result.main.includes('category-grid'));assert.ok(result.sidebar.includes('Preview name'));
assert.ok(!(await(await fetch(base)).text()).includes('Preview name'));
assert.equal((await send('layout_save',{context:'home',widgets:JSON.stringify(layout)}))[0],200);assert.ok((await(await fetch(base)).text()).includes('Preview name'));
assert.equal((await send('layout_save',{context:'home',widgets:JSON.stringify([{type:'toc',zone:'sidebar'}])}))[0],422);
assert.equal((await send('layout_save',{context:'home',reset:'1'}))[0],200);
const stamp=Date.now();assert.equal((await send('page_save',{title:'Landing '+stamp,slug:'landing-'+stamp,status:'published'}))[0],200);
html=await(await fetch(base+'/admin/pages')).text();const id=html.match(new RegExp('/admin/pages\\?page_edit=(\\d+)[\\s\\S]*?landing-'+stamp))?.[1];
// Resolve the new row using the page listing, independent of builtin rows.
const row=html.split('<tr>').find(r=>r.includes('Landing '+stamp));const pageId=row.match(/page_edit=(\d+)/)[1];
assert.equal((await send('layout_save',{context:'page:'+pageId,widgets:JSON.stringify([{type:'categories',zone:'main'}])}))[0],200);
assert.equal((await send('settings_save',{site_name:'QA',description:'Test',homepage:pageId}))[0],200);
html=await(await fetch(base)).text();assert.ok(html.includes('category-grid'));
assert.equal((await send('page_save',{id:pageId,title:'Landing '+stamp,slug:'landing-'+stamp,status:'draft'}))[0],422);
assert.equal((await send('settings_save',{site_name:'QA',description:'Test',homepage:'0'}))[0],200);
assert.equal((await send('page_save',{id:pageId,title:'Landing '+stamp,slug:'landing-'+stamp,status:'draft'}))[0],200);
assert.equal((await fetch(base+'/?page_slug=landing-'+stamp)).status,404);assert.equal((await fetch(base+'/?page_slug=landing-'+stamp+'&edit_layout=1')).status,200);
console.log('PASS page management, homepage setting, scoped live preview without persistence, save/reset, invalid widget constraints, draft visibility and retired routes');
