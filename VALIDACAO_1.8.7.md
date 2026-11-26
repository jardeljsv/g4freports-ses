# Validação 1.8.7

## Mudanças verificadas

- INS11 e INS12 visíveis lado a lado; INS12 inicia com GLPI + telefonia e permite somente GLPI. A troca invalida a exportação até regenerar os dados.
- INS4 aceita grupos N1 ou N2, exclui N3 inclusive em atribuição simultânea e não exige categoria elegível N1. O filtro geral permanece explícito.
- Cadastros de profissionais e números de teste são salvos independentemente nas configurações, conferidos após gravar e reutilizados para outras competências. Alterações inválidas ou com versão desatualizada não sobrescrevem o cadastro.
- Cada aba INS tem evidências detalhadas, valores utilizados, participação e motivo. As tabelas nativas do Excel têm filtros e links internos. Dados de origem iniciados por caracteres de fórmula continuam texto literal.
- Ausência de prazo, ausência de SLA e ausência de categoria são classificações distintas. O prazo ausente não é inventado nem transformado em cumprimento automático.
- INS10 usa o cadastro salvo e conserva evidências da equipe no fechamento e das saídas na janela de três meses. Saída sem classificação deixa o percentual pendente. Datas e valores monetários são validados; o valor mensal preserva centavos.

## Evidências locais

Execução com PHP 8.3.33, incluindo validação de sintaxe de 51 arquivos PHP. Foram exercitados os dois modos INS12, recortes N1/N2/N3, prazos ausentes, datas de movimentação, exclusão de testes, cadastros vazios e informações pendentes. A interface foi renderizada em PHP; a lógica JavaScript e o endpoint de gravação foram exercitados com modelos locais de sessão, configuração e transporte.

A amostra de junho tem 6.567 chamados e 99 ligações. O INS4 conserva o chamado N2 existente nessa amostra. INS12 combinado permanece 665/785 = 84,71%; somente GLPI, 619/739 = 83,76%. Em agosto, combinado 566/658 = 86,02%; somente GLPI, 537/629 = 85,37%. São resultados das amostras fornecidas, não valores fixos.

Junho contém 832 chamados sem prazo: 484 possuem SLA ID e 348 não possuem. Em agosto são 1.042: 669 com SLA ID e 373 sem. Chamados sem categoria são outro conjunto: 18 em junho e 7 em agosto. Isso explica por que a faixa cinza não pode ser chamada “Sem SLA”.

O Excel de junho, com todas as evidências, foi gerado em processo novo com limite de 256 MiB: pico de aproximadamente 174,5 MiB e tempo de 7,21 segundos no ambiente de teste. O arquivo tem aproximadamente 7,3 MiB. O consumo de produção depende do GLPI, do PHP e do volume de dados. As linhas são serializadas progressivamente para evitar reter milhões de células como objetos PHP.

Os geradores nativos WordChart/XlsxChart, o modelo timbrado, a consulta Repository e o exportador dos relatórios por profissional permanecem idênticos à 1.8.6. As verificações de OOXML abrangem relações internas, tabelas, gráficos, células de origem, valores preservados e conflitos de mesclagem.

Não houve execução no GLPI de produção nem abertura no Microsoft Word/Excel de desktop. A persistência e a sessão GLPI foram simuladas nos testes locais; a validação CSRF nativa permanece a cargo do bootstrap normal do GLPI, sem bypass.

## Aplicação da atualização

1. Substitua os arquivos do plugin, preservando sua configuração; não desinstale.
2. Confira **v1.8.7** no cabeçalho da área SES.
3. Confira os seletores INS11/INS12 e os IDs N1/N2/N3.
4. Salve os números de teste e os profissionais. Ao reabrir a página, os cadastros devem permanecer preenchidos.
5. Gere novamente a competência. Rascunhos anteriores às regras v5 precisam ser regenerados.
6. Confira os registros pela tabela de cada aba INS; Word e Excel utilizam a mesma extração preservada.
