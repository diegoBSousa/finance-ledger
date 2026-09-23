# Arquitetura e fronteiras

A etapa 02 acrescenta as camadas de domínio e aplicação à composição técnica inicial. A preparação de um lançamento já pode ser executada sem Laravel; a persistência financeira entra no próximo incremento.

## Direção das dependências

| Camada | Conteúdo | Pode depender de |
| --- | --- | --- |
| `Domain` | Money, contas, lados, lançamentos e invariantes | PHP e tipos próprios |
| `Application` | DTOs, casos de uso e portas de persistência | Domain e tipos próprios |
| `Infrastructure` | Repositórios MySQL, records Eloquent, fila e armazenamento | Application, Domain e Laravel |
| `Http` / `Console` | Entrada HTTP/CLI, autenticação e apresentação | Application e Laravel |

Controllers de negócio fazem validação de transporte, constroem RequestDTOs e chamam casos de uso injetados. Os casos de uso retornam ResponseDTOs próprios. Nenhum contrato interno recebe `Request`, `UploadedFile`, `Model`, `Collection`, `Paginator`, `Carbon` ou tipos da biblioteca JWT.

`Account`, `Posting` e `JournalEntry` implementam `toData()` retornando DTOs imutáveis de `Domain/Accounting/Data`. Assim, as entidades não dependem da camada Application nem de um formato HTTP. O caso de uso envolve `JournalEntryData` em seu próprio ResponseDTO. Os futuros records Eloquent também terão mapeamento explícito para DTOs próprios; os repositórios não expõem entidades ou models.

Inserções em lote terão adapters próprios e não dependerão de eventos individuais dos models.

## Fluxo implementado

`PrepareCsvPostingRequest` contém o ator confiável e os quatro campos já interpretados de uma linha CSV. `PrepareCsvPostingUseCase` canonicaliza a linha, pede a conta financeira ao `AccountRepository`, confere a titularidade, resolve a conta técnica e constrói o agregado balanceado. A resposta contém dados próprios: hash, texto canônico, descrição original para auditoria e `JournalEntryData` com duas partidas.

O caso de uso não grava operações, publica eventos ou reserva hashes. Na etapa de persistência, a unicidade precisa ser garantida pela restrição MySQL e pela mesma transação que confirma a operação. O hash calculado aqui, sozinho, não oferece idempotência transacional.

`AccountRepository` é somente uma porta de leitura. Seu double está em `tests/Doubles`, e não é registrado no container Laravel. O teste abstrato `AccountRepositoryContract` define as expectativas que serão reutilizadas pelo adapter MySQL.

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
