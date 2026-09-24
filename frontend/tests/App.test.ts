import { describe, expect, it, vi } from "vitest";
import { flushPromises } from "@vue/test-utils";
import App from "../src/App.vue";
import { ApiError } from "../src/api/client";
import { imported, login, services, view } from "./helpers";

describe("authenticated navigation", () => {
  it("redirects protected routes to login and returns after successful authentication", async () => {
    const value = services(false);
    vi.spyOn(value.api, "login").mockResolvedValue({ data: login });
    vi.spyOn(value.api, "balances").mockResolvedValue({
      data: [],
      meta: {
        current_page: 1,
        per_page: 10,
        total: 0,
        last_page: 1,
        has_next_page: false,
      },
    });
    const { wrapper, router } = await view(
      App,
      "/accounts?account_number=682",
      value,
    );
    expect(router.currentRoute.value.name).toBe("login");
    await wrapper.get("#email").setValue("demo@example.test");
    await wrapper.get("#password").setValue("password");
    await wrapper.get("form").trigger("submit");
    await flushPromises();
    await vi.dynamicImportSettled();
    await flushPromises();
    expect(value.api.login).toHaveBeenCalledWith(
      "demo@example.test",
      "password",
      expect.any(AbortSignal),
    );
    expect(router.currentRoute.value.fullPath).toBe(
      "/accounts?account_number=682",
    );
    expect(wrapper.text()).toContain("Contas e saldos");
  });
  it("shows invalid credentials and clears the password without creating a session", async () => {
    const value = services(false);
    vi.spyOn(value.api, "login").mockRejectedValue(new ApiError(401));
    const { wrapper } = await view(App, "/login", value);
    await wrapper.get("#email").setValue("demo@example.test");
    await wrapper.get("#password").setValue("bad");
    await wrapper.get("form").trigger("submit");
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain("inválidos");
    expect((wrapper.get("#password").element as HTMLInputElement).value).toBe(
      "",
    );
    expect(value.auth.current()).toBeNull();
  });
  it("clears private content and pending imports when the session expires", async () => {
    const value = services();
    const { wrapper, router } = await view(App, "/dashboard", value);
    value.observeImport(imported);
    value.auth.expire(value.auth.current()!);
    await flushPromises();
    expect(router.currentRoute.value.name).toBe("login");
    expect(wrapper.text()).toContain("Sua sessão expirou");
    expect(wrapper.find('[data-testid="dashboard-balance"]').exists()).toBe(
      false,
    );
    expect(value.activeImports.size).toBe(0);
  });
  it("forgets the token even when server logout is unavailable, with an honest notice", async () => {
    const value = services();
    vi.spyOn(value.api, "logout").mockRejectedValue(new ApiError(503));
    const { wrapper } = await view(App, "/dashboard", value);
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Sair")!
      .trigger("click");
    await flushPromises();
    expect(value.auth.current()).toBeNull();
    expect(wrapper.text()).toContain(
      "Não foi possível confirmar o encerramento",
    );
  });
  it("refreshes visible financial data when an observed import commits new rows", async () => {
    const value = services();
    await view(App, "/dashboard", value);
    expect(value.api.dashboard).toHaveBeenCalledOnce();
    value.observeImport(imported);
    value.observeImport({
      ...imported,
      status: "processing",
      inserted_rows: "10",
      processed_rows: "10",
    });
    await flushPromises();
    expect(value.api.dashboard).toHaveBeenCalledTimes(2);
  });
});
