'use strict';
// Exercise the real page controller with a minimal DOM: edits must survive page,
// search and month changes, and saves must include the complete backing register.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const crypto = require('crypto');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/ses.js'), 'utf8');
const financialSource = fs.readFileSync(path.join(__dirname, '../assets/js/ses_financial.js'), 'utf8');
const ids = new Map();
function dataName(name) { return name.replace(/^data-/, '').replace(/-([a-z])/g, (_, x) => x.toUpperCase()); }
function matches(element, selector) {
  selector = selector.trim();
  if (selector.includes(' ')) selector = selector.split(/\s+/).pop();
  if (selector.includes(':not([type="hidden"])') && element.type === 'hidden') return false;
  selector = selector.replace(':not([type="hidden"])', '');
  const tag = /^[a-z]+/i.exec(selector);
  if (tag && element.tagName !== tag[0].toLowerCase()) return false;
  const klass = /^\.([\w-]+)/.exec(selector);
  if (klass && !element.className.split(/\s+/).includes(klass[1])) return false;
  const attr = /\[([\w-]+)(?:="([^"]*)")?\]/.exec(selector);
  if (attr) {
    const value = attr[1].startsWith('data-') ? element.dataset[dataName(attr[1])] : element[attr[1]];
    if (value === undefined || (attr[2] !== undefined && value !== attr[2])) return false;
  }
  return true;
}
class Element {
  constructor(tag = 'div') { this.tagName = tag; this.children = []; this.dataset = {}; this.events = {}; this.value = ''; this.className = ''; this.textContent = ''; this.classList = { toggle() {} }; }
  appendChild(child) { child.parent = this; this.children.push(child); return child; }
  removeChild(child) { this.children.splice(this.children.indexOf(child), 1); }
  get firstChild() { return this.children[0] || null; }
  get firstElementChild() { return this.firstChild; }
  get childNodes() { return this.children; }
  setAttribute(key, value) { this[key] = String(value); }
  removeAttribute(key) { delete this[key]; }
  setCustomValidity(message) { this.validationMessage = message; }
  addEventListener(event, listener) { (this.events[event] || (this.events[event] = [])).push(listener); }
  querySelectorAll(selector) {
    const found = [], selectors = selector.split(',');
    function walk(element) { element.children.forEach(child => { if (selectors.some(part => matches(child, part))) found.push(child); walk(child); }); }
    walk(this); return found;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  reportValidity() { return true; }
  focus() {}
  scrollIntoView() {}
  async emit(event, value = {}) { for (const listener of this.events[event] || []) await listener.call(this, Object.assign({ target: this, currentTarget: this, preventDefault() {} }, value)); }
}
function make(id, tag = 'div') { if (ids.has(id)) return ids.get(id); const element = new Element(tag); element.id = id; ids.set(id, element); return element; }
for (const script of [source, financialSource]) for (const match of script.matchAll(/el\('([^']+)'\)/g)) make(match[1]);
const root = make('g4fr-ses'), form = make('g4fr-ses-form', 'form');
const monthInput = make('ses-month', 'input'); monthInput.name = 'month'; monthInput.value = '2026-06'; form.elements = { month: monthInput }; form.appendChild(monthInput);
for (const kind of ['staff', 'tests']) {
  ['search', 'filter', 'billing-filter', 'profile-filter', 'clear-filters', 'undo', 'export-scope', 'export', 'export-state', 'changes', 'duplicates', 'prev', 'next', 'page', 'history', 'history-refresh', 'history-body', 'body', 'empty', 'state'].forEach(field => make('ses-' + kind + '-' + field));
  make('ses-' + kind + '-filter').value = 'all'; make('ses-' + kind + '-billing-filter').value = 'all';
  const register = make('g4fr-ses-' + kind + '-form', 'form');
  register.appendChild(make('ses-' + kind + '-body'));
}
['fronts','fronts-empty','month','deductions','adjustments','adjustments-empty','state','preview'].forEach(field=>make('ses-financial-'+field));
const financeForm = make('g4fr-ses-financial-form', 'form');
['fronts','deductions','adjustments'].forEach(field=>financeForm.appendChild(make('ses-financial-'+field)));
for (const element of ids.values()) if (element !== root && !element.parent) root.appendChild(element);
const rows = Array.from({length:45}, (_, index) => ({ id:'staff-' + String(index+1).padStart(3,'0'), name:'Profissional ' + String(index+1).padStart(3,'0'), profile:'Perfil de teste', admission_date:'2026-01-01', departure_date:'', monthly_value:'3000.00', not_billed:index===2, billing_days_overrides:{} }));
const config = { staff_rows:rows, test_numbers:[], test_revision:'test-version', staff_revision:'staff-version', nonce:'nonce', csrf_token:'csrf', can_configure:true, connections_ready:true, financial_config:{contract_total:'562457.91',contract_fronts:[{id:'n2',label:'Atendimento N2',profiles:['Perfil de teste','Perfil único'],contract_quantity:1,monthly_rate:'100.00'},{id:'monitoramento',label:'Monitoramento',profiles:['Perfil de teste'],contract_quantity:1,monthly_rate:'100.00'}],months:{}}, financial_revision:'financial-version', api_url:'/api', registers_url:'/registers', financial_url:'/finance' };
rows[1].profile='Perfil único';rows[2].service_front='removida';
let sentRows = null, saveCount=0, financeSaveCount=0;
const window = { G4FREPORTS_SES:config, G4FStaffBilling:require('../assets/js/staff_billing.js'), crypto:crypto.webcrypto, confirm:()=>true, addEventListener(){}, location:{href:'https://example.invalid/ses.php'} };
const document = { getElementById:id=>ids.get(id)||null, createElement:tag=>new Element(tag), createTextNode:text=>Object.assign(new Element('#text'),{textContent:text}) };
const context = {window,document,Intl,Uint8Array,TextEncoder,AbortController,URLSearchParams,URL,setTimeout,clearTimeout,fetch:async(url,request)=>{
  if(request.method==='GET')return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,csrf_token:'csrf-dispatch'})};
  const body=Object.fromEntries(request.body);
  if(url==='/finance'){financeSaveCount++;return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,config:JSON.parse(body.config),revision:'finance-'+financeSaveCount,csrf_token:'csrf-finance'})}}
  assert.equal(url,'/registers'); assert.equal(body.action,'save_staff');
  sentRows=JSON.parse(body.rows);saveCount++;
  return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,rows:sentRows,revision:'staff-version-'+saveCount,history:[],csrf_token:'csrf-next'})};
}};
vm.createContext(context);vm.runInContext(financialSource,context);vm.runInContext(source,context);
function visible(id) { return make('ses-staff-body').children.find(row=>row.dataset.staffId===id); }
function front(row) { return row.querySelector('[data-service-front]'); }
function days(row) { return row.querySelector('[data-billing-override]'); }
async function edit(input, value) { input.value=value;await input.emit('input'); }
(async()=>{
  assert.equal(make('ses-staff-body').children.length,20);
  const first = visible('staff-001'); assert.equal(first.children[0].textContent,'1');
  assert.equal(front(first).value,'');assert(first.children[4].children[1].textContent.includes('mais de uma frente'));
  assert(front(visible('staff-002')).children[0].textContent.includes('Automática · Atendimento N2'));
  assert.equal(front(visible('staff-003')).value,'removida');assert(visible('staff-003').children[4].children[1].textContent.includes('não está'));
  front(first).value='n2';await front(first).emit('change');assert(make('ses-generate').disabled);
  await edit(days(first),'2');await edit(days(visible('staff-002')),'0');
  await make('ses-staff-next').emit('click');
  const twentyFirst = visible('staff-021'); assert.equal(twentyFirst.children[0].textContent,'21');
  await edit(days(twentyFirst),'7');front(twentyFirst).value='monitoramento';await front(twentyFirst).emit('change');
  monthInput.value='2026-07';await form.emit('change',{target:monthInput});
  assert.equal(days(visible('staff-021')).value,'');await edit(days(visible('staff-021')),'4');
  monthInput.value='2026-06';await form.emit('change',{target:monthInput});assert.equal(days(visible('staff-021')).value,'7');
  await edit(make('ses-staff-search'),'Profissional 001');assert.equal(make('ses-staff-body').children.length,1);assert.equal(days(visible('staff-001')).value,'2');assert.equal(visible('staff-001').children[0].textContent,'1');
  await make('g4fr-ses-staff-form').emit('submit');
  assert.equal(saveCount,1);assert.equal(sentRows.length,45);assert.equal(sentRows.find(row=>row.id==='staff-002').billing_days_overrides['2026-06'],'0');assert.equal(sentRows.find(row=>row.id==='staff-001').billing_days_overrides['2026-06'],'2');
  assert.equal(sentRows.find(row=>row.id==='staff-021').billing_days_overrides['2026-06'],'7');assert.equal(sentRows.find(row=>row.id==='staff-021').billing_days_overrides['2026-07'],'4');
  assert.strictEqual(sentRows.find(row=>row.id==='staff-003').not_billed,true);assert.strictEqual(sentRows.find(row=>row.id==='staff-001').not_billed,false);
  assert.equal(config.csrf_token,'csrf-next');assert(!make('ses-generate').disabled);
  assert.equal(sentRows.find(row=>row.id==='staff-001').service_front,'n2');assert.equal(sentRows.find(row=>row.id==='staff-021').service_front,'monitoramento');assert.equal(sentRows.find(row=>row.id==='staff-003').service_front,'removida');
  // Finance saves refresh front options without replacing unsaved staff edits.
  await edit(days(visible('staff-001')),'3');
  const frontLabel=make('ses-financial-fronts').children[0].children[0].firstChild;await edit(frontLabel,'Atendimento N2 atualizado');
  await make('g4fr-ses-financial-form').emit('submit');assert.equal(financeSaveCount,1);assert.equal(days(visible('staff-001')).value,'3');assert.equal(front(visible('staff-001')).value,'n2');assert(front(visible('staff-001')).children.some(option=>option.textContent==='Atendimento N2 atualizado'));assert(make('ses-generate').disabled);
  await make('g4fr-ses-staff-form').emit('submit');assert.equal(saveCount,2);assert.equal(sentRows.find(row=>row.id==='staff-001').billing_days_overrides['2026-06'],'3');
  // Clearing June only must preserve this worker's July adjustment.
  await edit(make('ses-staff-search'),'Profissional 021');await edit(days(visible('staff-021')),'');
  await make('g4fr-ses-staff-form').emit('submit');assert.equal(saveCount,3);assert(!('2026-06' in sentRows.find(row=>row.id==='staff-021').billing_days_overrides));assert.equal(sentRows.find(row=>row.id==='staff-021').billing_days_overrides['2026-07'],'4');
  // An invalid value on another page cannot evade validation by navigating away.
  await edit(days(visible('staff-021')),'31');await edit(make('ses-staff-search'),'Profissional 001');await make('g4fr-ses-staff-form').emit('submit');assert.equal(saveCount,3);assert(make('ses-generate').disabled);
  process.stdout.write('Staff UI: unique/ambiguous/deleted front assignment, finance save retaining unsaved staff edits, pagination, search, month switching, full-register saving and exclusion flags passed.\n');
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exit(1)});
