# 1.8.24 — 2026-10-26

- Download de Word e Excel do mês arquivado no mesmo formato completo do relatório mensal, com revisão fixada e sem nova extração. JSON mantido.
- Perfil mensal preservado `ses-monthly-v1`, com modelo, fórmulas e dependências de apresentação verificadas. Novos snapshots registram o perfil; arquivos compatíveis anteriores usam a base mensal validada da 1.8.23 sem alteração dos arquivos originais.
- Histórico compartilhado por grupos explicitamente configurados do GLPI hospedeiro. Publicação escolhida pelo autor, catálogo anual limitado, autoria e integridade preservadas e autorização em todas as rotas.
- Rótulos numéricos e legendas nos gráficos anuais; painéis legíveis para seleções longas, precisão e unidades próprias, sem alterar comparabilidade ou valores mensais.
- Mapas diários numéricos em Word/Excel, com blocos de dias, contraste e conciliação de volumes. Fórmulas do Excel preservadas.
- Admissão de uma exportação mensal completa por instância/volume temporário antes de decodificar o snapshot; mensagens de indisponibilidade sem provocar nova apuração.
- Formulário administrativo com template próprio da versão. Mantidos cálculos v16, SQL de extração, cadastros e conteúdo dos relatórios mensais.

# 1.8.23 — 2026-10-23

- Redesenho da coleção anual/de meses selecionados em Word e Excel: síntese curta, matriz de indicadores, linhas mensais com metas arquivadas, volumes, tempos de atendimento e espera com amostras, custos e mapas diários.
- Linhas respeitam meses consecutivos e critérios comparáveis; valores provisórios ou de origem desconhecida permanecem visíveis sem falsas conexões ou conclusões.
- Preparação explícita de mapas diários a partir da evidência arquivada, uma revisão por solicitação, com leitura limitada, admissão global entre usuários e projeção compacta imutável reutilizada nas exportações.
- Preview e exportação leem somente resumos e projeções compactas. Nenhuma nova consulta às bases operacionais; cálculos, SQL, cadastros e relatórios mensais preservados.
- Mantém regras `ses-2026-10-05-v16`, versões escolhidas explicitamente, acesso por proprietário e totais financeiros em centavos de meses completos.

# 1.8.22 — 2026-10-23

- Arquivo mensal persistente, por proprietário, com revisões imutáveis e verificação de que o rascunho conferido não mudou em outra aba.
- Seleção explícita de versões de até 24 competências; prévia e exportação anual/de meses selecionados sem consultas operacionais às fontes.
- Word e Excel próprios para a coleção, com gráficos horizontais, metas e resultados mensais, diferenças em pontos percentuais, contexto e custos preservados.
- Comparabilidade por indicador, origem e critérios arquivados; meses ausentes, provisórios ou incompatíveis não recebem falsas conclusões. Sem indicadores anuais agregados.
- Subtotais financeiros em centavos, somente de competências encerradas e financeiramente completas; ausência não vira zero.
- Evidência completa disponível em JSON; leitura usual usa somente metadados/resumos compactos, com limites de tamanho e seleção.
- Identidade não secreta das fontes capturada a partir dos perfis efetivamente usados na extração; não inclui credenciais nem modifica consultas.
- Mantém cálculos, regras `ses-2026-10-05-v16`, importação dos cadastros, relatórios mensais e SQL existente. Fila, worker, geração automática de meses ausentes e otimizações SQL continuam pendentes.

# 1.8.21 — 2026-10-23

- Cadastro e histórico salvos e conferidos em transação nativa, com compatibilidade GLPI 10/11, preservando bloqueio e revisão.
- POSTs da área SES serializados com token novo antes de cada envio, inclusive histórico, importação, exportação e configuração financeira. Sem repetição automática de gravações após falha de rede.
- Importação normaliza representações numéricas suportadas em células de valor, data e dias sem perder precisão nem alterar identificadores; admite datas ISO à meia-noite sob validação estrita.
- Exportação preserva até 1.212 competências de ajuste por vínculo, conforme o intervalo já aceito no cadastro.
- Diagnóstico distingue conexão, recursos SQL e acesso ao esquema; inclui colunas de autoria e mantém associações de apresentação opcionais.
- Scripts de metadados e plano revisto para consultas e coleção de relatórios mensais. Não implementa extração em fila nem relatório anual; não afirma ganho de desempenho.
- Consultas de produção, regras `ses-2026-10-05-v16`, cálculos e modelos de relatório mantidos.

# 1.8.20 — 2026-10-23

- Exportação completa por padrão nas tabelas de profissionais e telefones, preservando a opção filtrada.
- Formato XLSX de importação com identificação de versão, frentes de serviço, ajustes de dias de todas as competências e metadados legados preservados.
- Importação com prévia, comparação campo a campo, combinação sem exclusão implícita, identificação de duplicatas e rejeição integral de conflitos/dados inválidos.
- Aplicação à tabela separada do salvamento; mantém permissão de configuração, CSRF/nonce, revisão concorrente e histórico do endpoint existente.
- Leitor XLSX sem novas dependências, com limites de descompactação, validação ZIP/XML e recusa de fórmulas, macros e referências externas.
- Gráficos dos 12 indicadores em barras horizontais no Word/Excel, preservando dados e referências.
- Análise do relatório anual e das consultas em ANALISE_RELATORIO_ANUAL.txt; nenhum botão anual, worker, índice ou consulta de produção alterado nesta entrega.
- Regras de cálculo continuam ses-2026-10-05-v16. Mantém INS10, satisfação, faturamento e total contratual informado.

## 1.8.19 — composição contratual e faturamento na abertura

- Quadros iniciais do Word com a composição contratual e o faturamento efetivo da competência, agrupados por frente de serviço.
- Referência SES com 83 profissionais e perfis de valores distintos dentro da mesma frente; total contratual informado de R$ 562.457,91.
- Cadastro financeiro detalhado por perfil e seleção de frente no cadastro de profissionais; perfis compartilhados não são atribuídos automaticamente a uma frente arbitrária.
- Totais mensais derivados dos valores individuais faturados, com vínculos proporcionais, dias editados e exclusões já existentes.
- Excel com linhas contratuais de perfis, total contratual informado preservado e fórmulas mensais vinculadas à base de colaboradores.
- Salvamento compatível com cadastros antigos e referência padrão, sem inversão de marcações ou exclusão de ajustes mensais.
- Regras `ses-2026-10-05-v16`: gerar novamente a competência após substituir os arquivos.

## 1.8.18 — 2026-09-30

- INS10 usa todos os profissionais com dias faturados na competência, inclusive proporcionais e saídas no mês; mantém a janela de três meses das saídas e as marcações originais Não Faturar.
- Dias faturados editáveis por profissional e mês, com restauração automática, validação do vínculo e persistência nas revisões do cadastro.
- Índice da equipe, relação mensal compacta no Word, retirada da classificação opcional de saída da apresentação e manutenção do histórico completo na auditoria.
- Word com fonte vermelha nas metas não atingidas, tabelas redundantes removidas, narrativas mais curtas e gráficos Meta × Resultado Apurado. Comparadores, percentuais, eixos e legendas explícitos.
- Composição contratual por frentes informada pela gestão, glosas pelas faixas contratuais, decisões financeiras por competência com justificativa e ajustes de crédito/desconto.
- Novas abas Resultado Final Indicadores e Base de Dados Colaboradores, com fórmulas de faturamento, glosa e conciliação de totais; quadros financeiros nativos também no Word.
- Preserva as consultas e regras dos demais indicadores, a seleção das notas telefônicas e os controles de salvamento. Regras v15 exigem nova geração do mês.

## 1.8.17 — 2026-09-23

- Cadastro de profissionais: Dias Faturado, Valor Faturado e Status, calculados para a competência selecionada e atualizados ao editar datas, valores ou Não Faturar.
- Faturamento inclusivo por dias corridos do vínculo dentro do mês; rateio pelo número real de dias da competência, sem arredondamento antecipado do valor diário. Valor mensal cadastrado preservado.
- Word: quadros de profissionais e faturamento limitados ao período e às saídas na janela de três meses; profissionais desconsiderados separados. Datas inicial/final faturadas, dias, valor proporcional e status explícitos, com totais.
- Rótulo descritivo no Word: Profissional desconsiderado? (Não Faturado). Na interface permanece Não Faturar e todas as marcações são preservadas.
- Excel mensal e exportação independente do cadastro incluem os novos campos; exportação da tabela identifica a competência e recalcula os valores no servidor. Histórico completo preservado na auditoria.
- Mantém a equação e a contagem do INS10, o salvamento dos cadastros e a seleção de satisfação telefônica da 1.8.16. Campos calculados não são gravados no cadastro.
- Regras v14: gerar novamente a competência para obter os novos campos.

## 1.8.16 — 2026-09-23

- INS12 telefônico: consulta os campos `atendimento` e `nota`, quando a coluna `nota` existe. Mantém compatibilidade com esquemas anteriores.
- Compara os pares válidos no recorte mensal das filas selecionadas. Se um mesmo `atendimento` corresponde a notas distintas, prioriza `nota` sem inversão; caso contrário, mantém `6 − atendimento`.
- Nota ausente ou inválida no modo de prioridade usa `6 − atendimento`. Atendimento ausente ou inválido pode ser recuperado por nota válida. Respostas inválidas/ausentes conservam tratamento separado.
- Seleção da fonte ocorre uma vez, antes das exclusões de números de teste; essas exclusões e a contagem de uma pesquisa por ligação permanecem aplicadas às métricas.
- Tela, Word, Excel, gráficos e contagens usam a mesma nota final. Preserva ambos os campos brutos, fonte utilizada e motivo da escolha nas evidências.
- Regras v13 exigem nova extração da competência para incorporar a coluna adicional. Não altera INS10, cadastros, marcações ou conexões salvas.

## 1.8.15 — 2026-09-23

- Corrige o salvamento e a leitura de histórico dos cadastros no GLPI 11, substituindo chamadas diretas de SQL pelos métodos nativos de bloqueio. A liberação do bloqueio não substitui a confirmação de salvamento nem o erro original.
- Word: linhas de indicadores com meta não atingida destacadas em vermelho claro na primeira tabela consolidada.
- Excel: aba Exceções INS5 com chamados afetados, eventos desconsiderados, autores, justificativas e efeito da regra; contagens de eventos e chamados separadas.
- Navegação para as exceções a partir das abas dos indicadores e índice das tabelas de auditoria existentes.
- Mantém regras v12, resultados, marcações Não Faturar, filtros, paginação, dados salvos e conexões.

## 1.8.14 — 2026-09-23

- INS10 calculado por datas e exclusões, sem exigir motivo de saída. A classificação permanece descritiva e não altera contagens, disponibilidade ou resultado.
- Checkbox renomeado para **Não Faturar**, preservando as marcações originais e a persistência explícita de exclusão.
- Contagem de ativos, saídas, evidências, gráficos, textos Word e fórmulas Excel usam a mesma regra. Motivo ausente é identificado como não informado, sem apuração pendente.
- Busca em todos os campos, filtros e exportação Excel independente dos cadastros de profissionais e números de teste. Exporta todos os registros filtrados, em todas as páginas, ou o cadastro inteiro, com os valores presentes na tela.
- Regras v12 exigem nova geração do relatório mensal. Histórico, demais indicadores e conexões preservados.

## 1.8.13 — 2026-09-23

- Corrige a interpretação das marcações originais do cadastro: marcado significa não faturado/excluído; desmarcado significa incluído conforme as datas. A inversão visual da 1.8.12 é removida.
- Campo explícito `not_billed` para persistência. Registros antigos com `billed` mantêm o mesmo checkmark, interpretado como exclusão, e registros sem campo começam incluídos. Salvar e recarregar repetidamente não inverte os valores.
- O cálculo do INS10 exclui os marcados de todas as parcelas, inclusive pendências de classificação de saída. Contagem de ativos e histórico por competência preservados.
- Tela, recuperação de histórico, Word e Excel usam a mesma regra. Saídas elegíveis ainda sem classificação são identificadas por nome nas observações.
- Regras v11 exigem nova geração; demais indicadores, conexões e cadastro de números de teste preservados.

## 1.8.12 — 2026-09-23

- Cadastro de profissionais: checkbox **Não foi faturado?**, marcado para excluir e desmarcado para incluir conforme as datas. Novos vínculos começam incluídos.
- Decisões de faturamento já salvas e histórico de recuperação preservados sem inverter dados; só a representação do checkbox muda.
- Word, Excel, leitura sem edição e histórico apresentam a mesma indicação de exclusão. Fórmulas Excel de quantidade e valor mensal ajustadas ao significado da coluna.
- Intervalo da tabela de cadastro na auditoria Excel corrigido para abranger suas 13 colunas, incluindo identificação do registro e do vínculo.
- Regras v10 exigem nova extração para atualizar os textos. Fórmula do INS10, classificação de saída, datas e demais indicadores preservados.

## 1.8.11 — 2026-09-23

- Duração do SLA conciliada por chamado com a base validada, preservando a abertura herdada de clones e a comparação operacional na auditoria.
- INS5: exceção de reabertura pelo mesmo solucionador N1/N2/N3 por evento, com auditoria e preservação de outras reaberturas/execução incompleta.
- INS6: complemento pelo solucionador N1 apenas para categorias elegíveis sem grupo técnico atribuído; gráficos e fórmulas consistentes.
- INS12: fontes separadas e identificadas, distribuição completa com Não preenchida e totais, mantendo o padrão combinado.
- INS7–INS9: detalhe inicial por condição medida e acesso à base completa; cálculo e consulta telefônica preservados.
- Word: legendas circulares e rótulos legíveis, resumos em duas colunas, textos duplicados reduzidos. Excel: fórmulas por fonte, cabeçalhos legíveis e novos detalhes de auditoria.
- Nomes alinhados à planilha do cliente; severidade contratual separada da prioridade GLPI.
- Cadastros: ordenação, busca, filtros, paginação, prevenção de duplicatas, confirmação/desfazer e recuperação individual das últimas dez versões salvas.
- INS10 mantém sua fórmula. Configurações, credenciais, timbre e fluxo de relatórios profissionais por API preservados.
- Identificação de versão uniformizada e regras v9 exigindo nova geração de rascunhos.

## 1.8.10 — 2026-09-21

- Ordem do contrato como seleção inicial INS1–INS4; seleção de associação sempre visível, mantendo a alternativa do painel.
- Classificações Não excedido / Excedido em tabelas e gráficos.
- Totais de contagens em distribuições, evidências e bases; contagem de registros visíveis em tabelas filtráveis do Excel.
- Reconciliação das notas GLPI, incluindo Não preenchida e Total geral, sem alterar o denominador de respostas válidas.
- Regra de abertura herdada de clones documentada e testada, sem mudança no relógio de SLA.
- Regras v8 exigem nova geração de rascunhos. A diferença entre 69 excedidos nos dados de junho e 100 na referência validada permanece explicitamente não conciliada por falta da classificação validada por ID.

# 1.8.9 — correção de SLA, evidências e faturamento da equipe

- SLA: prazo aplicável e relógio do próprio chamado têm precedência. Clonagem, mudança de grupo/SLA e abertura retroativa deixam de bloquear automaticamente a apuração. Prazo ausente é calculado pelas definições e contadores disponíveis; reconstrução histórica deixa de ser requisito universal.
- Calendário vinculado ao SLA considerado antes da descoberta por grupo, mantendo escolhas explícitas e as metas por prioridade do relatório.
- INS11 por categoria e INS12 com notas inválidas excluídas pela regra apresentam a situação normal de cumprimento da meta. Auditoria de método não torna um SLA calculável provisório.
- Tabelas principais do Excel restritas à população de cada indicador, incluindo os casos fora da meta; bases completas e exclusões preservadas. Campos de SLA retirados de evidências de métricas que não avaliam prazo.
- Cadastro de profissionais: campo persistente "Foi faturado?". Somente marcados compõem o efetivo, o valor mensal somado e as saídas/substituições do INS10. Não faturados permanecem salvos e não geram pendência de classificação de saída.
- Compatibilidade: profissionais já salvos sem o campo conservam inclusão; novas linhas começam desmarcadas. Word, Excel e prévia usam a mesma seleção e apuração preservadas no rascunho.
- Regras `ses-2026-09-21-v7`: gerar novamente o mês após atualizar. Configurações e cadastros existentes são preservados.

# 1.8.8 — apuração de tempo e rastreabilidade de SLA

- Novo extrator técnico de SLA, calendário, segmentos, feriados, entidades e histórico, com consultas preparadas e lotes limitados. Mantida leitura exclusiva das fontes.
- Avaliação por meta de prioridade e tempo útil com suspensões no mesmo calendário; prazo vazio não é condição de exclusão. Datas e relógios conflitantes não viram cumprimento automático.
- Métodos identificados por chamado: reconstrução de histórico, contadores validados, prova de cumprimento pelo limite superior e apuração pendente. Tempo líquido desconhecido não recebe valor inventado.
- Calendários automáticos por catálogo/entidade e seleções explícitas N1/N2/N3; mudanças de nível, prioridade, SLA e configuração histórica identificáveis nas evidências ficam assinaladas.
- Recorte elegível, avaliados, cobertura e ressalvas separados. Comparação com prazo de origem preservada.
- Excel com fundos claros explícitos nas evidências, novas colunas de auditoria e compactação progressiva de planilhas. Word com explicações atualizadas e início de cada indicador em página própria.
- Versão de regras ses-2026-09-21-v6: nova extração obrigatória antes de exportar.
- Preservados API dos relatórios profissionais, conexões, telefone, pesquisas, cadastros, seleção por solução e exclusões já acordadas.

# 1.8.7 — cadastros permanentes e rastreabilidade dos indicadores

- Seletores INS11/INS12 visíveis lado a lado; INS12 mantém GLPI + telefonia por padrão.
- INS4 com vínculo atual N1 ou N2, sem filtro de categoria N1, excluindo N3; catálogo confere os grupos N2.
- Tabelas filtráveis de registros e motivos em cada aba INS do Excel, preservando os gráficos nativos e a identidade G4F.
- Separação explícita entre ausência de prazo de solução, ausência de SLA e ausência de categoria; rótulos de contagens específicos por indicador.
- Gravação direta e confirmada dos números de teste nas configurações, com proteção contra sobrescrita de uma versão já alterada em outra sessão.
- Cadastro persistente de profissionais com datas, perfil, valor mensal e classificação de saída; INS10 e seus gráficos/evidências calculados a partir do cadastro salvo.
- Word com quadro mensal de profissionais e movimentações dos três meses; regras v5 exigem nova geração do mês.

# Histórico de alterações — Relatórios G4F

## 1.8.6

- Nova seleção das fontes do INS12. Padrão: chamados GLPI e pesquisas de telefonia. Alternativa: somente GLPI, preservando a apuração da 1.8.5.
- Resultado combinado usa soma das respostas favoráveis dividida pela soma das válidas, com o mesmo peso por resposta; não faz média dos percentuais. Telefonia mantém conversão 6 menos a nota original; notas consideradas 4 e 5 são favoráveis.
- Uma resposta telefônica por identificador de ligação, mesmo em várias filas; números de teste continuam excluídos. Linha representativa e nota usada no INS12 registradas nas evidências. Respostas ausentes/inválidas não entram no denominador.
- Prévia, Word e Excel informam a escolha, a composição por fonte e o total. Fórmulas, distribuição diária e gráfico de notas incorporam as duas bases conforme a seleção. Fonte incluída indisponível deixa o resultado pendente.
- Regras `ses-2026-07-31-v4`: nova extração obrigatória para rascunhos anteriores. A escolha das fontes não pode ser alterada pela atualização de textos.
- Preservados INS1–INS11, exclusões N3, data de solução, telefonia INS7–INS9, conexões, identidade visual e estrutura nativa dos gráficos.

## 1.8.5

- Aplicado checklist do cliente: exclusão de vínculo técnico atual N3 somente em INS3, INS4 e INS6, tanto no numerador como no denominador, com precedência sobre vínculo N1 simultâneo. Base completa e demais indicadores preservados.
- Grupos N3 configuráveis, com nomes conferidos na fonte; exclusões e elegibilidade efetiva INS6 documentadas na prévia, Word e Excel. Fórmulas e gráficos usam a população após exclusões.
- Confirmadas as categorias INS6 162, 149 e 176 já presentes na lista inicial. Mantidas as 44 categorias e as metas máximas INS5 ≤2%, INS9 ≤5% e INS10 ≤10%.
- Rótulos percentuais de comparação no Excel passam a duas casas decimais, alinhados às tabelas e ao Word, sem alterar a estrutura OOXML dos gráficos corrigida na 1.8.4.
- Evidência de pesquisa telefônica apresentada separadamente do INS12, com contagem por identificador único, disponibilidade da fonte, nota original, nota convertida, ID e data da resposta. Incluído acompanhamento gerencial editável; pesquisas telefônicas não são incorporadas automaticamente à fórmula GLPI.
- Regras `ses-2026-07-31-v3`: rascunhos anteriores exigem nova extração. Mantidos fontes, credenciais, recorte por solução, exclusões de testes, identidade visual e relatórios por profissional via API.

## 1.8.4

- Corrigidos os rótulos personalizados dos gráficos Word e Excel conforme o contexto de série e as restrições do Microsoft Office. Roscas não emitem posição explícita; opções de percentual e bolha são limitadas aos tipos compatíveis. Preservados dados, referências, paleta G4F, barras, pizzas, roscas e mapas.
- Grupo N1 inicial corrigido de 53 para 55. Seleções explícitas, lista de 44 categorias, população por solução e critérios de atribuição atual permanecem preservados.
- IDs e nomes dos grupos, categorias elegíveis e artigos informados são consultados na fonte GLPI do relatório, preservados no rascunho e apresentados na prévia e nos dois formatos de exportação.
- Seleções inexistentes produzem erros específicos. Falta de acesso ao catálogo de artigos mantém o INS11 por vínculos validados pendente. A lista exige IDs de artigos e não presume aprovação por associação a uma categoria.
- Teste de conexão GLPI inclui os campos dos catálogos usados na conferência de IDs. Fontes, credenciais, consultas mensais de chamados/telefonia, exclusões e relatórios profissionais pela API são preservados.
- Rascunhos v2 existentes podem ser reexportados com a estrutura de gráficos corrigida, mantendo sua apuração original. Para aplicar o N1 corrigido e registrar os nomes, gere novamente o mês.

## 1.8.3

- Corrigida a estrutura OOXML dos rótulos individuais de pizzas e roscas no Excel: a opção de excluir um rótulo não é combinada com suas opções de exibição. A estrutura anterior era rejeitada pelo esquema e correspondia às quatro partes de desenho removidas na mensagem de reparo apresentada.
- Volumes diários voltaram a colunas: ligações empilhadas por desfecho e chamados por data de solução. Mantidos pizzas, roscas e mapas de calor quando adequados.
- Aplicada a paleta do manual G4F aos gráficos, tabelas e mapas dos relatórios SES: marinho, azul, amarelo e cinza claro, com variantes oficiais e tons claros derivados. Mantido o papel timbrado existente.
- Preservadas as consultas, regras, bases, fórmulas dos indicadores, normalização temporal, exclusões, configurações e fluxo anterior por API. Rascunhos válidos 1.8.1/1.8.2 podem ser reexportados.

## 1.8.2

- Gráficos escolhidos conforme a informação: pizza para tipos de chamados; roscas para composições selecionadas; colunas para duração, espera e notas de satisfação; linhas para volumes diários. Comparações de metas e contagens de prazo mantêm barras.
- Acrescentados mapas de calor por hora e dia da semana, para chamados solucionados e ligações recebidas, com valores, totais e escala de intensidade próprios. O horário é o registrado na extração; ligações de teste permanecem excluídas.
- Nova aba `Distribuição Temporal` no Excel, totalizando 18 abas, com fórmulas baseadas nas datas da própria planilha e formatação condicional. O Word apresenta os mapas como tabelas editáveis.
- Percentuais de composição são identificados separadamente das frações contratuais. Fatias pequenas e quantidades zero continuam documentadas nas evidências; pendência/N/A não vira resultado zero.
- Atualização restrita à apresentação: preservados consultas, seleção por solução/entrada na fila, regras dos indicadores, exclusões, configuração e fluxo por API. Rascunhos válidos da versão 1.8.1 podem ser exportados com as novas visualizações.

## 1.8.1

- Corrigido INS6: exige categoria elegível e atribuição atual a grupo N1; removido o critério que tratava ausência de N2 como solução N1. O relatório explicita que vínculo atual não identifica automaticamente o solucionador histórico.
- Acrescentados gráficos nativos editáveis ao Word e gráficos por indicador ao Excel, derivados da mesma extração. Metas mínimas e máximas ficam distintas; pendente/N/A não é mostrado como zero.
- Adicionada a aba `Auditoria de exclusões`, com regras aplicadas e ligações excluídas. Contagens oficiais continuam usando apenas ligações consideradas.
- Telefonia distingue abandono total e abandono após mais de 30 segundos, além das durações médias e percentuais por limite.
- Corrigido contraste do botão de geração e seus estados.
- Versão de regras alterada para `ses-2026-07-31-v2`; rascunhos anteriores exigem nova geração para exportar com os critérios e gráficos atualizados.
- Preservadas as consultas SQL e as demais regras dos indicadores, as conexões e o fluxo anterior por API.

## 1.8.0

### Relatório mensal SES

- Nova área acessível pela tela principal do plugin, com geração mensal a partir das conexões GLPI/MariaDB e Asterisk/MySQL.
- Seleção de chamados por data de solução e de ligações por entrada na fila; critérios e data da extração informados nos arquivos.
- Word editável com timbre G4F e Excel com 16 abas, gerados a partir do mesmo rascunho de dados e regras. Exportação não refaz consultas.
- Prévia de indicadores e tabelas paginadas; bases Excel completas para o recorte gerado.
- Cadastro administrativo de números de teste com motivo e situação. Exclusão exata pelo número de origem normalizado aplicada a todas as métricas e detalhes considerados de telefonia.
- INS1–INS4 com mapeamentos explícitos do painel ou da ordem do contrato; uso do prazo registrado no GLPI e identificação de chamados sem prazo.
- INS5 com reaberturas registradas e complemento manual de execução incompleta; INS6 com categorias elegíveis e critério de grupo visível.
- INS11 padrão por vínculo direto a artigo previamente validado, com opção provisória de aproximação por categoria.
- Dados manuais de pessoal para INS10, textos gerenciais editáveis e situações pendente/provisório/não aplicável quando necessárias.
- Rascunhos temporários vinculados ao usuário, com validade de 24 horas; atualização de textos preserva a extração.

### Configuração e diagnóstico

- Template versionado fixado ao diretório do código carregado e identificação visível da versão 1.8.0.
- Corrigido o valor do campo oculto `config_class`, evitando separadores duplicados que impediam a resolução do tratamento nativo de configuração.
- Diagnóstico administrativo com versão, origem dos arquivos, hashes em disco, candidatos de diretório e informações de OPcache, sem exibir credenciais ou consultar as fontes dos relatórios.
- Mantidos os campos e testes independentes das duas conexões SQL, a criptografia nativa das senhas e a gravação pelo formulário de configuração do GLPI.
- Atualização preserva configurações existentes. Desinstalação não faz parte do procedimento de atualização.

A renderização do formulário completo, com cache de produção e namespace antigo, foi verificada localmente. A causa do formulário antigo no ambiente de destino não foi afirmada como conclusivamente identificada; o diagnóstico permite conferir o código efetivamente carregado.

### Fluxo existente

- Preservados os relatórios por profissional, seus perfis de API, contextos, análises, processamento em lotes e exportação DOCX.
- Sem dependência de PHPWord ou de biblioteca externa de planilhas e sem criação de tabelas de negócio nas fontes consultadas.

## 1.7.0 — histórico

- Configuração e teste independentes de GLPI/MariaDB e Callcenter/MySQL, com preenchimento de endereço JDBC, TLS e limites de tempo.
- Criptografia nativa, manutenção de senha ao deixar o campo vazio e exclusão explícita.
- Diagnóstico de conexão e acesso às tabelas/colunas usadas pelos relatórios.
- A versão 1.7.0 continha somente as conexões; o módulo SES e suas exportações foram acrescentados na 1.8.0.

## Histórico anterior

## Alterações v0.9.0

- Corrigido ciclo de geração/exportação para evitar erro de autorização ao gerar um rascunho, exportar e depois alterar parâmetros sem recarregar a página.
- Exportação DOCX agora ocorre em iframe oculto, sem navegar a página principal.
- Sessões da API GLPI são finalizadas em `finally` nos endpoints de teste, busca e geração.
- Busca de grupos por nome usa descoberta de search options e tenta múltiplos campos seguros de nome/completename.
- ID e nome do agente são somente leitura e preenchidos pela busca.
- Adicionada área de análises selecionáveis junto dos contextos.
- Adicionadas análises de tempo de resposta com cálculo requester -> primeira ação técnica posterior.
- Adicionada análise de localização com fallback: localização do chamado, localização do solicitante e `Local não informado`.
- DOCX recebeu tabelas/gráficos editáveis para análises.
- Modelos operacional, gerencial, técnico/projeto e anexo ficaram mais diferenciados.

## Alterações v0.9.1

- Ajuste visual das análises exportadas em DOCX.
- Gráficos nativos do Word/LibreOffice para linha, pizza, rosca, barras horizontais e colunas.
- Mapa de calor editável em tabela Word para volumetria por dia da semana.
- Análises distribuídas ao longo do documento conforme o modelo selecionado, em vez de ficarem concentradas em um único bloco no início.
- Prévia web com visualizações variadas: linha em SVG, donut/pizza por CSS, heatmap em grade e barras apenas quando fizer sentido.
- Mapa de calor derivado da análise de volume diário.
- Mantida a lógica API-first, extração em lotes, sem /ajax, sem dependências externas e sem bibliotecas de gráficos.


## Alterações v1.0.1

- Adicionado Contexto de dados com filtro por múltiplos títulos de chamados.
- Busca de chamado por ID para usar o título como referência de filtro.
- Filtro por título aplica em conjunto com agente/grupos/contextos selecionados, sem substituir o escopo.
- Modo gerencial por grupos selecionados, omitindo detalhamento individual e consolidando indicadores por agente.
- Exportação DOCX preserva papel timbrado G4F e distribui análises/tabelas conforme o modelo selecionado.


## Alterações v1.0.1

- Corrigida a lógica de filtro por título para que ela não use categoria/referência como substituto do título real do chamado.
- Entradas derivadas de chamados agora carregam `ticket_id` e `ticket_title` separadamente, evitando que o filtro pareça renomear itens encontrados.
- O filtro por título passa a ser aplicado após os contextos de agente/grupo/período, em composição com eles, e não como substituição do escopo.
- Em modo `somente detalhamento`, os indicadores e gráficos continuam usando o escopo completo, mas `detail_entries` retorna apenas os chamados cujo título real corresponde aos títulos selecionados.
- Em modo `global`, os fatos, entradas, indicadores e gráficos passam a considerar apenas os chamados cujo título real corresponde aos títulos selecionados.


## Alterações v1.1.1

- Resumo gerencial por agente agora prioriza nome e sobrenome do usuário GLPI em vez do login/ID.
- Login/ID foi mantido como referência secundária em coluna própria na prévia e no DOCX.
- Resolução de nomes usa cache e fallback seguro para não reintroduzir timeouts.

## Alterações v1.1.0

- Corrigida a paginação de contextos por grupo: cada grupo selecionado agora é processado como alvo independente, evitando `ERROR_RANGE_EXCEED_TOTAL` e evitando que um grupo grande bloqueie a leitura dos demais.
- Contextos por grupo passam a usar chaves internas por grupo, mantendo rótulo agregado na interface e permitindo totais por escopo mais confiáveis.
- Chamados de usuário/grupo agora são pesquisados por múltiplas bases de data em lotes separados: atualização, abertura, solução e fechamento, reduzindo casos de chamados presentes no GLPI mas ausentes no relatório.
- `ERROR_RANGE_EXCEED_TOTAL` é tratado como fim de paginação daquele alvo, não como erro de relatório.
- Modo gerencial passa a montar resumo por agente com fallback por técnico atribuído quando eventos de tarefa/acompanhamento/solução não estão disponíveis ou estão parciais.
- Eventos técnicos em modo gerencial consideram ações não solicitantes com autoria detectável, evitando resumo zerado quando a API não expõe vínculo de grupo no subitem.
- O preset Gerencial agora prioriza grupos selecionados e não contextos centrados no agente solicitante.
- O resumo executivo e as validações do modo gerencial usam chamados/fatos carregados, não a lista de entradas detalhadas que é omitida por design.


## Alterações v1.2.0

- Adicionado critério temporal explícito para chamados: criados no período com ação do agente, atualizados no período, solucionados/fechados no período e solucionados/fechados pelo agente.
- Removido o uso invisível de todos os campos de data ao mesmo tempo; os lotes agora usam apenas os critérios temporais selecionados na interface.
- Critérios “pelo agente” passam a exigir autoria detectável via tarefas, acompanhamentos ou soluções expostas pela API.
- Melhorada a cobertura da extração com painel de fontes, totais da API, linhas lidas, únicos adicionados, duplicados e limitações da API.
- Corrigida extração de IDs de pais em subitens quando a API retorna rótulos como “Chamado 123456” em vez de número puro.
- Restaurados métodos de última atividade de chamados/projetos que haviam sido removidos acidentalmente em um ajuste anterior.
- Leitura de subitens ganhou modo de verificação paginado e controlado por orçamento para evitar perdas silenciosas sem reintroduzir 504.
- A distribuição de origem em modo gerencial prioriza grupos selecionados sobre contexto de solicitante quando o chamado foi descoberto por múltiplas fontes.

## Alterações v1.4.0

- Corrige a chamada/runtime de `mergeTicketSearchRows` garantindo que o pacote contenha a implementação usada pelo fluxo temporal.
- Normaliza fontes de chamados com escopo temporal para as chaves-base (`tickets_assigned_user`, `tickets_requested_by_user`, `tickets_assigned_groups`) para evitar que chamados encontrados sejam tratados depois como fora do contexto.
- Mantém chaves detalhadas por grupo/data para cobertura, mas recalcula a fonte primária após mesclagem de lotes, priorizando grupos selecionados em relatórios gerenciais.
- Adiciona limitação compacta de cobertura quando buscas diretas de subitens falham, sem exportar erro técnico bruto no DOCX.
- Atualiza a versão em `setup.php` e `inc/common.php` para remover ambiguidade de pacotes v1.2.0.


### Correção v1.4.0

- O pipeline de tickets agora é canônico: linhas brutas da API e descobertas por múltiplos contextos são mescladas por ID de chamado antes de gerar o detalhamento editável.
- O relatório normal renderiza um único bloco por chamado, evitando duplicidade quando o mesmo ticket é encontrado por agente, grupo e critério temporal.
- A data exibida no detalhamento passa a seguir o critério temporal selecionado sempre que possível; em relatórios de criação no período, a data de abertura do chamado é priorizada em vez da última solução/comentário.
- Entradas cujo detalhamento cairia fora do período são omitidas do corpo normal e contabilizadas na cobertura como detalhes fora do período.
- Métricas e gráficos passam a usar os fatos finais/canônicos em vez de entradas parciais de lote.
