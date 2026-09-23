# Decisões aprovadas para os próximos incrementos

Este documento registra requisitos; não afirma que já foram implementados nesta base.

1. API Laravel e SPA Vue independentes. Autenticação JWT; hash de senha Argon2id. Debezium removido.
2. Inteiros do CSV representam centavos de BRL. Persistência em `BIGINT`; DTOs JSON expõem centavos como strings decimais para preservar precisão em JavaScript.
3. O sufixo `#<número>` identifica a conta de destino. Seu proprietário será resolvido e autorizado pelo backend; não confundir com o usuário que enviou o arquivo.
4. Partidas dobradas, na perspectiva de contas financeiras de ativo: receita debita a conta financeira e credita a conta técnica de receita; despesa debita a conta técnica de despesa e credita a conta financeira. Os dois lados confirmam na mesma transação MySQL.
5. Lançamentos confirmados são imutáveis. As contas financeiras podem ficar negativas; o arquivo fornecido contém esse cenário.
6. `account_balances` é uma projeção reconstruível, com `staled`, versões do ledger e do cálculo, totais e saldo. Todo novo lançamento marca a conta como `staled` dentro da transação SQL, inclusive em bulk insert.
7. Um processo independente recalcula somente contas `staled`. A listagem também recalcula as contas pendentes antes de devolver os saldos. O protocolo de locks/versões deve impedir que um recálculo limpe uma invalidação mais recente.
8. Idempotência por SHA-256 da linha interpretada e canonicalizada, com índice único MySQL por proprietário. Nome do arquivo, linha e importação não entram na identidade. Arquivos parciais, sobrepostos e reordenados são aceitos.
9. Canonicalização versionada: data ISO, descrição em NFC com trim externo, tipo normalizado, centavos inteiros e número da conta. Exemplo: `["csv-row-v1","BRL","2026-08-16","Serviços de Limpeza","494618","expense","682"]`. Linhas rejeitadas não reservam a identidade financeira.
10. Duas operações reais com conteúdo totalmente igual serão consideradas uma só sem um identificador externo adicional. Correções de conteúdo não substituem silenciosamente operações já confirmadas.
11. Upload máximo de exatamente **100.000.000 bytes**, um CSV por requisição; leitura em streaming, processamento em chunks e checkpoints persistidos. O endpoint deve retornar após o upload/registro, sem fazer a importação financeira de forma síncrona.
12. Toda listagem paginada terá padrão 10 e máximo 10; `per_page=11` será rejeitado. Tamanho de chunk do worker é independente desse limite.
13. Outbox no MySQL evita a dependência de dual write para publicar eventos. Consumers serão idempotentes. Cache de dashboard usa revisão do proprietário e invalidação por eventos; não somar ambas as contrapartidas como fluxo financeiro.
14. MySQL real nos testes de triggers, transações, unicidade e concorrência. Testes com mocks sozinhos não comprovam esses comportamentos.

## Referência do CSV fornecido

| Medida | Valor |
| --- | ---: |
| Linhas de dados | 15.000 |
| Contas financeiras | 900, de 100 a 999 |
| Receitas em centavos | 3.611.960.974 |
| Despesas em centavos | 2.670.954.574 |
| Saldo financeiro em centavos | 941.006.400 |
| Partidas esperadas | 30.000 |
| Soma dos débitos e soma dos créditos | 6.282.915.548 em cada lado |
| Saldo final da conta #682 em centavos | 109.209 |

Esses números vieram da análise do arquivo durante o planejamento. Ainda não são resultados obtidos pela aplicação desta entrega.
