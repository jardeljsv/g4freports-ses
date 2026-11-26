# Validação 1.8.23 — 23/10/2026

## Escopo

Redesenho do Word e Excel da coleção anual/de até 24 competências selecionadas. Os valores continuam sendo os de cada revisão mensal: não há recálculo de indicadores, média anual de percentuais/TMA/TME ou seleção automática de versões. Os gráficos de evolução usam datas reais, metas arquivadas, linhas somente entre meses consecutivos comparáveis e marcadores para valores provisórios ou sem comparação confirmada. Os custos são observações mensais em reais, sem inferir economia ou qualidade a partir de sua variação.

Mapas diários de tickets solucionados e ligações consideradas são preparados explicitamente a partir da evidência arquivada. A preparação não consulta as bases operacionais. A coleção usa somente resumos e projeções compactas nas prévias/exportações.

## Preservação e controles

- Calculator, Settings, FinancialSummary, StaffRegister, FinanceSettings, PhoneSurveyScore, SlaDataSource, SlaEvaluator, ActorEvidence, ChartData, Repository, MonthlySummary, AnnualReport, exportadores mensais, cadastros e modelos permanecem iguais à versão 1.8.22. Regras: `ses-2026-10-05-v16`.
- O mesmo snapshot completo gerou conteúdo mensal idêntico em todos os 87 componentes do DOCX e 100 componentes do XLSX, comparados dentro dos pacotes.
- Preparação de uma revisão por solicitação com bloqueio global não bloqueante, compartilhado entre proprietários/processos. Outra preparação recebe conflito, sem fila ou repetição automática. Reutilizar uma projeção válida não relê a evidência bruta.
- Leitor seletivo com limite de 64 MiB, 100 mil tickets, 100 mil ligações incluídas/excluídas somadas, profundidade 128 e orçamento de 15 segundos. Não retém arrays de evidência nem textos descartados. Uma recusa não publica resumo parcial.
- Projeção versionada, compacta, por proprietário e revisão, publicada atomicamente. Snapshot, resumo, manifesto e digest originais não são reescritos. Cache opcional danificado não impede ler os valores mensais; preparação explícita informa falha e não substitui silenciosamente esse cache.
- Datas inválidas/fora da competência e totais divergentes são identificados. Zero, ausência, dias futuros e dias inexistentes têm representações distintas. Escala diária comum entre competências por fonte; tickets e ligações têm escalas separadas.

## Evidência de testes

- Sintaxe dos 111 arquivos PHP e dos sete JavaScript da aplicação verificada; 31 suítes PHP e sete suítes JavaScript aprovadas, além de 63 solicitações HTTP aos controladores de arquivo/coleção. Incluem regressões de importação, cadastros, faturamento, satisfação, SLA e CSRF.
- Testes de parser incluem sintaxe/UTF-8, limites, fronteiras de buffers, checksum completo e arquivo com 48 MiB de texto não selecionado sob memória PHP de 24 MiB.
- Preparação completa de um arquivo com 48 MiB também passou sob memória PHP de 24 MiB, mantendo os quatro arquivos originais. Snapshot mensal completo de referência conciliou oito tickets, três ligações e zero testes excluídos no dia correto.
- Testes HTTP executam controladores, arquivos e exportadores reais com clientes de origem proibidos. Cobrem dono, CSRF/nonce, tipos inválidos, revisão fixada, falhas, repetição idempotente, integridade e liberação da sessão antes do processamento. Autenticação/bootstrap/CSRF GLPI são emulados.
- A interface executa o JavaScript real com DOM/rede simulados: versões fixadas, seleção de até 24 meses, preparação serializada com token novo, falha sem repetição automática, revisão invalidada, zero/ausência, status provisório e textos literais.
- Word verificado com seleções de 12 e 24 competências, meses ausentes/provisórios e intervalos longos; gráficos, matriz, finanças, mapas e apoio preservados em pacotes OOXML válidos. Comparações já disponíveis entre meses selecionados não consecutivos permanecem identificadas no apoio, sem conectar essas datas como evolução mensal contínua.
- Excel organizado em sete abas, com gráficos nativos ligados a tabelas numéricas, auxiliares ocultos fora da impressão, mapas com contagens exatas e fórmulas de diferenças/subtotais. Importado, recalculado e renderizado com Artifact Tool; sem erros de fórmula no caso verificado. Alterações de células atualizaram diferenças, direção e subtotais. Renderização com LibreOffice e inspeção visual de ambos os formatos; gráficos não são imagens achatadas.

## Homologação

Substituir a pasta sem desinstalar ou apagar configurações e confirmar a versão 1.8.23. Conferir uma exportação mensal conhecida; selecionar versões anuais, preparar mapas diários, revisar e abrir Word/Excel nos aplicativos usados pela equipe. Validar permissões, backup e armazenamento persistente comum com bloqueios/renomeações atômicas quando houver vários servidores PHP.

Os testes não substituem uma instalação GLPI 10/11 real, Microsoft Office ou medição de carga na infraestrutura de produção. LibreOffice e ferramentas de inspeção verificam os arquivos gerados; paginação pode variar entre aplicativos.

Nenhuma consulta mensal, índice, worker ou fila de extração foi alterado. Três usuários consultando/preparando uma coleção não iniciam novas consultas operacionais. Isso não demonstra capacidade para três extrações mensais simultâneas; esse trabalho continua separado, conforme ANALISE_RELATORIO_ANUAL.txt.
