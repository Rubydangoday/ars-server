'use strict';
window.ARSRenderMath=root=>{
 if(!window.renderMathInElement)return;
 root.querySelectorAll('.qtext,.pj-text,.qcard .choices,.qcard .answers,.pj-opts,.expl,.qc .content,.qc ul.o,.qc .ex').forEach(el=>{
  if(el.dataset.mathDone==='1')return;
  renderMathInElement(el,{delimiters:[{left:'$$',right:'$$',display:true},{left:'\\[',right:'\\]',display:true},{left:'\\(',right:'\\)',display:false}],throwOnError:false,trust:false,maxExpand:1000,strict:'warn'});el.dataset.mathDone='1';
 });
};
document.addEventListener('DOMContentLoaded',()=>window.ARSRenderMath(document));
