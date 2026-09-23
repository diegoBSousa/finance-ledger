# Finance Ledger — etapa 06

Teste técnico em implementação incremental: API Laravel 13/PHP 8.4 e SPA Vue 3/TypeScript independentes, com MySQL 8.4, Redis e worker. Esta versão acrescenta consulta autenticada de saldos, recálculo consistente sob concorrência e o processo independente de projeção.

## Implementado até esta etapa

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

- Docker Compose com `app`, `web`, `mysql`, `redis`, `worker`, `balance-projector` e `frontend`.
- Dependências PHP/JavaScript travadas em `composer.lock` e `package-lock.json`; imagens oficiais identificadas por digest.
- Endpoint operacional `GET /api/v1/health`, resposta JSON e CORS para o frontend.
- Tela inicial que consulta a API, indica indisponibilidade e permite tentar novamente.
- Argon2id configurado; Redis configurado para cache e fila; volume privado de uploads compartilhado por app/worker.
- Limites de PHP/Nginx preparados para um CSV de até 100.000.000 bytes, com margem para o envelope multipart.
- PHPUnit, Larastan/PHPStan, Pint, Vitest, Vue Test Utils, ESLint, checagem TypeScript e workflow de CI.
- Diagnóstico real de infraestrutura: consulta MySQL, publica um job Redis e confere se o worker leu o arquivo privado escrito pela aplicação.

O caso de uso `PostCsvBatchUseCase` já grava operações financeiras por meio de `MysqlJournalRepository`, usando as contas existentes. A autenticação está disponível na API. O saldo é marcado como `staled` pelo trigger e recalculado na consulta ou pelo `balance-projector`. O evento `LedgerChanged` fica pendente na outbox. Upload/importador de arquivos, relay, consumidor de cache, dashboard e extratos entram nas próximas etapas. O `actorUserId` vem do contexto autenticado/confiável, nunca de um ID livre enviado pelo cliente. A SPA mantém a tela operacional; sua interface de login será implementada com o frontend de negócio.

O endpoint operacional público não expõe dados de negócio. O limite exato de 100.000.000 bytes será validado no futuro caso de uso de upload; PHP/Nginx já têm os limites de transporte. A paginação está conectada à consulta de saldos, com validação HTTP e no DTO.

## Iniciar no Ubuntu

Pré-requisitos: Docker Engine com plugin Docker Compose v2.20 ou superior, Bash e acesso à internet para baixar imagens/dependências. O usuário deve conseguir executar `docker info`. PHP, Composer, Node e MySQL serão executados nos containers.

Depois de extrair o ZIP:

```bash
cd finance-ledger
bash scripts/bootstrap.sh
bash scripts/check.sh
```

O bootstrap gera `.env` com chaves independentes para aplicação/JWT e senhas aleatórias, usa seu UID/GID para os arquivos, instala as versões dos lockfiles, inicia MySQL/Redis, aplica as migrations, executa o seed e inicia os serviços. Por fim, executa o diagnóstico de fila e volume. Ao executar novamente, preserva configurações e volumes existentes e acrescenta as variáveis de demonstração/JWT se estiverem ausentes.

As 900 contas financeiras pertencem ao usuário definido por `DEMO_USER_EMAIL` (padrão `demo@example.test`). A senha inicial fica em `DEMO_USER_PASSWORD`, gerada pelo bootstrap. O seed usa Argon2id e não troca senhas de usuários existentes. Funciona somente em `local`/`testing`; se uma conta do intervalo já pertencer a outra pessoa, aborta e desfaz o seed inteiro. O CSV ainda não é importado e os saldos iniciais são zero.

Ao atualizar para a etapa 06, execute o bootstrap: ele aplica a migration de `staled_since` e inicia `balance-projector`. Preserve `.env` e volumes. O Compose mantém `log_bin_trust_function_creators=1` nos bancos de desenvolvimento/teste, permitindo criar os triggers com o usuário da aplicação.

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
docker compose logs -f app web worker balance-projector
docker compose exec app php artisan route:list
docker compose exec app composer test
docker compose run --rm --no-deps app composer test:core
bash scripts/test-mysql.sh
docker compose exec app php artisan db:seed --force
docker compose exec app php artisan auth:prune-revoked-tokens
docker compose exec app php artisan balances:project --once
docker compose exec frontend npm run test
docker compose exec frontend npm run build
bash scripts/smoke.sh
docker compose stop
docker compose up -d --wait
```

Worker e projector mantêm código carregado em memória. Depois de alterar esses processos, use `docker compose restart worker balance-projector`.

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

`scripts/check.sh` executa validação do Compose, Composer, estilo, análise estática, as três suítes PHP, lint/testes/build frontend e diagnóstico com os serviços reais. `composer test` mantém as suítes de núcleo e HTTP; a integração MySQL é chamada separadamente pelo script.

`bash scripts/test-mysql.sh` inicia `mysql-test` e executa `test-runner` pelo perfil `test`. Esse banco é descartável, usa `tmpfs`, não publica porta e não compartilha o volume de desenvolvimento. A suíte recria somente `finance_ledger_test`, exige `MYSQL_TEST_RESET=1` e recusa configuração em cache. O script para o banco ao terminar. As dependências PHP devem estar instaladas pelo bootstrap.

Consulte [docs/step-06.md](docs/step-06.md) para API, recálculo, concorrência, projector e atualização. A postagem está em [docs/step-05.md](docs/step-05.md), autenticação em [docs/step-04.md](docs/step-04.md) e o contrato HTTP em [docs/openapi.yaml](docs/openapi.yaml).

**371 testes backend passaram, com 1778 assertions**:

| Suíte | Resultado |
| --- | --- |
| Núcleo puro e contratos em memória | 146 testes, 1009 assertions |
| HTTP/JWT e isolamento | 94 testes, 287 assertions |
| MySQL 8.4.11, contratos, HTTP real e concorrência | 131 testes, 482 assertions |

Pint, PHPStan/Larastan nível 6, Composer validate, configuração Compose e sintaxe Bash passaram. As suítes de núcleo/HTTP foram executadas com variáveis de desenvolvimento herdadas do processo. A integração usou o usuário com privilégios somente no banco de testes, incluindo migrations e processos concorrentes. A consulta HTTP de #682 foi validada com JWT e recálculo real no MySQL sem projector em execução.

A execução completa dos containers permanece pendente no Ubuntu/CI porque o ambiente de implementação não tem daemon Docker. O frontend não mudou e não foi revalidado nesta etapa. O schema original está em [docs/step-03.md](docs/step-03.md).

Os dois arquivos PHPUnit configuram tanto `<env force="true">` quanto `<server>`: Laravel consulta `$_SERVER` antes de `$_ENV`/`getenv()`. Isso impede que os valores do Compose selecionem o Redis de desenvolvimento durante os testes e compartilhem contadores de login entre casos/execuções. A correção dos erros 429 da etapa 04 está detalhada em [docs/step-04.md](docs/step-04.md#correção-do-isolamento-dos-testes-no-container). Depois de atualizar esses arquivos, execute novamente `bash scripts/check.sh`; não é necessário refazer o bootstrap ou remover volumes para aplicar esta correção.

A situação da infraestrutura anterior está em [docs/step-01.md](docs/step-01.md). A configuração de CI está incluída, mas não foi enviada a um repositório remoto nem executada no GitHub.

## Próximo incremento

Implementar relay da outbox, upload de CSV até 100.000.000 bytes e acompanhamento da importação. Em seguida, implementar processamento em chunks, checkpoints e retomada idempotente, seguindo o plano aprovado.

As separações arquiteturais estão em [docs/architecture.md](docs/architecture.md), e as regras do teste estão em [docs/decisions.md](docs/decisions.md).
