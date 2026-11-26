# Versão 1.8.16 — seleção da satisfação telefônica

## Regra aplicada

A extração busca `atendimento` e, quando existente, `nota` na mesma resposta de `tab_pesquisa`. Mantém a resposta mais recente, por data e ID, com pelo menos um desses campos não nulo, vinculada ao identificador da ligação. A ausência da coluna `nota` é compatível com a regra anterior e não indisponibiliza o INS12.

O recorte contém as ligações das filas selecionadas que entraram no mês. Antes das exclusões de números de teste, compara pares de valores válidos (inteiros de 1 a 5). Se um mesmo `atendimento` corresponde a mais de uma `nota` distinta, prioriza `nota` sem inversão em toda a competência. Não há regra fixa para junho nem limiar arbitrário de satisfação média. Valores inválidos não provocam a mudança de critério.

Sem essa correspondência múltipla, conserva `6 − atendimento`. No modo de prioridade de `nota`, respostas com `nota` inválida/ausente usam `6 − atendimento`. Um `atendimento` inválido/ausente pode ser recuperado por `nota` válida. Se ambos estão inválidos ou ausentes, não há nota válida para a fração; respostas ausentes continuam separadas de respostas inválidas.

A detecção anterior às exclusões mantém estável o diagnóstico de origem da competência. Números de teste continuam excluídos dos indicadores. Respostas em várias filas contam uma única vez por identificador da ligação. Não há estado compartilhado entre competências.

Ambos os valores de origem são preservados. O resultado é resolvido uma única vez e usado pela tela, pelo INS12, pelas distribuições, pelo Word e pelo Excel. As tabelas de evidência registram a origem escolhida e o motivo. Colunas novas da base Excel foram adicionadas ao final, mantendo as posições anteriores e suas fórmulas.

## Verificações executadas

- 59 verificações sintéticas aprovadas: mês com correspondência múltipla, meses normais, nota ausente/inválida, atendimento ausente/inválido, resposta vazia, evidência anterior, uma resposta em várias filas, repetição sem dupla inversão, exclusão de teste e variantes SQL com/sem a coluna adicional.
- Regressão de apresentação aprovada: separação das fontes, resposta não preenchida, inválida e indisponível.
- Reprodução integral de junho: 6.567 chamados e 76 ligações da referência, enriquecidas pelos campos originais das extrações fornecidas. As 76 ligações foram vinculadas às extrações existentes.
- Telefonia: nota 1 = 1 resposta; nota 4 = 5; nota 5 = 40; 30 ligações sem resposta. Total válido = 46; favoráveis = 45; resultado telefônico = 97,83%.
- INS12 combinado: GLPI 619/739 mais telefonia 45/46 = 664/785 = 84,59%. Outros onze indicadores preservam os resultados anteriores, inclusive INS10 2/80 na base de conferência.
- 309 verificações estruturais Word/Excel aprovadas: XML, relacionamentos, gráficos, valores e participação nas tabelas.
- Todos os 76 registros conferidos no Excel: nota final, valor usado pelo INS12 e valor direto de `nota` coincidem nas respostas válidas.
- Recálculo do Excel no LibreOffice: 21.619 células verificadas, sem diferença de fórmula ou erro.
- Word renderizado no LibreOffice; páginas alteradas do INS12 inspecionadas visualmente. PHP 8.3 e JavaScript alterados passaram pelas verificações de sintaxe.

A consulta de produção não foi executada a partir deste ambiente. Microsoft Office nativo não estava disponível. Os cálculos foram testados com dados fornecidos e casos sintéticos; o recálculo e a renderização foram feitos no LibreOffice.

## Instalação

Substitua a pasta ativa `g4freports/` pela pasta do pacote, sem desinstalar. Confirme a versão 1.8.16 e gere novamente a competência. As regras agora são `ses-2026-09-23-v13`: rascunhos anteriores precisam ser extraídos novamente para obter ambos os campos. Cadastros, marcações Não Faturar, números de teste, credenciais e regras do INS10 não são modificados. O sistema Django de telefonia usado como referência não foi alterado.
