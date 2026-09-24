import { defineConfig } from "cypress";

export default defineConfig({
  e2e: {
    baseUrl: "http://frontend:5173",
    specPattern: "e2e/**/*.cy.ts",
    supportFile: false,
    testIsolation: true,
  },
  viewportWidth: 1440,
  viewportHeight: 1000,
  defaultCommandTimeout: 15_000,
  requestTimeout: 20_000,
  responseTimeout: 120_000,
  video: false,
  screenshotOnRunFailure: true,
  screenshotsFolder: "artifacts/screenshots",
  env: {
    apiUrl: "http://api:8080/api/v1",
    demoEmail: "e2e@example.test",
    demoPassword: "e2e-disposable-password-only",
  },
});
