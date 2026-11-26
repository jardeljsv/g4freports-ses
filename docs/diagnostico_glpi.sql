-- G4F Reports: inventario limitado de metadados da fonte GLPI.
-- Selecione previamente o MESMO banco configurado para a fonte GLPI do plugin.
-- Nao ha USE com nome presumido, dados de chamados, credenciais ou alteracoes.
-- Leia DIAGNOSTICO_BANCOS.txt antes de executar e preserve todos os resultados.
-- Estas consultas nao medem o custo nem certificam o desempenho da extracao.

-- 1. Identificacao da sessao. Se selected_database for NULL, pare e selecione o banco.
SELECT DATABASE() AS selected_database,
       VERSION() AS server_version,
       @@version_comment AS version_comment,
       @@session.time_zone AS session_timezone,
       @@system_time_zone AS system_timezone;

-- 2. Lista fixa de 10 variaveis: no maximo 10 linhas, sem leitura de tabelas operacionais.
-- Nomes ausentes podem variar por motor/versao; ausencia nao equivale a valor zero.
-- SHOW nao admite ORDER BY portavel; compare/ordene este pequeno resultado por Variable_name.
SHOW SESSION VARIABLES
WHERE Variable_name IN (
    'autocommit', 'max_allowed_packet', 'max_execution_time', 'max_statement_time',
    'sql_mode', 'time_zone', 'transaction_isolation', 'transaction_read_only',
    'tx_isolation', 'tx_read_only'
);

-- 3. As 21 tabelas abaixo sao referenciadas pelo codigo atual da extracao SES.
-- TABLE_ROWS e estimativa; nao e contagem exata. ENGINE NULL pode indicar uma view.
-- A lista limita naturalmente o resultado a 21 nomes; LIMIT 22 e sentinela.
SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'glpi_tickets', 'glpi_groups_tickets', 'glpi_tickets_users',
      'glpi_users', 'glpi_groups_users', 'glpi_groups', 'glpi_locations',
      'glpi_itilcategories', 'glpi_logs', 'glpi_itilsolutions',
      'glpi_ticketsatisfactions', 'glpi_slas', 'glpi_slms', 'glpi_entities',
      'glpi_calendars', 'glpi_calendarsegments', 'glpi_holidays',
      'glpi_calendars_holidays', 'glpi_configs',
      'glpi_knowbaseitems_items', 'glpi_knowbaseitems'
  )
ORDER BY TABLE_NAME
LIMIT 22;

-- 4. Tipos temporais e tipos/collations das chaves, sem valores das linhas.
-- Se retornar 2001 linhas, o resultado pode estar incompleto: pare e informe isso.
SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, DATA_TYPE, COLUMN_TYPE,
       IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'glpi_tickets', 'glpi_groups_tickets', 'glpi_tickets_users',
      'glpi_users', 'glpi_groups_users', 'glpi_groups', 'glpi_locations',
      'glpi_itilcategories', 'glpi_logs', 'glpi_itilsolutions',
      'glpi_ticketsatisfactions', 'glpi_slas', 'glpi_slms', 'glpi_entities',
      'glpi_calendars', 'glpi_calendarsegments', 'glpi_holidays',
      'glpi_calendars_holidays', 'glpi_configs',
      'glpi_knowbaseitems_items', 'glpi_knowbaseitems'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION
LIMIT 2001;

-- 5. Indices existentes: cada linha representa uma coluna de um indice.
-- CARDINALITY e estimativa; nao atualize estatisticas para executar este inventario.
-- Se retornar 2001 linhas, o resultado pode estar incompleto: pare e informe isso.
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME,
       SUB_PART, COLLATION, CARDINALITY, INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'glpi_tickets', 'glpi_groups_tickets', 'glpi_tickets_users',
      'glpi_users', 'glpi_groups_users', 'glpi_groups', 'glpi_locations',
      'glpi_itilcategories', 'glpi_logs', 'glpi_itilsolutions',
      'glpi_ticketsatisfactions', 'glpi_slas', 'glpi_slms', 'glpi_entities',
      'glpi_calendars', 'glpi_calendarsegments', 'glpi_holidays',
      'glpi_calendars_holidays', 'glpi_configs',
      'glpi_knowbaseitems_items', 'glpi_knowbaseitems'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
LIMIT 2001;
