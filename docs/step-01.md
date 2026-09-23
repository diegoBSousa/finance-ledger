# Etapa 01 — base de desenvolvimento

Entrega de 23/09/2026. Corresponde à base técnica da etapa 2 do plano aprovado; as decisões da etapa 1 já haviam sido registradas no planejamento.

## Verificado nesta entrega

| Verificação executada | Resultado |
| --- | --- |
| Instalação Composer a partir do lockfile e bootstrap Laravel | Passou |
| `composer validate --strict` | Passou |
| Pint | Passou |
| PHPStan/Larastan, nível 6 | Passou |
| PHPUnit: contrato HTTP, CORS e erros JSON | 5 testes, 12 assertions, todos passaram |
| Hash gerado pelo driver configurado | Argon2id |
| ESLint | Passou |
| Vitest/Vue Test Utils: conexão, falha HTTP, retry, falha de rede e payload inesperado | 4 testes passaram |
| TypeScript e build de produção Vite | Passaram |
| Parse/validação do Compose pela CLI v2.39.4 | Passou |
| Sintaxe Bash dos scripts | Passou |

Os testes PHP foram executados em PHP 8.4.1 temporário e os testes frontend em Node 24.19.0. A CLI Compose foi usada para validar a configuração. Não há daemon Docker neste ambiente; as imagens de desenvolvimento não foram construídas/executadas aqui.

## Aceite que precisa ser executado no Ubuntu

```bash
bash scripts/bootstrap.sh
bash scripts/check.sh
```

Esperado: containers iniciados, resposta da API válida, origem frontend autorizada por CORS, conexão MySQL, conexão Redis e confirmação de que o worker processou o job e leu o mesmo arquivo privado. O diagnóstico usa um arquivo temporário e uma chave Redis com identificador aleatório, que limpa ao terminar; não altera o livro contábil.

Abra também http://localhost:5173 e confira “Conexão estabelecida.”. Os testes de componente usam respostas simuladas; esta verificação no navegador confirma o endereço público configurado no seu host.

Esses comandos não foram executados contra containers nesta entrega. A CI contém o mesmo fluxo, mas ainda não foi executada no GitHub. A etapa estará validada de ponta a ponta quando essas verificações passarem no Ubuntu/CI.

## Dependências efetivamente travadas

| Pacote | Versão |
| --- | --- |
| Laravel Framework | 13.33.0 |
| PHPUnit | 12.5.35 |
| Larastan / PHPStan | 3.12.2 / 2.2.14 |
| Vue | 3.5.43 |
| TypeScript | 5.9.3 |
| Vite | 7.3.6 |
| Vitest | 4.1.11 |
| Vue Test Utils | 2.5.1 |
| ESLint | 10.11.0 |

As imagens PHP, Composer, MySQL, Redis, Nginx e Node estão identificadas por tag e digest do registro oficial no Dockerfile/Compose. O build ainda consulta o repositório Debian para as bibliotecas do sistema; não se afirma reprodução binária idêntica do build inteiro.

## Próxima etapa

Implementar o núcleo contábil e seus contratos: `Money`, identificador da conta, débito/crédito, lançamento balanceado, canonicalização da linha, Request/Response DTOs e interfaces próprias. Criar testes unitários que funcionem sem subir Laravel e uma regra de arquitetura que proíba dependências do framework no núcleo.

Em seguida, implementar migrations e repositórios MySQL, autenticação JWT, postagem atômica, invalidação `staled`, recálculo, processo independente, outbox/importação e telas financeiras, seguindo a ordem do plano.
