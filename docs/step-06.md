# Etapa 06 — consulta e projeção consistente dos saldos

Esta entrega cobre as etapas 7 e 8 do plano aprovado: recálculo compartilhado, API autenticada e processo independente `balance-projector`. O livro contábil e a invalidação transacional continuam os da [etapa 05](step-05.md).

## API disponível

Todas as rotas abaixo exigem `Authorization: Bearer <JWT>` e usam `Cache-Control: no-store, private`, inclusive nas respostas de erro.

| Método e caminho | Comportamento |
| --- | --- |
| `GET /api/v1/balances` | Saldos das contas financeiras do usuário, ordenados pelo ID interno |
| `GET /api/v1/balances?page=2&per_page=10` | Página explícita; padrão/máximo de 10 itens |
| `GET /api/v1/balances?account_number=682` | Filtro pelo número externo da conta |
| `GET /api/v1/accounts/682/balance` | Um saldo, resolvido pelo número externo #682 |

Contas técnicas ficam fora da API pública de saldos. Contas financeiras inativas continuam visíveis ao titular, preservando a consulta do histórico. Nenhum parâmetro de proprietário substitui a identidade autenticada. Conta alheia e conta inexistente retornam o mesmo 404 no detalhe; o filtro da listagem retorna página vazia nesses casos.

`page`, `per_page` e `account_number` aceitam inteiros decimais positivos. A API rejeita arrays, frações, notação científica, valores fora da faixa e offsets que excedam o inteiro de 64 bits. `per_page > 10` retorna 422; não é ajustado silenciosamente. Página posterior à última retorna `data: []` e conserva os metadados da consulta.

Exemplo ilustrativo de resposta para uma despesa de 494618 centavos em #682 (o ID interno depende do cadastro):

```json
{
  "data": {
    "account_id": "42",
    "account_number": "682",
    "active": true,
    "currency": "BRL",
    "debit_total_minor": "0",
    "credit_total_minor": "494618",
    "balance_minor": "-494618",
    "ledger_version": "1",
    "calculated_version": "1",
    "calculated_at": "2026-09-23T12:00:00.000000Z"
  }
}
```

Dinheiro, identificadores e versões são strings decimais, sem conversão para float. Os valores monetários representam centavos. A listagem envolve os mesmos objetos em `data: []` e acrescenta `meta` com `current_page`, `per_page`, `total`, `last_page` e `has_next_page`. Datas são UTC. `calculated_at` admite null para projeções legadas sem timestamp; contas provisionadas pelo projeto recebem esse instante na criação.

O contrato completo está em [openapi.yaml](openapi.yaml). A resposta não pede ao frontend que interprete `staled`: a API garante o recálculo antes de apresentar o saldo.

## Contratos e fluxo

Controllers instanciam DTOs de request e chamam `ListAccountBalancesUseCase` ou `GetAccountBalanceUseCase`. A listagem seleciona no máximo 10 contas autorizadas por meio de `BalanceRepository::page()`. Para cada conta, chama `RefreshAccountBalanceUseCase`, que também é usado pelo serviço independente.

A porta `BalanceRepository` oferece seleção da página, recálculo e seleção interna de pendências. Suas assinaturas usam apenas escalares e DTOs readonly próprios. Models, paginator, Carbon, query builder, conexões e exceções SQL ficam na infraestrutura. `AccountBalanceRecord::toData()` converte a projeção para `AccountBalanceData`; o presenter HTTP monta o JSON explicitamente.

O caso de uso recusa resultados inconsistentes retornados pelo adapter: flag pendente, divergência de versões, identidade incorreta ou ausência de resultado numa consulta do usuário. Falhas produzem 503 `balance_unavailable`, sem resposta parcial, saldo antigo ou zero substituto. A API informa `Retry-After: 1`; isso sugere uma nova tentativa, sem prometer recuperação em um segundo.

## Recálculo e concorrência

`MysqlBalanceRepository` usa `balance_projection`, uma conexão PDO própria com as mesmas credenciais MySQL, sessão em `READ COMMITTED` e timezone UTC. Não reaproveita a transação do escritor nem seu snapshot. Se houver uma transação externa nessa conexão reservada, recusa a operação.

Cada conta é processada em uma transação curta:

1. Verificar conta e titular.
2. Bloquear `account_balances` com `FOR UPDATE` e reler flag/versões.
3. Reutilizar a projeção se ela já estiver consistente, sem alterar timestamp ou totais.
4. Caso contrário, somar débitos/créditos confirmados da conta e calcular o saldo conforme sua natureza.
5. Persistir os totais, o saldo, `calculated_version = ledger_version`, `staled = false` e o instante de cálculo; confirmar antes de retornar o DTO.

As somas de inteiros do MySQL são lidas como decimais exatos e validadas antes do cast. Se qualquer total ultrapassar o BIGINT assinado, a transação é revertida e a conta permanece pendente. O saldo de ativo/despesa usa débitos menos créditos; receita usa créditos menos débitos. Saldo negativo de ativo é válido. Efeito líquido zero também exige atualizar totais e versões.

O recálculo não bloqueia o estado do titular nem as partidas. A projeção bloqueada coordena os escritores existentes. Se o escritor confirmar primeiro, o recálculo inclui sua operação; se confirmar depois, o trigger volta a marcar a projeção como pendente. Um escritor que fizer rollback não aparece na soma. Dois recálculos simultâneos revalidam sob lock e o segundo reutiliza o resultado do primeiro.

A espera InnoDB é de até 5 segundos por tentativa, com até três tentativas para erros de concorrência reconhecidos pelo Laravel. Esse limite não é um prazo total da requisição nem da agregação. A API não usa `SKIP LOCKED` e não omite uma conta ocupada.

Cada saldo representa um estado consistente observado durante a requisição. A página reúne cálculos por conta e não promete um snapshot global simultâneo de todas elas. Um lançamento confirmado depois do cálculo pode tornar a projeção novamente pendente. O futuro dashboard terá seu próprio snapshot para revisão/totais.

O GET atualiza somente dados derivados. Não cria partidas, eventos, entregas ou revisões financeiras. O recálculo é uma operação sobre dados já confirmados: não deve ser chamado de dentro de uma transação de escrita para tentar enxergar suas alterações ainda não confirmadas.

## Processo independente

O Compose inclui `balance-projector`, reutilizando a imagem PHP com dependência apenas do MySQL. Executa `php artisan balances:project --sleep=1 --max-time=3600`, sem precisar do Redis, de eventos ou do worker de importação.

O processo seleciona no máximo 10 pendências por lote, incluindo contas técnicas. Considera `staled = true` e, defensivamente, divergência de versões. O cursor percorre IDs crescentes, inclusive quando encontra uma conta bloqueada ou com falha; ao terminar a passagem, volta ao início. Reiniciar o processo recomeça a busca no MySQL, preservando todo trabalho já confirmado.

Somente esse fluxo usa `FOR UPDATE SKIP LOCKED` no recálculo. Contas ocupadas são puladas e revisitadas em uma passagem posterior. Falhas individuais não impedem o processamento das seguintes. Contas que ficaram consistentes entre a busca e o lock são reutilizadas. Uma falha na busca é registrada e retentada depois da pausa.

O comando aceita `--once` para um único lote de até 10, `--sleep=1..60` e `--max-time=1..86400`. `--once` retorna código 1 se houver falha, 0 se concluir sem falhas; contas temporariamente ocupadas contam como puladas. O modo contínuo trata SIGTERM/SIGINT e encerra após o lote; o Compose o reinicia após a renovação periódica. Interrupção forçada reverte a transação ativa; a marca persistida permite retomar.

Cada lote escreve uma linha JSON com contas examinadas, recalculadas, puladas e com falha; quantidade pendente antes do lote; timestamp/idade da pendência mais antiga; e duração em milissegundos. As métricas são observações operacionais durante o processamento, não um snapshot financeiro global. Não há dependência de Redis para registrá-las.

## Migration e atualização

A migration `000007_track_balance_staleness_age` adiciona `account_balances.staled_since` e um trigger `BEFORE UPDATE` nessa tabela. Ele conserva o primeiro instante enquanto a conta permanece pendente e limpa o campo quando fica consistente. O trigger de INSERT das partidas permanece responsável por revisão, versão e `staled`.

Para pendências anteriores à migration, `staled_since` começa no instante da atualização; sua idade histórica anterior é desconhecida. Esse campo é apenas observabilidade, não participa do cálculo ou da decisão de atualidade. A reversão remove somente esse trigger e a coluna. O banco passa a ter seis triggers.

Atualize os arquivos do projeto preservando `.env` e volumes e execute:

```bash
bash scripts/bootstrap.sh
bash scripts/check.sh
docker compose ps balance-projector
docker compose logs --tail=20 balance-projector
```

O bootstrap aplica a migration antes de iniciar o serviço novo. Continua necessário `log_bin_trust_function_creators=1` no MySQL com binary log; a configuração já existe no Compose. A migration verifica essa condição antes do DDL.

Comandos úteis:

```bash
# Pausar o atualizador para verificar o recálculo pela API.
docker compose stop balance-projector

# JWT obtido previamente pelo login.
curl -sS -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  'http://localhost:8080/api/v1/accounts/682/balance'
curl -sS -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  'http://localhost:8080/api/v1/balances?page=1&per_page=10'

# Processar um lote manualmente; depois retomar o processo contínuo.
docker compose exec -T app php artisan balances:project --once
docker compose start balance-projector
```

O seed ainda cria contas com saldo zero. O importador de arquivos entra na etapa seguinte; estes comandos não carregam o CSV automaticamente. Os testes geram lançamentos próprios para comprovar o recálculo. Depois de alterar código do processo, execute `docker compose restart balance-projector`.

## Verificação

As suítes cobrem contratos em memória/MySQL, titularidade, contas inativas, número externo versus ID, precisão de 64 bits, overflow de agregação, efeito líquido zero, paginação inválida, falha sem fallback, rollback após atualizar a projeção, migrações, timestamps, ausência de projeção e funcionamento sem Redis.

Os cenários de concorrência usam processos PHP e sessões MySQL independentes, com commits reais: escritor antes/depois do recálculo, rollback do escritor, dois recálculos simultâneos, snapshot externo antigo, conta ocupada pulada pelo serviço e três tentativas esgotadas na consulta do usuário. O fluxo HTTP completo autentica um JWT, consulta #682 e persiste o recálculo real sem projector em execução.

Resultados e limitações do ambiente estão no [README](../README.md#testes-e-situação-de-validação). O frontend não mudou nesta etapa; a tela de saldos e seus testes serão implementados junto à interface de negócio.

## Próximo incremento

Implementar relay da outbox, upload de CSV até 100.000.000 bytes e status de importação. O processamento em chunks com checkpoints e retomada vem na sequência do plano. O evento financeiro existente permanece pendente até que seu consumidor seja implementado.

## Referências

- [MySQL 8.4: níveis de isolamento](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-isolation-levels.html)
- [MySQL 8.4: locking reads e SKIP LOCKED](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html)
- [Laravel 13: transações](https://github.com/laravel/docs/blob/13.x/database.md)
