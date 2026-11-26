const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
class Element {
  constructor(tag='div'){this.tagName=tag;this.children=[];this.events={};this.value='';this.disabled=false;this.textContent='';}
  appendChild(child){this.children.push(child);child.parent=this;return child}
  removeChild(child){this.children.splice(this.children.indexOf(child),1)}
  get firstChild(){return this.children[0]||null}
  setAttribute(k,v){this[k]=v}
  addEventListener(name,fn){this.events[name]=fn}
  querySelectorAll(){return all.filter(x=>['input','textarea','button'].includes(x.tagName))}
  reportValidity(){return true}
}
const all=[],els={};
const ids=['ses-financial-state','ses-financial-fronts','ses-financial-fronts-empty','ses-financial-month','ses-financial-deductions','ses-financial-adjustments','ses-financial-adjustments-empty','ses-save-financial','ses-financial-preview','g4fr-ses-financial-form','ses-add-front','ses-add-adjustment','ses-contract-total','ses-use-contract-baseline'];
ids.forEach(id=>{els[id]=new Element();els[id].id=id;all.push(els[id])});
let month='2026-06',busy=false,dirty=false,saved=0,captured,status,failNext=false;
const config={can_configure:true,financial_config:{contract_total:'562457.91',contract_fronts:[{id:'n2',label:'N2',profiles:['Analista Pleno','Analista Sênior','Nome alternativo'],contract_quantity:4,monthly_rate:'0.00',profile_lines:[{profile:'Analista Pleno',contract_quantity:3,monthly_rate:'9182.02'},{profile:'Analista Sênior',contract_quantity:1,monthly_rate:'14053.37'}]},{id:'legacy',label:'Legado',profiles:['Perfil salvo'],contract_quantity:2,monthly_rate:'100.00'}],months:{}},financial_revision:'r1',csrf_token:'csrf1',nonce:'nonce',financial_url:'/save'};
config.financial_baseline={contract_total:'562457.91',contract_fronts:[{id:'standard',label:'Padrão SES',profiles:['Perfil padrão'],contract_quantity:1,monthly_rate:'0.00',profile_lines:[{profile:'Perfil padrão',contract_quantity:1,monthly_rate:'100.00'}]}],months:{}};
const context={window:{crypto:require('crypto').webcrypto,confirm:()=>true},document:{getElementById:id=>els[id],createElement:tag=>{const e=new Element(tag);all.push(e);return e}},Intl,AbortController,URLSearchParams,setTimeout,clearTimeout,fetch:async(url,req)=>{captured=Object.fromEntries(req.body);if(failNext){failNext=false;return {ok:false,status:409,text:async()=>JSON.stringify({ok:false,error:'Conflito de versão',csrf_token:'csrf3'})}}return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,config:JSON.parse(captured.config),revision:'r2',csrf_token:'csrf2'})}}};
vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/js/ses_financial.js'),'utf8'),context);
let sharedPosts=0;
const editor=context.window.G4FSesFinancial.create({config,month:()=>month,isBusy:()=>busy,setBusy:v=>{busy=v;editor.setBusy(v)},onDirty:v=>dirty=v,onSaved:()=>saved++,status:message=>status=message,
  postWithCsrf:async(url,body,consume)=>{sharedPosts++;assert(!body.has('_glpi_csrf_token'),'Financial editor delegates token handling to shared transport');body.set('_glpi_csrf_token','shared-dispatch-token');return consume(await context.fetch(url,{method:'POST',body}));}});
function change(element,value){element.value=value;element.events.input.call(element)}
function descendants(element){return element.children.flatMap(child=>[child,...descendants(child)])}
function field(label){return descendants(els['ses-financial-fronts']).find(item=>item['aria-label']===label)}
function deduction(index){const row=els['ses-financial-deductions'].children[index-1];return {rate:row.children[1].firstChild,reason:row.children[2].firstChild}}
(async()=>{
  assert.equal(els['ses-financial-deductions'].children.length,12);
  assert.equal(els['ses-contract-total'].value,'562457.91');
  assert.equal(field('Outros nomes de perfil em N2').value,'Nome alternativo');
  change(field('Perfil contratual em N2'),'Analista renomeado');
  change(field('Quantidade do perfil em N2'),'4');
  change(field('Valor unitário do perfil em N2'),'9.182,02');
  let ins3=deduction(3);change(ins3.rate,'0');change(ins3.reason,'Ajuste aprovado pelo cliente');
  assert(dirty);assert(ins3.reason.required);
  els['ses-add-adjustment'].events.click();let adj=els['ses-financial-adjustments'].children[0];change(adj.children[0].firstChild,'Crédito do período anterior');change(adj.children[1].firstChild,'125,50');
  month='2026-07';editor.renderMonth();assert.equal(deduction(3).rate.value,'');
  change(deduction(5).rate,'1');change(deduction(5).reason,'Ajuste aprovado em julho');
  month='2026-06';editor.renderMonth();assert.equal(deduction(3).rate.value,'0');assert.equal(els['ses-financial-adjustments'].children[0].children[1].firstChild.value,'125,50');
  await els['g4fr-ses-financial-form'].events.submit({preventDefault(){},currentTarget:els['g4fr-ses-financial-form']});
  let sent=JSON.parse(captured.config);assert.equal(sent.contract_total,'562457.91');assert.equal(sent.contract_fronts[0].profile_lines[0].profile,'Analista renomeado');assert.equal(sent.contract_fronts[0].profile_lines[0].contract_quantity,4);assert.deepEqual(sent.contract_fronts[0].profiles,['Nome alternativo']);assert(!('profile_lines' in sent.contract_fronts[1]));assert.equal(sent.contract_fronts[1].contract_quantity,2);assert.equal(sent.months['2026-06'].deduction_overrides.INS3.rate,0);assert.equal(sent.months['2026-07'].deduction_overrides.INS5.rate,1);assert.equal(sent.months['2026-06'].adjustments[0].amount,'125,50');assert(!dirty);assert.equal(saved,1);assert.equal(config.csrf_token,'csrf2');assert.equal(captured.revision,'r1');
  change(deduction(3).rate,'');await els['g4fr-ses-financial-form'].events.submit({preventDefault(){},currentTarget:els['g4fr-ses-financial-form']});sent=JSON.parse(captured.config);assert(!('INS3' in sent.months['2026-06'].deduction_overrides));assert.equal(sent.months['2026-07'].deduction_overrides.INS5.rate,1);
  month='2026-08';editor.renderMonth();change(deduction(1).rate,'0');month='2026-06';editor.renderMonth();const prior=captured.config;await els['g4fr-ses-financial-form'].events.submit({preventDefault(){},currentTarget:els['g4fr-ses-financial-form']});assert.equal(captured.config,prior);assert(status.includes('2026-08'));assert(dirty);
  month='2026-08';editor.renderMonth();change(deduction(1).reason,'Ajuste autorizado');failNext=true;await els['g4fr-ses-financial-form'].events.submit({preventDefault(){},currentTarget:els['g4fr-ses-financial-form']});assert(dirty);assert.equal(deduction(1).rate.value,'0');assert.equal(config.csrf_token,'csrf3');assert.equal(status,'Conflito de versão');
  field('Remover perfil Analista Sênior').events.click();
  await els['g4fr-ses-financial-form'].events.submit({preventDefault(){},currentTarget:els['g4fr-ses-financial-form']});sent=JSON.parse(captured.config);assert.equal(sent.contract_fronts[0].profile_lines.length,1);assert.deepEqual(sent.contract_fronts[0].profiles,['Nome alternativo']);
  change(els['ses-contract-total'],'');
  await els['g4fr-ses-financial-form'].events.submit({preventDefault(){},currentTarget:els['g4fr-ses-financial-form']});assert.strictEqual(JSON.parse(captured.config).contract_total,null);
  els['ses-use-contract-baseline'].events.click();assert.equal(els['ses-contract-total'].value,'562457.91');
  await els['g4fr-ses-financial-form'].events.submit({preventDefault(){},currentTarget:els['g4fr-ses-financial-form']});sent=JSON.parse(captured.config);assert.equal(sent.contract_fronts.length,1);assert.equal(sent.contract_fronts[0].id,'standard');assert.equal(sent.contract_total,'562457.91');assert.equal(sent.months['2026-07'].deduction_overrides.INS5.rate,1);assert.equal(sent.months['2026-08'].deduction_overrides.INS1.rate,0);
  editor.renderPreview({complete:false,gross_value:'100.00',deduction_total:'0.00',adjustment_total:'0.00',net_value:'100.00'});assert(els['ses-financial-preview'].children[0].textContent.includes('parciais'));
  // The read-only page has no form or save control and creates no editable inputs.
  delete els['g4fr-ses-financial-form'];delete els['ses-save-financial'];
  context.window.G4FSesFinancial.create({config:{can_configure:false,financial_config:config.financial_baseline},month:()=>month,isBusy:()=>false,onDirty(){throw new Error('Read-only editor cannot become dirty')},status(){}});
  assert(!descendants(els['ses-financial-fronts']).some(item=>['input','textarea','button'].includes(item.tagName)));assert(els['ses-contract-total'].textContent.includes('562.457,91'));
  assert(sharedPosts>1);assert.equal(captured._glpi_csrf_token,'shared-dispatch-token');
  process.stdout.write('Financial UI: profile quantities/rates, approved total, legacy preservation, renamed profiles, explicit automatic total, preset preserving months, validation, shared POST transport and conflict-safe saving passed.\n');
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exit(1)});
