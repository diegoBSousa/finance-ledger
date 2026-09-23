# Etapa 02 — núcleo contábil, DTOs e contratos

Corresponde à etapa 3 do plano aprovado. Acrescenta regras de negócio puras à infraestrutura da etapa 01.

## Implementação

| Componente | Responsabilidade |
| --- | --- |
| `Domain/Shared/Money` | Centavos imutáveis, strings decimais exatas e aritmética com verificação de overflow |
| `Domain/Accounting/Account` | Identidade interna, número externo, proprietário, papel, moeda e atividade |
| `Posting` / `JournalEntry` | Partidas positivas, proprietário único e igualdade entre débitos e créditos |
| `JournalEntry::forMovement()` | Exatamente duas partidas para receita/despesa, com contas e lados corretos |
| `AccountKind::balance()` | Saldo conforme a natureza, incluindo ativos negativos |
| `Application/Imports/CsvRowCanonicalizer` | Validação dos quatro campos interpretados, normalização, sufixo da conta e SHA-256 |
| `PrepareCsvPostingUseCase` | Resolução/autorização de contas e construção da resposta com o lançamento preparado |
| `AccountRepository` | Consulta por número externo ou por proprietário/papel técnico; retorna `AccountData` |
| `PageRequest` | Padrão/máximo de 10 itens, página positiva e offset sem overflow |

O núcleo não contém `Illuminate`, Eloquent, `Carbon`, objetos HTTP, funções auxiliares do Laravel ou tipos de JWT. O processo que rodar o caso de uso continua responsável por fornecer um ator autenticado/confiável.

## Exemplo: despesa na conta #682

Entrada:

```csv
2026-08-16,Serviços de Limpeza #682,494618,Despesa
```

Nos testes, a conta externa `682` tem ID interno `42`, pertence ao usuário `7` e tem a contrapartida de despesas `7002`. O caso de uso retorna estes dados, sem gravá-los:

```json
{
  "uploadedByUserId": "7",
  "financialAccountId": "42",
  "financialAccountNumber": "682",
  "movementType": "expense",
  "sourceRowHash": "d4862cdd783f053103511bf4f49bfb5438fce54b40bbabc58163b424e1136a8b",
  "originalDescription": "Serviços de Limpeza #682",
  "journal": {
    "ownerUserId": "7",
    "transactionDate": "2026-08-16",
    "description": "Serviços de Limpeza",
    "postings": [
      {"accountId": "7002", "side": "debit", "amountMinor": "494618", "currency": "BRL"},
      {"accountId": "42", "side": "credit", "amountMinor": "494618", "currency": "BRL"}
    ]
  }
}
```

O campo adicional `canonicalRecord` é omitido acima por legibilidade e contém exatamente:

```json
["csv-row-v1","BRL","2026-08-16","Serviços de Limpeza","494618","expense","682"]
```

O JSON acima representa o DTO interno atual. O presenter HTTP, incluindo seus nomes de campos públicos, será implementado com os endpoints financeiros.

## Contrato da identidade de linha

- Entrada: campos já decodificados por um parser CSV, na ordem `date,description,amount,type`. Não se divide CSV com `explode(',')`.
- Unicode NFC e remoção de whitespace externo, inclusive espaços Unicode. Caixa, acentos e espaços internos significativos da descrição são preservados.
- O último sufixo `#<dígitos>` determina a conta; zeros à esquerda são normalizados. A descrição antes dele deve ser não vazia.
- Datas canônicas `YYYY-MM-DD`, incluindo validação de calendário. `Receita`/`Despesa` aceitam variação de caixa e viram `income`/`expense`.
- Centavos são inteiros positivos no intervalo assinado de 64 bits. Frações, notação científica, sinais e overflow são recusados. A escala nunca é multiplicada por 100.
- JSON em ordem fixa, sem espaços decorativos, Unicode e barras sem escape; aspas, controles e separadores U+2028/U+2029 seguem os escapes JSON do PHP. Flags: `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`.
- SHA-256 hexadecimal minúsculo. Nem ator, posição, arquivo nem horário entram no hash.
- Os testes fixam o exemplo informado e um segundo exemplo com barras, aspas, newline e U+2028. Mudanças nessa serialização exigem migração/reconciliação; não basta trocar `csv-row-v1`.

O teste de conjuntos parciais/sobrepostos verifica a estabilidade da identidade. Não simula recuperação de jobs nem comprova a futura unicidade SQL. Linhas com o mesmo conteúdo canônico representam uma única operação pela regra aprovada; operações reais idênticas exigiriam um identificador externo para serem distinguíveis.

## Validação executada

| Verificação | Resultado |
| --- | --- |
| PHPUnit do núcleo, com autoload de Laravel bloqueado | 92 testes, 435 assertions, passaram |
| PHPUnit HTTP da etapa anterior | 5 testes, 12 assertions, passaram |
| Pint | Passou |
| PHPStan/Larastan, nível 6 | Passou |
| `composer validate --strict` | Passou |

Execução em PHP 8.4.1 de 64 bits. Os testes do núcleo usam PHPUnit diretamente, sem estender `Tests\TestCase` do Laravel e sem iniciar banco, Redis ou worker. O contrato do repositório foi executado contra o double de testes; o adapter MySQL ainda não existe.

## Como executar

Em uma instalação existente, atualize os arquivos preservando seu `.env` e instale o lockfile atualizado:

```bash
docker compose run --rm --no-deps app composer install --no-interaction
docker compose run --rm --no-deps app composer test:core
docker compose run --rm --no-deps app composer test
```

Uma instalação nova usa `bash scripts/bootstrap.sh`. A verificação completa da composição continua disponível em `bash scripts/check.sh`, que agora executa as duas suítes PHP.

## Próximo incremento

Criar migrations, constraints e índices do livro contábil, contas/projeções/estados e estruturas de importação/outbox; implementar seeds e o adapter MySQL de contas; executar o contrato reutilizável no banco real. Depois vêm JWT, a escrita transacional, trigger de invalidação e recálculo de saldos.

Persistência, unicidade concorrente, `staled`, projeções, serviços de background financeiros, retomada de importações e upload de 100 MB ainda não foram implementados ou validados nesta etapa. Também permanece pendente a execução dos containers no Ubuntu/CI, pois este ambiente não possui daemon Docker.

## Referências

- [PHP: inteiros e comportamento de overflow](https://www.php.net/manual/en/language.types.integer.php)
- [PHP: normalização Unicode](https://www.php.net/manual/en/normalizer.normalize.php)
- [PHP: parsing de CSV](https://www.php.net/manual/en/function.str-getcsv.php)
- [PHPUnit: testes e data providers](https://docs.phpunit.de/en/12.5/writing-tests-for-phpunit.html)
