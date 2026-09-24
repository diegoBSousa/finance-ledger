<script setup lang="ts">
import { ref, watch } from "vue";
import { useServices } from "../services";
import { useResource } from "../composables/useResource";
import { usePageQuery } from "../composables/usePageQuery";
import { formatMoney } from "../format";
import PageHeader from "../components/PageHeader.vue";
import ErrorNotice from "../components/ErrorNotice.vue";
import PagePager from "../components/PagePager.vue";

const { api, financialVersion } = useServices();
const { route, router, text, page, go } = usePageQuery();
const account = ref(text("account_number"));
const { data, error, busy, load } = useResource((signal) =>
  api.balances(
    { page: page.value, account_number: text("account_number") },
    signal,
  ),
);
watch(
  () => route.query,
  () => {
    account.value = text("account_number");
    void load();
  },
  { immediate: true },
);
watch(financialVersion, () => {
  void load();
});
function filter() {
  void router.push({
    query: account.value ? { account_number: account.value.trim() } : {},
  });
}
</script>
<template>
  <PageHeader
    title="Contas e saldos"
    description="Entradas, saídas e saldo atualizado de cada conta."
    ><button class="secondary" :disabled="busy" @click="load()">
      Atualizar
    </button></PageHeader
  >
  <section class="panel">
    <form class="filter-bar" @submit.prevent="filter">
      <div class="field">
        <label for="account-filter">Número da conta</label
        ><input
          id="account-filter"
          v-model="account"
          inputmode="numeric"
          pattern="[1-9][0-9]*"
          maxlength="20"
          placeholder="Ex.: 682"
        />
      </div>
      <button type="submit">Filtrar</button
      ><button
        type="button"
        class="secondary"
        @click="
          account = '';
          filter();
        "
      >
        Limpar
      </button>
    </form>
    <ErrorNotice v-if="error" :message="error" retry @retry="load()" />
    <p v-else-if="busy" role="status" class="loading">Consultando os saldos…</p>
    <template v-else-if="data">
      <div v-if="data.data.length" class="table-scroll">
        <table>
          <caption class="sr-only">
            Saldos das contas em reais
          </caption>
          <thead>
            <tr>
              <th>Conta</th>
              <th>Situação</th>
              <th class="numeric">Entradas</th>
              <th class="numeric">Saídas</th>
              <th class="numeric">Saldo</th>
              <th><span class="sr-only">Ações</span></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="balance in data.data"
              :key="balance.account_id"
              data-testid="balance-row"
            >
              <td class="account-number">#{{ balance.account_number }}</td>
              <td>
                <span
                  class="badge"
                  :class="balance.active ? 'completed' : ''"
                  >{{ balance.active ? "Ativa" : "Inativa" }}</span
                >
              </td>
              <td class="numeric">
                {{ formatMoney(balance.debit_total_minor) }}
              </td>
              <td class="numeric">
                {{ formatMoney(balance.credit_total_minor) }}
              </td>
              <td
                class="numeric strong"
                :class="{ negative: balance.balance_minor.startsWith('-') }"
              >
                {{ formatMoney(balance.balance_minor) }}
              </td>
              <td>
                <RouterLink
                  class="text-link"
                  :to="{
                    name: 'transactions',
                    query: { account_number: balance.account_number },
                  }"
                  :aria-label="`Ver extrato da conta ${balance.account_number}`"
                  >Extrato →</RouterLink
                >
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <h2>Nenhuma conta encontrada</h2>
        <p class="muted">Confira o número informado ou limpe o filtro.</p>
      </div>
      <PagePager :meta="data.meta" @page="go" />
    </template>
  </section>
</template>
