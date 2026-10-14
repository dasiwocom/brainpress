'use strict';
const messages=JSON.parse(document.querySelector('#translations')?.textContent||'{}');
function tr(text){return messages[text]||text;}
function uiCount(number,kind){return document.documentElement.lang==='zh-CN'?number+(kind==='characters'?' 个字符':' 篇文章'):number+' '+(kind==='characters'?'characters':number===1?'post':'posts');}
