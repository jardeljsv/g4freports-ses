<?php

require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;
use GlpiPlugin\G4freports\Ses\Settings;
use GlpiPlugin\G4freports\Ses\ArchiveAccess;
use GlpiPlugin\G4freports\Ses\StaffRegister;
use GlpiPlugin\G4freports\Ses\MetricEvidence;
use GlpiPlugin\G4freports\Ses\PhoneSurveySummary;
use GlpiPlugin\G4freports\Ses\FinanceSettings;

if (class_exists('Session')) {
    Session::checkLoginUser();
}
$cfg = Config::merged();
if (!Security::canUse($cfg)) {
    if (class_exists('Html')) { Html::displayRightError(); }
    http_response_code(403);
    exit;
}
$canConfigure = Security::canConfigure();
$archiveSharing = ArchiveAccess::status($cfg);
$initial = Settings::normalizeInput(['month' => date('Y-m', strtotime('first day of last month'))], $cfg);
$testNumbers = Settings::testNumbers($cfg['ses_test_numbers_json'] ?? '[]');
$staffRows = StaffRegister::fromSettings($cfg);
$testRevision = StaffRegister::revision($testNumbers);
$staffRevision = StaffRegister::revision($staffRows);
$financialConfig = FinanceSettings::fromSettings($cfg);
$financialRevision = FinanceSettings::revision($financialConfig);
$metricLabels = [];
for ($i = 1; $i <= 12; $i++) { $metricLabels['INS' . $i] = MetricEvidence::labels('INS' . $i); }
$root = rtrim((string)($GLOBALS['CFG_GLPI']['root_doc'] ?? ''), '/');
$front = $root . '/plugins/g4freports/front/';
$configUrl = $root . '/front/config.form.php';
$csrf = (class_exists('Session') && method_exists('Session', 'getNewCSRFToken')) ? Session::getNewCSRFToken() : '';
if (empty($_SESSION['g4freports_ses_nonce'])) {
    $_SESSION['g4freports_ses_nonce'] = bin2hex(random_bytes(24));
}
$nonce = (string)$_SESSION['g4freports_ses_nonce'];
$h = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$field = static function (string $name) use ($initial, $h): string {
    $value = $initial[$name] ?? '';
    return $h(is_array($value) ? implode(', ', $value) : $value);
};
$registerControls = static function (string $kind) use ($canConfigure): void {
    $staff = $kind === 'staff';
    ?>
    <div class="g4fr-ses-register-tools">
      <div><label for="ses-<?php echo $kind; ?>-search">Buscar em todos os campos</label><input type="search" class="form-control" id="ses-<?php echo $kind; ?>-search" placeholder="<?php echo $staff ? 'Nome, perfil, frente, data, dias, valor, status ou identificação' : 'Número, identificação ou situação da exclusão'; ?>"></div>
      <div><label for="ses-<?php echo $kind; ?>-filter">Mostrar</label><select class="form-select" id="ses-<?php echo $kind; ?>-filter"><option value="all">Todos os registros</option><?php if ($staff): ?><option value="current">Sem saída registrada</option><option value="departed">Com saída registrada</option><?php else: ?><option value="active">Exclusão habilitada</option><option value="inactive">Exclusão desabilitada</option><?php endif; ?></select></div>
      <?php if ($staff): ?><div><label for="ses-staff-billing-filter">Não Faturar</label><select class="form-select" id="ses-staff-billing-filter"><option value="all">Todas as marcações</option><option value="included">Desmarcado · participa do INS10</option><option value="excluded">Marcado · excluído do INS10</option></select></div><div><label for="ses-staff-profile-filter">Perfil profissional</label><select class="form-select" id="ses-staff-profile-filter"><option value="">Todos os perfis</option></select></div><?php endif; ?>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="ses-<?php echo $kind; ?>-clear-filters">Limpar filtros</button>
      <?php if ($canConfigure): ?><button type="button" class="btn btn-outline-secondary btn-sm" id="ses-<?php echo $kind; ?>-undo" hidden>Desfazer última remoção</button><?php endif; ?>
    </div>
    <div class="g4fr-ses-register-export"><div><label for="ses-<?php echo $kind; ?>-export-scope">Exportar</label><select class="form-select" id="ses-<?php echo $kind; ?>-export-scope"><option value="all">Todos os registros · todas as páginas</option><option value="filtered">Somente registros filtrados</option></select></div><button type="button" class="btn btn-outline-secondary" id="ses-<?php echo $kind; ?>-export">Exportar todos (.xlsx)</button><?php if ($canConfigure): ?><button type="button" class="btn btn-outline-secondary" id="ses-<?php echo $kind; ?>-import">Importar tabela (.xlsx)</button><input type="file" accept=".xlsx" id="ses-<?php echo $kind; ?>-import-file" hidden><?php endif; ?></div>
    <p class="g4fr-ses-help" id="ses-<?php echo $kind; ?>-export-state" role="status" aria-live="polite"></p>
    <p class="g4fr-ses-help">A busca considera todos os campos e todas as páginas. A exportação usa os valores atuais da tabela, inclusive alterações ainda não salvas, e não altera o cadastro.</p>
    <?php if ($canConfigure): ?><p class="g4fr-ses-help">Importe o mesmo formato exportado por esta versão. A prévia combina os registros sem remover os ausentes do arquivo. Confira as alterações e use Salvar para confirmar. Nos profissionais, edite Dias informados; os valores calculados são apenas informativos. A aba Dias por competência conserva os ajustes dos demais meses. IDs e telefones devem permanecer como texto.</p><div id="ses-<?php echo $kind; ?>-import-preview" class="g4fr-ses-notice" hidden aria-live="polite"></div><?php endif; ?>
    <p class="g4fr-ses-help" id="ses-<?php echo $kind; ?>-changes" role="status"></p>
    <?php if ($staff): ?><div id="ses-staff-duplicates" class="g4fr-ses-notice" hidden></div><?php endif;
};
$registerFooter = static function (string $kind) use ($canConfigure): void {
    ?>
    <div class="g4fr-ses-pager g4fr-ses-register-pager"><button type="button" class="btn btn-outline-secondary btn-sm" id="ses-<?php echo $kind; ?>-prev">Anterior</button><span id="ses-<?php echo $kind; ?>-page"></span><button type="button" class="btn btn-outline-secondary btn-sm" id="ses-<?php echo $kind; ?>-next">Próxima</button></div>
    <?php if ($canConfigure): ?><details class="g4fr-ses-details" id="ses-<?php echo $kind; ?>-history"><summary>Histórico e recuperação de registros</summary><p class="g4fr-ses-help">São preservadas as versões anteriores aos 10 últimos salvamentos que alteraram este cadastro, com data e responsável. Restaure apenas os registros necessários e salve para confirmar.</p><button type="button" class="btn btn-outline-secondary btn-sm" id="ses-<?php echo $kind; ?>-history-refresh">Atualizar histórico</button><div id="ses-<?php echo $kind; ?>-history-body"></div></details><?php endif;
};
$configured = [];
foreach (['ses' => 'GLPI / MariaDB', 'callcenter' => 'Telefonia / MySQL'] as $source => $label) {
    $prefix = $source . '_db_';
    $ready = (int)($cfg[$prefix . 'enabled'] ?? 0) === 1;
    foreach (['host', 'name', 'user'] as $part) { $ready = $ready && trim((string)($cfg[$prefix . $part] ?? '')) !== ''; }
    $configured[$source] = ['label' => $label, 'ready' => $ready];
}
if (class_exists('Html')) { Html::header('Relatório mensal SES — G4F', $_SERVER['PHP_SELF'], 'tools', 'PluginG4freportsMenu'); }
?>
<style><?php readfile(dirname(__DIR__) . '/assets/css/ses.css'); ?></style>
<div class="g4fr-ses" id="g4fr-ses">
  <header class="g4fr-ses-header">
    <div><span class="g4fr-ses-eyebrow">G4F · CENTRAL DE SERVIÇOS · v<?php echo $h(PLUGIN_G4FREPORTS_VERSION); ?></span><h1>Relatório mensal SES</h1><p>Indicadores de serviço, chamados e telefonia no mesmo relatório.</p></div>
    <nav aria-label="Áreas do plugin"><a class="btn btn-outline-secondary" href="<?php echo $h($front . 'report.php'); ?>">Relatórios por profissional</a><?php if ($canConfigure): ?> <a class="btn btn-outline-secondary" href="<?php echo $h($configUrl); ?>">Configurar conexões</a><?php endif; ?></nav>
  </header>
  <div class="g4fr-ses-connections" aria-label="Configuração das fontes">
    <?php foreach ($configured as $connection): ?><span class="g4fr-ses-source <?php echo $connection['ready'] ? 'configured' : 'missing'; ?>"><?php echo $h($connection['label']); ?>: <strong><?php echo $connection['ready'] ? 'configurada' : 'configuração pendente'; ?></strong></span><?php endforeach; ?>
    <span class="g4fr-ses-help">As fontes são consultadas ao gerar o relatório. O teste de acesso fica na configuração.</span>
  </div>
  <?php if (!$configured['ses']['ready'] || !$configured['callcenter']['ready']): ?><p class="g4fr-ses-notice">Configure e habilite as duas conexões em <strong>Configurar → Geral → Relatórios G4F</strong> antes de gerar. Esta área usa as conexões de banco dedicadas ao relatório SES.</p><?php endif; ?>
  <noscript><p class="g4fr-ses-notice error">Ative o JavaScript para gerar o relatório e administrar os cadastros de números de teste e profissionais.</p></noscript>

  <form id="g4fr-ses-form">
    <section class="g4fr-ses-card">
      <h2>1. Período e abrangência</h2>
      <div class="g4fr-ses-grid">
        <div class="g4fr-ses-field"><label for="ses-month">Mês de referência</label><input class="form-control" id="ses-month" name="month" type="month" value="<?php echo $field('month'); ?>" required><small>Chamados pela data de solução. Ligações pela entrada na fila.</small></div>
        <div class="g4fr-ses-field"><label for="ses-entity">ID da entidade GLPI</label><input class="form-control" id="ses-entity" name="entity_id" type="number" min="0" step="1" value="<?php echo $field('entity_id'); ?>" required><small>Somente a entidade informada; não soma subentidades.</small></div>
        <div class="g4fr-ses-field"><label for="ses-groups">IDs dos grupos atribuídos</label><input class="form-control" id="ses-groups" name="group_ids" value="<?php echo $field('group_ids'); ?>" placeholder="Opcional — deixe vazio para todos"><small>Filtra a população inteira do relatório pela atribuição atual. Deixe vazio para incluir todos os grupos; selecionar somente N1 aqui também retiraria chamados N2 do INS4. Os critérios N1 e N2 são definidos abaixo.</small></div>
        <div class="g4fr-ses-field"><label for="ses-queue">Identificador da fila de telefonia</label><input class="form-control" id="ses-queue" name="queue_filter" maxlength="100" value="<?php echo $field('queue_filter'); ?>" required><small>Inclui filas cujo nome contém este texto, como ssdf.</small></div>
      </div>
      <p class="g4fr-ses-method">O mês inclui do primeiro dia às 00:00 até o início do mês seguinte, sem incluir esse instante. Chamados criados antes do mês entram se foram solucionados nele. Esse critério constará nos arquivos exportados.</p>
      <div class="g4fr-ses-criteria" aria-label="Critérios INS11 e INS12"><h3>Como calcular os indicadores</h3><div class="g4fr-ses-grid">
            <div class="g4fr-ses-field"><label for="ses-kb">INS11 · Base de conhecimento</label><select class="form-select" id="ses-kb" name="kb_mode"><option value="validated_links" <?php echo ($initial['kb_mode'] ?? '') === 'validated_links' ? 'selected' : ''; ?>>Artigo vinculado ao chamado e validado</option><option value="category_proxy" <?php echo ($initial['kb_mode'] ?? '') === 'category_proxy' ? 'selected' : ''; ?>>Categoria com base de conhecimento</option></select><small>O relatório informa o critério selecionado e apresenta seu resultado e a meta normalmente.</small></div>
            <div class="g4fr-ses-field"><label for="ses-ins12">INS12 · Fontes da pesquisa de satisfação</label><select class="form-select" id="ses-ins12" name="ins12_mode"><option value="glpi_and_phone" <?php echo ($initial['ins12_mode'] ?? '') === 'glpi_and_phone' ? 'selected' : ''; ?>>Chamados GLPI e pesquisas de telefonia — padrão</option><option value="glpi_only" <?php echo ($initial['ins12_mode'] ?? '') === 'glpi_only' ? 'selected' : ''; ?>>Somente chamados GLPI</option></select><small>O padrão soma respostas favoráveis e respostas válidas das duas fontes, com o mesmo peso por resposta. Telefonia: uma resposta por ligação, após excluir números de teste. <?php echo $h(PhoneSurveySummary::scoreRuleText()); ?> Notas consideradas 4 e 5 são favoráveis. Alterar esta seleção exige gerar novamente.</small></div>
      </div></div>
      <div class="g4fr-ses-field"><label for="ses-priority-map">Associação dos indicadores INS1 a INS4</label><select class="form-select" id="ses-priority-map" name="priority_preset"><option value="contract" selected>Ordem do contrato · INS1 Muito alta, INS2 Alta, INS3 Média, INS4 Baixa — padrão</option><option value="panel">Painel anterior · INS1 Baixa, INS2 Média, INS3 Alta, INS4 Muito alta</option></select><small id="ses-priority-description">Códigos GLPI: INS1 = 5; INS2 = 4; INS3 = 3; INS4 = 2. INS4 considera chamados de prioridade baixa dos grupos N1 e N2, sem vínculo N3.</small></div>
      <details class="g4fr-ses-details">
        <summary>Critérios dos indicadores</summary>
        <div class="g4fr-ses-detail-body">
          <p class="g4fr-ses-help">A associação inicial segue a ordem do contrato e do relatório mensal validado. A ordem do painel anterior continua disponível na seleção acima. A apuração compara a duração consolidada no GLPI com a meta da prioridade. Quando essa duração não é positiva, utiliza o tempo entre abertura e solução descontando a espera geral, se o resultado for positivo; caso contrário, utiliza o tempo entre abertura e solução. A auditoria conserva as informações de prazo e calendário.</p>
          <div class="g4fr-ses-grid">
            <div class="g4fr-ses-field g4fr-ses-full"><label for="ses-n1-categories">IDs das categorias elegíveis N1</label><textarea class="form-control" id="ses-n1-categories" name="n1_categories" rows="3"><?php echo $field('n1_categories'); ?></textarea><small>Lista inicial das consultas fornecidas, incluindo Instalação de Navegador (162), Outro problema com o computador (149) e Criar nova pasta em servidor ou rede (176). Separe os IDs por vírgula; os nomes da base serão conferidos na extração.</small></div>
            <div class="g4fr-ses-field"><label for="ses-n1-groups">IDs dos grupos N1</label><input class="form-control" id="ses-n1-groups" name="n1_group_ids" value="<?php echo $field('n1_group_ids'); ?>"><small>Seleção inicial: 55. Os nomes serão consultados na conexão GLPI do relatório e exibidos na prévia. Usado no INS6 e, junto com os grupos N2, no INS4. Não restringe os demais indicadores.</small></div>
            <div class="g4fr-ses-field"><label for="ses-n2-groups">IDs dos grupos N2 · INS4</label><input class="form-control" id="ses-n2-groups" name="n2_group_ids" value="<?php echo $field('n2_group_ids'); ?>" required><small>INS4 considera chamados atribuídos a N1 ou N2, conforme a prioridade selecionada, sem vínculo N3. Os nomes serão conferidos na mesma base GLPI da extração.</small></div>
            <div class="g4fr-ses-field"><label for="ses-n3-groups">IDs dos grupos N3 a excluir de INS3, INS4 e INS6</label><input class="form-control" id="ses-n3-groups" name="n3_group_ids" value="<?php echo $field('n3_group_ids'); ?>" required><small>Qualquer vínculo atual a um destes grupos exclui o chamado das duas parcelas desses três indicadores, mesmo junto com N1. A base detalhada e os demais indicadores mantêm os chamados. Os nomes serão conferidos na base do relatório.</small></div>
            <div class="g4fr-ses-field"><label for="ses-ins6">INS6 · Critério de enquadramento N1</label><select class="form-select" id="ses-ins6" name="ins6_mode" aria-describedby="ses-ins6-help"><option value="assigned_n1" selected>Categoria elegível, atendimento N1 e sem grupo N3</option></select><small id="ses-ins6-help">A base elegível usa as categorias selecionadas sem vínculo N3. Conta como N1 quando há atribuição ao grupo N1 ou, na ausência de qualquer grupo atribuído, quando o solucionador pertence à equipe N1. A exceção por solucionador fica discriminada na auditoria. Chamados apenas em outros grupos não entram por essa exceção.</small></div>
            <div class="g4fr-ses-field"><label for="ses-calendar-n1">Calendário N1 para conferência · ID</label><input class="form-control" id="ses-calendar-n1" name="sla_n1_calendar_id" type="number" min="0" step="1" value="<?php echo $field('sla_n1_calendar_id'); ?>" required><small>0 = identificar automaticamente. Usado na conferência operacional da auditoria; não substitui a duração consolidada usada no indicador.</small></div>
            <div class="g4fr-ses-field"><label for="ses-calendar-n2">Calendário N2 para conferência · ID</label><input class="form-control" id="ses-calendar-n2" name="sla_n2_calendar_id" type="number" min="0" step="1" value="<?php echo $field('sla_n2_calendar_id'); ?>" required><small>0 = identificar automaticamente. A auditoria confere os segmentos e feriados; esta seleção não substitui a duração consolidada usada no indicador.</small></div>
            <div class="g4fr-ses-field"><label for="ses-calendar-n3">Calendário N3 para conferência · ID</label><input class="form-control" id="ses-calendar-n3" name="sla_n3_calendar_id" type="number" min="0" step="1" value="<?php echo $field('sla_n3_calendar_id'); ?>" required><small>0 = identificar automaticamente. Usado na conferência da auditoria; não altera a duração do indicador nem as exclusões N3 de INS3, INS4 e INS6.</small></div>
            <div class="g4fr-ses-field g4fr-ses-full"><label for="ses-kb-ids">IDs dos artigos previamente validados</label><input class="form-control" id="ses-kb-ids" name="validated_kb_ids" value="<?php echo $field('validated_kb_ids'); ?>" placeholder="IDs de artigos separados por vírgula"><small>Use IDs de artigos, não de categorias de chamados ou da base de conhecimento. A existência no catálogo não comprova aprovação. Informe a lista para usar o modo por artigos; o modo por categoria não usa esta lista.</small></div>
          </div>
          <p class="g4fr-ses-method">Metas por prioridade GLPI: 2 (baixa) = 8h; 3 (média) = 6h; 4 (alta) = 4h; 5 (muito alta) = 2h. O resultado compara a duração apurada com o limite da prioridade: duração consolidada positiva no GLPI; na ausência, abertura até solução menos espera geral, quando positivo; caso contrário, abertura até solução. O relógio começa na abertura registrada no chamado, inclusive quando herdada do original; a criação técnica do clone não reinicia a contagem. Clonagem ou mudança de grupo não excluem o chamado da apuração.</p>
        </div>
      </details>
    </section>

    <section class="g4fr-ses-card">
      <h2>2. Complementos e textos do relatório</h2>
      <div class="g4fr-ses-field"><label for="ses-title">Título</label><input class="form-control" id="ses-title" name="report_title" maxlength="180" value="<?php echo $field('report_title'); ?>" data-manual="1"></div>
      <details class="g4fr-ses-details"><summary>Complemento do INS5</summary><div class="g4fr-ses-detail-body g4fr-ses-grid">
        <div class="g4fr-ses-field g4fr-ses-full"><label for="ses-incomplete">INS5 · IDs dos chamados com execução incompleta</label><input class="form-control" id="ses-incomplete" name="incomplete_ticket_ids" value="<?php echo $field('incomplete_ticket_ids'); ?>" placeholder="Opcional — IDs separados por vírgula"><small>Somados aos chamados reabertos, sem contar o mesmo chamado duas vezes. Somente chamados do período e escopo.</small></div>
      </div></details>
      <details class="g4fr-ses-details"><summary>Análise gerencial, ações e observações</summary><div class="g4fr-ses-detail-body g4fr-ses-grid">
        <div class="g4fr-ses-field g4fr-ses-full"><label for="ses-summary">Análise gerencial</label><textarea class="form-control" id="ses-summary" name="executive_summary" rows="4" maxlength="12000" data-manual="1"><?php echo $field('executive_summary'); ?></textarea></div>
        <div class="g4fr-ses-field"><label for="ses-actions">Ações corretivas e acompanhamento</label><textarea class="form-control" id="ses-actions" name="actions" rows="4" maxlength="12000" data-manual="1"><?php echo $field('actions'); ?></textarea></div>
        <div class="g4fr-ses-field"><label for="ses-notes">Observações e justificativas</label><textarea class="form-control" id="ses-notes" name="notes" rows="4" maxlength="12000" data-manual="1"><?php echo $field('notes'); ?></textarea></div>
        <div class="g4fr-ses-field g4fr-ses-full"><label for="ses-phone-actions">INS12 · Acompanhamento das pesquisas de telefonia</label><textarea class="form-control" id="ses-phone-actions" name="phone_survey_actions" rows="3" maxlength="12000" data-manual="1"><?php echo $field('phone_survey_actions'); ?></textarea><small>Informe providências, responsáveis e prazos efetivamente acordados. O relatório mostra automaticamente a disponibilidade e as respostas vinculadas, informando sua participação conforme as fontes selecionadas para o INS12. Este texto não altera a fórmula.</small></div>
      </div></details>
    </section>
  </form>

  <section class="g4fr-ses-card">
    <details class="g4fr-ses-details g4fr-ses-details-first"><summary>3. Números de teste excluídos da telefonia <span id="ses-test-count"></span></summary>
      <div class="g4fr-ses-detail-body">
        <p class="g4fr-ses-help">Esta lista fica salva no plugin e é carregada automaticamente em relatórios futuros, inclusive ao trocar de mês ou reabrir esta página. Os números ativos são excluídos dos indicadores de telefonia e da pesquisa telefônica do INS12; continuam identificados na relação de testes excluídos. Espaços, parênteses e pontuação são removidos; os dígitos devem corresponder exatamente ao número registrado na fila. Não são excluídas automaticamente ligações curtas.</p>
        <?php if ($canConfigure): ?><form id="g4fr-ses-tests-form" method="post" action="<?php echo $h($front . 'ses_registers.php'); ?>">
          <input type="hidden" name="_glpi_csrf_token" value="<?php echo $h($csrf); ?>">
        <?php endif; ?>
        <?php $registerControls('tests'); ?>
        <div class="g4fr-ses-table-wrap"><table class="g4fr-ses-table" id="ses-tests-table"><thead><tr><th scope="col">Número de origem</th><th scope="col">Identificação / motivo</th><th scope="col">Excluir</th><?php if ($canConfigure): ?><th scope="col">Ação</th><?php endif; ?></tr></thead><tbody id="ses-tests-body"></tbody></table></div>
        <?php $registerFooter('tests'); ?>
        <p id="ses-tests-empty" class="g4fr-ses-help" hidden>Nenhum número cadastrado. Todas as origens serão consideradas.</p>
        <?php if ($canConfigure): ?><div class="g4fr-ses-actions"><button type="button" class="btn btn-outline-secondary" id="ses-add-test">Adicionar número</button><button type="submit" class="btn btn-primary" id="ses-save-tests">Salvar números de teste</button></div><p class="g4fr-ses-help" id="ses-tests-state">Lista carregada do cadastro permanente. Salve depois de adicionar, alterar ou remover números; os relatórios futuros usarão a lista salva.</p></form><?php else: ?><p class="g4fr-ses-help">A lista é administrada pelos usuários com permissão de configuração.</p><?php endif; ?>
      </div>
    </details>
  </section>

  <section class="g4fr-ses-card" aria-labelledby="ses-staff-title">
    <h2 id="ses-staff-title">4. Profissionais da equipe · INS10</h2>
    <p class="g4fr-ses-help">O cadastro histórico fica salvo no plugin e é reutilizado em todos os meses. Marque “Não Faturar” somente para excluir o profissional do INS10. Desmarcado: participa conforme suas datas de admissão e saída. Marcado: permanece no cadastro, mas não entra na equipe, nas saídas nem na soma dos valores mensais. Novos cadastros começam desmarcados e incluídos. A data de saída encerra o vínculo sem apagar sua participação nos meses anteriores.</p>
    <p class="g4fr-ses-help">As marcações originais são respeitadas: marcado significa exclusão por não faturamento; desmarcado significa participação conforme as datas. A opção vale para o vínculo inteiro, em todos os meses. O INS10 considera todos os profissionais com dias faturados no mês, inclusive os admitidos ou desligados durante o mês, e as saídas consideradas no mês e nos dois anteriores.</p>
    <?php if ($canConfigure): ?><form id="g4fr-ses-staff-form" method="post" action="<?php echo $h($front . 'ses_registers.php'); ?>">
      <input type="hidden" name="_glpi_csrf_token" value="<?php echo $h($csrf); ?>">
    <?php endif; ?>
    <?php $registerControls('staff'); ?>
    <p class="g4fr-ses-method" id="ses-staff-billing-month" role="status" aria-live="polite"></p>
    <p class="g4fr-ses-help">Dias Faturado usa automaticamente os dias corridos entre a admissão e a saída, limitados ao mês selecionado e incluindo as duas datas. Preencha um valor para ajustar somente esse mês; deixe vazio ou use “Automático” para restaurar a contagem pelas datas. Zero é um ajuste válido. Os dias informados não podem superar o período do vínculo nesse mês. Valor Faturado = Valor mensal ÷ dias do mês × Dias Faturado, arredondado em centavos por profissional. “Não Faturar” zera o faturamento. Status descreve a existência de uma data de saída e não altera a contagem histórica.</p>
    <p class="g4fr-ses-help">A frente de serviço é identificada automaticamente quando o perfil pertence a uma única frente. Se o perfil existir em mais de uma frente, selecione a frente do profissional.</p>
    <div class="g4fr-ses-table-wrap"><table class="g4fr-ses-table g4fr-ses-staff-table"><thead><tr><th scope="col">Nº</th><th scope="col">Não Faturar</th><th scope="col">Nome</th><th scope="col">Perfil Profissional</th><th scope="col">Frente de serviço</th><th scope="col">Data de admissão</th><th scope="col">Data de Saída</th><th scope="col">Dias Faturado</th><th scope="col">Valor mensal</th><th scope="col">Valor Faturado</th><th scope="col">Status</th><?php if ($canConfigure): ?><th scope="col">Ação</th><?php endif; ?></tr></thead><tbody id="ses-staff-body"></tbody></table></div>
    <?php $registerFooter('staff'); ?>
    <p id="ses-staff-empty" class="g4fr-ses-help" hidden>Nenhum profissional cadastrado. Cadastre e salve a equipe para apurar o INS10.</p>
    <?php if ($canConfigure): ?><div class="g4fr-ses-actions"><button type="button" class="btn btn-outline-secondary" id="ses-add-staff">Adicionar profissional</button><button type="submit" class="btn btn-primary" id="ses-save-staff">Salvar profissionais</button><span id="ses-staff-count" class="g4fr-ses-help"></span></div><p class="g4fr-ses-help" id="ses-staff-state">Cadastro permanente carregado. Para encerrar um vínculo, preencha a data de saída. Sem data de saída, o vínculo continua ativo após a admissão, inclusive nos relatórios históricos. Os filtros apenas organizam a tela. Remover é indicado para entradas incorretas; exige confirmação e permite desfazer ou recuperar pelo histórico.</p></form><?php else: ?><p class="g4fr-ses-help">O cadastro é administrado pelos usuários com permissão de configuração.</p><?php endif; ?>
  </section>

  <section class="g4fr-ses-card" aria-labelledby="ses-financial-title">
    <h2 id="ses-financial-title">5. Composição contratual e ajustes financeiros</h2>
    <p class="g4fr-ses-help">Os valores faturados da equipe formam a base do mês. As faixas de desconto dos indicadores são aplicadas automaticamente. Um ajuste financeiro aprovado pode alterar o desconto aplicado, com justificativa, sem mudar o resultado apurado do indicador.</p>
    <?php if ($canConfigure): ?><form id="g4fr-ses-financial-form" method="post" action="<?php echo $h($front . 'ses_financial.php'); ?>">
      <input type="hidden" name="_glpi_csrf_token" value="<?php echo $h($csrf); ?>">
    <?php endif; ?>
    <details class="g4fr-ses-details"><summary>Frentes de serviço e composição contratual</summary>
      <p class="g4fr-ses-help">Informe os perfis, as quantidades e os valores unitários de cada frente. A composição mensal soma o Valor Faturado dos profissionais associados. Um mesmo perfil pode existir em mais de uma frente.</p>
      <div class="g4fr-ses-table-wrap"><table class="g4fr-ses-table g4fr-ses-fronts-table"><thead><tr><th>Frente de serviço</th><th>Perfis e valores contratuais</th><?php if ($canConfigure): ?><th>Ação</th><?php endif; ?></tr></thead><tbody id="ses-financial-fronts"></tbody></table></div>
      <p class="g4fr-ses-help" id="ses-financial-fronts-empty">Nenhuma frente cadastrada.</p>
      <div class="g4fr-ses-field g4fr-ses-contract-total"><label for="ses-contract-total">Total contratual mensal</label><?php if ($canConfigure): ?><input type="text" inputmode="decimal" class="form-control" id="ses-contract-total" value="<?php echo $h($financialConfig['contract_total'] ?? ''); ?>" placeholder="Soma das frentes"><small>Em branco, usa a soma das frentes.</small><?php else: ?><span id="ses-contract-total"></span><?php endif; ?></div>
      <?php if ($canConfigure): ?><div class="g4fr-ses-actions"><button type="button" class="btn btn-outline-secondary" id="ses-add-front">Adicionar frente</button><button type="button" class="btn btn-outline-secondary" id="ses-use-contract-baseline">Usar referência contratual SES</button></div><?php endif; ?>
    </details>
    <p class="g4fr-ses-method" id="ses-financial-month"></p>
    <details class="g4fr-ses-details"><summary>Descontos aprovados para o mês selecionado</summary>
      <p class="g4fr-ses-help">Deixe a taxa vazia para usar a faixa automática do contrato. Informe uma taxa de 0% a 4% somente quando houver um ajuste aprovado e registre sua justificativa. Informar 0% retira o desconto financeiro, mas mantém a violação e o resultado do indicador visíveis.</p>
      <div class="g4fr-ses-table-wrap"><table class="g4fr-ses-table g4fr-ses-deductions-table"><thead><tr><th>Indicador</th><th>Taxa aplicada (%)</th><th>Justificativa do ajuste aprovado</th></tr></thead><tbody id="ses-financial-deductions"></tbody></table></div>
    </details>
    <details class="g4fr-ses-details"><summary>Outros ajustes do mês</summary>
      <p class="g4fr-ses-help">Informe créditos com valor positivo e descontos adicionais com valor negativo, acompanhados de descrição. Use esta tabela também para valores de períodos anteriores que devam compor o faturamento deste mês.</p>
      <div class="g4fr-ses-table-wrap"><table class="g4fr-ses-table g4fr-ses-adjustments-table"><thead><tr><th>Descrição / referência</th><th>Valor do ajuste</th><?php if ($canConfigure): ?><th>Ação</th><?php endif; ?></tr></thead><tbody id="ses-financial-adjustments"></tbody></table></div>
      <p class="g4fr-ses-help" id="ses-financial-adjustments-empty">Nenhum ajuste adicional para este mês.</p>
      <?php if ($canConfigure): ?><button type="button" class="btn btn-outline-secondary" id="ses-add-adjustment">Adicionar ajuste</button><?php endif; ?>
    </details>
    <?php if ($canConfigure): ?><div class="g4fr-ses-actions g4fr-ses-financial-save"><button type="submit" class="btn btn-primary" id="ses-save-financial">Salvar composição e ajustes</button></div><p id="ses-financial-state" class="g4fr-ses-help" role="status" aria-live="polite">Configuração permanente carregada. Os ajustes são salvos separadamente para cada mês.</p></form><?php else: ?><p class="g4fr-ses-help">A composição e os ajustes são administrados pelos usuários com permissão de configuração.</p><?php endif; ?>
  </section>

  <div class="g4fr-ses-action-bar">
    <div class="g4fr-ses-actions"><button type="submit" form="g4fr-ses-form" class="btn btn-primary" id="ses-generate">Gerar relatório mensal</button><button type="button" class="btn btn-outline-secondary" id="ses-save-manual" disabled>Atualizar textos</button><button type="button" class="btn btn-success" id="ses-export-docx" disabled>Exportar Word</button><button type="button" class="btn btn-success" id="ses-export-xlsx" disabled>Exportar Excel</button><button type="button" class="btn btn-outline-primary" id="ses-archive" disabled>Arquivar este mês</button><a class="btn btn-outline-secondary" href="<?php echo $h($front . 'ses_annual.php'); ?>">Histórico mensal e comparação</a></div>
    <?php if ($archiveSharing['enabled'] && $archiveSharing['allowed']): ?><label class="g4fr-ses-help" for="ses-archive-share"><input type="checkbox" id="ses-archive-share"> Disponibilizar este mês à equipe de relatórios ao arquivar</label><p class="g4fr-ses-help">Desmarcado: arquiva no seu histórico sem novo compartilhamento. Marcado: a equipe autorizada poderá consultar e exportar esta versão mensal completa. Compartilhamentos anteriores não são removidos ao desmarcar.</p><?php else: ?><p class="g4fr-ses-help">O arquivamento mantém esta versão no seu histórico pessoal. O compartilhamento depende de uma equipe de relatórios configurada e autorizada.</p><?php endif; ?>
    <span id="ses-draft-state" class="g4fr-ses-help">Gere o relatório para revisar os indicadores e exportar.</span>
  </div>
  <div id="ses-status" role="status" aria-live="polite" hidden></div>
  <section id="ses-preview" hidden aria-label="Prévia do relatório">
    <div class="g4fr-ses-card"><div class="g4fr-ses-preview-heading"><h2 id="ses-preview-title">Prévia do relatório</h2><span id="ses-generated-at" class="g4fr-ses-help"></span></div><div id="ses-totals" class="g4fr-ses-totals"></div><div id="ses-financial-preview"></div><div id="ses-selections"></div><div id="ses-warnings"></div></div>
    <div class="g4fr-ses-card"><h2>Níveis de serviço</h2><div id="ses-metrics" class="g4fr-ses-metrics"></div><div id="ses-phone-survey"></div><details class="g4fr-ses-details"><summary>Regras aplicadas e observações</summary><div id="ses-rule-notes" class="g4fr-ses-detail-body"></div></details></div>
    <div class="g4fr-ses-card"><h2>Dados que sustentam os indicadores</h2><p class="g4fr-ses-help">A prévia é paginada. O Excel inclui todos os registros do relatório gerado.</p><div id="ses-source-tabs" class="g4fr-ses-actions" role="group" aria-label="Selecionar tabela"><button type="button" class="btn btn-outline-secondary active" data-source="tickets" aria-pressed="true">Chamados</button><button type="button" class="btn btn-outline-secondary" data-source="calls" aria-pressed="false">Ligações consideradas</button><button type="button" class="btn btn-outline-secondary" data-source="excluded_calls" aria-pressed="false">Testes excluídos</button></div><div id="ses-source-table" class="g4fr-ses-table-wrap"></div><div class="g4fr-ses-pager"><button type="button" id="ses-page-prev" class="btn btn-outline-secondary btn-sm">Anterior</button><span id="ses-page-info"></span><button type="button" id="ses-page-next" class="btn btn-outline-secondary btn-sm">Próxima</button></div></div>
  </section>
</div>
<script>
window.G4FREPORTS_SES = <?php echo json_encode([
    'archive_url' => $front . 'ses_archive_api.php', 'api_url' => $front . 'ses_api.php', 'export_url' => $front . 'ses_export.php', 'registers_url' => $front . 'ses_registers.php', 'register_export_url' => $front . 'ses_register_export.php', 'register_import_url' => $front . 'ses_register_import.php', 'financial_url' => $front . 'ses_financial.php',
    'archive_sharing' => $archiveSharing, 'nonce' => $nonce, 'csrf_token' => $csrf, 'can_configure' => $canConfigure, 'test_numbers' => $testNumbers,
    'financial_config' => $financialConfig, 'financial_revision' => $financialRevision, 'financial_baseline' => FinanceSettings::defaultBaseline(),
    'staff_rows' => $staffRows, 'test_revision' => $testRevision, 'staff_revision' => $staffRevision, 'metric_labels' => $metricLabels,
    'connections_ready' => $configured['ses']['ready'] && $configured['callcenter']['ready'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script><?php readfile(dirname(__DIR__) . '/assets/js/staff_billing.js'); ?></script>
<script><?php readfile(dirname(__DIR__) . '/assets/js/ses_financial.js'); ?></script>
<script><?php readfile(dirname(__DIR__) . '/assets/js/ses.js'); ?></script>
<?php if (class_exists('Html')) { Html::footer(); } ?>
