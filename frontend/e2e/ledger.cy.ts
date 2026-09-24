// Every response below comes from the real API. Intercepts only observe requests.
// scripts/test-e2e.sh provisions a separate disposable database and real workers.
type Environment = { demoEmail: string; demoPassword: string; apiUrl: string };
let environment: Environment;
before(() => {
  cy.env<Environment>(["demoEmail", "demoPassword", "apiUrl"], {
    log: false,
  }).then((values) => {
    environment = values;
  });
});
const login = () => {
  cy.visit("/#/login");
  cy.get("#email").type(environment.demoEmail);
  cy.get("#password").type(environment.demoPassword, { log: false });
  cy.contains("button", /^Entrar$/).click();
  cy.contains("h1", "Visão geral").should("be.visible");
};
const nav = (text: string) =>
  cy.get('nav[aria-label="Navegação principal"]').contains("a", text).click();
const brl = (value: string) => `R$\u00a0${value}`;
const upload = (contents: string, name: string) => {
  nav("Importações");
  cy.get("#csv-file").selectFile({
    contents: Cypress.Buffer.from(contents, "utf8"),
    fileName: name,
    mimeType: "text/csv",
  });
  cy.contains("button", /^Enviar CSV$/).click();
  cy.contains("h1", "Acompanhar importação").should("be.visible");
};

describe("financial workflow with real workers and no balance projector", () => {
  it("imports the supplied CSV, recalculates balances on read, paginates and deduplicates partial reuploads", () => {
    login();
    cy.readFile(
      "../backend/tests/Fixtures/financial_transactions.csv",
      "utf8",
    ).then((csv: string) => {
      upload(csv, "financial_transactions.csv");
      // Continue using the app while the global monitor follows the active import.
      nav("Visão geral");
      cy.get('[data-testid="dashboard-balance"]', { timeout: 150_000 }).should(
        "have.text",
        brl("9.410.064,00"),
      );
      cy.contains("15.000 operações registradas").should("be.visible");
      nav("Importações");
      cy.contains("a", "Detalhes").first().click();
      cy.get('[data-testid="import-status"]').should("have.text", "Concluída");
      cy.get('[data-testid="inserted-count"]').should("have.text", "15.000");
      cy.get('[data-testid="import-row"]').should("have.length", 10);
      cy.contains("button", "Próxima").click();
      cy.contains("Página 2 de 1500").should("be.visible");
      cy.get('[data-testid="import-row"]').first().should("contain", "12");

      cy.intercept("GET", "**/api/v1/balances*").as("balances");
      nav("Contas e saldos");
      cy.wait("@balances").its("response.body.meta.per_page").should("eq", 10);
      cy.get('[data-testid="balance-row"]').should("have.length", 10);
      cy.contains("button", "Próxima").click();
      cy.contains("Página 2 de 90").should("be.visible");
      cy.get("#account-filter").type("682");
      cy.contains("button", /^Filtrar$/).click();
      cy.get('[data-testid="balance-row"]')
        .should("have.length", 1)
        .and("contain", brl("1.092,09"));
      cy.get('a[aria-label="Ver extrato da conta 682"]').click();
      cy.get('[data-testid="transaction-row"]').should("have.length", 10);
      cy.screenshot("statement-desktop");

      const lines = csv.trimEnd().split(/\r?\n/);
      const partial =
        [
          lines[0],
          ...lines.slice(1, 4),
          "2026-08-16,Receita adicional #682,100,Receita",
          "2026-08-16,Conta inexistente #999999,100,Receita",
        ].join("\n") + "\n";
      upload(partial, "partial.csv");
      cy.get('[data-testid="import-status"]', { timeout: 60_000 }).should(
        "have.text",
        "Concluída com rejeições",
      );
      cy.get('[data-testid="inserted-count"]').should("have.text", "1");
      cy.get('[data-testid="duplicate-count"]').should("have.text", "3");
      cy.get('[data-testid="rejected-count"]').should("have.text", "1");
      cy.get("#row-status").select("rejected");
      cy.get('[data-testid="import-row"]')
        .should("have.length", 1)
        .and("contain", "Rejeitado");
      upload(partial, "partial-again.csv");
      cy.get('[data-testid="import-status"]', { timeout: 60_000 }).should(
        "have.text",
        "Concluída com rejeições",
      );
      cy.get('[data-testid="inserted-count"]').should("have.text", "0");
      cy.get('[data-testid="duplicate-count"]').should("have.text", "4");
      nav("Visão geral");
      cy.get('[data-testid="dashboard-balance"]').should(
        "have.text",
        brl("9.410.065,00"),
      );
      cy.screenshot("dashboard-desktop");
      nav("Extrato");
      cy.get("#tx-account").type("682");
      cy.contains("button", /^Filtrar$/).click();
      cy.get('[data-testid="transaction-row"]').should("have.length", 10);
      cy.contains("button", "Próxima").click();
      cy.get('[data-testid="transaction-row"]').should("have.length", 1);
      cy.contains("Página 2 de 2").should("be.visible");
      cy.viewport(390, 844);
      nav("Visão geral");
      cy.get('[data-testid="dashboard-balance"]').should("be.visible");
      cy.document().then((document) => {
        expect(document.documentElement.scrollWidth).to.be.at.most(390);
      });
      cy.screenshot("dashboard-mobile");
    });
  });

  it("requires login after reload and removes private content after server revocation", () => {
    login();
    cy.reload();
    cy.contains("h2", "Entrar na conta").should("be.visible");
    cy.get('[data-testid="dashboard-balance"]').should("not.exist");
    cy.intercept("GET", "**/api/v1/dashboard").as("dashboard");
    cy.get("#email").type(environment.demoEmail);
    cy.get("#password").type(environment.demoPassword, { log: false });
    cy.contains("button", /^Entrar$/).click();
    cy.wait("@dashboard").then(({ request }) => {
      cy.request({
        method: "POST",
        url: `${environment.apiUrl}/auth/logout`,
        headers: { Authorization: String(request.headers.authorization) },
        log: false,
      });
    });
    cy.contains("button", /^Atualizar$/).click();
    cy.contains("Sua sessão expirou").should("be.visible");
    cy.get('[data-testid="dashboard-balance"]').should("not.exist");
    cy.window().then((window) => {
      expect(window.localStorage.length).to.eq(0);
      expect(window.sessionStorage.length).to.eq(0);
    });
  });
});
