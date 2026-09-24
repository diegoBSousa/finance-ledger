import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiClient, ApiError, errorMessage } from "../src/api/client";
import { createAuth } from "../src/auth";
import { deferred, login } from "./helpers";

const cleanups: (() => void)[] = [];
afterEach(() => {
  cleanups.splice(0).forEach((clear) => clear());
});
function client() {
  const auth = createAuth();
  auth.establish(login);
  cleanups.push(() => auth.clear());
  return { auth, api: new ApiClient(auth, "http://api.test/api/v1") };
}
const response = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });

describe("memory session and authenticated transport", () => {
  it("expires the session and writes no browser storage", async () => {
    vi.useFakeTimers();
    const write = vi.spyOn(Storage.prototype, "setItem");
    const { auth } = client();
    await vi.advanceTimersByTimeAsync(900_000);
    expect(auth.current()).toBeNull();
    expect(auth.state.user).toBeNull();
    expect(auth.state.notice).toContain("expirou");
    expect(write).not.toHaveBeenCalled();
  });
  it("sets Bearer authentication, excludes cookies and clamps the requested page size", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(response({ data: [], meta: {} }));
    vi.stubGlobal("fetch", fetchMock);
    const { api } = client();
    await api.balances({ page: 2, per_page: 100, account_number: "682" });
    const [url, options] = fetchMock.mock.calls[0];
    expect(url).toContain("page=2");
    expect(url).toContain("per_page=10");
    expect(options.headers.Authorization).toBe("Bearer test-token");
    expect(options.credentials).toBe("omit");
    expect(options.cache).toBe("no-store");
  });
  it("expires the current session on a protected 401", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(response({ code: "invalid_token" }, 401)),
    );
    const { auth, api } = client();
    await expect(api.dashboard()).rejects.toMatchObject({ status: 401 });
    expect(auth.current()).toBeNull();
  });
  it.each([200, 401])(
    "discards a late %s response from a previous login",
    async (status) => {
      const pending = deferred<Response>();
      vi.stubGlobal("fetch", vi.fn().mockReturnValue(pending.promise));
      const { auth, api } = client();
      const result = api.dashboard();
      auth.establish({ ...login, access_token: "new-token" });
      pending.resolve(response({ data: { private: "former user" } }, status));
      await expect(result).rejects.toMatchObject({ name: "AbortError" });
      expect(auth.current()?.token).toBe("new-token");
    },
  );
  it("does not attach a former token to login or accept malformed success payloads", async () => {
    const fetchMock = vi.fn().mockResolvedValue(response({ status: "ok" }));
    vi.stubGlobal("fetch", fetchMock);
    const { api } = client();
    await expect(
      api.login("demo@example.test", "secret"),
    ).rejects.toMatchObject({ status: 502 });
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBeUndefined();
  });
  it.each([413, 422, 429, 503])(
    "reports HTTP %s without exposing server exception text",
    (status) => {
      const cause = new ApiError(status, "internal_sensitive_details");
      expect(errorMessage(cause)).not.toContain("internal_sensitive_details");
      expect(errorMessage(cause)).not.toContain("conectar");
    },
  );
});

describe("multipart upload transport", () => {
  class FakeXHR {
    static latest: FakeXHR;
    headers: Record<string, string> = {};
    upload = {
      onprogress: null as
        | null
        | ((event: {
            lengthComputable: boolean;
            loaded: number;
            total: number;
          }) => void),
    };
    onload = () => {};
    onerror = () => {};
    ontimeout = () => {};
    onabort = () => {};
    status = 202;
    responseText = JSON.stringify({ data: { id: "41" } });
    timeout = 0;
    send = vi.fn();
    open = vi.fn();
    abort = vi.fn(() => this.onabort());
    constructor() {
      FakeXHR.latest = this;
    }
    setRequestHeader(name: string, value: string) {
      this.headers[name] = value;
    }
  }
  it("sends one File with a browser-owned boundary and reports upload progress", async () => {
    vi.stubGlobal("XMLHttpRequest", FakeXHR);
    const { api } = client();
    const progress = vi.fn();
    const file = new File(["csv"], "file.csv");
    const promise = api.upload(file, progress, new AbortController().signal);
    const xhr = FakeXHR.latest;
    expect(xhr.headers["Content-Type"]).toBeUndefined();
    expect(xhr.headers.Authorization).toBe("Bearer test-token");
    expect([...xhr.send.mock.calls[0][0].keys()]).toEqual(["file"]);
    xhr.upload.onprogress?.({ lengthComputable: true, loaded: 5, total: 10 });
    expect(progress).toHaveBeenCalledWith(50);
    xhr.onload();
    await expect(promise).resolves.toMatchObject({ data: { id: "41" } });
  });
  it("expires the session even when a proxy returns a non-JSON 401", async () => {
    vi.stubGlobal("XMLHttpRequest", FakeXHR);
    const { auth, api } = client();
    const promise = api.upload(
      new File(["csv"], "file.csv"),
      vi.fn(),
      new AbortController().signal,
    );
    FakeXHR.latest.status = 401;
    FakeXHR.latest.responseText = "<html>Unauthorized</html>";
    FakeXHR.latest.onload();
    await expect(promise).rejects.toMatchObject({ status: 401 });
    expect(auth.current()).toBeNull();
  });
  it("aborts an upload when the owning view is disposed", async () => {
    vi.stubGlobal("XMLHttpRequest", FakeXHR);
    const { api } = client();
    const controller = new AbortController();
    const promise = api.upload(
      new File(["csv"], "file.csv"),
      vi.fn(),
      controller.signal,
    );
    controller.abort();
    await expect(promise).rejects.toMatchObject({ name: "AbortError" });
    expect(FakeXHR.latest.abort).toHaveBeenCalledOnce();
  });
});
