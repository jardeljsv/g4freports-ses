# Relatórios G4F 1.7.0

## Escopo

Configuração e teste de duas fontes SQL independentes para o relatório mensal SES: GLPI/MariaDB e Callcenter/MySQL. A API dos relatórios existentes permanece em uso. O módulo mensal com indicadores, tabela de números de teste e exportações Word/Excel será uma etapa posterior; este pacote prepara suas conexões.

## Alterações

- Duas seções na configuração administrativa, com servidor, porta, banco, usuário, senha, variável de ambiente, TLS e limites de tempo.
- Preenchimento de endereço a partir de JDBC MySQL/MariaDB, sem credenciais ou parâmetros na URL.
- Testes com valores salvos, sem envio de senha em GET, usando apenas consultas de diagnóstico fixas e sem recuperação de registros.
- Criptografia nativa GLPI, registro para rotação de chave, retenção de senha quando o campo está em branco e remoção explícita.
- Diagnóstico de acesso às tabelas/colunas das consultas fornecidas, incluindo pesquisa telefônica opcional.
- Atualização adiciona somente chaves ausentes, preservando configurações existentes.
- Sem alterações nos arquivos do fluxo atual de extração, nas páginas de relatório/API, no gerador DOCX ou no papel timbrado.

## Verificação

- Sintaxe PHP: 29 arquivos aprovados em PHP 8.3.
- Conector: 46 verificações com PHP/mysqlnd e MariaDB 10.11 local, incluindo sucesso com leitura, tabelas/colunas ausentes, permissões insuficientes, senha incorreta, banco inexistente, falha de rede e recusa de conexão sem TLS quando a verificação é exigida.
- Configuração: 31 verificações com representações controladas de Config/GLPIKey, incluindo retenção/remoção da senha, senha literal “0”, espaços, variáveis de ambiente, fontes independentes e preservação na atualização.
- Endpoint: acesso administrativo, método, seleção da fonte e conexão desabilitada verificados.
- Interface: sintaxe JavaScript, 3 endereços JDBC válidos e 14 inválidos, uso exclusivo de configuração salva, bloqueio após edição, saída como texto e renderização Twig 3.8 em modo estrito.

Não houve acesso aos bancos de produção. Ainda é necessário validar a instalação/atualização no GLPI real e testar as conexões a partir daquele servidor. Não foi verificada uma conexão TLS bem-sucedida com a cadeia de certificados de produção. A renderização Twig foi verificada; não houve inspeção visual em um navegador GLPI completo.

## Referências de compatibilidade

O fluxo de gravação e criptografia foi conferido no código oficial GLPI 10.0.20 e 11.0.0:

- https://github.com/glpi-project/glpi/blob/10.0.20/src/Config.php
- https://github.com/glpi-project/glpi/blob/11.0.0/src/Config.php
- https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/hooks.html#unclassified
- https://www.php.net/manual/en/mysqli.options.php
- https://www.php.net/manual/en/mysqli.real-connect.php
