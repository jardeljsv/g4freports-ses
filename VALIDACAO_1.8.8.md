# Validação da atualização 1.8.8

Data: 21/09/2026. Base de conferência: extrações de março de 2026 fornecidas pelo usuário e relatório anterior do plugin. Nenhuma alteração foi executada nos bancos ou no GLPI de produção.

## O que mudou na apuração

O prazo de solução gravado não é mais a única forma de apurar INS1–INS4. O plugin lê o histórico técnico, as definições de SLA, os calendários, seus intervalos e feriados e a herança de calendário das entidades. A seleção mensal continua pela **data de solução**.

As metas operacionais por código de prioridade são 2 = 8 h, 3 = 6 h, 4 = 4 h e 5 = 2 h. A associação dessas prioridades aos indicadores continua selecionável. INS4 conserva o recorte N1/N2 e a exclusão de N3; o grupo N1 inicial continua 55.

A política aplicada usa o calendário selecionado para o nível atual durante todo o ciclo entre abertura e solução, descontando suspensões nesse mesmo calendário. Mudanças de grupo, prioridade, entidade, SLA ou configuração histórica são assinaladas. Essa é uma política operacional explícita: não certifica a regra histórica utilizada pelo relatório gerencial nem transforma automaticamente as horas corridas do texto contratual em horas úteis.

Há quatro situações de evidência:

1. **Reconstrução:** histórico suficiente para calcular tempo bruto, suspensões e tempo líquido.
2. **Contadores validados:** histórico insuficiente, mas calendário, meta, prazo e contadores passam conjuntamente nas verificações de coerência.
3. **Cumprimento pelo limite superior:** todo o intervalo de trabalho, antes de descontar pausas, já cabe na meta. Isso comprova cumprimento; os tempos exatos de pausa e líquido continuam vazios. Um intervalo acima da meta, por si só, não comprova atraso.
4. **Apuração pendente:** evidência insuficiente ou conflitante. O chamado aparece no recorte e na tabela de evidências, mas não recebe classificação inventada nem entra no percentual apurado. A cobertura e a situação provisória ficam explícitas.

O prazo e as estatísticas de origem permanecem disponíveis para comparação. Prazo vazio, ausência de categoria, ausência de vínculo SLA e apuração pendente são situações distintas.

## Reprodução de março

Os 30 arquivos CSV recebidos foram conferidos pelos hashes do manifesto anterior. Os 9.319 IDs de chamados, suas prioridades, SLAs, prazos e grupos coincidem com o Excel anterior. Foram usados 80.072 registros de histórico técnico e os desfechos/pesquisas das 91 ligações. Os dados pessoais desnecessários foram omitidos dos testes.

| Verificação | Resultado |
| --- | ---: |
| Chamados no recorte original | 9.319 |
| Chamados com apuração | 8.830 |
| Chamados com apuração pendente | 489 |
| Prazos de origem vazios | 560 |
| Prazos vazios com apuração recuperada | 259 |
| N1 com prazo vazio recuperado | **194 de 194** |
| Durações exatas comparáveis com a auditoria independente | 8.243 |
| Divergências nessas durações | **0** |
| Classificações comprovadas pelo limite superior | 513 |
| Classificações diferentes da comparação com o prazo gravado | 33 |
| Avaliações com ressalva de método/configuração histórica | 2.962 |

Dos 259 prazos vazios recuperados, 194 são N1, 10 N2 e 55 N3. No N1, 179 foram reconstruídos e 15 comprovados pelo limite superior.

Os 489 pendentes se distribuem em 291 sem calendário N3 unívoco, 163 com ciclo copiado sem histórico suficiente, 2 com criação posterior à solução e 33 com outras lacunas históricas. Isso não significa que o chamado não tenha meta: significa que a evidência recebida não sustenta uma conclusão precisa sob a regra selecionada. Um calendário N3 explicitamente escolhido pode resolver a ambiguidade de calendário, mas não corrige por si só lacunas de histórico.

Com o preset operacional INS1→2 / INS2→3 / INS3→4 / INS4→5:

| Indicador | No prazo | Apurados | Pendentes | Resultado | Situação |
| --- | ---: | ---: | ---: | ---: | --- |
| INS1 | 8.638 | 8.792 | 489 | 98,25% | Provisório |
| INS2 | 15 | 36 | 0 | 41,67% | Provisório: ressalvas de relógio |
| INS3 | 2 | 2 | 0 | 100,00% | Provisório: ressalvas de relógio |
| INS4 | 0 | 0 | 0 | — | Não aplicável |

O resultado de prioridade média muda significativamente. Parte dos prazos históricos refletia SLA de 12 horas/calendário 3, enquanto a política atual usa meta de 6 horas e calendário N1 4. As durações recalculadas também conferem com a auditoria independente; os percentuais não foram ajustados para reproduzir artificialmente o relatório gerencial. O preset alternativo foi testado e mantém os mesmos relógios dos chamados, respeitando os escopos próprios de cada indicador.

Permanecem iguais os totais de INS5 (462/9.319), INS6 (6.511/7.698), INS7 (79/91), INS8 (87/91), INS9 (0/91) e INS12 (586/708). INS11 fica pendente no cenário de teste sem artigos validados selecionados. INS10 não é usado para comparar com o gerencial porque o cadastro de profissionais fornecido estava incompleto.

## Exportação e compatibilidade

- Excel: tabelas de evidências com fundo branco/azul claro e texto marinho explicitamente definidos; cada indicador identifica o recorte, a participação no cálculo e os motivos. Campos adicionais registram calendário, meta, tempos, limite superior, método e divergências.
- Word: explicações alinhadas com a apuração atual, referência dos calendários e início de cada indicador em página própria.
- Gráficos nativos, paleta G4F, timbre, filtros, bases completas e fórmulas preservados. As planilhas são compactadas progressivamente para evitar cópias simultâneas grandes de XML na memória.
- Métodos de cálculo, calendários usados, segmentos, feriados e critérios ficam preservados no rascunho. Exportar não consulta novamente os bancos.

Os testes locais executaram o código PHP 8.3.33 em runtime isolado, com casos de calendário, suspensão, reabertura, cópia, mudanças de SLA, relógios inconsistentes, fuso, herança, indisponibilidade de metadados e extração em lotes. O carregador foi verificado com fontes simuladas dos esquemas GLPI 10 e 11. Também passaram os testes de INS12 combinado/somente chamados, exclusão e reutilização de números de teste, reutilização/saída/remoção de profissionais e bloqueio de rascunhos antigos.

Os 53 arquivos PHP e os 4 arquivos JavaScript passaram na verificação de sintaxe. No cenário completo de março, a geração de uma extração e sua serialização passaram com limite de 256 MiB, pico de 208 MiB; o Excel passou com pico de 214,125 MiB. Esses números foram medidos no runtime isolado, sem o consumo adicional do GLPI/PHP-FPM nem garantia para meses maiores. O plugin libera o histórico bruto após a apuração e não aumenta o limite de memória do servidor.

Os pacotes de Word e Excel gerados passaram na conferência de CRC, XML e destinos dos relacionamentos. O Excel conservou 14 gráficos e 1.980.997 células de evidência com fundo claro e texto marinho explícitos. A conferência visual utilizou LibreOffice e renderização das páginas; os arquivos instaláveis não incluem esses relatórios de teste.

A reprodução usa os arquivos recebidos, não uma conexão ao MariaDB real. A validação de documentos é local e não substitui a abertura no Microsoft Word/Excel do ambiente de destino. Não foi executado Microsoft Office desktop nem teste integrado no GLPI de produção.

## Atualização

1. Substitua somente a pasta ativa `g4freports/` pela pasta do pacote, mantendo as permissões adequadas ao servidor.
2. **Não desinstale.** A atualização preserva as conexões e os cadastros de profissionais e números de teste. A desinstalação remove configurações.
3. Execute a atualização indicada na área de plugins do GLPI, se exibida, e confirme **1.8.8** na configuração/diagnóstico.
4. Gere novamente a competência. Rascunhos das regras anteriores não podem ser exportados com os novos textos de SLA.
5. Confira a seleção de grupos e os calendários em **Critérios dos indicadores**. Zero usa descoberta automática; um ID informado aplica a escolha explícita ao respectivo nível.
6. Abra os novos Word e Excel e confira a metodologia, a cobertura e as evidências. Arquivos exportados anteriormente não são alterados.

Além das tabelas já consultadas, a conta de leitura precisa acessar os metadados de SLA/SLM, calendários, segmentos, feriados, entidades e o histórico técnico `glpi_logs`. Falta de acesso gera aviso ou apuração pendente; o plugin não concede permissões nem modifica dados de origem. Limites de volume interrompem a extração com erro em vez de produzir evidência histórica truncada.

O pacote instalável não contém as bases de teste, históricos extraídos, senhas ou dependências do runtime de validação.
