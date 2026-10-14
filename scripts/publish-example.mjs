import fs from 'node:fs/promises';
const base='http://127.0.0.1:8081';
const page=await fetch(base+'/?write'); const html=await page.text();
const csrf=html.match(/name="csrf" content="([^"]+)"/)[1],cookie=page.headers.get('set-cookie').split(';')[0];
const category=html.match(/<option value="(\d+)"/)[1];
const source=(await fs.readFile('resources/example.md','utf8')).replace(/^\uFEFF/,'');
const form=new FormData();for(const [k,v] of Object.entries({action:'save',csrf,category_id:category,title:'Markdown, diagrams & mathematics',markdown:source}))form.set(k,v);
const result=await fetch(base,{method:'POST',headers:{cookie},body:form});const data=await result.json();if(!result.ok)throw Error(JSON.stringify(data)); console.log(base+data.url);
for(const path of ['/', '/?write','/?categories','/?view=ai','/?view=messages','/?view=profile',data.url,'/?q=mathematics']){const r=await fetch(base+path);if(r.status!==200)throw Error(path+' failed');console.log('PASS '+path);}
