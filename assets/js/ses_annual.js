(function () {
  'use strict';
  var config = window.G4FREPORTS_ANNUAL || {}, root = document.getElementById('g4fr-ses-annual');
  if (!root || root.dataset.initialized) return;
  root.dataset.initialized = '1';
  var state = { busy: false, year: null, records: [], selected: new Map(), reviewed: null, initial: true };
  var fmt = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  function el(id) { return document.getElementById(id); }
  function node(tag, text) { var n = document.createElement(tag); if (text !== undefined) n.textContent = String(text); return n; }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function notice(text, error) { var n = el('annual-status'); n.textContent = text; n.className = 'g4fr-ses-notice' + (error ? ' error' : ''); n.hidden = false; }
  function selection() {
    var months = Array.from(state.selected.keys()).sort();
    return { months: months, selections: months.filter(function (m) { return !!state.selected.get(m); }).map(function (m) { return Object.assign({ month: m }, state.selected.get(m)); }) };
  }
  function invalidate() { state.reviewed = null; el('annual-preview').hidden = true; controls(); }
  function controls() {
    Array.prototype.forEach.call(root.querySelectorAll('input,select,button'), function (n) { n.disabled = state.busy; });
    el('annual-all').disabled = state.busy || state.year === null;
    el('annual-preview-button').disabled = state.busy || !state.selected.size;
    el('annual-activity-button').disabled = state.busy || !selection().selections.length;
    el('annual-word').disabled = el('annual-excel').disabled = state.busy || !state.reviewed;
    el('annual-selection-count').textContent = state.selected.size + ' competência(s) selecionada(s).';
    clear(el('annual-selection'));
    Array.from(state.selected.keys()).sort().forEach(function (month) {
      var b = node('button', month + ' · remover'); b.type = 'button'; b.className = 'btn btn-outline-secondary btn-sm'; b.disabled = state.busy;
      b.addEventListener('click', function () { state.selected.delete(month); invalidate(); renderCatalog(); });
      el('annual-selection').appendChild(b);
    });
  }
  async function json(response) {
    var result; try { result = JSON.parse(await response.text()); } catch (e) { throw new Error('O servidor não retornou uma resposta válida (HTTP ' + response.status + '). Confira a sessão do GLPI.'); }
    if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível concluir a operação.');
    return result;
  }
  async function get(action, values) {
    var url = new URL(config.api_url, window.location.href); url.searchParams.set('action', action); url.searchParams.set('_ses_nonce', config.nonce);
    Object.keys(values || {}).forEach(function (k) { url.searchParams.set(k, values[k]); });
    var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 30000);
    try { return await json(await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store', signal: controller.signal })); }
    finally { clearTimeout(timer); }
  }
  var queue = Promise.resolve();
  function post(url, body, consume) {
    var operation = queue.then(async function () {
      var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 60000);
      try {
        var tokenUrl = new URL(config.csrf_url, window.location.href); tokenUrl.searchParams.set('action', 'get_csrf'); tokenUrl.searchParams.set('payload', JSON.stringify({ _ses_nonce: config.nonce }));
        var token = await json(await fetch(tokenUrl.toString(), { credentials: 'same-origin', cache: 'no-store', signal: controller.signal }));
        if (typeof token.csrf_token !== 'string' || !token.csrf_token) throw new Error('Não foi possível validar a sessão.');
        body.set('_glpi_csrf_token', token.csrf_token); body.set('_ses_nonce', config.nonce);
        return await consume(await fetch(url, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store', signal: controller.signal }));
      } finally { clearTimeout(timer); }
    });
    queue = operation.catch(function () {}); return operation;
  }
  function recordKey(record) { return record ? (record.archive_ref || record.id || '') : ''; }
  function reference(record) { var result = { id: record.id }; if (record.archive_ref) result.archive_ref = record.archive_ref; return result; }
  function selectedId(month) { return recordKey(state.selected.get(month)); }
  function setSelected(month, record) { state.selected.set(month, record ? reference(record) : null); }
  function referenceParams(params, month, record) { params.set('month', month); params.set('id', record.id); if (record.archive_ref) params.set('archive_ref', record.archive_ref); }
  function downloadUrl(month, record, format) {
    var url = new URL(format === 'json' ? config.api_url : config.monthly_export_url, window.location.href);
    if (format === 'json') url.searchParams.set('action', 'download_snapshot'); else url.searchParams.set('format', format);
    referenceParams(url.searchParams, month, record); url.searchParams.set('_ses_nonce', config.nonce); return url.toString();
  }
  async function exportMonth(month, record, format) {
    if (state.busy) return;
    var name = format === 'docx' ? 'Word' : 'Excel';
    var expectedType = format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 120000);
    state.busy = true; controls(); notice('Preparando o relatório mensal ' + name + ' de ' + month + '…');
    try {
      var response = await fetch(downloadUrl(month, record, format), { method: 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
      var contentType = String(response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
      if (!response.ok || contentType !== expectedType) { await json(response); throw new Error('O servidor não retornou um arquivo mensal ' + name + ' válido.'); }
      var blob = await response.blob(); if (!blob.size) throw new Error('O arquivo mensal retornado está vazio.');
      var url = URL.createObjectURL(blob), a = node('a'); a.href = url;
      a.download = (format === 'docx' ? 'Relatorio_Mensal_SES_' : 'Indicadores_SES_') + month + '-rev-' + record.id.slice(0, 12) + '.' + format;
      document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
      notice('Relatório mensal ' + name + ' de ' + month + ' preparado para download. A versão arquivada foi preservada.');
    } catch (error) { notice(error.name === 'AbortError' ? 'A exportação mensal demorou. Aguarde antes de tentar novamente; a versão arquivada permanece disponível.' : error.message, true); }
    finally { clearTimeout(timer); state.busy = false; controls(); }
  }
  async function shareRecord(month, record) {
    if (state.busy || !record.can_share) return;
    var body = new URLSearchParams(); body.set('action', 'share'); referenceParams(body, month, record);
    state.busy = true; controls(); notice('Disponibilizando esta versão à equipe de relatórios…');
    try {
      var result = await post(config.api_url, body, json), shared = result.record;
      if (!shared || shared.id !== record.id || shared.month !== month || !shared.archive_ref) throw new Error('O servidor não confirmou o compartilhamento. Consulte o ano novamente.');
      var oldKey = recordKey(record); state.records = state.records.map(function (r) { return r.month === month && recordKey(r) === oldKey ? shared : r; });
      if (selectedId(month) === oldKey) setSelected(month, shared);
      invalidate(); renderCatalog(); notice('Versão disponível à equipe de relatórios. Os dados e a autoria foram preservados.');
    } catch (error) { notice(error.name === 'AbortError' ? 'A confirmação demorou. Consulte o ano antes de tentar novamente.' : error.message, true); }
    finally { state.busy = false; controls(); }
  }
  function table(headers, rows) {
    var wrap = node('div'); wrap.className = 'g4fr-ses-table-wrap'; var t = node('table'); t.className = 'g4fr-ses-table'; var tr = node('tr'), head = node('thead'), body = node('tbody');
    headers.forEach(function (s) { tr.appendChild(node('th', s)); }); head.appendChild(tr); t.appendChild(head);
    rows.forEach(function (row) { var r = node('tr'); row.forEach(function (s) { r.appendChild(node('td', s === null || s === undefined ? '—' : s)); }); body.appendChild(r); });
    t.appendChild(body); wrap.appendChild(t); return wrap;
  }
  function renderCatalog() {
    clear(el('annual-catalog')); if (state.year === null) return;
    var t = node('table'); t.className = 'g4fr-ses-table'; var h = node('thead'), hr = node('tr'), b = node('tbody');
    ['Incluir na comparação', 'Competência', 'Versão arquivada', 'Relatório mensal salvo'].forEach(function (v) { hr.appendChild(node('th', v)); }); h.appendChild(hr); t.appendChild(h);
    for (var i = 1; i <= 12; i++) (function () {
      var month = state.year + '-' + String(i).padStart(2, '0'), rows = state.records.filter(function (r) { return r.month === month; });
      var r = node('tr'), c = node('td'), check = node('input'); check.type = 'checkbox'; check.checked = state.selected.has(month); check.setAttribute('aria-label', 'Incluir ' + month); c.appendChild(check); r.appendChild(c); r.appendChild(node('td', month));
      c = node('td'); var select = node('select'); select.className = 'form-select'; select.setAttribute('aria-label', 'Versão de ' + month);
      var empty = node('option', rows.length ? 'Escolha uma versão' : 'Sem mês arquivado'); empty.value = ''; select.appendChild(empty);
      rows.forEach(function (v) { var audience = v.visibility === 'shared' ? 'Equipe' : (v.visibility === 'private' ? 'Pessoal' : 'Meu arquivo'); var author = v.creator_id ? ' · autor #' + v.creator_id : ''; var o = node('option', (v.archived_at || '') + ' · ' + (v.scope_label || v.title || 'Relatório mensal') + ' · ' + audience + author + ' · ' + v.id.slice(0, 8)); o.value = recordKey(v); select.appendChild(o); });
      var pinned = selectedId(month);
      if (pinned && !rows.some(function (v) { return recordKey(v) === pinned; })) { var unavailable = node('option', 'Versão selecionada indisponível nesta consulta'); unavailable.value = pinned; select.appendChild(unavailable); }
      select.value = pinned; c.appendChild(select); r.appendChild(c);
      var detail = node('td'); r.appendChild(detail);
      function chosenRecord() { return rows.find(function (v) { return recordKey(v) === select.value; }); }
      function downloadLink() {
        clear(detail); var record = chosenRecord(); if (!record) return;
        var actions = node('div'); actions.className = 'g4fr-ses-actions';
        [['docx', 'Word mensal'], ['xlsx', 'Excel mensal'], ['json', 'Dados JSON']].forEach(function (item) {
          var a = node('a', item[1]); a.href = downloadUrl(month, record, item[0]); a.className = 'btn btn-outline-secondary btn-sm'; a.setAttribute('aria-label', item[1] + ' de ' + month + ' · revisão ' + record.id.slice(0, 12));
          if (item[0] !== 'json') a.addEventListener('click', function (event) { if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || (event.button !== undefined && event.button !== 0)) return; event.preventDefault(); return exportMonth(month, record, item[0]); });
          actions.appendChild(a);
        }); detail.appendChild(actions);
        detail.appendChild(node('p', record.visibility === 'shared' ? 'Disponível à equipe autorizada.' : (record.visibility === 'private' ? 'Arquivo pessoal.' : 'Arquivo de sua autoria.')));
        if (record.can_share) { var share = node('button', 'Disponibilizar à equipe'); share.type = 'button'; share.className = 'btn btn-outline-primary btn-sm'; share.addEventListener('click', function () { return shareRecord(month, record); }); detail.appendChild(share); }
      }
      check.addEventListener('change', function () {
        if (check.checked) { if (state.selected.size >= 24 && !state.selected.has(month)) { check.checked = false; notice('Selecione no máximo 24 competências.', true); return; } setSelected(month, chosenRecord()); } else state.selected.delete(month);
        invalidate();
      });
      select.addEventListener('change', function () {
        if (select.value && !state.selected.has(month) && state.selected.size >= 24) { select.value = ''; notice('Selecione no máximo 24 competências.', true); return; }
        if (select.value) { var chosen = chosenRecord(); if (chosen) setSelected(month, chosen); check.checked = true; } else if (check.checked) setSelected(month, null);
        downloadLink(); invalidate();
      }); downloadLink(); b.appendChild(r);
    })();
    t.appendChild(b); el('annual-catalog').appendChild(t); controls();
  }
  async function loadYear(event) {
    if (event) event.preventDefault(); if (state.busy) return;
    var year = Number(el('annual-year').value); if (!Number.isInteger(year) || year < 2000 || year > 2100) { notice('Informe um ano entre 2000 e 2100.', true); return; }
    state.busy = true; controls(); notice('Consultando as versões arquivadas…');
    try {
      var result = await get('list', { year: year }); state.year = year; state.records = result.revisions || [];
      if (state.initial) { for (var i = 1; i <= 12; i++) state.selected.set(year + '-' + String(i).padStart(2, '0'), null); state.initial = false; }
      renderCatalog(); notice('Escolha a versão de cada mês. Competências sem versão selecionada serão identificadas como ausentes.');
    } catch (error) { notice(error.name === 'AbortError' ? 'A consulta ao arquivo demorou. Tente novamente.' : error.message, true); }
    finally { state.busy = false; controls(); }
  }
  function renderPreview(report, analytics) {
    var target = el('annual-preview-body'); clear(target);
    target.appendChild(node('p', report.coverage.available + ' de ' + report.coverage.requested + ' competências com versão selecionada.'));
    if (analytics) {
      if ((analytics.findings || []).length) {
        target.appendChild(node('h3', 'Principais variações'));
        var findings = node('ul'); analytics.findings.forEach(function (finding) { findings.appendChild(node('li', finding.text)); }); target.appendChild(findings);
      }
      target.appendChild(node('h3', 'Volume de atendimento'));
      var tickets = analytics.volumes.tickets.rows, calls = analytics.volumes.calls.rows;
      target.appendChild(table(['Competência', 'Tickets solucionados', 'Chamadas recebidas'], analytics.months.map(function (month, i) { return [month, tickets[i].value, calls[i].value]; })));
      var prepared = (analytics.activity || []).filter(function (month) { return !!month.activity; }).length;
      target.appendChild(node('p', 'Mapas diários preparados: ' + prepared + ' de ' + report.coverage.available + ' versões selecionadas. ' + (prepared < report.coverage.available ? 'Use “Preparar mapas diários” para incluir a evidência disponível.' : '')));
      var unavailable = (analytics.activity || []).filter(function (month) { return month.activity && month.activity.status === 'unavailable'; }).map(function (month) { return month.month; });
      if (unavailable.length) target.appendChild(node('p', 'Sem detalhe diário conciliado em ' + unavailable.join(', ') + '. Os totais mensais estão preservados; os relatórios identificam a evidência ausente ou inconsistente.'));
      target.appendChild(node('h3', 'Indicadores por competência'));
      target.appendChild(table(['Indicador'].concat(analytics.months), analytics.matrix.map(function (metric) {
        return [metric.id].concat(metric.cells.map(function (cell) {
          var status = { met: 'Meta atingida', unmet: 'Meta não atingida', pending: 'Pendente', provisional: 'Provisório', not_applicable: 'Não aplicável', missing: 'Ausente' }[cell.status] || cell.status;
          return (cell.value === null || cell.value === undefined ? '—' : fmt.format(cell.value) + '%') + ' · ' + status + (cell.period_completeness !== 'complete' && cell.status !== 'missing' ? ' / período não encerrado' : '');
        }));
      })));
    }
    (report.warnings || []).forEach(function (s) { target.appendChild(node('p', s)); });
    var f = report.financial; target.appendChild(node('h3', 'Custos mensais')); target.appendChild(node('p', f.coverage_label));
    target.appendChild(table(['Competência', 'Bruto', 'Glosas', 'Ajustes', 'Líquido', 'Situação'], f.months.map(function (m) { return [m.month].concat(['gross_cents', 'deduction_cents', 'adjustment_cents', 'net_cents'].map(function (key) { return m[key] === null || m[key] === undefined || !m.available ? '—' : money.format(m[key] / 100); })).concat([{ missing: 'Ausente', unavailable: 'Indisponível', provisional: 'Mês provisório', incomplete: 'Incompleto', complete: 'Completo' }[m.status] || 'Incompleto']); })));
    target.appendChild(node('p', f.subtotal_cents.net === null || f.subtotal_cents.net === undefined
      ? 'Total líquido não apurado: nenhuma competência encerrada e financeiramente completa.'
      : (f.is_partial ? 'Subtotal líquido dos meses encerrados e financeiramente completos: ' : 'Total líquido: ') + money.format(f.subtotal_cents.net / 100)));
    var details = node('details'); details.appendChild(node('summary', 'Resultados, metas e critérios de comparação por indicador')); target.appendChild(details);
    Object.keys(report.metrics).forEach(function (id) {
      var m = report.metrics[id]; details.appendChild(node('h3', id + ' · ' + m.label));
      details.appendChild(table(['Competência', 'Resultado', 'Meta', 'Situação', 'Comparação'], m.months.map(function (r) {
        var c = r.comparison || {}, movement = { improved: 'Melhora', declined: 'Piora', unchanged: 'Sem variação', unavailable: 'Sem comparação' }[c.direction] || 'Sem comparação';
        var comparison = c.delta_pp === null || c.delta_pp === undefined ? (c.reason || movement) : movement + ': ' + (c.delta_pp > 0 ? '+' : '') + fmt.format(c.delta_pp) + ' p.p. em relação a ' + c.previous_month + (c.gap_months > 0 ? ' (intervalo entre competências selecionadas)' : '');
        var status = { met: 'Meta atingida', unmet: 'Meta não atingida', pending: 'Pendente', provisional: 'Provisório', not_applicable: 'Não aplicável', missing: 'Ausente' }[r.status] || r.status;
        if (r.status !== 'missing' && r.period_completeness !== 'complete') status += r.period_completeness === 'provisional' ? ' / mês provisório' : ' / encerramento não confirmado';
        return [r.month, r.value === null ? '—' : fmt.format(r.value) + '%', r.target === null ? '—' : fmt.format(r.target) + '%', status, comparison];
      })));
    });
    el('annual-preview').hidden = false;
  }
  async function preview() {
    if (state.busy || !state.selected.size) return;
    var payload = selection(), body = new URLSearchParams(); body.set('action', 'preview'); body.set('payload', JSON.stringify(payload)); state.busy = true; controls(); notice('Preparando a comparação dos meses selecionados…');
    try { var result = await post(config.api_url, body, json); renderPreview(result.report, result.analytics); state.reviewed = result.report.coverage.available > 0 ? payload : null; notice(state.reviewed ? 'Comparação preparada. Word e Excel usarão exatamente estas versões.' : 'Nenhuma versão selecionada. Escolha ao menos uma versão arquivada para exportar.'); }
    catch (error) { state.reviewed = null; notice(error.name === 'AbortError' ? 'A consulta demorou. Tente novamente.' : error.message, true); }
    finally { state.busy = false; controls(); }
  }
  async function prepareActivity() {
    if (state.busy) return;
    var payload = selection(); if (!payload.selections.length) return;
    invalidate(); state.busy = true; controls();
    var completed = 0, succeeded = false;
    try {
      for (var i = 0; i < payload.selections.length; i++) {
        var revision = payload.selections[i], body = new URLSearchParams();
        notice('Preparando mapas diários: ' + (i + 1) + ' de ' + payload.selections.length + ' · ' + revision.month + '…');
        body.set('action', 'prepare_activity'); referenceParams(body, revision.month, revision);
        await post(config.api_url, body, json); completed++;
      }
      succeeded = true;
    } catch (error) {
      notice(completed + ' de ' + payload.selections.length + ' mapas preparados. ' + (error.name === 'AbortError' ? 'A preparação demorou; confira novamente antes de continuar.' : error.message) + ' Os resultados mensais arquivados estão preservados.', true);
    } finally { state.busy = false; controls(); }
    if (succeeded) await preview();
  }
  async function exportFile(format) {
    if (state.busy || !state.reviewed) return;
    var body = new URLSearchParams(); body.set('format', format); body.set('payload', JSON.stringify(state.reviewed)); state.busy = true; controls(); notice('Preparando o arquivo…');
    try {
      await post(config.export_url, body, async function (response) {
        var type = response.headers.get('Content-Type') || ''; if (!response.ok || !/application\/vnd\.openxmlformats-officedocument/.test(type)) { await json(response); throw new Error('O arquivo não foi retornado.'); }
        var blob = await response.blob(); if (!blob.size) throw new Error('O arquivo retornado está vazio.');
        var url = URL.createObjectURL(blob), a = node('a'); a.href = url; a.download = 'Relatorio_SES_Meses_Selecionados.' + format; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
      }); notice('Arquivo preparado para download.');
    } catch (error) { notice(error.name === 'AbortError' ? 'A exportação demorou. Tente novamente com menos competências.' : error.message, true); }
    finally { state.busy = false; controls(); }
  }
  el('annual-year-form').addEventListener('submit', loadYear);
  el('annual-all').addEventListener('click', function () { if (state.busy || state.year === null) return; var months = []; for (var i = 1; i <= 12; i++) months.push(state.year + '-' + String(i).padStart(2, '0')); if (state.selected.size + months.filter(function (m) { return !state.selected.has(m); }).length > 24) { notice('Selecione no máximo 24 competências.', true); return; } months.forEach(function (m) { if (!state.selected.has(m)) state.selected.set(m, null); }); invalidate(); renderCatalog(); });
  el('annual-clear').addEventListener('click', function () { if (state.busy) return; state.selected.clear(); invalidate(); renderCatalog(); });
  el('annual-preview-button').addEventListener('click', preview);
  el('annual-activity-button').addEventListener('click', prepareActivity);
  el('annual-word').addEventListener('click', function () { exportFile('docx'); });
  el('annual-excel').addEventListener('click', function () { exportFile('xlsx'); });
  loadYear();
})();
