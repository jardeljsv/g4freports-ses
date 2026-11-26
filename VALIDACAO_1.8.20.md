# Validação 1.8.20

Data: 23/10/2026. Base: pacote entregue g4freports_v1.8.19.zip.

## Alterações entregues

- Exportar todos é a seleção inicial nas duas tabelas, sem depender da busca ou página atual; exportação filtrada permanece disponível.
- Importação XLSX de profissionais e números de teste com validação integral e prévia de inclusões, atualizações e campos alterados.
- Duplicatas idênticas são contabilizadas e ignoradas; duplicatas conflitantes, identificações ambíguas, datas, marcas, valores e dias inválidos recusam o arquivo inteiro.
- A combinação mantém registros e meses ausentes do arquivo. Não há exclusão implícita ou gravação automática. Aplicar à tabela prepara a edição; Salvar confirma pelos controles existentes de concorrência e histórico.
- Exportação inclui frentes de serviço e ajustes de dias das demais competências. Campos calculados não substituem dados cadastrais. Telefones e IDs preservam zeros iniciais.
- Os 12 gráficos Meta × Resultado no Word e Excel usam barras horizontais.
- Análise do relatório anual/de meses selecionados em ANALISE_RELATORIO_ANUAL.txt. A funcionalidade anual permanece no estágio de análise, conforme o escopo solicitado.

## Verificações executadas

- Suítes PHP e JavaScript existentes aprovadas, incluindo salvamento, rateio, exclusões, INS10, satisfação, referência contratual, finanças e apresentação.
- Exportação de cadastro: 140 verificações. Exportação de faturamento: 69 verificações. Importação: 51 verificações de comportamento com arquivos reais gerados pelo exportador.
- Leitor de XLSX: 45 verificações, com limites de arquivo, partes e descompactação; validação de CRC, offsets, sobreposições, nomes ZIP e XML. Fórmulas, macros, objetos e vínculos externos são recusados. Não são instaladas novas bibliotecas no servidor GLPI.
- Controlador real da interface testado com DOM/fetch simulados: exportar todos/filtrados, prévia, cancelar, aplicar, salvar, alterações locais posteriores à prévia, conflito de revisão, validação, timeout, preservação de registros e acesso somente leitura.
- Treze requisições HTTP multipart reais ao novo endpoint, com sessão/Config/permissões GLPI simulados: upload válido, tipo incorreto, arquivo corrompido/excessivo, falta de permissão, nonce inválido, revisão desatualizada e método incorreto. A prévia não gravou configuração em nenhum caso. O CSRF nativo é emulado no ensaio; em produção, o endpoint mantém o bootstrap GLPI normal.
- O endpoint existente de salvamento manteve suas 143 verificações, incluindo revisão concorrente, lock, leitura de confirmação e histórico.
- Exportação/importação repetida preserva todos os campos dos vínculos, marcas Não Faturar, frentes, valores e ajustes mensais; registros excluídos da planilha permanecem no cadastro.
- Workbooks de profissionais com e sem competência e de telefones foram abertos e salvos novamente pelo LibreOffice, e depois importados. As linhas resultantes foram idênticas, sem inclusões ou alterações. Também foi verificada a regravação por openpyxl.
- Renderização dos novos arquivos de cadastro conferida no LibreOffice: cabeçalhos e instruções completos, aba de ajustes legível e aba Controle oculta.
- Word/Excel gerados pelo plugin com cenário sintético: comparação com a versão anterior mostrou exatamente 12 partes XML de gráficos modificadas em cada formato. Conteúdo do Word, planilhas, fórmulas, referências e dados incorporados permaneceram idênticos. As páginas de gráficos dos 12 indicadores foram renderizadas e conferidas no LibreOffice.
- Sintaxe PHP verificada em todos os arquivos do pacote; JavaScript alterado validado pelo Node.

## Preservação de regras e escopo

Não foram alterados Repository, MysqlClient, SlaDataSource, Calculator, StaffRegister, FinancialSummary, PhoneSurveyScore ou a versão de regras ses-2026-10-05-v16. Não houve consulta às bases GLPI/Asterisk, mudança de índices, agendamento ou alteração de CI. O total contratual informado de R$ 562.457,91 e as regras de faturamento/INS10 permanecem intactos.

A avaliação de carga é estática. Índices reais, planos de execução, concorrência e latências dos servidores não foram medidos. A proposta anual não deve ser tratada como otimização já aplicada.

## Uso e limites

Atualize os arquivos da pasta g4freports sem desinstalar nem limpar configurações. Recarregue a página e confirme 1.8.20. Exporte um novo modelo para importar: os arquivos anteriores não possuem os campos de intercâmbio necessários. Após importar, confira a prévia, aplique à tabela e salve; gere novamente a competência se houver alterações cadastrais.

Arquivos aceitos: XLSX de até 2 MB, até 500 registros, respeitando os limites de armazenamento existentes. Nenhuma fórmula é executada. Para limpar um ajuste de dias, mantenha a linha e deixe Dias informados em branco; omitir a linha conserva o ajuste anterior. Alterações de identidade em um ID existente são realizadas na interface, seguidas de nova exportação. Um telefone renumerado representa uma nova entrada; a anterior só é removida explicitamente na tabela.

Não foi feita instalação no GLPI do cliente nem validação em Microsoft Office nativo. Os cenários de teste são sintéticos; não representam uma nova extração ou validação dos dados reais de junho. Arquivos temporários de teste, ferramentas de execução e bases do cliente não acompanham o pacote.
