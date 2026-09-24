<script setup lang="ts">
import { watch } from "vue";
import { useServices } from "../services";
import { useResource } from "../composables/useResource";
import { formatCount, formatMoney } from "../format";
import PageHeader from "../components/PageHeader.vue";
import ErrorNotice from "../components/ErrorNotice.vue";

const { api, financialVersion } = useServices();
const { data, error, busy, load } = useResource(
  async (signal) => (await api.dashboard(signal)).data,
);
watch(
  financialVersion,
  () => {
    void load();
  },
  { immediate: true },
);
</script>
<template>
  <PageHeader
    title="Visão geral"
    description="O seu histórico financeiro, em um só lugar."
  >
    <button class="secondary" :disabled="busy" @click="load()">Atualizar</button
    ><RouterLink class="button" to="/imports"
      >Importar CSV <span aria-hidden="true">↗</span></RouterLink
    >
  </PageHeader>
  <ErrorNotice v-if="error" :message="error" retry @retry="load()" />
  <p v-else-if="busy" role="status" class="loading">Carregando seu resumo…</p>
  <template v-else-if="data">
    <section class="metrics" aria-label="Resumo financeiro">
      <article class="metric balance-metric">
        <p>Saldo total</p>
        <strong data-testid="dashboard-balance">{{
          formatMoney(data.balance_minor)
        }}</strong
        ><span>Receitas menos despesas</span>
      </article>
      <article class="metric">
        <p>
          <span class="metric-icon positive" aria-hidden="true">↙</span>
          Receitas
        </p>
        <strong class="positive">{{ formatMoney(data.income_minor) }}</strong
        ><span>Entradas em todas as contas</span>
      </article>
      <article class="metric">
        <p>
          <span class="metric-icon negative" aria-hidden="true">↗</span>
          Despesas
        </p>
        <strong>{{ formatMoney(data.expense_minor) }}</strong
        ><span>Saídas em todas as contas</span>
      </article>
    </section>
    <section class="panel overview-detail">
      <div>
        <p class="eyebrow">HISTÓRICO CONSOLIDADO</p>
        <h2>{{ formatCount(data.transaction_count) }} operações registradas</h2>
        <p class="muted">
          Os valores consideram todo o histórico das suas contas em reais.
        </p>
      </div>
      <RouterLink class="text-link" to="/transactions"
        >Ver extrato <span aria-hidden="true">→</span></RouterLink
      >
    </section>
    <section class="quick-links" aria-label="Acessos rápidos">
      <RouterLink class="panel quick-link" to="/accounts"
        ><span class="eyebrow">01 / CONTAS</span>
        <h2>Cada saldo, em detalhe <span aria-hidden="true">↗</span></h2>
        <p class="muted">
          Consulte entradas, saídas e o saldo de cada conta.
        </p></RouterLink
      >
      <RouterLink class="panel quick-link" to="/imports"
        ><span class="eyebrow">02 / IMPORTAÇÕES</span>
        <h2>Do arquivo ao extrato <span aria-hidden="true">↗</span></h2>
        <p class="muted">
          Envie seu CSV e acompanhe os registros importados e rejeitados.
        </p></RouterLink
      >
    </section>
  </template>
</template>
