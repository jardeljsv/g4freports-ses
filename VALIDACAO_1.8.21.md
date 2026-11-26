# Validação 1.8.21 — 23/10/2026

## Escopo entregue

Correções isoladas de persistência, CSRF, compatibilidade da importação/exportação de cadastros e diagnóstico de conexão. Nenhuma extração foi executada nas fontes GLPI ou Callcenter durante o desenvolvimento. Esta versão não implementa fila de extração, arquivo mensal durável ou relatório anual e não afirma redução da carga das consultas de produção.

## Verificações executadas

- Sintaxe: 88 arquivos PHP e todos os JavaScript em `assets/js` sem erros.
- 21 suítes PHP, incluindo as regras de atores, e 5 suítes JavaScript aprovadas.
- Importação: 103 verificações, incluindo representações numéricas em notação científica, datas ISO à meia-noite, datas inválidas, horários/fusos rejeitados, precisão, duplicatas, identidades e ajustes mensais. Um cadastro de exatamente 60.000 bytes, com 4.848 ajustes, foi exportado e reimportado sem perda.
- Exportação dos cadastros: 140 verificações. Leitor XLSX limitado: 45 verificações. A exportação com 241 competências preserva todos os ajustes.
- Persistência: 284 verificações com contrato GLPI simulado. As mesmas solicitações foram exercitadas com métodos nativos não modificados de `DBmysql` do GLPI 10.0.28 (267 verificações) e 11.0.11 (284). Transporte SQL, configuração e sessão continuam simulados: não se trata de teste em uma instalação GLPI real.
- Falhas de persistência: omissão individual de cada chave, erro após gravação parcial, falhas de início/commit/rollback, transação já ativa no GLPI 10 e savepoints no GLPI 11. Os casos verificam preservação do estado anterior, histórico recuperável e ausência de confirmação de gravação incompleta.
- CSRF: solicitações concorrentes de histórico, cadastro, importação, exportação binária e configuração financeira usam a mesma fila local e token novo no envio. Cobertos timeout após gravação simulada, resposta perdida, falha na renovação, acesso de leitura e ausência de repetição automática de POST.
- Endpoint de token: 6 casos de autenticação, autorização, nonce, método e token ausente; cadastros malformados não impedem a emissão do token.
- Upload HTTP real: 13 solicitações multipart aprovadas, sem gravações na prévia. PHP recebe arquivos pelo caminho real de upload; autenticação/configuração/CSRF do GLPI são emulados.
- Diagnóstico: 51 verificações com transporte sintético, sem conexão externa. Acesso, recursos SQL e desempenho são tratados separadamente. A ausência de pesquisa telefônica e de atores de apresentação continua opcional conforme o extrator.
- Scripts de metadados: cinco instruções por fonte; listas fixas de 21 tabelas GLPI e 2 Callcenter; limites sentinela, sem leitura de registros operacionais, COUNT, ANALYZE ou DDL.

## Preservação funcional

Comparação de bytes com o pacote 1.8.20 confirma que `Repository`, `ActorEvidence`, `Calculator`, `PhoneSurveyScore`, `StaffRegister`, `FinancialSummary`, `FinanceSettings`, `Settings`, `SlaDataSource`, `SlaEvaluator`, `ChartData`, `DocxExporter` e `XlsxExporter` não mudaram. O método `MysqlClient::fetchAll` também permanece igual; apenas o caminho de diagnóstico recebeu as novas verificações. O fluxo de geração de `ses_api.php` foi mantido; a nova ação emite somente um token CSRF.

As suítes financeiras, de faturamento, INS10, satisfação, SLA e apresentação continuam passando. Não foram alterados metas, população, duração, exclusões, ordem de resolução das notas, arredondamento, valor contratual ou fórmulas. Os 12 gráficos horizontais e os modelos de Word/Excel são os mesmos da 1.8.20. A versão de regras permanece `ses-2026-10-05-v16`.

## Limites da validação

Não houve validação com uma sessão nativa completa do GLPI, banco SQL real, carga de três usuários ou Microsoft Office nesta etapa. As classes nativas GLPI foram revisadas e parcialmente executadas com transporte simulado; isso não substitui uma verificação no ambiente instalado. Os testes de UI exercitam os controladores reais com DOM e rede simulados.

A importação aceita um subconjunto explícito de representações XLSX. Telefones e identificadores continuam exigindo células de texto. Datas ISO com hora diferente de meia-noite ou deslocamentos não neutros são rejeitadas; não há conversão de fuso. Fórmulas, macros e vínculos externos continuam proibidos. O contador informativo da linha de total continua exigindo dígitos.

A fila local de POST evita reutilização concorrente de token na página. Ela não é uma fila de extração entre usuários. As consultas mensais ainda usam o mecanismo anterior; o teste de conexão não comprova capacidade, consistência transacional ou desempenho. O plano de evolução e as informações necessárias constam em `ANALISE_RELATORIO_ANUAL.txt` e `docs/DIAGNOSTICO_BANCOS.txt`.

## Aplicação da atualização

Preserve a configuração instalada e substitua os arquivos do plugin. Use a atualização nativa da gestão de plugins se o GLPI a solicitar; não desinstale. Recarregue a página e confirme 1.8.21. Confira um salvamento e sua recuperação, uma exportação/importação sem alterações e o acesso aos relatórios já disponíveis. Nenhuma tarefa automática ou mudança nas tabelas das fontes é instalada por esta versão.
