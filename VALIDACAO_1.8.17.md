# Versão 1.8.17 — faturamento mensal dos profissionais

## Comportamento entregue

- A interface apresenta Dias Faturado, Valor Faturado e Status e recalcula esses campos ao alterar a competência, admissão, saída, valor mensal ou Não Faturar. Busca e exportação independente contemplam os valores calculados.
- Primeiro dia faturado = maior data entre admissão e início do mês. Último dia faturado = menor data entre saída, quando informada, e fim do mês. A contagem inclui ambos os dias e considera o número real de dias do mês, inclusive fevereiro bissexto.
- Valor Faturado = Valor mensal × Dias Faturado ÷ dias da competência. O arredondamento para centavos ocorre uma vez por profissional, ao final; o valor mensal cadastrado permanece intacto.
- Não Faturar marcado mantém a exclusão: dias e valor faturados iguais a zero. Também não há faturamento quando o vínculo não alcança o mês. Não há inversão de marcações nem migração do cadastro.
- Status é Inativo quando existe data de saída registrada e Ativo quando não existe. É uma informação descritiva; uma saída posterior à competência não exclui o profissional de cálculos históricos.
- O Word inclui profissionais que alcançam o mês ou têm saída no mês e nos dois anteriores. Saídas antigas e admissões futuras deixam de aparecer no quadro principal. Os desconsiderados permanecem em quadros separados.
- O Word usa o rótulo Profissional desconsiderado? (Não Faturado), datas inicial/final faturadas, dias, valores mensal/faturado, status e totais. Quadros de identificação e faturamento separados mantêm a legibilidade no papel timbrado G4F em retrato.
- O Excel mensal inclui os campos calculados e totais; a auditoria conserva o histórico completo. A exportação independente identifica a competência e calcula os valores no servidor a partir dos campos originais, sem confiar em valores calculados enviados pelo navegador.
- Campos derivados não são persistidos no cadastro. A equação do INS10 continua sendo saídas elegíveis na janela de três meses divididas pelo efetivo elegível no fim da competência, multiplicadas por 100. O motivo da saída não impede o cálculo.
- A seleção de satisfação telefônica da versão 1.8.16 e as correções de salvamento são preservadas.

## Verificações executadas

- PHP: 158 verificações de faturamento mensal; JavaScript: 25 casos compartilhados, com 150 verificações. Incluem meses completos, admissão/saída no meio do mês, datas futuras/antigas, um único dia, fevereiro bissexto, centavos, exclusões e edição incompleta.
- Preservação das marcações: 105 verificações aprovadas. Testes dos cadastros também aprovados.
- Word: 33 verificações de seleção mensal, histórico, exclusões, datas, valores, totais e largura das tabelas. Regressão de apresentação Word/Excel aprovada.
- Excel e exportação independente: 45 verificações dos campos de faturamento, fórmulas, totais, tipos numéricos, filtro completo e entradas incompletas; 93 verificações anteriores de exportação aprovadas.
- Salvamento: 93 verificações do endpoint com métodos oficiais de bloqueio do GLPI 11.0.7 e transporte de banco simulado. Navegação entre páginas, edição antes de salvar, repetição após falha, modo de leitura, busca e exportação foram verificados em DOM simulado.
- Satisfação telefônica: 59 verificações de regressão aprovadas.
- Reprodução integral de junho com 6.567 chamados, 76 ligações e 91 profissionais: os doze indicadores mantiveram exatamente os resultados da versão 1.8.16. INS10 = 2/80 = 2,50%; INS12 = 664/785 = 84,59%.
- Na base de referência: 82 profissionais no quadro principal, nove desconsiderados, 81 com dias faturados no mês e 2.395 dias faturados. Os valores mensais dessa base estão zerados; a validação de rateio monetário utilizou casos sintéticos com valores conhecidos.
- 309 verificações estruturais Word/Excel aprovadas. Recálculo no LibreOffice: 21.625 células de fórmulas verificadas, sem diferenças nem erros.
- Word renderizado no LibreOffice e páginas dos quadros de profissionais inspecionadas visualmente. Arquivos PHP e JavaScript alterados passaram pelas verificações de sintaxe.

Não houve acesso a uma instalação GLPI em produção ou ao Microsoft Office nativo neste ambiente. As verificações usam a base fornecida, casos sintéticos, DOM simulado, estrutura OOXML e LibreOffice.

## Instalação

Substitua a pasta ativa `g4freports/` pela pasta do pacote, sem desinstalar. Confirme a versão **1.8.17** e gere novamente a competência para obter os campos calculados nas regras `ses-2026-09-23-v14`. Cadastros históricos, marcações Não Faturar, números de teste, credenciais e conexões permanecem preservados.
