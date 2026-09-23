# Finance Ledger — etapa 07

Teste técnico em implementação incremental: API Laravel 13/PHP 8.4 e SPA Vue 3/TypeScript independentes, com MySQL 8.4, Redis e worker. Esta versão acrescenta upload privado de CSV, acompanhamento autenticado, publicação recuperável da outbox e preparação inicial do arquivo.

## Implementado até esta etapa

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
- Tela inicial que consulta a API, indica indisponibilidade e permite tentar novamente.
- Argon2id configurado; Redis configurado para cache e fila; volume privado de uploads compartilhado por app/worker.
- Limites de PHP/Nginx preparados para um CSV de até 100.000.000 bytes, com margem para o envelope multipart.
- PHPUnit, Larastan/PHPStan, Pint, Vitest, Vue Test Utils, ESLint, checagem TypeScript e workflow de CI.
- Diagnóstico real de infraestrutura: consulta MySQL, publica um job Redis e confere se o worker leu o arquivo privado escrito pela aplicação.

O caso de uso `PostCsvBatchUseCase` já grava operações financeiras por meio de `MysqlJournalRepository`, usando as contas existentes. O saldo é marcado como `staled` pelo trigger e recalculado na consulta ou pelo `balance-projector`. O upload agora está disponível, mas o worker desta etapa prepara somente o arquivo: uma importação válida permanece `pending`, com `prepared_at` preenchido e contadores zerados, até a implementação dos chunks. As entregas para `csv-importer` e `dashboard-cache-invalidator` permanecem duráveis e pendentes; o relay publica somente para `import-preparer`. O `actorUserId` vem do JWT, nunca de um ID livre enviado pelo cliente. A SPA mantém a tela operacional; as telas de negócio entram depois do backend.

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

Ao atualizar para a etapa 07, execute o bootstrap: ele reconstrói a imagem PHP com o parsing multipart explícito, aplica a migration de metadados da importação e inicia `outbox-relay` e o worker nas filas `imports,default`. Preserve `.env` e volumes. O Compose mantém `log_bin_trust_function_creators=1` nos bancos de desenvolvimento/teste, permitindo criar os triggers com o usuário da aplicação.

O primeiro build pode levar alguns minutos. Os serviços ficam disponíveis em:

| Serviço | Endereço padrão |
| --- | --- |
| Frontend | http://localhost:5173 |
| API operacional | http://localhost:8080/api/v1/health |

A API deve responder `{"status":"ok","service":"finance-ledger-api"}`; a página deve mostrar “Conexão estabelecida.”. Essa rota verifica a inicialização HTTP. A disponibilidade de banco, fila e volume é verificada pelo comando de diagnóstico.

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

`scripts/check.sh` executa validação do Compose, Composer, estilo, análise estática, as três suítes PHP, lint/testes/build frontend e diagnóstico com os serviços reais. `composer test` mantém as suítes de núcleo e HTTP; a integração MySQL/Redis é chamada separadamente pelo script.

`bash scripts/test-mysql.sh` recria `mysql-test` e `redis-test` com `--force-recreate` e executa `test-runner` pelo perfil `test`. Esses serviços são descartáveis, usam `tmpfs`, não publicam portas e não compartilham os volumes de desenvolvimento. A recriação evita reutilizar containers que ainda referenciem uma rede removida entre execuções do Compose. A suíte recria somente `finance_ledger_test`, exige `MYSQL_TEST_RESET=1` e recusa configuração em cache. Os testes Redis exigem `REDIS_TEST_ENABLED=1`, usam prefixos exclusivos e removem somente suas próprias chaves. O script para os dois serviços ao terminar. As dependências PHP devem estar instaladas pelo bootstrap.

Consulte [docs/step-07.md](docs/step-07.md) para upload, status, recuperação da outbox e atualização. Os saldos estão em [docs/step-06.md](docs/step-06.md), a postagem em [docs/step-05.md](docs/step-05.md), autenticação em [docs/step-04.md](docs/step-04.md) e o contrato HTTP em [docs/openapi.yaml](docs/openapi.yaml).

**435 testes backend passaram, com 2342 assertions**:

| Suíte | Resultado |
| --- | --- |
| Núcleo puro e contratos em memória | 158 testes, 1303 assertions |
| HTTP/JWT, armazenamento e isolamento | 116 testes, 363 assertions |
| MySQL 8.4.11, Redis 8.2.1, HTTP real e concorrência | 161 testes, 676 assertions |

Pint, PHPStan/Larastan nível 6, Composer validate, configuração Compose e sintaxe Bash passaram. As suítes de núcleo/HTTP foram executadas com variáveis de desenvolvimento herdadas do processo. A integração usou MySQL e Redis reais e processos PHP independentes. Validou publicação, consumo, perda/republicação de jobs, concorrência e rollback. O transporte multipart foi exercitado com servidor HTTP PHP 8.4 e `memory_limit=64M`: 100.000.000 bytes aceitos, um byte acima recusado e campos extras/arquivos repetidos rejeitados. Isso verifica upload/preparação, não o desempenho da futura importação financeira completa.

A execução completa dos containers permanece pendente no Ubuntu/CI porque o ambiente de implementação não tem daemon Docker. O frontend não mudou e não foi revalidado nesta etapa. O schema original está em [docs/step-03.md](docs/step-03.md).

No log de validação no Ubuntu enviado após esta entrega, o bootstrap, os diagnósticos de infraestrutura, Pint, PHPStan e os 274 testes de núcleo/HTTP passaram. O check parou antes da integração porque `mysql-test` referenciava uma rede inexistente. A correção de inicialização está em [docs/step-07.md](docs/step-07.md#correção-de-network-not-found-nos-testes). Depois de atualizar `scripts/test-mysql.sh`, basta executar `bash scripts/check.sh`; esse ajuste não exige bootstrap, rebuild ou remoção dos volumes de desenvolvimento. A sintaxe Bash e a configuração Compose foram verificadas, mas a recuperação no Docker precisa ser confirmada no Ubuntu.

Os dois arquivos PHPUnit configuram tanto `<env force="true">` quanto `<server>`: Laravel consulta `$_SERVER` antes de `$_ENV`/`getenv()`. Isso impede que os valores do Compose selecionem o Redis de desenvolvimento durante os testes e compartilhem contadores de login entre casos/execuções. A correção dos erros 429 da etapa 04 está detalhada em [docs/step-04.md](docs/step-04.md#correção-do-isolamento-dos-testes-no-container). Depois de atualizar esses arquivos, execute novamente `bash scripts/check.sh`; não é necessário refazer o bootstrap ou remover volumes para aplicar esta correção.

A situação da infraestrutura anterior está em [docs/step-01.md](docs/step-01.md). A configuração de CI está incluída, mas não foi enviada a um repositório remoto nem executada no GitHub.

## Próximo incremento

Implementar o consumidor `csv-importer`: leitura em streaming de até 500 registros por chunk, resultados por linha, checkpoint e próximo evento na mesma transação. Validar retomada, reenvios parciais/sobrepostos e concorrência com o arquivo fornecido, preservando centavos BRL, contas no sufixo e partidas dobradas.

As separações arquiteturais estão em [docs/architecture.md](docs/architecture.md), e as regras do teste estão em [docs/decisions.md](docs/decisions.md).
