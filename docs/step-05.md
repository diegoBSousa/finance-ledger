# Etapa 05 — postagem atômica e invalidação dos saldos

Corresponde à etapa 6 do plano aprovado. Entrega a gravação do livro contábil por lote, idempotência concorrente, invalidação transacional e intenção durável de evento. O recálculo dos saldos e os endpoints financeiros entram no próximo incremento.

## Contrato de aplicação

`PostCsvBatchUseCase` recebe `PostCsvBatchRequest`: o ID do ator confiável e uma lista de `CsvRowData` já interpretados. Reutiliza `PrepareCsvPostingUseCase` e chama `JournalRepository::post(PostingBatchData)`. O retorno é `PostCsvBatchResponse`, com os resultados na ordem original.

Cada `PostedJournalData` contém `sourceRowHash`, `journalEntryId` e `status` (`inserted` ou `duplicate`). Os identificadores são strings. A porta transita somente DTOs próprios; transações, query builder, Eloquent e exceções SQL ficam na infraestrutura.

O lote interno aceita de 1 a 500 operações de um único titular. Esse limite é independente das páginas públicas, que continuam limitadas a 10. O ator deve vir da autenticação/importação persistida; não de um campo livre enviado pelo cliente.

Exemplo de uso interno, com o ator previamente autenticado:

```php
$response = $useCase->execute(new PostCsvBatchRequest($actorUserId, [
    new CsvRowData('2026-08-16', 'Serviços de Limpeza #682', '494618', 'Despesa'),
]));
```

O primeiro envio confirma débito na conta técnica de despesa e crédito de 494618 centavos na conta financeira #682. O reenvio retorna o mesmo ID como duplicata. O exemplo é uma chamada de aplicação, não um endpoint HTTP novo.

Uma linha inválida rejeita este lote inteiro. O futuro importador separará rejeições de validação por registro e comporá o lote válido junto de resultados/checkpoint. Parser streaming, upload de 100 MB e retomada de importação ainda não estão implementados.

## Transação e locks

`MysqlJournalRepository` executa a sequência abaixo dentro da mesma transação:

1. Bloqueia a linha do titular em `financial_states`.
2. Bloqueia as projeções das contas por ID crescente e depois as próprias contas na mesma ordem.
3. Revalida titularidade, atividade, conta/natureza, BRL, partidas balanceadas e identidade canônica com os dados atuais.
4. Consulta cada hash por titular usando leitura bloqueante atual. Retorna o ID existente ou insere cabeçalho e duas partidas.
5. Os triggers invalidam as contas na mesma transação.
6. Se houver operações novas, grava um `LedgerChanged` e uma entrega pendente na outbox.
7. Confirma a transação e devolve os resultados.

Projeções e estados precisam existir; sua ausência interrompe a escrita. Não são criados saldos zero para encobrir metadados ausentes. Textos persistidos são limitados a 65535 bytes antes da escrita, compatíveis com as colunas TEXT.

O lock do titular serializa escritores legítimos daquela pessoa; proprietários diferentes têm estados próprios. O índice único de proprietário/hash permanece como proteção adicional. As consultas de duplicatas usam `FOR UPDATE`, evitando depender de um snapshot anterior em uma transação `REPEATABLE READ`.

As conexões MySQL usam `innodb_lock_wait_timeout=5`. O adapter permite até três tentativas de transação em falhas de concorrência reconhecidas pelo Laravel. Em transação externa, usa savepoint; o chamador precisa fazer rollback e retentar a transação externa completa após uma falha de concorrência. Não interpretar o retorno interno como commit definitivo.

Falhas SQL são convertidas em `PostingUnavailable`. Somente a violação do índice específico de proprietário/hash pode seguir o caminho de duplicata; FK, overflow, falha de trigger ou de outbox não são ignorados. Não há `INSERT IGNORE`.

## Idempotência

- A identidade permanece o SHA-256 canônico por titular, independente de arquivo, posição ou lote.
- Duplicatas dentro do mesmo lote mantêm a ordem das entradas e apontam para a primeira operação.
- Reenvios parciais, reordenados e sobrepostos inserem somente conteúdo novo.
- Duplicatas não criam partidas, eventos nem avançam versões/revisões.
- A representação canônica armazenada é comparada antes de aceitar uma duplicata. Um cabeçalho sem suas duas partidas, ou divergente do conteúdo esperado, gera erro de integridade.
- A descrição original do primeiro envio é preservada. Uma variante normalizada equivalente não reescreve o histórico.

Contas são revalidadas inclusive em reenvios: uma conta atualmente inativa é recusada antes da deduplicação. Não há autorização administrativa para importar em nome de outro titular nesta versão.

## Triggers e projeções

A migration nova instala cinco triggers e restringe posições das partidas a 1 e 2.

| Operação | Efeito |
| --- | --- |
| INSERT em cada partida | Incrementa a revisão do titular e `ledger_version` da conta; define `staled=1` |
| UPDATE/DELETE em cabeçalho ou partida | Rejeitado com erro SQL |
| Tentativa de terceira partida em operação completa | Rejeitada pela restrição de posição |
| Rollback da transação | Desfaz partidas, invalidações e evento |

A revisão do titular avança duas vezes por operação nova, uma vez por partida. Ambas as contas ficam desatualizadas, inclusive a técnica. Efeito financeiro líquido zero também invalida. O trigger não altera totais, saldo, versão calculada ou data de cálculo.

Os triggers funcionam em SQL direto e bulk insert, sem observers Eloquent. Se uma projeção estiver ausente, o INSERT falha; em um INSERT de várias linhas, os efeitos das linhas anteriores também são desfeitos. Overflow de versão/revisão interrompe a operação.

O balanceamento entre linhas continua garantido pelo agregado, pela revalidação do adapter e pela transação. O schema não torna qualquer sequência arbitrária de SQL uma operação balanceada. Todos os escritores de negócio devem usar a porta de postagem; os testes de SQL direto verificam as proteções específicas do banco.

A migration também marca contas com partidas preexistentes como `staled`, preserva versões já maiores e avança a revisão do titular. Não apaga lançamentos nem recalcula dinheiro. A reversão remove os triggers e a restrição adicional, sem diminuir versões.

## Evento durável

Cada lote com inserções cria um evento `LedgerChanged`, versão 1. O payload contém `owner_user_id`, `currency`, `journal_entry_ids`, `account_ids` ordenados e `financial_revision`. IDs e revisão são strings; apenas as operações novas e suas contas entram no evento.

A entrega para `dashboard-cache-invalidator` começa como `pending`. O evento e a entrega fazem parte da transação financeira; falhar ao gravar qualquer um desfaz o lote inteiro. A postagem não acessa Redis.

O relay e esse consumidor serão implementados nas etapas seguintes. A intenção já é durável, mas não há publicação/consumo desses eventos nesta entrega.

## Atualização no Ubuntu

Preserve o `.env` e os volumes existentes, atualize o projeto e execute:

```bash
bash scripts/bootstrap.sh
bash scripts/check.sh
```

O Compose passa `--log-bin-trust-function-creators=1` aos serviços `mysql` e `mysql-test`. Essa configuração permite criar os triggers usando o usuário com privilégios limitados ao banco, com o binary log habilitado. Foi reproduzido o erro 1419 sem a configuração e validada a migration com ela.

O bootstrap recria o serviço se a configuração mudou e aplica a migration, preservando o volume de desenvolvimento. A migration verifica essa condição antes de iniciar seu DDL e apresenta uma instrução explícita se o servidor não estiver configurado. O projeto continua usando as credenciais normais da aplicação, sem repassar a senha root ao PHP.

Para executar somente as verificações relevantes depois do bootstrap:

```bash
docker compose exec -T app composer test
bash scripts/test-mysql.sh
```

Os testes de concorrência criam processos PHP independentes e usam o banco descartável guardado `finance_ledger_test`. Após cenários com commit, recriam esse banco para limpar o livro imutável. Não executar a suíte fora do serviço de testes configurado.

## Verificação executada

| Suíte/verificação | Resultado |
| --- | --- |
| Núcleo puro, arquitetura e contratos em memória | 129 testes, 802 assertions |
| HTTP/JWT e isolamento de ambiente | 71 testes, 184 assertions |
| MySQL real, contratos, triggers e concorrência | 107 testes, 346 assertions |
| Total backend | **307 testes, 1332 assertions, passaram** |
| Pint, PHPStan/Larastan nível 6 e Composer validate | Passaram |
| Configuração Compose e sintaxe Bash | Passaram |

Os testes MySQL cobrem duas conexões gravando conteúdo idêntico, lotes sobrepostos, snapshots anteriores, espera seguida de rollback do primeiro escritor, falhas após cabeçalho/partidas/evento/entrega, rollback externo, contas desativadas, estado/projeção ausentes, overflow, valores de 64 bits, imutabilidade, lote de 500 operações, instalação dos triggers sobre dados existentes e reversão/reaplicação de migrations.

Validação em PHP 8.4.1 e MySQL 8.4.11. A integração final usou `ledger_test` com permissões somente no banco de testes; a preparação administrativa ficou fora da aplicação. Núcleo/HTTP também passaram com as variáveis de desenvolvimento herdadas do processo, mantendo a correção da etapa anterior.

Não há daemon Docker no ambiente de implementação; a execução completa dos containers continua pendente no Ubuntu/CI. O frontend não mudou e não foi revalidado. O aviso da extensão opcional de aceleração do PHPStan continua sem impedir a análise.

## Próxima etapa

Implementar o recálculo compartilhado e a listagem autenticada de saldos: no máximo 10 contas por página, recálculo das projeções `staled` antes da resposta e proteção contra uma invalidação mais recente. O processo independente reutilizará esse caso de uso.

## Referências

- [MySQL 8.4: triggers e rollback de statements](https://dev.mysql.com/doc/refman/8.4/en/trigger-syntax.html)
- [MySQL 8.4: binary logging e criação de triggers](https://dev.mysql.com/doc/refman/8.4/en/stored-programs-logging.html)
- [Laravel 13: transações e retentativas](https://github.com/laravel/docs/blob/13.x/database.md)

