<script setup lang="ts">
import { nextTick, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useServices } from "./services";
import { useImportMonitor } from "./composables/useImportMonitor";

const services = useServices();
const { auth, api } = services;
const monitor = useImportMonitor(services);
const router = useRouter();
const route = useRoute();
const leaving = ref(false);
function focusMain() {
  document.querySelector<HTMLElement>("#main-content")?.focus();
}
watch(
  () => auth.state.user,
  (user) => {
    if (!user && route.meta.auth)
      void router.replace({
        name: "login",
        query: { redirect: route.fullPath },
      });
  },
);
watch(
  () => route.fullPath,
  async () => {
    await nextTick();
    focusMain();
  },
);
async function logout() {
  if (leaving.value) return;
  const current = auth.current();
  if (!current) return;
  leaving.value = true;
  let notice = "";
  try {
    await api.logout();
  } catch {
    notice =
      "Você saiu neste navegador. Não foi possível confirmar o encerramento no servidor; a sessão expira automaticamente.";
  } finally {
    auth.clear(notice, current);
    leaving.value = false;
  }
}
</script>
<template>
  <div v-if="auth.state.user" class="app-layout">
    <a class="skip-link" href="#main-content" @click.prevent="focusMain"
      >Pular para o conteúdo</a
    >
    <aside class="sidebar">
      <RouterLink class="brand" to="/dashboard"
        ><span class="brand-mark" aria-hidden="true">fl.</span
        ><span>Finance<br />Ledger</span></RouterLink
      >
      <p class="nav-label">PRINCIPAL</p>
      <nav class="main-nav" aria-label="Navegação principal">
        <RouterLink to="/dashboard"
          ><span aria-hidden="true">◫</span> Visão geral</RouterLink
        ><RouterLink to="/accounts"
          ><span aria-hidden="true">▤</span> Contas e saldos</RouterLink
        ><RouterLink to="/transactions"
          ><span aria-hidden="true">⇅</span> Extrato</RouterLink
        ><RouterLink
          to="/imports"
          :class="{ 'router-link-active': route.name === 'import' }"
          ><span aria-hidden="true">↥</span> Importações</RouterLink
        >
      </nav>
      <div class="sidebar-footer">
        <span class="currency-dot" aria-hidden="true"></span> Valores em reais
        <strong>BRL</strong>
      </div>
    </aside>
    <div class="workspace">
      <header class="topbar">
        <span class="muted">Seu espaço financeiro</span>
        <div>
          <span class="user-name">{{ auth.state.user.name }}</span
          ><button class="secondary" :disabled="leaving" @click="logout">
            {{ leaving ? "Saindo…" : "Sair" }}
          </button>
        </div>
      </header>
      <main id="main-content" tabindex="-1">
        <div v-if="monitor.error.value" class="notice" role="status">
          A atualização automática das importações está temporariamente
          indisponível. Tentaremos novamente.
        </div>
        <RouterView v-slot="{ Component }"
          ><component :is="Component" :key="route.name"
        /></RouterView>
      </main>
      <footer class="page-footer">
        Finance Ledger <span>Uma visão clara das suas contas.</span>
      </footer>
    </div>
  </div>
  <RouterView v-else />
</template>
