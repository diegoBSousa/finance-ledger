import { afterEach, beforeEach, vi } from "vitest";
import { enableAutoUnmount, mount, flushPromises } from "@vue/test-utils";
import { createMemoryHistory } from "vue-router";
import type { Component } from "vue";
import { createServices, servicesKey, type Services } from "../src/services";
import { createLedgerRouter } from "../src/router";
import type {
  Balance,
  CsvImport,
  Login,
  Page,
  Transaction,
} from "../src/api/types";

enableAutoUnmount(afterEach);
const sessions: Services[] = [];
beforeEach(() => {
  vi.stubGlobal("scrollTo", vi.fn());
  Object.defineProperty(document, "visibilityState", {
    configurable: true,
    value: "visible",
  });
});
afterEach(() => {
  sessions.forEach((services) => services.auth.clear());
  sessions.length = 0;
  vi.unstubAllGlobals();
  vi.useRealTimers();
});
export const login: Login = {
  access_token: "test-token",
  token_type: "Bearer",
  expires_in: 900,
  user: { id: "1", name: "Demo Ledger", email: "demo@example.test" },
};
export const dashboard = {
  currency: "BRL" as const,
  income_minor: "501000",
  expense_minor: "494618",
  balance_minor: "6382",
  transaction_count: "12",
  financial_revision: "24",
};
export const imported: CsvImport = {
  id: "41",
  original_name: "movimentos.csv",
  file_size_bytes: "1200",
  status: "pending",
  processed_rows: "0",
  inserted_rows: "0",
  duplicate_rows: "0",
  rejected_rows: "0",
  created_at: "2026-08-16T12:00:00Z",
  prepared_at: null,
  started_at: null,
  finished_at: null,
  error_code: null,
  status_url: "/api/v1/imports/41",
};
export const balance: Balance = {
  account_id: "1",
  account_number: "682",
  active: true,
  currency: "BRL",
  debit_total_minor: "501000",
  credit_total_minor: "494618",
  balance_minor: "6382",
  ledger_version: "2",
  calculated_version: "2",
  calculated_at: "2026-08-16T12:00:00Z",
};
export const transaction: Transaction = {
  id: "1",
  account_number: "682",
  transaction_date: "2026-08-16",
  description: "Serviços de Limpeza #682",
  type: "expense",
  amount_minor: "494618",
  currency: "BRL",
  created_at: "2026-08-16T12:00:00Z",
};
export function paged<T>(data: T[], total = data.length, current = 1): Page<T> {
  return {
    data,
    meta: {
      current_page: current,
      per_page: 10,
      total,
      last_page: Math.max(1, Math.ceil(total / 10)),
      has_next_page: current * 10 < total,
    },
  };
}
export function services(authenticated = true) {
  const value = createServices("http://api.test/api/v1");
  sessions.push(value);
  if (authenticated) value.auth.establish(login);
  vi.spyOn(value.api, "imports").mockResolvedValue(paged([]));
  vi.spyOn(value.api, "dashboard").mockResolvedValue({ data: dashboard });
  return value;
}
export async function view(
  component: Component,
  path: string,
  value = services(),
) {
  const router = createLedgerRouter(value.auth, createMemoryHistory());
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, {
    global: { plugins: [router], provide: { [servicesKey as symbol]: value } },
  });
  await flushPromises();
  return { wrapper, router, services: value };
}
export function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (cause: unknown) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
}
