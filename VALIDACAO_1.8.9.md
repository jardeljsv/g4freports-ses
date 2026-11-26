# Validação da atualização 1.8.9

Data: 21/09/2026. Regras: `ses-2026-09-21-v7`.

Esta versão corrige a exclusão indevida de chamados calculáveis e acrescenta a seleção persistente de profissionais faturados. As verificações usaram o código PHP da atualização e os dados fornecidos, sem consultas ou alterações no GLPI de produção.

## Apuração de SLA

O prazo registrado no próprio chamado é usado quando o SLA vinculado corresponde à meta da prioridade e ao calendário aplicável. Na ausência de prazo ou quando a duração cadastrada difere da meta do relatório, o cálculo usa a meta, o calendário aplicável e os contadores de espera. Histórico é uma fonte complementar, não uma condição obrigatória para todo chamado.

Clonagem, abertura retroativa e mudanças de grupo, categoria ou SLA não impedem a apuração por si sós. Os contadores de duração e as observações de histórico continuam disponíveis para conferência. Um contador limitado a zero pelo GLPI não é apresentado como prova de atendimento instantâneo.

O calendário do SLA vinculado tem precedência sobre a descoberta por grupo, mantendo a seleção explícita de calendário quando informada. As metas por código de prioridade continuam 2 = 8h, 3 = 6h, 4 = 4h e 5 = 2h. Os dois mapeamentos INS permanecem selecionáveis.

## Reprodução de junho

Foram usados os 6.567 chamados e 99 registros de ligações da extração fornecida, com a mesma seleção por solução, grupos, categorias e exclusão de um número de teste. O cenário usa INS1→5, INS2→4, INS3→3 e INS4→2, conforme o relatório de junho analisado.

| Indicador | Quantidade que atende à condição | Base de cálculo | Situação |
| --- | ---: | ---: | --- |
| INS1 | 1 | 1 | Meta atingida |
| INS2 | 1 | 1 | Meta atingida |
| INS3 | 3 | 12 | Meta não atingida |
| INS4 | 6.154 | 6.223 | Meta atingida — 98,8912% |
| INS5 | 264 | 6.567 | Meta não atingida |
| INS6 | 4.600 | 5.381 | Meta atingida |
| INS7 | 91 | 98 | Meta atingida |
| INS8 | 94 | 98 | Meta atingida |
| INS9 | 3 | 98 | Meta atingida |
| INS11 por categoria | 5.973 | 6.567 | Meta atingida |
| INS12 GLPI e telefonia | 665 | 785 | Meta atingida |

Os quatro indicadores de prazo têm cobertura de 100% no cenário: 6.237 chamados no conjunto de seus recortes, sem pendência de cálculo. Os 245 casos antes pendentes no INS4 passaram a ser medidos: 34 pelo prazo registrado, 190 pelo prazo calculado e 21 pela regra N2 com espera zero. Destes, 212 ficam no prazo e 33 fora do prazo. Três chamados anteriormente classificados fora do prazo também foram corrigidos pela aplicação consistente do prazo do próprio SLA.

Os totais dos demais indicadores permanecem iguais. INS10 não foi comparado ao relatório gerencial, pois o cadastro completo de profissionais não estava disponível; seu comportamento foi validado com casos específicos de faturamento e movimentação.

O Excel fornecido não contém o histórico bruto completo de junho nem as datas técnicas de criação. Por isso, a reprodução não inventa esses dados: 58 chamados N3 anteriormente reconstruídos não podem ser reproduzidos pelo histórico nesse conjunto de teste. Todos estão fora dos recortes INS1–INS4. Isso não altera a cobertura dos indicadores; contagens de auditoria da base geral ficam separadas das contagens do recorte.

## Profissionais faturados

- O checkbox **Foi faturado?** é salvo no mesmo cadastro persistente de profissionais.
- Somente marcados compõem o efetivo no fechamento, as saídas/substituições na janela de três meses e a soma de valores mensais do INS10.
- Desmarcados permanecem salvos e aparecem na auditoria. Uma saída sem classificação de profissional não faturado não bloqueia o indicador.
- Registros existentes sem o campo são tratados como marcados, preservando a seleção anterior. Novos registros começam desmarcados.
- Um cadastro preenchido somente com não faturados resulta em população zero e **Não aplicável**, sem transformar isso em rotatividade zero ou pendência de classificação.
- A marcação é persistente e se aplica às novas extrações; não constitui um histórico mensal de faturamento. Cada rascunho preserva a seleção usada quando foi gerado.

Passaram os casos de persistência do booleano, preservação de cadastros antigos, alteração da revisão do cadastro, efetivo faturado, saída de não faturado, saída sem classificação, substituição na janela de três meses, soma de valores e reutilização em meses posteriores.

## Evidências e situações

As tabelas principais contêm a população de cada indicador, incluindo resultados desfavoráveis. INS6 não fica limitado aos atendimentos N1 que compõem a condição positiva; inclui todos os elegíveis ao cálculo. INS7–INS9 incluem as ligações recebidas consideradas, inclusive abandonos. INS12 contém as respostas válidas das fontes selecionadas. A base completa e a auditoria permanecem disponíveis.

As tabelas de reabertura, elegibilidade e conhecimento não exibem situação de SLA como se fosse parte dessas métricas. INS11 por categoria e a exclusão normal de notas inválidas no INS12 não tornam seus resultados provisórios. A escolha de um método e alterações ordinárias no histórico também não alteram a situação de uma meta calculável. Falta real de dados de uma fonte selecionada continua identificada.

## Verificações de código

Executadas no PHP 8.3.33 isolado: apuração operacional de SLA, prazos compatíveis e ausentes, alvo diferente do SLA cadastrado, precedência e herança de calendários, seleções explícitas, contadores excedentes, clones, limites de tempo, registros realmente incompletos, extração de metadados GLPI 10/11, telefonia e INS12 preservados, cadastros persistentes, faturamento e rejeição de rascunhos com regras antigas. A análise de sintaxe passou nos 53 arquivos PHP e nos quatro JavaScript.

## Verificações das exportações

Foram gerados Word e Excel pelo próprio código PHP, tanto com a população de junho quanto com um cadastro sintético de oito profissionais, incluindo faturados, não faturados, saída na janela, admissão futura e vínculo encerrado antes da janela.

- As tabelas efetivamente gravadas no Excel conferem com os recortes: INS1 = 1, INS2 = 1, INS3 = 12, INS4 = 6.223, INS5 = 6.567, INS6 = 5.381, INS7–INS9 = 98 por aba, INS11 = 6.567 e INS12 = 785.
- No caso de pessoal, quatro registros relevantes aparecem na evidência do INS10 e os oito permanecem na auditoria. Somente dois profissionais faturados compõem o efetivo de fechamento e o valor somado de R$ 12.000,99. A saída não faturada sem classificação não gera pendência.
- Fórmulas e valores armazenados do INS10 conferem com as colunas de efetivo, substituição e valor mensal, sem referenciar os profissionais excluídos da auditoria.
- Pacotes ZIP, arquivos XML, destinos dos relacionamentos, intervalos de tabelas e estilos de evidência foram conferidos. O Excel de junho conserva 16 gráficos nativos e fundos claros nas evidências. Os geradores de objetos de gráfico não foram alterados.
- O Word do cenário de pessoal foi renderizado para verificar as páginas alteradas e a coluna de faturamento. A paleta e o timbre G4F foram preservados.

A inspeção local não executa o Microsoft Word/Excel desktop do usuário. As exportações de teste não integram o pacote instalável.

## Atualização

1. Substitua a pasta ativa `g4freports/` pela pasta do pacote, preservando proprietário e permissões do servidor.
2. **Não desinstale:** as conexões, números de teste e profissionais já salvos devem ser mantidos.
3. Execute a atualização na área de plugins se o GLPI a oferecer e confirme a versão **1.8.9**.
4. Reabra a tela SES e revise **Foi faturado?**. Cadastros anteriores estarão marcados; desmarque os profissionais que não devem participar e clique em **Salvar profissionais**.
5. Gere novamente a competência e exporte novos Word e Excel. Rascunhos e arquivos anteriores não são recalculados pela substituição do código.

O pacote contém o plugin completo, sem bases de teste, relatórios com dados operacionais, credenciais ou dependências do ambiente de validação. A conexão real, o PHP do servidor e a abertura no Microsoft Office do ambiente de destino não foram executados nesta validação local.
