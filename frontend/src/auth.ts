import { reactive, readonly } from "vue";
import type { Login, User } from "./api/types";

export interface Credential {
  token: string;
  generation: number;
}

export function createAuth() {
  const state = reactive<{ user: User | null; notice: string }>({
    user: null,
    notice: "",
  });
  let credential: Credential | null = null;
  let generation = 0;
  let timer: ReturnType<typeof setTimeout> | undefined;

  function clear(notice = "", expected?: Credential) {
    if (expected && expected !== credential) return;
    clearTimeout(timer);
    credential = null;
    generation++;
    state.user = null;
    state.notice = notice;
  }

  function establish(login: Login) {
    clear();
    credential = { token: login.access_token, generation };
    state.user = login.user;
    const current = credential;
    timer = setTimeout(() => expire(current), login.expires_in * 1000);
  }

  function expire(expected: Credential) {
    clear("Sua sessão expirou. Entre novamente para continuar.", expected);
  }

  // There is no localStorage, sessionStorage, cookie or URL persistence.
  return {
    state: readonly(state),
    current: () => credential,
    establish,
    clear,
    expire,
  };
}
export type Auth = ReturnType<typeof createAuth>;
