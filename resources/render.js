import mermaid from 'mermaid';
import katex from 'katex';
const tr=text=>window.tr?.(text)||text;
let sequence=0,queue=Promise.resolve();
const theme=()=>document.documentElement.dataset.theme==='dark'?'dark':'default';
async function render(root){
 for(const code of root.querySelectorAll('pre > code.language-mermaid')){const figure=document.createElement('figure');figure.className='diagram';figure.dataset.source=code.textContent;figure.textContent=code.textContent;code.parentElement.replaceWith(figure);}
 for(const code of root.querySelectorAll('pre > code:not(.language-mermaid)')){const pre=code.parentElement;if(pre.parentElement.classList.contains('code-container'))continue;const wrapper=document.createElement('div');wrapper.className='code-container';pre.before(wrapper);wrapper.append(pre);const button=document.createElement('button');button.type='button';button.className='code-copy';const copyIcon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V3H3v13h5"/></svg>';button.innerHTML=copyIcon;button.setAttribute('aria-label',tr('Copy code'));button.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(code.textContent);button.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>';button.setAttribute('aria-label',tr('Copied'));}catch{button.setAttribute('aria-label',tr('Copy failed'));}setTimeout(()=>{button.innerHTML=copyIcon;button.setAttribute('aria-label',tr('Copy code'));},1800);});wrapper.append(button);}
 const math=[...root.querySelectorAll('.math-source:not([data-rendered])')];
 if(math.length){for(const element of math){try{katex.render(element.textContent,element,{displayMode:element.dataset.display==='true',throwOnError:true,trust:false,maxExpand:1000});element.dataset.rendered='true';}catch{element.classList.add('render-error');element.title=tr('Invalid or unsupported LaTeX. Source is preserved.');}}}
 const color=theme();
 for(const figure of root.querySelectorAll('.diagram[data-source]')){
  if(figure.dataset.renderedTheme===color)continue;
  const source=figure.dataset.source;
  try{
   mermaid.initialize({startOnLoad:false,securityLevel:'strict',theme:color,suppressErrorRendering:true,maxTextSize:50000,flowchart:{htmlLabels:false},fontFamily:'Arial, sans-serif'});const svg=(await mermaid.render(`bp-diagram-${++sequence}`,source)).svg;
   if(figure.isConnected){figure.innerHTML=svg;figure.dataset.renderedTheme=color;figure.classList.remove('render-error','diagram-loading');figure.removeAttribute('aria-label');}
  }catch{figure.textContent=tr('Unable to render this diagram. Check the Mermaid syntax.')+'\n\n'+source;figure.classList.remove('diagram-loading');figure.classList.add('render-error');}
 }
}
window.renderRichContent=root=>{queue=queue.catch(()=>{}).then(()=>render(root));return queue;};
window.renderRichContent(document);
document.addEventListener('brainpress:theme',()=>window.renderRichContent(document));
