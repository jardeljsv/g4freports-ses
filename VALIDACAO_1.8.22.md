# Validação 1.8.22 — 23/10/2026

## Escopo entregue

Arquivo mensal explícito e coleção anual/de até 24 competências escolhidas pelo usuário. Cada revisão preserva a evidência, os indicadores, custos e textos do rascunho conferido. A coleção usa resumos compactos, sem refazer a extração ou o cálculo mensal. Não foram executadas consultas nas bases operacionais do usuário.

Não estão incluídos fila de extração, worker, geração automática de meses ausentes, novos índices ou reescrita das consultas. A carga e a concorrência das gerações mensais continuam com o comportamento anterior.

## Verificações executadas

- Sintaxe dos 104 arquivos PHP do pacote e dos sete JavaScript da aplicação; 27 suítes PHP e sete suítes JavaScript existentes e novas, incluindo regras de atores, faturamento, satisfação, SLA, importação/exportação dos cadastros e controles CSRF.
- Arquivo mensal: 86 verificações de revisão imutável, proprietário, rejeição de prévia desatualizada, repetição idempotente, corrupção, limites de tamanho/revisões, permissões, links simbólicos/físicos e contenção de bloqueio entre processos. Um diretório pai GLPI existente com permissão 0755 é preservado; a subárvore de arquivo mantém acesso privado.
- Análise anual: 112 verificações sobre valores congelados, centavos exatos, comparabilidade específica de cada indicador, origem desconhecida, mudanças de critérios, meses abertos, ausentes e não consecutivos, direção da melhora e cobertura financeira.
- Seleção: 40 verificações de tipos JSON, limites, duplicatas, meses, revisões e recusa de valores calculados enviados pelo cliente.
- HTTP: 54 solicitações reais aos novos controladores usando armazenamento e exportadores reais. Autenticação, configuração e CSRF nativo GLPI são emulados. Cobertos acesso de leitura, isolamento entre proprietários inclusive administradores, sessão liberada antes do trabalho, revisão antiga, arquivo corrompido, falta de revisão para exportar e fontes operacionais desabilitadas.
- Interface: controladores JavaScript reais com DOM/rede simulados. Verificados campos mínimos no arquivamento, revisão atualizada após narrativa, bloqueio por alterações não aplicadas, serialização/renovação CSRF, falhas sem repetição automática, seleção explícita, versões fixadas, limite de 24 meses, meses ausentes, período provisório e distinção entre zero e indisponível.
- Integração: um snapshot mensal completo da base local de verificação percorreu arquivamento, leitura, comparação e ambos os exportadores. Os 12 valores, situações, metas, numeradores, denominadores, métodos e notas permaneceram iguais. Bruto de 2.850.000 centavos, glosas de 114.000 e líquido de 2.736.000 foram preservados nesse caso de teste; não são valores fixos no produto.
- Word/Excel: validação dos pacotes OOXML, tabelas e gráficos horizontais, referências, valores acima de 100%, origem ausente, longas narrativas como texto literal, meses provisórios e seleções de 12/24 competências. Renderização com LibreOffice e inspeção visual; planilha importada, recalculada e renderizada também com Artifact Tool. Nenhum erro de fórmula foi encontrado no caso verificado; alterações de resultado atualizaram as diferenças correspondentes corretamente.

## Preservação do relatório mensal

Comparação com a 1.8.21 confirma os mesmos bytes em Calculator, Settings, FinancialSummary, StaffRegister, FinanceSettings, PhoneSurveyScore, SlaDataSource, SlaEvaluator, ActorEvidence, ChartData, DocxExporter, XlsxExporter e nos modelos. As classes e o fluxo de importação/exportação de cadastros não mudaram.

Repository acrescenta somente uma identidade SHA-256 de host/porta/banco dos perfis efetivamente usados, sem guardar credenciais nem detalhes brutos da conexão. As consultas e sua ordem permanecem iguais; 34 verificações focadas cobrem essa adição. O endpoint mensal copia essa identidade para o snapshot e devolve a revisão do rascunho. A versão de regras continua `ses-2026-10-05-v16`.

Prova de saída: o mesmo snapshot, com e sem o novo metadado, gerou conteúdo mensal idêntico em todos os 87 componentes do DOCX e 100 componentes do XLSX. A comparação foi feita dentro dos pacotes, sem considerar timestamps ZIP.

## Limites da validação e homologação

Os testes não substituem uma instalação GLPI 10/11 real, uma sessão nativa completa, Microsoft Word/Excel ou um ensaio de carga no ambiente de produção. A validação visual usa LibreOffice; a paginação e o espaçamento podem variar no Microsoft Office. Os testes HTTP emulam o bootstrap/autorização/CSRF do GLPI, enquanto executam os controladores, arquivo e exportadores do pacote.

Antes de produção, verificar na homologação:

1. Substituir a pasta sem desinstalar; confirmar versão 1.8.22 e preservar os cadastros/configurações. Conferir o mensal e suas exportações usuais.
2. Confirmar `GLPI_PLUGIN_DOC_DIR` persistente, privado e gravável pelo PHP, incluído no backup e no monitoramento de espaço. Validar que o servidor web não serve seus arquivos diretamente. Se houver vários servidores PHP, confirmar armazenamento comum com bloqueio e publicação atômica compatíveis.
3. Gerar e conferir um mês, arquivar, recarregar e reabrir. Arquivar a mesma revisão duas vezes não deve duplicá-la. Alterar a narrativa e arquivar outra revisão não deve mudar a primeira.
4. Usar duas contas para conferir o isolamento, além de abas simultâneas para rejeitar uma revisão desatualizada. Verificar persistência após reinício dos processos PHP.
5. Selecionar meses completos, ausentes e provisórios; conferir resultados/custos contra os meses originais e abrir Word/Excel no aplicativo usado pela equipe. Confirmar que a comparação continua funcionando sem acesso às fontes operacionais.

Não há retenção automática, exclusão pela interface nem cota global de disco. Os limites por revisão e competência não substituem monitoramento de armazenamento. Uma recusa de arquivamento por limite ou indisponibilidade da área de documentos não apaga arquivos anteriores nem altera o relatório mensal.

Três usuários podem consultar suas coleções sem iniciar novas consultas de chamados/telefonia. Isso não equivale a demonstrar capacidade para três extrações mensais simultâneas. Esse próximo marco depende de EXPLAIN representativo, medições, limites efetivos das conexões e decisão sobre o trabalhador compartilhado, conforme `ANALISE_RELATORIO_ANUAL.txt`.
