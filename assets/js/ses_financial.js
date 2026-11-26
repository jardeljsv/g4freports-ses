(function (root) {
  'use strict';
  // Month-specific edits stay in this backing object when the selected month changes.
  // Rendering and filtering never decide which records are sent to the server.
  function create(options) {
    var config = options.config, canEdit = !!config.can_configure;
    var data = editableData(config.financial_config || {}), dirty = false;
    var money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    data.contract_fronts = Array.isArray(data.contract_fronts) ? data.contract_fronts : [];
    data.months = object(data.months);
    function el(id) { return document.getElementById(id); }
    function node(tag, className, text) { var item = document.createElement(tag); if (className) item.className = className; if (text !== undefined) item.textContent = String(text); return item; }
    function clear(item) { while (item.firstChild) item.removeChild(item.firstChild); }
    function copy(value) { return JSON.parse(JSON.stringify(value)); }
    function editableData(value) {
      var result = copy(value);
      (result.contract_fronts || []).forEach(function (front) {
        var lineNames = (front.profile_lines || []).map(function (line) { return String(line.profile || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('pt-BR'); });
        front.profiles = (front.profiles || []).filter(function (profile) { return lineNames.indexOf(String(profile).trim().replace(/\s+/g, ' ').toLocaleLowerCase('pt-BR')) === -1; });
      });
      return result;
    }
    function object(value) { return value && typeof value === 'object' && !Array.isArray(value) ? value : {}; }
    function validMonth(value) { return /^(20\d{2}|2100)-(0[1-9]|1[0-2])$/.test(value); }
    function currentMonth() { return options.month(); }
    function monthData(month) {
      if (!data.months[month]) data.months[month] = { deduction_overrides: {}, adjustments: [] };
      var result = data.months[month];
      result.deduction_overrides = object(result.deduction_overrides);
      result.adjustments = Array.isArray(result.adjustments) ? result.adjustments : [];
      return result;
    }
    function markDirty() {
      dirty = true;
      if (el('ses-financial-state')) el('ses-financial-state').textContent = 'Há alterações não salvas. A troca de mês preserva os ajustes em edição. Salve antes de gerar o relatório.';
      options.onDirty(true);
    }
    function makeField(row, key, label, type, changed) {
      var td = node('td');
      if (!canEdit) { td.textContent = Array.isArray(row[key]) ? row[key].join('; ') : String(row[key] === undefined ? '' : row[key]); return td; }
      var input = node(type === 'textarea' ? 'textarea' : 'input', 'form-control');
      if (type !== 'textarea') input.type = type || 'text'; else input.rows = 2;
      input.value = Array.isArray(row[key]) ? row[key].join('; ') : (row[key] === null || row[key] === undefined ? '' : row[key]);
      input.setAttribute('aria-label', label);
      input.addEventListener('input', function () { changed(input.value, input); markDirty(); });
      td.appendChild(input); return td;
    }
    function removeCell(action, label) {
      var td = node('td'), button = node('button', 'btn btn-outline-danger btn-sm', 'Remover'); button.type = 'button';
      button.setAttribute('aria-label', label); button.addEventListener('click', action); td.appendChild(button); return td;
    }
    function amountInCents(value) {
      var text = String(value === undefined || value === null ? '' : value).replace(/R\$|\s/g, '');
      if (text.indexOf(',') >= 0) text = text.replace(/\./g, '').replace(',', '.');
      if (!/^\d+(?:\.\d{1,2})?$/.test(text)) return null;
      var parts = text.split('.');
      return Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0'));
    }
    function frontTotals(front) {
      var lines = Array.isArray(front.profile_lines) && front.profile_lines.length ? front.profile_lines : [front];
      var quantity = 0, cents = 0, valid = true;
      lines.forEach(function (line) {
        var rate = amountInCents(line.monthly_rate), count = Number(line.contract_quantity);
        if (!/^\d+$/.test(String(line.contract_quantity)) || count > 10000 || rate === null) valid = false;
        else { quantity += count; cents += count * rate; }
      });
      return valid ? quantity + ' profissional(is) · ' + money.format(cents / 100) : 'Preencha as quantidades e os valores';
    }
    function renderFronts() {
      var body = el('ses-financial-fronts'); clear(body);
      data.contract_fronts.forEach(function (front) {
        var tr = node('tr'), linesCell = node('td', 'g4fr-ses-front-composition');
        var label = makeField(front, 'label', 'Frente de serviço', 'text', function (value) { front.label = value; });
        if (canEdit) { label.firstChild.maxLength = 180; label.firstChild.required = true; }
        tr.appendChild(label);
        var summary = node('p', 'g4fr-ses-front-subtotal', frontTotals(front));
        function refreshTotal() { summary.textContent = frontTotals(front); }
        var profileLines = Array.isArray(front.profile_lines) && front.profile_lines.length > 0;
        if (profileLines) {
          var table = node('table', 'g4fr-ses-table g4fr-ses-profile-lines'), head = node('thead'), header = node('tr'), linesBody = node('tbody');
          ['Perfil profissional', 'Quantidade', 'Valor unitário', 'Valor mensal'].concat(canEdit ? ['Ação'] : []).forEach(function (title) { header.appendChild(node('th', '', title)); });
          head.appendChild(header); table.appendChild(head); table.appendChild(linesBody);
          front.profile_lines.forEach(function (line) {
            var lineRow = node('tr'), lineTotal = node('td', 'g4fr-ses-billing-value');
            function updateLineTotal() { var rate = amountInCents(line.monthly_rate); lineTotal.textContent = rate !== null && /^\d+$/.test(String(line.contract_quantity)) ? money.format(rate * Number(line.contract_quantity) / 100) : '—'; refreshTotal(); }
            var profile = makeField(line, 'profile', 'Perfil contratual em ' + front.label, 'text', function (value) { line.profile = value; });
            var quantity = makeField(line, 'contract_quantity', 'Quantidade do perfil em ' + front.label, 'number', function (value) { line.contract_quantity = value; updateLineTotal(); });
            var rate = makeField(line, 'monthly_rate', 'Valor unitário do perfil em ' + front.label, 'text', function (value) { line.monthly_rate = value; updateLineTotal(); });
            if (canEdit) {
              profile.firstChild.required = true; profile.firstChild.maxLength = 240;
              quantity.firstChild.min = '0'; quantity.firstChild.max = '10000'; quantity.firstChild.step = '1'; quantity.firstChild.required = true;
              rate.firstChild.inputMode = 'decimal'; rate.firstChild.required = true; rate.firstChild.placeholder = '0,00';
            }
            lineRow.appendChild(profile); lineRow.appendChild(quantity); lineRow.appendChild(rate); lineRow.appendChild(lineTotal); updateLineTotal();
            if (canEdit) lineRow.appendChild(removeCell(function () {
              if (front.profile_lines.length === 1) { options.status('Mantenha ao menos um perfil na frente, ou remova a frente inteira.', 'error'); return; }
              if (!window.confirm('Remover o perfil “' + (line.profile || 'Sem nome') + '” da frente “' + front.label + '”?')) return;
              front.profile_lines = front.profile_lines.filter(function (item) { return item !== line; }); markDirty(); renderFronts();
            }, 'Remover perfil ' + (line.profile || 'Sem nome')));
            linesBody.appendChild(lineRow);
          });
          linesCell.appendChild(table);
          if (canEdit) {
            var addLine = node('button', 'btn btn-outline-secondary btn-sm', 'Adicionar perfil'); addLine.type = 'button';
            addLine.setAttribute('aria-label', 'Adicionar perfil em ' + front.label);
            addLine.addEventListener('click', function () { front.profile_lines.push({ profile: '', contract_quantity: 0, monthly_rate: '0.00' }); markDirty(); renderFronts(); }); linesCell.appendChild(addLine);
          }
        } else {
          // Keep saved aggregate fronts intact until the user chooses to detail them.
          var legacyTable = node('table', 'g4fr-ses-table g4fr-ses-legacy-front'), legacyHead = node('thead'), legacyHeaders = node('tr'), legacyBody = node('tbody'), legacyRow = node('tr');
          ['Quantidade contratual', 'Valor mensal unitário'].forEach(function (title) { legacyHeaders.appendChild(node('th', '', title)); }); legacyHead.appendChild(legacyHeaders); legacyTable.appendChild(legacyHead);
          var quantity = makeField(front, 'contract_quantity', 'Quantidade contratual em ' + front.label, 'number', function (value) { front.contract_quantity = value; refreshTotal(); });
          var rate = makeField(front, 'monthly_rate', 'Valor mensal unitário da frente ' + front.label, 'text', function (value) { front.monthly_rate = value; refreshTotal(); });
          if (canEdit) { quantity.firstChild.min = '0'; quantity.firstChild.max = '10000'; quantity.firstChild.step = '1'; quantity.firstChild.required = true; rate.firstChild.inputMode = 'decimal'; rate.firstChild.required = true; }
          legacyRow.appendChild(quantity); legacyRow.appendChild(rate); legacyBody.appendChild(legacyRow); legacyTable.appendChild(legacyBody); linesCell.appendChild(legacyTable);
          if (canEdit) {
            var detail = node('button', 'btn btn-outline-secondary btn-sm', 'Detalhar por perfil'); detail.type = 'button';
            detail.addEventListener('click', function () { front.profile_lines = [{ profile: front.profiles && front.profiles.length === 1 ? front.profiles[0] : '', contract_quantity: front.contract_quantity, monthly_rate: front.monthly_rate }]; markDirty(); renderFronts(); }); linesCell.appendChild(detail);
          }
        }
        var aliasesLabel = node('div', 'g4fr-ses-help', profileLines ? 'Outros nomes de perfil usados no cadastro (separados por ponto e vírgula)' : 'Perfis associados (separados por ponto e vírgula)');
        var aliases = makeField(front, 'profiles', 'Outros nomes de perfil em ' + front.label, 'textarea', function (value) { front.profiles = value.split(';').map(function (part) { return part.trim(); }).filter(Boolean); });
        var aliasesBox = node('div', 'g4fr-ses-front-aliases'); aliasesBox.appendChild(aliasesLabel);
        if (canEdit) aliasesBox.appendChild(aliases.firstChild); else aliasesBox.appendChild(node('span', '', aliases.textContent));
        linesCell.appendChild(aliasesBox); linesCell.appendChild(summary); tr.appendChild(linesCell);
        if (canEdit) tr.appendChild(removeCell(function () {
          if (!window.confirm('Remover a frente “' + (front.label || 'Sem nome') + '”? A alteração será confirmada ao salvar.')) return;
          data.contract_fronts = data.contract_fronts.filter(function (item) { return item !== front; }); markDirty(); renderFronts();
        }, 'Remover frente ' + (front.label || 'Sem nome')));
        body.appendChild(tr);
      });
      el('ses-financial-fronts-empty').hidden = data.contract_fronts.length > 0;
      var total = el('ses-contract-total');
      if (total) { if (canEdit) total.value = data.contract_total || ''; else total.textContent = data.contract_total ? money.format(Number(data.contract_total)) : 'Soma das frentes'; }
      setBusy(options.isBusy());
    }
    function renderMonth() {
      var month = currentMonth(), valid = validMonth(month);
      el('ses-financial-month').textContent = valid ? 'Ajustes financeiros de ' + month.slice(5) + '/' + month.slice(0, 4) + '. Os demais meses são preservados.' : 'Selecione um mês válido para consultar ou editar seus ajustes.';
      var deductions = el('ses-financial-deductions'), adjustments = el('ses-financial-adjustments'); clear(deductions); clear(adjustments);
      if (!valid) { el('ses-financial-adjustments-empty').hidden = false; setBusy(options.isBusy()); return; }
      var entry = monthData(month);
      for (var index = 1; index <= 12; index++) {
        (function (id) {
          var tr = node('tr'), override = entry.deduction_overrides[id] || { rate: '', justification: '' };
          tr.appendChild(node('td', '', id));
          var rate = makeField(override, 'rate', id + ' · taxa aplicada em ' + month, 'number', function (value) { override.rate = value; entry.deduction_overrides[id] = override; updateJustification(); });
          var justification = makeField(override, 'justification', id + ' · justificativa do ajuste em ' + month, 'textarea', function (value) { override.justification = value; entry.deduction_overrides[id] = override; });
          function updateJustification() { if (canEdit) justification.firstChild.required = override.rate !== '' && override.rate !== null && override.rate !== undefined; }
          if (canEdit) {
            rate.firstChild.min = '0'; rate.firstChild.max = '4'; rate.firstChild.step = '0.01'; rate.firstChild.placeholder = 'Automática';
            justification.firstChild.maxLength = 2000; justification.firstChild.placeholder = 'Preencha somente para um ajuste aprovado'; updateJustification();
          } else if (override.rate === '' || override.rate === null || override.rate === undefined) rate.textContent = 'Automática';
          tr.appendChild(rate); tr.appendChild(justification); deductions.appendChild(tr);
        })('INS' + index);
      }
      entry.adjustments.forEach(function (adjustment) {
        var tr = node('tr');
        var description = makeField(adjustment, 'description', 'Descrição do ajuste em ' + month, 'textarea', function (value) { adjustment.description = value; });
        var amount = makeField(adjustment, 'amount', 'Valor do ajuste em ' + month, 'text', function (value) { adjustment.amount = value; });
        if (canEdit) { description.firstChild.required = true; description.firstChild.maxLength = 1000; amount.firstChild.required = true; amount.firstChild.inputMode = 'decimal'; amount.firstChild.placeholder = 'Ex.: -150,00 ou 150,00'; }
        tr.appendChild(description); tr.appendChild(amount);
        if (canEdit) tr.appendChild(removeCell(function () { entry.adjustments = entry.adjustments.filter(function (item) { return item !== adjustment; }); markDirty(); renderMonth(); }, 'Remover ajuste ' + (adjustment.description || 'sem descrição')));
        adjustments.appendChild(tr);
      });
      el('ses-financial-adjustments-empty').hidden = entry.adjustments.length > 0;
      setBusy(options.isBusy());
    }
    function preparedData() {
      var result = copy(data), cleaned = {};
      result.contract_total = result.contract_total === undefined || result.contract_total === null || String(result.contract_total).trim() === '' ? null : String(result.contract_total).trim();
      if (result.contract_total !== null && amountInCents(result.contract_total) === null) throw new Error('Informe um total contratual mensal válido.');
      result.contract_fronts.forEach(function (front) {
        if (!String(front.label || '').trim()) throw new Error('Informe o nome de cada frente de serviço.');
        var lines = Array.isArray(front.profile_lines) && front.profile_lines.length ? front.profile_lines : [front];
        lines.forEach(function (line) {
          if (line !== front && !String(line.profile || '').trim()) throw new Error('Informe o nome de todos os perfis em ' + front.label + '.');
          if (!/^\d+$/.test(String(line.contract_quantity)) || Number(line.contract_quantity) > 10000) throw new Error('Informe uma quantidade contratual inteira de 0 a 10000 para ' + (line.profile || front.label) + '.');
          if (amountInCents(line.monthly_rate) === null) throw new Error('Informe um valor unitário válido para ' + (line.profile || front.label) + '.');
          line.contract_quantity = Number(line.contract_quantity);
        });
      });
      Object.keys(result.months).forEach(function (month) {
        var entry = result.months[month], overrides = {};
        Object.keys(entry.deduction_overrides || {}).forEach(function (id) {
          var override = entry.deduction_overrides[id];
          if (override.rate === '' || override.rate === null || override.rate === undefined) return;
          var rate = Number(override.rate);
          if (!Number.isFinite(rate) || rate < 0 || rate > 4) throw new Error('A taxa de ' + id + ' em ' + month + ' deve estar entre 0% e 4%.');
          if (!String(override.justification || '').trim()) throw new Error('Informe a justificativa do ajuste aprovado de ' + id + ' em ' + month + '.');
          overrides[id] = { rate: rate, justification: String(override.justification).trim() };
        });
        (entry.adjustments || []).forEach(function (adjustment) {
          if (!String(adjustment.description || '').trim() || !String(adjustment.amount === undefined ? '' : adjustment.amount).trim()) throw new Error('Preencha a descrição e o valor de todos os ajustes de ' + month + ', ou remova a linha vazia.');
        });
        if (Object.keys(overrides).length || (entry.adjustments || []).length) cleaned[month] = { deduction_overrides: overrides, adjustments: entry.adjustments || [] };
      });
      result.months = cleaned;
      return result;
    }
    async function save(event) {
      event.preventDefault();
      if (options.isBusy() || !event.currentTarget.reportValidity()) return;
      var payload;
      try { payload = preparedData(); } catch (error) { options.status(error.message, 'error'); return; }
      var body = new URLSearchParams(); body.set('action', 'save'); body.set('config', JSON.stringify(payload)); body.set('revision', config.financial_revision || ''); body.set('_ses_nonce', config.nonce);
      options.setBusy(true); options.status('Salvando a composição contratual e os ajustes de todos os meses…', '', true);
      try {
        var result = await options.postWithCsrf(config.financial_url, body, async function (response) {
          var text = await response.text(), result;
          try { result = JSON.parse(text); } catch (error) { throw new Error('O GLPI não confirmou o salvamento (HTTP ' + response.status + '). Seus ajustes continuam nesta tela.'); }
          if (result.csrf_token) config.csrf_token = result.csrf_token;
          if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível salvar os ajustes.');
          if (!result.config || !result.revision) throw new Error('A confirmação está incompleta. Confira a configuração salva antes de tentar novamente.');
          return result;
        });
        data = editableData(result.config); data.contract_fronts = data.contract_fronts || []; data.months = object(data.months);
        config.financial_config = copy(result.config); config.financial_revision = result.revision; dirty = false;
        options.onDirty(false); options.onSaved(); renderFronts(); renderMonth();
        el('ses-financial-state').textContent = 'Composição e ajustes salvos. Gere novamente para aplicá-los aos relatórios.';
        options.status('Composição contratual e ajustes financeiros salvos e conferidos.', 'success');
      } catch (error) {
        options.status(error.name === 'AbortError' ? 'O servidor não confirmou o salvamento em 30 segundos. Seus ajustes continuam nesta tela; confira a configuração salva antes de tentar novamente.' : error.message, 'error');
      } finally { options.setBusy(false); }
    }
    function setBusy(busy) {
      var form = el('g4fr-ses-financial-form'); if (!form) return;
      Array.prototype.forEach.call(form.querySelectorAll('input:not([type="hidden"]),textarea,button'), function (input) { input.disabled = busy || (!validMonth(currentMonth()) && input.id === 'ses-add-adjustment'); });
      el('ses-save-financial').textContent = busy ? 'Aguarde…' : 'Salvar composição e ajustes';
    }
    function renderPreview(financial) {
      var target = el('ses-financial-preview'); if (!target) return; clear(target);
      if (!financial) return;
      target.appendChild(node('h3', 'g4fr-ses-financial-preview-title', financial.complete === false ? 'Resumo financeiro · valores parciais' : 'Resumo financeiro'));
      if (financial.complete === false) target.appendChild(node('p', 'g4fr-ses-notice', 'O resumo financeiro está incompleto. Os valores abaixo são parciais; confira as observações antes de utilizar o total para faturamento.'));
      var totals = node('div', 'g4fr-ses-totals');
      [['gross_value', 'Faturamento bruto'], ['deduction_total', 'Descontos dos indicadores'], ['adjustment_total', 'Outros ajustes'], ['net_value', financial.complete === false ? 'Saldo parcial' : 'Valor líquido']].forEach(function (item) {
        var tile = node('div', 'g4fr-ses-total'), value = financial[item[0]];
        tile.appendChild(node('strong', '', value === null || value === undefined ? '—' : money.format(Number(value)))); tile.appendChild(node('span', '', item[1])); totals.appendChild(tile);
      });
      target.appendChild(totals);
      if (financial.applied_rate_total !== undefined) target.appendChild(node('p', 'g4fr-ses-help', 'Desconto apurado: ' + financial.calculated_rate_total + '% · Desconto aplicado: ' + financial.applied_rate_total + '%. Ajustes financeiros não alteram os resultados dos indicadores.'));
      (financial.notes || []).forEach(function (note) { target.appendChild(node('p', 'g4fr-ses-help', note)); });
    }
    if (canEdit) {
      if (el('ses-contract-total')) el('ses-contract-total').addEventListener('input', function () { data.contract_total = this.value; markDirty(); });
      if (el('ses-use-contract-baseline')) el('ses-use-contract-baseline').addEventListener('click', function () {
        if (!config.financial_baseline) return;
        if (!window.confirm('Substituir as frentes e o total contratual pela referência SES? Os ajustes mensais e o cadastro de profissionais serão preservados. Salve para confirmar.')) return;
        data.contract_fronts = editableData(config.financial_baseline).contract_fronts || []; data.contract_total = config.financial_baseline.contract_total === undefined ? null : config.financial_baseline.contract_total;
        markDirty(); renderFronts();
      });
      el('ses-add-front').addEventListener('click', function () {
        var bytes = new Uint8Array(12); window.crypto.getRandomValues(bytes);
        data.contract_fronts.push({ id: Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join(''), label: '', profiles: [], contract_quantity: 0, monthly_rate: '0.00', profile_lines: [{ profile: '', contract_quantity: 0, monthly_rate: '0.00' }] }); markDirty(); renderFronts();
      });
      el('ses-add-adjustment').addEventListener('click', function () { var month = currentMonth(); if (!validMonth(month)) return; monthData(month).adjustments.push({ description: '', amount: '' }); markDirty(); renderMonth(); });
      el('g4fr-ses-financial-form').addEventListener('submit', save);
    }
    renderFronts(); renderMonth();
    return { renderMonth: renderMonth, renderPreview: renderPreview, setBusy: setBusy, isDirty: function () { return dirty; } };
  }
  root.G4FSesFinancial = { create: create };
})(window);
