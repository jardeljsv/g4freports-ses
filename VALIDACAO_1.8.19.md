# Validação 1.8.19

## Escopo

- Dois quadros editáveis na abertura do Word, após a apresentação: composição contratual e composição do faturamento da competência, agrupados nas sete frentes de serviço.
- Referência SES com 14 combinações de perfil/frente, 83 postos, quantitativos e valores unitários próprios. Total contratual informado de **R$ 562.457,91**, preservado no Word e no Excel.
- Total mensal formado pela soma do Valor Faturado individual, considerando dias proporcionais, ajustes salvos e Não Faturar. As taxas contratuais não substituem os valores mensais cadastrados dos profissionais.
- Seleção explícita da frente de serviço do profissional; associação automática somente quando o perfil pertence a uma única frente. Perfis compartilhados entre N2 e Monitoramento não recebem associação arbitrária.
- Referência padrão para composição ainda não cadastrada; configurações personalizadas e decisões mensais anteriores preservadas. A opção Usar referência contratual SES mantém os ajustes por competência.

## Verificações

- Dezessete suítes PHP e três suítes JavaScript executadas com sucesso. Incluem cálculo financeiro, referência contratual, exportações, rateio, exclusões, salvamento, controles da tela e regressões dos indicadores.
- Referência contratual: 48 verificações de quantidades, valores, perfis compartilhados, configuração legada, total informado e preservação dos ajustes mensais. Financeiro: 115 verificações.
- Salvamento: 143 verificações pelo endpoint do plugin, com Config, sessão e transporte do banco simulados. Incluem perfis por frente, atribuições dos profissionais, configuração vazia intencional, referência padrão, leitura após gravação e preservação dos outros cadastros.
- Interface: edição dos perfis e taxas, associação automática/explicita, frente removida, paginação, busca, mudança de mês e salvamento financeiro mantendo alterações ainda não salvas no cadastro dos profissionais.
- Pacotes Word/Excel inspecionados quanto a XML, relacionamentos, ordem das tabelas e valores. O Word foi renderizado e os dois quadros iniciais foram conferidos visualmente; as novas seções do Excel também foram renderizadas.
- Recálculo do Excel com LibreOffice após remoção de todos os resultados de fórmula em cache. O total contratual permaneceu R$ 562.457,91 e as fórmulas do faturamento mensal responderam aos ajustes de dias.

## Cenário de cálculo

O cenário de exportação desta versão é anônimo e sintético: 89 vínculos históricos, 83 com participação no mês, 14 desconsiderados e 69 faturados. Inclui admissão parcial, saídas na competência, ajuste manual de dias e associação explícita de perfis compartilhados.

O cenário inicial totalizou 1.994 dias e R$ 432.576,09. Ao alterar um ajuste de 12 dias para zero no Excel, o recálculo produziu 68 profissionais, 1.982 dias e R$ 430.685,09. A frente correspondente e o INS10 foram atualizados pelas mesmas fórmulas; as oito saídas da janela permaneceram iguais. Nenhum erro de fórmula foi encontrado.

Esses números comprovam o comportamento do código e não representam uma nova validação dos dados reais de junho. Os arquivos de teste gerados, dados de referência do cliente e ferramentas de execução não acompanham o pacote.

## Atualização e limites

Substitua os arquivos de `g4freports` sem desinstalar ou apagar configurações. Recarregue a tela, confirme a versão 1.8.19, confira a frente dos profissionais e gere novamente a competência com as regras `ses-2026-10-05-v16`.

Se já houver uma composição personalizada, ela será mantida. Para aplicar a referência fornecida nesta versão, use Usar referência contratual SES e salve a composição. Selecione a frente dos analistas cujo perfil pertence tanto a N2 quanto a Monitoramento.

As consultas aos bancos, satisfação telefônica, regras de SLA, reaberturas, cálculo do INS10, rateio individual e marcações Não Faturar não foram alterados.

Não houve instalação no GLPI do cliente nem acesso aos bancos de produção. A validação de abertura, renderização e recálculo utilizou LibreOffice e ferramentas de inspeção OOXML; não foi executado Microsoft Office neste ambiente.
