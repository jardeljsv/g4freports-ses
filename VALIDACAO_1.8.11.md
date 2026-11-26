# Validação da versão 1.8.11

Data: 23/09/2026. Regras: `ses-2026-09-23-v9`.

Esta versão foi executada localmente com PHP 8.3 e com as extrações GLPI fornecidas para junho. Os arquivos Word e Excel foram produzidos pelos exportadores reais do plugin. Nenhuma conexão à produção foi utilizada e nenhum registro das fontes GLPI/Asterisk foi alterado.

## Resultados conferidos

| Verificação | Resultado |
|---|---|
| População de chamados | 6.567 IDs, sem duplicação |
| SLA calculado | 6.567 avaliados; zero não apurados ou provisórios |
| Duração usada | 6.487 tempos de solução armazenados positivos; 75 intervalos menos espera geral; 5 intervalos integrais |
| INS1 | 1 / 1 |
| INS2 | 1 / 1 |
| INS3 automático | 2 / 12 antes de decisões humanas sobre justificativas |
| INS4 | 6.123 / 6.223 = 98,39306%; 100 excedidos |
| INS5 | 251 / 6.567; 13 reaberturas administrativas auditadas |
| INS6 | 4.601 / 5.381; complemento por solucionador para um chamado sem grupo |
| INS11 por categoria | 5.973 / 6.567 |
| INS12 somente GLPI | 619 / 739 = 83,76184% |
| INS12 combinado na amostra | 665 / 785 = 84,71338% |
| Conferência das pesquisas GLPI | 739 respostas válidas + 5.828 não preenchidas = 6.567 chamados |

Os valores são resultados da amostra fornecida; não são inseridos como constantes nos indicadores. Mudanças na base, abrangência, cadastro de testes ou seleção de fontes podem alterar uma nova extração.

## Diferenças interpretadas

**INS5:** a exceção geral pelo mesmo solucionador N1/N2/N3 identifica 13 chamados. A planilha histórica excluiu apenas um e apresentava 263; a aplicação consistente da regra acordada produz 251. A auditoria mostra o evento dispensado, seus autores, a solução anterior e se outro motivo mantém o chamado no indicador. O ator é o técnico solucionador do ciclo anterior, não o usuário ou processo que posteriormente aprovou/fechou automaticamente o chamado. Vínculos de equipe são os existentes na extração, expressamente identificados dessa forma.

**INS3:** o resultado automático pela duração da base é 2/12. O resultado gerencial 11/12 inclui reavaliações humanas de pendenciamento; o plugin não inventa a motivação dessas decisões. O critério automático é comum aos quatro níveis.

**Telefonia:** a consulta e os critérios INS7–INS9 foram preservados. A amostra arquivada foi utilizada somente para verificar a apresentação: 69, 73 e 3 registros nas tabelas iniciais de INS7, INS8 e INS9, com acesso à base completa de 76. O denominador não vira o subtotal da visualização. O preenchimento da lista de testes pode mudar os resultados futuros, conforme informado pelo usuário.

**INS10:** a fórmula `StaffRegister::summarize()` foi comparada com a versão anterior e permanece byte a byte igual. Foram testados faturamento, profissionais desligados e preservação da participação histórica ao filtrar/ordenar. A amostra de integração não contém o cadastro completo de pessoal e não serve para validar um total de equipe. Não foi aplicada correção numérica para forçar um resultado.

## Testes executados

- PHP 8.3: verificação de sintaxe de todos os arquivos PHP do pacote.
- SLA: 39 casos sintéticos e 32.617 verificações no conjunto dos 6.567 chamados, incluindo limites em segundos, abertura original de clones, prazos vazios e contadores zero. Os 6.302 intervalos de calendário disponíveis para conferência independente continuam iguais; comparações de prazo nativo foram preservadas.
- INS5/INS6: mesma pessoa, autores distintos, IDs ausentes, eventos múltiplos, recusa de solução, execução incompleta manual, ciclos de solução, registros posteriores, grupo N3 e preservação do critério de grupo N1.
- Cadastros: ordenação com acentos, bloqueio de nova duplicata, readmissão, homônimo identificado, duplicata antiga preservada para revisão, recuperação individual, limite de dez versões e fragmentos UTF-8 de histórico.
- Interface: execução do JavaScript real com DOM da tela e dados sintéticos. Foram verificados busca/paginação, remover/cancelar/desfazer, salvamento mantendo linhas ocultas, bloqueio de duplicata e recuperação de telefone salvo. `node --check` sem erros.
- Apresentação: severidade contratual versus código GLPI, inclusive mapeamento alternativo; ligação em várias filas sem duplicar a pesquisa; ausência de resposta, nota inválida e fonte indisponível.
- Estrutura Office: 288 verificações no modo GLPI e 293 no modo combinado, sem falhas, incluindo CRC dos arquivos, XML, relacionamentos, séries/legendas, resultados e quantidades das tabelas.
- Excel reaberto e recalculado no LibreOffice: nenhuma diferença de fórmulas/resultados nem erro de célula nos dois modos. No combinado foram comparadas 21.601 células. As séries GLPI e Telefonia permaneceram separadas.
- Inspeção visual das exportações: gráficos, rótulos e tabelas legíveis nos painéis e nos indicadores alterados do Excel. Word renderizado em 31 páginas em cada modo; relatório combinado completo e páginas específicas do modo GLPI revisados, sem sobreposição, corte ou categorias ausentes.
- As colunas usadas nas novas consultas de autores/soluções/vínculos foram conferidas contra o esquema extraído do banco fornecido.

## Limites da validação

O ambiente não dispõe de Microsoft Word/Excel, de um GLPI instalado com a configuração do cliente nem das credenciais para suas fontes. A inspeção de documentos e a recalculação utilizaram LibreOffice e verificações estruturais OOXML. O teste de interface utilizou DOM executável, sem navegação em uma instalação GLPI real. A gravação nativa e o bloqueio concorrente no banco GLPI precisam ser exercitados no ambiente de destino; os testes de histórico utilizam persistência simulada.

Não se afirma validação de ajustes financeiros, completude do cadastro de pessoal ou reconstrução histórica de filiação a grupos além dos dados extraídos.

## Atualização e primeiro uso

1. Guarde a versão anterior e o backup habitual do GLPI.
2. Substitua a pasta ativa `g4freports/` pela pasta do pacote, mantendo o plugin instalado e suas configurações no banco. A desinstalação apaga as configurações.
3. Aplique a atualização indicada pelo GLPI e confirme a versão **1.8.11** na configuração/diagnóstico.
4. Reabra a área SES. Confira as listas salvas de profissionais e números de teste; elas permanecem na configuração existente.
5. Gere novamente junho ou a competência desejada. Rascunhos de versões anteriores não são reexportados com as novas regras.
6. Exporte Word e Excel. Confira a fonte INS12 escolhida e os novos quadros de auditoria INS5/INS6.

O histórico de recuperação começa nos salvamentos efetuados com esta versão e conserva os dez anteriores de cada cadastro. Uma remoção anterior à instalação não pode ser recuperada por esse histórico. Para desligar um profissional preservando apurações históricas, use sua data de saída; remover o vínculo altera as próximas extrações, inclusive de meses anteriores.

## Testes sintéticos reproduzíveis

Sem banco de dados ou informações do cliente, em PHP 8.3 com as extensões usuais do GLPI:

```sh
php tests/sla_evaluator_test.php
php tests/actor_rules.php
php tests/registers_test.php
php tests/presentation_test.php
```

Os arquivos privados de extração e as exportações usadas nos testes não integram o pacote. Os scripts de integração requerem esses arquivos externos; os resultados numéricos acima não foram utilizados para substituir os cálculos do plugin.
