# Versão 1.8.15 — salvamento e evidências de exceções

## Salvamento dos cadastros

O salvamento e a consulta do histórico usavam `DBmysql::query()` para adquirir e liberar um bloqueio. Esse método gera uma exceção no GLPI 11 antes de processar as linhas recebidas, causando HTTP 500. A versão 1.8.15 usa os métodos nativos `getLock()` e `releaseLock()`, presentes no GLPI 10 e no GLPI 11.

A leitura da versão atual, a comparação da revisão, o salvamento e a conferência dos dados gravados continuam dentro do mesmo bloqueio. Se outro processo estiver usando o cadastro, a operação informa que é necessário tentar novamente. Falhas na liberação são registradas sem conteúdo dos cadastros e não substituem uma confirmação de salvamento ou um erro anterior.

O formulário continua enviando o cadastro completo, incluindo alterações feitas em páginas diferentes ou ocultas por filtros. As marcações **Não Faturar**, o histórico, os controles de acesso, o nonce e a proteção CSRF nativa permanecem. Nenhuma regra de contagem foi modificada.

## Word e Excel

A primeira tabela consolidada de indicadores do Word destaca somente os indicadores com situação **Meta não atingida**: fundo vermelho claro, texto vermelho escuro e situação em negrito. Os demais estados mantêm a aparência anterior. Na amostra de junho, INS3 e INS5 recebem o destaque. O restante do corpo XML do Word é idêntico ao produzido pela versão anterior para a mesma extração.

O Excel acrescenta a aba **Exceções INS5**, acessível por um link na aba INS5 e pelo índice da auditoria. Ela contém:

- Resumo com quantidades de eventos desconsiderados, chamados distintos, chamados retirados do total de reabertos, chamados ainda contados e chamados mantidos na base de solucionados.
- Tabela filtrável com uma linha por chamado afetado, título, categoria, solução e efeito da regra.
- Tabela filtrável com uma linha por evento desconsiderado, IDs, datas, autor da reabertura, solucionador do ciclo anterior, grupos e motivos.
- Totais separados de chamados distintos e eventos, sem somá-los como se fossem a mesma unidade.

A dispensa administrativa se aplica à reabertura. O chamado permanece no total de solucionados. Se houver outra reabertura válida ou execução incompleta informada, ele continua também entre os reabertos. As tabelas usam as participações da própria extração e não recalculam a regra.

A aba **Auditoria de exclusões** mantém as evidências anteriores e passa a ter um índice clicável para números de teste, chamadas excluídas, exclusões N3, eventos INS5, inclusões complementares INS6 e equipe. As tabelas de regras telefônicas, ligações excluídas e exclusões N3 ganham filtros independentes. As abas correspondentes dos indicadores também têm acesso à auditoria. A participação pelo solucionador N1 continua identificada como inclusão, e não como exclusão.

## Verificações realizadas

| Verificação | Resultado |
| --- | --- |
| Endpoint real do plugin e classes de cadastro com métodos oficiais DBmysql do GLPI 11.0.7 | 93 verificações aprovadas |
| Mesmo teste com métodos oficiais do GLPI 10.0.0 | 93 verificações aprovadas |
| Word: estados atingido, não atingido, pendente, não aplicável e provisório | 63 verificações aprovadas |
| Excel: eventos, chamados, efeitos, filtros, totais e navegação | 39 verificações aprovadas |
| Regressões de cadastro, INS10, apresentação, faturamento e autoria | Aprovadas |
| Pacotes Word/Excel completos de junho | 304 verificações estruturais aprovadas |
| Recálculo do Excel no LibreOffice | 21.613 células conferidas; nenhuma diferença ou erro |
| Comparação dos 12 indicadores com a versão 1.8.14 | Resultados idênticos |

O teste de salvamento executa o endpoint, bootstrap e classes reais do plugin em requisições PHP isoladas. Configuração/sessão do GLPI e transporte do banco são simulados; os métodos de bloqueio testados vêm dos arquivos oficiais das versões indicadas. Foram cobertos edição e inclusão no cadastro completo, leitura dos valores gravados, números com zero inicial, histórico, conflito de revisão, conteúdo inválido, acesso negado, bloqueio ocupado, falhas de gravação e falhas de liberação. Uma gravação sem confirmação por releitura continua sendo rejeitada.

A base completa de junho contém 6.567 chamados. O novo detalhamento mostra 13 eventos administrativos desconsiderados em 13 chamados distintos, todos preservados no total de solucionados. O INS5 permanece em 251 / 6.567. Casos de vários eventos no mesmo chamado, outra reabertura válida, execução incompleta e tabelas vazias também foram testados.

O Word foi renderizado e sua tabela alterada foi inspecionada visualmente. As novas tabelas do Excel foram abertas e renderizadas em cópia de conferência. Microsoft Office nativo e o GLPI/MariaDB de produção não estavam disponíveis; nenhuma configuração do cliente foi alterada.

## Atualização

Substitua a pasta ativa `g4freports/` pela pasta do pacote, sem desinstalar. Confirme **1.8.15** e reabra a área SES. Caso existam alterações ainda não salvas na tela antiga, a exportação da tabela permite guardar esses valores antes de atualizar a página.

As regras permanecem `ses-2026-09-23-v12`. Um rascunho v12 válido pode ser exportado novamente para receber o novo formato. Depois de salvar alterações na equipe ou nos números de teste, gere novamente o relatório para incluir esses dados. Rascunhos anteriores a v12 precisam de nova extração. Os arquivos Word e Excel já baixados não são alterados.
