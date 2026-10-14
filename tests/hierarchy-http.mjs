import assert from 'node:assert/strict';const base='http://127.0.0.1:8082';let res=await fetch(base);let html=await res.text();assert.equal(res.status,200);const csrf=html.match(/name="csrf" content="([^"]+)/)[1],cookie=res.headers.get('set-cookie').split(';')[0];
async function send(action,fields){const body=new URLSearchParams({action,csrf});for(const [k,v]of Object.entries(fields)){if(Array.isArray(v))v.forEach(x=>body.append(k+'[]',x));else body.set(k,v);}const r=await fetch(base,{method:'POST',headers:{cookie},body});return[r.status,await r.json()];}
const stamp=Date.now();const [,parent]=await send('category',{name:'Root '+stamp});const [code,child]=await send('category',{name:'Child '+stamp,parent_id:parent.id});assert.equal(code,200);
assert.equal((await send('category',{name:'Third '+stamp,parent_id:child.id}))[0],422);
assert.equal((await send('category_rename',{id:parent.id,name:'Root '+stamp,parent_id:child.id}))[0],422);
assert.equal((await send('category_rename',{id:child.id,name:'Child '+stamp,parent_id:child.id}))[0],422);
html=await(await fetch(base+'/admin/categories')).text();assert.ok(html.includes('name="parent_id"'));assert.ok(html.includes(`value="${parent.id}" selected`));
const menu={label:['Root','Child'],url:['/','/?categories'],enabled:['0','1'],menu_id:['root','child'],parent:['','root']};assert.equal((await send('menu_save',menu))[0],200);html=await(await fetch(base+'/admin/menus')).text();assert.ok(html.includes('data-selected="root"'));
assert.equal((await send('menu_save',{...menu,parent:['child','root']}))[0],422);
assert.equal((await send('menu_save',{...menu,label:['A','B','C'],url:['/','/','/'],menu_id:['a','b','c'],parent:['','a','b']}))[0],422);
assert.equal((await send('category_delete',{id:parent.id}))[0],200);html=await(await fetch(base+'/admin/categories')).text();assert.ok(html.includes('Child '+stamp));
console.log('PASS two-level category/menu persistence, self/cycle/third-level rejection, parent-category deletion preserves child');
