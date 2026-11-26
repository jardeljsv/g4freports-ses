# Verificação da versão 1.8.3

Registro histórico: a verificação abaixo cobriu o esquema ECMA, mas não todas as restrições de implementação do Microsoft Office. Persistiram defeitos em ambos os geradores, corrigidos na versão 1.8.4. A afirmação sobre a estrutura correta do Word refere-se somente à alternativa `delete` do rótulo; não abrangia o posicionamento do rótulo dentro da série. Consulte `VALIDACAO_1.8.4.md` para a verificação atual.

## Correção do reparo do Excel

A mensagem apresentada removia drawing1, drawing6, drawing7 e drawing10. Na exportação de agosto da versão 1.8.2, essas partes referenciam a pizza de tipos de chamados e as roscas INS5, INS6 e INS9.

A validação reproduziu 11 erros nos quatro gráficos: o rótulo individual combinava `delete` e opções de exibição. O tipo `CT_DLbl` permite uma alternativa ou a outra. A versão 1.8.3 mantém somente `idx + delete` para rótulos ocultos e `idx + opções de exibição` para rótulos visíveis. O Word já usava a estrutura correta.

Foram usados os esquemas oficiais ECMA-376, quinta edição, Parte 4 (transitional) e Parte 2 (OPC). Gráficos, desenhos e relacionamentos foram validados sem modificar seu XML. Também foram verificados os destinos dos relacionamentos e as planilhas embutidas dos gráficos Word.

No texto de células, o atributo `xml:space` suportado pelo Office foi conferido separadamente e removido somente da árvore em memória para validação XSD: o esquema representa esse elemento como string simples. Os arquivos entregues não são reescritos pelo validador. O XML principal do documento Word e propriedades centrais preservadas foram analisados sintaticamente, sem alegação de validação XSD integral dessas partes.

Referências oficiais: [ECMA-376](https://ecma-international.org/publications-and-standards/standards/ecma-376/) e [Microsoft Spreadsheet.Text](https://learn.microsoft.com/en-us/dotnet/api/documentformat.openxml.spreadsheet.text).

## Apresentação

- Colunas diárias de chamados por solução e colunas empilhadas de ligações por desfecho.
- Pizzas, roscas e mapas de calor preservados; contagens e evidências continuam visíveis.
- Paleta da página 33 do manual G4F fornecido: `011224`, `376BFF`, `FFE554`, `DAE6F2`, variantes oficiais e tonalidades claras derivadas para leitura.
- Cabeçalhos e rodapés do papel timbrado preservados. A fonte de corpo existente foi mantida; não é necessária instalação de fontes adicionais.

## Verificações locais

- Sintaxe dos 46 arquivos PHP aprovada.
- Exportação real pelos endpoints PHP com interfaces GLPI simuladas: Word, Excel, JSON, tamanho completo do download, rascunho e atualização de texto aprovados.
- Formulário real da configuração renderizado com Twig, incluindo simulação de namespace antigo/cache de produção: versão 1.8.3, campos de conexão e tratamento nativo da gravação presentes.
- Arquivos de junho, agosto, exclusões, limites temporais e microssegundos gerados; gráficos/desenhos dos arquivos Excel e gráficos/planilhas embutidas Word aprovados nos esquemas dentro do escopo descrito acima.
- Comparação com a versão 1.8.2: 662.388 células e 44.438 fórmulas/caches Excel preservados em cinco cenários, com alteração apenas dos textos de cor dos mapas. Séries, categorias e referências dos 80 gráficos Excel e dados dos 63 gráficos Word/planilhas embutidas preservados. Nenhum gráfico de linha permanece nas exportações SES verificadas.
- Todas as 22 páginas Word de junho e 21 de agosto foram inspecionadas; visualizações Excel de resumo, indicadores e distribuição temporal também foram renderizadas e conferidas.
- Renderização nativa em LibreOffice usada para conferir layout, cores, rótulos e mapas; não é usada como substituta da validação estrutural do Excel.

Não houve acesso ao GLPI de produção, às bases externas ou ao Microsoft Excel/Word de desktop nesta verificação.

## Atualização

Substitua a pasta do plugin e confirme a versão 1.8.3. Preserve as configurações; não desinstale para atualizar. Exporte novamente Word/Excel. Rascunhos ainda válidos de 1.8.1/1.8.2 podem ser reexportados sem nova consulta. Um arquivo salvo após o Excel remover seus objetos não é restaurado apenas reabrindo-o.
