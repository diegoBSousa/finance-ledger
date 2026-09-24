# Finance Ledger — etapa 10

Teste técnico em implementação incremental: API Laravel 13/PHP 8.4 e SPA Vue 3/TypeScript independentes, com MySQL 8.4, Redis e worker. Esta versão conecta as telas Vue de login, dashboard, contas/saldos, extrato, upload e acompanhamento à API protegida por JWT. Inclui testes frontend e uma stack Cypress descartável.

## Implementado até esta etapa

- SPA independente com Vue Router, sessão JWT em memória e expiração/revogação tratadas na interface.
- Dashboard, contas/saldos e extrato com dinheiro exato em BRL, filtros e páginas de até dez itens.
- Upload de um CSV até 100.000.000 bytes, progresso de envio e acompanhamento separado do processamento.
- Resultados por registro, duplicatas/rejeições, polling cancelável e atualização dos dados financeiros após novos registros.
- 44 testes frontend; suíte Cypress com serviços reais, stack própria e capturas para o CI.

- `GET /api/v1/dashboard`: receitas, despesas, saldo, contagem e revisão do titular, sem dupla contagem das contrapartidas.
- Revisão e totais no mesmo snapshot MySQL; projeções pendentes não atrasam o dashboard.
- Cache Redis por titular/revisão, com comparação e troca atômica, TTL de limpeza e fallback para SQL.
- Consumidor `dashboard-cache-invalidator` ativo, com invalidação idempotente e confirmação durável após sucesso.
- `GET /api/v1/accounts`, `/transactions` e `/accounts/{accountNumber}/transactions`, com filtros e páginas de até 10.
- `GET /api/v1/imports/{importId}/rows` e `/errors`, com autorização pelo titular e filtros de classificação.
- Contagem e registros de cada página de extrato/resultados lidos no mesmo snapshot; DTOs próprios e presenters explícitos.
- Testes de cache real, eventos repetidos, precisão, falhas e snapshots durante commits concorrentes.

- Consumidor `csv-importer` lê até 500 registros por chunk, com limites adicionais de bytes/tempo e suporte a aspas, vírgulas e campos multilinha.
- Resolução de contas em lote, autorização pelo titular, valores em centavos BRL e duas partidas por operação nova.
- Partidas, resultados das linhas, contadores, checkpoint, próxima intenção e confirmação do job são confirmados na mesma transação MySQL.
- Reenvios completos, parciais, reordenados e concorrentes preservam a união das operações únicas.
- Verificação SHA-256 de cada bloco lido, sem percorrer o arquivo inteiro a cada chunk; registros e descrições têm limites próprios.
- Cinco tentativas persistidas por checkpoint, leases de 180 segundos, recuperação de jobs perdidos e proteção contra workers antigos.
- Estados `processing`, `completed`, `completed_with_errors` e `failed` disponíveis no acompanhamento; erros estruturais preservam lotes anteriores.
- CSV fornecido validado: 15.000 operações, 30.000 partidas, 900 contas e reenvio com 15.000 duplicatas e nenhuma nova operação.

- `POST /api/v1/imports`: exatamente um CSV de até 100.000.000 bytes, com JWT, resposta 202 e URL de acompanhamento.
- `GET /api/v1/imports` e `GET /api/v1/imports/{importId}`: somente importações do titular, páginas de até 10 e contadores como strings decimais.
- Armazenamento privado em streaming, nome interno aleatório, SHA-256 e limpeza após falhas quando a ausência de referência SQL é confirmada.
- Importação, evento `ImportRequested` e entrega persistidos juntos no MySQL; o upload não publica diretamente no Redis.
- Serviço `outbox-relay` com lotes de até 10, leases, `SKIP LOCKED`, retentativas e republicação de entregas sem confirmação do consumidor.
- Worker valida integridade/cabeçalho e confirma checkpoint inicial, `prepared_at` e intenção do primeiro chunk na mesma transação.
- Testes reais de Redis, concorrência e multipart, incluindo 100.000.000 bytes com PHP limitado a 64 MB de memória.

- `GET /api/v1/balances` e `GET /api/v1/accounts/{accountNumber}/balance`, com JWT e filtro obrigatório por titular.
- Paginação padrão/máximo de 10, filtro por número externo, precisão em centavos BRL e respostas sem cache HTTP.
- Recálculo em conexão própria `READ COMMITTED`, lock da projeção e reutilização do resultado quando já consistente.
- Erro 503 sem saldo antigo/parcial/zero substituto quando o recálculo falha; somas exatas com proteção contra overflow.
- Serviço `balance-projector` independente do Redis, lotes de até 10, `SKIP LOCKED`, retomada por pendências e métricas JSON.
- `staled_since` registra o início da pendência; novo trigger conserva a idade até um recálculo confirmado.

- Caso de uso de postagem de lotes internos com até 500 operações, usando DTOs e a porta de persistência do journal.
- Cabeçalhos, duas partidas por operação nova e um evento durável por lote confirmados na mesma transação MySQL.
- Duplicatas preservam o ID original; reenvios parciais/sobrepostos não repetem partidas, versões ou eventos.
- Locks por titular e contas em ordem determinística, revalidação das contas antes da escrita e limite de espera de 5 segundos.
- Trigger por nova partida incrementa a revisão do titular e a versão da conta, marcando seu saldo como desatualizado.
- Triggers bloqueiam UPDATE/DELETE do journal e das partidas; posições 1/2 impedem adicionar uma terceira partida à operação completa.
- Testes reais com dois processos/conexões MySQL, lotes sobrepostos, rollback e limite de 500 operações.
- `POST /api/v1/auth/login`, `GET /api/v1/auth/me` e `POST /api/v1/auth/logout`, com controllers pequenos, DTOs, casos de uso e presenters explícitos.
- JWT HS256 de 15 minutos com chave própria, validação de assinatura, emissor, audiência, identidade e datas; biblioteca `lcobucci/jwt` travada no lockfile.
- Argon2id, rehash de parâmetros antigos e resposta uniforme para e-mail inexistente/senha incorreta; limite de 5 tentativas por e-mail/IP e 30 por IP a cada minuto.
- Revogação durável por token, consultada no MySQL em toda requisição protegida; logout de uma sessão preserva as outras.
- Contratos de usuário/revogação testados em memória e no MySQL, sem expor models ou tipos JWT ao núcleo.
- Nove tabelas financeiras com índices, FKs e CHECKs: contas, lançamentos, partidas, projeções, revisões, importações, resultados de linhas e outbox.
- Unicidade do hash por titular e chaves compostas que impedem vínculos de partidas com contas/lançamentos de outro proprietário.
- `MysqlAccountRepository` registrado no container; `AccountRecord::toData()` entrega DTOs próprios, preservando identificadores como strings.
- Seed repetível das contas financeiras #100–#999 e duas contrapartidas, com usuário de demonstração explícito e senha Argon2id.
- Provisionamento transacional de conta, estado e projeção inicialmente zerada; seed preserva dados existentes e recusa conflitos de titularidade.
- Integração executada em MySQL 8.4, incluindo os mesmos contratos dos doubles, constraints, rollback, seeds, revogação e reversão/reaplicação das migrations.
- Domínio imutável: `Money`, contas, tipos contábeis, partidas e lançamentos balanceados; centavos exatos com proteção contra overflow de 64 bits.
- Receita debita a conta financeira e credita receitas; despesa debita despesas e credita a conta financeira. Saldos de ativo podem ficar negativos.
- Canonicalização UTF-8/NFC e SHA-256 versionado das linhas interpretadas do CSV, com exemplos de referência fixos.
- `PrepareCsvPostingUseCase`, Request/Response DTOs e contrato `AccountRepository` que retorna DTOs próprios. Entidades expõem `toData()`.
- Autorização por proprietário no caso de uso, validação de contas ativas e resolução explícita das contrapartidas.
- `PageRequest` com padrão/máximo 10 e proteção contra overflow do offset.
- Testes do núcleo, contratos reutilizáveis e verificação das dependências por análise da árvore de sintaxe. A suíte isolada bloqueia o carregamento de Laravel e adapters.

- Docker Compose com `app`, `web`, `mysql`, `redis`, `worker`, `outbox-relay`, `balance-projector` e `frontend`.
- Dependências PHP/JavaScript travadas em `composer.lock` e `package-lock.json`; imagens oficiais identificadas por digest.
- Endpoint operacional `GET /api/v1/health`, resposta JSON e CORS para o frontend.
- Telas de negócio com estados de carregamento, falha, ausência de registros e nova tentativa.
- Argon2id configurado; Redis configurado para cache e fila; volume privado de uploads compartilhado por app/worker.
- Limites de PHP/Nginx preparados para um CSV de até 100.000.000 bytes, com margem para o envelope multipart.
- PHPUnit, Larastan/PHPStan, Pint, Vitest, Vue Test Utils, ESLint, checagem TypeScript e workflow de CI.
- Diagnóstico real de infraestrutura: consulta MySQL, publica um job Redis e confere se o worker leu o arquivo privado escrito pela aplicação.

O upload responde 202 e o worker prepara e processa o arquivo em background. O relay publica para `import-preparer`, `csv-importer` e `dashboard-cache-invalidator`. `ProcessImportChunkUseCase` reutiliza a preparação contábil e `MysqlJournalRepository` na transação do checkpoint. O saldo é marcado como `staled` pelo trigger e recalculado na consulta ou pelo `balance-projector`. O consumidor de `LedgerChanged` remove revisões anteriores do cache; a consulta SQL da revisão impede leituras antigas mesmo se o evento atrasar. O `actorUserId` vem do JWT, nunca de um ID livre enviado pelo cliente. A SPA consome esses contratos por API; não soma saldos no navegador nem persiste o token.

O endpoint operacional público não expõe dados de negócio. O limite exato de 100.000.000 bytes é verificado no upload; o corpo multipart admite 110.000.000 bytes para acomodar o envelope. A paginação está conectada aos saldos e às importações, com validação HTTP e no DTO. O login recebe JSON; o upload usa multipart com um único campo `file`.

## Iniciar no Ubuntu

Pré-requisitos: Docker Engine com plugin Docker Compose v2.20 ou superior, Bash e acesso à internet para baixar imagens/dependências. O usuário deve conseguir executar `docker info`. PHP, Composer, Node e MySQL serão executados nos containers.

Depois de extrair o ZIP:

```bash
cd finance-ledger
bash scripts/bootstrap.sh
bash scripts/check.sh
```

O bootstrap gera `.env` com chaves independentes para aplicação/JWT e senhas aleatórias, usa seu UID/GID para os arquivos, instala as versões dos lockfiles, inicia MySQL/Redis, aplica as migrations, executa o seed e inicia os serviços. Por fim, executa o diagnóstico de fila e volume. Ao executar novamente, preserva configurações e volumes existentes e acrescenta as variáveis de demonstração/JWT se estiverem ausentes.

As 900 contas financeiras pertencem ao usuário definido por `DEMO_USER_EMAIL` (padrão `demo@example.test`). A senha inicial fica em `DEMO_USER_PASSWORD`, gerada pelo bootstrap. O seed usa Argon2id e não troca senhas de usuários existentes. Funciona somente em `local`/`testing`; se uma conta do intervalo já pertencer a outra pessoa, aborta e desfaz o seed inteiro. O seed não carrega o CSV e as contas novas começam com saldo zero.

Ao atualizar para a etapa 10, execute o bootstrap: ele aplica eventuais migrations anteriores e reinicia worker, relay e projector para carregar o código novo, mesmo quando a imagem Docker não mudou. Esta etapa não acrescenta migrations; o bootstrap instala também as novas dependências frontend. Preserve `.env` e volumes. O Compose mantém `log_bin_trust_function_creators=1` nos bancos de desenvolvimento/teste, permitindo criar os triggers com o usuário da aplicação.

O primeiro build pode levar alguns minutos. Os serviços ficam disponíveis em:

| Serviço | Endereço padrão |
| --- | --- |
| Frontend | http://localhost:5173 |
| API operacional | http://localhost:8080/api/v1/health |

A API deve responder `{"status":"ok","service":"finance-ledger-api"}`; a página deve mostrar o formulário de login. Use as credenciais de demonstração definidas no `.env`. Essa rota verifica a inicialização HTTP. A disponibilidade de banco, fila e volume é verificada pelo comando de diagnóstico.

MySQL, Redis e PHP-FPM não publicam portas no host. Os dois endereços web são vinculados a `127.0.0.1`. Esta composição é para desenvolvimento local.

## Comandos do dia a dia

```bash
docker compose ps
docker compose logs -f app web worker outbox-relay balance-projector
docker compose exec app php artisan route:list
docker compose exec app composer test
docker compose run --rm --no-deps app composer test:core
bash scripts/test-mysql.sh
docker compose exec app php artisan db:seed --force
docker compose exec app php artisan auth:prune-revoked-tokens
docker compose exec app php artisan balances:project --once
docker compose exec app php artisan outbox:relay --once
docker compose exec frontend npm run test
docker compose exec frontend npm run build
bash scripts/smoke.sh
docker compose stop
docker compose up -d --wait
```

Worker, relay e projector mantêm código carregado em memória. Depois de alterar esses processos, use `docker compose restart worker outbox-relay balance-projector`.

`docker compose down` remove os containers e preserva os volumes. A opção `--volumes` também apaga os bancos/arquivos persistidos; o workflow usa essa opção apenas no ambiente descartável da CI.

Para trocar as portas, ajuste `API_PORT`, `FRONTEND_PORT`, `APP_URL`, `CORS_ALLOWED_ORIGINS` e `VITE_API_BASE_URL` no `.env`, mantendo os endereços consistentes, e recrie os serviços com `docker compose up -d --force-recreate`. O diagnóstico considera o frontend em `http://localhost:<FRONTEND_PORT>`.

## Organização

| Caminho | Responsabilidade |
| --- | --- |
| `backend/` | Laravel, adapters HTTP/CLI, infraestrutura e testes PHP |
| `frontend/` | SPA Vue, cliente HTTP e testes de componentes |
| `docker/` | Imagem PHP e configuração Nginx |
| `scripts/` | Inicialização e verificações locais/CI |
| `docs/` | Arquitetura, decisões e situação desta etapa |
| `.github/workflows/ci.yml` | Build e testes com a composição real no GitHub Actions |

O repositório usa um único `.env` na raiz. Compose passa as variáveis necessárias ao PHP e não repassa a senha root do MySQL à aplicação. Não é necessário executar `artisan key:generate`: o bootstrap já cria `APP_KEY`.

## Testes e situação de validação

`composer test` executa a suíte isolada do núcleo e depois a suíte HTTP em processos separados. Para rodar somente o núcleo, depois de instalar as dependências:

```bash
docker compose run --rm --no-deps app composer test:core
```

Esse comando não precisa iniciar MySQL, Redis, worker nem o kernel Laravel. Para execução local com PHP 8.4 e Composer, use `composer install` e `composer test:core` dentro de `backend/`.

`scripts/check.sh` executa validação do Compose, Composer, estilo, análise estática, as três suítes PHP, lint/testes/build frontend, tipos do Cypress, diagnóstico com os serviços reais e E2E em uma composição descartável separada. `composer test` mantém as suítes de núcleo e HTTP; a integração MySQL/Redis é chamada separadamente pelo script.

`bash scripts/test-mysql.sh` recria `mysql-test` e `redis-test` com `--force-recreate` e executa `test-runner` pelo perfil `test`. Esses serviços são descartáveis, usam `tmpfs`, não publicam portas e não compartilham os volumes de desenvolvimento. A recriação evita reutilizar containers que ainda referenciem uma rede removida entre execuções do Compose. A suíte recria somente `finance_ledger_test`, exige `MYSQL_TEST_RESET=1` e recusa configuração em cache. Os testes Redis exigem `REDIS_TEST_ENABLED=1`, usam prefixos exclusivos e removem somente suas próprias chaves. O script para os dois serviços ao terminar. As dependências PHP devem estar instaladas pelo bootstrap.

Consulte [docs/step-10.md](docs/step-10.md) para as telas, sessão, polling e execução dos novos testes E2E. Veja [docs/step-09.md](docs/step-09.md) para dashboard, cache, extratos, resultados e atualização; [docs/step-08.md](docs/step-08.md) cobre chunks, limites e retomada. O upload está em [docs/step-07.md](docs/step-07.md), os saldos em [docs/step-06.md](docs/step-06.md), a postagem em [docs/step-05.md](docs/step-05.md), autenticação em [docs/step-04.md](docs/step-04.md) e o contrato HTTP em [docs/openapi.yaml](docs/openapi.yaml).

**556 testes backend passaram, com 3345 asserções**, também confirmados pelo log da etapa 09 enviado pelo usuário no Ubuntu:

| Suíte | Resultado |
| --- | --- |
| Núcleo puro e contratos em memória | 188 testes, 1811 asserções |
| HTTP/JWT, armazenamento, parser CSV e isolamento | 165 testes, 445 asserções |
| MySQL 8.4.11, Redis 8.2.1, HTTP real e concorrência | 203 testes, 1089 asserções |

Pint, PHPStan/Larastan nível 6, Composer validate, configuração Compose e sintaxe Bash passaram. A integração usou MySQL e Redis reais e processos PHP independentes. Validou publicação, consumo, perda/republicação de jobs, concorrência, rollback de cada parte do checkpoint e perda da resposta de um commit já confirmado. O CSV fornecido foi importado integralmente e reenviado: 15.000 operações únicas, 30.000 partidas e nenhuma nova operação no reenvio. A consulta da conta #682 confirmou R$ 1.092,09 após recalcular sua projeção.

As novas consultas confirmaram receitas de R$ 36.119.609,74, despesas de R$ 26.709.545,74 e saldo de R$ 9.410.064,00 no dashboard, preservados após reenvio do CSV. Os testes incluem snapshots durante commits concorrentes, autorização e filtros, precisão de 64 bits, Redis indisponível com fallback SQL, invalidação repetida e recuperação após falha da confirmação MySQL. O mesmo contrato do cache passou em memória e no Redis real. O OpenAPI 0.9.0 foi conferido contra as 15 operações HTTP registradas.

O transporte multipart foi exercitado com servidor HTTP PHP 8.4 e `memory_limit=64M`: 100.000.000 bytes aceitos, um byte acima recusado e campos extras/arquivos repetidos rejeitados. Esse cenário verifica upload/preparação. O processamento financeiro completo foi validado com o CSV fornecido; a medição de importação financeira de 100 MB permanece para a etapa de desempenho.

Na etapa 10, **44 testes frontend**, lint, TypeScript, build, tipos Cypress, Bash e configuração Compose passaram. Um ensaio integrado dos componentes Vue em jsdom, com HTTP, MySQL, Redis e workers reais, também passou com o CSV de 15 mil registros, recálculo de saldo, reenvios parciais e revogação do token.

A execução da **nova suíte Cypress e a revisão visual em navegador permanecem pendentes no Ubuntu/CI**: não há daemon Docker aqui e o download do navegador retornou uma página de indisponibilidade. A suíte já faz parte do check e usa um projeto Compose próprio, sem os volumes ou credenciais de desenvolvimento. O primeiro build baixa Cypress; screenshots ficam em `frontend/artifacts/`. Veja [docs/step-10.md](docs/step-10.md) para evidências e limites. O schema original está em [docs/step-03.md](docs/step-03.md).

O log enviado após a etapa 09 confirmou o bootstrap, os diagnósticos de infraestrutura e todas as suítes no Docker do Ubuntu. A correção anterior de `network not found` foi preservada em `scripts/test-mysql.sh`; seu contexto está em [docs/step-07.md](docs/step-07.md#correção-de-network-not-found-nos-testes). Não é necessário remover volumes de desenvolvimento para atualizar ou executar os testes.

Os dois arquivos PHPUnit configuram tanto `<env force="true">` quanto `<server>`: Laravel consulta `$_SERVER` antes de `$_ENV`/`getenv()`. Isso impede que os valores do Compose selecionem o Redis de desenvolvimento durante os testes e compartilhem contadores de login entre casos/execuções. A correção dos erros 429 da etapa 04 está detalhada em [docs/step-04.md](docs/step-04.md#correção-do-isolamento-dos-testes-no-container). Depois de atualizar esses arquivos, execute novamente `bash scripts/check.sh`; não é necessário refazer o bootstrap ou remover volumes para aplicar esta correção.

A situação da infraestrutura anterior está em [docs/step-01.md](docs/step-01.md). A configuração de CI está incluída, mas não foi enviada a um repositório remoto nem executada no GitHub.

## Próximo incremento

Validar a escala: importação financeira completa de 100 MB, concorrência sustentada, memória, retomada e consultas com EXPLAIN, conforme a próxima etapa do plano aprovado.

As separações arquiteturais estão em [docs/architecture.md](docs/architecture.md), e as regras do teste estão em [docs/decisions.md](docs/decisions.md).
