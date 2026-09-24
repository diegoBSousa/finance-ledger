import { describe, expect, it, vi } from "vitest";
import { flushPromises } from "@vue/test-utils";
import AccountsView from "../src/views/AccountsView.vue";
import TransactionsView from "../src/views/TransactionsView.vue";
import DashboardView from "../src/views/DashboardView.vue";
import ImportsView from "../src/views/ImportsView.vue";
import ImportDetailView from "../src/views/ImportDetailView.vue";
import { ApiError } from "../src/api/client";
import {
  balance,
  dashboard,
  deferred,
  imported,
  paged,
  services,
  transaction,
  view,
} from "./helpers";
import type { Envelope, CsvImport, ImportRow } from "../src/api/types";

async function click(
  wrapper: Awaited<ReturnType<typeof view>>["wrapper"],
  text: string,
) {
  await wrapper
    .findAll("button")
    .find((button) => button.text() === text)!
    .trigger("click");
  await flushPromises();
}

describe("financial screens", () => {
  it("preserves draft filters during background updates and clears unsubmitted values", async () => {
    const value = services();
    vi.spyOn(value.api, "transactions").mockResolvedValue(paged([]));
    const { wrapper } = await view(TransactionsView, "/transactions", value);
    await wrapper.get("#tx-account").setValue("682");
    await wrapper.get("#date-from").setValue("2026-08-01");
    value.financialVersion.value++;
    await flushPromises();
    expect((wrapper.get("#tx-account").element as HTMLInputElement).value).toBe(
      "682",
    );
    expect((wrapper.get("#date-from").element as HTMLInputElement).value).toBe(
      "2026-08-01",
    );
    await click(wrapper, "Limpar");
    expect((wrapper.get("#tx-account").element as HTMLInputElement).value).toBe(
      "",
    );
    expect((wrapper.get("#date-from").element as HTMLInputElement).value).toBe(
      "",
    );
  });

  it("renders large integer totals exactly, then hides them when a refresh fails", async () => {
    const value = services();
    vi.mocked(value.api.dashboard)
      .mockResolvedValueOnce({
        data: { ...dashboard, balance_minor: "9223372036854775807" },
      })
      .mockRejectedValueOnce(new ApiError(503));
    const { wrapper } = await view(DashboardView, "/dashboard", value);
    expect(wrapper.get('[data-testid="dashboard-balance"]').text()).toBe(
      "R$\u00a092.233.720.368.547.758,07",
    );
    await click(wrapper, "Atualizar");
    expect(wrapper.find('[data-testid="dashboard-balance"]').exists()).toBe(
      false,
    );
    expect(wrapper.get('[role="alert"]').text()).toContain("indisponível");
  });
  it("shows ten balances, preserves the filter on pagination and resets it for a new search", async () => {
    const value = services();
    vi.spyOn(value.api, "balances").mockResolvedValue(
      paged(
        Array.from({ length: 10 }, (_, index) => ({
          ...balance,
          account_id: String(index + 1),
        })),
        25,
      ),
    );
    const { wrapper, router } = await view(
      AccountsView,
      "/accounts?account_number=682",
      value,
    );
    expect(wrapper.findAll('[data-testid="balance-row"]')).toHaveLength(10);
    await click(wrapper, "Próxima");
    expect(value.api.balances).toHaveBeenLastCalledWith(
      { page: 2, account_number: "682" },
      expect.any(AbortSignal),
    );
    await wrapper.get("#account-filter").setValue("683");
    await wrapper.get("form").trigger("submit");
    await flushPromises();
    expect(router.currentRoute.value.query.page).toBeUndefined();
    expect(value.api.balances).toHaveBeenLastCalledWith(
      { page: 1, account_number: "683" },
      expect.any(AbortSignal),
    );
  });
  it("keeps a negative balance negative and links its statement to the correct account", async () => {
    const value = services();
    vi.spyOn(value.api, "balances").mockResolvedValue(
      paged([{ ...balance, balance_minor: "-6382", active: false }]),
    );
    const { wrapper } = await view(AccountsView, "/accounts", value);
    expect(wrapper.text()).toContain("−R$\u00a063,82");
    expect(wrapper.text()).toContain("Inativa");
    expect(wrapper.get("a").attributes("href")).toContain("account_number=682");
  });
  it("applies date/type filters and rejects an inverted range before a request", async () => {
    const value = services();
    vi.spyOn(value.api, "transactions").mockResolvedValue(paged([transaction]));
    const { wrapper } = await view(
      TransactionsView,
      "/transactions?account_number=682",
      value,
    );
    expect(wrapper.text()).toContain("16/08/2026");
    expect(wrapper.text()).toContain("−R$\u00a04.946,18");
    await wrapper.get("#date-from").setValue("2026-08-17");
    await wrapper.get("#date-to").setValue("2026-08-16");
    await wrapper.get("form").trigger("submit");
    expect(wrapper.get('[role="alert"]').text()).toContain("data inicial");
    expect(value.api.transactions).toHaveBeenCalledOnce();
    await wrapper.get("#date-from").setValue("2026-08-01");
    await wrapper.get("#tx-type").setValue("expense");
    await wrapper.get("form").trigger("submit");
    await flushPromises();
    expect(value.api.transactions).toHaveBeenLastCalledWith(
      {
        page: 1,
        account_number: "682",
        date_from: "2026-08-01",
        date_to: "2026-08-16",
        type: "expense",
      },
      expect.any(AbortSignal),
    );
  });
  it("shows an empty statement without inventing zero-valued transactions", async () => {
    const value = services();
    vi.spyOn(value.api, "transactions").mockResolvedValue(paged([]));
    const { wrapper } = await view(TransactionsView, "/transactions", value);
    expect(wrapper.text()).toContain("Nenhuma operação");
    expect(wrapper.findAll("tbody tr")).toHaveLength(0);
    expect(
      wrapper
        .findAll("button")
        .find((button) => button.text() === "Próxima")!
        .attributes("disabled"),
    ).toBeDefined();
  });
});

describe("upload and import results", () => {
  async function select(
    wrapper: Awaited<ReturnType<typeof view>>["wrapper"],
    file: File,
  ) {
    Object.defineProperty(wrapper.get("#csv-file").element, "files", {
      configurable: true,
      value: { item: () => file },
    });
    await wrapper.get("#csv-file").trigger("change");
  }
  it("validates size before upload and distinguishes sending from processing", async () => {
    const value = services();
    const pending = deferred<Envelope<CsvImport>>();
    vi.spyOn(value.api, "upload").mockImplementation((_file, progress) => {
      progress(42);
      return pending.promise;
    });
    const { wrapper, router } = await view(ImportsView, "/imports", value);
    const large = new File(["a"], "large.csv");
    Object.defineProperty(large, "size", { value: 100_000_001 });
    await select(wrapper, large);
    await wrapper.get("form").trigger("submit");
    expect(value.api.upload).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain("100.000.000");
    await select(wrapper, new File(["a"], "small.csv"));
    await wrapper.get("form").trigger("submit");
    expect(wrapper.text()).toContain("Enviando arquivo · 42%");
    expect(wrapper.get("progress").attributes("value")).toBe("42");
    pending.resolve({ data: imported });
    await flushPromises();
    await vi.dynamicImportSettled();
    await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe("/imports/41");
    expect(value.activeImports.has("41")).toBe(true);
  });
  it("warns about an uncertain upload result and refreshes the history", async () => {
    const value = services();
    vi.spyOn(value.api, "upload").mockRejectedValue(new TypeError("network"));
    const { wrapper } = await view(ImportsView, "/imports", value);
    await select(wrapper, new File(["a"], "file.csv"));
    await wrapper.get("form").trigger("submit");
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain(
      "confira o histórico antes de reenviar",
    );
    expect(value.api.imports).toHaveBeenCalledTimes(2);
  });
  it("keeps pagination working while the previous page contains an active import", async () => {
    const value = services();
    vi.mocked(value.api.imports)
      .mockResolvedValueOnce(paged([imported], 11))
      .mockResolvedValueOnce(
        paged([{ ...imported, id: "1", status: "completed" }], 11, 2),
      );
    const { wrapper } = await view(ImportsView, "/imports", value);
    await click(wrapper, "Próxima");
    expect(wrapper.text()).toContain("Página 2 de 2");
    expect(wrapper.text()).toContain("Concluída");
    expect(vi.mocked(value.api.imports).mock.calls[1][1]?.aborted).toBe(false);
  });
  it("polls progress, lists rejections by ten and stops after completion", async () => {
    vi.useFakeTimers();
    const value = services();
    vi.spyOn(value.api, "import")
      .mockResolvedValueOnce({ data: imported })
      .mockResolvedValue({
        data: {
          ...imported,
          status: "completed_with_errors",
          processed_rows: "11",
          inserted_rows: "10",
          rejected_rows: "1",
        },
      });
    const rejected: ImportRow = {
      id: "11",
      source_record_number: "12",
      status: "rejected",
      source_row_hash: null,
      journal_entry_id: null,
      error_code: "account_not_found",
      error_message: "Account was not found.",
    };
    vi.spyOn(value.api, "rows").mockResolvedValue(paged([rejected]));
    const { wrapper } = await view(ImportDetailView, "/imports/41", value);
    expect(wrapper.text()).toContain("Na fila");
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();
    expect(wrapper.text()).toContain("Concluída com rejeições");
    expect(wrapper.get('[data-testid="inserted-count"]').text()).toBe("10");
    await wrapper.get("#row-status").setValue("rejected");
    await flushPromises();
    expect(value.api.rows).toHaveBeenLastCalledWith(
      "41",
      { page: 1, status: "rejected" },
      expect.any(AbortSignal),
    );
    expect(wrapper.text()).toContain("account_not_found");
    await vi.advanceTimersByTimeAsync(10_000);
    expect(value.api.import).toHaveBeenCalledTimes(2);
  });
  it("explains partial preservation on failure and never claims a full rollback", async () => {
    const value = services();
    vi.spyOn(value.api, "import").mockResolvedValue({
      data: {
        ...imported,
        status: "failed",
        inserted_rows: "500",
        processed_rows: "500",
        error_code: "invalid_csv",
      },
    });
    vi.spyOn(value.api, "rows").mockResolvedValue(paged([]));
    const { wrapper } = await view(ImportDetailView, "/imports/41", value);
    expect(wrapper.text()).toContain(
      "registros já confirmados foram preservados",
    );
    expect(wrapper.get('[data-testid="inserted-count"]').text()).toBe("500");
  });
});
