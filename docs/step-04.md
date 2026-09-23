# Etapa 04 — autenticação JWT e revogação durável

Corresponde à etapa 5 do plano aprovado. Entrega login, consulta da identidade autenticada e logout na API independente. A implementação não usa Inertia nem autenticação por cookie/sessão.

## API entregue

| Método e rota | Autenticação | Resposta de sucesso |
| --- | --- | --- |
| `POST /api/v1/auth/login` | E-mail e senha no JSON | 200, token e perfil público |
| `GET /api/v1/auth/me` | Header Bearer | 200, perfil do titular do token |
| `POST /api/v1/auth/logout` | Header Bearer | 200, confirmação da revogação deste token |

Login recebe somente `email` e `password`. O e-mail é normalizado para minúsculas/sem espaços externos; a senha preserva seus espaços e aceita até 1024 bytes, sem bytes nulos. Campos extras como `user_id` não determinam a identidade. IDs públicos são strings decimais.

Exemplo de resposta de login:

```json
{
  "data": {
    "access_token": "<JWT assinado>",
    "token_type": "Bearer",
    "expires_in": 900,
    "user": {"id": "1", "name": "Demo Ledger", "email": "demo@example.test"}
  }
}
```

`/me` retorna `{"data":{"id":"1","name":"Demo Ledger","email":"demo@example.test"}}`. O ID do exemplo não deve ser presumido: ele depende do banco existente. `/logout` retorna `{"data":{"revoked":true}}` depois que a gravação da revogação foi concluída.

Respostas de autenticação usam `Cache-Control: no-store, private` e `Pragma: no-cache`; não criam cookies. O header Authorization é obrigatório nas rotas protegidas: token no corpo, query string ou cookie não autentica. O contrato OpenAPI está em [openapi.yaml](openapi.yaml).

| Situação | HTTP e código |
| --- | --- |
| E-mail inexistente ou senha incorreta | 401, `invalid_credentials`, mesma mensagem |
| Token ausente, inválido, expirado, revogado ou usuário removido | 401, `unauthenticated`, com `WWW-Authenticate: Bearer` |
| Campos inválidos | 422, erros de validação Laravel |
| Tentativas excedidas | 429, `rate_limited`, com `Retry-After` |
| Chave ausente/inválida ou indisponibilidade dos repositórios de autenticação | 503, `authentication_unavailable` |

## JWT e senhas

- `lcobucci/jwt` 5.6.0, fixado no `composer.lock`, faz a assinatura e verificação criptográfica.
- HS256 é fixado pelo servidor; o algoritmo informado no token não seleciona outro verificador.
- Header `typ=at+jwt`; headers adicionais não suportados, inclusive indicação de chaves remotas, são rejeitados.
- Claims obrigatórias: `iss`, `aud`, `sub`, `jti`, `iat`, `nbf` e `exp`. Assinatura, emissor, audiência e datas são validados antes de consultar a identidade no banco.
- `sub` é o ID canônico do usuário como string; `jti` tem 32 bytes aleatórios codificados em hexadecimal. Cada login cria um identificador novo.
- Duração de 900 segundos, sem tolerância adicional após a expiração. Datas são inteiros; `iat=nbf`, `exp>iat` e validade não pode superar 900 segundos. Hosts devem manter seus relógios sincronizados.
- O payload contém identificação e datas, sem senha, hash, nome, e-mail ou permissões. O perfil atual é lido do MySQL a cada autenticação.
- JWT recebido aceita até 4096 caracteres. Assinatura alterada, `alg=none`, audiência/tipo incorretos e claims malformadas resultam em 401.

O adapter `Argon2idPasswordHasher` usa Laravel Hash com Argon2id: 65536 KiB, 3 iterações e 1 thread. Credenciais ausentes passam por uma verificação de hash fictício com esse custo padrão antes de retornar a mesma falha das credenciais incorretas. Se os parâmetros forem alterados no futuro, atualizar também esse hash de custo equivalente. Hashes de outros algoritmos não são aceitos pelo login desta versão.

Um login válido atualiza hashes Argon2id com parâmetros antigos. A atualização compara o hash anterior byte a byte e não sobrescreve uma senha que tenha mudado concorrentemente. O DTO público e os presenters não expõem o hash.

O limiter Laravel usa o cache configurado: Redis no Compose e array isolado nos testes. Limita todas as tentativas, inclusive bem-sucedidas, a 5 por combinação e-mail/IP e 30 por IP a cada minuto. O e-mail é normalizado antes de construir a chave de limitação; essa chave contém o hash da identidade. Trocar o e-mail não contorna o limite total do IP.

## Revogação e comportamento do logout

A migration nova cria `revoked_tokens`: identificador do token, usuário, expiração em segundos e data da revogação. O identificador é único, há FK para o usuário e índice de expiração. O JWT completo nunca é armazenado nessa tabela.

Toda requisição protegida valida a assinatura/claims, consulta a revogação no MySQL e procura o usuário atual. A revogação não é cacheada em Redis. Limpar cache ou abrir uma nova conexão não torna um token revogado válido. Uma falha de leitura da revogação bloqueia o acesso; uma falha de escrita no logout retorna erro, sem confirmar saída.

Logout afeta somente o token autenticado naquela requisição. Outros tokens do mesmo usuário continuam válidos. A escrita da revogação é idempotente, preservando o registro original; uma segunda chamada HTTP com um token já revogado recebe 401. Requisições que já passaram pela autenticação antes do logout podem concluir.

O comando abaixo remove apenas revogações de tokens que já expiraram. Ele pode ser executado periodicamente; a correção da autenticação não depende de sua execução:

```bash
docker compose exec app php artisan auth:prune-revoked-tokens
```

Não há refresh token nesta versão. A futura SPA manterá o access token em memória e exigirá novo login depois de expiração ou recarga, conforme o plano aprovado.

## Arquitetura e titularidade

As portas `UserRepository`, `PasswordHasher`, `TokenService`, `TokenRevocationRepository` e `Clock` transitam somente escalares e DTOs próprios. Controllers recebem FormRequest/Request na borda, constroem Request DTOs, invocam casos de uso e apresentam Response DTOs. `User` implementa `toData()` e `toCredentialsData()`; apenas o repositório de credenciais usa o segundo.

O middleware `jwt.auth` coloca `AuthenticationData` nos atributos internos da requisição. Os controllers usam `AuthenticatedContext::fromRequest()`. Os futuros endpoints financeiros deverão obter o ator desse contexto, mantendo autorização por titular e paginação de no máximo 10 itens. Não usar IDs do corpo/query para substituir a identidade, nem misturar o guard de sessão padrão com essa API.

Há teste MySQL que autentica um usuário por JWT, passa seu ID confiável ao caso de uso de preparação do CSV e verifica a recusa da conta de outro titular. `/me` também ignora `user_id` enviado pelo cliente. Endpoints financeiros ainda não estão disponíveis; sua autorização HTTP será verificada quando forem implementados.

## Configuração e execução local

Depois de atualizar o código, preservando suas configurações e volumes:

```bash
bash scripts/bootstrap.sh
bash scripts/check.sh
```

O bootstrap instala o lockfile atualizado, aplica a migration e acrescenta `JWT_SECRET` ao `.env` se ausente/vazio. São 32 bytes gerados aleatoriamente e codificados em base64, independentes de `APP_KEY`. Uma chave existente é preservada; não há segredo padrão de desenvolvimento no código. `JWT_ISSUER` e `JWT_AUDIENCE` têm padrões `finance-ledger-api` e `finance-ledger-spa`.

Para testar manualmente, substitua a senha abaixo pelo valor inicial de `DEMO_USER_PASSWORD` de sua instalação:

```bash
curl --request POST http://localhost:8080/api/v1/auth/login \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{"email":"demo@example.test","password":"YOUR_DEMO_USER_PASSWORD"}'

curl http://localhost:8080/api/v1/auth/me \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer YOUR_ACCESS_TOKEN'

curl --request POST http://localhost:8080/api/v1/auth/logout \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer YOUR_ACCESS_TOKEN'
```

O seed preserva senhas já existentes. Alterar `DEMO_USER_PASSWORD` depois da criação do usuário não redefine sua senha. A composição usa HTTP restrito a localhost; uma implantação acessível pela rede deve usar HTTPS. Trocar `JWT_SECRET` e recriar os serviços invalida todos os tokens antigos; rotação com várias chaves não foi incluída.

## Validação executada

| Verificação | Resultado |
| --- | --- |
| Núcleo puro, incluindo contratos de autenticação | 116 testes, 666 assertions |
| HTTP, adapter JWT e isolamento do ambiente | 71 testes, 184 assertions |
| MySQL real, incluindo contratos e durabilidade do logout | 71 testes, 174 assertions |
| Total backend | **258 testes, 1024 assertions, passaram** |
| Pint, PHPStan/Larastan nível 6 e Composer validate | Passaram |
| Compose e sintaxe Bash | Passaram |

Os testes JWT cobrem adulteração, outra chave, algoritmo/tipo incorretos, claims ausentes ou com tipos errados, expiração na fronteira exata e configuração sem chave forte. Os testes HTTP cobrem transporte, payload público, tentativas excedidas, revogação, preservação das outras sessões e falha da persistência. Os mesmos contratos de usuários/revogação rodam em memória e no MySQL.

Execução em PHP 8.4.1 e MySQL Community 8.4.11 temporário. Não há daemon Docker neste ambiente: bootstrap completo, limiter sobre Redis real, containers e CI ainda precisam executar no Ubuntu/GitHub. O PHP portátil emite aviso sobre a extensão opcional de aceleração do PHPStan; a análise termina sem erros. O frontend não mudou e não foi revalidado nesta etapa.

## Correção do isolamento dos testes no container

Na primeira execução enviada pelo usuário, dois testes de limitação receberam 429 antes do momento esperado. Uma segunda execução imediata teve dez falhas, também por 429. Os logs das exceções `AuthenticationUnavailable` vinham dos testes que simulam falhas de persistência e esperam 503; essas exceções não eram as falhas que interrompiam a suíte.

A causa estava nos arquivos `phpunit.xml` e `phpunit.integration.xml`: `<env force="true">` atualiza `getenv()` e `$_ENV`, mas não substitui a cópia das variáveis em `$_SERVER`. O PHP CLI no container recebe ali `APP_ENV=local`, `CACHE_STORE=redis` e `LOG_CHANNEL=stderr`. Laravel/phpdotenv consulta `$_SERVER` primeiro, então a suíte HTTP usava Redis persistente para os contadores, em vez de um cache em memória para cada aplicação de teste. Recriar os containers não corrige essa precedência.

Os dois XMLs agora também declaram `<server>` com os mesmos valores isolados. O canal de log de testes usa o valor citado `&quot;null&quot;`, preservando o nome textual do canal `null` em vez de convertê-lo ao valor PHP `null`. A configuração da aplicação e seus limites de login permanecem iguais.

`EnvironmentIsolationTest` verifica o ambiente de testes, os serviços em memória e o descarte dos contadores quando a aplicação é recriada. Antes da correção, com as variáveis do Compose herdadas pelo processo, esses testes falharam por ambiente incorreto e por receber `RedisStore` em vez de `ArrayStore`. Depois da correção, os dois passaram. Também passaram o núcleo, duas execuções consecutivas da suíte HTTP completa e a suíte MySQL. Nos testes HTTP, as portas de Redis/MySQL foram apontadas para destinos inacessíveis para detectar qualquer dependência acidental desses serviços.

Para aplicar, atualize `backend/phpunit.xml`, `backend/phpunit.integration.xml` e acrescente `backend/tests/Feature/EnvironmentIsolationTest.php` do pacote corrigido. Com os serviços já iniciados pelo bootstrap:

```bash
bash scripts/check.sh
```

Não é necessário alterar `.env`, reinstalar dependências, refazer migrations ou apagar volumes. Caso tenha executado `artisan config:cache` manualmente, limpe essa configuração antes de testar, com `docker compose exec app php artisan config:clear`; o bootstrap do projeto não gera esse cache.

## Próximo incremento

Postagem atômica do cabeçalho e das duas partidas, unicidade/idempotência sob concorrência, imutabilidade do livro e trigger que avança revisão/versão e marca `staled`. Depois será implementado o recálculo compartilhado entre consulta HTTP e serviço independente.

## Referências

- [lcobucci/jwt: configuração de algoritmo e chave](https://lcobucci-jwt.readthedocs.io/en/stable/configuration/)
- [lcobucci/jwt: constraints de validação](https://lcobucci-jwt.readthedocs.io/en/stable/validating-tokens/)
- [RFC 8725: boas práticas de JWT](https://www.rfc-editor.org/rfc/rfc8725.html)
- [PHPUnit 12.5: configuração de variáveis de ambiente e servidor](https://docs.phpunit.de/en/12.5/xml-configuration-file.html#the-php-element)
