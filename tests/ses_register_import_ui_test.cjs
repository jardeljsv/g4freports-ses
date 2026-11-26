'use strict';
// Run the real register page controller. Network/storage are mocked; import
// validation and persistence endpoint behavior are covered by the PHP tests.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const crypto = require('crypto');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/ses.js'), 'utf8');
const clone = value => JSON.parse(JSON.stringify(value));
const nextTurn = () => new Promise(resolve => setImmediate(resolve));
function dataName(name) { return name.replace(/^data-/, '').replace(/-([a-z])/g, (_, x) => x.toUpperCase()); }
function matches(element, selector) {
  selector = selector.trim();
  if (selector.includes(' ')) selector = selector.split(/\s+/).pop();
  if (selector.includes(':not([type="hidden"])') && element.type === 'hidden') return false;
  selector = selector.replace(':not([type="hidden"])', '');
  const tag = /^[a-z]+/i.exec(selector), klass = /^\.([\w-]+)/.exec(selector);
  if (tag && element.tagName !== tag[0].toLowerCase()) return false;
  if (klass && !element.className.split(/\s+/).includes(klass[1])) return false;
  const attr = /\[([\w-]+)(?:="([^"]*)")?\]/.exec(selector);
  if (attr) {
    const value = attr[1].startsWith('data-') ? element.dataset[dataName(attr[1])] : element[attr[1]];
    if (value === undefined || (attr[2] !== undefined && value !== attr[2])) return false;
  }
  return true;
}
class Element {
  constructor(tag = 'div') { this.tagName = tag; this.children = []; this.dataset = {}; this.events = {}; this.value = ''; this.className = ''; this.textContent = ''; this.classList = { toggle() {} }; this.files = []; }
  appendChild(child) { child.parent = this; this.children.push(child); return child; }
  removeChild(child) { this.children.splice(this.children.indexOf(child), 1); }
  remove() { if (this.parent) this.parent.removeChild(this); }
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
  click() { this.clicked = true; return this.emit('click'); }
  async emit(event, value = {}) { for (const listener of this.events[event] || []) await listener.call(this, Object.assign({ target: this, currentTarget: this, preventDefault() {} }, value)); }
}
function text(element) { return element.textContent + element.children.map(text).join(' '); }
function createPage(canConfigure = true) {
  const ids = new Map(), requests = [], timers = new Map(); let nextTimer = 0;
  function make(id, tag = 'div') { if (ids.has(id)) return ids.get(id); const item = new Element(tag); item.id = id; ids.set(id, item); return item; }
  for (const match of source.matchAll(/el\('([^']+)'\)/g)) make(match[1]);
  const root = make('g4fr-ses'), form = make('g4fr-ses-form', 'form');
  const month = make('ses-month', 'input'); month.name = 'month'; month.value = '2026-06'; form.elements = { month }; form.appendChild(month);
  for (const kind of ['staff', 'tests']) {
    ['search','filter','billing-filter','profile-filter','clear-filters','undo','export-scope','export','export-state','changes','duplicates','prev','next','page','history','history-refresh','history-body','body','empty','state'].forEach(field => make('ses-' + kind + '-' + field));
    make('ses-' + kind + '-filter').value = 'all'; make('ses-' + kind + '-billing-filter').value = 'all';
    make('ses-' + kind + '-export-scope').value = 'all';
    if (canConfigure) {
      for (const field of ['import', 'import-file', 'import-preview']) make('ses-' + kind + '-' + field);
      make('ses-' + kind + '-import-preview').hidden = true;
      const register = make('g4fr-ses-' + kind + '-form', 'form');
      register.appendChild(make('ses-' + kind + '-body'));
    }
  }
  for (const element of ids.values()) if (element !== root && !element.parent) root.appendChild(element);
  const staff = Array.from({ length: 25 }, (_, i) => ({ id: 'staff-' + String(i + 1).padStart(3, '0'), name: 'Profissional ' + String(i + 1).padStart(3, '0'), profile: 'Perfil', admission_date: '2026-01-01', departure_date: '', monthly_value: '3000.00', not_billed: i === 2, billing_days_overrides: i === 3 ? { '2026-05': 12 } : {} }));
  const phones = [{ number: '001234', label: 'Telefone original', active: true }, { number: '98765', label: 'Preservado', active: false }];
  const config = { staff_rows: clone(staff), test_numbers: clone(phones), staff_revision: 'staff-r1', test_revision: 'tests-r1', nonce: 'nonce', csrf_token: 'csrf', can_configure: canConfigure, connections_ready: true, financial_config: { contract_fronts: [] }, api_url: '/api', registers_url: '/save', register_export_url: '/export', register_import_url: '/import' };
  const page = { make, ids, config, requests, timers, form, month, staff, phones, importReply: null, importError: null, holdImport: false };
  const response = (body, ok = true, status = 200) => ({ ok, status, text: async () => JSON.stringify(body) });
  const window = { G4FREPORTS_SES: config, G4FStaffBilling: require('../assets/js/staff_billing.js'), G4FSesFinancial: { create: options => { page.financialOptions = options; return { setBusy() {} }; } }, crypto: crypto.webcrypto, confirm: () => true, addEventListener() {}, location: { href: 'https://example.invalid/ses.php' } };
  const document = { body: new Element('body'), getElementById: id => ids.get(id) || null, createElement: tag => new Element(tag), createTextNode: value => Object.assign(new Element('#text'), { textContent: value }) };
  class TestFormData extends Map { set(k, v) { super.set(k, v); } }
  class TestURL extends URL { static createObjectURL() { return 'blob:test'; } static revokeObjectURL() {} }
  const context = { window, document, Intl, Uint8Array, TextEncoder, AbortController, URLSearchParams, FormData: TestFormData, URL: TestURL,
    setTimeout: (fn, ms) => { const id = ++nextTimer; timers.set(id, { fn, ms }); return id; }, clearTimeout: id => timers.delete(id),
    fetch: async (url, request) => {
      const body = Object.fromEntries(request.body || []); requests.push({ url, body, request });
      if (page.handleRequest) return page.handleRequest(url, request, body);
      if (request.method === 'GET') {
        const target = new URL(url); assert.equal(target.pathname, '/api'); assert.equal(target.searchParams.get('action'), 'get_csrf');
        assert.equal(JSON.parse(target.searchParams.get('payload'))._ses_nonce, config.nonce);
        return response({ ok: true, csrf_token: 'csrf-dispatch-' + requests.length });
      }
      if (url === '/export') return { ok: true, status: 200, headers: { get: key => key === 'Content-Type' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : key === 'X-G4F-CSRF-Token' ? 'csrf-export' : null }, blob: async () => ({ size: 10 }) };
      if (url === '/import') {
        if (page.holdImport) return new Promise((resolve, reject) => { request.signal.addEventListener('abort', () => reject(Object.assign(new Error('Aborted'), { name: 'AbortError' }))); page.resolveImport = resolve; });
        if (page.importError) return response({ ok: false, error: page.importError, csrf_token: 'csrf-import-failure' }, false, 400);
        return response(Object.assign({ ok: true, csrf_token: 'csrf-import' }, page.importReply));
      }
      assert.equal(url, '/save');
      assert(['save_staff', 'save_tests'].includes(body.action));
      return response({ ok: true, rows: JSON.parse(body.rows), revision: body.kind + '-saved', history: [], csrf_token: 'csrf-saved' });
    }
  };
  vm.createContext(context); vm.runInContext(source, context);
  page.export = async kind => { await make('ses-' + kind + '-export').emit('click'); await nextTurn(); return JSON.parse(requests.filter(r => r.url === '/export').at(-1).body.rows); };
  page.upload = async (kind, file = { name: 'Cadastro.xlsx', size: 100 }) => { const input = make('ses-' + kind + '-import-file'); input.files = [file]; input.value = 'selected'; await input.emit('change'); await nextTurn(); };
  page.action = async (kind, label) => { const item = make('ses-' + kind + '-import-preview').querySelectorAll('button').find(button => button.textContent === label); assert(item, 'Preview button exists: ' + label); await item.emit('click'); };
  page.visible = id => make('ses-staff-body').children.find(row => row.dataset.staffId === id);
  return page;
}
function changedPreview(rows, kind = 'staff') {
  const result = clone(rows);
  if (kind === 'staff') { result[0].profile = 'Perfil importado'; result[0].billing_days_overrides = { '2026-06': 8 }; }
  else { result[0].label = 'Telefone importado'; result[0].active = false; }
  return { rows: result, summary: { added: 0, updated: 1, unchanged: 0, duplicates: 1 }, retained: rows.length - 1, month: kind === 'staff' ? '2026-06' : '', changes: [{ action: 'updated', label: kind === 'staff' ? result[0].name : result[0].number, key: kind === 'staff' ? result[0].id : result[0].number, fields: [{ field: kind === 'staff' ? 'Perfil Profissional' : 'Identificação', before: kind === 'staff' ? rows[0].profile : rows[0].label, after: kind === 'staff' ? result[0].profile : result[0].label }] }] };
}
async function edit(input, value) { input.value = value; await input.emit('input'); }

if (require.main === module) (async () => {
  const p = createPage();
  await p.make('ses-staff-next').emit('click');
  assert.equal(p.make('ses-staff-body').children.length, 5);
  await edit(p.make('ses-staff-search'), 'Profissional 001');
  assert.equal(p.make('ses-staff-body').children.length, 1);
  assert.equal((await p.export('staff')).length, 25, 'Default all export ignores page and active filters');
  const exported = p.requests.filter(r => r.url === '/export').at(-1);
  assert.equal(exported.body.scope, 'all'); assert.equal(exported.body.month, '2026-06');
  p.make('ses-staff-export-scope').value = 'filtered'; await p.make('ses-staff-export-scope').emit('change');
  assert.equal((await p.export('staff')).length, 1, 'Explicit filtered export remains available');
  p.make('ses-staff-export-scope').value = 'all'; await p.make('ses-staff-export-scope').emit('change');
  p.importReply = changedPreview(p.staff);
  await p.upload('staff');
  assert.equal(p.make('ses-staff-import-file').value, '', 'File input resets to permit importing the same file again');
  assert(!p.make('ses-staff-import-preview').hidden);
  assert(text(p.make('ses-staff-import-preview')).includes('24 registro(s) existente(s)'));
  assert(text(p.make('ses-staff-import-preview')).includes('Perfil importado'), 'Preview shows new values');
  assert.deepEqual(await p.export('staff'), p.staff, 'Preview alone does not change register');
  assert.equal(p.requests.filter(r => r.url === '/save').length, 0);
  const upload = p.requests.find(r => r.url === '/import');
  assert.equal(upload.body.revision, 'staff-r1'); assert.equal(upload.body.kind, 'staff'); assert.equal(upload.body._ses_nonce, 'nonce'); assert.match(upload.body._glpi_csrf_token, /^csrf-dispatch-/);
  assert.equal(JSON.parse(upload.body.rows).length, 25); assert.equal(upload.body.workbook.name, 'Cadastro.xlsx');
  assert.equal(upload.request.credentials, 'same-origin'); assert.equal(p.config.csrf_token, 'csrf-export');
  await p.action('staff', 'Cancelar importação');
  assert(p.make('ses-staff-import-preview').hidden); assert.deepEqual(await p.export('staff'), p.staff, 'Cancel keeps all existing data');
  await p.upload('staff'); await p.action('staff', 'Aplicar à tabela');
  let applied = await p.export('staff');
  assert.equal(applied[0].profile, 'Perfil importado'); assert.equal(applied.length, 25);
  assert.deepEqual(applied.find(r => r.id === 'staff-004').billing_days_overrides, { '2026-05': 12 });
  assert.strictEqual(applied.find(r => r.id === 'staff-003').not_billed, true);
  assert.equal(p.requests.filter(r => r.url === '/save').length, 0, 'Apply does not implicitly save');
  assert(p.make('ses-generate').disabled, 'Applied import marks the register dirty');
  await p.make('g4fr-ses-staff-form').emit('submit');
  let saved = p.requests.filter(r => r.url === '/save');
  assert.equal(saved.length, 1); assert.equal(saved[0].body.action, 'save_staff'); assert.equal(saved[0].body.revision, 'staff-r1');
  assert.deepEqual(JSON.parse(saved[0].body.rows), applied, 'Existing save endpoint receives complete merged register');
  assert.equal(p.config.staff_revision, 'staff-saved'); assert(!p.make('ses-generate').disabled);

  // A local edit or saved revision change after analysis invalidates the preview.
  p.importReply = changedPreview(applied); p.importReply.rows[0].profile = 'Must not apply';
  await p.upload('staff');
  await edit(p.visible('staff-001').querySelector('[data-billing-override]'), '5');
  await p.action('staff', 'Aplicar à tabela');
  applied = await p.export('staff');
  assert.equal(applied[0].profile, 'Perfil importado'); assert.equal(applied[0].billing_days_overrides['2026-06'], '5');
  assert(text(p.make('ses-status')).includes('A tabela mudou após a prévia'));
  p.importReply = changedPreview(applied); await p.upload('staff'); p.config.staff_revision = 'staff-other-session';
  await p.action('staff', 'Aplicar à tabela'); assert.deepEqual(await p.export('staff'), applied, 'Revision change also invalidates preview');

  // Server validation failures and timeouts leave current edits and rows intact.
  p.importError = 'Valores divergentes para o mesmo ID'; await p.upload('staff');
  assert(p.make('ses-staff-import-preview').hidden); assert(p.make('ses-staff-export-state').textContent.includes('Valores divergentes'));
  assert.equal(p.config.csrf_token, 'csrf-import-failure'); assert.deepEqual(await p.export('staff'), applied);
  p.importError = null; p.holdImport = true; await p.upload('staff');
  const pendingTimer = [...p.timers.values()].find(timer => timer.ms === 30000); assert(pendingTimer); pendingTimer.fn(); await nextTurn();
  assert(p.make('ses-staff-export-state').textContent.includes('30 segundos'));
  assert.deepEqual(await p.export('staff'), applied); assert(!p.make('ses-staff-export').disabled, 'Timeout releases busy state');
  p.holdImport = false;
  const importCount = p.requests.filter(r => r.url === '/import').length;
  await p.upload('staff', { name: 'wrong.csv', size: 100 }); await p.upload('staff', { name: 'huge.xlsx', size: 2097153 });
  assert.equal(p.requests.filter(r => r.url === '/import').length, importCount, 'Wrong extension/oversize rejected before upload');

  // Telephone registers use the same preview/apply/save flow and preserve zeros.
  const t = createPage(); t.importReply = changedPreview(t.phones, 'tests');
  await t.upload('tests'); assert.deepEqual(await t.export('tests'), t.phones);
  await t.action('tests', 'Aplicar à tabela');
  const phones = await t.export('tests'); assert.equal(phones.length, 2); assert.equal(phones[0].number, '001234'); assert.strictEqual(phones[0].active, false); assert.deepEqual(phones[1], t.phones[1]);
  await t.make('g4fr-ses-tests-form').emit('submit'); saved = t.requests.filter(r => r.url === '/save');
  assert.equal(saved.length, 1); assert.equal(saved[0].body.action, 'save_tests'); assert.equal(saved[0].body.revision, 'tests-r1'); assert.deepEqual(JSON.parse(saved[0].body.rows), phones);
  const ro = createPage(false); assert(!ro.ids.has('ses-staff-import') && !ro.ids.has('ses-tests-import'));
  assert.equal((await ro.export('staff')).length, 25, 'Read-only users retain export');
  assert.equal(ro.requests.filter(r => r.url === '/import' || r.url === '/save').length, 0, 'Read-only page installs no import/save flow');
  process.stdout.write('Register import UI: all/filtered export, preview/cancel/apply, explicit full-register save, stale previews, validation failures, timeout, telephone preservation and read-only access passed.\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exit(1); });
module.exports = { createPage, changedPreview, nextTurn, text };
