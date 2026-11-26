(function () {
  'use strict';
  var config = window.G4FREPORTS_SES || {};
  var root = document.getElementById('g4fr-ses');
  if (!root || root.dataset.initialized) return;
  root.dataset.initialized = '1';
  var form = document.getElementById('g4fr-ses-form');
  var state = { snapshot: null, token: '', draftRevision: '', busy: false, dataDirty: false, manualDirty: false, testsDirty: false, staffDirty: false, financialDirty: false, source: 'tickets', page: 0 };
  var testNumbers = (config.test_numbers || []).map(function (row) { return { number: String(row.number || ''), label: String(row.label || ''), active: row.active === true || row.active === 1 || row.active === '1' }; });
  function staffIsNotBilled(row) {
    var value = Object.prototype.hasOwnProperty.call(row, 'not_billed') ? row.not_billed : row.billed;
    return value === true || value === 1 || value === '1';
  }
  // Original checked marks mean exclusion, including the legacy field named billed.
  var staffRows = (config.staff_rows || []).map(function (row) { var copy = Object.assign({}, row, { not_billed: staffIsNotBilled(row) }); delete copy.billed; return copy; });
  var financialEditor = null;
  var pageSize = 25;
  var statusNames = { met: 'Meta atendida', unmet: 'Fora da meta', not_applicable: 'Não aplicável', pending: 'Pendente', provisional: 'Provisório' };
  var numberFormat = new Intl.NumberFormat('pt-BR');
  var percentFormat = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function el(id) { return document.getElementById(id); }
  function node(tag, className, text) { var element = document.createElement(tag); if (className) element.className = className; if (text !== undefined) element.textContent = String(text); return element; }
  function clear(element) { while (element.firstChild) element.removeChild(element.firstChild); }
  function number(value) { return value === null || value === undefined || value === '' ? '—' : numberFormat.format(Number(value)); }
  function seconds(value) { if (value === null || value === undefined || value === '') return '—'; var n = Math.max(0, Math.round(Number(value))); return String(Math.floor(n / 3600)).padStart(2, '0') + ':' + String(Math.floor((n % 3600) / 60)).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0'); }
  function bool(value) { return value === null || value === undefined ? '—' : (value ? 'Sim' : 'Não'); }
  function status(message, kind, loading) { var target = el('ses-status'); clear(target); target.className = 'g4fr-ses-notice' + (kind ? ' ' + kind : ''); if (loading) target.appendChild(node('span', 'g4fr-ses-loading')); target.appendChild(document.createTextNode(message)); target.hidden = false; }
  function updateControls() {
    var ready = !!state.snapshot && !!state.token && !state.busy && !state.dataDirty && !state.manualDirty && !state.testsDirty && !state.staffDirty && !state.financialDirty;
    el('ses-generate').disabled = state.busy || !config.connections_ready || state.testsDirty || state.staffDirty || state.financialDirty;
    el('ses-generate').textContent = state.busy ? 'Aguarde…' : 'Gerar relatório mensal';
    el('ses-export-docx').disabled = !ready;
    el('ses-export-xlsx').disabled = !ready;
    if (el('ses-archive')) el('ses-archive').disabled = !ready || !state.draftRevision;
    if (el('ses-archive-share')) el('ses-archive-share').disabled = state.busy || !(config.archive_sharing && config.archive_sharing.enabled && config.archive_sharing.allowed);
    el('ses-save-manual').disabled = !state.snapshot || !state.manualDirty || state.dataDirty || state.busy || state.testsDirty || state.staffDirty || state.financialDirty;
    Array.prototype.forEach.call(form.querySelectorAll('input,select,textarea'), function (input) { input.disabled = state.busy; });
    ['g4fr-ses-tests-form', 'g4fr-ses-staff-form'].forEach(function (id) { var registerForm = el(id); if (registerForm) Array.prototype.forEach.call(registerForm.querySelectorAll('input:not([type="hidden"]),select,button'), function (input) { input.disabled = state.busy || input.dataset.pageBound === '1' || input.dataset.monthBound === '1'; }); });
    Array.prototype.forEach.call(root.querySelectorAll('.g4fr-ses-register-tools input,.g4fr-ses-register-tools select,.g4fr-ses-register-tools button,.g4fr-ses-register-export select,.g4fr-ses-register-export button'), function (input) { input.disabled = state.busy; });
    Array.prototype.forEach.call(root.querySelectorAll('.g4fr-ses-register-pager button'), function (input) { input.disabled = state.busy || input.dataset.pageBound === '1' || input.dataset.monthBound === '1'; });
    var message = 'Gere o relatório para revisar os indicadores e exportar.';
    if (!config.connections_ready) message = 'As duas conexões de banco devem estar configuradas e habilitadas.';
    else if (state.testsDirty || state.staffDirty || state.financialDirty) message = 'Salve as alterações nos cadastros antes de gerar ou exportar o relatório mensal. A exportação das tabelas continua disponível.';
    else if (state.dataDirty && state.snapshot) message = 'Os critérios mudaram. Gere novamente para atualizar os indicadores.';
    else if (state.manualDirty && state.snapshot) message = 'Atualize os textos para incluí-los nos arquivos exportados.';
    else if (ready) message = 'Word e Excel usarão os mesmos dados e critérios desta prévia.';
    if (state.busy) message = 'Processando o relatório. Aguarde o término desta operação.';
    el('ses-draft-state').textContent = message;
    if (financialEditor) financialEditor.setBusy(state.busy);
  }
  function ids(name) {
    var raw = String(form.elements[name].value || '').trim();
    if (!raw) return [];
    if (!/^[\d\s,;]+$/.test(raw)) throw new Error('Informe somente IDs numéricos separados por vírgula em "' + (form.elements[name].labels[0].textContent || name) + '".');
    var result = [];
    raw.split(/[\s,;]+/).filter(Boolean).forEach(function (part) { var id = Number(part); if (!Number.isSafeInteger(id) || id < 1) throw new Error('Os IDs devem ser números inteiros maiores que zero.'); if (result.indexOf(id) < 0) result.push(id); });
    return result;
  }
  function manualInput() { var input = {}; ['report_title', 'executive_summary', 'actions', 'notes', 'phone_survey_actions'].forEach(function (name) { input[name] = String(form.elements[name].value || '').trim(); var max = name === 'report_title' ? 180 : 20000; if (new TextEncoder().encode(input[name]).length > max) throw new Error('Reduza o texto de "' + form.elements[name].labels[0].textContent + '" para caber no limite do relatório.'); }); return input; }
  function collectInput() {
    var input = manualInput();
    input.month = form.elements.month.value;
    input.entity_id = Number(form.elements.entity_id.value);
    input.queue_filter = form.elements.queue_filter.value.trim();
    if (new TextEncoder().encode(input.queue_filter).length > 100) throw new Error('Reduza o identificador da fila de telefonia.');
    input.priority_map = form.elements.priority_preset.value === 'contract' ? { INS1: 5, INS2: 4, INS3: 3, INS4: 2 } : { INS1: 2, INS2: 3, INS3: 4, INS4: 5 };
    ['group_ids', 'n1_categories', 'n1_group_ids', 'n2_group_ids', 'n3_group_ids', 'validated_kb_ids', 'incomplete_ticket_ids'].forEach(function (name) { input[name] = ids(name); });
    input.ins6_mode = 'assigned_n1';
    input.ins12_mode = form.elements.ins12_mode.value;
    input.kb_mode = form.elements.kb_mode.value;
    ['sla_n1_calendar_id', 'sla_n2_calendar_id', 'sla_n3_calendar_id'].forEach(function (name) {
      var value = Number(form.elements[name].value);
      if (!Number.isSafeInteger(value) || value < 0 || value > 2147483647) throw new Error('Informe um ID de calendário válido ou 0 para identificação automática.');
      input[name] = value;
    });
    return input;
  }
  async function request(action, payload) {
    var serialized = JSON.stringify(payload);
    if (encodeURIComponent(JSON.stringify(Object.assign({}, payload, { _ses_nonce: config.nonce }))).length > 1800) {
      var bytes = new TextEncoder().encode(serialized);
      if (bytes.length > 128000) throw new Error('Os critérios e textos excedem o limite de 128 KB do relatório. Reduza os textos e tente novamente.');
      var totalChunks = Math.ceil(bytes.length / 1024);
      var upload = await sendRequest('input_start', { size_bytes: bytes.length, total_chunks: totalChunks });
      if (!upload.upload_token) throw new Error('Não foi possível preparar o envio dos textos.');
      for (var index = 0; index < totalChunks; index++) {
        var part = bytes.subarray(index * 1024, (index + 1) * 1024);
        var binary = ''; for (var byte = 0; byte < part.length; byte++) binary += String.fromCharCode(part[byte]);
        await sendRequest('input_chunk', { upload_token: upload.upload_token, index: index, data: btoa(binary) });
      }
      var staged = await sendRequest('input_finish', { upload_token: upload.upload_token });
      if (!staged.input_token) throw new Error('O envio dos textos não foi concluído.');
      return sendRequest(action, { input_token: staged.input_token });
    }
    return sendRequest(action, payload);
  }
  async function sendRequest(action, payload) {
    var url = new URL(config.api_url, window.location.href);
    var requestPayload = Object.assign({}, payload, { _ses_nonce: config.nonce });
    url.searchParams.set('action', action);
    url.searchParams.set('payload', JSON.stringify(requestPayload));
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 90000);
    try {
      var response = await fetch(url.toString(), { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
      var text = await response.text();
      var result;
      try { result = JSON.parse(text); } catch (error) { throw new Error(response.status === 414 ? 'O texto excedeu o limite aceito pelo servidor. Reduza os textos e tente novamente.' : 'O servidor não retornou o resultado do relatório (HTTP ' + response.status + '). Verifique a sessão do GLPI e tente novamente.'); }
      if (!response.ok || !result.ok) throw new Error(typeof result.error === 'string' ? result.error : (result.message || 'Não foi possível concluir a operação.'));
      return result;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('O servidor não concluiu o relatório em 90 segundos. Verifique o acesso e os tempos de consulta das duas conexões.');
      throw error;
    } finally { clearTimeout(timer); }
  }
  // Native form POSTs consume their CSRF token. Serialize every SES POST,
  // including history reads, and obtain a new token only when dispatching.
  // A lost response must never cause an automatic replay of a save.
  var postQueue = Promise.resolve();
  function postWithCsrf(url, body, consume) {
    var operation = postQueue.then(async function () {
      var controller = new AbortController();
      var timer = setTimeout(function () { controller.abort(); }, 30000);
      try {
        var tokenUrl = new URL(config.api_url, window.location.href);
        tokenUrl.searchParams.set('action', 'get_csrf');
        tokenUrl.searchParams.set('payload', JSON.stringify({ _ses_nonce: config.nonce }));
        var tokenResponse = await fetch(tokenUrl.toString(), { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
        var tokenText = await tokenResponse.text(), tokenResult;
        try { tokenResult = JSON.parse(tokenText); } catch (error) { tokenResult = null; }
        if (!tokenResponse.ok || !tokenResult || !tokenResult.ok || typeof tokenResult.csrf_token !== 'string' || !tokenResult.csrf_token) {
          throw new Error('Não foi possível validar a sessão do GLPI. A operação não foi enviada e seus valores continuam nesta tela.');
        }
        config.csrf_token = tokenResult.csrf_token;
        body.set('_glpi_csrf_token', tokenResult.csrf_token);
        var response = await fetch(url, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
        return await consume(response);
      } finally { clearTimeout(timer); }
    });
    // Failure releases the queue, but the next operation refreshes its token.
    postQueue = operation.catch(function () {});
    return operation;
  }
  function acceptSnapshot(result) {
    if (!result.snapshot || !result.token) throw new Error('O servidor retornou um rascunho incompleto. Gere novamente antes de exportar.');
    state.snapshot = result.snapshot; state.token = result.token; state.draftRevision = result.draft_revision || ''; state.dataDirty = false; state.manualDirty = false; state.page = 0;
    renderSnapshot();
  }
  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (state.busy || state.testsDirty || state.staffDirty || state.financialDirty || !form.reportValidity()) return;
    if (!config.connections_ready) { status('Configure e habilite as conexões GLPI e telefonia antes de gerar.', 'error'); return; }
    var input;
    try { input = collectInput(); } catch (error) { status(error.message, 'error'); return; }
    state.busy = true; updateControls();
    status('Consultando os chamados e as ligações do mês e calculando os indicadores…', '', true);
    try { acceptSnapshot(await request('generate', input)); status('Relatório gerado. Revise os indicadores e exporte os arquivos.', 'success'); }
    catch (error) { status(error.message || 'Não foi possível gerar o relatório.', 'error'); }
    finally { state.busy = false; updateControls(); }
  });
  form.addEventListener('input', function (event) { if (event.target.dataset.manual) state.manualDirty = true; else state.dataDirty = true; if (event.target.name === 'month') { renderStaff(); if (financialEditor) financialEditor.renderMonth(); } updateControls(); });
  form.addEventListener('change', function (event) {
    if (event.target.dataset.manual) state.manualDirty = true; else state.dataDirty = true;
    if (event.target.name === 'priority_preset') el('ses-priority-description').textContent = event.target.value === 'contract' ? 'Códigos GLPI: INS1 = 5; INS2 = 4; INS3 = 3; INS4 = 2. As metas continuam associadas à prioridade; a apuração usa a duração consolidada do GLPI e a abertura registrada, inclusive em clones.' : 'Códigos GLPI: INS1 = 2; INS2 = 3; INS3 = 4; INS4 = 5. Esta associação será informada no relatório.';
    if (event.target.name === 'month') { renderStaff(); if (financialEditor) financialEditor.renderMonth(); }
    updateControls();
  });
  el('ses-save-manual').addEventListener('click', async function () {
    if (state.busy || !state.snapshot || state.dataDirty || state.testsDirty || state.staffDirty || state.financialDirty) return;
    var input;
    try { input = manualInput(); } catch (error) { status(error.message, 'error'); return; }
    input.token = state.token;
    state.busy = true; updateControls(); status('Atualizando os textos no rascunho…', '', true);
    try { acceptSnapshot(await request('update_manual', input)); status('Textos atualizados. Os dois arquivos usarão esta versão do relatório.', 'success'); }
    catch (error) { status(error.message, 'error'); }
    finally { state.busy = false; updateControls(); }
  });

  async function exportReport(format) {
    if (!state.snapshot || !state.token || state.busy || state.dataDirty || state.manualDirty || state.testsDirty || state.staffDirty || state.financialDirty) return;
    state.busy = true; updateControls(); status('Preparando o arquivo ' + (format === 'docx' ? 'Word' : 'Excel') + '…', '', true);
    try {
      var url = new URL(config.export_url, window.location.href); url.searchParams.set('token', state.token); url.searchParams.set('format', format); url.searchParams.set('_ses_nonce', config.nonce);
      var response = await fetch(url.toString(), { method: 'GET', credentials: 'same-origin', cache: 'no-store' });
      var contentType = response.headers.get('Content-Type') || '';
      if (!response.ok || /json|text\/html/i.test(contentType)) { var failure = await response.text(); try { var parsed = JSON.parse(failure); throw new Error(parsed.error || parsed.message || 'Não foi possível exportar.'); } catch (error) { if (error.name !== 'SyntaxError') throw error; throw new Error('O servidor não concluiu a exportação (HTTP ' + response.status + '). Gere novamente se a sessão expirou.'); } }
      var blob = await response.blob();
      if (!blob.size) throw new Error('O arquivo retornado está vazio.');
      var objectUrl = URL.createObjectURL(blob); var link = node('a'); link.href = objectUrl; link.download = 'Relatorio_Mensal_SES_' + String(state.snapshot.period.month || '').replace(/[^\d-]/g, '') + '.' + format; document.body.appendChild(link); link.click(); link.remove(); setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
      status('Arquivo ' + (format === 'docx' ? 'Word' : 'Excel') + ' preparado para download.', 'success');
    } catch (error) { status(error.message || 'Não foi possível exportar o relatório.', 'error'); }
    finally { state.busy = false; updateControls(); }
  }
  el('ses-export-docx').addEventListener('click', function () { exportReport('docx'); });
  el('ses-export-xlsx').addEventListener('click', function () { exportReport('xlsx'); });
  if (el('ses-archive')) el('ses-archive').addEventListener('click', async function () {
    if (el('ses-archive').disabled || state.busy || !state.token || !state.draftRevision) return;
    var body = new URLSearchParams();
    body.set('action', 'archive'); body.set('token', state.token);
    body.set('draft_revision', state.draftRevision); body.set('_ses_nonce', config.nonce);
    if (config.archive_sharing && config.archive_sharing.enabled && config.archive_sharing.allowed && el('ses-archive-share') && el('ses-archive-share').checked) body.set('share', '1');
    state.busy = true; updateControls(); status('Arquivando a versão revisada deste mês…', '', true);
    try {
      var archived = await postWithCsrf(config.archive_url, body, async function (response) {
        var result; try { result = JSON.parse(await response.text()); } catch (error) { throw new Error('O servidor não confirmou o arquivamento. Consulte a coleção antes de tentar novamente.'); }
        if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível arquivar o mês.');
        return result.record || {};
      });
      status('Mês arquivado' + (archived.visibility === 'shared' ? ' e disponível à equipe de relatórios.' : ' no seu histórico pessoal.') + ' Abra Histórico mensal e comparação para baixar novamente o Word ou Excel mensal completo. Alterações posteriores no rascunho não mudam a versão arquivada.', 'success');
    } catch (error) { status(error.name === 'AbortError' ? 'A confirmação do arquivamento demorou. Consulte a coleção antes de tentar novamente. O rascunho continua disponível.' : error.message, 'error'); }
    finally { state.busy = false; updateControls(); }
  });

  function renderSelections(snapshot) {
    var target = el('ses-selections');
    if (!target) return;
    clear(target);
    var input = snapshot.input || {}, catalog = snapshot.selection_catalog || {};
    function name(kind, id) { return String(((catalog[kind] || {})[id] || {}).name || 'Nome não registrado nesta extração'); }
    function labels(kind, ids, empty) { return ids.length ? ids.map(function (id) { return id + ' — ' + name(kind, id); }).join('; ') : empty; }
    target.appendChild(node('p', 'g4fr-ses-method', 'Grupos N1 usados no INS6: ' + labels('groups', input.n1_group_ids || [], 'Não informado')));
    target.appendChild(node('p', 'g4fr-ses-method', 'INS4 · N1 e N2: ' + labels('groups', (input.n1_group_ids || []).concat(input.n2_group_ids || []), 'Não informado') + '. Sem vínculo N3; não exige categoria elegível N1.'));
    target.appendChild(node('p', 'g4fr-ses-method', 'Grupos N3 excluídos de INS3, INS4 e INS6: ' + labels('groups', input.n3_group_ids || [], 'Não informado')));
    target.appendChild(node('p', 'g4fr-ses-method', 'Fontes INS12: ' + (input.ins12_mode === 'glpi_only' ? 'Somente chamados GLPI' : 'Chamados GLPI e pesquisas de telefonia')));
    target.appendChild(node('p', 'g4fr-ses-help', 'INS11: ' + (input.kb_mode === 'category_proxy' ? 'Associação por categoria de base de conhecimento. A lista de artigos não participa deste modo.' : 'Vínculo direto a artigo previamente validado. Informe a lista de artigos validados para usar este critério.')));
    var details = node('details', 'g4fr-ses-details');
    details.appendChild(node('summary', '', 'IDs e nomes usados nesta extração'));
    var wrap = node('div', 'g4fr-ses-table-wrap'), table = node('table', 'g4fr-ses-table');
    var head = node('thead'), header = node('tr');
    ['Seleção', 'ID', 'Nome na base GLPI do relatório'].forEach(function (label) { var th = node('th', '', label); th.scope = 'col'; header.appendChild(th); });
    head.appendChild(header); table.appendChild(head);
    var body = node('tbody');
    [['groups', 'group_ids', 'Filtro geral de grupos'], ['groups', 'n1_group_ids', 'Grupo N1'], ['groups', 'n2_group_ids', 'Grupo N2 · INS4'], ['groups', 'n3_group_ids', 'Grupo N3 excluído de INS3/4/6'], ['categories', 'n1_categories', 'Categoria elegível N1'], ['articles', 'validated_kb_ids', 'Artigo validado informado']].forEach(function (selection) {
      (input[selection[1]] || []).forEach(function (id) {
        var row = node('tr');
        [selection[2], id, name(selection[0], id)].forEach(function (value) { row.appendChild(node('td', '', value)); });
        body.appendChild(row);
      });
    });
    table.appendChild(body); wrap.appendChild(table); details.appendChild(wrap); target.appendChild(details);
  }

  function renderSnapshot() {
    var snapshot = state.snapshot;
    el('ses-preview').hidden = false;
    el('ses-preview-title').textContent = 'Prévia · ' + ((snapshot.period || {}).label || (snapshot.period || {}).month || 'Relatório mensal');
    el('ses-generated-at').textContent = 'Extraído em ' + String(snapshot.generated_at || '').replace('T', ' ') + ' · ' + String(snapshot.rules_version || '');
    var totals = el('ses-totals'); clear(totals);
    var countItems = [['tickets', 'Chamados solucionados'], ['requests', 'Requisições'], ['incidents', 'Incidentes'], ['calls_received', 'Ligações consideradas'], ['calls_answered', 'Ligações atendidas'], ['calls_abandoned', 'Ligações abandonadas · total']];
    [['calls_abandoned_over_30', 'Abandonadas após 30 s · INS9'], ['calls_abandoned_up_to_30', 'Abandonadas em até 30 s'], ['calls_abandoned_unknown_wait', 'Abandonadas sem espera válida']].forEach(function (item) {
      if (Object.prototype.hasOwnProperty.call(snapshot.totals || {}, item[0])) countItems.push(item);
    });
    countItems.push(['calls_excluded', 'Ligações de teste excluídas'], ['sla_scope_count', 'Chamados no recorte de INS1–INS4'], ['sla_scope_evaluated', 'Chamados avaliados em INS1–INS4']);
    if (Number((snapshot.totals || {}).sla_scope_unresolved) > 0) countItems.push(['sla_scope_unresolved', 'Chamados de INS1–INS4 com dados insuficientes']);
    countItems.forEach(function (item) { var tile = node('div', 'g4fr-ses-total'); tile.appendChild(node('strong', '', number((snapshot.totals || {})[item[0]]))); tile.appendChild(node('span', '', item[1])); totals.appendChild(tile); });
    [['mean_talk_seconds', 'Duração média das atendidas'], ['mean_wait_seconds', 'Espera média antes de atender']].forEach(function (item) { var tile = node('div', 'g4fr-ses-total'); tile.appendChild(node('strong', '', seconds((snapshot.totals || {})[item[0]]))); tile.appendChild(node('span', '', item[1])); totals.appendChild(tile); });
    renderSelections(snapshot);
    if (financialEditor) financialEditor.renderPreview(snapshot.financial);
    var warnings = el('ses-warnings'); clear(warnings);
    (snapshot.warnings || []).forEach(function (warning) { warnings.appendChild(node('p', 'g4fr-ses-notice', warning)); });
    var metrics = el('ses-metrics'); clear(metrics);
    (snapshot.metrics || []).forEach(function (metric) {
      var safeStatus = Object.prototype.hasOwnProperty.call(statusNames, metric.status) ? metric.status : 'pending';
      var card = node('article', 'g4fr-ses-metric ' + safeStatus);
      var top = node('div', 'g4fr-ses-metric-top'); top.appendChild(node('strong', '', metric.id)); top.appendChild(node('span', 'g4fr-ses-badge', statusNames[safeStatus])); card.appendChild(top);
      card.appendChild(node('h3', '', metric.label));
      card.appendChild(node('div', 'g4fr-ses-metric-value', metric.value === null || metric.value === undefined ? '—' : percentFormat.format(metric.value) + '%'));
      card.appendChild(node('span', 'g4fr-ses-help', 'Meta: ' + (metric.direction === 'lte' ? '≤ ' : '≥ ') + number(metric.target) + '%'));
      var metricLabels = (config.metric_labels || {})[metric.id] || {};
      card.appendChild(node('span', 'g4fr-ses-help', (metric.numerator_label || metricLabels.numerator || 'Registros que atendem ao critério') + ': ' + number(metric.numerator)));
      card.appendChild(node('span', 'g4fr-ses-help', (metric.denominator_label || metricLabels.denominator || 'Registros avaliados') + ': ' + number(metric.denominator)));
      if (metric.unresolved_count !== undefined) {
        card.appendChild(node('span', 'g4fr-ses-help', 'Chamados no recorte: ' + number(metric.cohort_count)));
        if (Number(metric.unresolved_count) > 0) card.appendChild(node('span', 'g4fr-ses-help', 'Chamados com dados insuficientes: ' + number(metric.unresolved_count)));
        if (metric.coverage_percent !== null && metric.coverage_percent !== undefined) card.appendChild(node('span', 'g4fr-ses-help', 'Cobertura da apuração: ' + percentFormat.format(metric.coverage_percent) + '%'));
      }
      if (metric.id === 'INS12' && metric.source_counts) {
        [['tickets', 'GLPI'], ['phone', 'Telefonia']].forEach(function (item) {
          var source = metric.source_counts[item[0]];
          card.appendChild(node('p', 'g4fr-ses-help', item[1] + ': ' + (source.available ? number(source.numerator) + ' favoráveis / ' + number(source.denominator) + ' válidas' : 'Fonte indisponível') + (source.included ? ' · Incluída' : ' · Excluída por seleção')));
        });
      }
      var details = node('details'); details.appendChild(node('summary', '', 'Como foi calculado')); details.appendChild(node('p', '', metric.method || '')); if (metric.note) details.appendChild(node('p', '', metric.note)); card.appendChild(details); metrics.appendChild(card);
    });
    var phone = el('ses-phone-survey'); clear(phone);
    if (snapshot.phone_survey) {
      var survey = snapshot.phone_survey;
      phone.appendChild(node('h3', '', 'INS12 · Pesquisas de telefonia'));
      phone.appendChild(node('p', 'g4fr-ses-method', survey.status));
      phone.appendChild(node('p', '', 'Ligações únicas consideradas: ' + number(survey.unique_calls) + ' · Respostas vinculadas: ' + number(survey.responses) + ' · Notas válidas (1–5): ' + number(survey.valid_responses) + ' · Favoráveis (notas consideradas 4–5): ' + number(survey.favorable_responses) + ' · Fora da faixa: ' + number(survey.invalid_responses) + ' · Sem resposta: ' + number(survey.without_response)));
      var phoneDetails = node('details', 'g4fr-ses-details');
      phoneDetails.appendChild(node('summary', '', 'Participação no INS12 e acompanhamento'));
      phoneDetails.appendChild(node('p', '', survey.explanation));
      if (survey.resolution && survey.resolution.explanation) phoneDetails.appendChild(node('p', '', survey.resolution.explanation));
      String(survey.actions || '').split('\n').forEach(function (line) { phoneDetails.appendChild(node('p', '', line)); });
      phone.appendChild(phoneDetails);
    }
    var notes = el('ses-rule-notes'); clear(notes);
    (snapshot.rule_notes || []).forEach(function (note) { notes.appendChild(node('div', 'g4fr-ses-rule', note)); });
    renderTable();
  }
  var ticketColumns = [
    ['ID', function (r) { return r.id; }], ['Título', function (r) { return r.title; }], ['Categoria', function (r) { return r.category; }], ['Grupos', function (r) { return r.groups; }], ['Solução', function (r) { return r.solved_at; }], ['Severidade do contrato', function (r) { return ({ 5: 1, 4: 2, 3: 3, 2: 4 })[r.priority] || 'Não mapeada'; }], ['Prioridade GLPI', function (r) { return ({ 1: 'Muito baixa', 2: 'Baixa', 3: 'Média', 4: 'Alta', 5: 'Muito alta', 6: 'Máxima' })[r.priority] || 'Não informada'; }], ['Código GLPI · auditoria', function (r) { return r.priority; }],
    ['Conformidade', function (r) { return r.within_sla === null || r.within_sla === undefined ? 'Apuração pendente' : (r.within_sla ? 'Não excedido' : 'Excedido'); }],
    ['Meta de solução', function (r) { return seconds(r.sla_target_seconds); }], ['Duração usada no indicador', function (r) { return seconds(r.sla_net_seconds); }], ['Espera do calendário · auditoria', function (r) { return seconds(r.sla_pending_seconds); }], ['Calendário · auditoria', function (r) { return r.sla_calendar_name ? r.sla_calendar_id + ' — ' + r.sla_calendar_name : ''; }], ['Justificativa da apuração', function (r) { return r.sla_reason; }],
    ['Prazo gravado · conferência', function (r) { return r.deadline; }], ['Tempo de solução armazenado GLPI', function (r) { return seconds(r.solve_seconds); }], ['Reaberto', function (r) { return bool(r.reopened); }], ['Categoria elegível N1', function (r) { return bool(r.n1_eligible); }], ['Vínculo N3', function (r) { return bool(r.assigned_n3); }], ['Elegível INS6 após exclusões', function (r) { return bool(r.ins6_eligible); }], ['Artigo validado', function (r) { return bool(r.kb_validated); }]
  ];
  var callColumns = [
    ['ID da ligação', function (r) { return r.id; }], ['Entrada na fila', function (r) { return r.entered_at; }], ['Origem', function (r) { return r.caller; }], ['Fila', function (r) { return r.queue; }], ['Tipo', function (r) { return r.type; }], ['Tempo de atendimento', function (r) { return seconds(r.talk_seconds); }], ['Espera antes de atender', function (r) { return seconds(r.wait_seconds); }], ['Espera até abandonar', function (r) { return seconds(r.abandon_wait_seconds); }], ['Encerramento', function (r) { return r.termination; }], ['Pesquisa: atendimento original', function (r) { return r.satisfaction_raw; }], ['Pesquisa: nota considerada (1–5)', function (r) { return r.satisfaction; }], ['Data da pesquisa', function (r) { return r.survey_at; }], ['Nota para INS12', function (r) { return r.ins12_score; }], ['Resposta incluída no INS12', function (r) { return bool(r.ins12_eligible); }],
    ['Pesquisa: nota registrada', function (r) { return r.satisfaction_nota_raw; }], ['Origem da nota considerada', function (r) { return ({ nota: 'nota (sem inversão)', atendimento: 'atendimento (6 − valor)', legacy: 'Nota já convertida na evidência', none: 'Sem nota válida' })[r.satisfaction_source] || ''; }], ['Motivo da escolha da nota', function (r) { return r.satisfaction_reason; }]
  ];
  function renderTable() {
    if (!state.snapshot) return;
    var rows = state.snapshot[state.source] || [];
    var maxPage = Math.max(0, Math.ceil(rows.length / pageSize) - 1); state.page = Math.min(maxPage, Math.max(0, state.page));
    var columns = state.source === 'tickets' ? ticketColumns : callColumns;
    var container = el('ses-source-table'); clear(container);
    if (!rows.length) container.appendChild(node('p', 'g4fr-ses-help', 'Nenhum registro nesta tabela.'));
    else {
      var table = node('table', 'g4fr-ses-table'); var head = node('thead'); var headRow = node('tr'); columns.forEach(function (column) { var cell = node('th', '', column[0]); cell.scope = 'col'; headRow.appendChild(cell); }); head.appendChild(headRow); table.appendChild(head);
      var body = node('tbody'); rows.slice(state.page * pageSize, (state.page + 1) * pageSize).forEach(function (row) { var tr = node('tr'); columns.forEach(function (column) { var value = column[1](row); tr.appendChild(node('td', '', value === null || value === undefined || value === '' ? '—' : value)); }); body.appendChild(tr); }); table.appendChild(body);
      var foot = node('tfoot'); var totalRow = node('tr'); var totalLabel = node('th', '', 'Total geral de registros'); totalLabel.scope = 'row'; totalLabel.colSpan = columns.length - 1; totalRow.appendChild(totalLabel); totalRow.appendChild(node('td', '', number(rows.length))); foot.appendChild(totalRow); table.appendChild(foot); container.appendChild(table);
    }
    el('ses-page-info').textContent = rows.length ? number(state.page * pageSize + 1) + '–' + number(Math.min((state.page + 1) * pageSize, rows.length)) + ' de ' + number(rows.length) : '0 registros';
    el('ses-page-prev').disabled = state.page === 0; el('ses-page-next').disabled = state.page >= maxPage;
  }
  el('ses-page-prev').addEventListener('click', function () { state.page--; renderTable(); });
  el('ses-page-next').addEventListener('click', function () { state.page++; renderTable(); });
  el('ses-source-tabs').addEventListener('click', function (event) { var button = event.target.closest('[data-source]'); if (!button) return; state.source = button.dataset.source; state.page = 0; Array.prototype.forEach.call(el('ses-source-tabs').querySelectorAll('[data-source]'), function (item) { item.classList.toggle('active', item === button); item.setAttribute('aria-pressed', item === button ? 'true' : 'false'); }); renderTable(); });

  var registerPages = { staff: 0, tests: 0 }, registerPageSize = 20;
  var registerUndo = { staff: [], tests: [] };
  var savedRegisters = { staff: cloneRows(staffRows), tests: cloneRows(testNumbers) };
  var nameCollator = new Intl.Collator('pt-BR', { sensitivity: 'base', numeric: true });
  function cloneRows(rows) { return JSON.parse(JSON.stringify(rows)); }
  function registerRows(kind) { return kind === 'staff' ? staffRows : testNumbers; }
  function setRegisterRows(kind, rows) { if (kind === 'staff') staffRows = rows; else testNumbers = rows; }
  function rowKey(kind, row) { return kind === 'staff' ? row.id : String(row.number || '').replace(/\D/g, ''); }
  function searchText(value) { return String(value === null || value === undefined ? '' : value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('pt-BR'); }
  function employmentKey(row) { return [searchText(row.name), row.admission_date || '', searchText(row.profile), searchText(row.record_reference)].join('|'); }
  function rowLabel(kind, row) { return kind === 'staff' ? ((row.name || 'Profissional sem nome') + ' · admissão ' + (row.admission_date || 'não preenchida') + (row.record_reference ? ' · ' + row.record_reference : '')) : ((row.number || 'Número vazio') + (row.label ? ' · ' + row.label : '')); }
  function newStaffId() { var bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes); return Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join(''); }
  function renderRegister(kind) { if (kind === 'staff') renderStaff(); else renderTests(); }
  function changedRegister(kind) { if (kind === 'staff') staffChanged(); else testsChanged(); }
  function dateSearch(value) { var text = String(value || ''), match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text); return text + (match ? ' ' + match[3] + '/' + match[2] + '/' + match[1] : ''); }
  function moneySearch(value) {
    var raw = String(value === null || value === undefined ? '' : value), normalized = raw.replace(/R\$|\s/g, '');
    if (normalized.indexOf(',') >= 0) normalized = normalized.replace(/\./g, '').replace(',', '.');
    var amount = Number(normalized);
    return raw + (normalized && Number.isFinite(amount) ? ' ' + amount.toFixed(2) + ' ' + amount.toFixed(2).replace('.', ',') + ' R$ ' + percentFormat.format(amount) : '');
  }
  function profileMatchKey(value) { return String(value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('pt-BR'); }
  function staffFrontOptions() { return ((config.financial_config || {}).contract_fronts || []); }
  function staffFront(row) {
    var fronts = staffFrontOptions(), explicit = fronts.find(function (front) { return front.id === row.service_front; });
    if (explicit) return { id: explicit.id, label: explicit.label, automatic: false, ambiguous: false };
    if (row.service_front) return { id: '', label: 'Frente não disponível · ' + row.service_front, automatic: false, ambiguous: false };
    var key = profileMatchKey(row.profile), found = fronts.filter(function (front) {
      var profiles = (front.profiles || []).concat((front.profile_lines || []).map(function (line) { return line.profile; }));
      return key && profiles.some(function (profile) { return profileMatchKey(profile) === key; });
    });
    return found.length === 1 ? { id: found[0].id, label: found[0].label, automatic: true, ambiguous: false } : { id: '', label: found.length > 1 ? 'Selecionar frente' : 'Sem frente informada', automatic: true, ambiguous: found.length > 1 };
  }
  function renderStaffFrontCell(row, cell) {
    clear(cell); var resolved = staffFront(row);
    if (!config.can_configure) { cell.textContent = resolved.id ? resolved.label : 'Sem frente informada'; return; }
    var select = node('select', 'form-select'); select.dataset.serviceFront = '1'; select.setAttribute('aria-label', 'Frente de serviço · ' + row.name);
    var automatic = node('option', '', resolved.automatic && resolved.id ? 'Automática · ' + resolved.label : 'Automática pelo perfil'); automatic.value = ''; select.appendChild(automatic);
    staffFrontOptions().forEach(function (front) { var option = node('option', '', front.label); option.value = front.id; select.appendChild(option); });
    if (row.service_front && !staffFrontOptions().some(function (front) { return front.id === row.service_front; })) { var missing = node('option', '', 'Frente não disponível · ' + row.service_front); missing.value = row.service_front; select.appendChild(missing); }
    select.value = row.service_front || '';
    select.addEventListener('change', function () { if (select.value) row.service_front = select.value; else delete row.service_front; staffChanged(); renderStaffFrontCell(row, cell); });
    cell.appendChild(select);
    if (!resolved.id) cell.appendChild(node('small', 'g4fr-ses-help', row.service_front ? 'A frente selecionada não está na composição atual. Selecione uma frente disponível.' : resolved.ambiguous ? 'Este perfil pertence a mais de uma frente. Selecione a frente do profissional.' : 'Associe o perfil ou selecione uma frente.'));
    else if (!resolved.automatic) cell.appendChild(node('small', 'g4fr-ses-help', 'Frente selecionada para este vínculo.'));
  }
  function registerSearchValue(kind, row) {
    if (kind === 'staff') {
      var billing = staffBilling(row);
      return searchText([row.id, row.name, row.profile, row.service_front, staffFront(row).label, row.record_reference, dateSearch(row.admission_date), dateSearch(row.departure_date), moneySearch(row.monthly_value), billing.billed_days, moneySearch(billing.billed_value), billing.status, dateSearch(billing.billing_first_day), dateSearch(billing.billing_last_day), row.departure_date ? 'com saída registrada' : 'sem saída registrada', staffIsNotBilled(row) ? 'Não Faturar Sim marcado excluído true' : 'Não Faturar Não desmarcado incluído false'].join(' '));
    }
    return searchText([row.number, String(row.number || '').replace(/\D/g, ''), row.label, row.active ? 'Sim exclusão habilitada ativo true' : 'Não exclusão desabilitada inativo false'].join(' '));
  }
  function sortedRegister(kind, rows) {
    return rows.slice().sort(function (a, b) { return nameCollator.compare(kind === 'staff' ? a.name : a.number, kind === 'staff' ? b.name : b.number) || nameCollator.compare(rowKey(kind, a), rowKey(kind, b)); });
  }
  function filteredRegister(kind) {
    var query = searchText(el('ses-' + kind + '-search').value), filter = el('ses-' + kind + '-filter').value;
    var billing = kind === 'staff' ? el('ses-staff-billing-filter').value : 'all', profile = kind === 'staff' ? el('ses-staff-profile-filter').value : '';
    return sortedRegister(kind, registerRows(kind).filter(function (row) {
      if (kind === 'staff' && ((filter === 'current' && row.departure_date) || (filter === 'departed' && !row.departure_date))) return false;
      if (kind === 'staff' && ((billing === 'included' && staffIsNotBilled(row)) || (billing === 'excluded' && !staffIsNotBilled(row)))) return false;
      if (kind === 'staff' && profile && searchText(row.profile) !== profile) return false;
      if (kind === 'tests' && ((filter === 'active' && !row.active) || (filter === 'inactive' && row.active))) return false;
      return !query || registerSearchValue(kind, row).indexOf(query) !== -1;
    }));
  }
  function refreshProfileFilter() {
    var select = el('ses-staff-profile-filter'), chosen = select.value, profiles = {};
    staffRows.forEach(function (row) { var key = searchText(row.profile); if (key && !Object.prototype.hasOwnProperty.call(profiles, key)) profiles[key] = row.profile.trim(); });
    clear(select); var all = node('option', '', 'Todos os perfis'); all.value = ''; select.appendChild(all);
    Object.keys(profiles).sort(nameCollator.compare).forEach(function (key) { var option = node('option', '', profiles[key]); option.value = key; select.appendChild(option); });
    if (chosen && !Object.prototype.hasOwnProperty.call(profiles, chosen)) { var removed = node('option', '', chosen + ' · sem registros'); removed.value = chosen; select.appendChild(removed); }
    select.value = chosen;
  }
  function clearRegisterFilters(kind) {
    el('ses-' + kind + '-search').value = ''; el('ses-' + kind + '-filter').value = 'all';
    if (kind === 'staff') { el('ses-staff-billing-filter').value = 'all'; el('ses-staff-profile-filter').value = ''; }
    registerPages[kind] = 0;
  }
  function visibleRegister(kind) {
    var rows = filteredRegister(kind);
    var pages = Math.max(1, Math.ceil(rows.length / registerPageSize));
    registerPages[kind] = Math.max(0, Math.min(registerPages[kind], pages - 1));
    var start = registerPages[kind] * registerPageSize;
    el('ses-' + kind + '-page').textContent = (rows.length ? number(start + 1) + '–' + number(Math.min(start + registerPageSize, rows.length)) : '0') + ' de ' + number(rows.length) + ' localizado(s) · ' + number(registerRows(kind).length) + ' no cadastro';
    el('ses-' + kind + '-prev').dataset.pageBound = registerPages[kind] === 0 ? '1' : '0';
    el('ses-' + kind + '-next').dataset.pageBound = registerPages[kind] >= pages - 1 ? '1' : '0';
    el('ses-' + kind + '-prev').disabled = state.busy || registerPages[kind] === 0;
    el('ses-' + kind + '-next').disabled = state.busy || registerPages[kind] >= pages - 1;
    return rows.slice(start, start + registerPageSize);
  }
  function updateRegisterChanges(kind) {
    var current = registerRows(kind), removed = savedRegisters[kind].filter(function (old) { return !current.some(function (row) { return rowKey(kind, row) === rowKey(kind, old); }); });
    el('ses-' + kind + '-changes').textContent = removed.length ? 'Remoções a confirmar ao salvar: ' + removed.map(function (row) { return rowLabel(kind, row); }).join('; ') : 'Busca, ordem e páginas alteram apenas a visualização. Todos os registros são preservados ao salvar.';
    if (el('ses-' + kind + '-undo')) el('ses-' + kind + '-undo').hidden = !registerUndo[kind].length;
  }
  function removeRegisterRow(kind, row) {
    var message = 'Remover ' + rowLabel(kind, row) + '?\n\n' + (kind === 'staff' ? 'Para encerrar um vínculo, prefira preencher a data de saída. Remover retira esta entrada das próximas extrações, inclusive de meses anteriores.\n\n' : '') + 'A remoção será confirmada ao salvar. Você poderá desfazer agora ou recuperar pelo histórico após salvar.';
    if (!window.confirm(message)) return;
    registerUndo[kind].push(cloneRows([row])[0]);
    setRegisterRows(kind, registerRows(kind).filter(function (item) { return item !== row; }));
    changedRegister(kind); renderRegister(kind);
  }
  function mergeRestoredRow(kind, old) {
    var rows = registerRows(kind), key = rowKey(kind, old), index = rows.findIndex(function (row) { return rowKey(kind, row) === key; });
    if (index >= 0 && !window.confirm('Já existe uma entrada para ' + rowLabel(kind, old) + '. Substituir somente esta entrada pelos valores da versão selecionada?')) return false;
    var copy = cloneRows([old])[0];
    if (index >= 0) rows[index] = copy; else rows.push(copy);
    changedRegister(kind); clearRegisterFilters(kind); renderRegister(kind);
    status('Registro restaurado na tela. Confira os valores e salve o cadastro para confirmar.', 'success');
    return true;
  }
  function testsChanged() { state.testsDirty = true; var label = el('ses-tests-state'); if (label) label.textContent = 'Há alterações não salvas. Salve a lista antes de gerar o relatório mensal. A exportação desta tabela usa os valores atuais.'; updateRegisterChanges('tests'); updateControls(); }
  function renderTests() {
    var body = el('ses-tests-body'); clear(body);
    visibleRegister('tests').forEach(function (row) {
      var tr = node('tr'); var numberCell = node('td'); var labelCell = node('td'); var activeCell = node('td');
      if (config.can_configure) {
        var numberInput = node('input', 'form-control'); numberInput.type = 'text'; numberInput.inputMode = 'tel'; numberInput.maxLength = 40; numberInput.value = row.number; numberInput.setAttribute('aria-label', 'Número de origem'); numberInput.addEventListener('input', function () { row.number = numberInput.value; testsChanged(); }); numberCell.appendChild(numberInput);
        var labelInput = node('input', 'form-control'); labelInput.type = 'text'; labelInput.maxLength = 200; labelInput.value = row.label; labelInput.setAttribute('aria-label', 'Identificação do número'); labelInput.addEventListener('input', function () { row.label = labelInput.value; testsChanged(); }); labelCell.appendChild(labelInput);
        var checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.checked = row.active; checkbox.setAttribute('aria-label', 'Excluir o número ' + row.number); checkbox.addEventListener('change', function () { row.active = checkbox.checked; testsChanged(); }); activeCell.appendChild(checkbox);
        tr.appendChild(numberCell); tr.appendChild(labelCell); tr.appendChild(activeCell);
        var actionCell = node('td'); var remove = node('button', 'btn btn-outline-danger btn-sm', 'Remover'); remove.type = 'button'; remove.addEventListener('click', function () { removeRegisterRow('tests', row); }); actionCell.appendChild(remove); tr.appendChild(actionCell);
      } else { numberCell.textContent = row.number; labelCell.textContent = row.label || '—'; activeCell.textContent = row.active ? 'Sim' : 'Não'; tr.appendChild(numberCell); tr.appendChild(labelCell); tr.appendChild(activeCell); }
      body.appendChild(tr);
    });
    el('ses-tests-empty').hidden = testNumbers.length > 0;
    el('ses-test-count').textContent = '· ' + number(testNumbers.length) + ' cadastrado(s)';
    updateRegisterChanges('tests');
  }
  async function exportRegister(kind) {
    if (state.busy) return;
    var scope = el('ses-' + kind + '-export-scope').value === 'filtered' ? 'filtered' : 'all';
    var rows = scope === 'all' ? sortedRegister(kind, registerRows(kind)) : filteredRegister(kind);
    var button = el('ses-' + kind + '-export'), feedback = el('ses-' + kind + '-export-state');
    var body = new URLSearchParams(); body.set('kind', kind); body.set('rows', JSON.stringify(rows)); body.set('scope', scope); body.set('_ses_nonce', config.nonce);
    if (kind === 'staff') body.set('month', form.elements.month.value);
    state.busy = true; updateControls(); button.textContent = 'Preparando tabela…'; feedback.textContent = 'Preparando ' + number(rows.length) + ' registro(s)…';
    try {
      await postWithCsrf(config.register_export_url, body, async function (response) {
        var nextToken = response.headers.get('X-G4F-CSRF-Token'); if (nextToken) config.csrf_token = nextToken;
        var type = response.headers.get('Content-Type') || '';
        if (!response.ok || /json|text\/html/i.test(type)) {
          var failure = await response.text(), detail;
          try { detail = JSON.parse(failure); } catch (error) { detail = {}; }
          if (detail.csrf_token) config.csrf_token = detail.csrf_token;
          throw new Error(detail.error || 'Não foi possível exportar a tabela (HTTP ' + response.status + '). Os valores continuam nesta tela.');
        }
        var blob = await response.blob(); if (!blob.size) throw new Error('O arquivo retornado está vazio.');
        var filename = kind === 'staff' ? 'Cadastro_Profissionais.xlsx' : 'Numeros_Desconsiderados.xlsx';
        var disposition = response.headers.get('Content-Disposition') || '', match = /filename="?([A-Za-z0-9_.-]+\.xlsx)"?(?:;|$)/i.exec(disposition); if (match) filename = match[1];
        var objectUrl = URL.createObjectURL(blob), link = node('a'); link.href = objectUrl; link.download = filename; document.body.appendChild(link); link.click(); link.remove(); setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
        feedback.textContent = number(rows.length) + ' registro(s) exportado(s). O cadastro não foi alterado.';
      });
    } catch (error) {
      feedback.textContent = error.name === 'AbortError' ? 'A exportação não terminou em 30 segundos. Seus valores continuam nesta tela; tente novamente.' : (error.message || 'Não foi possível exportar a tabela.');
    } finally { state.busy = false; button.textContent = exportRegisterLabel(kind); updateControls(); }
  }
  function exportRegisterLabel(kind) {
    return el('ses-' + kind + '-export-scope').value === 'filtered' ? 'Exportar filtrados (.xlsx)' : 'Exportar todos (.xlsx)';
  }
  function importDisplay(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Sim' : 'Não';
    if (typeof value === 'object') return Object.keys(value).sort().map(function (month) { return month + ': ' + value[month]; }).join('; ') || 'Automático';
    return String(value);
  }
  function showImportPreview(kind, result, baseRows, baseRevision) {
    var target = el('ses-' + kind + '-import-preview'); clear(target); target.hidden = false;
    var summary = result.summary;
    target.appendChild(node('p', '', 'Prévia: ' + number(summary.added) + ' inclusão(ões), ' + number(summary.updated) + ' atualização(ões), ' + number(summary.unchanged) + ' sem alteração e ' + number(summary.duplicates) + ' repetição(ões) idêntica(s) ignorada(s).'));
    target.appendChild(node('p', '', number(result.retained) + ' registro(s) existente(s) ausente(s) do arquivo serão mantidos. Nenhum registro será removido. Confira os valores antes de aplicar à tabela.'));
    if (result.month) target.appendChild(node('p', '', 'Competência dos dias informados na tabela principal: ' + result.month + '. Os outros meses vêm da aba Dias por competência; meses ausentes conservam o valor atual.'));
    (result.changes || []).forEach(function (change) {
      var details = node('details', 'g4fr-ses-history-entry');
      details.appendChild(node('summary', '', (change.action === 'added' ? 'Incluir: ' : 'Atualizar: ') + change.label + ' · ' + change.key));
      var wrap = node('div', 'g4fr-ses-table-wrap'), table = node('table', 'g4fr-ses-table'), head = node('thead'), titles = node('tr'), body = node('tbody');
      ['Campo', 'Atual', 'Importado'].forEach(function (label) { titles.appendChild(node('th', '', label)); }); head.appendChild(titles); table.appendChild(head);
      (change.fields || []).forEach(function (field) { var row = node('tr'); [field.field, importDisplay(field.before), importDisplay(field.after)].forEach(function (value) { row.appendChild(node('td', '', value)); }); body.appendChild(row); });
      table.appendChild(body); wrap.appendChild(table); details.appendChild(wrap); target.appendChild(details);
    });
    var actions = node('div', 'g4fr-ses-actions'), apply = node('button', 'btn btn-primary', 'Aplicar à tabela'), cancel = node('button', 'btn btn-outline-secondary', 'Cancelar importação');
    apply.type = cancel.type = 'button';
    apply.addEventListener('click', function () {
      if (state.busy) return;
      if (JSON.stringify(registerRows(kind)) !== baseRows || (kind === 'staff' ? config.staff_revision : config.test_revision) !== baseRevision) {
        clear(target); target.hidden = true; status('A tabela mudou após a prévia. Importe o arquivo novamente para comparar com os valores atuais.', 'error'); return;
      }
      if (summary.added || summary.updated) {
        setRegisterRows(kind, cloneRows(result.rows)); clearRegisterFilters(kind); changedRegister(kind); renderRegister(kind);
        status('Importação aplicada à tabela. Confira os registros e clique em Salvar para confirmar. Nenhum cadastro foi salvo automaticamente.', 'success');
      } else status('O arquivo não contém alterações. O cadastro permanece igual.', 'success');
      clear(target); target.hidden = true;
    });
    cancel.addEventListener('click', function () { if (!state.busy) { clear(target); target.hidden = true; } });
    actions.appendChild(apply); actions.appendChild(cancel); target.appendChild(actions);
    target.scrollIntoView({ block: 'nearest' });
  }
  async function importRegister(kind, file) {
    if (state.busy || !config.can_configure || !file) return;
    var feedback = el('ses-' + kind + '-export-state'), target = el('ses-' + kind + '-import-preview');
    clear(target); target.hidden = true;
    if (!/\.xlsx$/i.test(file.name) || !file.size || file.size > 2097152) { feedback.textContent = 'Selecione um arquivo .xlsx de até 2 MB exportado por esta tabela.'; return; }
    var baseRows = JSON.stringify(registerRows(kind)), baseRevision = kind === 'staff' ? config.staff_revision : config.test_revision;
    var body = new FormData(); body.set('kind', kind); body.set('rows', baseRows); body.set('revision', baseRevision || ''); body.set('workbook', file); body.set('_ses_nonce', config.nonce);
    state.busy = true; updateControls(); feedback.textContent = 'Validando o arquivo e comparando os registros…';
    try {
      var result = await postWithCsrf(config.register_import_url, body, async function (response) {
        var text = await response.text(), result;
        try { result = JSON.parse(text); } catch (error) { throw new Error('O servidor não confirmou a análise (HTTP ' + response.status + '). Nenhum dado foi alterado.'); }
        if (result.csrf_token) config.csrf_token = result.csrf_token;
        if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível importar o arquivo.');
        if (!Array.isArray(result.rows) || !result.summary || !Array.isArray(result.changes)) throw new Error('A prévia retornada está incompleta. Nenhum dado foi alterado.');
        return result;
      });
      showImportPreview(kind, result, baseRows, baseRevision); feedback.textContent = 'Arquivo validado. Revise a prévia antes de aplicar à tabela.';
    } catch (error) {
      feedback.textContent = error.name === 'AbortError' ? 'A análise não terminou em 30 segundos. Nenhum dado foi alterado; tente novamente.' : (error.message || 'Não foi possível analisar o arquivo.');
    } finally { state.busy = false; updateControls(); }
  }
  async function registerRequest(action, kind, payload) {
    var body = new URLSearchParams();
    body.set('action', action); body.set('kind', kind); body.set('_ses_nonce', config.nonce);
    Object.keys(payload || {}).forEach(function (key) { body.set(key, payload[key]); });
    try {
      return await postWithCsrf(config.registers_url, body, async function (response) {
        var text = await response.text(), result;
        try { result = JSON.parse(text); } catch (error) { throw new Error('O GLPI não confirmou a operação (HTTP ' + response.status + '). Suas alterações continuam nesta tela.'); }
        if (result.csrf_token) config.csrf_token = result.csrf_token;
        if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível concluir a operação.');
        return result;
      });
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('O servidor não confirmou a operação em 30 segundos. Confira a versão salva antes de tentar salvar novamente.');
      throw error;
    }
  }
  async function saveRegister(kind, rows, revision) {
    var result = await registerRequest(kind === 'staff' ? 'save_staff' : 'save_tests', kind, { rows: JSON.stringify(rows), revision: revision || '' });
    if (!Array.isArray(result.rows) || !result.revision) throw new Error('A confirmação do cadastro está incompleta. Confira a versão salva antes de tentar novamente.');
    savedRegisters[kind] = cloneRows(result.rows); registerUndo[kind] = [];
    renderHistory(kind, result.history || []);
    return result;
  }
  function historyDate(value) { var date = new Date(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleString('pt-BR'); }
  function renderHistory(kind, entries) {
    var target = el('ses-' + kind + '-history-body'); if (!target) return; clear(target);
    if (!entries.length) { target.appendChild(node('p', 'g4fr-ses-help', 'O histórico começa no primeiro salvamento com alterações nesta versão do plugin.')); return; }
    entries.forEach(function (entry) {
      var details = node('details', 'g4fr-ses-history-entry');
      details.appendChild(node('summary', '', historyDate(entry.saved_at) + ' · ' + (entry.user_name || 'Usuário') + ' (ID ' + entry.user_id + ') · ' + entry.added + ' inclusão(ões), ' + entry.updated + ' alteração(ões), ' + entry.removed + ' remoção(ões)'));
      var body = node('div'); details.appendChild(body);
      details.addEventListener('toggle', async function () {
        if (!details.open || details.dataset.loaded || details.dataset.loading) return;
        details.dataset.loading = '1'; body.textContent = 'Carregando a versão anterior a esse salvamento…';
        try {
          var result = await registerRequest('history_detail', kind, { history_id: entry.id }); clear(body);
          body.appendChild(node('p', 'g4fr-ses-help', 'Valores anteriores a esse salvamento. A restauração altera somente o registro escolhido na tela; salve depois de conferir.'));
          var query = node('input', 'form-control'); query.type = 'search'; query.placeholder = 'Localizar registro nesta versão'; query.setAttribute('aria-label', 'Buscar na versão anterior'); body.appendChild(query);
          var list = node('div', 'g4fr-ses-history-records'); body.appendChild(list);
          function showRows() {
            clear(list);
            var found = result.rows.filter(function (row) { return !query.value || registerSearchValue(kind, row).indexOf(searchText(query.value)) !== -1; });
            found.forEach(function (row) {
              var item = node('div', 'g4fr-ses-history-record');
              var description = rowLabel(kind, row) + (kind === 'staff' ? ' · ' + row.profile + ' · saída: ' + (row.departure_date || 'não registrada') + ' · Não Faturar: ' + (staffIsNotBilled(row) ? 'sim' : 'não') + ' · R$ ' + row.monthly_value : ' · exclusão: ' + (row.active ? 'habilitada' : 'desabilitada'));
              item.appendChild(node('span', '', description));
              var restore = node('button', 'btn btn-outline-secondary btn-sm', 'Restaurar esta entrada'); restore.type = 'button';
              restore.addEventListener('click', function () { if (!state.busy) mergeRestoredRow(kind, row); }); item.appendChild(restore); list.appendChild(item);
            });
            if (!found.length) list.appendChild(node('p', 'g4fr-ses-help', 'Nenhum registro localizado.'));
          }
          query.addEventListener('input', showRows); showRows(); details.dataset.loaded = '1';
        } catch (error) { body.textContent = error.message; }
        finally { delete details.dataset.loading; }
      });
      target.appendChild(details);
    });
  }
  async function loadHistory(kind) {
    var target = el('ses-' + kind + '-history-body');
    try { renderHistory(kind, (await registerRequest('history', kind, {})).history || []); }
    catch (error) { target.textContent = error.message; }
  }
  ['staff', 'tests'].forEach(function (kind) {
    (kind === 'staff' ? ['search', 'filter', 'billing-filter', 'profile-filter'] : ['search', 'filter']).forEach(function (field) { el('ses-' + kind + '-' + field).addEventListener(field === 'search' ? 'input' : 'change', function () { registerPages[kind] = 0; renderRegister(kind); }); });
    el('ses-' + kind + '-clear-filters').addEventListener('click', function () { clearRegisterFilters(kind); renderRegister(kind); });
    el('ses-' + kind + '-export').addEventListener('click', function () { exportRegister(kind); });
    el('ses-' + kind + '-export-scope').addEventListener('change', function () { el('ses-' + kind + '-export').textContent = exportRegisterLabel(kind); });
    if (config.can_configure && el('ses-' + kind + '-import')) {
      el('ses-' + kind + '-import').addEventListener('click', function () { if (!state.busy) el('ses-' + kind + '-import-file').click(); });
      el('ses-' + kind + '-import-file').addEventListener('change', function () { var file = this.files[0]; this.value = ''; importRegister(kind, file); });
    }
    el('ses-' + kind + '-prev').addEventListener('click', function () { registerPages[kind]--; renderRegister(kind); });
    el('ses-' + kind + '-next').addEventListener('click', function () { registerPages[kind]++; renderRegister(kind); });
    if (config.can_configure) {
      el('ses-' + kind + '-undo').addEventListener('click', function () { var row = registerUndo[kind][registerUndo[kind].length - 1]; if (row && mergeRestoredRow(kind, row)) { registerUndo[kind].pop(); updateRegisterChanges(kind); } });
      el('ses-' + kind + '-history-refresh').addEventListener('click', function () { loadHistory(kind); });
      el('ses-' + kind + '-history').addEventListener('toggle', function () { if (this.open && !this.dataset.loaded) { this.dataset.loaded = '1'; loadHistory(kind); } });
    }
  });
  if (config.can_configure && el('g4fr-ses-tests-form')) {
    el('ses-add-test').addEventListener('click', function () { if (testNumbers.length >= 500) { status('O cadastro aceita até 500 números.', 'error'); return; } testNumbers.push({ number: '', label: '', active: true }); clearRegisterFilters('tests'); testsChanged(); renderTests(); var first = el('ses-tests-body').firstElementChild; if (first) first.querySelector('input').focus(); });
    el('g4fr-ses-tests-form').addEventListener('submit', async function (event) {
      event.preventDefault(); if (state.busy) return;
      var normalized;
      try {
        var seen = {}; normalized = testNumbers.map(function (row) { if (/[^0-9+().\-\s]/.test(row.number)) throw new Error('Use somente dígitos e formatação de telefone no número de origem.'); var digits = row.number.replace(/\D/g, ''); if (!digits || digits.length > 32) throw new Error('Preencha o número de todas as linhas com até 32 dígitos ou remova as linhas vazias.'); if (seen[digits]) throw new Error('O número ' + digits + ' está repetido na lista.'); if (new TextEncoder().encode(row.label.trim()).length > 240) throw new Error('Reduza a identificação do número ' + digits + '.'); seen[digits] = true; return { number: digits, label: row.label.trim(), active: !!row.active }; });
      } catch (error) { status(error.message, 'error'); el('ses-status').scrollIntoView({ block: 'nearest' }); return; }
      state.busy = true; updateControls(); status('Salvando os números de teste no cadastro permanente…', '', true);
      try {
        var saved = await saveRegister('tests', normalized, config.test_revision); testNumbers = saved.rows; config.test_revision = saved.revision;
        state.testsDirty = false; state.dataDirty = true; renderTests();
        el('ses-tests-state').textContent = 'Lista salva no plugin. Ela será carregada ao reabrir esta página e aplicada aos próximos relatórios, de qualquer mês.';
        status('Números de teste salvos e conferidos. Gere novamente para aplicar a lista ao relatório.', 'success');
      } catch (error) { state.testsDirty = true; status(error.message || 'Não foi possível salvar os números de teste.', 'error'); }
      finally { state.busy = false; updateControls(); }
    });
  }
  function staffChanged() { state.staffDirty = true; var label = el('ses-staff-state'); if (label) label.textContent = 'Há alterações não salvas na equipe. Salve o cadastro antes de gerar o relatório mensal. A exportação desta tabela usa os valores atuais.'; updateRegisterChanges('staff'); updateDuplicateNotice(); refreshProfileFilter(); updateControls(); }
  function duplicateGroups(rows) { var groups = {}; rows.forEach(function (row) { if (!row.name || !row.admission_date || !row.profile) return; var key = employmentKey(row); (groups[key] || (groups[key] = [])).push(row); }); return groups; }
  function updateDuplicateNotice() {
    var target = el('ses-staff-duplicates'); clear(target);
    var groups = duplicateGroups(staffRows), duplicates = Object.keys(groups).filter(function (key) { return groups[key].length > 1; });
    if (duplicates.length) { target.appendChild(node('p', '', 'Vínculos repetidos para conferir; nenhuma entrada foi removida automaticamente:')); duplicates.forEach(function (key) { target.appendChild(node('p', '', rowLabel('staff', groups[key][0]) + ' · ' + groups[key].length + ' entradas')); }); }
    var likely = {}; staffRows.forEach(function (row) { if (!row.name || !row.admission_date || row.record_reference) return; var key = searchText(row.name) + '|' + row.admission_date; (likely[key] || (likely[key] = [])).push(row); });
    Object.keys(likely).forEach(function (key) { var rows = likely[key]; if (rows.length > 1 && new Set(rows.map(employmentKey)).size > 1) target.appendChild(node('p', '', 'Confira os vínculos de ' + rows[0].name + ' com admissão em ' + rows[0].admission_date + '. Perfis diferentes podem representar a mesma pessoa. Se forem vínculos distintos, use a identificação do vínculo.')); });
    target.hidden = !target.childNodes.length;
  }
  function validateStaffRows() {
    staffRows.forEach(function (row) {
      Object.keys(row.billing_days_overrides || {}).forEach(function (month) {
        var value = row.billing_days_overrides[month], billing = window.G4FStaffBilling.calculate(row, month);
        if (!/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/.test(month) || !/^\d+$/.test(String(value)) || billing.automatic_billed_days === null || billing.automatic_billed_days === undefined || Number(value) > Number(billing.automatic_billed_days)) throw new Error('Confira os dias faturados de ' + rowLabel('staff', row) + ' em ' + month + '. O ajuste deve ficar entre 0 e os dias do vínculo nesse mês.');
      });
    });
    var saved = duplicateGroups(savedRegisters.staff), groups = duplicateGroups(staffRows);
    Object.keys(groups).forEach(function (key) { if (groups[key].length < 2) return; var priorIds = (saved[key] || []).map(function (row) { return row.id; }); if (groups[key].some(function (row) { return priorIds.indexOf(row.id) < 0; })) throw new Error('Vínculo duplicado: ' + rowLabel('staff', groups[key][0]) + '. Confira a entrada existente ou informe uma identificação diferente para vínculos realmente distintos.'); });
  }
  function staffBilling(row) { return window.G4FStaffBilling.calculate(row, form.elements.month.value); }
  function billingMoney(value) { return value === null || value === undefined ? '—' : 'R$ ' + percentFormat.format(Number(value)); }
  function billingCell(tr, key) { var cell = node('td', 'g4fr-ses-billing-value'); cell.dataset.billing = key; tr.appendChild(cell); return cell; }
  function updateStaffBillingRow(row, tr) {
    var billing = staffBilling(row);
    Array.prototype.forEach.call(tr.querySelectorAll('[data-billing]'), function (cell) {
      var key = cell.dataset.billing;
      cell.textContent = key === 'billed_value' ? billingMoney(billing[key]) : key === 'billed_days' ? number(billing[key]) : billing[key];
      cell.title = key === 'status' ? 'Status do cadastro: uma data de saída registrada indica Inativo. Não altera a apuração histórica.' : (billing.billing_first_day ? 'Período faturado: ' + billing.billing_first_day + ' a ' + billing.billing_last_day : billing.billed_days === null ? 'Preencha um mês e datas válidos para calcular.' : 'Sem dias faturados no mês selecionado.');
    });
    var dayInput = tr.querySelector('[data-billing-override]'), helper = tr.querySelector('[data-billing-auto]');
    if (dayInput) {
      var automatic = billing.automatic_billed_days;
      if (automatic !== null && automatic !== undefined) dayInput.max = String(automatic); else dayInput.removeAttribute('max');
      dayInput.placeholder = automatic === null || automatic === undefined ? 'Automático' : String(automatic);
      var override = row.billing_days_overrides && row.billing_days_overrides[dayInput.dataset.billingOverride];
      var valid = override === undefined || (Number.isInteger(Number(override)) && Number(override) >= 0 && automatic !== null && automatic !== undefined && Number(override) <= Number(automatic));
      dayInput.setCustomValidity(valid ? '' : 'Informe dias inteiros de 0 até ' + (automatic === null || automatic === undefined ? 'o período válido do vínculo' : automatic) + ', ou deixe vazio para usar o automático.');
      if (helper) helper.textContent = 'Automático: ' + number(automatic) + ' · Faturado: ' + number(billing.billed_days) + (staffIsNotBilled(row) ? ' · Não Faturar marcado' : '');
    }
  }
  function updateStaffBillingMonth() {
    var month = form.elements.month.value, parts = /^(20\d{2}|2100)-(0[1-9]|1[0-2])$/.exec(month);
    var label = parts ? new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(Number(parts[1]), Number(parts[2]) - 1, 1))) : 'selecione um mês válido';
    el('ses-staff-billing-month').textContent = 'Faturamento calculado para: ' + label + '. Ajustes de dias valem apenas para este mês e são preservados ao trocar de mês ou página.';
  }
  function renderStaff() {
    var body = el('ses-staff-body'); clear(body); refreshProfileFilter(); updateStaffBillingMonth();
    var displayOrder = sortedRegister('staff', staffRows);
    var displayedMonth = form.elements.month.value;
    visibleRegister('staff').forEach(function (row) {
      var tr = node('tr'); tr.dataset.staffId = row.id;
      tr.appendChild(node('td', '', displayOrder.indexOf(row) + 1));
      if (!config.can_configure) {
        [staffIsNotBilled(row) ? 'Sim' : 'Não', row.name + (row.record_reference ? ' · ' + row.record_reference : ''), row.profile].forEach(function (value) { tr.appendChild(node('td', '', value)); });
        var readOnlyFront = node('td'); renderStaffFrontCell(row, readOnlyFront); tr.appendChild(readOnlyFront);
        [row.admission_date, row.departure_date || '—'].forEach(function (value) { tr.appendChild(node('td', '', value)); });
        billingCell(tr, 'billed_days'); tr.appendChild(node('td', '', billingMoney(row.monthly_value))); billingCell(tr, 'billed_value'); billingCell(tr, 'status');
      } else {
        var billedCell = node('td'), notBilled = node('input'); notBilled.type = 'checkbox'; notBilled.checked = staffIsNotBilled(row);
        notBilled.setAttribute('aria-label', 'Não Faturar · ' + row.name); notBilled.addEventListener('change', function () { row.not_billed = notBilled.checked; staffChanged(); updateStaffCount(); updateStaffBillingRow(row, tr); }); billedCell.appendChild(notBilled); tr.appendChild(billedCell);
        function field(key, type, label, required, max) {
          var td = node('td'), input = node('input', 'form-control'); input.type = type; input.value = row[key] === null || row[key] === undefined ? '' : row[key]; input.required = !!required; if (max) input.maxLength = max;
          input.setAttribute('aria-label', label + ' · ' + row.name); if (type === 'date') { input.min = '1900-01-01'; input.max = '2100-12-31'; }
          if (key === 'monthly_value') { input.inputMode = 'decimal'; input.placeholder = '0,00'; }
          input.addEventListener('input', function () { row[key] = input.value; staffChanged(); if (key === 'profile' && frontCell) renderStaffFrontCell(row, frontCell); if (key === 'admission_date' || key === 'departure_date' || key === 'monthly_value') { updateStaffBillingRow(row, tr); updateStaffCount(); } }); td.appendChild(input); tr.appendChild(td); return { cell: td, input: input };
        }
        var name = field('name', 'text', 'Nome', true, 180);
        var reference = node('input', 'form-control'); reference.type = 'text'; reference.value = row.record_reference || ''; reference.maxLength = 100; reference.placeholder = 'Identificação do vínculo (opcional)'; reference.setAttribute('aria-label', 'Identificação para homônimos ou vínculos distintos · ' + row.name); reference.addEventListener('input', function () { row.record_reference = reference.value; staffChanged(); }); name.cell.appendChild(reference);
        field('profile', 'text', 'Perfil profissional', true, 240);
        var frontCell = node('td'); renderStaffFrontCell(row, frontCell); tr.appendChild(frontCell);
        field('admission_date', 'date', 'Data de admissão', true);
        field('departure_date', 'date', 'Data de saída', false);
        var daysCell = node('td', 'g4fr-ses-days-editor'), daysInput = node('input', 'form-control');
        daysInput.type = 'number'; daysInput.min = '0'; daysInput.step = '1'; daysInput.inputMode = 'numeric';
        daysInput.dataset.billingOverride = displayedMonth;
        daysInput.dataset.monthBound = /^(20\d{2}|2100)-(0[1-9]|1[0-2])$/.test(displayedMonth) ? '0' : '1';
        daysInput.value = row.billing_days_overrides && Object.prototype.hasOwnProperty.call(row.billing_days_overrides, displayedMonth) ? row.billing_days_overrides[displayedMonth] : '';
        daysInput.setAttribute('aria-label', 'Dias Faturado em ' + displayedMonth + ' · ' + row.name);
        daysInput.addEventListener('input', function () {
          if (!row.billing_days_overrides || Array.isArray(row.billing_days_overrides)) row.billing_days_overrides = {};
          if (daysInput.value === '') delete row.billing_days_overrides[displayedMonth];
          else row.billing_days_overrides[displayedMonth] = daysInput.value;
          staffChanged(); updateStaffBillingRow(row, tr); updateStaffCount();
        });
        daysCell.appendChild(daysInput);
        var automatic = node('button', 'btn btn-outline-secondary btn-sm', 'Automático'); automatic.type = 'button';
        automatic.dataset.monthBound = daysInput.dataset.monthBound;
        automatic.setAttribute('aria-label', 'Restaurar dias automáticos em ' + displayedMonth + ' · ' + row.name);
        automatic.addEventListener('click', function () { if (row.billing_days_overrides) delete row.billing_days_overrides[displayedMonth]; daysInput.value = ''; staffChanged(); updateStaffBillingRow(row, tr); updateStaffCount(); });
        daysCell.appendChild(automatic); var daysHelp = node('small', 'g4fr-ses-help'); daysHelp.dataset.billingAuto = '1'; daysCell.appendChild(daysHelp); tr.appendChild(daysCell);
        field('monthly_value', 'text', 'Valor mensal em reais', true, 24); billingCell(tr, 'billed_value'); billingCell(tr, 'status');
        var action = node('td'), remove = node('button', 'btn btn-outline-danger btn-sm', 'Remover'); remove.type = 'button'; remove.addEventListener('click', function () { removeRegisterRow('staff', row); }); action.appendChild(remove); tr.appendChild(action);
      }
      updateStaffBillingRow(row, tr);
      body.appendChild(tr);
    });
    el('ses-staff-empty').hidden = staffRows.length > 0; updateStaffCount(); updateRegisterChanges('staff'); updateDuplicateNotice();
  }
  function updateStaffCount() { if (el('ses-staff-count')) el('ses-staff-count').textContent = number(staffRows.length) + ' cadastrado(s) · ' + number(staffRows.filter(function (row) { return !staffIsNotBilled(row); }).length) + ' não excluído(s) · ' + number(staffRows.filter(staffIsNotBilled).length) + ' excluído(s) por não faturamento · ' + number(staffRows.filter(function (row) { return Number(staffBilling(row).billed_days) > 0; }).length) + ' com dias faturados no mês selecionado'; }
  if (config.can_configure && el('g4fr-ses-staff-form')) {
    el('ses-add-staff').addEventListener('click', function () { if (staffRows.length >= 500) { status('O cadastro aceita até 500 profissionais.', 'error'); return; } staffRows.push({ id: newStaffId(), not_billed: false, name: '', profile: '', record_reference: '', admission_date: '', departure_date: '', monthly_value: '', departure_reason: 'pending', billing_days_overrides: {} }); clearRegisterFilters('staff'); staffChanged(); renderStaff(); var first = el('ses-staff-body').firstElementChild; if (first) first.querySelector('input[type="text"]').focus(); });
    el('g4fr-ses-staff-form').addEventListener('submit', async function (event) {
      event.preventDefault(); if (state.busy || !this.reportValidity()) return;
      try { validateStaffRows(); } catch (error) { status(error.message, 'error'); el('ses-status').scrollIntoView({ block: 'nearest' }); return; }
      state.busy = true; updateControls(); status('Salvando os profissionais no cadastro permanente…', '', true);
      try {
        var saved = await saveRegister('staff', staffRows, config.staff_revision); staffRows = saved.rows; config.staff_revision = saved.revision;
        state.staffDirty = false; state.dataDirty = true; renderStaff();
        el('ses-staff-state').textContent = 'Profissionais salvos no plugin. O cadastro será carregado automaticamente nos próximos relatórios. Gere novamente para atualizar o INS10.';
        status('Profissionais salvos e conferidos. Gere novamente para calcular o INS10 com este cadastro.', 'success');
      } catch (error) { state.staffDirty = true; status(error.message || 'Não foi possível salvar os profissionais.', 'error'); }
      finally { state.busy = false; updateControls(); }
    });
  }
  window.addEventListener('beforeunload', function (event) { if (state.testsDirty || state.staffDirty || state.financialDirty) { event.preventDefault(); event.returnValue = ''; } });
  financialEditor = window.G4FSesFinancial.create({
    config: config, month: function () { return form.elements.month.value; },
    postWithCsrf: postWithCsrf,
    isBusy: function () { return state.busy; },
    setBusy: function (value) { state.busy = value; updateControls(); },
    onDirty: function (value) { state.financialDirty = value; updateControls(); },
    onSaved: function () { state.dataDirty = true; renderStaff(); updateControls(); }, status: status
  });
  renderTests(); renderStaff(); updateControls();
})();
