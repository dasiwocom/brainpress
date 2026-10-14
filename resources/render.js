import katex from 'katex';
import mermaid from 'mermaid';
let sequence = 0;
let queue = Promise.resolve();
const configure = () => mermaid.initialize({startOnLoad:false,securityLevel:'strict',theme:document.documentElement.dataset.theme === 'dark' ? 'dark' : 'default',suppressErrorRendering:true,maxTextSize:50000,flowchart:{htmlLabels:false},fontFamily:'Arial, sans-serif'});
async function render(root) {
  configure();
  for(const code of root.querySelectorAll('pre > code:not(.language-mermaid)')) {
    const pre=code.parentElement;if(pre.parentElement.classList.contains('code-container'))continue;
    const wrapper=document.createElement('div');wrapper.className='code-container';pre.before(wrapper);wrapper.append(pre);
    const button=document.createElement('button');button.type='button';button.className='code-copy';button.textContent='Copy';button.setAttribute('aria-label','Copy code');
    button.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(code.textContent);button.textContent='Copied';}catch{button.textContent='Copy failed';}setTimeout(()=>button.textContent='Copy',1800);});wrapper.append(button);
  }
  for (const element of root.querySelectorAll('.math-source:not([data-rendered])')) {
    try { katex.render(element.textContent,element,{displayMode:element.dataset.display==='true',throwOnError:true,trust:false,maxExpand:1000}); element.dataset.rendered='true'; }
    catch { element.classList.add('render-error'); element.title='Invalid or unsupported LaTeX. Source is preserved.'; }
  }
  for (const code of root.querySelectorAll('pre > code.language-mermaid')) {
    const pre=code.parentElement; const figure=document.createElement('figure'); figure.className='diagram'; figure.dataset.source=code.textContent; pre.replaceWith(figure);
  }
  for (const figure of root.querySelectorAll('.diagram[data-source]')) {
    try { const {svg}=await mermaid.render(`bp-diagram-${++sequence}`,figure.dataset.source); if(figure.isConnected) {figure.innerHTML=svg;figure.classList.remove('render-error');} }
    catch { figure.textContent='Unable to render this diagram. Check the Mermaid syntax.\n\n'+figure.dataset.source; figure.classList.add('render-error'); }
  }
}
window.renderRichContent = root => { queue=queue.catch(()=>{}).then(()=>render(root)); return queue; };
window.renderRichContent(document);
document.addEventListener('brainpress:theme',()=>window.renderRichContent(document));
