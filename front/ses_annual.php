<?php
require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;
use GlpiPlugin\G4freports\Ses\ArchiveAccess;

if (class_exists('Session')) Session::checkLoginUser();
if (Security::currentUserId() <= 0 || !Security::canUse(Config::merged())) {
    if (class_exists('Html')) Html::displayRightError();
    http_response_code(403); exit;
}
// This page deliberately does not normalize current registers or require source connections.
if (empty($_SESSION['g4freports_ses_nonce'])) $_SESSION['g4freports_ses_nonce'] = bin2hex(random_bytes(24));
$nonce = (string)$_SESSION['g4freports_ses_nonce'];
$sharing = ArchiveAccess::status();
$front = rtrim((string)($GLOBALS['CFG_GLPI']['root_doc'] ?? ''), '/') . '/plugins/g4freports/front/';
$h = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
if (class_exists('Html')) Html::header('Histórico mensal e comparação SES', $_SERVER['PHP_SELF'], 'tools', 'PluginG4freportsMenu');
?>
<style><?php readfile(dirname(__DIR__) . '/assets/css/ses.css'); ?></style>
<div class="g4fr-ses" id="g4fr-ses-annual">
  <header class="g4fr-ses-header"><div><span class="g4fr-ses-eyebrow">G4F · SES/DF</span><h1>Histórico mensal e comparação</h1><p>Baixe novamente um relatório mensal salvo ou compare indicadores e custos de várias competências.</p></div><nav><a class="btn btn-outline-secondary" href="<?php echo $h($front . 'ses.php'); ?>">Relatório mensal</a><a class="btn btn-outline-secondary" href="<?php echo $h($front . 'report.php'); ?>">Relatórios G4F</a></nav></header>
  <section class="g4fr-ses-card">
    <h2>Selecionar competências e versões</h2>
    <p>Arquive cada resultado revisado pelo botão <strong>Arquivar este mês</strong> no relatório mensal. Cada versão conserva seus valores e comentários. Os botões <strong>Word mensal</strong> e <strong>Excel mensal</strong> reproduzem o relatório mensal completo daquela versão, sem preparar uma comparação.</p>
    <p class="g4fr-ses-help"><?php echo $sharing['enabled'] && $sharing['allowed'] ? 'Você vê seus arquivos pessoais e as versões disponibilizadas à equipe de relatórios. Disponibilizar uma versão preserva sua autoria e permite que a equipe autorizada a consulte e exporte.' : ($sharing['enabled'] ? 'Você vê os arquivos de sua autoria. O acesso às versões da equipe depende da permissão de relatórios compartilhados.' : 'Você vê os arquivos de sua autoria. Um administrador pode configurar a equipe de relatórios para habilitar o compartilhamento.'); ?></p>
    <form id="annual-year-form" class="g4fr-ses-actions"><label for="annual-year">Ano</label><input id="annual-year" type="number" min="2000" max="2100" value="<?php echo date('Y'); ?>" required><button class="btn btn-primary" type="submit">Consultar ano</button><button class="btn btn-outline-secondary" type="button" id="annual-all">Selecionar ano completo</button><button class="btn btn-outline-secondary" type="button" id="annual-clear">Limpar seleção</button></form>
    <p class="g4fr-ses-help">É possível combinar até 24 competências, inclusive de anos diferentes. Trocar o ano mantém a seleção. Meses selecionados sem versão aparecem como ausentes; esta tela não gera meses automaticamente.</p>
    <div id="annual-status" class="g4fr-ses-notice" role="status" aria-live="polite" hidden></div>
    <div id="annual-catalog" class="g4fr-ses-table-wrap"></div>
    <p id="annual-selection-count" class="g4fr-ses-help"></p><div id="annual-selection" class="g4fr-ses-actions"></div>
    <p class="g4fr-ses-help">Para a comparação anual ou de vários meses, prepare os mapas diários para incluir os dias de maior atividade. A preparação usa apenas a evidência arquivada, uma competência por vez; o resultado fica disponível nas próximas exportações. Sem preparação, os mapas da comparação são identificados como indisponíveis. O download mensal completo acima não depende desta preparação.</p>
    <div class="g4fr-ses-actions"><button class="btn btn-outline-primary" type="button" id="annual-activity-button" disabled>Preparar mapas diários</button><button class="btn btn-primary" type="button" id="annual-preview-button" disabled>Revisar comparação</button><button class="btn btn-success" type="button" id="annual-word" disabled>Exportar comparação Word</button><button class="btn btn-success" type="button" id="annual-excel" disabled>Exportar comparação Excel</button></div>
  </section>
  <section class="g4fr-ses-card" id="annual-preview" hidden><h2>Comparação das versões selecionadas</h2><div id="annual-preview-body"></div></section>
</div>
<script>window.G4FREPORTS_ANNUAL = <?php echo json_encode(['api_url' => $front . 'ses_archive_api.php', 'csrf_url' => $front . 'ses_api.php', 'export_url' => $front . 'ses_annual_export.php', 'monthly_export_url' => $front . 'ses_archive_export.php', 'sharing' => $sharing, 'nonce' => $nonce], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<script><?php readfile(dirname(__DIR__) . '/assets/js/ses_annual.js'); ?></script>
<?php if (class_exists('Html')) Html::footer(); ?>
