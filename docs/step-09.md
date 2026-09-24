# Etapa 09 — dashboard, cache e consultas financeiras

Esta entrega implementa a etapa 11 do plano aprovado. Acrescenta seis endpoints autenticados e ativa o consumidor de invalidação do cache. Os valores continuam em centavos de BRL, serializados como strings decimais. O frontend será conectado na próxima etapa.

## Endpoints

| Rota GET em `/api/v1` | Resposta e filtros |
| --- | --- |
| `/dashboard` | Receitas, despesas, saldo, quantidade de operações e revisão do titular; agregado global, sem filtros ou paginação |
| `/accounts` | Contas financeiras do titular, inclusive inativas; filtro opcional `account_number` |
| `/transactions` | Operações do titular; `account_number`, `date_from`, `date_to` e `type` opcionais |
| `/accounts/{accountNumber}/transactions` | Extrato da conta; período e tipo opcionais |
| `/imports/{importId}/rows` | Resultados por registro lógico; filtro `status=inserted|duplicate|rejected` |
| `/imports/{importId}/errors` | Somente resultados rejeitados da importação |

Todas as coleções usam `page` com padrão 1 e `per_page` com padrão/máximo 10. Valores fracionários, notação científica, arrays e offsets fora da faixa suportada geram 422. Uma página além da última retorna `data: []` e os metadados de paginação. O dashboard agrega todo o histórico do titular, independentemente das páginas de extrato exibidas.

As datas são inclusivas, no formato `YYYY-MM-DD`, entre os anos 1000 e 9999, e devem existir no calendário. `date_from` não pode superar `date_to`. `type` aceita `income` ou `expense`. Operações são ordenadas por data financeira decrescente e ID decrescente; resultados de importação, por número do registro lógico crescente. O identificador de conta na rota prevalece sobre eventual `account_number` na query; `/errors` sempre restringe o resultado a `rejected`.

Contas são ordenadas por ID interno crescente e não incluem contrapartidas técnicas. Essa rota apresenta o cadastro, sem saldos. `/balances` continua recalculando as projeções pendentes antes de devolver seus valores.

O titular vem exclusivamente do JWT. Conta alheia e conta inexistente recebem o mesmo 404 nos extratos, inclusive quando informadas como filtro. Na listagem de cadastros, esse filtro simplesmente não encontra resultados. Importação alheia e inexistente recebem o mesmo 404, inclusive quando ainda não há registros processados. IDs de titulares, caminhos privados, payloads de eventos e detalhes de exceções internos não são expostos pelas novas consultas; os IDs públicos de operações/resultados e seus hashes de origem são parte do contrato HTTP.

## Dashboard e origem dos valores

O dashboard combina cada operação do journal com sua partida na conta financeira, uma única vez. Receita/despesa seguem o tipo de negócio da operação. As contrapartidas técnicas não são somadas novamente. O saldo é a diferença entre receitas e despesas, válida para o contrato atual de contas de ativo com saldo inicial zero.

A conexão reservada `ledger_read` usa `REPEATABLE READ` e UTC. Revisão e agregados são lidos na mesma transação/snapshot, sem locks de escrita. Uma transação já aberta nessa conexão é recusada. A consulta não participa da transação do escritor nem do recálculo em `READ COMMITTED` do `balance-projector`.

Assim, projeções `staled` não atrasam os totais globais nem obrigam a recalcular centenas de contas durante o dashboard. A consulta também não limpa essas flags. A listagem e a consulta individual de saldos preservam o protocolo de recálculo da etapa 06.

`SUM(BIGINT)` é tratado como decimal exato e validado antes da conversão para inteiro. Overflow ou indisponibilidade do MySQL retorna 503 com `financial_read_unavailable`, sem dados parciais ou substituição por zero. Um titular sem movimentos recebe totais zero e uma contagem zero.

## Cache por revisão

`GetDashboardUseCase` primeiro consulta a revisão atual no MySQL. Somente depois tenta obter um cache com o mesmo titular e revisão. Num miss, lê revisão e totais em um novo snapshot e grava sob a revisão desse snapshot, que pode já ter avançado. Um hit representa o estado observado na leitura inicial da revisão; uma nova transação pode confirmar depois desse ponto.

As chaves são versionadas e segregadas por titular:

- `dashboard:v1:{owner}:revision:{revision}` guarda somente JSON com DTO próprio.
- `dashboard:v1:{owner}:head` identifica a revisão atualmente armazenada para aquele titular.

O prefixo configurado do Redis também se aplica a essas chaves. A conexão `dashboard` usa o banco de cache, com timeouts de conexão/leitura de dois segundos e sem retentativas automáticas do cliente. O TTL de 300 segundos limita a retenção; a revisão SQL determina a atualidade.

Scripts Lua com comparação e troca mantêm o ponteiro e o valor juntos, usando somente as chaves explícitas recebidas como argumentos. Uma gravação antiga não substitui uma revisão mais nova. Atualizar o cache remove o valor anterior conhecido, sem varrer chaves. As revisões são comparadas como strings decimais, preservando a precisão de `BIGINT UNSIGNED`, inclusive acima do limite inteiro do JavaScript. Disputas são limitadas a três tentativas; depois, a resposta continua usando a origem SQL.

A invalidação remove somente uma revisão anterior à do evento. Um evento atrasado, repetido ou relativo à revisão já armazenada preserva o valor atual. Se um leitor antigo preencher o cache após a invalidação, ele ainda escreverá sob a revisão antiga; consultas futuras lerão a revisão SQL nova e não utilizarão esse valor.

Falha de leitura/escrita do Redis não descarta um snapshot SQL válido. JSON malformado, dinheiro inconsistente ou identidade/revisão incompatível no cache é tratado como miss. Falha do MySQL nunca autoriza devolver um cache antigo. Nenhuma resposta financeira tem cache HTTP: todas usam `Cache-Control: no-store, private`.

## Eventos e confirmação do consumidor

O relay agora atende `dashboard-cache-invalidator`, além de `import-preparer` e `csv-importer`. Publica `InvalidateDashboardJob` na fila `default`; jobs de importação continuam em `imports`. O worker existente já atende ambas.

O job carrega apenas o ID da entrega. `MysqlDashboardInvalidationRepository` resolve e valida o evento durável `LedgerChanged` v1, seu titular, moeda e revisão. O caso de uso chama `DashboardCache::invalidate()` e só então confirma a entrega no MySQL. Não mantém uma transação SQL durante a chamada ao Redis.

Uma falha do Redis deixa a entrega recuperável. Se a invalidação ocorrer e a confirmação SQL falhar, o retry repete a invalidação com segurança. Jobs perdidos são republicados pelo relay enquanto não houver confirmação. A confirmação antecipada do consumidor continua protegida contra uma resposta atrasada do publicador. O próprio estado `acknowledged` deduplica a entrega; não é necessária uma nova tabela de eventos processados para esse efeito idempotente.

Eventos incompatíveis são marcados como falha da entrega com código seguro. Não há `FLUSHDB`, `FLUSHALL` ou limpeza global na aplicação. O cache de outro titular é preservado. Os extratos e resultados não recebem cache Redis nesta etapa, portanto não exigem invalidação adicional.

## Contratos e consistência das páginas

Controllers validam o transporte, montam RequestDTOs, chamam um caso de uso e apresentam o ResponseDTO. As portas novas são `DashboardRepository`, `DashboardCache`, `DashboardInvalidationRepository`, `LedgerReadRepository` e `ImportRowsRepository`. Elas expõem apenas escalares e DTOs próprios readonly. `JournalEntryRecord::toData()` e `ImportRowRecord::toData()` mantêm models fora das fronteiras internas.

Contagem e registros de uma página de extrato/resultados são lidos no mesmo snapshot SQL. Isso evita uma contagem anterior ao commit combinada com registros posteriores durante a mesma requisição. Requisições de páginas diferentes têm snapshots independentes: uma importação concorrente pode deslocar posições na paginação por offset. A revisão do dashboard não é um cursor que congele todas as páginas.

Resultados contêm número lógico, classificação, hash e ID da operação quando disponíveis, além do código de rejeição. O texto do erro é genérico; não reproduz SQL, exceções ou conteúdo privado do arquivo. Rejeições estruturais que encerram um chunk aparecem no acompanhamento da importação; não inventam resultados para linhas não confirmadas.

## Atualização e verificação no Ubuntu

Esta etapa não acrescenta migrations. O bootstrap aplica eventuais migrations anteriores e reinicia os processos persistentes para carregar bindings, leitores e o consumidor novo. Preserve `.env` e volumes:

```bash
bash scripts/bootstrap.sh
bash scripts/check.sh
```

As entregas de `LedgerChanged` acumuladas nas etapas anteriores passarão a ser consumidas. O cache inicia vazio e é preenchido nas consultas. As correções de isolamento dos testes e de recriação da rede dos serviços descartáveis foram preservadas.

Depois do login, com `ACCESS_TOKEN` definido:

```bash
curl -sS -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  http://localhost:8080/api/v1/dashboard

curl -sS -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  'http://localhost:8080/api/v1/accounts/682/transactions?per_page=10&type=expense'

curl -sS -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  'http://localhost:8080/api/v1/transactions?date_from=2026-08-01&date_to=2026-08-31&page=1'

# Substitua 15 pelo ID da importação.
curl -sS -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  'http://localhost:8080/api/v1/imports/15/rows?status=duplicate'
```

## Evidências e limites

O teste do CSV fornecido agora valida também o dashboard e o extrato de #682: receitas de R$ 36.119.609,74, despesas de R$ 26.709.545,74, saldo de R$ 9.410.064,00 e 15.000 operações. O extrato de #682 contém dez operações. O reenvio completo preserva esses totais e a revisão.

Há testes com MySQL e Redis reais para snapshot durante commit concorrente, precisão e overflow, indisponibilidade de cache/banco, isolamento por titular, páginas e filtros, perda de mensagem Redis e falha de confirmação SQL. O mesmo contrato do cache é executado contra o double em memória e o adapter Redis. A suíte isolada continua bloqueando Laravel nas camadas de domínio/aplicação.

Os resultados consolidados estão no [README](../README.md#testes-e-situação-de-validação). A execução dos containers ainda precisa ser confirmada no Ubuntu/CI: o ambiente de implementação não tem daemon Docker. As telas Vue não foram alteradas. Benchmark financeiro de 100 MB, concorrência sustentada e ajustes com EXPLAIN permanecem na etapa de desempenho.

## Próxima etapa

Implementar as telas Vue de login, dashboard, contas/saldos, extrato, upload e acompanhamento, com JWT em memória, formatação exata de BRL, polling cancelável e testes de componentes/fluxos.

## Referências

- [MySQL 8.4: consistent nonlocking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html)
- [Redis: execução atômica e chaves explícitas em scripts Lua](https://redis.io/docs/latest/develop/programmability/eval-intro/)
