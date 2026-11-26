# Verificação da versão 1.8.4

## Correções entregues

A versão 1.8.3 corrigiu uma alternativa inválida no esquema de rótulos, mas ainda colocava rótulos personalizados fora da série. Os dois geradores, Word e Excel, foram corrigidos para respeitar as restrições adicionais de implementação do Microsoft Office. Roscas usam posição automática; gráficos de barras não emitem opções exclusivas de porcentagem de pizza ou tamanho de bolha. Os tipos dos gráficos, dados, referências, paleta G4F e mapas de calor foram preservados.

O N1 inicial passou a 55. Os IDs informados são conferidos na mesma base GLPI usada pela extração. Os nomes ficam no rascunho, na prévia e na metodologia dos dois arquivos. As 44 categorias elegíveis e as fórmulas dos indicadores permanecem preservadas. O campo de artigos do INS11 usa IDs de artigos e não presume validação pela existência no catálogo. Falta de acesso ao catálogo de artigos mantém o modo por vínculos validados pendente.

## Evidência de verificação

- Os 47 arquivos PHP foram verificados com PHP 8.3, sem erro de sintaxe.
- Foram comparados 128 gráficos gerados pelos escritores anteriores e corrigidos. O verificador das restrições de contexto identificou problemas em 111 gráficos antigos e em nenhum corrigido. Removendo somente os nós de rótulos para comparação, o XML canônico dos gráficos permanece igual: séries, valores, referências, cores, eixos e configurações restantes foram preservados.
- Foram gerados 12 arquivos completos Word/Excel: junho, agosto e cenários de 0%, 100%, fatia de 0,1% e informação indisponível. Passaram nos esquemas aplicáveis, nos relacionamentos entre desenhos/gráficos/planilhas embutidas e nas regras de contexto Microsoft verificadas. Conferidos 3.857 valores de cache contra suas fontes; nenhum gráfico de linha foi gerado.
- Junho foi reconstruído a partir do Excel fornecido, usando o catálogo SQL para resolver os IDs reais dos grupos. A correspondência por nome é exata para os 12 grupos presentes nos arquivos de junho/agosto. Não foram reutilizados o ID N1 incorretamente inferido nem IDs sentinela das antigas amostras locais.
- O INS6 de junho reproduz **4.600 / 5.439 = 84,5743703%**; agosto, **4.328 / 5.142 = 84,169584%**. Os demais numeradores, denominadores e resultados das amostras foram preservados. Junho em modo provisório por categoria reproduz **5.973 / 6.567 = 90,9547739%** no INS11.
- Foram verificados IDs inexistentes, seleção explícita de 53 sem substituição automática, nomes preservados, ausência de lista de artigos, ausência de acesso ao catálogo e distinção entre categorias BC e artigos. O caso 53 exibe seu nome real, Suporte Técnico IPESS, e zero observado.
- O teste de conexão e as consultas preparadas de catálogo foram executados em MariaDB local com DDL oficial GLPI 10.0.21 e 11.0.6. Ausência de grupos ou do campo de entidade das categorias reprova a prontidão; ausência do catálogo opcional de artigos mantém a conexão pronta e o INS11 estrito pendente. Conferido processamento em lotes de 1.000 IDs, sem duplicidade.
- O HTML real do formulário e seu JavaScript foram exercitados com interfaces GLPI/rede simuladas. Confirmados padrão 55, filtro geral vazio, identificação dos artigos, texto dos botões em repouso/atividade, prévia com nomes, exportações e bloqueio até nova geração após alterar N1. Nomes vindos da base são apresentados como texto. Rascunhos anteriores sem catálogo têm identificação de nome não registrado.
- As 24 páginas Word de junho e as visualizações Excel afetadas foram inspecionadas por renderização LibreOffice. As roscas INS5/INS6/INS9 aparecem nos cenários mensais e nas bordas de zero, total e fatia pequena. A tabela de categorias Word apresenta os 44 IDs e nomes, com cabeçalho repetido e paginação.

As verificações de pacote incluem CRC, referências e sintaxe XML. Os esquemas ECMA-376 e as regras específicas MS-OI29500 são verificações diferentes e foram aplicados em conjunto. A validação XSD não cobre integralmente o documento Word e cada extensão Office possível; o documento principal é analisado sintaticamente, enquanto gráficos, desenhos, relacionamentos e planilhas embutidas têm os esquemas aplicáveis conferidos. O tratamento de `xml:space` nos textos Excel ocorre somente na árvore em memória do validador, sem reescrever o arquivo testado.

A geração CLI de QA utilizou limite de memória de 1.024 MB; nenhuma configuração de memória da aplicação foi alterada.

Não houve conexão às bases de produção ou homologação. Microsoft Word e Excel de desktop não estão disponíveis neste ambiente; não foi realizada abertura nesses aplicativos. Renderização em LibreOffice e aprovação estrutural não são apresentadas como comprovação de abertura no Microsoft Office.

## Atualização

Substitua a pasta `g4freports/` pela do pacote e execute a atualização indicada pelo GLPI. Confirme **1.8.4** na configuração. Não desinstale: a desinstalação remove configurações salvas.

Gere novamente o mês com **Grupos N1 = 55**, mantendo o filtro geral de grupos conforme o escopo desejado, e exporte Word e Excel. Isso aplica o ID correto e registra os nomes consultados. Reexportar um rascunho v2 ainda válido corrige a estrutura dos gráficos, mas preserva seus IDs e resultados originais. A atualização não repara objetos já removidos de um arquivo salvo pelo Office.
