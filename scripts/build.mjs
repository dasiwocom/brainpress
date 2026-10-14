import {build} from 'esbuild';
import {cp,mkdir} from 'node:fs/promises';
await mkdir('public/assets/vendor',{recursive:true});
await build({entryPoints:['resources/render.js'],bundle:true,format:'esm',splitting:true,outdir:'public/assets/vendor',minify:true,chunkNames:'chunks/[name]-[hash]',legalComments:'linked'});
await cp('node_modules/katex/dist/katex.min.css','public/assets/vendor/katex.min.css');
await cp('node_modules/katex/dist/fonts','public/assets/vendor/fonts',{recursive:true});
await cp('node_modules/katex/LICENSE','public/assets/vendor/KATEX-LICENSE.txt');
await cp('node_modules/mermaid/LICENSE','public/assets/vendor/MERMAID-LICENSE.txt');
