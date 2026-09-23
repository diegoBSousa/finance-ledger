# Etapa 03 — persistência MySQL e contas de demonstração

Corresponde à etapa 4 do plano aprovado. Conecta o contrato de contas ao MySQL e cria as estruturas que serão usadas pela postagem, projeção de saldos e importação.

## Estruturas implementadas

| Tabela | Dados e proteções principais |
| --- | --- |
| `accounts` | ID interno, titular, número externo único, natureza, BRL e atividade; número obrigatório para ativo e ausente nas contrapartidas |
| `financial_states` | Uma revisão por titular, inicialmente zero |
| `account_balances` | Projeção física por conta, totais de débito/crédito, saldo assinado, `staled`, versões e data do cálculo |
| `journal_entries` | Titular, conta financeira, ator, data, descrição original/canônica, tipo, registro canônico e SHA-256 |
| `ledger_entries` | Cabeçalho, titular, conta, posição, lado, centavos positivos e BRL |
| `imports` | Ator, arquivo, tamanho/checksum, status, contadores, offset em bytes, número do registro, versão do checkpoint e lease/heartbeat |
| `import_rows` | Resultado por registro: inserido, duplicado ou rejeitado; hash opcional para erros, operação associada e diagnóstico |
| `outbox_events` | Tipo/versão, payload JSON e data do evento |
| `outbox_deliveries` | Consumidor, tentativas, disponibilidade, lease, publicação, confirmação e último erro |

As quatro migrations usam DDL explícito para MySQL 8.4/InnoDB, incluindo CHECKs e índices compostos. Os campos monetários são `BIGINT` assinado: não há `FLOAT`/`DOUBLE`, e os totais de débito/crédito não podem ser negativos. Saldos negativos são aceitos. O backend preserva valores de 64 bits e os DTOs de valores/identificadores usam strings decimais.

`accounts.technical_kind` é uma coluna gerada: `NULL` para ativo e a natureza para receita/despesa. `UNIQUE(owner_user_id, technical_kind)` permite várias contas financeiras e somente uma contrapartida de cada natureza por titular. Os números externos #100–#999 continuam independentes dos IDs internos.

As FKs de partidas incluem ID, titular e moeda, tanto para a conta quanto para o cabeçalho. Uma partida não consegue vincular uma conta de outro titular a uma operação. As chaves referenciadas são explicitamente únicas, sem depender do suporte legado do MySQL a índices não únicos. Não há exclusão em cascata de dados contábeis.

`UNIQUE(owner_user_id, source_row_hash)` reserva a identidade na operação financeira, independentemente de arquivo, upload ou posição. `UNIQUE(import_id, source_record_number)` protege o resultado de cada registro. `import_rows.source_row_hash` não é único: repetições e rejeições precisam ser registradas sem reservar a identidade financeira. O importador ainda será responsável por tratar a colisão de unicidade e confirmar o lote em uma única transação.

Há índices para consulta por titular/data/ID, conta/data/ID, partidas por conta/cabeçalho, contas `staled`, resultados por importação/status e entregas disponíveis/leases expiradas. Hashes usam ASCII com comparação sensível a caixa e validação hexadecimal minúscula.

O schema aceita arquivos de até **100.000.000 bytes** e verifica que o checkpoint não ultrapasse esse tamanho. Isso não substitui a validação HTTP do upload, ainda pendente. Descrições, registro canônico e diagnósticos usam `TEXT`; o importador deverá validar os limites em bytes dos campos antes da escrita e registrar erros de linha de forma controlada.

## Repositório e provisionamento

`MysqlAccountRepository` implementa a porta existente sem alterar suas assinaturas. Resolve a conta financeira pelo número externo e a técnica por titular/natureza, retorna `null` para ausência e mantém contas inativas visíveis para a validação no caso de uso. Consultas não criam contas.

O binding está em `AppServiceProvider`. `AccountRecord` fica em Infrastructure, implementa `toData()` e retorna `AccountData`; Eloquent não atravessa a interface. O teste de integração também resolve `PrepareCsvPostingUseCase` pelo container, consulta #682 no MySQL e verifica o mesmo DTO/hash da etapa anterior, sem gravar lançamentos.

`AccountProvisioner` cria conta e saldo inicial na mesma transação, criando a revisão do titular quando necessário. O lock do usuário serializa o provisionamento de suas contas. Ele é utilizado pelo seed; não adiciona um endpoint de criação de contas nem muda a política de importação para contas preexistentes.

## Seed repetível

O bootstrap executa `DatabaseSeeder`, que chama `DemoAccountsSeeder`:

1. Aceita somente `APP_ENV=local` ou `testing`, inclusive quando se passa `--force`.
2. Usa `DEMO_USER_EMAIL`, com padrão `demo@example.test`, e exige senha explícita de pelo menos 16 caracteres. O bootstrap gera `DEMO_USER_PASSWORD` aleatória quando ausente.
3. Cria o usuário se necessário, com Argon2id. Preserva a senha de um usuário já existente.
4. Cria as 900 contas financeiras #100–#999 para esse titular e as contrapartidas de receita e despesa, sem número externo.
5. Cria as 902 projeções zeradas e a revisão inicial do titular. Não importa o CSV.

Repetições preenchem contas ausentes e preservam IDs, atividade, senha, saldos, versões e revisões existentes. Um número já pertencente a outro titular aborta e desfaz todo o seed. Projeções/estados ausentes em contas preexistentes exigem reconciliação explícita; o seed não os substitui por zero.

Depois de atualizar os arquivos da etapa anterior, preservando suas configurações e volumes:

```bash
bash scripts/bootstrap.sh
bash scripts/check.sh
```

O bootstrap acrescenta variáveis de demonstração ausentes ao `.env` existente. Alterar `DEMO_USER_PASSWORD` depois da criação do usuário não redefine sua senha. Login será implementado na próxima etapa.

## Integração e isolamento dos testes

```bash
# Núcleo e HTTP, sem banco financeiro:
docker compose run --rm --no-deps app composer test

# MySQL real e descartável:
bash scripts/test-mysql.sh
```

O segundo comando inicia os serviços do perfil `test`. `mysql-test` usa `tmpfs`, credenciais exclusivas de teste e nenhuma porta publicada; não compartilha dados com o serviço `mysql`. `test-runner` usa o código PHP e essa conexão, sem montar o volume de uploads. O script para o banco ao terminar e os dados em memória são descartados.

`composer test:integration` executa `phpunit.integration.xml`. Seu bootstrap exige MySQL 8.4, ambiente `testing`, banco exatamente `finance_ledger_test`, configuração sem cache e `MYSQL_TEST_RESET=1`. Confere também o nome do banco conectado antes de `migrate:fresh`. Cada teste de dados usa rollback; o teste de migrations executa DDL fora da transação, pois MySQL faz commit implícito dessas operações.

As expectativas abstratas de `AccountRepositoryContract` são as mesmas usadas pelo double em memória. Há testes adicionais para FKs entre titulares, números externos, contrapartidas únicas, valores inválidos/overflow, precisão acima do limite exato de JavaScript, versões de saldo, seed parcial/repetido, rollback, hashes e checkpoints. Publicação e confirmação da outbox permanecem independentes: um consumidor pode confirmar antes de o relay registrar `published_at`.

## Validação executada

| Verificação | Resultado |
| --- | --- |
| Núcleo puro | 92 testes, 435 assertions, passaram |
| HTTP | 5 testes, 12 assertions, passaram |
| MySQL real | 58 testes, 131 assertions, passaram, sem testes arriscados ou avisos PHPUnit |
| Total backend | **155 testes, 578 assertions** |
| Pint / PHPStan-Larastan nível 6 | Passaram |
| `composer validate --strict` | Passou |
| Compose, incluindo perfil `test`, e sintaxe Bash | Passaram |
| Guardas contra reset sem opt-in ou com banco de desenvolvimento | Recusaram as duas configurações |

Execução em PHP 8.4.1 de 64 bits e MySQL Community 8.4.11 temporário, com os binários oficiais fora de Docker. A análise estática terminou sem erros; o PHP portátil avisou que não pode carregar a extensão opcional de aceleração do PHPStan. O frontend não mudou e não foi revalidado nesta etapa.

O ambiente de implementação não possui daemon Docker. Portanto, o bootstrap completo, os containers, o diagnóstico Redis/worker e a CI ainda precisam executar no Ubuntu/GitHub. A configuração do workflow já inclui a nova suíte e a limpeza do perfil de testes.

## Limites desta entrega e próxima etapa

As estruturas de saldo e outbox existem; seus serviços ainda não. O teste de rollback comprova a transação e as constraints do banco, não um caso de uso de postagem financeira já entregue.

Não há trigger de `staled`, recálculo de saldos, trava de imutabilidade do livro, relay, importador ou endpoints financeiros. A igualdade entre débitos e créditos é validada pelo domínio; um CHECK de linha não garante a soma de várias partidas. A etapa de postagem irá persistir o agregado inteiro, aplicar imutabilidade e invalidar as projeções, inclusive para inserções em lote. Não há importação automática dos dados anexados nesta entrega.

O próximo incremento é autenticação JWT, login, logout com revogação durável e usuário atual. Depois entram postagem/invalidação, recálculo e projeção independente, seguindo o plano aprovado.

## Referências técnicas

- [MySQL 8.4: chaves estrangeiras e índices únicos referenciados](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html)
- [MySQL 8.4: CHECK constraints e limitações a expressões de uma linha](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html)
