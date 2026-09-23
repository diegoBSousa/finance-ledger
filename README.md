# Finance Ledger — etapa 03

Teste técnico em implementação incremental: API Laravel 13/PHP 8.4 e SPA Vue 3/TypeScript independentes, com MySQL 8.4, Redis e worker. Esta versão acrescenta o modelo persistente e o repositório de contas ao núcleo contábil.

## Implementado até esta etapa

- Nove tabelas financeiras com índices, FKs e CHECKs: contas, lançamentos, partidas, projeções, revisões, importações, resultados de linhas e outbox.
- Unicidade do hash por titular e chaves compostas que impedem vínculos de partidas com contas/lançamentos de outro proprietário.
- `MysqlAccountRepository` registrado no container; `AccountRecord::toData()` entrega DTOs próprios, preservando identificadores como strings.
- Seed repetível das contas financeiras #100–#999 e duas contrapartidas, com usuário de demonstração explícito e senha Argon2id.
- Provisionamento transacional de conta, estado e projeção inicialmente zerada; seed preserva dados existentes e recusa conflitos de titularidade.
- 58 testes de integração executados em MySQL 8.4, incluindo os mesmos contratos do double, constraints, rollback, seeds e reversão/reaplicação das migrations.
- Domínio imutável: `Money`, contas, tipos contábeis, partidas e lançamentos balanceados; centavos exatos com proteção contra overflow de 64 bits.
- Receita debita a conta financeira e credita receitas; despesa debita despesas e credita a conta financeira. Saldos de ativo podem ficar negativos.
- Canonicalização UTF-8/NFC e SHA-256 versionado das linhas interpretadas do CSV, com exemplos de referência fixos.
- `PrepareCsvPostingUseCase`, Request/Response DTOs e contrato `AccountRepository` que retorna DTOs próprios. Entidades expõem `toData()`.
- Autorização por proprietário no caso de uso, validação de contas ativas e resolução explícita das contrapartidas.
- `PageRequest` com padrão/máximo 10 e proteção contra overflow do offset.
- 92 testes do núcleo, contrato reutilizável de repositório e verificação das dependências por análise da árvore de sintaxe. A suíte isolada bloqueia o carregamento de Laravel e adapters.

- Docker Compose com `app`, `web`, `mysql`, `redis`, `worker` e `frontend`.
- Dependências PHP/JavaScript travadas em `composer.lock` e `package-lock.json`; imagens oficiais identificadas por digest.
- Endpoint operacional `GET /api/v1/health`, resposta JSON e CORS para o frontend.
- Tela inicial que consulta a API, indica indisponibilidade e permite tentar novamente.
- Argon2id configurado; Redis configurado para cache e fila; volume privado de uploads compartilhado por app/worker.
- Limites de PHP/Nginx preparados para um CSV de até 100.000.000 bytes, com margem para o envelope multipart.
- PHPUnit, Larastan/PHPStan, Pint, Vitest, Vue Test Utils, ESLint, checagem TypeScript e workflow de CI.
- Diagnóstico real de infraestrutura: consulta MySQL, publica um job Redis e confere se o worker leu o arquivo privado escrito pela aplicação.

O caso de uso **prepara e valida** o lançamento usando contas reais do MySQL; ainda não grava operações financeiras. As estruturas de saldo, importação e outbox existem, mas seus serviços ainda não foram implementados. Login/JWT, endpoints financeiros, postagem transacional, trigger de `staled`, recálculo, importador, dashboard e processos financeiros independentes entram nas próximas etapas. O `actorUserId` deverá vir da autenticação ou do contexto confiável da importação, nunca de um ID livre enviado pelo cliente.

O endpoint operacional público não expõe dados de negócio. O limite exato de 100.000.000 bytes será validado no futuro caso de uso de upload; PHP/Nginx já têm os limites de transporte. A paginação está validada no DTO, e será conectada aos endpoints nas etapas correspondentes.

## Iniciar no Ubuntu

Pré-requisitos: Docker Engine com plugin Docker Compose v2.20 ou superior, Bash e acesso à internet para baixar imagens/dependências. O usuário deve conseguir executar `docker info`. PHP, Composer, Node e MySQL serão executados nos containers.

Depois de extrair o ZIP:

```bash
cd finance-ledger
bash scripts/bootstrap.sh
bash scripts/check.sh
```

O bootstrap gera `.env` com chave e senhas aleatórias, usa seu UID/GID para os arquivos, instala as versões dos lockfiles, inicia MySQL/Redis, aplica as migrations, executa o seed e inicia os serviços. Por fim, executa o diagnóstico de fila e volume. Ao executar novamente, preserva configurações e volumes existentes e acrescenta as variáveis de demonstração se estiverem ausentes.

As 900 contas financeiras pertencem ao usuário definido por `DEMO_USER_EMAIL` (padrão `demo@example.test`). A senha inicial fica em `DEMO_USER_PASSWORD`, gerada pelo bootstrap. O seed usa Argon2id e não troca senhas de usuários existentes. Funciona somente em `local`/`testing`; se uma conta do intervalo já pertencer a outra pessoa, aborta e desfaz o seed inteiro. O CSV ainda não é importado e os saldos iniciais são zero.

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
docker compose logs -f app web worker
docker compose exec app php artisan route:list
docker compose exec app composer test
docker compose run --rm --no-deps app composer test:core
bash scripts/test-mysql.sh
docker compose exec app php artisan db:seed --force
docker compose exec frontend npm run test
docker compose exec frontend npm run build
bash scripts/smoke.sh
docker compose stop
docker compose up -d --wait
```

O worker mantém o código carregado em memória. Depois de mudar jobs, use `docker compose restart worker`.

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

Consulte [docs/step-03.md](docs/step-03.md) para o schema, os comandos e os resultados: **155 testes backend passaram**, sendo 92 do núcleo, 5 HTTP e 58 no MySQL 8.4.11. Compose e scripts foram validados estaticamente; a execução dos containers permanece pendente no Ubuntu/CI porque o ambiente de implementação não tem daemon Docker. Frontend não foi alterado nesta etapa.

A situação da infraestrutura anterior está em [docs/step-01.md](docs/step-01.md). A configuração de CI está incluída, mas não foi enviada a um repositório remoto nem executada no GitHub.

## Próximo incremento

Implementar autenticação JWT, login/logout com revogação durável, usuário atual e autorização na API. Depois vêm postagem transacional, trigger de invalidação e recálculo de saldos, seguindo o plano aprovado.

As separações arquiteturais estão em [docs/architecture.md](docs/architecture.md), e as regras do teste estão em [docs/decisions.md](docs/decisions.md).
