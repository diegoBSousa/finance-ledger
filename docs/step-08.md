# Etapa 08 — importação financeira em chunks e retomada

Esta entrega implementa a etapa 10 do plano aprovado. O arquivo aceito pela API passa a gerar operações financeiras em background, usando as mesmas regras contábeis e a mesma idempotência da postagem. Não há novos endpoints: o acompanhamento existente passa a informar o progresso e o resultado real da importação.

## Fluxo e contrato

1. O upload grava a origem privada e confirma importação/`ImportRequested` na outbox; responde 202.
2. `import-preparer` verifica tamanho, checksum e cabeçalho, persiste o manifesto de integridade e o primeiro checkpoint.
3. O relay publica `ImportChunkRequested` para `ProcessImportChunkJob` na fila `imports`. O job transporta somente o ID da entrega; importação e geração esperada são resolvidas no evento durável.
4. `ProcessImportChunkUseCase` reserva a geração, lê um chunk a partir do offset e resolve as contas em uma consulta em lote.
5. Cada linha válida é preparada como débito/crédito, com o hash canônico existente. Linhas inválidas recebem um resultado de rejeição.
6. Uma transação confirma operações novas, resultados, contadores, checkpoint, próxima intenção e confirmação da entrega atual.
7. O último chunk confirma o estado final e `ImportCompleted`. Erros definitivos de chunk confirmam `failed` e `ImportFailed`.

Controllers e API continuam usando DTOs próprios. As novas portas são `CsvChunkReader`, `ImportAccountRepository` e `ImportChunkRepository`; não expõem streams, models, conexões ou classes Laravel. `ResolvedImportAccounts` oferece ao caso de uso contábil uma visão em memória dos DTOs da consulta em lote. Seu contrato é testado pelas mesmas expectativas de `AccountRepository`.

O destino continua sendo a conta no sufixo `#N` da descrição, autorizada para o usuário do JWT. O inteiro `amount` já é o valor em centavos de BRL. Receita debita a conta financeira e credita receitas; despesa debita despesas e credita a conta financeira. Saldos negativos continuam permitidos.

## Limites de leitura

| Limite | Valor e comportamento |
| --- | --- |
| Arquivo | Até 100.000.000 bytes, como na etapa anterior |
| Chunk | Até 500 registros lógicos, incluindo rejeitados |
| Bytes por leitura | Pausa depois de atingir 1.048.576 bytes, ao terminar o registro atual |
| Tempo de leitura | Pausa depois de dois segundos, ao terminar o registro atual |
| Registro lógico | Até 65.536 bytes de conteúdo, sem o terminador externo LF/CRLF |
| Descrição recebida | Até 16.384 bytes UTF-8; excesso rejeita a linha |
| Bloco de integridade | 1.048.576 bytes, conferidos por SHA-256 |
| Job | Timeout de 60 segundos; fila com `retry_after=90` |
| Posse da importação | 180 segundos, revalidada no commit |
| Tentativas financeiras | Até cinco reservas por checkpoint, persistidas no MySQL |
| Paginação pública | Padrão/máximo de 10, independente do tamanho do chunk |

O limite de bytes/tempo é avaliado em fronteiras completas; pode exceder o limiar pelo tamanho/tempo de um registro limitado. Os dois segundos se referem à leitura, não a toda a transação. O timeout do job limita a execução completa. O algoritmo mantém somente um bloco de integridade e os registros do chunk em memória.

O CSV usa vírgula, campos opcionais entre aspas duplas e aspas duplicadas dentro de campos delimitados. LF, CRLF, campos multilinha e último registro sem quebra final são aceitos. BOM UTF-8 é aceito antes do cabeçalho. Barra invertida é literal; não é escape alternativo. Texto após uma aspa de fechamento, aspas no meio de campo não delimitado, aspa sem fechamento e CR externo sem LF são erros estruturais. Espaços fora das aspas não são ignorados para aceitar uma estrutura malformada.

Quantidade errada de colunas, linha vazia, data/valor inválidos, UTF-8 inválido, descrição excessiva e conta inválida geram rejeição do registro quando a fronteira CSV permanece identificável. As regras contábeis ainda limitam seus campos persistidos a 65.535 bytes; uma descrição cujo formato canônico ultrapasse isso também é rejeitada. Não há coerção para float.

## Integridade sem varredura integral por chunk

A preparação já percorre o arquivo para conferir seu checksum. Agora também calcula o SHA-256 de cada bloco de 1 MiB e grava a lista em `imports.file_block_hashes`. O leitor abre a origem privada e verifica seu tamanho; carrega por inteiro o bloco que contém o checkpoint e confere o hash antes de consumir seus bytes. Repete isso somente para os blocos tocados pelo chunk. Um registro que atravessa blocos só é entregue depois de conferir os trechos correspondentes.

Alterações com o mesmo tamanho também são detectadas nos blocos lidos. O parser trabalha sobre os bytes já verificados em memória. Não transporta o arquivo para Redis e não percorre os blocos anteriores ao offset a cada job. Algoritmo SHA-256 e tamanho de bloco são parte do contrato persistido dessa versão; uma mudança futura exige migração/versionamento do manifesto.

Importações preparadas na etapa 07 não têm manifesto. O primeiro chunk verifica novamente o checksum integral e grava o manifesto junto de seu checkpoint. Uma tentativa que falhar antes desse commit pode repetir essa verificação. A atualização preserva os arquivos privados e os jobs pendentes existentes.

## Atomicidade, concorrência e idempotência

O claim bloqueia entrega e importação em transação curta e verifica o `checkpoint_version` do evento. Geração antiga ou importação já encerrada confirma a entrega repetida sem ler o arquivo. Reserva ativa faz o job duplicado sair sem alterar contadores; a entrega original continua recuperável. Eventos de formato/versão/geração futura incompatíveis são marcados como falha da entrega.

O processamento do arquivo e a preparação contábil acontecem fora da transação financeira. No commit, o adapter bloqueia novamente entrega/importação e exige o mesmo token de posse ainda válido, geração, offset, número do registro e titular. Depois chama o repositório contábil na mesma conexão/transação. O journal mantém a ordem de locks por titular, projeções e contas e revalida os dados das contas antes da escrita.

O commit abrange:

- Cabeçalhos e duas partidas por operação nova, com os efeitos transacionais dos triggers.
- Uma linha de `import_rows` por registro, identificada por importação/número lógico e classificada como `inserted`, `duplicate` ou `rejected`.
- Contadores, `byte_offset`, `last_record_number`, `checkpoint_version`, estado e liberação da posse.
- `LedgerChanged` para lotes com operações novas e a intenção do próximo chunk ou o evento de término.
- Confirmação `acknowledged` da entrega atual.

Qualquer falha nessas escritas desfaz o lote inteiro. Os lotes anteriores continuam válidos. O cabeçalho é o registro lógico 1; o primeiro registro financeiro é 2. Campos multilinha contam como um registro, não como várias linhas físicas.

A identidade financeira é `UNIQUE(owner_user_id, source_row_hash)`. O hash do arquivo não deduplica uploads. Um arquivo reenviado cria outro acompanhamento, mas as linhas já lançadas são registradas como duplicadas e apontam para o journal original. Duplicatas dentro do mesmo arquivo, reordenação e sobreposição seguem a mesma regra. Uma linha antes rejeitada pode ser reenviada depois de corrigir seus dados ou regularizar a conta.

Se uma conta mudar entre a preparação e a escrita, a revalidação recusa o lote. A nova tentativa refaz a resolução e pode registrar a linha como rejeitada, mantendo as demais válidas. Não grava usando a autorização antiga.

## Retentativas e término

`chunk_attempts` avança ao obter uma nova posse da geração, não a cada publicação no Redis. Reservas concorrentes recusadas não gastam tentativas. O contador volta a zero quando o checkpoint avança. Assim, republicação ou reinício do worker não reinicia indefinidamente o orçamento de um chunk que sempre falha.

Uma falha técnica preserva checkpoint/resultados anteriores, libera somente sua própria posse quando possível e permite nova tentativa. Cada job Redis admite até três tentativas; se necessário, o relay republica a entrega sem confirmação após seu prazo de recuperação. Cinco reservas sem sucesso encerram a importação com `import_chunk_attempts_exhausted`. Uma reserva abandonada após queda do processo vence; uma nova posse pode continuar, sujeita ao mesmo contador durável. Banco indisponível antes de conseguir registrar a reserva não consome uma tentativa financeira.

Um worker antigo não pode concluir nem liberar a reserva de outro. Se a resposta do commit se perder depois da confirmação real, a tentativa seguinte encontra a entrega/checkpoint já concluídos e não repete seus efeitos. Arquivo ausente/alterado, CSV malformado ou registro acima do limite encerram o chunk com código seguro; os registros ainda não confirmados daquele chunk não são contados como processados.

| Estado no acompanhamento | Significado |
| --- | --- |
| `pending` | Aguardando preparação ou primeira reserva financeira |
| `processing` | Processamento iniciado, incluindo espera pelo próximo chunk ou retentativa |
| `completed` | Fim do arquivo alcançado sem rejeições; duplicatas são aceitas |
| `completed_with_errors` | Fim do arquivo alcançado com registros rejeitados |
| `failed` | Erro definitivo; o progresso anteriormente confirmado permanece |

Os quatro contadores contam registros financeiros e são strings decimais. Sempre vale `processed_rows = inserted_rows + duplicate_rows + rejected_rows`. `prepared_at` marca a verificação inicial; `started_at`, a primeira reserva financeira; `finished_at`, o término. `error_code` pode informar a última falha temporária durante `processing` e é limpo após um chunk bem-sucedido.

`GET /api/v1/imports/{id}` e a listagem já exibem esses dados. Os resultados individuais ficam persistidos em `import_rows`; sua consulta HTTP paginada será acrescentada com as consultas de negócio. Eventos `ImportCompleted`/`ImportFailed` ficam registrados sem entregas para consumidores ainda inexistentes. O consumidor de cache de `LedgerChanged` permanece para o próximo incremento.

## Atualização no Ubuntu

A migration `000009_add_import_chunk_recovery` acrescenta `file_block_hashes` e `chunk_attempts`. Não reescreve operações financeiras. Depois de atualizar os arquivos, preservando `.env` e volumes:

```bash
bash scripts/bootstrap.sh
bash scripts/check.sh
docker compose logs --tail=30 worker outbox-relay
```

O bootstrap agora reinicia worker, relay e projector após aplicar as migrations e iniciar os serviços. Isso recarrega o código PHP mesmo quando o bind mount mudou sem alterar a imagem/container. A correção de `network not found` foi preservada: os serviços descartáveis `mysql-test` e `redis-test` são recriados a cada check.

Uploads válidos preparados na etapa 07 serão processados automaticamente pelos jobs pendentes. Para conferir um arquivo novo, obtenha um JWT pelo login e envie o CSV extraído:

```bash
curl -i -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  -F 'file=@backend/tests/Fixtures/financial_transactions.csv;type=text/csv' \
  http://localhost:8080/api/v1/imports

# Substitua 15 pelo ID retornado; repita até um estado terminal.
curl -sS -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  http://localhost:8080/api/v1/imports/15
curl -sS -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  http://localhost:8080/api/v1/accounts/682/balance
```

Se o JWT expirar durante o acompanhamento, faça login novamente. O processamento já aceito continua no worker. Para retomar uma interrupção técnica, inicie worker/relay; leases e outbox recuperam as pendências. Se a importação terminou como `failed`, corrija a causa e reenvie o arquivo completo ou parcial: os commits anteriores serão reconhecidos como duplicatas. Não existe endpoint de retry que apague resultados ou reabra um estado final.

## Aceite com o CSV fornecido

O arquivo original está em `backend/tests/Fixtures/financial_transactions.csv`, com 788.178 bytes e SHA-256 `f60074309616fa37ba606843875d6adf624281690c30375d8fb22253e1387893`. O teste de integração percorre o upload, a preparação, todos os chunks e um reenvio completo.

| Verificação | Resultado validado |
| --- | --- |
| Operações / partidas | 15.000 / 30.000 |
| Contas financeiras | 900 |
| Receitas | 3.611.960.974 centavos — R$ 36.119.609,74 |
| Despesas | 2.670.954.574 centavos — R$ 26.709.545,74 |
| Saldo financeiro total | 941.006.400 centavos — R$ 9.410.064,00 |
| Total global em cada lado | 6.282.915.548 centavos — R$ 62.829.155,48 |
| Contas com saldo negativo | 301 |
| Conta #682 | 10 operações; saldo de 109.209 centavos — R$ 1.092,09 |
| Reenvio completo | 0 inseridas, 15.000 duplicadas, 0 rejeitadas |

O reenvio preservou o total de partidas, a revisão financeira e a quantidade de eventos `LedgerChanged`. A consulta de #682 recalcula sua projeção diretamente do ledger. Na regressão final, a importação levou aproximadamente 32 segundos e o reenvio, 25 segundos. O pico acumulado do processo PHP naquele ponto da suíte foi de 68,5 MiB. São medições locais com MySQL 8.4.11 e execução direta dos casos de uso; não incluem a cadência do relay, transporte HTTP ou tempo de espera da fila. Não constituem meta de desempenho para o Ubuntu.

Os testes também cobrem registros multilinha, limites, mudança de arquivo com mesmo tamanho, duas importações concorrentes, dois workers da mesma geração, falhas em cada parte do commit, resposta de commit perdida, alteração concorrente de conta, regularização de conta rejeitada e recuperação de job Redis perdido. O parser é verificado sem banco, os casos de uso sem Laravel, e a persistência com MySQL/Redis reais.

O resultado consolidado das suítes está no [README](../README.md#testes-e-situação-de-validação). A execução integrada dos containers permanece pendente no Ubuntu/CI; o ambiente de implementação não tem daemon Docker. O frontend não mudou. Upload HTTP de 100 MB já possui teste com memória limitada, mas processamento financeiro completo de 100 MB e medições extensivas de escala continuam para a etapa de desempenho do plano.

## Próximo incremento

Implementar dashboard e extratos, cache por revisão financeira, consumidor de invalidação e consultas paginadas dos resultados das importações. Depois, conectar essas APIs às telas Vue e aos testes frontend/E2E.

## Referências

- [RFC 4180: CSV, aspas e campos multilinha](https://www.rfc-editor.org/rfc/rfc4180.html)
- [PHP: str_getcsv e escape explícito](https://www.php.net/manual/en/function.str-getcsv.php)
- [Laravel 13: filas e retentativas](https://github.com/laravel/docs/blob/13.x/queues.md)
