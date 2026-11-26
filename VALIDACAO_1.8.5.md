# Validação da versão 1.8.5 — checklist do cliente

Base: pacote funcional 1.8.4. Solicitações: `Simple checklist.xlsx`, itens 1–5, 10 e 11. A entrega é um pacote para atualização; não houve implantação nem consulta aos bancos de produção nesta revisão.

## Cobertura das solicitações

| Item | Resultado implementado |
| --- | --- |
| 1 — INS3 | Exclui vínculo técnico atual N3 do numerador, denominador e gráfico; evidências mantidas na base e auditoria. |
| 2 — INS4 | Mesma exclusão específica, respeitando a prioridade associada a INS4 na extração. |
| 3 — categorias INS6 | Verificadas e mantidas nos padrões as categorias 162, 149 e 176, já presentes entre as 44. IDs e nomes completos são conferidos na fonte. |
| 4 — N3 no INS6 | Exclui N3 de ambas as parcelas; vínculo simultâneo N1/N3 também é excluído. Categoria elegível e elegibilidade efetiva ficam identificadas separadamente. |
| 5 — INS9 | Duas casas decimais na prévia, tabela e resumo de ambos os formatos, incluindo rótulos percentuais de comparação no Excel. |
| 10 — metas máximas | Conferidos INS5 ≤ 2,00%, INS9 ≤ 5,00% e INS10 ≤ 10,00%, mantendo fórmula e sentido já corretos na 1.8.4. |
| 11 — pesquisa telefônica | Apresenta disponibilidade da fonte, quantidades, motivo da separação do INS12 e providências. Campo próprio permite registrar acompanhamento gerencial. Não presume validação nem altera a fórmula do INS12. |

Os itens acima descrevem a implementação técnica. Não representam aprovação da contratante ou preenchimento de sua coluna de validação no checklist.

## Conferência das amostras fornecidas

As duas bases foram reconstruídas a partir das planilhas antigas fornecidas, com os IDs reais de grupos obtidos do catálogo Q02. Nomes, títulos e telefones pessoais foram substituídos nas fixtures locais; nenhum dado de produção acompanha o ZIP do plugin. As fixtures não inventam ID/data nem notas originais da pesquisa que não existam nos arquivos antigos.

| Competência | Chamados na base completa | N3 excluídos do INS6 | INS6 anterior (1.8.4) | INS6 atualizado |
| --- | ---: | ---: | --- | --- |
| Junho/2026 | 6.567 | 58 | 4.600 / 5.439 = 84,57% | 4.600 / 5.381 = 85,49% |
| Agosto/2026 | 6.342 | 63 | 4.328 / 5.142 = 84,17% | 4.328 / 5.079 = 85,21% |

Os totais originais e os demais resultados dos dois meses coincidem com a 1.8.4 usando as mesmas fixtures. INS3 e INS4 permanecem iguais nessas amostras com o mapeamento inicial (prioridades 4 e 5), pois não contêm N3 nessas prioridades. Isso não indica falha de exclusão. O mapeamento alternativo é tratado pelos IDs dos indicadores; não se inverte silenciosamente a associação para produzir outro resultado.

As amostras possuem 46 respostas telefônicas vinculadas em junho e 29 em agosto, respectivamente em 99 e 91 ligações distintas. Essas quantidades descrevem os arquivos fornecidos, não o estado atual dos bancos. As respostas telefônicas continuam separadas do numerador e denominador GLPI do INS12.

## Verificações executadas

- PHP 8.3.33 em runtime local PHP.wasm: os 48 arquivos PHP passam na verificação de sintaxe. JavaScript SES passa em `node --check`.
- Tela SES renderizada pelo PHP com configuração e sessão GLPI simuladas: campos presentes, sem IDs de elementos duplicados e sem erros PHP. Funções reais de coleta e controle executadas em Node: N3 e acompanhamento coletados, botões habilitados/bloqueados conforme o estado e INS9 apresentado como 3,03%. Não houve teste interativo de navegador.
- 28 verificações de regressão: configuração N3, sobreposição N1/N3, mapeamento padrão/alternativo, exclusão antes da classificação de prazo ausente, população inteiramente N3, metas máximas, deduplicação de pesquisas entre filas, números de teste, respostas fora da faixa, fonte indisponível, atualização narrativa sem alterar cálculos e rejeição de rascunhos v2.
- Gerados Word e Excel para junho/agosto e três cenários sintéticos, totalizando 10 arquivos de teste. Nenhum arquivo de teste acompanha o pacote.
- Pacotes OOXML: 107 gráficos nativos, 2.170 valores entre séries e células de origem e 596 relacionamentos internos verificados. XMLs e destinos de relacionamentos válidos; sem gráficos de linha; preservadas as regras de rótulos em séries e as restrições de barras/roscas.
- Conferidas 13.902 condições de dados, evidências, fórmulas e precisão nos cinco arquivos Excel: denominadores por registro, composição após N3, sinais de metas e formato de duas casas decimais do INS9 no painel e na aba individual. Não houve recálculo no Excel desktop.
- As variantes da consulta SQL com e sem pesquisa telefônica foram inspecionadas por reflexão: seleção de aliases novos, resposta não nula mais recente e junção por identificador preservadas. A consulta não foi executada em banco real neste ambiente.

## Limites e atualização

A classificação N3 usa os vínculos técnicos presentes na extração. Não reconstitui o grupo que atendeu historicamente o chamado. A lista inicial contém os nove grupos N3 identificados no catálogo fornecido: 57, 58, 59, 60, 1663, 3294, 3300, 3304 e 3306.

A consulta de pesquisas telefônicas mantém a seleção da resposta não nula mais recente por `uniqueid`, sem limitar sua data ao mês da entrada da ligação. Agora preserva também o valor original e o ID/data da resposta. Sem acesso à fonte, a quantidade de respostas é indisponível, não zero.

As correções estruturais de gráficos da 1.8.4 foram mantidas; a única alteração no gerador nativo de gráficos Excel é a máscara percentual de duas casas decimais. A validação de pacote e cálculos não equivale à abertura em Word/Excel desktop nem a um teste de conectividade no servidor GLPI.

Atualize a pasta do plugin sem desinstalar, confirme a versão 1.8.5 e gere novamente a competência. Rascunhos com regras v2 não podem ser exportados como se já contivessem a exclusão N3: a nova extração usa `ses-2026-07-31-v3`.
