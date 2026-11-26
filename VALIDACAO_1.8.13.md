# Versão 1.8.13 — marcações originais e cálculo do INS10

> Registro histórico: a exigência de classificação das saídas descrita neste documento foi removida na 1.8.14. Consulte `VALIDACAO_1.8.14.md` para a apuração atual; as marcações originais continuam preservadas.

## Correção aplicada

O preenchimento original do cliente sempre usou a marcação para excluir o profissional. A versão 1.8.12 inverteu a apresentação dessas marcações e manteve a interpretação incorreta do campo antigo. A versão 1.8.13 corrige a leitura e o cálculo:

| Marcação original | Não foi faturado? | Tratamento |
| --- | --- | --- |
| Marcada | Marcada | Excluído de todas as contribuições ao INS10 |
| Desmarcada | Desmarcada | Incluído conforme admissão, saída e competência |
| Campo ausente em cadastro antigo | Desmarcada | Incluído conforme as datas |

O campo interno explícito passa a ser `not_billed`. Ao ler um cadastro antigo, o valor de `billed` é copiado sem inversão; se os dois campos existirem, `not_billed` tem precedência. A normalização remove o nome antigo. Salvar, reabrir e recuperar uma versão do histórico não alternam as marcações. Mesmo um salvamento sem alterações grava o formato explícito, sem criar uma alteração fictícia no histórico.

Uma pessoa excluída permanece no cadastro histórico, mas não entra no efetivo ativo, no valor mensal, nas saídas, nas substituições ou nas pendências de classificação. Os demais registros continuam avaliados pelas datas da competência. Uma pendência elegível não apaga a contagem de profissionais ativos; a mensagem identifica os registros que ainda precisam de classificação.

Fórmula de rotatividade, janela de três meses, classificação das saídas e regra de fechamento mensal permanecem inalteradas. Não há números de profissionais ou resultados fixados no código. A marcação continua aplicável ao vínculo inteiro, sem histórico mensal de faturamento.

## Conferência dos dados fornecidos

O cadastro reconstruído da exportação tem 91 registros. Preservar suas marcações originais resulta em **9 exclusões e 80 registros ativos no fechamento de junho**. As nove saídas marcadas não geram pendências. Cinco ciclos de normalização e salvamento mantiveram todas as marcações originais.

Há também dois registros desmarcados com saídas em maio/junho. A exportação anterior ocultou a classificação dessas saídas ao excluí-los incorretamente. Não é possível recuperar essa classificação a partir do arquivo exportado. A reconstrução conservadora apresenta duas pendências, mantendo o efetivo em 80. Isso não comprova que o cadastro salvo no GLPI esteja sem classificação: se ela já estiver preenchida, a versão corrigida a utiliza normalmente. Portanto, esta conferência não afirma um percentual final para o cadastro real.

Os 80 são registros ativos, não uma certificação de pessoas únicas; vínculos históricos existentes não foram apagados ou deduplicados automaticamente.

## Verificação executada

- PHP 8.3: sintaxe dos 12 arquivos PHP alterados aprovada; teste de exclusão com 72 verificações, testes de cadastros/histórico e de apresentação Word/Excel sem falhas.
- Valores legados, valores explícitos, precedência, campo ausente, tipos inválidos, cinco ciclos de salvamento, saída antiga, admissão futura e competências históricas foram exercitados.
- JavaScript real executado no DOM da tela: marcação antiga preservada, marcar exclui, desmarcar inclui, novo profissional começa desmarcado e salvar/reabrir preserva o estado. Busca e paginação não eliminam registros; remoção, desfazer e recuperação continuam funcionando.
- Exportadores reais executados com a base de junho e uma equipe sintética de cinco registros. Resultado esperado e obtido: dois ativos, três excluídos, nenhuma saída pendente, valor mensal de R$ 5.000,00 e INS10 de 0/2 = 0%, meta atingida. Saídas excluídas sem classificação não bloquearam o indicador. Essa equipe é exclusivamente um teste.
- Estrutura Word/Excel: 303 verificações aprovadas. Excel reaberto e recalculado no LibreOffice: 21.611 células comparadas, nenhuma diferença de fórmula e nenhum erro. Word renderizado; cadastro, marcações, totais e texto de apuração revisados visualmente.
- Revisão independente do caminho entre leitura, configuração, tela, salvamento, histórico, cálculo e exportadores sem bloqueios encontrados.

Microsoft Office nativo e a instalação GLPI do cliente não estavam disponíveis. Nenhuma configuração de produção ou fonte GLPI/Asterisk foi alterada pelos testes.

## Atualização

1. Substitua a pasta `g4freports/` pela do pacote, mantendo o plugin instalado e as configurações existentes. **Não desinstale para atualizar.**
2. Confirme a versão **1.8.13** e reabra a página do relatório SES para descartar o formulário antigo.
3. Salve o cadastro de profissionais. As marcações originais permanecem como preenchidas; não inverta a lista manualmente. O salvamento passa a persistir o campo explícito de exclusão.
4. Gere novamente a competência e exporte os arquivos. As regras atuais são `ses-2026-09-23-v11`; rascunhos com regras anteriores precisam ser regenerados.

Se houve edição manual das marcações ou cadastro de novos registros sob a versão 1.8.12, o booleano antigo sozinho não distingue essas ações das marcações originais. A atualização não adivinha nem faz uma inversão global desses casos. O histórico de versões do cadastro permanece disponível para conferência e recuperação.
