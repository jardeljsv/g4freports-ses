'use strict';
// Executes the real SES controller. This server double consumes native-style
// form tokens once; it is not a substitute for testing a deployed GLPI session.
const assert = require('assert');
const { createPage, changedPreview, nextTurn } = require('./ses_register_import_ui_test.cjs');

function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
function server(page) {
  const state = { issued: new Set(), used: [], posts: [], refreshes: 0, active: 0, maximum: 0, holds: [], holdNext: false, failRefresh: false };
  function token() { const value = 'native-token-' + (++state.sequence); state.issued.add(value); return value; }
  state.sequence = 0;
  page.handleRequest = async (url, request, body) => {
    assert.equal(request.credentials, 'same-origin'); assert.equal(request.cache, 'no-store');
    if (request.method === 'GET') {
      state.refreshes++;
      const target = new URL(url);
      assert.equal(target.pathname, '/api'); assert.equal(target.searchParams.get('action'), 'get_csrf');
      assert.deepEqual(JSON.parse(target.searchParams.get('payload')), { _ses_nonce: 'nonce' });
      if (state.failRefresh) return { ok: false, status: 403, text: async () => '<html>Session expired</html>' };
      return { ok: true, status: 200, text: async () => JSON.stringify({ ok: true, csrf_token: token() }) };
    }
    assert.equal(request.method, 'POST');
    assert(state.issued.delete(body._glpi_csrf_token), 'POST has a new, unconsumed token');
    assert(!state.used.includes(body._glpi_csrf_token), 'No token is reused');
    state.used.push(body._glpi_csrf_token); state.posts.push({ url, body });
    state.active++; state.maximum = Math.max(state.maximum, state.active);
    const nextToken = token();
    let result = { ok: true, csrf_token: nextToken, history: [] };
    if (url === '/import') result = Object.assign(result, changedPreview(page.staff));
    if (body.rows) result = Object.assign(result, { rows: JSON.parse(body.rows), revision: 'saved-revision' });
    if (body.config) result = Object.assign(result, { config: JSON.parse(body.config), revision: 'finance-revision' });
    let hold = null;
    if (state.holdNext) {
      state.holdNext = false; hold = deferred(); state.holds.push(hold);
      request.signal.addEventListener('abort', () => hold.reject(Object.assign(new Error('Request aborted after server consumed token'), { name: 'AbortError' })), { once: true });
    }
    async function read(value) { try { if (hold) await hold.promise; return value; } finally { state.active--; } }
    return {
      ok: true, status: 200,
      headers: { get: key => key === 'X-G4F-CSRF-Token' ? nextToken : key === 'Content-Type' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : null },
      text: () => read(JSON.stringify(result)), blob: () => read({ size: 10 })
    };
  };
  return state;
}
const readJson = async response => JSON.parse(await response.text());
const pause = async () => { await nextTurn(); await nextTurn(); };

(async () => {
  // A history read has no page busy flag. A save must still wait until its
  // response body has been read; its timeout must start at actual dispatch.
  const p = createPage(), s = server(p); s.holdNext = true;
  await p.make('ses-staff-history-refresh').emit('click'); await pause();
  assert.equal(s.posts.length, 1); assert.equal(s.posts[0].body.action, 'history');
  const saving = p.make('g4fr-ses-staff-form').emit('submit'); await pause();
  assert.equal(s.posts.length, 1, 'Save does not race history');
  assert.equal(s.refreshes, 1, 'Queued operation does not fetch its token early');
  assert.equal([...p.timers.values()].filter(x => x.ms === 30000).length, 1, 'Waiting operations do not start a timeout');
  s.holds[0].resolve(); await saving;
  assert.equal(s.posts.length, 2); assert.equal(s.posts[1].body.action, 'save_staff');
  assert.equal(s.maximum, 1); assert.equal(s.refreshes, 2);
  assert.equal(p.config.staff_revision, 'saved-revision');

  const importing = createPage(), ins = server(importing); ins.holdNext = true;
  await importing.upload('staff'); await pause();
  await importing.make('ses-staff-history-refresh').emit('click'); await pause();
  assert.equal(ins.posts.length, 1); assert.equal(ins.posts[0].url, '/import');
  ins.holds[0].resolve(); await pause();
  assert.equal(ins.posts.length, 2); assert.equal(ins.posts[1].body.action, 'history');
  assert.equal(ins.maximum, 1); assert(!importing.make('ses-staff-import-preview').hidden);
  assert.equal(ins.posts.filter(x => x.body.action === 'save_staff').length, 0, 'Import remains preview-only');

  // The financial editor receives this same queue. A history read and
  // financial POST cannot use independent transport/CSRF state.
  const f = createPage(), fs = server(f); fs.holdNext = true;
  await f.make('ses-tests-history-refresh').emit('click'); await pause();
  const financeBody = new URLSearchParams({ action: 'save', config: '{}', revision: 'r1', _ses_nonce: 'nonce' });
  const finance = f.financialOptions.postWithCsrf('/finance', financeBody, readJson); await pause();
  assert.equal(fs.posts.length, 1);
  fs.holds[0].resolve(); assert.equal((await finance).revision, 'finance-revision');
  assert.equal(fs.posts[1].url, '/finance'); assert.equal(fs.maximum, 1);

  // Binary export must keep the queue occupied until its body is consumed.
  const b = createPage(false), bs = server(b); bs.holdNext = true;
  const exporting = b.export('staff'); await pause();
  const followupBody = new URLSearchParams({ action: 'history', kind: 'staff', _ses_nonce: 'nonce' });
  const afterBlob = b.financialOptions.postWithCsrf('/save', followupBody, readJson); await pause();
  assert.equal(bs.posts.length, 1); assert.equal(bs.posts[0].url, '/export');
  bs.holds[0].resolve(); await exporting; await afterBlob;
  assert.equal(bs.maximum, 1); assert.equal(bs.refreshes, 2);

  // The server may have saved before the browser times out. Never replay it.
  // The next operation obtains a fresh token despite the lost response.
  const lost = createPage(), ls = server(lost); ls.holdNext = true;
  const lostSave = lost.make('g4fr-ses-staff-form').emit('submit'); await pause();
  assert.equal(ls.posts[0].body.action, 'save_staff');
  [...lost.timers.values()].find(x => x.ms === 30000).fn(); await lostSave;
  assert.equal(ls.posts.length, 1, 'An ambiguous save is never replayed');
  assert.equal(lost.config.staff_revision, 'staff-r1', 'Unconfirmed result never changes saved revision');
  await lost.make('ses-staff-history-refresh').emit('click'); await pause();
  assert.equal(ls.posts.length, 2); assert.equal(ls.posts[1].body.action, 'history');
  assert.equal(ls.refreshes, 2); assert.equal(ls.maximum, 1);

  // A failed refresh prevents dispatch altogether, preserves rows and allows
  // an explicitly requested later operation to retry token acquisition.
  const denied = createPage(), ds = server(denied); ds.failRefresh = true;
  await denied.make('g4fr-ses-staff-form').emit('submit');
  assert.equal(ds.posts.length, 0); assert.equal(denied.config.staff_revision, 'staff-r1');
  assert(denied.make('ses-status').children.some(x => x.textContent.includes('não foi enviada')));
  ds.failRefresh = false; await denied.make('g4fr-ses-staff-form').emit('submit');
  assert.equal(ds.posts.length, 1); assert.equal(ds.posts[0].body.action, 'save_staff');

  process.stdout.write('SES CSRF queue: history/save/financial/export serialization, dispatch-time tokens/timeouts, body completion, lost response without replay, failed refresh and read-only export passed.\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exit(1); });
