import assert from 'node:assert/strict';
const base='http://127.0.0.1:8083';const first=await fetch(base);let html=await first.text();assert.equal(first.status,200,html.slice(0,250));const cookie=first.headers.get('set-cookie').split(';')[0];const csrf=html.match(/name="csrf" content="([^"]+)/)[1];
async function send(action,fields={}){const r=await fetch(base,{method:'POST',headers:{cookie},body:new URLSearchParams({action,csrf,...fields})});const text=await r.text();return[r.status,JSON.parse(text)];}
assert.equal((await fetch(base+'/admin/tabs')).status,200);
assert.ok(html.includes('mobile-menu-drawer'));assert.ok(html.includes('aria-current="page" aria-label="Home"'));
assert.equal((await send('menu_save',{'label[0]':'Header only','url[0]':'/','enabled[0]':'0'}))[0],200);
const menuBefore=await(await fetch(base+'/admin/menus')).text();
const tabs={'label[0]':'Categories QA','url[0]':'/?categories','icon[0]':'grid','enabled[0]':'0','label[1]':'Write QA','url[1]':'/?write','icon[1]':'plus','enabled[1]':'1','prominent[0]':'1'};
assert.equal((await send('tabs_save',tabs))[0],200);
html=await(await fetch(base+'/?categories')).text();const nav=html.match(/<nav class="mobile-tabs"[\s\S]*?<\/nav>/)[0];assert.ok(nav.includes('Categories QA'));assert.ok(!nav.includes('Header only'));assert.ok(nav.indexOf('Categories QA')<nav.indexOf('Write QA'));assert.ok(nav.includes('active" aria-current="page"'));
assert.equal((await(await fetch(base+'/admin/menus')).text()).match(/<form data-admin-action="menu_save"[\s\S]*?<\/form>/)[0],menuBefore.match(/<form data-admin-action="menu_save"[\s\S]*?<\/form>/)[0],'Tabs must not alter header menu');
for(const url of ['javascript:alert(1)','//example.com','/\\evil'])assert.equal((await send('tabs_save',{...tabs,'url[0]':url}))[0],422);
assert.equal((await send('tabs_save',{...tabs,'icon[0]':'invalid'}))[0],422);
assert.equal((await send('tabs_save',{...tabs,'prominent[1]':'0'}))[0],422);
assert.equal((await send('tabs_save',{'label[0]':'Hidden','url[0]':'/','icon[0]':'home'}))[0],200);html=await(await fetch(base)).text();assert.ok(!html.includes('<nav class="mobile-tabs"'));assert.ok(!html.includes('site-body has-mobile-tabs'));
assert.equal((await send('tabs_save',{}))[0],200);assert.ok(!(await(await fetch(base)).text()).includes('<nav class="mobile-tabs"'));
console.log('PASS independent mobile tabs: ordering, icons, visibility, disabling, selected state, prominent action and unsafe input rejection; header menu preserved');

