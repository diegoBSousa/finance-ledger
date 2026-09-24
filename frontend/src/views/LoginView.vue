<script setup lang="ts">
import { onScopeDispose, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useServices } from "../services";
import { errorMessage } from "../api/client";
import ErrorNotice from "../components/ErrorNotice.vue";

const { api, auth } = useServices();
const router = useRouter();
const route = useRoute();
const email = ref("");
const password = ref("");
const busy = ref(false);
const error = ref("");
let controller: AbortController | undefined;
onScopeDispose(() => controller?.abort());
async function submit() {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  controller = new AbortController();
  const signal = controller.signal;
  try {
    const response = await api.login(
      email.value.trim(),
      password.value,
      signal,
    );
    if (signal.aborted) return;
    auth.establish(response.data);
    const redirect =
      typeof route.query.redirect === "string" ? route.query.redirect : "";
    const safe =
      redirect.startsWith("/") &&
      !redirect.startsWith("//") &&
      router.resolve(redirect).meta.auth;
    await router.replace(safe ? redirect : "/dashboard");
  } catch (cause) {
    if (!signal.aborted) error.value = errorMessage(cause);
  } finally {
    password.value = "";
    busy.value = false;
  }
}
</script>
<template>
  <main class="login-layout">
    <section class="login-story" aria-label="Finance Ledger">
      <div class="brand">
        <span class="brand-mark" aria-hidden="true">fl.</span
        ><span>Finance Ledger</span>
      </div>
      <div>
        <p class="eyebrow">CLAREZA EM CADA MOVIMENTO</p>
        <h1>Suas contas.<br />Uma visão completa.</h1>
        <p>
          Reúna receitas e despesas, acompanhe seus saldos e encontre cada
          lançamento.
        </p>
      </div>
      <p class="login-footnote">Contas em reais · Importação por CSV</p>
    </section>
    <section class="login-form-panel">
      <form class="login-form" @submit.prevent="submit">
        <p class="eyebrow">BEM-VINDO AO SEU FINANCEIRO</p>
        <h2>Entrar na conta</h2>
        <p class="muted">Use seu e-mail e senha para continuar.</p>
        <div v-if="auth.state.notice" class="notice" role="status">
          {{ auth.state.notice }}
        </div>
        <ErrorNotice v-if="error" :message="error" />
        <label for="email">E-mail</label
        ><input
          id="email"
          v-model="email"
          type="email"
          autocomplete="username"
          required
          maxlength="254"
          :disabled="busy"
          placeholder="voce@exemplo.com"
        />
        <label for="password">Senha</label
        ><input
          id="password"
          v-model="password"
          type="password"
          autocomplete="current-password"
          required
          :disabled="busy"
        />
        <button type="submit" :disabled="busy">
          {{ busy ? "Entrando…" : "Entrar" }}
        </button>
        <p class="muted small">
          Ao recarregar a página, você precisará entrar novamente.
        </p>
      </form>
    </section>
  </main>
</template>
