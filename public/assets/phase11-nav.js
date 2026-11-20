/* Adds server-protected team views to the existing Phase 10 responsive sidebar. */
(()=>{'use strict';const enhance=()=>{const nav=document.querySelector('.futura-nav');if(!nav)return;const role=document.querySelector('meta[name="crm-role"]')?.content||'';const page=location.pathname.split('/').pop();const links=[['team-queue.php','Team queue','◷'],['work-report.php','Work report','▤']];if(role==='admin')links.push(['team-bulk-assign.php','Team bulk assignment','♧'],['team-settings.php','Team status & roles','⚙'],['team-settings.php#create-user','+ Create employee','＋']);if(role==='document_collection'||role==='verification'||role==='form_filled'){nav.querySelectorAll('a[href="performance.php"],a[href="workspace.php"]').forEach(a=>a.remove());}const heading=document.createElement('div');heading.className='nav-category';heading.textContent='TEAM WORKFLOW';nav.append(heading);for(const [href,label,glyph] of links){const a=document.createElement('a');a.href=href;a.className='futura-nav-link'+(href.split('#')[0]===page && !href.includes('#')?' is-current':'');if(href===page)a.setAttribute('aria-current','page');const i=document.createElement('span');i.className='nav-glyph';i.setAttribute('aria-hidden','true');i.textContent=glyph;const text=document.createElement('span');text.textContent=label;const chevron=document.createElement('span');chevron.className='nav-chevron';chevron.textContent='›';a.append(i,text,chevron);nav.append(a);}if(page==='team-queue.php'||page==='team-settings.php'||page==='team-bulk-assign.php'){const crumb=document.querySelector('.futura-breadcrumb strong');if(crumb)crumb.textContent=page==='team-queue.php'?'Team queue':page==='team-bulk-assign.php'?'Team bulk assignment':'Team status & roles';}};if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',enhance,{once:true});else enhance();})();

// Open a student from any list row without intercepting its controls.
(()=>{
 'use strict';
 document.addEventListener('dblclick',event=>{
  if(!(event.target instanceof Element))return;
  if(event.target.closest('a,button,input,select,textarea,label,[contenteditable="true"],[role="button"]'))return;
  const row=event.target.closest('tbody tr');
  if(!row)return;
  const link=Array.from(row.querySelectorAll('a[href]')).find(anchor=>{
   const url=new URL(anchor.href,location.href);
   return url.origin===location.origin && url.pathname.split('/').pop()==='student.php' && /^[1-9]\d*$/.test(url.searchParams.get('id')||'');
  });
  if(!link)return;
  event.preventDefault();
  location.assign(link.href);
 });
})();
