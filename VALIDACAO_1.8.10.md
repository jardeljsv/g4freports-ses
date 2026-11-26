# Validação da atualização 1.8.10

Data: 21/09/2026. Regras: `ses-2026-09-21-v8`.

## Alterações

A associação inicial passa à ordem do relatório contratual: INS1→5, INS2→4, INS3→3, INS4→2. O usuário continua podendo escolher explicitamente a ordem do painel anterior. A seleção fica visível antes dos critérios detalhados. O vínculo de prioridade com as metas permanece 2=8h, 3=6h, 4=4h e 5=2h.

As classificações usam **Não excedido** e **Excedido**, inclusive nos critérios das fórmulas Excel que contam essas classificações. As tabelas de composição e de dados têm totais adequados ao seu conteúdo. Quantidades de registros usam contagem; percentuais usam a razão das quantidades somadas. Identificadores, datas e percentuais não são somados. Medidas sobrepostas do INS10 não recebem uma soma enganosa.

O INS12 inclui uma tabela de conferência de todos os chamados GLPI, com notas 1–5, Não preenchida e Total geral. Essa tabela não modifica a base de respostas válidas nem a opção de incluir telefonia. Uma fonte de pesquisa indisponível não é apresentada como se todas as pesquisas estivessem em branco.

A versão também ajusta a paginação de tabelas compactas do Word para manter o total junto às linhas e corrige a ordem das propriedades de borda e largura das células dessas tabelas.

## Apuração dos clones

Não houve mudança em `SlaEvaluator.php`, `Repository.php` ou `SlaDataSource.php`. O relógio já usa a abertura registrada (`date`/`opened_at`), inclusive herdada do original, e não a criação técnica do clone (`date_creation`). Esse comportamento está explicitado na metodologia e foi validado com doze asserções PHP, cobrindo prazo preenchido, prazo ausente, tempo anterior à clonagem, suspensão aplicada uma vez e equivalência entre chamado normal e clone com os mesmos dados operacionais.

Todos os 5.717 prazos preenchidos do recorte N1/N2 de prioridade baixa de junho foram reproduzidos exatamente a partir da abertura, de oito horas e da espera acumulada do SLA no calendário correspondente. Incluem 55 chamados com indicação de clonagem.

## Conciliação de junho

A planilha recebida `Relatorio_Mensal_SES_2026-06 (1).xlsx`, gerada em 21/09/2026 às 16:50, usa a ordem do painel. A reprodução de teste altera somente essa associação para a ordem do contrato e mantém os dados e demais seleções extraídos.

| Conferência | Resultado |
| --- | --- |
| Total de chamados | 6.567 |
| INS4 — base N1/N2 de prioridade baixa, sem N3 | 6.223 |
| INS4 — não excedidos | 6.154 |
| INS4 — excedidos | 69 |
| INS4 — sem apuração no recorte | 0 |
| INS4 — percentual | 98,8912% |
| INS12 — favoráveis / válidas, somente GLPI | 619 / 739 |
| Telefonia considerada / excluída por teste | 76 / 23 |

Os 6.223 chamados se dividem em 5.717 prazos preenchidos, 483 prazos calculados e 23 durações operacionais validadas. Os 69 excedidos se dividem, respectivamente, em 36, 12 e 21. Os quatro indicadores de prazo têm cobertura completa no seu recorte na reprodução.

**A diferença para a imagem validada ainda não está conciliada:** a imagem informa 6.123 não excedidos e 100 excedidos, também totalizando 6.223. Falta a relação de IDs com a classificação validada, ao menos os 100 excedidos, para determinar as diferenças por chamado. A versão não força o resultado a esse total nem altera a suspensão de clones sem evidência.

A planilha gerencial antiga disponível classifica 36 chamados do recorte como excedidos. Todos permanecem excedidos, e a medição atual acrescenta os 33 casos sem prazo preenchido descritos acima. Essa relação explica 69, não os 100 da validação mais recente.

A distribuição GLPI de satisfação reproduz exatamente a imagem: nota 1=24, 2=1, 3=95, 4=3, 5=616, não preenchida=5.828, total=6.567. As não preenchidas ficam fora do denominador do INS12.

## Verificações executadas

- Análise sintática de 53 arquivos PHP e do JavaScript modificado.
- PHP 8.3.33 isolado: associação inicial do contrato, preservação de associação explícita, N1=55, seleção INS12, limites mensais e rejeição de rascunhos anteriores às regras v8.
- Reprodução pelo `Calculator` e pelos próprios exportadores PHP com os 6.567 chamados e 99 registros de telefonia, sem consultas ou alterações no GLPI de produção.
- Datas técnicas de criação e histórico bruto ausentes da planilha não foram inventados no teste. Calendários, segmentos e definições de SLA vieram das extrações fornecidas. A cobertura aqui relatada é a dos indicadores, não uma certificação de todos os históricos da base geral.
- Totais do Excel: composição INS4=6.223; detalhamento INS4=6.223; distribuição diária=6.154/6.223; respostas válidas INS12=739; favoráveis=619; distribuição completa GLPI=6.567; bases=6.567 chamados e 76 ligações consideradas.
- As fórmulas `SUBTOTAL(103,...)` contam os registros visíveis sem incluir o próprio total. Séries de gráficos excluem as linhas de total. Totais diários percentuais usam divisão entre somas.
- Casos vazios: total zero sem criação de registro fictício. Caso de pessoal: profissional não faturado fora do efetivo, da saída e do valor; contagem e soma de faturados ativos conferidas com `COUNTIFS`/`SUMIFS`.
- ZIP/CRC, XML e relacionamentos internos dos arquivos Word/Excel válidos. Linhas de planilha sem duplicação e na ordem correta. Propriedades de células Word na ordem adequada. A planilha de teste preserva 17 gráficos nativos.
- Renderização do Word para inspeção das tabelas, rótulos e paginação alterados. Inspeção de totais e fórmulas da planilha gerada, incluindo renderização no LibreOffice dos quadros de conformidade INS4 e satisfação GLPI. A abertura no LibreOffice não substitui a verificação no Microsoft Office.

Não foi executado Microsoft Word/Excel desktop nem a conexão do servidor de destino. Os arquivos e dependências de teste não integram o plugin instalável. O pacote não contém bases de chamados, profissionais, telefones ou credenciais.

## Atualização

1. Substitua a pasta ativa `g4freports/` pela pasta do pacote, preservando permissões.
2. **Não desinstale:** mantenha conexões e cadastros já salvos.
3. Confirme a versão **1.8.10** e execute a atualização do plugin se oferecida pelo GLPI.
4. Reabra a área SES. Confira **Ordem do contrato**, as fontes INS12 e os demais critérios desejados.
5. Gere novamente o mês e exporte novos arquivos. Rascunhos antigos e arquivos já baixados não são recalculados pela atualização.
