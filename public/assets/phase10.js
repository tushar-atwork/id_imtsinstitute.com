/* IMTS CRM Phase 10 – accessible navigation and progressive UI enhancement. No changes to business logic. */
(() => {
  'use strict';
  const start = () => {
    const page = (location.pathname.split('/').pop() || 'index.php').toLowerCase();
    if (document.body.classList.contains('login-body') || page === 'login.php') {
      const subtitle = document.querySelector('.login-card p');
      if (subtitle) subtitle.textContent = 'Secure admission recovery workspace';
      const card = document.querySelector('.login-card');
      if (card) card.insertAdjacentHTML('afterbegin', '<div class="login-eyebrow">IMTS · OPERATIONS PLATFORM <span class="online-dot"></span></div>');
      return;
    }
    const oldHeader = document.querySelector('body > header');
    const main = document.querySelector('body > main');
    if (!oldHeader || !main) return;
    const role = (document.querySelector('meta[name="crm-role"]')?.content || '').toLowerCase();
    const isAdmin = role === 'admin';
    const userName = document.querySelector('meta[name="crm-user"]')?.content || oldHeader.querySelector('.top-actions span')?.textContent?.trim() || 'My account';
    const allLinks = [
      ['index.php','Admissions','grid'],['followups.php','Follow-up queue','clock'],['workspace.php','Workspace','layers'],
      ['bulk-assign.php','Bulk assignment','users','admin'],['reports.php','Management reports','chart','admin'],['analytics.php','Analysis Report','chart'],
      ['performance.php','Performance','activity'],['assignment-history.php','Assignment history','history','admin'],
      ['sync-health.php','ERP sync health','refresh','admin']
    ];
    const glyph = {grid:'▦',clock:'◷',layers:'▤',users:'♧',chart:'◫',activity:'⌁',history:'↺',refresh:'⟳'};
    const labels = Object.fromEntries(allLinks.map(([href,label])=>[href,label]));
    labels['student.php']='Student workspace';
    const currentLabel = labels[page] || 'Admissions';
    const side = document.createElement('aside'); side.className='futura-sidebar';side.id='crm-sidebar';
    const logo = document.createElement('a');logo.href='index.php';logo.className='futura-logo';
    logo.innerHTML='<span class="futura-mark">i<span>✳</span></span><span class="futura-logotype">IMTS <small>RECOVERY OS</small></span>';
    side.append(logo);
    const nav = document.createElement('nav');nav.className='futura-nav';nav.setAttribute('aria-label','Primary navigation');
    let group = '';
    for(const [url,label,icon,permission] of allLinks){
      if(permission === 'admin' && !isAdmin) continue;
      const next = ['index.php','followups.php','workspace.php'].includes(url) ? 'WORKSPACE' : ['bulk-assign.php','reports.php','analytics.php','performance.php'].includes(url) ? 'MANAGEMENT' : 'SYSTEM';
      if(next !== group){group=next;const heading=document.createElement('div');heading.className='nav-category';heading.textContent=next;nav.append(heading);}
      const a=document.createElement('a');a.href=url;a.className='futura-nav-link'+(page===url?' is-current':'');
      if(page===url) a.setAttribute('aria-current','page');a.innerHTML='<span class="nav-glyph" aria-hidden="true">'+glyph[icon]+'</span><span>'+label+'</span><span class="nav-chevron">›</span>';nav.append(a);
    }
    side.append(nav);
    const footer=document.createElement('div');footer.className='futura-sidefooter';footer.innerHTML='<div class="futura-live"><span class="online-dot"></span> Connected workspace</div><div class="futura-sidehint">IMTS · Admission Recovery</div>';
    side.append(footer);
    const header = document.createElement('header');header.className='futura-topbar';
    const left=document.createElement('div');left.className='futura-top-left';
    const menu=document.createElement('button');menu.type='button';menu.className='futura-menu';menu.setAttribute('aria-label','Open navigation');menu.setAttribute('aria-controls','crm-sidebar');menu.setAttribute('aria-expanded','false');menu.innerHTML='☰';
    const breadcrumb=document.createElement('div');breadcrumb.className='futura-breadcrumb';breadcrumb.innerHTML='<span>IMTS / </span><strong></strong>';
    breadcrumb.querySelector('strong').textContent=currentLabel;left.append(menu,breadcrumb);header.append(left);
    const right=document.createElement('div');right.className='futura-top-right';
    const today=document.createElement('span');today.className='futura-date';today.textContent=new Intl.DateTimeFormat('en-IN',{day:'numeric',month:'short',year:'numeric'}).format(new Date());right.append(today);
    const avatar=document.createElement('span');avatar.className='futura-avatar';avatar.textContent=(userName.match(/\b\p{L}/gu)||['I']).slice(0,2).join('').toUpperCase();
    const identity=document.createElement('span');identity.className='futura-user';identity.textContent=userName;
    const logout=document.createElement('a');logout.href='logout.php';logout.className='futura-logout';logout.textContent='Sign out';right.append(avatar,identity,logout);header.append(right);
    const scrim=document.createElement('button');scrim.className='futura-scrim';scrim.setAttribute('aria-label','Close navigation');scrim.type='button';
    document.body.classList.add('futura-app');
    oldHeader.replaceWith(header);document.body.prepend(side,scrim);
    const close=()=>{document.body.classList.remove('sidebar-open');menu.setAttribute('aria-expanded','false');menu.setAttribute('aria-label','Open navigation');};
    menu.addEventListener('click',()=>{const now=document.body.classList.toggle('sidebar-open');menu.setAttribute('aria-expanded',String(now));menu.setAttribute('aria-label',now?'Close navigation':'Open navigation');});
    scrim.addEventListener('click',close);document.addEventListener('keydown',e=>{if(e.key==='Escape')close();if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){const search=document.querySelector('.filters input[name="q"],input[name="q"]');if(search){e.preventDefault();search.focus();}}});
    const heading=main.querySelector('h1');if(heading){const intro=document.createElement('div');intro.className='futura-page-eyebrow';intro.textContent='ADMISSION RECOVERY / '+currentLabel.toUpperCase();heading.before(intro);}
    // Give every data cell a heading to enable clean, semantic mobile cards. Preserve real table markup and links.
    document.querySelectorAll('table').forEach(table=>{
      const heads=[...table.querySelectorAll('thead tr:first-child th')].map(x=>x.textContent.trim());
      if(!heads.length)return;
      table.classList.add('futura-data-table');table.querySelectorAll('tbody tr').forEach(tr=>{
        const cells=[...tr.children].filter(x=>x.tagName==='TD');if(cells.length!==heads.length)return;
        cells.forEach((td,i)=>td.dataset.label=heads[i]||'Select');
      });
      if(table.closest('.table-wrap,.scroll'))table.closest('.table-wrap,.scroll').classList.add('futura-table-wrap');
    });
    if(page==='index.php' || page===''){ 
      main.classList.add('futura-admissions');const hero=main.querySelector('.hero');if(hero){const p=hero.querySelector('h1');if(p)p.textContent='Admission command center';}
      const firstCard=main.querySelector('.cards .card');firstCard?.classList.add('futura-primary-stat');
      const search=main.querySelector('.filters input[name="q"]');if(search)search.placeholder='Search students, admission no., phone or email  ·  Ctrl+K';
    }
    if(page==='student.php'){
      main.classList.add('futura-student');const p2Head=main.querySelector('.p2-head');if(p2Head){const initial=main.querySelector('.p2-head h1')?.textContent?.trim()?.[0] || 'S';const portrait=document.createElement('span');portrait.className='student-monogram';portrait.textContent=initial;p2Head.prepend(portrait);}
      const facts=main.querySelector('.p2-facts');if(facts){facts.querySelectorAll(':scope > div').forEach((item,i)=>{item.classList.add('fact-item');if(i===0)item.classList.add('fact-first');});}
      const followUp=main.querySelector('.p2-stack > .panel:last-child');if(followUp)followUp.classList.add('futura-followup-form');
      const hist=main.querySelector('.p2-history');if(hist){hist.querySelector('h2')?.insertAdjacentHTML('beforeend',' <span class="history-caption">Latest activity</span>');}
    }
    main.querySelectorAll('section.panel').forEach((panel,i)=>{panel.style.setProperty('--reveal-index',String(Math.min(i,4)));});
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
