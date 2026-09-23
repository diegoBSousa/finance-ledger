# Arquitetura e fronteiras

A primeira entrega contém apenas a composição técnica e a prova de comunicação HTTP. As camadas de domínio e aplicação serão criadas no próximo incremento, junto de seus primeiros comportamentos e testes.

## Direção das dependências

| Camada planejada | Conteúdo | Pode depender de |
| --- | --- | --- |
| `Domain` | Money, contas, lados, lançamentos e invariantes | PHP e tipos próprios |
| `Application` | DTOs, casos de uso e portas de persistência | Domain e tipos próprios |
| `Infrastructure` | Repositórios MySQL, records Eloquent, fila e armazenamento | Application, Domain e Laravel |
| `Http` / `Console` | Entrada HTTP/CLI, autenticação e apresentação | Application e Laravel |

Controllers de negócio fazem validação de transporte, constroem RequestDTOs e chamam casos de uso injetados. Os casos de uso retornam ResponseDTOs próprios. Nenhum contrato interno recebe `Request`, `UploadedFile`, `Model`, `Collection`, `Paginator`, `Carbon` ou tipos da biblioteca JWT.

Records Eloquent terão mapeamento explícito para DTOs de dados. DTOs de leitura HTTP não devem passar a ser responsabilidade das entidades de domínio. Inserções em lote terão adapters próprios e não dependerão de eventos individuais dos models.

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
