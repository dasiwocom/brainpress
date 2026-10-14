import {cp,mkdir,writeFile,access} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const destination=path.resolve(process.argv[2]||path.join(root,'dist','brainpress'));
if(destination===root||root.startsWith(destination+path.sep)||destination.startsWith(path.join(root,'public')+path.sep))throw new Error('Choose a separate output directory outside the public web root.');
try{await access(destination);throw new Error('Output directory already exists. Choose a new path to keep packages clean.');}catch(error){if(error.code!=='ENOENT')throw error;}
await mkdir(destination,{recursive:true});
for(const item of ['src','locales','public','vendor','deploy','composer.json','composer.lock','config.example.php','README.md','CHANGELOG.md']){
  await cp(path.join(root,item),path.join(destination,item),{recursive:true,filter:source=>{
    const relative=path.relative(root,source).replaceAll('\\','/');
    return !relative.startsWith('public/uploads/') || ['public/uploads/.gitkeep','public/uploads/.htaccess'].includes(relative);
  }});
}
await mkdir(path.join(destination,'storage'),{recursive:true});await writeFile(path.join(destination,'storage','.gitkeep'),'');
console.log('Clean deployment package: '+destination);
