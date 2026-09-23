# Arquitetura e fronteiras

A etapa 06 acrescenta recálculo consistente, consulta de saldos e processo independente de projeção. O núcleo contábil e os casos de uso continuam testáveis sem Laravel; os adapters MySQL implementam locks, transações e outbox.

## Direção das dependências

| Camada | Conteúdo | Pode depender de |
| --- | --- | --- |
| `Domain` | Money, contas, lados, lançamentos e invariantes | PHP e tipos próprios |
| `Application` | DTOs, casos de uso e portas de persistência | Domain e tipos próprios |
| `Infrastructure` | Repositórios MySQL, records Eloquent, fila e armazenamento | Application, Domain e Laravel |
| `Http` / `Console` | Entrada HTTP/CLI, autenticação e apresentação | Application e Laravel |

Controllers de negócio fazem validação de transporte, constroem RequestDTOs e chamam casos de uso injetados. Os casos de uso retornam ResponseDTOs próprios. Nenhum contrato interno recebe `Request`, `UploadedFile`, `Model`, `Collection`, `Paginator`, `Carbon` ou tipos da biblioteca JWT.

`Account`, `Posting` e `JournalEntry` implementam `toData()` retornando DTOs imutáveis de `Domain/Accounting/Data`. Assim, as entidades não dependem da camada Application nem de um formato HTTP. O caso de uso envolve `JournalEntryData` em seu próprio ResponseDTO. `Infrastructure/Persistence/Models/AccountRecord` também implementa `toData()`, com casts de identificadores para strings; o repositório não expõe models.

Inserções em lote terão adapters próprios e não dependerão de eventos individuais dos models.

## Fluxo implementado

`PrepareCsvPostingRequest` contém o ator confiável e os quatro campos já interpretados de uma linha CSV. `PrepareCsvPostingUseCase` canonicaliza a linha, pede a conta financeira ao `AccountRepository`, confere a titularidade, resolve a conta técnica e constrói o agregado balanceado. A resposta contém dados próprios: hash, texto canônico, descrição original para auditoria e `JournalEntryData` com duas partidas.

`PrepareCsvPostingUseCase` continua somente preparando dados. `PostCsvBatchUseCase` o reutiliza e envia `PostingBatchData` à porta `JournalRepository`. O adapter MySQL revalida o agregado com contas bloqueadas, confirma operações novas e grava o evento `LedgerChanged` e sua entrega pendente na mesma transação. `UNIQUE(owner_user_id, source_row_hash)` complementa o lock do titular; a resposta mantém a ordem das linhas e o ID original das duplicatas. O hash sozinho não oferece idempotência transacional.

`AccountRepository` é somente uma porta de leitura. Seu double está em `tests/Doubles`; `MysqlAccountRepository` é a implementação registrada no container Laravel. Ambos herdam os mesmos testes de `AccountRepositoryContract`. Nenhuma interface foi ampliada para acomodar métodos de Eloquent.

`AccountProvisioner` é uma operação de infraestrutura utilizada pelos seeds. Em uma transação, verifica o usuário, serializa o provisionamento daquele titular e cria estado, conta e projeção. Não é um caso de uso HTTP nem permite criar implicitamente contas durante a importação. Um futuro fluxo de criação de conta terá seu próprio caso de uso/contrato quando existir essa necessidade.

As migrations são específicas de MySQL 8.4/InnoDB. FKs compostas preservam proprietário/moeda entre cabeçalho, partidas e contas. CHECKs validam valores e estados de uma linha; o balanceamento do agregado continua no domínio e será persistido atomicamente. O trigger `AFTER INSERT` avança revisão/versão e marca `staled` para cada partida. Quatro triggers bloqueiam UPDATE/DELETE dos registros contábeis. O schema original está em [step-03.md](step-03.md), e o protocolo de postagem/limites está em [step-05.md](step-05.md).

`phpunit.core.xml` usa um bootstrap que bloqueia o autoload de Laravel, Carbon e adapters. `ArchitectureTest` inspeciona nomes resolvidos na árvore de sintaxe e permite apenas dependências internas na direção correta e recursos nativos do PHP. Também verifica que as assinaturas do repositório expõem escalares, enums e DTOs readonly. O parser utilizado nessa verificação é uma dependência exclusiva de desenvolvimento.

BRL é a única moeda representável pelo enum `Currency`; códigos não suportados são rejeitados na conversão dos dados. Adicionar moedas exige rever as invariantes de aritmética e de balanceamento por moeda antes de ampliar esse enum.

`HealthController` é uma verificação operacional sem entrada ou comportamento de negócio; não cria um caso de uso fictício. `CheckInfrastructure` e `ProbeSharedUploadJob` também pertencem à borda técnica. Não servem como modelo para transportar classes Laravel pelas futuras interfaces de domínio/aplicação.

## Postagem e concorrência

Cada lote tem um titular autenticado e até 500 operações. O escritor bloqueia o estado do titular, as projeções por ID crescente e as contas nessa mesma ordem. As contas são revalidadas sob lock para impedir que uma preparação antiga use uma conta desativada. O recálculo bloqueia projeções, sem pedir depois o lock do titular.

A deduplicação usa leitura bloqueante atual, inclusive dentro de uma transação externa com snapshot anterior. Novas operações produzem duas partidas; duplicatas não produzem invalidação ou eventos. O trigger apenas mantém metadados: agregações e publicação Redis ficam fora dele. Uma falha em qualquer escrita desfaz o lote e os efeitos dos triggers.

As conexões MySQL limitam a espera InnoDB a 5 segundos. O adapter tenta até três vezes quando gerencia a transação externa; se for chamado dentro da transação de um futuro importador, o chamador precisa desfazer e retentar o lote externo após uma falha de concorrência. O retorno do repositório só é durável depois do commit externo.

## Consulta e projeção de saldos

`ListAccountBalancesUseCase` seleciona contas financeiras do titular em ordem de ID e chama o recálculo para cada uma das até 10 contas da página. `GetAccountBalanceUseCase` usa o número externo para resolver uma única conta. Contas inativas preservam a consulta histórica. `BalanceRepository` expõe DTOs próprios; `AccountBalanceRecord::toData()` converte o model e mantém dinheiro/IDs/versões em strings decimais.

`MysqlBalanceRepository` possui conexão PDO reservada `balance_projection`, configurada em `READ COMMITTED`/UTC e independente da transação do escritor. Cada recálculo verifica titularidade, bloqueia a projeção, relê flag/versões, agrega somente partidas confirmadas e confirma totais/versões antes de devolver o DTO. Uma transação externa na conexão reservada é rejeitada. A soma não toma locks no ledger nem no estado do titular.

Se a projeção já estiver consistente, o timestamp permanece igual. Overflow ou falha SQL gera `BalanceUnavailable`, com rollback, sem substituir o saldo por zero. O HTTP retorna 503 sem dados parciais. Cada conta representa um estado observado durante a requisição; a página não oferece um snapshot global. Os responses HTTP usam `no-store`.

O trigger `account_balances_track_staleness` mantém `staled_since` para métricas, preservando o início da pendência até o recálculo. Ele não participa da soma nem cria eventos. A decisão de atualidade usa `staled` e as versões. Algoritmo, endpoints, limites e comandos estão em [step-06.md](step-06.md).

## Autenticação

`LoginController` valida o transporte por `LoginFormRequest`, instancia `LoginRequest`, chama `LoginUseCase` e apresenta seu DTO. O caso de uso consulta `UserRepository`, verifica a senha por `PasswordHasher` e emite o token por `TokenService`. Hash, Laravel e `lcobucci/jwt` ficam nos adapters. Os demais controllers seguem a mesma fronteira.

O middleware `jwt.auth` aceita somente o header `Authorization: Bearer ...`, chama `AuthenticateTokenUseCase` e coloca `AuthenticationData` nos atributos internos da requisição. Esse caso de uso valida o token, consulta a revogação MySQL e carrega o usuário atual. Controllers usam `AuthenticatedContext::fromRequest()`; IDs, claims ou objetos enviados no corpo/query não substituem esse contexto. Não existe fallback de autenticação por sessão/cookie.

O logout grava o identificador aleatório do token e sua expiração em `revoked_tokens`. Não grava o JWT completo nem depende do Redis. Uma falha nessa persistência não retorna sucesso; uma falha ao consultar revogação não libera acesso. O comando de limpeza remove apenas registros de tokens já expirados. Requisições que já passaram pela autenticação antes do logout podem concluir; requisições seguintes são recusadas.

O contrato `Clock` expõe segundos como inteiro; o adapter da biblioteca converte para PSR Clock/DateTime internamente. O teste de arquitetura agora verifica todos os contratos de Application, incluindo os de autenticação. Os contracts de usuário e revogação são executados contra os doubles e os adapters reais.

O contexto autenticado já foi integrado ao caso de uso que prepara uma linha CSV, em teste MySQL, verificando a recusa de conta alheia. Os endpoints de saldos usam esse contexto, com filtro por titular e paginação de no máximo 10 itens. Os futuros endpoints de importação e extratos deverão preservar a mesma fronteira. Detalhes do protocolo estão em [step-04.md](step-04.md).

## Processos

App, worker e `balance-projector` compartilham a imagem PHP. O volume `uploads` fica fora da raiz pública do Nginx. O futuro `outbox-relay` reutilizará essa imagem quando seu caso de uso estiver implementado.

O `balance-projector` acessa diretamente o MySQL, sem depender do Redis. `ProjectBalancesUseCase` busca pendências em lotes de até 10 e reutiliza `RefreshAccountBalanceUseCase`. O cursor percorre contas mesmo quando algumas falham ou estão ocupadas; voltará a elas na passagem seguinte. `SKIP LOCKED` é exclusivo dessa execução em segundo plano.

Redis usa AOF, limite de memória e `noeviction`. Cache e filas usam bancos lógicos distintos, mas compartilham a política de memória. A durabilidade dos futuros eventos de negócio será garantida por outbox transacional e reconciliação, não apenas por `after_commit` ou AOF.

## Referências oficiais consultadas

- [Laravel como backend de API](https://laravel.com/docs/13.x/installation#laravel-the-api-backend)
- [Laravel: deployment, PHP-FPM e health route](https://laravel.com/docs/13.x/deployment)
- [Docker Compose: ordem de inicialização e healthchecks](https://docs.docker.com/compose/how-tos/startup-order/)
- [Vue: estratégias e ferramentas de testes](https://vuejs.org/guide/scaling-up/testing.html)
