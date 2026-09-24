# Etapa 10 — aplicação Vue e testes frontend

Esta entrega implementa a etapa 12 do plano aprovado. A SPA Vue 3/TypeScript usa Vue Router e se comunica diretamente com a API Laravel. Não há Inertia, compartilhamento de models PHP com o frontend nem novos endpoints ou migrations nesta etapa.

## Telas e navegação

| Tela | Recursos |
| --- | --- |
| Login | E-mail/senha, validação, indicação de indisponibilidade e retorno à rota protegida solicitada |
| Visão geral | Receitas, despesas, saldo global e quantidade de operações; atualização manual e após progresso de importações acompanhadas |
| Contas e saldos | Filtro por número da conta, situação ativa/inativa, entradas, saídas, saldo e acesso ao extrato |
| Extrato | Filtros por conta, datas inclusivas e receita/despesa; identificação de saldo negativo e valores em BRL |
| Importações | Seleção de um CSV, progresso de envio e histórico paginado |
| Acompanhamento | Estado do processamento, contadores, resultados por registro e filtro de rejeições/duplicatas/importados |

Todas as listas solicitam `per_page=10`; não há seletor para aumentar esse tamanho. Página e filtros ficam na URL. Uma alteração de filtro volta à primeira página; os botões anterior/próxima preservam os filtros aplicados. Consultas sem registros apresentam um estado vazio. Falhas financeiras escondem os valores anteriores e oferecem nova tentativa.

O histórico usa `createWebHashHistory()`: rotas como `/#/accounts?account_number=682` dispensam regras de rewrite no servidor estático. Há carregamento das telas por demanda, navegação por teclado, labels, indicação de foco, mensagens de estado e tabelas com rolagem horizontal em telas estreitas. O link para pular ao conteúdo não altera a rota.

## Sessão e transporte

O JWT existe apenas na memória de `createAuth()`. Não é escrito em `localStorage`, `sessionStorage`, cookies ou URL. Recarregar a página exige novo login. O prazo informado pela API agenda a expiração; um 401 em requisição protegida também limpa a sessão e encaminha ao login.

Cada requisição conserva a identidade da sessão que a iniciou. Se ela mudou enquanto a resposta estava em trânsito, o cliente descarta a resposta. Isso impede tanto exibir dados de uma sessão anterior quanto deixar um 401 antigo encerrar uma sessão nova. Requisições de telas substituídas são abortadas e respostas antigas não substituem a página atual.

O logout chama a revogação durável da API. A sessão local é encerrada também quando essa chamada falha; nesse caso a tela informa que não conseguiu confirmar o encerramento no servidor. Não há renovação automática de tokens nesta entrega.

`ApiClient` centraliza cabeçalhos, query strings, limite de página, cancelamento, erros e timeouts. Leituras usam `fetch`, sem cookies e com `cache: no-store`; upload usa `XMLHttpRequest` para obter o progresso do envio. O navegador monta o boundary de `multipart/form-data`, com apenas o campo `file`. O frontend não interpreta nem carrega o CSV inteiro para validar seu conteúdo.

## Precisão e importação

Valores financeiros, IDs, revisões e contadores permanecem strings decimais. A formatação usa `BigInt`, divisão inteira por 100 e resto para os centavos, inclusive para números acima do limite seguro de `Number`. Datas financeiras são exibidas como datas de calendário, sem deslocamento por fuso; horários de envio usam o fuso do navegador.

O seletor aceita um arquivo `.csv`, não vazio, de até **100.000.000 bytes**. Essa verificação melhora a experiência; os limites e a validação autoritativa permanecem na API. Arquivos ZIP não são enviados como CSV.

A tela distingue bytes enviados de registros processados. Chegar a 100% de envio ainda exige a confirmação HTTP; somente depois a importação é acompanhada. Não existe percentual fictício do processamento, pois a API não conhece previamente o total de registros lógicos. Os contadores de importados, duplicados e rejeitados mostram o progresso confirmado.

Falha do envio pode acontecer depois de o servidor ter aceitado o arquivo. Por isso a mensagem orienta conferir o histórico; um reenvio completo ou parcial continua protegido pela idempotência da API. Uma importação interrompida preserva os registros anteriormente confirmados e a tela explica esse comportamento.

## Atualização automática

O polling usa intervalos de 2,5 segundos após a conclusão da consulta anterior. Segue apenas importações conhecidas como `pending` ou `processing`, pausa quando a aba fica oculta e é encerrado na conclusão, desmontagem da tela ou perda da sessão.

O monitor da aplicação descobre as importações da primeira página ao entrar e acompanha as importações ativas observadas nas telas ou recém-enviadas. Consulta até dez IDs por rodada, em ordem rotativa, sem sobrepor rodadas. Uma importação antiga que não está entre as dez mais recentes é incorporada ao acompanhamento ao abrir sua página no histórico. Não existe varredura periódica de todo o histórico nem sincronização entre abas.

Mudanças nos contadores de inserção/estado invalidam as consultas financeiras visíveis. O dashboard, as contas e o extrato consultam a API novamente; a interface não tenta somar valores localmente. Campos de filtro que o usuário ainda está editando são preservados nessas atualizações. No acompanhamento, novos resultados atualizam apenas a página visível da tabela.

Os GETs de saldo continuam responsáveis pelo recálculo de projeções pendentes. O frontend não recebe uma escolha entre saldo antigo e saldo atual, nem utiliza a flag `staled` para apresentar valores desatualizados.

## Testes e execução no Ubuntu

Atualize os arquivos, preservando `.env` e os volumes existentes:

```bash
bash scripts/bootstrap.sh
bash scripts/check.sh
```

Acesse `http://localhost:5173` e entre com `DEMO_USER_EMAIL` e `DEMO_USER_PASSWORD` do `.env` da raiz. A senha não é exibida pela interface nem fica embutida no bundle. O CSV de referência está em `backend/tests/Fixtures/financial_transactions.csv`.

O check agora inclui:

- 44 testes Vitest/Vue Test Utils de autenticação, transporte, cancelamento, dinheiro, paginação, filtros, upload e polling.
- ESLint, TypeScript, build Vite e checagem de tipos da suíte Cypress.
- Dois cenários Cypress com API, MySQL, Redis, relay e worker reais: importação do arquivo fornecido e reenvios parciais; perda/revogação da sessão.

Para executar apenas o E2E após o bootstrap:

```bash
bash scripts/test-e2e.sh
```

O script usa `compose.e2e.yaml`, um nome de projeto exclusivo por execução, credenciais descartáveis, banco `finance_ledger_e2e`, volumes próprios e nenhuma porta publicada. Não lê o `.env` de desenvolvimento. A preparação destrutiva recusa banco/ambiente/host diferentes dos previstos. Um trap coleta os logs em falhas e remove somente essa stack e seus volumes.

O serviço `balance-projector` não é iniciado no E2E: os saldos devem ser recalculados pelo GET. A suíte importa 15.000 registros, navega pelas páginas, confere o extrato de #682, envia um arquivo parcial com duplicatas, uma operação nova e uma rejeição, e reenvia esse arquivo. Os intercepts Cypress observam as respostas reais; não simulam a API.

A imagem de testes instala Cypress 16.1.0 e as bibliotecas Linux necessárias. O primeiro build precisa de internet e pode levar alguns minutos. O frontend de desenvolvimento define `CYPRESS_INSTALL_BINARY=0`, evitando baixar o navegador a cada `npm ci`; o instalador roda explicitamente na imagem E2E. A suíte usa `cy.env()` conforme a API do Cypress 16. Screenshots ficam em `frontend/artifacts/` e são anexados pelo workflow de CI. Esses arquivos não integram o ZIP de código.

## Evidências e limitações

Passaram nesta implementação: **44 testes frontend**, ESLint, checagem TypeScript, build Vite, tipos do Cypress, sintaxe Bash e validação dos dois arquivos Compose.

Também passou um ensaio integrado adicional dos componentes Vue em **jsdom**, com chamadas HTTP reais à API PHP 8.4, MySQL 8.4 e Redis 8.2, e processos reais de relay/worker. O fluxo completo levou aproximadamente 74 segundos neste ambiente. Foram conferidos os 15.000 registros, o dashboard de R$ 9.410.064,00, páginas de dez, reenvios parciais e revogação real do JWT. Uma leitura SQL verificou #682 com `staled=1` antes da consulta e `staled=0`, versões iguais e R$ 1.092,09 após o GET, sem projector. A operação adicional de R$ 1,00 elevou o saldo global a R$ 9.410.065,00; o reenvio seguinte o preservou.

Esse ensaio não é uma execução do Cypress nem valida renderização visual. O download do binário Cypress/navegador retornou uma página de indisponibilidade nesta infraestrutura, e não há daemon Docker. Por isso a execução da nova stack E2E, as capturas e a revisão visual em navegador ainda precisam ser confirmadas no Ubuntu/CI. Os testes, scripts e configuração estão incluídos, sem marcar essa etapa de validação como aprovada antecipadamente.

O log enviado pelo usuário após a etapa 09 confirmou o bootstrap, smoke, estilo, análise estática, os 556 testes backend/3345 asserções e os quatro testes da antiga tela frontend no Docker do Ubuntu. Esta etapa preserva o backend e as correções de isolamento/recriação de rede; não confunde essa confirmação anterior com uma execução dos novos testes de navegador.

## Próxima etapa

Executar a etapa de escala e desempenho: importação financeira completa de 100 MB, concorrência sustentada, uso de memória, tempo de retomada e consultas com EXPLAIN. O transporte de 100 MB já foi testado em etapas anteriores; o ensaio desta entrega usa o CSV fornecido de 15 mil registros.

## Referências

- [Vue Router: instalação e navegação](https://router.vuejs.org/installation.html)
- [Cypress: requisitos de instalação no Linux](https://docs.cypress.io/app/get-started/install-cypress)
- [Cypress: seleção de arquivos](https://docs.cypress.io/api/commands/selectfile)
- [Cypress 16: variáveis de ambiente nos testes](https://docs.cypress.io/api/commands/env)
