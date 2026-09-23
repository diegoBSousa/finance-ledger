# Arquitetura e fronteiras

A etapa 03 conecta o núcleo contábil ao repositório MySQL de contas e cria o modelo persistente. A preparação de um lançamento continua testável sem Laravel. A gravação de operações financeiras entra depois da autenticação.

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

O caso de uso não grava operações, publica eventos ou reserva hashes. Já existe `UNIQUE(owner_user_id, source_row_hash)` no MySQL. A etapa de postagem usará essa restrição na mesma transação que confirma cabeçalho, partidas e evento. O hash calculado sozinho não oferece idempotência transacional.

`AccountRepository` é somente uma porta de leitura. Seu double está em `tests/Doubles`; `MysqlAccountRepository` é a implementação registrada no container Laravel. Ambos herdam os mesmos testes de `AccountRepositoryContract`. Nenhuma interface foi ampliada para acomodar métodos de Eloquent.

`AccountProvisioner` é uma operação de infraestrutura utilizada pelos seeds. Em uma transação, verifica o usuário, serializa o provisionamento daquele titular e cria estado, conta e projeção. Não é um caso de uso HTTP nem permite criar implicitamente contas durante a importação. Um futuro fluxo de criação de conta terá seu próprio caso de uso/contrato quando existir essa necessidade.

As migrations são específicas de MySQL 8.4/InnoDB. FKs compostas preservam proprietário/moeda entre cabeçalho, partidas e contas. CHECKs validam valores e estados de uma linha; o balanceamento do agregado continua no domínio e será persistido atomicamente. Não há trigger de invalidação nesta etapa. O schema completo e os limites estão em [step-03.md](step-03.md).

`phpunit.core.xml` usa um bootstrap que bloqueia o autoload de Laravel, Carbon e adapters. `ArchitectureTest` inspeciona nomes resolvidos na árvore de sintaxe e permite apenas dependências internas na direção correta e recursos nativos do PHP. Também verifica que as assinaturas do repositório expõem escalares, enums e DTOs readonly. O parser utilizado nessa verificação é uma dependência exclusiva de desenvolvimento.

BRL é a única moeda representável pelo enum `Currency`; códigos não suportados são rejeitados na conversão dos dados. Adicionar moedas exige rever as invariantes de aritmética e de balanceamento por moeda antes de ampliar esse enum.

`HealthController` é uma verificação operacional sem entrada ou comportamento de negócio; não cria um caso de uso fictício. `CheckInfrastructure` e `ProbeSharedUploadJob` também pertencem à borda técnica. Não servem como modelo para transportar classes Laravel pelas futuras interfaces de domínio/aplicação.

## Processos

App e worker compartilham a mesma imagem PHP e o volume `uploads`, montado fora da raiz pública do Nginx. Os futuros processos `outbox-relay` e `balance-projector` reutilizarão essa imagem, com comandos Artisan independentes. Eles serão incluídos quando existirem os casos de uso correspondentes; não há processos vazios simulando esses serviços.

O `balance-projector` terá acesso direto ao MySQL e funcionará sem a fila Redis. O mesmo caso de uso de recálculo será utilizado pela consulta HTTP das contas `staled`.

Redis usa AOF, limite de memória e `noeviction`. Cache e filas usam bancos lógicos distintos, mas compartilham a política de memória. A durabilidade dos futuros eventos de negócio será garantida por outbox transacional e reconciliação, não apenas por `after_commit` ou AOF.

## Referências oficiais consultadas

- [Laravel como backend de API](https://laravel.com/docs/13.x/installation#laravel-the-api-backend)
- [Laravel: deployment, PHP-FPM e health route](https://laravel.com/docs/13.x/deployment)
- [Docker Compose: ordem de inicialização e healthchecks](https://docs.docker.com/compose/how-tos/startup-order/)
- [Vue: estratégias e ferramentas de testes](https://vuejs.org/guide/scaling-up/testing.html)
