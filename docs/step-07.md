# Etapa 07 — upload, acompanhamento e outbox recuperável

Esta entrega cobre a etapa 9 do plano aprovado. O upload persiste a origem e a intenção de processamento; um relay independente publica no Redis e o worker prepara o arquivo. A leitura financeira por chunks corresponde à próxima etapa. Os casos de uso de postagem e consulta de saldos já implementados continuam disponíveis.

## API disponível

As três rotas exigem `Authorization: Bearer <JWT>`. Dados e erros de negócio usam `Cache-Control: no-store, private`.

| Método e caminho | Resultado |
| --- | --- |
| `POST /api/v1/imports` | Aceita um campo multipart `file` com um CSV e retorna 202 com `Location` |
| `GET /api/v1/imports` | Importações do usuário, por ID decrescente, padrão/máximo de 10 por página |
| `GET /api/v1/imports/{importId}` | Acompanhamento de uma importação do usuário |

A listagem aceita `page` e `per_page`. Valores inválidos, arrays, frações, overflow de offset ou `per_page > 10` retornam 422. Página além da última retorna `data: []`. O detalhe de importação alheia e o de importação inexistente retornam o mesmo 404 `import_not_found`. O titular vem do JWT; não há campo de proprietário aceito no upload.

O corpo aceita exatamente um arquivo, sem campos adicionais, arquivos repetidos ou arrays de arquivos. O nome deve terminar em `.csv` sem distinção de maiúsculas e ter até 255 bytes UTF-8 após remover componentes de diretório. O MIME informado pelo cliente não é prova de conteúdo. ZIP não é aceito: extraia o CSV antes do envio.

O limite é **100.000.000 bytes por arquivo**, e o arquivo não pode estar vazio. O corpo completo admite 110.000.000 bytes para o envelope multipart. PHP 8.4 faz parsing explícito com `request_parse_body()`; a imagem configura `enable_post_data_reading=Off`, permitindo rejeitar partes excedentes em vez de ignorá-las. O endpoint recusa a configuração nativa incompatível com 503. Autenticação/login continuam usando JSON.

| Situação | HTTP / código |
| --- | --- |
| Token ausente, inválido, expirado ou revogado | 401 `unauthenticated` |
| Arquivo/corpo acima do limite | 413 `upload_too_large` |
| Content-Type incorreto | 422 `multipart_required` |
| Multipart malformado ou com partes excedentes no parser nativo | 422 `invalid_multipart` |
| Campo ausente, adicional, aninhado ou diferente de `file` | 422 `one_csv_required` quando identificado após parsing |
| Upload incompleto, arquivo vazio ou nome inválido | 422 `incomplete_upload`, `empty_csv_file` ou `invalid_csv_file` |
| Armazenamento/banco temporariamente indisponível | 503 `import_unavailable` |

O Nginx também está configurado para responder 413 em JSON antes do PHP, quando necessário. Essa resposta pública contém somente mensagem/código e permite CORS. Os demais responses seguem o CORS configurado da API. Cabeçalho ou integridade inválidos descobertos no worker aparecem posteriormente no recurso de acompanhamento, sem converter o 202 anterior em sucesso financeiro.

Exemplo de resposta inicial, com ID e tamanho ilustrativos:

```json
{
  "data": {
    "id": "15",
    "original_name": "financial_transactions.csv",
    "file_size_bytes": 788178,
    "status": "pending",
    "processed_rows": "0",
    "inserted_rows": "0",
    "duplicate_rows": "0",
    "rejected_rows": "0",
    "created_at": "2026-09-23T12:00:00.000000Z",
    "prepared_at": null,
    "started_at": null,
    "finished_at": null,
    "error_code": null,
    "status_url": "/api/v1/imports/15"
  }
}
```

O header `Location` contém o mesmo caminho relativo de `status_url`. IDs e contadores são strings decimais; tamanho em bytes é inteiro limitado a 100.000.000. Timestamps são UTC. Caminho privado, checksum, proprietário interno e tokens de posse não são expostos. A listagem acrescenta o mesmo `meta` usado nos saldos. O contrato completo está em [openapi.yaml](openapi.yaml).

## Persistência e contratos

Controllers recebem/validam o transporte, instanciam DTOs e chamam `UploadCsvUseCase`, `ListImportsUseCase` ou `GetImportUseCase`. `ImportRepository`, `ImportFileStorage`, `ImportPreparationRepository`, `OutboxRepository` e `OutboxPublisher` trafegam somente escalares e DTOs próprios. `UploadedFile`, Eloquent, Carbon e conexões permanecem nos adapters. `ImportRecord::toData()` converte o registro antes de cruzar a fronteira.

O armazenamento lê blocos de 1 MB, confere o tamanho real e calcula SHA-256 durante a cópia. O destino usa chave `imports/<64 caracteres hexadecimais aleatórios>.csv`, criação exclusiva e permissões privadas. `fflush`/`fsync` ocorrem antes do registro SQL. O volume é compartilhado por aplicação e worker, fora da raiz pública. A fila transporta somente o ID da entrega, nunca o CSV ou seu conteúdo.

Depois da gravação do arquivo, uma transação cria `imports`, o evento `ImportRequested` versão 1 e sua entrega `import-preparer`. O endpoint responde 202 depois do commit, sem chamar Redis. O banco indisponível resulta em 503. Falha SQL com ausência de referência confirmada remove o arquivo; em caso de commit ambíguo ou indisponibilidade na conferência, o arquivo é preservado. Ainda não existe comando de limpeza periódica dos órfãos remanescentes. Não se deve remover arquivos referenciados por importações recuperáveis.

O checksum integral detecta alteração da origem. Ele não deduplica uploads: reenviar o arquivo cria outro acompanhamento. A idempotência financeira pertence ao hash canônico de cada registro por titular, já implementado na postagem da [etapa 05](step-05.md), e será conectado ao leitor de chunks. A conta continuará vindo do sufixo `#N` da descrição, sujeita à autorização do titular; `amount` continuará representando centavos BRL.

## Relay e recuperação

`outbox:relay` reserva até 10 entregas elegíveis por transação com `FOR UPDATE SKIP LOCKED`. O commit libera os locks antes da publicação Redis. A reserva tem token aleatório e vence em 60 segundos. Somente `import-preparer` está habilitado nesta entrega.

| Estado da entrega | Significado |
| --- | --- |
| `pending` | Intenção durável aguardando disponibilidade/retentativa |
| `publishing` | Reservada temporariamente por um relay |
| `published` | Redis aceitou o job; o consumidor ainda não confirmou o efeito |
| `acknowledged` | O consumidor confirmou seu resultado no MySQL |
| `failed` | Evento incompatível com o consumidor, retido para diagnóstico |

Uma falha conhecida de publicação volta a entrega para `pending` com atraso exponencial de 2 a 256 segundos. Reservas expiradas podem ser assumidas por outro relay. Entregas `published` sem confirmação voltam a ser elegíveis após 300 segundos, cobrindo perda de jobs no Redis ou falha após publicação. A publicação usa conexão Redis própria com limites de conexão/leitura de 2 segundos, sem afetar o consumo bloqueante do worker.

Atualizações do relay comparam status e token de posse. Assim, uma resposta atrasada não sobrescreve a confirmação de um worker rápido nem a reserva de outro processo. Se publicar e perder a resposta da escrita SQL seguinte, a intenção continua recuperável. A entrega é pelo menos uma vez: duplicatas são esperadas e o consumidor precisa ser idempotente.

O comando aceita `--once` para um lote e `--max-time=1..86400` (padrão 3600). O modo contínuo aguarda um segundo entre passagens, registra `claimed`, `published` e `retrying` em JSON e atende SIGTERM/SIGINT ao concluir o lote. Em `--once`, falha de persistência ou publicação retorna código 1. O serviço Compose reinicia o processo após sua renovação periódica.

## Preparação pelo worker

`PrepareImportJob` chama `PrepareImportUseCase` na fila `imports`. O job tem timeout de 60 segundos, até três tentativas com pausas de 5/30/60 segundos, e a conexão da fila usa `retry_after=90`. A intenção durável permanece elegível para reconciliação mesmo se as tentativas do job se esgotarem por uma falha técnica.

O consumidor bloqueia a entrega e a importação, verifica se já houve conclusão e reserva a importação por 180 segundos. O trabalho de arquivo ocorre fora da transação. A conclusão exige novamente o mesmo token de posse ainda válido; um processo antigo não pode concluir nem liberar a posse de outro.

O worker valida tamanho e checksum integral em streaming e lê um cabeçalho com menos de 4096 bytes, em uma única linha física. Aceita UTF-8 com BOM opcional, LF/CRLF e nomes de campos entre aspas. A sequência deve ser exatamente `date,description,amount,type`. Linhas financeiras, inclusive campos multilinha, serão interpretadas no próximo incremento.

Em uma única transação, a preparação bem-sucedida grava:

1. `prepared_at`, `byte_offset` após o cabeçalho e `last_record_number = 1`.
2. Evento `ImportChunkRequested` versão 1 com `import_id` e `checkpoint_version: "0"`, mais entrega `csv-importer`.
3. Confirmação `acknowledged` da entrega inicial e liberação da posse da importação.

Uma falha entre essas escritas desfaz todas elas. Jobs repetidos ou consumidores concorrentes não criam outro evento inicial. Arquivo ausente, alterado ou com cabeçalho inválido encerra a importação como `failed`, com `finished_at` e `source_file_missing`, `source_file_changed` ou `invalid_csv_header`; a entrega é confirmada como tratada. Falhas técnicas liberam a posse atual quando possível e permitem nova tentativa.

**Nesta versão, uma preparação válida mantém `status = pending`, contadores zero e `started_at = null`.** `prepared_at` informa que a origem foi verificada. A entrega `csv-importer` aguarda a próxima etapa; `dashboard-cache-invalidator`, gerada pela postagem financeira anterior, também aguarda seu consumidor. Nenhuma das duas é publicada ou confirmada antecipadamente pelo relay atual. O upload/preparação ainda não cria operações, partidas, resultados de linha ou mudanças nos saldos.

## Atualização e demonstração

A migration `000008_add_import_intake_metadata` acrescenta `original_name` e `prepared_at`. Registros anteriores recebem o nome padrão `import.csv`. Ela não modifica o livro contábil nem seus triggers. Preserve `.env` e volumes ao atualizar os arquivos:

```bash
bash scripts/bootstrap.sh
bash scripts/check.sh
docker compose ps outbox-relay worker balance-projector
docker compose logs --tail=20 outbox-relay worker
```

O bootstrap reconstrói a imagem PHP para aplicar `enable_post_data_reading=Off`, aplica a migration e inicia o relay e o worker nas filas `imports,default`. Reiniciar somente o container com a imagem antiga não aplica a configuração PHP nova. O perfil `test` agora inclui `redis-test`; `scripts/test-mysql.sh` inicia e para esse serviço junto com `mysql-test`, sem tocar nos dados de desenvolvimento.

Com um JWT obtido pelo login JSON da [etapa 04](step-04.md), extraia o CSV para um caminho local e use:

```bash
# Substitua ACCESS_TOKEN pelo token e o caminho pelo CSV extraído.
curl -i -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  -F 'file=@/caminho/financial_transactions.csv;type=text/csv' \
  http://localhost:8080/api/v1/imports

# Substitua 15 pelo ID retornado no upload.
curl -sS -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  http://localhost:8080/api/v1/imports/15
curl -sS -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  'http://localhost:8080/api/v1/imports?page=1&per_page=10'

# Publicação manual de até dez entregas elegíveis.
docker compose exec -T app php artisan outbox:relay --once
```

Para verificar a separação entre aceitação e processamento, pare `worker` e `outbox-relay`, faça o upload e consulte o acompanhamento. Ele deve estar `pending` com `prepared_at: null`; a intenção está no MySQL. Inicie os dois serviços e consulte novamente: um CSV válido terá `prepared_at` preenchido e continuará `pending` pelos motivos descritos acima. Depois de alterar o código dos processos, execute `docker compose restart worker outbox-relay`.

## Verificação e limites desta entrega

Passaram **435 testes backend, com 2342 assertions**: 158 do núcleo, 116 HTTP/armazenamento e 161 de integração. Os cenários novos incluem contratos em memória/MySQL, titularidade, paginação, rollback em cada escrita inicial, falha no repasse para o primeiro chunk, commit ambíguo, limpeza conservadora, posse expirada e duas publicações/consumidores concorrentes.

Redis 8.2.1 real foi usado para publicar e executar o job, simular job perdido e conferir republicação, tratar indisponibilidade e preservar confirmações antecipadas. MySQL 8.4.11 real executou migrations, constraints e locks com sessões independentes. Os testes HTTP usaram PHP 8.4 com o parser nativo e memória limitada a 64 MB: aceitaram 100.000.000 bytes, recusaram 100.000.001 e corpo acima de 110.000.000, além de arquivos duplicados, campos extras e arrays multipart.

Pint, PHPStan/Larastan, Composer validate, Compose config e sintaxe Bash passaram. O binário PHP portátil deste ambiente emitiu um aviso de extensão opcional de aceleração do PHPStan indisponível; a análise terminou sem erros. A execução integrada de Nginx/PHP-FPM e dos containers continua pendente no Ubuntu/CI porque o ambiente de implementação não possui daemon Docker. O frontend não mudou nesta etapa. Não foi medido desempenho de importação financeira de 100 MB, pois o leitor de chunks ainda será implementado.

## Próximo incremento

Implementar `csv-importer` com leitura de registros lógicos em streaming, até 500 registros por chunk e limites de bytes/tempo; autorização da conta no sufixo; hash canônico por linha; resultados e contadores; commit de operações, checkpoint e próxima intenção juntos. Testar reenvio completo/parcial, reordenação, sobreposição, retomada e concorrência com os resultados de referência do arquivo fornecido.

## Correção de network not found nos testes

O log enviado após a etapa 07 mostra bootstrap completo, oito serviços saudáveis e diagnósticos de MySQL, Redis, worker/volume e HTTP/CORS aprovados. Pint, PHPStan, 158 testes do núcleo e 116 testes HTTP também passaram. O check parou ao iniciar `mysql-test`, antes de executar a integração e as verificações frontend:

```text
Error response from daemon: network <id> not found
```

A sequência indica que um container de teste antigo manteve referência ao ID de uma rede removida: o bootstrap criou `finance-ledger_default`, enquanto o check tentou iniciar o `mysql-test` existente. O log não registra a operação que removeu a rede. Repetir `up` com configuração inalterada pode reutilizar o mesmo container e repetir a falha.

`scripts/test-mysql.sh` agora usa `up --force-recreate` explicitamente para `mysql-test` e `redis-test`. Os containers são recriados na rede atual antes da espera pelos healthchecks e da execução do runner. Ambos armazenam dados descartáveis em `tmpfs`; os serviços e volumes de desenvolvimento não são alvos desse comando. A limpeza ao sair continua parando somente os serviços de teste.

Depois de substituir o script, execute `bash scripts/check.sh`. Para recuperar a instalação antes mesmo de atualizar os arquivos, na pasta do projeto:

```bash
docker compose --profile test up -d --force-recreate --wait --wait-timeout 180 mysql-test redis-test
bash scripts/check.sh
```

Esse ajuste não exige novo bootstrap ou build. Foram conferidas a sintaxe Bash, a configuração Compose e a disponibilidade da opção no CLI. A reprodução e a recuperação com daemon Docker continuam pendentes de confirmação no Ubuntu; as suítes PHP não foram repetidas para esta alteração exclusiva de inicialização/documentação.

## Referências

- [PHP 8.4: request_parse_body](https://www.php.net/manual/en/function.request-parse-body.php)
- [PHP: limites de upload e enable_post_data_reading](https://www.php.net/manual/en/ini.core.php)
- [Laravel 13: filas, transações e timeouts](https://github.com/laravel/docs/blob/13.x/queues.md)
- [MySQL 8.4: locking reads e SKIP LOCKED](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html)
- [Docker Compose: up e force-recreate](https://docs.docker.com/reference/cli/docker/compose/up/)
