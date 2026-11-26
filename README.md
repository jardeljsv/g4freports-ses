# Relatórios G4F — 1.8.24

## Atualização 1.8.24 — histórico mensal e valores visíveis

### Reexportar um mês salvo

Abra **Histórico mensal e comparação**, escolha o ano e a revisão da competência e use **Word mensal**, **Excel mensal** ou **Dados JSON** na própria linha. Os dois primeiros geram o relatório mensal completo: identidade G4F, indicadores, quadros de profissionais e financeiro, gráficos e, no Excel, bases, fórmulas e auditorias. Não utilizam o formato da comparação anual e não dependem de **Preparar mapas diários**. O nome do download identifica a revisão escolhida.

A geração normal e a reexportação histórica usam o mesmo perfil mensal preservado, `ses-monthly-v1`. Novas extrações registram o perfil antes do cálculo da revisão. O perfil inclui o modelo G4F e os componentes necessários para conservar a apresentação e as fórmulas. Nenhum Word ou Excel precisa ser mantido no arquivo permanente. Rascunhos e arquivos anteriores com estrutura 2 e regras `ses-2026-10-05-v16` usam a base mensal compatível verificada da versão 1.8.23; isso não atribui retrospectivamente uma versão visual que não havia sido registrada. Combinações desconhecidas são recusadas de forma explícita e mantêm a opção JSON, sem nova consulta ou recálculo.

### Compartilhar o histórico com a equipe

Em **Configurar → Geral → Relatórios G4F → Histórico mensal compartilhado**, selecione os grupos do **GLPI que hospeda o plugin**. Os membros diretos desses grupos também precisam da permissão de usar o gerador. Administradores podem acessar a coleção quando houver grupos configurados. A associação é conferida no cadastro de grupos desse GLPI, sem herança automática de grupos ancestrais; os filtros e IDs da base operacional não definem esta permissão.

Ao arquivar, marque **Disponibilizar à equipe de relatórios** se desejar publicar aquela revisão. A opção começa desmarcada. Para uma revisão antiga própria, use **Disponibilizar à equipe** no histórico. Cada publicação conserva o autor, o mês, a revisão e seus dados originais. Configurar os grupos não publica automaticamente relatórios antigos. Desmarcar a opção numa nova operação não revoga uma publicação existente. Ao remover um usuário dos grupos autorizados ou desabilitar a coleção, ele perde o acesso compartilhado; seus próprios arquivos continuam disponíveis, conforme a permissão de uso do plugin.

O arquivo continua na área persistente `GLPI_PLUGIN_DOC_DIR/g4freports/monthly_archive`. Os relatórios **não são migrados para SQL**. O compartilhamento acrescenta um catálogo pequeno por ano, separado das evidências imutáveis. Inclua essa área no backup: somente o backup do banco não contém os relatórios. O catálogo tem limite de 4 MiB e 4.096 publicações por ano; atingir um limite não remove relatórios pessoais nem versões anteriores. A leitura anual não varre os diretórios de todos os usuários nem carrega as evidências completas de todos os meses.

### Comparação anual e de meses selecionados

Os gráficos mostram valores nos resultados mensais e legendas de resultado/meta, com identificação de valores provisórios e sem comparação confirmada. Percentuais mantêm duas casas decimais; tempos, quantidades e valores financeiros usam suas próprias unidades. Seleções longas usam painéis com escala comum. As metas mensais ficam identificadas sem encobrir os resultados. Lacunas e critérios incompatíveis continuam interrompendo as linhas.

Os mapas diários mostram contagens numéricas, com blocos de dias legíveis e escala comum por fonte. A conciliação informa volume representado, registros sem data atribuída e total mensal arquivado. Zero, ausência, dias futuros e datas inexistentes continuam distintos. As células do Excel permanecem vinculadas às bases mensais/diárias. Preparar os mapas continua necessário somente para a comparação anual, usando a evidência já arquivada.

### Carga e atualização

Nenhuma nova consulta a chamados, logs ou telefonia é executada para reexportar, compartilhar, comparar ou exibir os valores dos gráficos. Autenticação, configuração e verificação de grupos usam o GLPI normalmente. Cálculos, fontes, cadastros e regras v16 permanecem preservados.

A exportação mensal completa lê uma revisão por solicitação. Para limitar uso simultâneo de memória e CPU, uma exportação Word/Excel por instância e volume temporário é admitida por vez. Outra solicitação recebe uma mensagem para aguardar e tentar novamente, sem repetição automática nem regeneração dos dados. O bloqueio usa a área temporária já necessária aos rascunhos, sem exigir a área de arquivos para uma exportação mensal normal. Em vários servidores com áreas temporárias locais, o limite é por servidor; a área persistente dos arquivos deve continuar compartilhada. A preparação diária mantém seu próprio bloqueio no volume persistente.

O download JSON verifica a integridade completa antes de transmitir e envia os bytes originais em fluxo, sem decodificar nem reformatar toda a evidência em memória.

O limite de 64 MiB de um snapshot é um limite de armazenamento, não uma garantia de que qualquer exportação caiba em qualquer `memory_limit`. O teste sintético de 10.000 chamados e 5.000 ligações, com JSON de 24,7 MB, consumiu aproximadamente 162 MiB no Word e 243 MiB no Excel sob PHP de 256 MiB. O dimensionamento do PHP deve considerar a evidência real e as demais requisições do servidor. Esta versão não modifica a extração mensal nem implementa uma fila para consultas simultâneas às fontes.

Para atualizar, substitua a pasta `g4freports` **sem desinstalar**, sem apagar configurações, arquivos ou a área temporária em uso. Recarregue a tela e confirme **1.8.24**. O formulário administrativo tem um novo caminho de template para evitar reaproveitamento da tela antiga pelo cache Twig. Se o PHP usa OPcache sem revalidação, recarregue os processos PHP conforme a rotina do ambiente. Configure os grupos somente se quiser habilitar o compartilhamento. Consulte `VALIDACAO_1.8.24.md` para os testes e limites da validação local.

## Atualização 1.8.23 — análise visual das competências

O relatório anual ou de meses selecionados reúne versões mensais conferidas. Mostra INS1–INS12, metas, resultados, variações em pontos percentuais, custos e comentários registrados em cada competência. Não recalcula indicadores, não transforma meses ausentes em zero e não cria taxas anuais. A coleção usa linhas mensais, matriz de metas, volumes de tickets e chamadas, TMA/TME com amostras, evolução financeira e mapas de atividade por dia. As exportações mensais e a importação/exportação dos cadastros permanecem iguais.

1. Gere o relatório mensal como antes, confira os dados e aplique eventuais alterações da narrativa. Clique em **Arquivar este mês**. O botão só fica disponível quando a tela corresponde ao rascunho salvo; alterações em outra aba são verificadas pelo servidor.
2. Abra **Relatório anual e meses selecionados**, escolha o ano e selecione explicitamente uma versão arquivada de cada mês. É possível selecionar até 24 competências, inclusive de anos diferentes. Meses sem versão selecionada aparecem como ausentes; nenhuma geração é iniciada automaticamente.
3. Clique em **Preparar mapas diários** para incluir a atividade por dia das versões selecionadas. O processamento usa somente os arquivos já armazenados, uma competência por solicitação, e guarda uma projeção compacta reutilizável; não consulta GLPI nem telefonia. Sem a preparação, os mapas ficam explicitamente indisponíveis.
4. Clique em **Revisar comparação** e exporte Word ou Excel. A exportação usa exatamente as revisões conferidas, mesmo que outra revisão seja arquivada depois. Pelo menos uma versão deve estar selecionada. **Baixar dados JSON** recupera a evidência completa daquela revisão.

O arquivamento é imutável: repetir o mesmo conteúdo não duplica o arquivo; corrigir e arquivar novamente cria outra versão. Cada usuário acessa somente os próprios arquivos, inclusive administradores. O compartilhamento entre usuários não faz parte desta entrega. Os rascunhos temporários existentes não são migrados automaticamente para o arquivo permanente.

As comparações respeitam as regras, metas, fontes e filtros relevantes do indicador. Mudanças normais de equipe, dias e custos são preservadas. Critérios incompatíveis, origem antiga não identificada, resultado pendente ou competência extraída antes do encerramento não recebem conclusão de melhora/piora. Períodos não consecutivos são identificados. Valores provisórios permanecem visíveis, mas ficam fora do subtotal dos meses encerrados e financeiramente completos. Um mês antigo extraído hoje representa os dados disponíveis hoje; a data real da apuração fica registrada.

**Armazenamento:** os arquivos ficam em `GLPI_PLUGIN_DOC_DIR/g4freports/monthly_archive`, na área persistente de documentos do GLPI, separados dos rascunhos temporários. O processo PHP precisa de leitura/escrita; o diretório deve permanecer fora do acesso público. Inclua essa área no backup e no monitoramento de espaço. Em instalações com vários servidores PHP, use o mesmo armazenamento persistente com bloqueios/renomeações atômicas compatíveis. Não há fallback para `/tmp`, limpeza automática, exclusão pela interface nem cota global de disco nesta versão. Limites por proprietário: 50 revisões por competência; por revisão: evidência de 64 MiB e resumo de 1 MiB. Ao atingir um limite, o arquivamento falha sem remover versões anteriores nem impedir o relatório mensal.

**Leitura dos gráficos:** linhas ligam somente meses consecutivos com evidência comparável; pontos isolados mantêm os demais valores. Metas são as arquivadas em cada competência. As diferenças dos indicadores usam pontos percentuais; volumes apresentam variação absoluta e percentual quando houver base válida. TMA/TME conservam suas amostras mensais, sem média anual. Maior volume ou menor duração, isoladamente, não prova melhoria do serviço.

**Mapas diários:** tickets por data de solução e chamadas por entrada na fila, conforme a seleção mensal original. Escalas são comuns entre meses por fonte, com escalas separadas para tickets e chamadas. Dias sem observação, datas inválidas e diferenças de conciliação são identificados; não viram zeros silenciosamente. A preparação exige bloqueio global entre processos/usuários e lê uma revisão por vez com limites de tempo, tamanho e registros. A área de arquivos precisa compartilhar bloqueios entre servidores PHP. Uma recusa interrompe a preparação, sem alterar os quatro arquivos originais da revisão nem os resultados mensais.

**Carga nas fontes:** listar, comparar e exportar a coleção lê os resumos arquivados, sem consultar chamados, logs ou telefonia. A autenticação e as permissões continuam usando o GLPI normalmente. A geração mensal existente mantém suas consultas e seu comportamento de concorrência. Esta versão **não resolve a concorrência de três extrações mensais simultâneas**, não instala worker/fila e não reescreve SQL. Esses marcos exigem os planos das consultas mensais e a confirmação do ambiente de execução; veja `ANALISE_RELATORIO_ANUAL.txt`.

**Atualização:** substitua a pasta `g4freports` sem desinstalar nem apagar configurações, recarregue a tela e confirme **1.8.23**. Use **Atualizar** na gestão de plugins se solicitado pelo GLPI. As regras continuam `ses-2026-10-05-v16`; cálculos, fontes, financeiro e exportadores mensais permanecem iguais. Para registrar a identidade das fontes em novas comparações, gere e arquive o mês com esta versão. Rascunhos compatíveis anteriores podem ser arquivados, mas sua origem desconhecida limita as comparações. Antes do uso em produção, valide permissões/backup da área de documentos e o fluxo numa instalação de homologação. Veja `VALIDACAO_1.8.23.md`.

## Atualização 1.8.21 — integridade dos cadastros e diagnóstico

Esta atualização corrige o salvamento conjunto do cadastro e de seu histórico: os valores gravados são conferidos dentro de uma transação nativa do GLPI antes da confirmação. Mantém o bloqueio de concorrência e a comparação de revisão. O tratamento respeita as diferenças entre GLPI 10 e 11.

As solicitações de importação, exportação, histórico e salvamento da área SES compartilham uma fila local de requisições POST, com um token CSRF novo obtido antes de cada envio. Essa fila protege o envio de formulários; **não é uma fila de extração de relatórios**. Uma falha de rede não reenvia automaticamente um salvamento e não apaga as alterações na tela.

A importação aceita as representações numéricas equivalentes suportadas para valores, datas e dias, sem converter identificadores ou telefones em números. Datas ISO são aceitas quando contêm somente a data ou meia-noite, sem fuso ou com Z/+00:00; horários e deslocamentos ambíguos são rejeitados. O limite de ajustes mensais na exportação foi alinhado aos meses permitidos pelo cadastro (2000–2100), preservando ajustes antigos.

O teste de conexão verifica os recursos SQL usados pela extração e as tabelas/colunas de autoria obrigatórias. Recursos opcionais continuam opcionais. Um teste bem-sucedido confirma compatibilidade e acesso, **não desempenho**. Os scripts em `docs/diagnostico_glpi.sql` e `docs/diagnostico_callcenter.sql` coletam somente metadados; veja `docs/DIAGNOSTICO_BANCOS.txt`.

Consultas de extração, indicadores, faturamento, regras de satisfação, conteúdo dos relatórios e gráficos horizontais não foram alterados. A fila de extração, a reescrita das consultas e o relatório anual ainda não foram implementados. O plano revisado está em `ANALISE_RELATORIO_ANUAL.txt`.

**Instalação:** substitua a pasta `g4freports` sem desinstalar nem apagar configurações, recarregue a página e confirme a versão 1.8.21. Se o GLPI marcar o plugin como pendente de atualização, use **Atualizar** na gestão de plugins e confira sua ativação; não use **Desinstalar**. Esta versão não instala tarefas automáticas nem altera tabelas das fontes. As regras continuam `ses-2026-10-05-v16`; não há nova exigência de recalcular rascunhos por mudança de regras. Depois de mudar dados de cadastro, gere novamente o mês para refletir esses dados, como antes. Veja `VALIDACAO_1.8.21.md` para as verificações e limitações.

## Atualização 1.8.20 — importação de cadastros e gráficos horizontais

As tabelas de profissionais e números de teste passam a exportar todos os registros por padrão, independentemente da busca e da página. A seleção filtrada continua disponível. O arquivo inclui os dados atuais da tela, inclusive alterações ainda não salvas.

Para importar, use um arquivo exportado pela versão 1.8.20 ou posterior. Mantenha as abas e cabeçalhos. Números de telefone, IDs e identificações de vínculo devem permanecer como texto. As colunas calculadas são informativas; para alterar o faturamento, edite **Dias informados**. A aba **Dias por competência** contém os ajustes dos demais meses. Para restaurar o cálculo automático de um mês, deixe os dias dessa linha em branco; remover a linha conserva o valor existente.

A importação apresenta uma prévia com inclusões, atualizações, valores anteriores/novos e duplicatas idênticas. Registros conflitantes ou inválidos rejeitam o arquivo inteiro. **Aplicar à tabela** prepara as alterações; **Salvar** confirma usando os mesmos controles de revisão e histórico. Registros e competências ausentes do arquivo são preservados. Reimportar o mesmo arquivo não multiplica vínculos ou telefones.

Mantenha o ID de vínculos existentes. Para novos vínculos, deixe o ID vazio; homônimos ou vínculos distintos precisam de uma identificação diferente. Correções de nome, admissão ou identificação de um ID existente devem ser feitas na interface antes de exportar novamente. A frente de serviço usa o identificador já exportado; novas frentes precisam estar cadastradas. Limites: 2 MB por XLSX, 500 registros e os mesmos limites de armazenamento do cadastro. Fórmulas, macros e vínculos externos não são aceitos.

Os 12 gráficos Meta × Resultado dos indicadores usam barras horizontais no Word e no Excel. Dados, fórmulas, metas e cálculos permanecem iguais.

O relatório anual/de meses selecionados foi analisado, mas ainda não foi implementado. Veja **ANALISE_RELATORIO_ANUAL.txt** para o desenho de arquivo mensal persistente, exportação sem consultas às fontes e geração sequencial dos meses ausentes. As consultas de produção não mudam nesta versão.

Substitua a pasta do plugin sem desinstalar, recarregue a tela e confirme a versão 1.8.20. Exporte um novo modelo antes de importar. Após salvar alterações nos cadastros, gere novamente a competência. Veja **VALIDACAO_1.8.20.md** para as verificações e limitações.

## Atualização 1.8.19 — composição contratual na abertura do relatório

- O Word apresenta **Composição contratual de referência** e, logo abaixo, **Composição do faturamento da competência**, após a apresentação e antes dos resumos operacionais. Ambos são quadros editáveis, com as sete frentes de serviço, quantidades, valores e total geral.
- A referência SES inclui os 14 perfis/frentes da Ordem de Serviço 005/2026, 83 profissionais e **valor contratual de R$ 562.457,91**. Perfis diferentes na mesma frente possuem quantidades e valores unitários próprios. O total contratual informado é mantido no Word e no Excel.
- A composição da competência soma o **Valor Faturado** de cada profissional. Considera dias integrais ou proporcionais e ajustes de dias já salvos. **Não Faturar**, histórico, taxas individuais e regras do INS10 permanecem iguais.
- O cadastro de profissionais possui **Frente de serviço**. A associação automática usa somente perfis que identificam uma única frente. Para os analistas compartilhados entre N2 e Monitoramento, selecione a frente de cada vínculo. A seleção permanece salva ao pesquisar, paginar, mudar de mês ou exportar.
- A seção financeira permite editar os perfis por frente, seus quantitativos e taxas e o valor contratual total. A referência padrão é carregada quando não há composição anterior; configurações personalizadas preenchidas são preservadas. **Usar referência contratual SES** aplica os valores de referência sem apagar decisões financeiras dos meses.
- No Excel, a composição contratual detalha os perfis e a composição mensal continua vinculada aos dias e valores da base de colaboradores. O total contratual informado permanece fixo após recálculo.
- **Atualização:** substitua os arquivos da pasta `g4freports`, recarregue a página, confira as frentes dos profissionais e gere novamente a competência. Não desinstale nem apague as configurações. Rascunhos anteriores precisam ser gerados com as regras `ses-2026-10-05-v16`.

Veja `VALIDACAO_1.8.19.md` para as verificações e os limites do ambiente de teste.

## Histórico 1.8.18 — medição mensal e composição financeira

- **INS10:** saídas consideradas no mês e nos dois anteriores ÷ profissionais faturados em qualquer parte da competência × 100. Cada profissional com pelo menos um dia faturado conta uma vez, incluindo saídas no próprio mês. A meta permanece **≤ 10%**. O valor mensal igual a zero não exclui o profissional da contagem.
- **Dias Faturado editável:** o padrão continua automático pelas datas do vínculo. Informe os dias para ajustar apenas o mês selecionado; vazio ou **Automático** restaura o cálculo. Zero é válido. Os ajustes ficam salvos por profissional e competência, inclusive ao filtrar, paginar ou trocar de mês. Nenhum ajuste reescreve admissão, saída ou as marcações **Não Faturar**.
- **Profissionais:** índice alfabético na interface e nos relatórios. O Word apresenta Nº, Nome, Perfil Profissional, Dias Faturado e Valor Faturado somente para vínculos com participação no mês, incluindo os não faturados com zero. Saídas de meses anteriores ficam apenas no detalhe do INS10; o histórico completo permanece na auditoria Excel.
- **Word:** fonte vermelha para metas não atingidas, mantendo o fundo normal. Títulos hierárquicos, resumo de telefonia simplificado, siglas TMA/TME, uma tabela de valores por indicador e gráficos Meta × Resultado Apurado. Fontes GLPI e telefonia continuam separadas na satisfação, com Não preenchida e totais.
- **Financeiro:** nova seção para cadastrar frentes de serviço e sua referência contratual. Associe explicitamente os perfis às frentes; registros sem associação são identificados. Os valores contratuais são informados pela gestão e não são inferidos do cadastro da equipe.
- O faturamento bruto soma os valores proporcionais arredondados por profissional. As glosas usam as faixas do edital, com arredondamento por indicador antes da soma. **Glosa calculada** e **glosa aplicada** permanecem separadas: um ajuste aprovado exige taxa e justificativa específicas da competência e não altera o resultado técnico do indicador. Outros ajustes aceitam valores positivos (crédito) ou negativos (desconto).
- Novo Excel com **Resultado Final Indicadores** e **Base de Dados Colaboradores**, fórmulas vinculadas aos dados e aos indicadores, sem vínculos a arquivos externos. A documentação detalha o arredondamento para conciliar diferenças de centavos com planilhas que usam taxas de maior precisão.
- As conexões, números de teste, marcações, cadastro histórico e cálculo dos demais indicadores são preservados. A classificação de saída deixa de ser exibida; o dado legado permanece salvo.
- **Depois de substituir a pasta do plugin, recarregue a tela e gere novamente a competência.** Rascunhos anteriores não aplicam as regras `ses-2026-09-30-v15`. Não desinstale nem apague os cadastros para atualizar.

### Como aplicar os novos ajustes

1. Selecione a competência, confira a equipe e ajuste **Dias Faturado** quando necessário. Salve os profissionais.
2. Em **Composição contratual e ajustes financeiros**, cadastre a referência contratual e, quando houver, decisões financeiras já aprovadas. Salve a composição e os ajustes.
3. Gere o relatório novamente e exporte Word e Excel. O texto gerencial continua editável, sem alterar métricas ou decisões financeiras.

A base de junho usada na verificação reproduziu os onze indicadores de chamados/telefonia e alterou somente o INS10 de 8/67 para **8/69 (11,59%)**. Sem decisão financeira aprovada, a glosa do INS3 continua calculada. Valores ou decisões pontuais das planilhas de referência não são preenchidos automaticamente na configuração.

Veja `VALIDACAO_1.8.18.md` para verificações e limites.

## Histórico 1.8.17 — dias e valores faturados dos profissionais

- A tabela GLPI passa a mostrar **Dias Faturado**, **Valor Faturado** e **Status** para o mês selecionado. Datas, valor mensal e marcação Não Faturar atualizam os resultados sem alterar o cadastro automaticamente.
- Primeiro dia faturado = maior data entre admissão e início do mês. Último dia faturado = menor data entre saída (quando preenchida) e fim do mês. Ambos os dias entram na contagem; vínculos fora do mês e profissionais marcados Não Faturar têm zero dias e zero valor faturado.
- Valor faturado = valor mensal × dias faturados ÷ dias corridos do mês (28, 29, 30 ou 31). Arredondamento monetário apenas no resultado de cada profissional. O valor mensal original não é substituído.
- **Ativo/Inativo** indica ausência/presença de saída no cadastro, inclusive saídas futuras. A apuração histórica usa as datas do vínculo, não esse rótulo.
- Word mantém o timbre em retrato, com quadros correspondentes de identificação e faturamento. O quadro principal contém os profissionais que se aplicam ao mês e as saídas no mês ou nos dois meses anteriores. Marcados Não Faturar ficam em quadros separados; os demais vínculos históricos continuam no cadastro e na auditoria Excel.
- Word usa **Profissional desconsiderado? (Não Faturado)**. O nome e o significado de **Não Faturar** na tela permanecem os mesmos.
- Excel mensal e botão de exportação do cadastro incluem os novos campos. A exportação do cadastro usa o mês selecionado e os valores presentes na tela, incluindo alterações não salvas, sem salvar automaticamente.
- Fórmula do INS10, marcações salvas, cadastros e correção da satisfação telefônica preservados. **Gere novamente a competência** após atualizar para as regras `ses-2026-09-23-v14`.
- Verificações em `VALIDACAO_1.8.17.md`.

## Atualização 1.8.16 — satisfação telefônica por dois campos

- Consulta `atendimento` e a coluna opcional `nota`, preservando os valores de origem.
- Na competência e nas filas selecionadas, se um mesmo `atendimento` corresponder a mais de uma `nota` válida, usa `nota` diretamente, pois já representa satisfação tratada. Sem essa correspondência múltipla, mantém `6 − atendimento`.
- Os dois campos exigem notas inteiras de 1 a 5. Nota ausente/inválida no modo alternativo usa `6 − atendimento`; atendimento ausente/inválido pode ser recuperado por nota válida. A regra não é fixada em junho nem depende de nota média ou do percentual de satisfação.
- O diagnóstico usa as ligações extraídas antes das exclusões de teste; números de teste continuam fora das métricas. A resposta mais recente com um dos campos não nulo permanece vinculada por ID de ligação.
- Evidências incluem campo `atendimento`, campo `nota`, nota considerada, fonte e motivo. Tela, Word e Excel compartilham o mesmo resultado, sem segunda inversão.
- **Após atualizar, gere novamente o mês.** Rascunhos anteriores não contêm os dois campos; regras `ses-2026-09-23-v13`.
- Verificações em `VALIDACAO_1.8.16.md`. INS10 e os cadastros persistidos não são modificados.

## Atualização 1.8.15 — salvamento e evidências de exceções

- Salvamento dos cadastros corrigido para usar os métodos de bloqueio compatíveis com GLPI 10 e 11. Alterações feitas em páginas diferentes continuam sendo salvas juntas, preservando marcações, histórico e verificação de versão.
- A primeira tabela consolidada do Word destaca em vermelho claro os indicadores com meta não atingida, mantendo a situação escrita.
- O Excel inclui a aba **Exceções INS5**, com uma tabela por chamado afetado e outra por evento administrativo desconsiderado. Identifica autores, solução anterior, motivo e efeito na quantidade de reabertos; o chamado permanece na base total.
- Links nas abas dos indicadores e um índice na auditoria facilitam localizar também as exclusões N3, ligações de teste, participação da equipe e inclusões complementares do INS6.
- Os cálculos e as regras v12 permanecem iguais. Detalhes dos testes em `VALIDACAO_1.8.15.md`.

## Atualização 1.8.14 — INS10 por datas e exportação dos cadastros

- **Não Faturar** é o novo nome da marcação: marcado exclui o vínculo; desmarcado inclui conforme as datas. As marcações originais permanecem iguais.
- O INS10 conta as saídas elegíveis pela data, dentro dos três meses da competência, e os profissionais ativos no fechamento. O motivo da saída é somente descritivo: sua ausência ou alteração não bloqueia nem modifica o resultado.
- A data de saída continua opcional para profissionais ativos. O cadastro histórico permanece salvo e pode ser utilizado em competências anteriores.
- Profissionais e números excluídos da telefonia têm busca em todos os campos preenchidos, filtros e exportação independente em Excel. A exportação considera todos os registros encontrados, inclusive em outras páginas, ou o cadastro inteiro conforme a seleção.
- A exportação da tabela usa os valores presentes na tela, inclusive alterações ainda não salvas, e não exige gerar o relatório mensal. Exportar não grava o cadastro.
- Gere novamente a competência com as regras `ses-2026-09-23-v12`. Detalhes e validação em `VALIDACAO_1.8.14.md`.

## Histórico da atualização 1.8.13

A versão 1.8.13 corrigiu a leitura das marcações originais e manteve o campo explícito `not_billed`. A exigência adicional de classificação de saída que ainda existia nessa versão foi removida na 1.8.14. As marcações, datas e informações descritivas já salvas são preservadas.

## Histórico da atualização 1.8.12 — interpretação corrigida na 1.8.13

A versão 1.8.12 inverteu a apresentação das marcações antigas, mantendo uma interpretação incompatível com o preenchimento original do cliente. Essa orientação foi substituída pela correção da 1.8.13 descrita acima; não use a inversão da 1.8.12 como procedimento de atualização.

## Atualização 1.8.11

- SLA conciliado com a duração da base validada: tempo de solução positivo do GLPI; na ausência, tempo corrido desde a abertura menos espera geral quando positivo; caso contrário, tempo corrido. Clones preservam a abertura registrada. Prazos e calendários operacionais continuam na auditoria.
- INS5 desconsidera o evento de reabertura pelo mesmo solucionador integrante N1/N2/N3. Outra reabertura válida ou execução incompleta manual mantém o chamado contado. A auditoria discrimina os eventos e autores.
- INS6 aceita a categoria elegível com grupo N1 ou, quando não há grupo técnico atribuído, solucionador identificado com vínculo N1. Gráfico, cálculo e fórmulas usam o mesmo resultado.
- INS12 exibe fontes separadas, pesquisas “Não preenchida” e totais. GLPI + telefonia continua o padrão; somente GLPI permanece selecionável.
- Tabelas de ligações começam pela condição específica de INS7/8/9, com acesso à base completa. Totais visíveis não substituem o denominador do indicador.
- Legendas de gráficos circulares do Word e rótulos dos eixos corrigidos; mantidos gráficos editáveis e identidade G4F. Prioridade por nome e severidade contratual identificadas, conservando o código bruto na auditoria.
- Profissionais em ordem alfabética, busca, filtros e paginação. Duplicatas novas do mesmo vínculo são impedidas; vínculos distintos podem receber identificação própria.
- Remoções de profissionais e números de teste têm confirmação, desfazer e recuperação individual nas últimas dez versões salvas. O histórico registra o responsável e a data. O cálculo do INS10 permanece inalterado.
- Regras `ses-2026-09-23-v9`: gere novamente a competência depois da atualização.

Na conferência de junho, todos os 6.567 chamados puderam ser avaliados. INS4 reproduz **6.123 / 6.223 (98,39%)**, INS6 **4.601 / 5.381 (85,50%)** e INS11 por categoria **5.973 / 6.567**. A aplicação geral da exceção do INS5 desconsidera 13 chamados e resulta em **251 / 6.567**, diferente da exclusão manual de apenas um chamado na planilha histórica. São resultados da evidência fornecida, sem números fixos no cálculo.

Veja `VALIDACAO_1.8.11.md` para os testes, limites da validação e diferenças que dependem de decisões humanas. O histórico de versões anteriores permanece em `CHANGELOG.md`.

Plugin GLPI com dois fluxos: relatórios por profissional, obtidos pela API REST, e relatório mensal SES, obtido diretamente dos bancos GLPI/MariaDB e Asterisk/MySQL. O módulo SES gera Word editável em papel timbrado G4F e Excel a partir da mesma extração.

## Atualização

O pacote contém uma única pasta `g4freports/`.

1. Guarde uma cópia da pasta atual fora dos diretórios de plugins e mantenha o backup habitual do GLPI.
2. Substitua a pasta ativa `g4freports` pela pasta do pacote, preservando proprietário e permissões do servidor web. Evite manter outra cópia com o mesmo nome em `plugins` e `marketplace`.
3. Em **Configurar → Plugins**, execute a atualização indicada pelo GLPI e habilite o plugin. **Não desinstale para atualizar:** a desinstalação remove as configurações salvas.
4. Em **Configurar → Geral → Relatórios G4F**, confirme a identificação **Versão 1.8.23**, os campos API existentes e as duas seções de banco.
5. Em **Ferramentas → Relatórios G4F**, confirme o botão **Relatório mensal SES — Word e Excel** e gere novamente a competência desejada.

A instalação/atualização acrescenta somente configurações ausentes. Perfis e senhas existentes são preservados. Não são criadas tabelas de negócio nos bancos consultados. Composer, PHPWord e bibliotecas externas de planilhas não são necessários; o autoload e o papel timbrado já acompanham o pacote.

Se a versão ou os controles exibidos não corresponderem ao pacote, abra **Diagnóstico técnico**, ao final da configuração, ou o endereço relativo `plugins/g4freports/front/diagnostics.php`. A área administrativa informa a versão carregada, os caminhos dos arquivos e os hashes em disco, permitindo verificar qual cópia está em uso antes de alterar novamente a instalação.

## Conexões de banco

As fontes SQL do módulo SES são independentes dos perfis de API e da conexão do Metabase. Configure-as em **Configurar → Geral → Relatórios G4F**:

| Seção | Fonte |
| --- | --- |
| GLPI / MariaDB — Relatório mensal SES | Banco GLPI com chamados, prazos e evidências dos indicadores |
| Callcenter / MySQL — Telefonia | Banco Asterisk com `queue_log`; banco inicial `ipbx`, porta inicial `2210` |

Para cada fonte:

1. Preencha servidor, porta, banco e usuário; selecione **Habilitar conexão: Sim**. O auxiliar DBeaver aceita `jdbc:mysql://servidor:porta/banco` ou `jdbc:mariadb://servidor:porta/banco` e preenche apenas o endereço.
2. Informe a senha ou o nome de uma variável de ambiente disponível ao PHP do GLPI.
3. Configure TLS e os limites de tempo conforme o servidor. Em modo TLS, o certificado é verificado; o arquivo CA, quando informado, deve existir no servidor GLPI.
4. Clique em **Salvar configurações**, ao final da página, e depois em **Testar conexão** na seção correspondente.

O teste utiliza os valores salvos. A senha armazenada não reaparece no formulário: deixar em branco mantém o valor; preencher substitui; a opção de remoção apaga. As senhas usam a criptografia nativa do GLPI. Uma variável de ambiente configurada tem prioridade sobre a senha armazenada.

O PHP precisa da extensão `mysqli`, e o servidor GLPI precisa alcançar as duas fontes SQL. O acesso pelo DBeaver ou Metabase não comprova essa conectividade. Use contas com privilégios de leitura: o plugin executa consultas de leitura, mas o teste não certifica que a conta esteja impedida de escrever.

Abrir a configuração ou a tela SES não consulta as fontes SQL. A conexão ocorre ao testar ou gerar o relatório. Os relatórios por profissional continuam utilizando a API.

## Relatório mensal SES

1. Abra **Ferramentas → Relatórios G4F → Relatório mensal SES — Word e Excel**.
2. Escolha o mês, a entidade, os grupos atribuídos quando necessários e o identificador da fila. A entidade é exata, sem somar subentidades; grupos em branco abrangem todos os grupos dessa entidade. O filtro de fila procura o texto informado no nome da fila.
3. Revise **Critérios dos indicadores**, especialmente o mapeamento INS1–INS4, categorias/grupos N1, grupos N3 excluídos de INS3/INS4/INS6, artigos validados para INS11 e as fontes do INS12.
4. Complete os IDs manuais do INS5 e os textos gerenciais. Cadastre e salve os profissionais do INS10 e os números de teste antes da geração.
5. Clique em **Gerar relatório mensal**. Revise totais, indicadores, observações e as tabelas paginadas de chamados, ligações consideradas e testes excluídos.
6. Use **Exportar Word** e **Exportar Excel**. Ambos utilizam os mesmos dados e critérios preservados no rascunho.

O recorte dos chamados usa **data de solução (`solvedate`)**, inclusive chamados criados antes do mês. O intervalo começa no primeiro dia às 00:00 e termina no início do mês seguinte, sem incluir esse instante. Ligações usam a entrada na fila (`ENTERQUEUE`); eventos posteriores ao fim do mês são consultados para determinar o desfecho. O relatório usa horários locais da fonte. Para o SLA, o extrator confirma o fuso GLPI/entidade e verifica a compatibilidade da sessão SQL quando há campos `TIMESTAMP`; `DATETIME` não recebe conversão automática presumida. O fuso é necessário para a reconstrução de intervalos; um tempo de solução positivo armazenado continua utilizável sem reconstruir calendário. Esses critérios constam nos arquivos.

**Atualizar textos** altera título, análise, ações e observações sem consultar novamente as fontes. Alterar período, abrangência, critérios, o cadastro de profissionais ou a lista de testes exige gerar novamente. O rascunho é temporário, vinculado ao usuário e válido por até 24 horas; exporte os arquivos para manter o registro. A versão 1.8.18 usa as regras v15. Gere novamente os rascunhos das regras anteriores (até v14) antes de atualizar textos ou exportar Word/Excel; seus dados JSON permanecem disponíveis durante a validade original. Uma nova extração reflete o estado atual dos bancos e pode diferir de um relatório antigo.

O Word contém o relatório gerencial editável com o timbre G4F e gráficos nativos. O Excel contém **18 abas**: `Painel Consolidado`, `INS1` a `INS12`, `Distribuição Temporal`, `Base de Dados`, `Base de Dados Ligações`, `Auditoria de exclusões` e `Metodologia`. Os gráficos usam os mesmos dados preservados dos indicadores; as séries do Excel apontam para células do próprio arquivo. As bases incluem todos os registros considerados na extração; a paginação da prévia não limita os dados exportados. Os indicadores apresentam suas evidências, critérios e situações de apuração. Gráficos de contagens não substituem as frações contratuais: a meta e o denominador permanecem informados. Pendência e não aplicável não viram resultado zero. A telefonia distingue abandono total, abandono até 30 segundos e abandono acima de 30 segundos usado pelo INS9.

O relatório usa pizza para tipos de chamados, roscas para composições, colunas para notas e barras horizontais para faixas de duração/espera, colunas para volumes diários (empilhadas por desfecho nas ligações) e barras para comparações de metas e prazos. Os percentuais das fatias representam a composição da população exibida; não substituem o percentual contratual. Quantidades exatas continuam disponíveis ao lado dos gráficos.

A paleta dos relatórios segue o manual G4F: marinho `#011224`, azul `#376BFF`, amarelo `#FFE554` e cinza claro `#DAE6F2`, com variantes do manual e tonalidades claras derivadas para fundos e mapas de calor. O timbre existente é preservado. Resultados, exceções e pendências continuam identificados por texto e números, além das cores.

Os gráficos nativos mantêm categorias e valores identificáveis nos dois formatos. Gere novos arquivos após atualizar: um arquivo anteriormente reparado pelo Office não recupera objetos removidos apenas com a atualização do plugin.

Os mapas de calor mostram volumes acumulados por hora e dia da semana no mês, com contagens em cada célula e intensidade relativa ao maior valor do próprio mapa. Não são médias por ocorrência do dia da semana nem avaliações de cumprimento de SLA. Chamados usam a data de solução; ligações usam a entrada na fila após excluir testes. Datas inválidas ou fora do mês não entram nas visualizações temporais e sua quantidade é informada. Os mapas são tabelas editáveis no Word e células com fórmulas e formatação condicional no Excel. As colunas temporais acrescentadas ao final das bases preservam o texto original da data e geram uma referência com precisão de segundos, evitando que o arredondamento de microssegundos transfira registros para a hora ou o mês seguinte. As colunas anteriores e as fórmulas dos indicadores são preservadas. A exportação continua sem refazer consultas; rascunhos das regras v11 podem ser reexportados durante sua validade.

## Conferência dos IDs do relatório

O grupo N1 inicial é **55**, conforme o catálogo fornecido da base GLPI do relatório. O ID **53** corresponde a **Suporte Técnico IPESS** nessa base. IDs são específicos de cada ambiente; uma seleção explícita continua sendo respeitada.

Ao gerar, o plugin consulta os nomes dos grupos selecionados, das categorias elegíveis e dos artigos informados pela mesma conexão GLPI usada nos chamados. Os IDs e nomes ficam na prévia, na metodologia do Word/Excel e no rascunho preservado. Grupos ou categorias selecionados que não existam na fonte produzem uma mensagem específica, evitando um resultado baseado em seleção inválida. Quando nenhum chamado elegível atende ao critério de grupo N1 ou ao complemento por solucionador, a observação identifica os grupos consultados e mantém o zero medido.

O campo **IDs dos grupos atribuídos** restringe toda a população; o campo **IDs dos grupos N1** classifica o numerador do INS6. Para corrigir N1, use o segundo. As 44 categorias elegíveis foram preservadas: associação atual a N1 ou grupo configurado na categoria não acrescenta automaticamente novas categorias elegíveis.

O INS11 exige **IDs de artigos** em sua lista validada. Categorias de chamados, categorias da base de conhecimento e artigos têm cadastros distintos. O plugin verifica a existência do artigo e mostra seu título, mas não infere aprovação por nome, categoria ou sinalização FAQ. Sem lista, sem acesso aos vínculos ou sem acesso ao catálogo de artigos, o modo por artigos fica pendente. A opção por categoria mede a associação entre categorias e não usa a lista de artigos; apresenta a situação normal de cumprimento da meta. Artigos inexistentes informados no modo por vínculos diretos são rejeitados com identificação dos IDs.

Na amostra de junho conferida, o critério atual com grupo 55 e complemento pelo solucionador resulta em **4.601 / 5.381 = 85,50455%** após excluir 58 chamados de categorias elegíveis com vínculo N3 no INS6; a associação por categoria resulta em **5.973 / 6.567 = 90,95477%** no INS11. Esses valores descrevem a amostra fornecida, não são números fixos do plugin e podem variar em outra extração ou escopo.

## Critérios gerais

- **INS3, INS4 e INS6:** retiram chamados com qualquer grupo N3 atualmente atribuído das duas parcelas. A atribuição simultânea a N1 não anula a exclusão. Os chamados permanecem na base completa e nos demais indicadores. A auditoria identifica os IDs excluídos por indicador; não some essas contagens como se fossem chamados distintos.
- **N3:** IDs iniciais conferidos no catálogo fornecido: `57, 58, 59, 60, 1663, 3294, 3300, 3304, 3306`. Podem ser ajustados no campo próprio e seus nomes são consultados na fonte. Essa seleção não reconstitui o histórico de escalonamento.
- **Categorias INS6:** mantidas as 44 categorias existentes, inclusive `162` (Instalação de Navegador), `149` (Outro problema com o computador) e `176` (Criar nova pasta em servidor ou rede). O nome completo consultado aparece na metodologia.
- **Percentuais e metas:** resultados do INS9 com duas casas decimais também nos rótulos percentuais do painel Excel. Metas máximas preservadas: INS5 **≤ 2,00%**, INS9 **≤ 5,00%**, INS10 **≤ 10,00%**.
- **INS12 e telefonia:** o padrão passa a incluir as pesquisas dos chamados GLPI e as pesquisas telefônicas. A opção **Somente chamados GLPI** preserva o cálculo anterior. A prévia e os dois relatórios identificam as fontes incluídas, suas contagens e o resultado agregado. O campo **INS12 · Acompanhamento das pesquisas de telefonia** permite registrar responsáveis, prazos e ações efetivamente definidos.

A consulta de pesquisas usa `tab_pesquisa.uniqueid = callid`, selecionando a resposta mais recente com `atendimento` ou `nota` não nulo por identificador, inclusive respostas posteriores ao mês. A contagem não duplica uma ligação que aparece em várias filas e aplica as exclusões de testes antes de contar. Abrange somente as ligações do recorte, não todas as pesquisas existentes na central. A base Excel preserva os valores originais de `atendimento` e `nota`, a nota considerada, a fonte utilizada, o motivo, o ID e a data da pesquisa. Fonte indisponível resulta em quantidade indisponível, nunca em zero respostas. No modo padrão, o INS12 soma os numeradores e denominadores das duas fontes; no modo somente GLPI, as respostas telefônicas permanecem visíveis para conferência, sem participar da fração.

Os gráficos nativos, a paleta G4F e o timbre que já funcionavam são preservados. O pacote não altera tabelas ou registros nas duas fontes SQL. Após instalar, **gere novamente a competência** para aplicar as regras v11.

## INS12 — escolha das fontes

Na seção sempre visível **Como calcular os indicadores**, ao lado do seletor do INS11, use **INS12 · Fontes da pesquisa de satisfação**:

- **Chamados GLPI e pesquisas de telefonia — padrão:** `(favoráveis GLPI + favoráveis telefonia) / (válidas GLPI + válidas telefonia) × 100`.
- **Somente chamados GLPI:** `favoráveis GLPI / válidas GLPI × 100`, sem incluir a telefonia na fração.

Cada resposta válida tem o mesmo peso; não se calcula a média dos percentuais por fonte. São válidas as notas inteiras de 1 a 5. No GLPI, notas 4 e 5 são favoráveis. Na telefonia, normalmente **nota considerada = 6 − atendimento**. Quando o mesmo `atendimento` corresponde a notas válidas distintas no recorte mensal, usa-se **nota diretamente, sem inversão**, com retorno à conversão habitual nas respostas cuja `nota` seja inválida/ausente. Um `atendimento` inválido/ausente pode ser recuperado por `nota` válida. Notas consideradas **4 e 5** são favoráveis. Notas ausentes ou inválidas ficam fora do denominador e são discriminadas na conferência. Não tornam, por si, o resultado das respostas válidas provisório.

Uma ligação que aparece em mais de uma fila contribui uma única vez. A linha com a primeira entrada nas filas selecionadas dentro do mês, com desempate pela chave ligação/fila, representa sua resposta nos detalhes e na distribuição diária. A consulta já seleciona a última resposta não nula por identificador. A apuração não tenta deduplicar uma resposta de chamado e uma resposta telefônica por pessoa: são registros de canais distintos, sem vínculo confiável entre eles.

A escolha fica preservada no rascunho e aparece na prévia, Word, Excel e metodologia. Alterar a opção exige gerar novamente. O campo de acompanhamento permite atualizar apenas o texto; não muda a escolha das fontes.

Se a telefonia for incluída e sua fonte de pesquisas estiver indisponível, o INS12 fica **pendente**, sem apresentar um resultado GLPI parcial como se fosse combinado. O modo somente GLPI continua calculável quando a fonte GLPI está disponível. Se ambas as fontes selecionadas estiverem acessíveis, mas não houver respostas válidas nem valores inválidos observados, o resultado é **N/A**, não zero nem aprovação automática.

Na amostra de junho: GLPI **619/739**, telefonia **46/46**, combinado **665/785 = 84,71%**. Em agosto: GLPI **537/629**, telefonia **29/29**, combinado **566/658 = 86,02%**. São valores das amostras fornecidas, não números fixos do plugin.

## Números de teste

Na área SES, abra **Números de teste excluídos da telefonia**. Administradores podem adicionar, editar ou remover números, informar sua identificação/motivo e ativar a exclusão. Clique em **Salvar números de teste** antes de gerar.

A correspondência usa exatamente os dígitos do número de origem após remover espaços e pontuação. Não acrescenta DDI, não compara apenas o final do número e não exclui ligações curtas automaticamente. Cadastre o número como aparece na origem registrada pela fila.

As ligações correspondentes ficam fora dos totais, INS7–INS9, médias e detalhes de telefonia considerados. A prévia permite consultar os testes excluídos, e os relatórios informam sua quantidade. A lista aplicada fica preservada com o rascunho: editar o cadastro não altera uma extração já gerada. A aba `Auditoria de exclusões` registra a lista aplicada, inclusive regras inativas, e as ligações excluídas com identificador, fila, origem e motivo. Esses registros não entram na base de ligações consideradas nem nos seus indicadores.

## Critérios aplicados

| Indicador | Comportamento desta versão |
| --- | --- |
| INS1–INS4 | Há dois mapeamentos explícitos: painel atual (`2, 3, 4, 5`) ou ordem do contrato (`5, 4, 3, 2`). A associação escolhida aparece no relatório. A meta segue a prioridade (2=8h, 3=6h, 4=4h, 5=2h), independentemente da numeração INS. A meta é comparada com a duração do relatório: `solve_delay_stat` positivo; senão, intervalo abertura–solução menos `waiting_duration` quando positivo; senão, intervalo abertura–solução. Prazos e calendários são conferências separadas na auditoria. O método é identificado por chamado. INS3 e INS4 excluem N3 de ambas as parcelas. INS4 exige vínculo técnico atual a N1 ou N2, sem limitar suas categorias às do INS6. INS1 e INS2 mantêm seu escopo. |
| Prazo ausente | Não exclui automaticamente o chamado. A apuração usa as demais evidências; somente casos sem evidência suficiente ficam pendentes, com motivo específico. O relatório mostra recorte elegível, avaliados e cobertura; somente falta de dados que impeça parte do cálculo torna o resultado parcial. Escolha de método e histórico de alterações não alteram por si sós a situação da meta. |
| INS5 | Usa chamados com pelo menos uma transição de solucionado/fechado para estado ativo não dispensada, unidos aos IDs de execução incompleta informados manualmente. Dispensa cada evento feito pelo mesmo solucionador com vínculo N1/N2/N3 na extração, sem duplicar chamados. Os IDs manuais devem pertencer ao recorte. Recusa de solução é evidência distinta. |
| INS6 | A população elegível vem das categorias N1, excluindo chamados com vínculo atual N3 de ambas as parcelas. O numerador aceita atribuição atual a N1 ou, somente quando não há grupo técnico atribuído, solucionador identificado com vínculo N1 na extração. A ausência de grupo N2 deixou de ser aceita como evidência de solução N1. A atribuição atual não comprova o grupo responsável no instante da solução; essa limitação aparece na medição. |
| INS7–INS9 | INS7 considera conversação até 600 segundos; INS8, espera até conexão de até 30 segundos; INS9, abandono sem atendimento após mais de 30 segundos. O denominador é o total recebido após excluir testes, inclusive abandonadas. TMA/TME individuais são durações; suas médias são diferentes desses percentuais. |
| INS10 | Usa somente profissionais com “Não Faturar” desmarcado: saídas na janela de três meses / profissionais ativos no último dia da competência. Saída é o último dia ativo. O motivo é analítico e não altera a contagem; ausência de motivo não bloqueia a apuração. Cadastro vazio não permite apurar; efetivo zero resulta em N/A. Valores mensais cadastrados e valores faturados proporcionais são apresentados separadamente; não alteram as quantidades do INS10 nem inferem reajustes históricos. |
| INS11 | O padrão exige vínculo direto do chamado a um artigo da lista previamente validada. Sem a lista ou sem acesso aos vínculos e ao catálogo de artigos, fica pendente. A opção por categoria mede a associação de categorias usada no painel e apresenta a situação normal da meta, com o método identificado. |
| INS12 | Padrão: soma respostas válidas GLPI e telefônicas, uma por ligação. Favoráveis: notas 4 e 5; na telefonia, usa a seleção mensal entre `nota` direta e `6 − atendimento`, com recuperação pelo outro campo válido. Opção alternativa: somente GLPI. Fonte selecionada indisponível deixa a apuração pendente. A escala operacional continua 1–5, sem conversão para 0–10. |

Ausência de população elegível resulta em **não aplicável**, sem divisão por zero. Evidências indisponíveis são indicadas como pendentes ou provisórias. Justificativas e ações podem ser registradas nos campos narrativos; glosas e ajustes financeiros não são calculados automaticamente.


## Cadastros e evidências

- **INS12 visível:** as opções GLPI + telefonia (padrão) e somente GLPI aparecem ao lado do INS11, fora dos critérios avançados recolhidos.
- **INS4 N1 + N2:** usa os grupos N1 e N2 selecionados, com prevalência da exclusão N3. N2 inicial: `56, 3310, 3305`. Não exige categoria elegível N1. O filtro geral de grupos continua limitando toda a extração e pode restringir N2; para a abrangência completa, deixe-o vazio ou selecione ambos os níveis.
- **Excel por indicador:** cada aba INS contém somente os registros do seu critério, com filtros, dados utilizados e motivo. Nas abas de chamados, casos favoráveis e desfavoráveis do recorte permanecem juntos. INS7–INS9 começam pelas ligações da condição medida, mantendo link para a base recebida completa. Bases completas e auditoria contêm os registros fora do critério, inclusive ligações de teste. O link “Ver registros detalhados” vai diretamente à tabela.
- **Nomes claros:** contagens usam “Chamados não excedidos”, “Respostas favoráveis”, “Ligações recebidas consideradas” e equivalentes de cada indicador. A base de cálculo não é renomeada como “não elegíveis”, pois isso inverteria o sentido da fração.
- **Prazo, SLA e categoria distintos:** prazo vazio não impede uma classificação pela duração disponível. Um chamado pode ter SLA atribuído e nenhum prazo. A tabela exibe separadamente categoria, SLA ID/nome, prazo de origem, meta, calendário, tempo apurado e motivo de classificação.
- **Cadastros permanentes:** números de teste e profissionais são salvos nas configurações do plugin. O botão de salvar verifica a gravação. Reabrir a página ou trocar a competência reutiliza o cadastro. Somente administradores com permissão de configuração podem alterá-lo; outras configurações e credenciais são preservadas.

### Equipe e INS10

Cadastre **Não Faturar, Nome, Perfil Profissional, Data de admissão, Data de saída e Valor mensal** na área SES. Marque somente os profissionais que devem ficar fora do INS10. Novos cadastros começam desmarcados e incluídos. A data de saída é opcional e pode ser preenchida depois. O motivo da saída é também opcional, somente para análise; deixe sem motivo ou informe o que ocorreu, sem alterar a participação no indicador. Os registros podem ser editados ou removidos com confirmação; clique em **Salvar profissionais** para confirmar. Para encerrar um vínculo, preencha a data de saída, preservando sua participação nas competências anteriores.

A competência usa uma janela inclusiva do primeiro dia de dois meses antes até o último dia do mês selecionado. A equipe de referência é a ativa no último dia da competência; a data de saída é tratada como último dia ativo. Essa convenção operacional aparece nos dois relatórios. Todas as saídas de vínculos não excluídos na janela entram na contagem, independentemente do motivo. Motivos como substituição, redução e outra saída são detalhes analíticos; não são filtros de elegibilidade. Vínculos com Não Faturar marcado não entram no efetivo, nas saídas ou na soma mensal. Os antigos três contadores manuais não dirigem o resultado.

O Word apresenta os profissionais da competência e as saídas recentes, com identificação, status e faturamento em quadros correspondentes; os profissionais desconsiderados ficam separados. O Excel apresenta os profissionais relevantes ao INS10 na aba do indicador e preserva todo o cadastro na auditoria. O valor mensal é o cadastrado; sua soma de referência usa os profissionais incluídos ativos no fechamento. O valor faturado é calculado separadamente pelos dias inclusivos de vínculo dentro da competência, sem encargos ou inferência de reajustes anteriores. A marcação é persistente, não um histórico mensal de faturamento; cada relatório preserva a seleção existente quando foi gerado.

Os registros ficam congelados no rascunho gerado: salvar alterações no cadastro exige gerar novamente para incorporá-las. A atualização do plugin preserva ambos os cadastros. **Não desinstale** para atualizar: a desinstalação remove as configurações do plugin.

## Organização e recuperação dos cadastros

Busca, filtros e paginação mudam somente a visualização; o salvamento mantém todas as linhas. A busca considera os valores dos campos, inclusive datas, valores, opções e identificações, em todas as páginas. A lista de profissionais é ordenada alfabeticamente, inclusive nos relatórios. A identificação opcional do vínculo diferencia homônimos ou vínculos realmente distintos. Novas duplicatas exatas são rejeitadas; duplicatas antigas são sinalizadas para conferência, sem exclusão automática.

Cada cadastro tem **Exportar tabela** em Excel, com opção de exportar os registros filtrados ou todos. A exportação inclui todas as páginas do resultado selecionado e os valores atuais da tela, inclusive edições não salvas; não grava nem altera a apuração mensal. Números telefônicos permanecem texto para conservar zeros à esquerda.

“Remover” informa a entrada escolhida e permite desfazer antes de salvar. Depois de salvar, **Histórico / recuperar** apresenta as últimas dez alterações de cada cadastro, com responsável, data e quantidades alteradas. É possível recuperar uma entrada individual, conferir e salvar novamente. O histórico começa nas alterações realizadas após instalar esta versão; não recupera exclusões ocorridas antes dela. As versões mais antigas saem da lista após dez alterações posteriores.

Remover um profissional o retira de novas extrações históricas. Para manter o histórico de participação, use a data de saída e os filtros de visualização. Não use “Não Faturar” para esconder profissionais: essa marcação exclui o vínculo do INS10 em todas as competências. Relatórios já exportados e rascunhos existentes não mudam quando o cadastro é editado.

## Compatibilidade da configuração

O template de configuração corrigido na versão 1.8.1 permanece fixado no diretório do código carregado. Isso evita reutilizar o formulário antigo em cenários de cache de templates ou resolução de outra pasta. A identificação da versão é fornecida pelo código atual. O campo oculto `config_class` recebe a classe PHP diretamente, garantindo que a gravação nativa chame o tratamento das configurações.

Esses problemas foram reproduzidos/verificados localmente. A causa exata de uma instalação que ainda mostra a tela antiga depende dos arquivos efetivamente carregados; o diagnóstico foi incluído para identificá-los. Os testes locais não substituem o teste de conectividade e a conferência dos resultados no ambiente GLPI de destino.

## Relatórios por profissional — API

O fluxo anterior continua disponível pelo botão **Relatórios por profissional**, na área SES, ou pela tela principal do plugin. Configure o perfil da API, salve e use **Testar conexão padrão**. A extração mantém contextos, filtros, processamento em lotes, rascunho editável e exportação DOCX com identidade G4F.

## Contextos do relatório

O usuário pode marcar múltiplos contextos ao mesmo tempo:

- Chamados atribuídos ao agente.
- Chamados solicitados pelo agente.
- Chamados atribuídos aos grupos selecionados explicitamente.
- Tarefas de chamado feitas pelo agente.
- Acompanhamentos feitos pelo agente.
- Soluções registradas pelo agente.
- Projetos gerenciados pelo agente.
- Projetos gerenciados pelos grupos selecionados explicitamente.
- Projetos em que o agente participa.
- Tarefas de projeto do agente.
- Tarefas de projeto dos grupos selecionados explicitamente.
- Entradas narrativas manuais.

## Análises selecionáveis

As análises são calculadas a partir do rascunho já extraído, sem criar uma segunda varredura independente na API:

- Resumo por dia.
- Distribuição por status.
- Origem das atividades.
- Agente x grupos selecionados.
- Top categorias.
- Localização dos chamados.
- Tempo médio de resposta.
- Tempo de primeira resposta.
- Eventos do solicitante sem resposta técnica.
- Tabela consolidada de indicadores.

As visualizações exportadas para DOCX são tabelas/gráficos editáveis em Word, sem imagens geradas dinamicamente.
