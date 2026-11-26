# Validação 1.8.18

## Escopo implementado

- INS10: profissionais faturados em qualquer parte do mês, incluindo faturamento proporcional e desligamentos na competência. A janela de saídas continua sendo o mês e os dois anteriores. Meta máxima de 10%.
- Ajustes de dias faturados por profissional e competência, persistidos sem alterar datas do vínculo nem as marcações Não Faturar. Vazio restaura o automático, zero permanece um ajuste válido. Dias não podem exceder a interseção do vínculo com a competência.
- Índice alfabético, cadastro histórico preservado, relação mensal compacta no Word, motivos opcionais de saída retirados da apresentação e mantidos nos dados legados.
- Word: fonte vermelha para metas não atingidas, tabelas repetidas removidas, narrativas gerenciais, gráficos Meta × Resultado Apurado, TMA/TME e fontes da satisfação identificadas.
- Composição contratual configurável por frentes/perfis, faturamento proporcional e faixas de glosa. Ajustes financeiros específicos do mês exigem justificativa e não modificam o resultado operacional.
- Novas abas financeiras e de colaboradores, com fórmulas internas e auditoria preservada. Campos calculados e informados aparecem separadamente.

## Verificações executadas

- Sintaxe de todos os arquivos PHP e JavaScript alterados, com PHP 8.3 e Node.
- Quinze suítes PHP: contagem e rateio da equipe; exclusões e histórico; salvamento; pesquisas telefônicas; SLA; reaberturas e solucionador; apresentação, gráficos e exportações; composição financeira.
- Testes JavaScript do rateio em 37 cenários, além dos controladores reais da tela para paginação, busca, troca de mês, envio integral do cadastro, zero explícito, restauração do automático e preservação das marcações.
- Salvamento pelo endpoint real do plugin em processos PHP separados, com Config, sessão e transporte de banco simulados: criação/edição em páginas diferentes, ajustes em dois meses, revisão concorrente, JSON inválido, permissões, nonce, método HTTP, falha de persistência e de liberação do bloqueio. Usa a API nativa de bloqueio do GLPI, sem SQL direto nesse fluxo.
- Financeiro: limites exatos de todas as faixas e valores imediatamente acima/abaixo; glosa calculada distinta da aplicada; ajustes positivos/negativos; ausência de vazamento entre meses; cálculo parcial quando há evidência realmente indisponível; formatação monetária brasileira.
- Reabertura e recálculo do Excel no LibreOffice: nenhum erro de fórmula nas 21 abas; totais de faturamento e glosa conciliados com a apuração PHP. Cenários adicionais com dias ajustados, decisão financeira explícita e indicador provisório.
- Prova de cálculo efetivo: em uma cópia de teste, um ajuste de dias foi alterado para zero e todos os resultados de fórmula em cache foram removidos antes da avaliação. O Excel recalculou INS10 de 8/69 para 8/68, preservou as oito saídas e os outros onze indicadores, e atualizou faturamento, glosas, detalhe e auditoria pela mesma linha do profissional.
- XML e relacionamentos internos dos pacotes Word/Excel válidos; ausência de referências a pastas de trabalho externas. Gráficos com séries, percentuais, comparadores, legendas e eixos consistentes.
- Word renderizado e inspecionado visualmente: 23 páginas no cenário de junho, com cabeçalhos repetidos, valores legíveis, ausência de sobreposição e gráficos íntegros. Cenário adicional com sete frentes e ajustes financeiros também verificado.

## Comparação de junho

A reexecução usa as linhas e valores preservados no Excel mais recente fornecido: 6.567 chamados, 76 ligações já filtradas e 90 vínculos históricos. É uma conferência baseada na exportação, não uma nova consulta aos bancos de produção. Os históricos e calendários brutos completos não estavam disponíveis para reexecutar a aquisição.

| Indicador | Quantidade apurada / base |
|---|---:|
| INS1 | 1 / 1 |
| INS2 | 1 / 1 |
| INS3 | 2 / 12 |
| INS4 | 6.123 / 6.223 |
| INS5 | 251 / 6.567 |
| INS6 | 4.601 / 5.381 |
| INS7 | 69 / 76 |
| INS8 | 73 / 76 |
| INS9 | 3 / 76 |
| INS10 | **8 / 69 (11,59%)** |
| INS11 | 5.973 / 6.567 |
| INS12 | 664 / 785 |

Os onze indicadores de chamados/telefonia permanecem iguais à reexecução da versão 1.8.17. Somente o INS10 muda de 8/67 para 8/69. O quadro mensal contém 82 vínculos: 69 com dias faturados e 13 desconsiderados. Os oito desligamentos na janela são discriminados separadamente; o histórico de 90 vínculos permanece salvo.

## Precisão financeira e decisões manuais

O valor mensal cadastrado mantém duas casas decimais. O valor proporcional é arredondado ao centavo por profissional, sem arredondar antecipadamente a diária. Cada glosa é arredondada ao centavo antes da soma. O líquido é o bruto menos a soma das glosas mais os ajustes informados.

No cenário privado de teste com os quatro ajustes de dias documentados pelo cliente, a soma foi de 1.968 dias, mantendo 69 profissionais faturados. Com os valores mensais de duas casas da base do plugin, o bruto foi de R$ 463.503,18. Planilhas que usam taxas com mais casas decimais ou totais digitados podem apresentar diferença de centavos; esses totais não foram fixados no código.

Sem decisão financeira informada, as faixas resultam em 4% para INS3, 1% para INS5 e 1% para INS10 nesse cenário. A solicitação de afastar a glosa do INS3 não é tratada automaticamente como decisão aprovada. Informar uma glosa aplicada de 0% com justificativa mantém o resultado técnico de 2/12 e discrimina o ajuste financeiro.

Os dados de clientes, os ajustes de dias e as decisões usadas nos testes privados não fazem parte deste pacote nem são preenchidos como padrões.

## Atualização e limites

Substitua os arquivos da pasta `g4freports`, sem desinstalar ou apagar configurações. Recarregue a tela, confirme a versão 1.8.18 e gere novamente a competência. A regra `ses-2026-09-30-v15` impede a exportação de rascunhos anteriores como se já contivessem os novos cálculos.

As conexões, consultas de chamados/telefonia e seleção da nota telefônica não foram alteradas. Não houve migração ou inversão das marcações existentes. Novos campos opcionais são gravados somente quando preenchidos. Permanecem os limites de armazenamento com erro explícito, sem truncamento silencioso.

Não foi realizada instalação no GLPI do cliente, conexão aos bancos de produção nem validação na aplicação Microsoft Office instalada pelo cliente. As verificações de abertura, recálculo e renderização usaram LibreOffice e validação estrutural dos pacotes OOXML.
