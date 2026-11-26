'use strict';
// Executes the actual monthly controller with the minimal DOM pattern used by
// the register UI suites. Server responses are frozen fixtures, not live SQL.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const crypto = require('crypto');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/ses.js'), 'utf8');
const nextTurn = () => new Promise(resolve => setImmediate(resolve));
const text = element => element.textContent + element.children.map(text).join(' ');
function matches(element, selector) {
  selector = selector.trim().split(/\s+/).pop();
  if (selector.includes(':not([type="hidden"])') && element.type === 'hidden') return false;
  selector = selector.replace(':not([type="hidden"])', '');
  const tag = /^[a-z]+/i.exec(selector), klass = /^\.([\w-]+)/.exec(selector);
  if (tag && element.tagName !== tag[0].toLowerCase()) return false;
  if (klass && !element.className.split(/\s+/).includes(klass[1])) return false;
  const attr = /\[([\w-]+)(?:="([^"]*)")?\]/.exec(selector);
  if (attr) {
    const key = attr[1].replace(/^data-/, '').replace(/-([a-z])/g, (_, c) => c.toUpperCase());
    const value = attr[1].startsWith('data-') ? element.dataset[key] : element[attr[1]];
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
function createPage(sharing = {enabled:false,allowed:false}) {
  const ids = new Map(), requests = [], timers = new Map(); let timerId = 0;
  function make(id, tag = 'div') { if (!ids.has(id)) ids.set(id, Object.assign(new Element(tag), { id })); return ids.get(id); }
  for (const match of source.matchAll(/el\('([^']+)'\)/g)) make(match[1]);
  const root = make('g4fr-ses'), form = make('g4fr-ses-form', 'form'); form.elements = {};
  const fields = { month: '2026-06', entity_id: '1', queue_filter: 'ssdf', priority_preset: 'contract',
    group_ids: '', n1_categories: '171', n1_group_ids: '55', n2_group_ids: '56', n3_group_ids: '57',
    validated_kb_ids: '42', incomplete_ticket_ids: '', ins12_mode: 'glpi_and_phone', kb_mode: 'validated_links',
    sla_n1_calendar_id: '0', sla_n2_calendar_id: '0', sla_n3_calendar_id: '0', report_title: 'Relatório mensal',
    executive_summary: 'Resumo', actions: 'Ação', notes: 'Observação', phone_survey_actions: 'Providência' };
  for (const [name, value] of Object.entries(fields)) {
    const input = make('fixture-' + name, 'input'); input.name = name; input.value = value; input.labels = [{ textContent: name }];
    if (['report_title', 'executive_summary', 'actions', 'notes', 'phone_survey_actions'].includes(name)) input.dataset.manual = '1';
    form.elements[name] = input; form.appendChild(input);
  }
  for (const kind of ['staff', 'tests']) {
    for (const field of ['search', 'filter', 'billing-filter', 'profile-filter', 'clear-filters', 'undo', 'export-scope', 'export', 'export-state', 'changes', 'duplicates', 'prev', 'next', 'page', 'history', 'history-refresh', 'history-body', 'body', 'empty', 'state']) make('ses-' + kind + '-' + field);
    make('ses-' + kind + '-filter').value = 'all'; make('ses-' + kind + '-billing-filter').value = 'all';
    make('g4fr-ses-' + kind + '-form', 'form').appendChild(make('ses-' + kind + '-body'));
  }
  for (const element of ids.values()) if (element !== root && !element.parent) root.appendChild(element);
  const config = { nonce: 'nonce-fixture', csrf_token: 'stale-page-token', connections_ready: true, can_configure: true,
    api_url: '/api', archive_url: '/archive', registers_url: '/registers', staff_revision: 'staff-r1', test_revision: 'tests-r1',
    financial_config: { contract_fronts: [] }, archive_sharing: sharing,
    staff_rows: [{ id: 'staff-1', name: 'Profissional', profile: 'Analista', admission_date: '2026-01-01', departure_date: '', monthly_value: '1000.00', not_billed: false }],
    test_numbers: [{ number: '6100000001', label: 'Teste', active: true }] };
  const page = { make, form, config, requests, timers, revision: 'revision-a', token: 'draft-a', csrfSequence: 0,
    archiveMode: 'success', manualMode: 'success' };
  function snapshot() {
    return { period: { month: '2026-06', label: 'Junho de 2026' }, generated_at: '2026-07-01T00:00:00-03:00',
      rules_version: 'frozen-rules', input: {}, totals: {}, metrics: [], warnings: [], rule_notes: [],
      tickets: [], calls: [], financial: {}, sentinel: 'Snapshot must never be sent by archive' };
  }
  const response = (body, ok = true, status = 200) => ({ ok, status, text: async () => JSON.stringify(body) });
  const window = { G4FREPORTS_SES: config, G4FStaffBilling: require('../assets/js/staff_billing.js'),
    G4FSesFinancial: { create: options => { page.finance = options; return { setBusy() {}, renderMonth() {}, renderPreview() {} }; } },
    crypto: crypto.webcrypto, confirm: () => true, addEventListener() {}, location: { href: 'https://example.invalid/monthly' } };
  const document = { getElementById: id => ids.get(id) || null, createElement: tag => new Element(tag),
    createTextNode: value => Object.assign(new Element('#text'), { textContent: value }) };
  const context = { window, document, Intl, Uint8Array, TextEncoder, AbortController, URLSearchParams, URL,
    setTimeout: (fn, ms) => { const id = ++timerId; timers.set(id, { fn, ms }); return id; }, clearTimeout: id => timers.delete(id),
    fetch: async (url, request) => {
      const body = Object.fromEntries(request.body || []); requests.push({ url, body, request });
      assert.equal(request.credentials, 'same-origin'); assert.equal(request.cache, 'no-store');
      if (request.method === 'GET') {
        const target = new URL(url), action = target.searchParams.get('action'), payload = JSON.parse(target.searchParams.get('payload'));
        assert.equal(target.pathname, '/api'); assert.equal(payload._ses_nonce, config.nonce);
        if (action === 'get_csrf') return response({ ok: true, csrf_token: 'dispatch-token-' + (++page.csrfSequence) });
        assert(['generate', 'update_manual'].includes(action));
        if (action === 'update_manual') {
          assert.equal(payload.token, page.expectedManualToken || page.token);
          if (page.manualMode === 'failure') return response({ ok: false, error: 'Não foi possível atualizar os textos.' }, false, 409);
        }
        return response({ ok: true, token: page.token, draft_revision: page.revision, snapshot: snapshot() });
      }
      assert.equal(request.method, 'POST');
      assert.equal(body._glpi_csrf_token, 'dispatch-token-' + page.csrfSequence);
      if (url === '/registers') return response({ ok: true, rows: JSON.parse(body.rows), revision: body.kind + '-r2', history: [] });
      assert.equal(url, '/archive');
      if (page.archiveMode === 'hold') return new Promise((resolve, reject) => {
        page.releaseArchive = () => resolve(response({ ok: true, id: 'archived-fixture' }));
        request.signal.addEventListener('abort', () => reject(Object.assign(new Error('Lost response after server accepted request'), { name: 'AbortError' })), { once: true });
      });
      if (page.archiveMode === 'failure') return response({ ok: false, error: 'A revisão do rascunho mudou. Confira a coleção.' }, false, 409);
      if (page.archiveMode === 'malformed') return { ok: true, status: 200, text: async () => '<html>lost confirmation</html>' };
      return response({ ok: true, record: {id: 'archived-fixture', visibility: body.share === '1' ? 'shared' : 'private'} });
    }
  };
  vm.createContext(context); vm.runInContext(source, context);
  page.archives = () => requests.filter(request => request.url === '/archive');
  page.generate = () => form.emit('submit');
  page.archive = () => make('ses-archive').emit('click');
  page.edit = async (name, value) => { const input = form.elements[name]; input.value = value; await form.emit('input', { target: input }); };
  return page;
}
async function blocked(page, reason) {
  assert(page.make('ses-archive').disabled, reason);
  const count = page.archives().length; await page.archive();
  assert.equal(page.archives().length, count, 'Disabled archive handler sends nothing: ' + reason);
}

(async () => {
  const p = createPage(); await blocked(p, 'No accepted monthly snapshot');
  p.revision = ''; await p.generate();
  assert(!p.make('ses-export-docx').disabled, 'The monthly draft itself was accepted');
  await blocked(p, 'A draft without server revision cannot be archived');
  p.revision = 'revision-a'; await p.generate(); assert(!p.make('ses-archive').disabled);
  p.archiveMode = 'hold'; const pendingArchive = p.archive(); await nextTurn();
  await blocked(p, 'An archive request in flight blocks duplicate clicks');
  assert.equal(p.archives().length, 1);
  assert.deepEqual(p.archives()[0].body, { action: 'archive', token: 'draft-a', draft_revision: 'revision-a',
    _ses_nonce: 'nonce-fixture', _glpi_csrf_token: 'dispatch-token-1' }, 'Archive posts only the server draft identity, action, nonce and fresh native CSRF token');
  p.releaseArchive(); await pendingArchive;
  assert(!p.make('ses-archive').disabled); assert(text(p.make('ses-status')).includes('Mês arquivado'));

  await p.edit('notes', 'Texto ainda não aceito pelo servidor'); await blocked(p, 'Unsaved manual narrative');
  p.manualMode = 'failure'; await p.make('ses-save-manual').emit('click');
  await blocked(p, 'Rejected manual changes remain unsaved');
  p.manualMode = 'success'; p.expectedManualToken = 'draft-a'; p.token = 'draft-b'; p.revision = 'revision-b';
  await p.make('ses-save-manual').emit('click'); assert(!p.make('ses-archive').disabled);
  p.archiveMode = 'success'; await p.archive();
  assert.equal(p.archives().at(-1).body.token, 'draft-b'); assert.equal(p.archives().at(-1).body.draft_revision, 'revision-b', 'Accepted manual update replaces the archived draft revision');
  assert.equal(p.archives().at(-1).body._glpi_csrf_token, 'dispatch-token-2', 'A new archive action refreshes its consumed CSRF token');

  await p.edit('entity_id', '2'); await blocked(p, 'Changed extraction filter');
  await p.generate(); assert(!p.make('ses-archive').disabled, 'A newly accepted extraction clears changed-filter state');
  p.finance.setBusy(true); await blocked(p, 'Another operation holds the page busy');
  p.finance.setBusy(false); assert(!p.make('ses-archive').disabled);
  p.finance.onDirty(true); await blocked(p, 'Unsaved financial register changes');
  p.finance.onDirty(false); p.finance.onSaved(); await blocked(p, 'Saved financial changes still require a new extraction');
  await p.generate(); assert(!p.make('ses-archive').disabled);

  const staffName = p.make('ses-staff-body').querySelectorAll('input').find(input => input['aria-label'] === 'Nome · Profissional');
  assert(staffName); staffName.value = 'Nome alterado'; await staffName.emit('input');
  await blocked(p, 'Unsaved staff register changes');
  await p.make('g4fr-ses-staff-form').emit('submit'); await blocked(p, 'Saved staff register changes require regenerated metrics');
  await p.generate(); assert(!p.make('ses-archive').disabled);
  const phone = p.make('ses-tests-body').querySelector('input');
  assert(phone); phone.value = '6100000002'; await phone.emit('input');
  await blocked(p, 'Unsaved telephone test register changes');
  await p.make('g4fr-ses-tests-form').emit('submit'); await blocked(p, 'Saved telephone exclusions require a new extraction');
  await p.generate(); assert(!p.make('ses-archive').disabled);

  for (const mode of ['failure', 'malformed']) {
    const failed = createPage(); await failed.generate(); failed.archiveMode = mode;
    await failed.archive(); await nextTurn(); await nextTurn();
    assert.equal(failed.archives().length, 1, 'A rejected or ambiguous archive request is never replayed automatically: ' + mode);
    assert(!failed.make('ses-archive').disabled, 'The draft remains available after failure');
    assert(text(failed.make('ses-status')).includes('coleção'), 'Failure directs the user to inspect the collection');
  }
  const lost = createPage(); await lost.generate(); lost.archiveMode = 'hold';
  const waiting = lost.archive(); await nextTurn();
  const timeout = [...lost.timers.values()].find(timer => timer.ms === 30000); assert(timeout);
  timeout.fn(); await waiting; await nextTurn();
  assert.equal(lost.archives().length, 1, 'A timeout after dispatch never retries an archive that may already exist');
  assert(!lost.make('ses-archive').disabled); assert(text(lost.make('ses-status')).includes('Consulte a coleção'));
  lost.archiveMode = 'success'; await lost.archive();
  assert.equal(lost.archives().length, 2, 'Only a new explicit click can submit again');
  assert.equal(lost.archives()[1].body.draft_revision, 'revision-a', 'Lost acknowledgement does not invent or replace the accepted revision');
  assert.equal(lost.archives()[1].body._glpi_csrf_token, 'dispatch-token-2');

  const sharePage=createPage({enabled:true,allowed:true});
  assert(!sharePage.make('ses-archive-share').checked,'Sharing is not selected automatically');
  await sharePage.generate(); await sharePage.archive();
  assert(!Object.hasOwn(sharePage.archives().at(-1).body,'share'),'An unchecked archive remains personal even for an authorized team member');
  assert(text(sharePage.make('ses-status')).includes('histórico pessoal'));
  sharePage.make('ses-archive-share').checked=true; await sharePage.archive();
  assert.equal(sharePage.archives().at(-1).body.share,'1','Explicit sharing checkbox accompanies only the archive action');
  assert(text(sharePage.make('ses-status')).includes('disponível à equipe'));
  assert.equal(sharePage.archives().at(-1).body._glpi_csrf_token,'dispatch-token-2');
  for(const sharing of [{enabled:false,allowed:false},{enabled:true,allowed:false}]){
    const denied=createPage(sharing);await denied.generate();
    assert(denied.make('ses-archive-share').disabled,'Sharing control respects configured membership');
    denied.make('ses-archive-share').checked=true;await denied.archive();
    assert(!Object.hasOwn(denied.archives().at(-1).body,'share'),'A stale/forced checkbox cannot silently broaden the request');
  }
  const pagePhp=fs.readFileSync(path.join(__dirname,'../front/ses.php'),'utf8');
  const checkbox=pagePhp.match(/<input[^>]*id="ses-archive-share"[^>]*>/)[0];
  assert(!/\bchecked\b/.test(checkbox),'Server renders the sharing checkbox unchecked');

  process.stdout.write('Monthly archive UI: accepted server revisions, minimal CSRF-protected POST, manual refresh, busy/filter/register/financial guards, explicit optional sharing and no failure or timeout replay passed.\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exit(1); });
