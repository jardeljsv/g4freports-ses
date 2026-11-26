# Versão 1.8.12 — exclusão por não faturamento

> Registro histórico: a interpretação das marcações descrita neste documento foi corrigida na versão 1.8.13. Para atualizar, use `VALIDACAO_1.8.13.md`; não aplique a inversão descrita abaixo.

## Comportamento

O controle se chama **Não foi faturado?**:

- **Desmarcado:** o vínculo participa do INS10 conforme suas datas e classificação de saída.
- **Marcado:** o vínculo permanece no cadastro histórico, mas é excluído da equipe, das saídas, das substituições e do valor mensal do INS10.
- Novos vínculos começam desmarcados e incluídos.

O armazenamento continua utilizando o campo `billed`, com o mesmo significado das versões anteriores. Não há alteração automática dos registros existentes, inclusive no histórico de recuperação.

| Opção antiga — Foi faturado? | Opção nova — Não foi faturado? | Participação preservada |
| --- | --- | --- |
| Marcada | Desmarcada | Incluído conforme as datas |
| Desmarcada | Marcada | Excluído |
| Cadastro antigo sem o campo | Desmarcada | Incluído conforme as datas |

A inversão visual não corrige escolhas anteriores feitas com outro entendimento. Confira os vínculos efetivamente não faturados antes de salvar. Não marque alguém somente porque saiu da empresa: a data de saída já controla sua participação em cada competência.

A opção continua valendo para todo o vínculo; não foi criado um histórico mensal de faturamento. A fórmula de rotatividade, a janela de três meses, a referência no último dia da competência e a classificação de saída permanecem inalteradas. Saídas faturadas sem classificação dentro da janela continuam exigindo classificação; mudar o nome do checkbox não resolve essa pendência.

## Verificação executada

- PHP 8.3: sintaxe dos 11 arquivos PHP alterados; testes de cadastros/histórico e novo `tests/billing_presentation_test.php` sem falhas.
- JavaScript real executado no DOM da tela: faturado existente aparece desmarcado, não faturado existente aparece marcado e registro antigo sem campo mantém inclusão. Marcar salva exclusão; desmarcar salva inclusão. Novo vínculo começa incluído. Salvar sob busca/paginação preserva os demais registros; remoção, desfazer, duplicatas e recuperação continuam funcionando.
- Word e Excel usam a coluna **Não foi faturado?**, com **Sim** para excluído e **Não** para incluído conforme as datas. Os testes verificam os valores e totais, não somente o texto do cabeçalho.
- Excel: `COUNTIFS` e `SUMIFS` da auditoria ajustados para a coluna invertida; a tabela cobre as 13 colunas existentes. Não foram alteradas as fórmulas dos indicadores.
- Exportadores reais executados com a base de junho e um cadastro de pessoal sintético contendo inclusão, exclusão, registro legado, substituição e saída não faturada sem classificação. A amostra produz 2 profissionais ativos faturados, 1 substituição e R$ 5.000,00; a saída não faturada não gera pendência. Esses números são testes e não dados inseridos no plugin.
- Estrutura Office: 303 verificações sem falhas. Excel reaberto e recalculado no LibreOffice: 21.611 células comparadas, zero diferenças e zero erros. Word renderizado; tabela de profissionais, coluna invertida e totais revisados visualmente.

Microsoft Office e a instalação GLPI do cliente não estavam disponíveis. A validação usa PHP local, DOM executável, estrutura OOXML e LibreOffice; nenhuma fonte GLPI/Asterisk ou configuração de produção foi alterada.

## Atualização

Substitua a pasta `g4freports/` pela do pacote, mantendo o plugin instalado e as configurações existentes. Não desinstale. Confirme a versão **1.8.12**, reabra a área SES, confira **Não foi faturado?**, salve se houver ajustes e gere novamente a competência. As regras são `ses-2026-09-23-v10`; rascunhos anteriores precisam de nova geração para os textos atualizados.
