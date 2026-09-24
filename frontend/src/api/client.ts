import type { Auth, Credential } from "../auth";
import type {
  Balance,
  CsvImport,
  Dashboard,
  Envelope,
  ImportRow,
  Login,
  Page,
  Query,
  Transaction,
} from "./types";

export class ApiError extends Error {
  constructor(
    public status: number,
    public code?: string,
  ) {
    super("API request failed");
  }
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 401)
      return "E-mail ou senha inválidos, ou sessão expirada. Entre novamente.";
    if (error.status === 404)
      return "Não encontramos este registro para a sua conta.";
    if (error.status === 413)
      return "O arquivo deve ter no máximo 100 MB (100.000.000 bytes).";
    if (error.status === 422)
      return "Confira os campos informados. No upload, selecione um arquivo CSV válido.";
    if (error.status === 429)
      return "Muitas tentativas. Aguarde um minuto e tente novamente.";
    if (error.status >= 500)
      return "O serviço está indisponível. Tente novamente em instantes.";
  }
  return "Não foi possível conectar ao serviço. Confira sua conexão e tente novamente.";
}

const abortError = () => new DOMException("Request cancelled", "AbortError");

export class ApiClient {
  constructor(
    private auth: Auth,
    private base = (
      import.meta.env.VITE_API_BASE_URL ?? "http://localhost:8080/api/v1"
    ).replace(/\/$/, ""),
  ) {}

  private url(path: string, query?: Query) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query ?? {})) {
      if (value !== undefined && value !== "") params.set(key, String(value));
    }
    return `${this.base}${path}${params.size ? `?${params}` : ""}`;
  }

  private verifySession(expected: Credential | null) {
    if (expected && this.auth.current() !== expected) throw abortError();
  }

  private failure(
    status: number,
    body: unknown,
    expected: Credential | null,
  ): never {
    if (status === 401 && expected) this.auth.expire(expected);
    const code =
      body &&
      typeof body === "object" &&
      "code" in body &&
      typeof body.code === "string"
        ? body.code
        : undefined;
    throw new ApiError(status, code);
  }

  private async request<T>(
    path: string,
    options: {
      query?: Query;
      signal?: AbortSignal;
      body?: unknown;
      method?: string;
      public?: boolean;
    } = {},
  ): Promise<T> {
    const credential = options.public ? null : this.auth.current();
    if (!options.public && !credential) throw new ApiError(401);
    const signal = AbortSignal.any([
      AbortSignal.timeout(15_000),
      ...(options.signal ? [options.signal] : []),
    ]);
    const response = await fetch(this.url(path, options.query), {
      method: options.method ?? "GET",
      signal,
      cache: "no-store",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        ...(credential ? { Authorization: `Bearer ${credential.token}` } : {}),
        ...(options.body ? { "Content-Type": "application/json" } : {}),
      },
      body: options.body ? JSON.stringify(options.body) : undefined,
    });
    // A response from a former session can neither render nor expire a new login.
    this.verifySession(credential);
    const body: unknown = await response.json().catch(() => null);
    this.verifySession(credential);
    if (!response.ok) this.failure(response.status, body, credential);
    if (!body || typeof body !== "object" || !("data" in body))
      throw new ApiError(502);
    return body as T;
  }

  login(email: string, password: string, signal?: AbortSignal) {
    return this.request<Envelope<Login>>("/auth/login", {
      method: "POST",
      public: true,
      body: { email, password },
      signal,
    });
  }
  logout() {
    return this.request<Envelope<{ revoked: boolean }>>("/auth/logout", {
      method: "POST",
    });
  }
  dashboard(signal?: AbortSignal) {
    return this.request<Envelope<Dashboard>>("/dashboard", { signal });
  }
  balances(query: Query, signal?: AbortSignal) {
    return this.request<Page<Balance>>("/balances", {
      query: { ...query, per_page: 10 },
      signal,
    });
  }
  transactions(query: Query, signal?: AbortSignal) {
    return this.request<Page<Transaction>>("/transactions", {
      query: { ...query, per_page: 10 },
      signal,
    });
  }
  imports(query: Query, signal?: AbortSignal) {
    return this.request<Page<CsvImport>>("/imports", {
      query: { ...query, per_page: 10 },
      signal,
    });
  }
  import(id: string, signal?: AbortSignal) {
    return this.request<Envelope<CsvImport>>(
      `/imports/${encodeURIComponent(id)}`,
      { signal },
    );
  }
  rows(id: string, query: Query, signal?: AbortSignal) {
    return this.request<Page<ImportRow>>(
      `/imports/${encodeURIComponent(id)}/rows`,
      { query: { ...query, per_page: 10 }, signal },
    );
  }

  upload(
    file: File,
    progress: (percent: number) => void,
    signal: AbortSignal,
  ): Promise<Envelope<CsvImport>> {
    const credential = this.auth.current();
    if (!credential) return Promise.reject(new ApiError(401));
    return new Promise((resolve, reject) => {
      if (signal.aborted) {
        reject(abortError());
        return;
      }
      const xhr = new XMLHttpRequest();
      const abort = () => xhr.abort();
      signal.addEventListener("abort", abort, { once: true });
      const finish = () => signal.removeEventListener("abort", abort);
      xhr.open("POST", this.url("/imports"));
      xhr.timeout = 120_000;
      xhr.setRequestHeader("Accept", "application/json");
      xhr.setRequestHeader("Authorization", `Bearer ${credential.token}`);
      xhr.upload.onprogress = (event) => {
        if (event.lengthComputable && !signal.aborted)
          progress(
            Math.min(100, Math.floor((event.loaded / event.total) * 100)),
          );
      };
      xhr.onerror = xhr.ontimeout = () => {
        finish();
        reject(new ApiError(0));
      };
      xhr.onabort = () => {
        finish();
        reject(abortError());
      };
      xhr.onload = () => {
        finish();
        try {
          if (signal.aborted) throw abortError();
          this.verifySession(credential);
          let body = null;
          try {
            body = JSON.parse(xhr.responseText);
          } catch {
            /* A proxy may return HTML on failure. */
          }
          if (xhr.status < 200 || xhr.status >= 300)
            this.failure(xhr.status, body, credential);
          if (!body?.data?.id) throw new ApiError(502);
          resolve(body);
        } catch (error) {
          reject(error);
        }
      };
      const form = new FormData();
      form.append("file", file);
      // The browser supplies the multipart boundary and streams the File.
      xhr.send(form);
    });
  }
}
