# Versão 1.8.14 — INS10 e cadastros

## Resultado e regra do INS10

A exigência adicional de classificar uma saída foi removida. O cadastro histórico é avaliado pela competência, usando as datas e a marcação **Não Faturar**:

| Informação | Efeito no indicador |
| --- | --- |
| Não Faturar marcado | Exclui o vínculo do efetivo, das saídas e da soma mensal |
| Não Faturar desmarcado | Inclui o vínculo conforme as datas |
| Admissão até o fechamento e nenhuma saída anterior ao fechamento | Conta no efetivo ativo |
| Saída entre o primeiro dia de dois meses antes e o último dia da competência | Conta na quantidade de saídas, quando o vínculo não é excluído |
| Motivo da saída, informado ou ausente | Somente análise; não altera a contagem ou disponibilidade do resultado |

A data de saída continua sendo o último dia ativo; uma saída no próprio fechamento pode aparecer no efetivo e nas movimentações, conforme a convenção existente. Data em branco mantém o vínculo aberto. Admissão futura não entra no efetivo de uma competência anterior. Saídas antigas deixam de entrar quando estão fora da janela de três meses.

As marcações originais permanecem iguais. O campo explícito `not_billed` tem precedência; na ausência, o antigo campo `billed` é lido como a mesma marca de exclusão, sem inversão. Registros sem marcação são incluídos conforme as datas. Motivos antigos continuam salvos como detalhes, inclusive quando a data é apagada. O valor interno legado `pending` significa apenas motivo não informado; não bloqueia a apuração.

O numerador usa todas as saídas elegíveis por data, inclusive as descritas como substituição, redução ou outra saída. Essas descrições aparecem somente em subtotais analíticos. A equipe e a soma mensal permanecem independentes. Sem saídas elegíveis e com equipe ativa, o índice é 0% de rotatividade. Efetivo zero produz Não aplicável; cadastro vazio continua sem base para apurar.

## Conferência com a base fornecida

O cadastro da exportação mais recente de junho contém 91 entradas: 9 marcadas para exclusão, 80 ativas no fechamento e 2 saídas de vínculos não excluídos na janela. Sem mudar datas, marcas ou motivos, os exportadores reais produziram:

| Medição | Resultado |
| --- | ---: |
| Profissionais ativos no fechamento | 80 |
| Saídas consideradas | 2 |
| Registros excluídos por Não Faturar | 9 |
| INS10 | 2,50% |
| Situação, meta ≤ 10% | Meta atingida |

As duas saídas continuam descritas como motivo não informado e entram normalmente no indicador. O valor mensal total da amostra é R$ 0,00 porque todos os valores individuais recebidos eram zero; essa informação não bloqueia o cálculo. Os demais indicadores permaneceram iguais ao executar a mesma base completa de junho.

## Pesquisa, filtros e exportação dos cadastros

- Ambos os cadastros pesquisam os valores de todos os campos, em todas as páginas: texto, identificações, opções, datas e valores monetários. A pesquisa desconsidera acentos e diferenças entre maiúsculas e minúsculas; datas e valores também podem ser encontrados no formato apresentado em português.
- Profissionais têm filtros por presença de saída, Não Faturar e perfil. Números de teste têm filtro por exclusão habilitada/desabilitada. Limpar filtros restaura a visualização completa.
- Cada tabela tem **Exportar tabela (.xlsx)** e uma seleção entre registros filtrados e todos os registros. O padrão exporta todos os resultados dos filtros, inclusive os que estão nas outras páginas.
- A exportação usa os valores atuais da tela, incluindo alterações ainda não salvas e entradas incompletas. Isso permite exportar o trabalho em andamento sem gravar ou gerar o relatório mensal. Salvar o cadastro continua sendo uma operação separada.
- Os arquivos têm cabeçalhos fixos, filtros Excel, linhas alternadas e total de registros. Datas e valores completos são tipados; telefones e identificações mantêm zeros à esquerda e formatação. Conteúdo digitado permanece texto quando não é uma data ou valor válido; não vira fórmula.
- A pesquisa e a exportação não removem registros. Salvar continua enviando o cadastro inteiro. Confirmação de remoção, desfazer, recuperação pelo histórico e controle de edição concorrente foram mantidos.

## Verificação executada

- PHP 8.3: 105 verificações da regra de datas/exclusões, testes de cadastro/histórico e testes dos relatórios passaram. Ausência de motivo e todas as opções de motivo produzem o mesmo índice com as mesmas datas e marcações.
- Exportação independente: 93 verificações passaram, incluindo tipos de célula, caracteres especiais, marcas legadas, rascunhos incompletos, tabela filtrada vazia, telefones, totais e configurações de impressão.
- DOM com o JavaScript real e a página PHP renderizada: cenários de edição e somente leitura passaram. Verificados busca em todos os campos, filtros combinados, exportação de todas as páginas, valores ainda não salvos, falha de exportação, renovação de token, marcações preservadas e salvamento completo sob filtro.
- Relatório mensal completo: 6.567 chamados da evidência recebida, mesma base de telefonia usada na comparação anterior e cadastro real de 91 entradas. INS10 confirmou 2/80; os demais indicadores não mudaram.
- Word/Excel: 303 verificações estruturais Office passaram. Excel reaberto e recalculado no LibreOffice: 21.611 células comparadas, nenhuma diferença de fórmula e nenhum erro. Word renderizado, com revisão visual do indicador e da equipe/movimentações.
- Exportações independentes abertas por um leitor de planilhas e pelo LibreOffice. Revisão independente do código não encontrou bloqueios na regra, nos filtros ou no fluxo de exportação.

Microsoft Office nativo e a instalação GLPI do cliente não estavam disponíveis. Os testes usaram fontes fornecidas e ambiente local; nenhuma configuração de produção foi alterada. O novo endpoint usa a autenticação/permissão de acesso aos relatórios, POST/CSRF nativo e o nonce da página, sem gravar cadastros nem consultar os bancos de chamados ou telefonia.

## Instalação

Substitua a pasta ativa `g4freports/` pela do pacote, preservando as configurações. **Não desinstale para atualizar.** Confirme a versão **1.8.14**, reabra a área SES e gere novamente a competência para obter os resultados com as regras `ses-2026-09-23-v12`. Não inverta as marcações. O nome visível passa a ser **Não Faturar**, mantendo o significado de exclusão.

Os botões de exportação das tabelas funcionam sem gerar o relatório mensal. Alterações usadas em uma nova apuração mensal precisam ser salvas no cadastro antes de gerar o relatório. Rascunhos e arquivos já exportados permanecem como foram produzidos.
