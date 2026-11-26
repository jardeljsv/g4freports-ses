# Validação 1.8.24 — 26/10/2026

## Escopo e preservação

Esta atualização acrescenta a reexportação mensal completa das revisões arquivadas, compartilhamento explícito por grupos do GLPI hospedeiro e valores/legendas legíveis nas comparações Word e Excel. O histórico continua em arquivos persistentes, sem migração dos snapshots para o banco.

As consultas operacionais, os cálculos, as regras `ses-2026-10-05-v16`, os cadastros e suas importações permanecem preservados. Os gráficos anuais continuam consumindo os valores arquivados; não criam médias anuais de indicadores nem recalculam meses antigos.

O novo perfil `ses-monthly-v1` preserva 13 componentes de apresentação e o modelo Word da base 1.8.23. Novos rascunhos identificam esse perfil antes do cálculo da revisão. Revisões anteriores compatíveis usam a base verificada 1.8.23 sem alteração dos arquivos originais. Isso não representa recuperação de uma versão visual histórica que nunca foi registrada. Perfis, estruturas ou regras desconhecidos são recusados, conservando o download JSON.

## Relatórios mensais e fórmulas

- Comparação dos componentes descompactados: 172 componentes do Word, incluindo planilhas incorporadas, e 100 componentes do Excel iguais à base mensal 1.8.23. A adição do identificador de perfil não altera a saída.
- Testes de perfil e reexportação verificam estrutura, dados, fórmulas, recursos, nomes de arquivos, compatibilidade legada, recusa de metadados desconhecidos e ausência de dependências de configuração ou consultas atuais.
- Recálculo com ArtifactTool: os 12 indicadores, 285 dias faturados, R$ 28.500,00 brutos, R$ 1.140,00 de glosa e R$ 27.360,00 líquidos coincidem com a referência sintética. Edição de dias, substituição por zero, retorno ao cálculo automático e substituição da glosa propagam corretamente.
- Recálculo independente no LibreOffice Calc: 957 células de fórmula em 21 abas com fórmulas e resultados idênticos entre a base anterior e a nova exportação mensal; nenhum erro de fórmula.
- A exportação histórica carrega a evidência completa de exatamente uma revisão. Não utiliza os exportadores anuais, não depende da preparação diária e não atualiza a data original da apuração.

## Arquivo, acesso e concorrência

Os testes HTTP executam os controladores, armazenamento e exportadores reais em três processos PHP, com sessões distintas. O bootstrap e a autorização geral do GLPI são simulados; clientes das fontes operacionais são bloqueados. Foram exercitados:

- Acesso próprio, publicação explícita, publicação repetida idempotente e publicação de revisões antigas sem alterar seus arquivos.
- Leitura pelo segundo usuário autorizado e recusa ao terceiro usuário sem grupo, inclusive para JSON, resumo, preparação diária, comparação e Word/Excel mensais.
- Revogação de associação, configuração inválida, falha de consulta de grupos, sessão com índices numéricos e tentativas de forjar proprietário, caminho, mês ou referência.
- Revisões com o mesmo conteúdo e autores diferentes mantêm identidades de seleção distintas; uma referência indisponível não é substituída silenciosamente.
- Tokens, nonce, métodos HTTP, respostas sem cache e sem exposição de caminhos internos; falha de publicação após arquivamento informa que a revisão foi preservada.
- Bloqueio de exportações mensais concorrentes antes da decodificação do snapshot, resposta 409 para as demais solicitações e nova tentativa bem-sucedida após liberação.
- Falhas reais de limite de memória e tempo retornam 503 quando os cabeçalhos ainda permitem uma resposta; um erro fatal não relacionado continua sendo erro 500. Os arquivos salvos permanecem intactos.
- Uma exportação mensal normal continua funcionando sem a área de arquivo permanente disponível, pois o bloqueio usa a área temporária.
- O JSON é transmitido com os bytes originais e hash completo verificado antes da saída, pelo mesmo descritor de arquivo. Corrupção de mesmo tamanho, links e permissões impróprias são recusados. Um arquivo de 25.253.363 bytes foi transmitido em processo com limite de 8 MiB, com pico observado de 2 MiB, sem decodificação completa.

A associação compartilhada usa membros diretos dos grupos configurados no GLPI hospedeiro. Não se baseia em IDs de filtros operacionais, inferência de entidades ou chaves numéricas da sessão. O catálogo anual é limitado a 4 MiB/4.096 publicações, não exige varredura de todos os proprietários e verifica as referências antes de ler os resumos selecionados.

O bloqueio mensal vale por instância/volume temporário. Em servidores com volumes temporários locais diferentes, não é um limitador global do cluster. A preparação diária mantém seu bloqueio no volume persistente compartilhado. A atualização não implementa fila nem modifica a concorrência da extração mensal nas fontes SQL.

## Comparações anuais e meses selecionados

Os testes inspecionam os componentes OOXML, séries, referências, rótulos, formatos numéricos, legendas, limites de painéis, lacunas, metas, totais e fórmulas. Foram produzidas e renderizadas seleções de 1, 6, 12, 13 e 24 meses, incluindo casos com valores ausentes, zero, critérios incompatíveis, valores provisórios, metas variáveis, durações e custos extremos.

Os resultados mensais recebem rótulos exatos. Metas constantes aparecem com seu valor na legenda; metas variáveis permanecem explicitadas. Painéis do mesmo indicador usam a mesma escala. Os mapas exibem contagens em blocos de dias e distinguem zero, desconhecido, futuro e data inexistente. A conciliação mostra volume nos dias, registros sem data atribuída e total mensal.

As sete abas da comparação Excel foram recalculadas e renderizadas com ArtifactTool. Alterações nas células de dados atualizam gráficos, referências financeiras e conciliação diária, sem erros de fórmula. A revisão visual utilizou também PDFs gerados pelo LibreOffice. O Word foi renderizado e revisado, incluindo valores grandes nos mapas e a apresentação de um único mês.

## Volume e limites da validação

Um teste sintético gerado de forma consistente com o calculador existente usou 10.000 chamados e 5.000 ligações, com snapshot de 24.725.886 bytes. Sob PHP com `memory_limit=256M`:

| Exportação | Pico observado | Tempo observado |
|---|---:|---:|
| Word mensal | 161,6 MiB | 0,27 s |
| Excel mensal | 242,9 MiB | 5,56 s |

O Excel resultante teve CRC, 21 abas, 12 indicadores e a quantidade completa de linhas verificados. O processo de exportação não carregou o calculador nem o repositório operacional. Tempos e memória são observações deste ambiente sintético, não garantias de produção. Um snapshot permitido de até 64 MiB pode exigir mais memória do que esse exemplo; não há corte silencioso de evidências nem alteração automática dos limites PHP.

As suítes de regressão PHP/JavaScript e as verificações de sintaxe passaram localmente, com repetição dos testes afetados após os ajustes finais. Não houve acesso a bancos de produção, instalação em um GLPI real nem teste no Microsoft Office nativo. ArtifactTool e LibreOffice não garantem renderização idêntica em todas as versões do Office.

## Atualização e conferência no ambiente

Substitua a pasta `g4freports` sem desinstalar, preservando configurações e `GLPI_PLUGIN_DOC_DIR/g4freports/monthly_archive`. Confira a versão 1.8.24 e o cache PHP/Twig conforme a rotina do ambiente. O banco sozinho não contém os relatórios; inclua a área de documentos no backup.

Confira um mês conhecido pela exportação normal e pelos novos botões **Word mensal** e **Excel mensal** do histórico. Para compartilhar, configure os grupos em **Histórico mensal compartilhado** e publique somente as revisões desejadas. Configurar grupos não publica automaticamente arquivos anteriores. Verifique o acesso de dois membros e a recusa de um usuário externo, além de uma comparação com as competências habituais. Essas conferências de homologação complementam os testes locais; a atualização não foi instalada automaticamente.
