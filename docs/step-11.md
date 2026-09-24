# Etapa 11 — escala, concorrência e recuperação

Esta entrega corresponde à etapa 13 do plano aprovado. Acrescenta um ensaio reproduzível com upload HTTP, autenticação JWT, MySQL, Redis, relay, workers e projector reais. O código de instrumentação fica em `backend/tests/Performance/`, fora da aplicação e das suítes executadas normalmente pelo PHPUnit.

## Executar no Ubuntu

Depois de atualizar os arquivos e executar `bash scripts/bootstrap.sh`:

```bash
bash scripts/test-performance.sh
```

O script usa `compose.performance.yaml`, projeto exclusivo por execução, banco `finance_ledger_performance`, prefixo Redis e volumes próprios. Não lê o `.env` de desenvolvimento, não publica portas e não altera seus dados. Ao terminar, remove somente os containers/volumes dessa execução. O runner exige ambiente `testing`, autorização explícita de reset, configurações próprias e banco vazio; recusa configuração Laravel em cache.

Os arquivos de entrada são gerados em streaming. Não é necessário baixar ou adicionar um CSV de 100 MB ao Git. MySQL usa volume em disco, buffer pool de 256 MiB e redo de 128 MiB. Reserve alguns GB livres e execute sem outras cargas pesadas se quiser comparar tempos. O relatório identifica os recursos configurados; eles não representam limites ou capacidade de produção.

Para verificar rapidamente a instalação do ensaio:

```bash
bash scripts/test-performance.sh --quick
```

O modo `quick` reduz as massas principais, mas mantém a interrupção e os tempos reais de recuperação. Portanto, ainda leva vários minutos e **não valida a importação financeira de 100 MB**. O ensaio completo é separado de `scripts/check.sh` para não acrescentar uma carga longa a cada edição. Rode ambos antes da entrega final.

## Massa e controles

| Cenário completo | Registros de entrada | Bytes | Efeito esperado |
| --- | ---: | ---: | --- |
| Memória de referência | 10.000 | 5.000.000 | 10.000 operações novas |
| Escala | 200.000 | 100.000.000 | 200.000 operações novas |
| Sobreposição A | 10.000 | 5.000.029 | Compete com B pela identidade de 5.000 operações |
| Sobreposição B | 10.000 | 5.000.029 | União A/B de 15.000 operações |
| Reenvio parcial de B | 10.000 | 5.000.029 | Zero operações novas, 10.000 duplicatas |
| Recuperação | 2.000 | 1.000.029 | 2.000 operações, mesmo após matar o worker |

As linhas sintéticas têm aproximadamente 500 bytes, descrições distintas, data `2026-08-16` e contas #100–#999. Alternam despesas de 37 centavos e receitas de 101 centavos. A largura é deliberada e registrada: **100 MB com 200 mil linhas não equivalem ao custo de 100 MB com milhões de linhas curtas**. O CSV original de 15 mil registros continua sendo validado pela suíte de integração com seus próprios totais.

O gerador calcula controles independentes antes do upload. Ao concluir cada cenário, o ensaio verifica quantidade de operações e partidas, revisão financeira, equilíbrio de cada lançamento e saldo de cada conta contra esses controles. Também verifica dashboard, recálculo de #682, páginas de até dez itens e recusa de `per_page=11`.

Controle final da execução completa: 227.000 operações, 454.000 partidas, receitas de 11.463.500 centavos, despesas de 4.199.500 centavos e saldo de 7.264.000 centavos. Os arquivos sobrepostos e o reenvio não aumentam esses valores indevidamente.

## Interrupção e retomada

Um observador exclusivo do teste cria uma barreira depois de um INSERT no segundo chunk, dentro da transação ainda aberta. O primeiro chunk de 500 registros já está confirmado. O supervisor verifica essa fronteira e envia `SIGKILL` ao worker. Outro processo assume o consumo.

O teste não altera timestamps, leases, `retry_after` nem disponibilidade da outbox para acelerar a recuperação. Mantém o job reservado no Redis e espera o fluxo normal: uma entrega anterior ao vencimento do lease pode ser ignorada; a outbox pode republicar a intenção não confirmada depois de 300 segundos. O cenário falha se surgirem lançamentos sem checkpoint, registros perdidos, duplicatas contábeis ou jobs definitivamente falhados.

Depois dos imports, o teste consulta um saldo pendente sem projector e confirma o recálculo pelo GET. Em seguida inicia o projector em outro processo, espera todas as pendências e confere as projeções de todas as contas.

## Instrumentação e arquivos

Os resultados ficam em `artifacts/performance/<data>-<pid>/`:

| Arquivo | Conteúdo |
| --- | --- |
| `report.json` | Resultado final, controles, tempos, memória, chunks e consultas HTTP |
| `progress.json` | Último checkpoint do relatório, inclusive em uma interrupção abrupta do ambiente |
| `jobs.jsonl` | Duração, consultas e memória de cada execução de job |
| `explain.json` | SQL real capturado dos adapters de leitura, bindings e `EXPLAIN FORMAT=JSON` |
| `crash-ready.json` | Processo, checkpoint e posição da interrupção intencional |
| `api.log`, `relay.log`, `worker-*.log`, `projector.log` | Logs dos processos reais |
| `run.log`, `services.log` | Progresso do supervisor e logs do Compose |

`total_seconds` começa antes do upload e termina quando o polling observa a conclusão. Inclui upload, preparação, espera por fila/relay e persistência. A duração de um chunk mede a execução do job, sem sua espera na fila. O pico PHP vem de `memory_get_peak_usage(true)` reiniciado a cada job; o RSS é obtido pelo supervisor em `/proc`. Os workers têm `memory_limit=128M`.

`query_count` conta comandos SQL enviados pela aplicação durante o job. Não inclui comandos internos executados pelos triggers, controle de transações do PDO ou as consultas auxiliares feitas pela própria instrumentação antes/depois do job. Tempos de SQL incluem espera por locks. Os contadores InnoDB são globais à stack descartável: deltas de importações sobrepostas cobrem intervalos concorrentes e não devem ser somados como medidas independentes.

As leituras HTTP têm uma amostra para primeira leitura/recálculo e cinco para consultas repetidas, com mínimo, mediana e máximo. Isso serve como diagnóstico local, não como garantia de percentis ou de capacidade sob muitos usuários. Os relatórios incluem a sobrecarga da instrumentação.

## Correções encontradas

A validação de um lote reconstruía as entidades de todas as contas para cada operação. `PreparedPostingValidator::validateBatch()` agora prepara esse mapa uma vez e continua revalidando cada lançamento contra as contas bloqueadas. A ordem dos locks, a transação contábil, o hash e as verificações de partidas permanecem cobertos pelos contratos e testes de concorrência existentes.

A conexão MySQL de escrita herdava o fuso do servidor, enquanto os DTOs declaravam horários UTC. O ensaio em um servidor configurado fora de UTC expôs uma diferença de três horas. Todas as conexões MySQL agora configuram a sessão como `+00:00`. Um teste inicia a sessão em `-03:00` antes da configuração Laravel e verifica que a leitura conserva o instante e o UTC nas três conexões. Datas contábeis continuam sendo campos `DATE`, sem conversão de fuso.

Na primeira execução completa, o extrato geral levou cerca de 20 segundos e o primeiro dashboard cerca de 24 segundos, ultrapassando o timeout de 15 segundos do frontend. O `EXPLAIN` mostrou que a contagem e o dashboard escolhiam o índice de titular/hash e buscavam os registros completos em ordem dispersa. Com descrições extensas, isso exigia carregar muitos campos `TEXT` desnecessariamente.

A migration `2026_09_24_000001_cover_financial_journal_reads.php` substitui `journal_owner_date_id_index` por `journal_owner_read_index`, preservando o prefixo titular/data/ID e acrescentando conta financeira/tipo. Assim, as consultas podem obter as colunas necessárias diretamente do índice. O extrato primeiro conta, depois seleciona até dez IDs e finalmente carrega os detalhes desses IDs, mantendo as três leituras no mesmo snapshot. A mudança conserva os joins que identificam a partida financeira, a autorização e os filtros. A reversão da migration restaura o índice anterior.

Execute `bash scripts/bootstrap.sh` para aplicar a migration e reiniciar os processos ao atualizar. A validação inclui instalação, rollback/reaplicação e snapshots durante commits concorrentes.

Referência: [MySQL 8.4 — suporte a fusos e conversão de TIMESTAMP por sessão](https://dev.mysql.com/doc/refman/8.4/en/time-zone-support.html).

## Resultados observados em 24/09/2026

O ensaio completo terminou com sucesso entre `2026-09-24T18:07:55+00:00` e `2026-09-24T18:31:23+00:00`. Usou PHP 8.4.1, MySQL 8.4.11 e Redis 8.2.1 reais, dois consumidores, chunks de 500 registros e buffer pool de 256 MiB. Os relatórios anteriores interrompidos não contam como ensaios completos. Os números abaixo pertencem à repetição concluída com todas as correções.

| Massa | Tempo total | Chunks confirmados | Pico PHP por chunk | Chunk p50 / p95 / máximo |
| --- | ---: | ---: | ---: | ---: |
| 5 MB / 10.000 registros | 42,37 s | 20 | 34 MiB | 1.229,43 / 1.643,25 / 1.643,25 ms |
| 100 MB / 200.000 registros | 825,47 s | 400 | 34 MiB | 1.232,26 / 1.827,57 / 8.404,76 ms |

Não houve rejeições. A massa vinte vezes maior manteve o pico PHP dos chunks em 34 MiB, igual ao da amostra de 5 MB (34 MiB). Considerando todos os tipos de jobs, incluindo a preparação do arquivo, o maior pico PHP foi 36 MiB. O maior RSS observado entre os workers foi 60,30 MiB; RSS inclui memória do processo além das alocações contabilizadas pelo PHP.

Houve variação relevante entre execuções. Com a mesma correção de leitura, uma tentativa anterior concluiu a importação de 100 MB em 405,27 segundos, mas o ambiente foi interrompido durante a projeção e não houve relatório final. Seu [checkpoint parcial](performance/read-fix-interrupted-progress.json) está identificado como incompleto. A tabela usa a repetição integral concluída; não se deve atribuir a diferença entre execuções somente ao código ou tratá-la como um benchmark A/B com capacidade fixa.

Na massa de 100 MB, os chunks enviaram 1.008.399 comandos SQL (2.521,00 por chunk), somando 339,45 segundos nas chamadas instrumentadas. Esses tempos não devem ser somados ao tempo total: fazem parte dele.

Os dois arquivos concorrentes produziram uma união de 15.000 operações e 5.000 resultados duplicados. O reenvio parcial produziu zero operações novas e 10.000 duplicatas. O cenário de interrupção recuperou os 2.000 registros; havia apenas 500 confirmados quando o worker foi morto. A retomada terminou 304,95 segundos após o sinal, sem encurtar leases ou a republicação da outbox. Não houve jobs registrados com exceção nem jobs definitivamente falhados.

O projector independente levou 94,04 segundos para drenar as projeções pendentes, com lotes de até dez e o intervalo normal entre ciclos. Todos os saldos foram conferidos contra o controle do gerador. Antes de iniciá-lo, o GET já havia recalculado corretamente a conta #682 pendente.

### Leituras e comparação dos planos SQL

| Consulta | Antes da correção, ms | Versão final, ms | Amostras por execução |
| --- | ---: | ---: | ---: |
| Dashboard, primeira leitura | 23.857,92 | 946,85 | 1 |
| Dashboard, cache preenchido | 96,67 | 122,57 | 5 |
| Extrato geral, primeira página | 20.594,26 | 838,92 | 5 |
| Extrato geral, última página | 21.820,17 | 1.548,91 | 5 |
| Extrato da conta #682 | 110,40 | 171,96 | 5 |
| Saldo #682 pendente, recalculado no GET | 91,43 | 165,48 | 1 |
| Saldo #682 atualizado | 90,07 | 131,96 | 5 |
| Listagem de saldos | 108,46 | 164,12 | 5 |
| Resultados da importação | 112,89 | 134,28 | 5 |

Para consultas repetidas, a tabela usa a mediana; primeira leitura e recálculo têm uma única amostra. Mínimo e máximo estão no JSON. A execução anterior identificou consultas acima dos 15 segundos aceitos pelo frontend; na execução final, todas as amostras ficaram abaixo desse timeout. Isso não estabelece SLA: são medições locais com um cliente, instrumentação e tempos sujeitos à carga do ambiente. A comparação da preparação das contas está registrada separadamente e antecede a migration de leitura.

O novo índice cobre a contagem do extrato geral e os dados necessários ao dashboard. No extrato geral, a seleção dos IDs também percorre esse índice, e somente a página selecionada carrega os textos completos. Consultas por conta podem escolher os índices específicos da conta. A paginação continua usando offset: páginas profundas ainda percorrem entradas anteriores, e requisições distintas durante novas importações podem apresentar deslocamento de páginas. A contagem e os itens de uma mesma resposta conservam o snapshot consistente.

Os locks por titular e as contrapartidas compartilhadas continuam limitando o paralelismo de importações do mesmo usuário. Os deltas de espera por locks de cada cenário estão em `observed-report.json`; adicionar workers não é garantia de maior vazão. Esta etapa mede e preserva a correção desse comportamento.

### Evidências incluídas

- [Relatório final](performance/observed-report.json), [planos SQL finais](performance/observed-explain.json) e [métricas dos jobs](performance/observed-jobs.jsonl).
- [Relatório anterior à correção de leitura](performance/before-read-fix-report.json) e [planos SQL anteriores](performance/before-read-fix-explain.json).
- [Comparação da preparação de contas](performance/validator-comparison.json) e [verificações da etapa](performance/observed-validation.json).

Após as correções passaram **561 testes backend / 3.369 asserções**, além de Pint, PHPStan/Larastan nível 6 e Composer validate estrito. A suíte de integração inclui rollback/reaplicação da migration e snapshots durante commits concorrentes. A configuração do Compose de desempenho e a sintaxe do script foram validadas; os containers não foram executados aqui. O frontend permaneceu sem alterações nesta etapa; seus 44 testes são resultados da etapa 10.

## Limites de validação

O download normal do Cypress foi tentado novamente e o conteúdo recebido não pôde ser descompactado. A suíte de navegador e a revisão visual continuam pendentes no Ubuntu/CI, como registrado na etapa 10. Não há daemon Docker neste ambiente; as medições locais usam os runtimes reais PHP/MySQL/Redis e processos independentes, com os mesmos limites do ensaio, sem afirmar uma execução local dos containers.

A próxima entrega corresponde à etapa 14: consolidar README, roteiro de demonstração e instalação limpa, fechar a validação de navegador e preparar a entrega final.
