'use strict';
document.querySelectorAll('[data-auth-action]').forEach(form=>form.addEventListener('submit',async event=>{
 event.preventDefault();const button=form.querySelector('button[type=submit]'),message=form.querySelector('[role=status]');button.disabled=true;message.textContent='';
 try{const data=new FormData(form);data.set('action',form.dataset.authAction);data.set('csrf',document.querySelector('meta[name=csrf]').content);const response=await fetch('/',{method:'POST',body:data});const result=await response.json();if(!response.ok)throw new Error(result.error);location.href=result.url||'/account';}catch(error){message.textContent=error.message;button.disabled=false;}
}));
