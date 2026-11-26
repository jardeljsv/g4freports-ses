# Validação 1.8.6 — INS12 com fontes selecionáveis

Base: versão 1.8.5 entregue anteriormente. Mudança solicitada: incluir normalmente as respostas de pesquisas telefônicas no INS12 por padrão, com opção de desativar sua participação.

## Comportamento

Em **Critérios dos indicadores → INS12 · Fontes da pesquisa de satisfação**:

| Opção | Cálculo |
| --- | --- |
| Chamados GLPI e pesquisas de telefonia — padrão | Soma das respostas favoráveis das duas fontes ÷ soma das respostas válidas das duas fontes × 100 |
| Somente chamados GLPI | Respostas GLPI favoráveis ÷ respostas GLPI válidas × 100 |

Não se faz média dos percentuais por fonte. Cada resposta válida tem o mesmo peso. GLPI: notas inteiras de 1 a 5, com 4 e 5 favoráveis. Telefonia: mantida a conversão já existente `6 − nota original`; portanto notas originais 1 e 2 tornam-se 5 e 4, favoráveis.

A telefonia conta uma resposta por identificador único de ligação, após excluir os números de teste. A mesma ligação em várias filas não duplica a avaliação. A consulta SQL continua selecionando a última resposta não nula por identificador; a primeira entrada nas filas selecionadas dentro da competência representa essa resposta na distribuição diária. A seleção dos chamados continua por solução; a das ligações, por entrada na fila. A data da resposta não substitui esses critérios.

Ausentes e inválidas não entram no denominador. Inválidas observadas em uma fonte selecionada tornam o resultado provisório quando há outras respostas válidas. Fonte selecionada indisponível deixa o INS12 pendente, sem transformar a informação parcial em resultado completo. No modo somente GLPI, a disponibilidade da pesquisa telefônica não interfere no INS12.

A seleção aparece na prévia, nos relatórios e na metodologia. As contagens por fonte são apresentadas separadamente para permitir conferência. O gráfico de notas, as fórmulas de total e os valores diários do Excel usam as mesmas evidências da medição. Os dados de telefone continuam disponíveis para conferência mesmo quando sua participação está desativada.

## Conferência dos arquivos fornecidos

| Mês | GLPI favoráveis/válidas | Telefonia favoráveis/válidas | Padrão combinado | Somente GLPI |
| --- | --- | --- | --- | --- |
| Junho/2026 | 619/739 | 46/46 | 665/785 = 84,71% | 83,76% |
| Agosto/2026 | 537/629 | 29/29 | 566/658 = 86,02% | 85,37% |

Valores reconstruídos dos arquivos fornecidos, não de consultas aos bancos atuais. Os arquivos antigos continham somente a nota telefônica já convertida; não foram inventadas notas originais para essas fixtures. Casos sintéticos verificam separadamente a conversão das notas originais.

INS1–INS11 e todos os totais operacionais coincidem com a 1.8.5 nas mesmas amostras. Mantidas as exclusões N3, as 44 categorias, as metas, os números de teste, a identidade visual, as 18 abas e a estrutura dos geradores de gráficos.

## Verificações locais

- PHP 8.3.33 via PHP.wasm: 48 arquivos PHP sem erros de sintaxe; JavaScript SES validado com `node --check`.
- 39 verificações focadas: seleção padrão e explícita, modo somente GLPI, conversão de notas, deduplicação entre filas, colisão entre ID numérico de chamado e ID de ligação, ordem dos registros, números de teste, inválidas, ausentes, fonte indisponível e rascunhos anteriores.
- Atualização narrativa conferida nos dois modos: altera o acompanhamento e mantém a seleção, os cálculos e a identificação correta da participação telefônica. Uma tentativa de alterar a seleção através da atualização de textos não modifica a fonte escolhida.
- 9 Word e 9 Excel gerados: junho/agosto nos dois modos e cinco cenários sintéticos.
- 1.205 conferências independentes de bases, evidências e fórmulas dos nove Excel, incluindo soma das fontes, `SUMIFS` diários, histogramas, contagens e preservação de INS1–INS11.
- Pacotes OOXML: 232 gráficos, 6.163 valores de cache vinculados às células de origem e 1.208 relacionamentos internos verificados. Preservadas as restrições de rótulos que corrigiram os reparos do Office na 1.8.4.
- Tela PHP renderizada com configuração/sessão GLPI simuladas. Funções JavaScript reais verificadas em ambiente de teste: padrão correto, envio das duas opções, alteração exigindo nova geração e identificação correta das fontes na prévia. Sem navegador interativo.

Não houve conexão aos bancos de produção, execução no GLPI de destino ou abertura/recálculo nos aplicativos Microsoft Office desktop. A inspeção estrutural dos pacotes não equivale a esses testes.

## Atualização

Substitua a pasta do plugin sem desinstalar, confirme a versão **1.8.6**, revise as fontes do INS12 e gere novamente o mês. As regras são `ses-2026-07-31-v4`; rascunhos anteriores devem ser regenerados antes da exportação. Relatórios profissionais via API e credenciais existentes permanecem preservados.
