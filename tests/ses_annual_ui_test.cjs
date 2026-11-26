'use strict';
// Run the actual annual controller with a small DOM and one-use CSRF server.
const assert = require('assert'), fs = require('fs'), vm = require('vm'), path = require('path');
const turn = () => new Promise(r => setImmediate(r));
class Element {
  constructor(tag) { this.tagName = tag; this.children = []; this.dataset = {}; this.events = {}; this.value = ''; this.textContent = ''; this.disabled = false; }
  appendChild(n) { n.parent = this; this.children.push(n); return n; }
  removeChild(n) { this.children.splice(this.children.indexOf(n), 1); }
  remove() { if (this.parent) this.parent.removeChild(this); }
  get firstChild() { return this.children[0]; }
  setAttribute(k,v) { this[k] = v; }
  addEventListener(k,fn) { (this.events[k] ||= []).push(fn); }
  async emit(k, details = {}) { for (const fn of this.events[k] || []) await fn(Object.assign({ target: this, preventDefault() {} }, details)); }
  click() { if(this.download) downloads.push(this.download); return this.emit('click'); }
  querySelectorAll(selector) { const out = [], tags = selector.split(','); const visit = n => { for (const c of n.children) { if (tags.includes(c.tagName)) out.push(c); visit(c); } }; visit(this); return out; }
}
const source = fs.readFileSync(path.join(__dirname, '../assets/js/ses_annual.js'), 'utf8');
const ids = new Map(), root = new Element('div'), requests = [], used = new Set(), downloads = [];
ids.set('g4fr-ses-annual', root);
for (const m of source.matchAll(/el\('([^']+)'\)/g)) if (!ids.has(m[1])) { const n = new Element(m[1].includes('button') ? 'button' : 'div'); ids.set(m[1], n); root.appendChild(n); }
ids.get('annual-year').value = '2026';
const revision = 'a'.repeat(64), laterRevision = 'b'.repeat(64), records = [{ id: revision, month:'2026-06', archived_at:'2026-07-01T09:00:00-03:00', scope_label:'Entidade 1 <script>literal</script>', creator_id:7, visibility:'private', can_share:true }];
const report = {coverage:{available:1,requested:12},warnings:['Aviso preservado'],financial:{months:[{month:'2026-06',status:'unavailable',available:false,complete:false,gross_cents:null,net_cents:null}],coverage_label:'Nenhuma competência completa',subtotal_cents:{net:null},is_partial:true},metrics:{INS5:{label:'Taxa <literal>',months:[{month:'2026-06',value:0,target:2,status:'met',comparison:{delta_pp:null,reason:'Primeiro mês'}}]}}};
let token = 0, currentToken = '', failTokens = false, failActivity = false, exportPayload, monthlyMode='success';
const response = body => ({ok:true,status:200,text:async()=>JSON.stringify(body)});
class TestURL extends URL { static createObjectURL() { return 'blob:download'; } static revokeObjectURL() {} }
const context = {window:{G4FREPORTS_ANNUAL:{api_url:'/archive',csrf_url:'/csrf',export_url:'/export',monthly_export_url:'/monthly-export',sharing:{enabled:true,allowed:true},nonce:'nonce'},location:{href:'https://example.invalid/annual'}},document:{getElementById:id=>ids.get(id),createElement:tag=>new Element(tag),body:new Element('body')},Intl,Map,URL:TestURL,URLSearchParams,AbortController,setTimeout:()=>1,clearTimeout:()=>{},fetch:async(url,request={})=>{
  const u = new URL(url,'https://example.invalid'), body = Object.fromEntries(request.body || []); requests.push({u,body,request});
  assert.equal(request.credentials,'same-origin'); assert.equal(request.cache,'no-store');
  if(u.pathname==='/monthly-export'){
    assert.equal(request.method,'GET');assert.equal(u.searchParams.get('_ses_nonce'),'nonce');assert.equal(u.searchParams.get('month'),'2026-06');assert.equal(u.searchParams.get('id'),revision);
    assert.equal(Object.keys(body).length,0,'Saved downloads do not post report data');
    const kind=u.searchParams.get('format');assert(['docx','xlsx'].includes(kind));
    const type=kind==='docx'?'application/vnd.openxmlformats-officedocument.wordprocessingml.document':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    if(monthlyMode==='busy')return {ok:false,status:409,headers:{get:()=> 'application/json'},text:async()=>JSON.stringify({ok:false,error:'Outra exportação mensal está em andamento.'})};
    if(monthlyMode==='html')return {ok:true,status:200,headers:{get:()=> 'text/html'},text:async()=>'<html>GLPI login</html>',blob:async()=>{throw new Error('Login must not become a downloaded report');}};
    return {ok:true,status:200,headers:{get:()=>type},blob:async()=>({size:monthlyMode==='empty'?0:100}),text:async()=>''};
  }
  if (u.pathname === '/csrf') { if(failTokens) return {ok:false,status:403,text:async()=>'<html>expired</html>'}; currentToken = 'one-use-'+(++token); return response({ok:true,csrf_token:currentToken}); }
  if (request.method === 'POST') { assert.equal(body._glpi_csrf_token,currentToken); assert(!used.has(currentToken)); used.add(currentToken); assert.equal(body._ses_nonce,'nonce'); }
  if (u.pathname === '/export') { exportPayload = JSON.parse(body.payload); return {ok:true,status:200,headers:{get:()=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'},blob:async()=>({size:100})}; }
  if(body.action==='share') {
    assert.equal(body.id,revision); assert.equal(body.month,'2026-06');
    assert.deepEqual(Object.keys(body).sort(),['_glpi_csrf_token','_ses_nonce','action','id','month']);
    const index=records.findIndex(r=>r.id===body.id && !r.archive_ref);
    assert(index>=0,'Only an owned private entry is shared');
    records[index]={...records[index],archive_ref:'c'.repeat(64),visibility:'shared',can_share:false};
    return response({ok:true,record:records[index]});
  }
  if(body.action==='preview') return response({ok:true,report,analytics:{months:['2026-06'],findings:[{text:'Observação verificada <literal>'}],volumes:{tickets:{rows:[{value:500}]},calls:{rows:[{value:0}]}},activity:[{month:'2026-06',activity:null}],matrix:[{id:'INS5',cells:[report.metrics.INS5.months[0]]}]}});
  if(body.action==='prepare_activity') {
    assert(['2026-06','2025-11'].includes(body.month));
    if(!body.archive_ref) { assert.equal(body.id,laterRevision); assert.deepEqual(Object.keys(body).sort(),['_glpi_csrf_token','_ses_nonce','action','id','month']); }
    else { assert.equal(body.id,revision); assert(['c'.repeat(64),'d'.repeat(64),'e'.repeat(64)].includes(body.archive_ref)); assert.deepEqual(Object.keys(body).sort(),['_glpi_csrf_token','_ses_nonce','action','archive_ref','id','month']); }
    if (failActivity) return {ok:false,status:409,text:async()=>JSON.stringify({ok:false,error:'Outra preparação está em andamento.'})};
    return response({ok:true,activity:{schema_version:1,month:'2026-06'}});
  }
  assert.equal(u.searchParams.get('action'),'list'); assert.equal(u.searchParams.get('_ses_nonce'),'nonce'); return response({ok:true,revisions:records});
}};
vm.createContext(context); vm.runInContext(source,context);
const text = n => n.textContent + n.children.map(text).join(' ');
(async()=>{
  await turn(); await turn();
  assert.equal(root.querySelectorAll('input').length,12);
  assert.equal(root.querySelectorAll('select').length,12);
  assert(root.querySelectorAll('input').every(n=>n.checked),'Year includes missing months explicitly');
  assert.equal(ids.get('annual-word').disabled,true,'Export requires review');
  const june = root.querySelectorAll('select')[5]; june.value = revision; await june.emit('change');
  await ids.get('annual-preview-button').emit('click');
  assert.equal(ids.get('annual-word').disabled,false);
  assert(text(ids.get('annual-preview-body')).includes('Total líquido não apurado'));
  assert(!text(ids.get('annual-preview-body')).includes('R$'),'Unknown finances never become zero');
  assert(text(ids.get('annual-preview-body')).includes('0,00%'),'Actual metric zero remains visible');
  assert(text(ids.get('annual-preview-body')).includes('500'),'Monthly ticket volume is visible in the overview');
  assert(text(ids.get('annual-preview-body')).includes('Observação verificada <literal>'),'Findings stay literal text');
  assert(text(ids.get('annual-preview-body')).includes('Mapas diários preparados: 0 de 1'),'Unprepared daily evidence is explicit');
  assert(text(ids.get('annual-preview-body')).includes('encerramento não confirmado'),'Unknown period closure is explicit');
  await ids.get('annual-word').emit('click'); await turn(); await turn();
  assert.equal(exportPayload.months.length,12); assert.equal(exportPayload.selections[0].id,revision);
  assert(!requests.some(r=>r.u.searchParams.get('action')==='generate'),'No extraction call');
  // A new archive appears after review. Export must keep the pinned ID.
  records.unshift({...records[0],id:laterRevision});
  await ids.get('annual-year-form').emit('submit'); await ids.get('annual-word').emit('click'); await turn(); await turn();
  assert.equal(exportPayload.selections[0].id,revision);
  const juneNew = root.querySelectorAll('select')[5]; juneNew.value=laterRevision; await juneNew.emit('change');
  assert(ids.get('annual-word').disabled,'Changing a revision invalidates reviewed selection');
  failTokens=true; const posts=requests.filter(r=>r.request.method==='POST').length;
  await ids.get('annual-preview-button').emit('click');
  assert.equal(requests.filter(r=>r.request.method==='POST').length,posts,'Failed CSRF refresh prevents dispatch');
  failTokens=false; report.coverage.available=0; await ids.get('annual-preview-button').emit('click');
  assert(ids.get('annual-word').disabled && ids.get('annual-excel').disabled,'All-missing preview cannot export an empty report');
  report.coverage.available=1; report.metrics.INS5.months[0].period_completeness='provisional'; await ids.get('annual-preview-button').emit('click');
  assert(text(ids.get('annual-preview-body')).includes('mês provisório'),'Open month remains explicitly provisional');
  assert(!ids.get('annual-activity-button').disabled);
  const activityBefore = requests.filter(r=>r.body.action==='prepare_activity').length;
  await ids.get('annual-activity-button').emit('click');
  assert.equal(requests.filter(r=>r.body.action==='prepare_activity').length,activityBefore+1,'Only selected revision is prepared');
  assert(!ids.get('annual-word').disabled,'Successful preparation refreshes the reviewed comparison');
  failActivity=true;
  await ids.get('annual-activity-button').emit('click');
  assert(ids.get('annual-word').disabled,'Failed preparation invalidates prior review');
  assert(text(ids.get('annual-status')).includes('Outra preparação'));
  assert.equal(requests.filter(r=>r.body.action==='prepare_activity').length,activityBefore+2,'No automatic retries after contention');
  failActivity=false;
  await ids.get('annual-clear').emit('click');
  assert(ids.get('annual-preview-button').disabled); assert(ids.get('annual-activity-button').disabled); assert(root.querySelectorAll('input').every(n=>!n.checked));
  // Year navigation preserves selections, with an explicit24-month bound.
  await ids.get('annual-all').emit('click'); ids.get('annual-year').value='2025'; await ids.get('annual-year-form').emit('submit'); await ids.get('annual-all').emit('click');
  assert(text(ids.get('annual-selection-count')).startsWith('24 '));
  ids.get('annual-year').value='2024'; await ids.get('annual-year-form').emit('submit'); await ids.get('annual-all').emit('click');
  assert(text(ids.get('annual-selection-count')).startsWith('24 ')); assert(text(ids.get('annual-status')).includes('máximo 24'));
  // Saved monthly downloads are available without annual review or map preparation.
  await ids.get('annual-clear').emit('click');
  records.push({...records.find(r=>r.id===revision),archive_ref:'d'.repeat(64),creator_id:8,visibility:'shared',can_share:false});
  records.push({...records.find(r=>r.id===revision),month:'2025-11',archive_ref:'e'.repeat(64),creator_id:9,visibility:'shared',can_share:false});
  ids.get('annual-year').value='2026'; await ids.get('annual-year-form').emit('submit');
  let savedJune=root.querySelectorAll('select')[5];
  assert(savedJune.children.some(o=>o.value===revision)&&savedJune.children.some(o=>o.value==='d'.repeat(64)),'Identical content under different authors has distinct selection options');
  savedJune.value=revision; await savedJune.emit('change');
  const row=savedJune.parent.parent;
  let links=row.querySelectorAll('a'); assert.equal(links.length,3);
  for(const [i,format] of ['docx','xlsx','json'].entries()){
    const u=new URL(links[i].href); assert.equal(u.pathname,format==='json'?'/archive':'/monthly-export');
    assert.equal(u.searchParams.get('id'),revision);assert.equal(u.searchParams.get('month'),'2026-06');assert.equal(u.searchParams.get('_ses_nonce'),'nonce');
    assert.equal(u.searchParams.has('archive_ref'),false);
    if(format==='json') assert.equal(u.searchParams.get('action'),'download_snapshot'); else assert.equal(u.searchParams.get('format'),format);
  }
  assert(text(row).includes('Word mensal')&&text(row).includes('Excel mensal'),'Download labels clearly identify complete monthly reports');
  assert(ids.get('annual-word').disabled,'Monthly downloads require no annual preview');
  let downloadCount=downloads.length, savedRequests=requests.filter(r=>r.u.pathname==='/monthly-export').length;
  assert.equal(savedRequests,0,'Rendering a saved revision never downloads it automatically');
  await links[0].emit('click');
  assert.equal(downloads.length,downloadCount+1);assert.equal(downloads.at(-1),'Relatorio_Mensal_SES_2026-06-rev-'+revision.slice(0,12)+'.docx');
  assert(text(ids.get('annual-status')).includes('Relatório mensal Word'));
  assert(root.querySelectorAll('select').every(s=>!s.disabled),'Saved download releases UI controls');
  monthlyMode='busy';savedRequests=requests.filter(r=>r.u.pathname==='/monthly-export').length;downloadCount=downloads.length;
  await links[1].emit('click');await turn();await turn();
  assert.equal(requests.filter(r=>r.u.pathname==='/monthly-export').length,savedRequests+1,'Busy monthly export is not retried automatically');
  assert.equal(downloads.length,downloadCount);assert(text(ids.get('annual-status')).includes('Outra exportação mensal'));
  assert.equal(root.querySelectorAll('select')[5].value,revision,'409 keeps the user on the same saved revision');
  for(const mode of ['html','empty']){monthlyMode=mode;await links[0].emit('click');assert.equal(downloads.length,downloadCount,'Invalid or empty response is never downloaded: '+mode);}
  monthlyMode='success';
  let prevented=false;savedRequests=requests.filter(r=>r.u.pathname==='/monthly-export').length;
  await links[0].emit('click',{ctrlKey:true,preventDefault(){prevented=true;}});
  assert(!prevented&&requests.filter(r=>r.u.pathname==='/monthly-export').length===savedRequests,'Modified click retains the standard href fallback');
  assert.equal(requests.filter(r=>r.body.action==='share').length,0,'Viewing or selecting never shares an archive');
  await ids.get('annual-preview-button').emit('click');
  const share=row.querySelectorAll('button').find(b=>b.textContent==='Disponibilizar à equipe');assert(share);
  await share.emit('click');
  assert.equal(requests.filter(r=>r.body.action==='share').length,1,'Sharing requires one explicit CSRF-protected action');
  assert(ids.get('annual-word').disabled,'New shared reference invalidates the prior review');
  savedJune=root.querySelectorAll('select')[5];assert.equal(savedJune.value,'c'.repeat(64));
  links=savedJune.parent.parent.querySelectorAll('a');assert(links.every(a=>new URL(a.href).searchParams.get('archive_ref')==='c'.repeat(64)));
  await links[1].emit('click');
  const savedShared=requests.filter(r=>r.u.pathname==='/monthly-export').at(-1);
  assert.equal(savedShared.u.searchParams.get('archive_ref'),'c'.repeat(64));assert.equal(savedShared.u.searchParams.get('format'),'xlsx');
  assert.equal(downloads.at(-1),'Indicadores_SES_2026-06-rev-'+revision.slice(0,12)+'.xlsx');
  assert(!savedJune.parent.parent.querySelectorAll('button').some(b=>b.textContent==='Disponibilizar à equipe'),'Published version has no redundant share action');
  // Selecting the other creator's identical snapshot preserves that precise shared reference.
  savedJune.value='d'.repeat(64);await savedJune.emit('change');
  ids.get('annual-year').value='2025';await ids.get('annual-year-form').emit('submit');
  const november=root.querySelectorAll('select')[10];november.value='e'.repeat(64);await november.emit('change');
  await ids.get('annual-preview-button').emit('click');await ids.get('annual-word').emit('click');await turn();await turn();
  assert.deepEqual(exportPayload.selections,[{month:'2025-11',id:revision,archive_ref:'e'.repeat(64)},{month:'2026-06',id:revision,archive_ref:'d'.repeat(64)}],'Cross-year selections retain each original content hash and authorized shared reference');
  const beforeSharedPrepare=requests.filter(r=>r.body.action==='prepare_activity').length;
  await ids.get('annual-activity-button').emit('click');
  assert.equal(requests.filter(r=>r.body.action==='prepare_activity').length,beforeSharedPrepare+2);
  assert.deepEqual(requests.filter(r=>r.body.action==='prepare_activity').slice(-2).map(r=>r.body.archive_ref),['e'.repeat(64),'d'.repeat(64)],'Preparation also uses the selected shared reference');
  ids.get('annual-year').value='2026';await ids.get('annual-year-form').emit('submit');
  assert.equal(root.querySelectorAll('select')[5].value,'d'.repeat(64),'Year reload restores the pinned cross-owner option');
  records.splice(records.findIndex(r=>r.archive_ref==='d'.repeat(64)),1);
  await ids.get('annual-year-form').emit('submit');
  const unavailableJune=root.querySelectorAll('select')[5];
  assert.equal(unavailableJune.value,'d'.repeat(64),'An unavailable selected reference is not replaced with another author’s identical hash');
  assert(unavailableJune.children.some(o=>o.textContent.includes('indisponível')));
  assert.equal(unavailableJune.parent.parent.querySelectorAll('a').length,0,'Unavailable entries have no misleading fallback download links');
  assert(!requests.some(r=>r.u.searchParams.get('action')==='generate'),'Historical monthly downloads never regenerate operational data');
  assert(!source.includes('innerHTML'),'Stored narratives use text nodes');
  console.log('Annual UI: monthly downloads, explicit sharing, duplicate-content authors, cross-year shared references, pinned exports, daily preparation, missing/zero values, CSRF and no extraction passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
