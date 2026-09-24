<script setup lang="ts">
import { reactive, ref, watch } from "vue";
import { useServices } from "../services";
import { useResource } from "../composables/useResource";
import { usePageQuery } from "../composables/usePageQuery";
import { formatDate, formatMoney } from "../format";
import PageHeader from "../components/PageHeader.vue";
import ErrorNotice from "../components/ErrorNotice.vue";
import PagePager from "../components/PagePager.vue";

const { api, financialVersion } = useServices();
const { route, router, text, page, go } = usePageQuery();
const fields = ["account_number", "date_from", "date_to", "type"] as const;
const filters = reactive({
  account_number: "",
  date_from: "",
  date_to: "",
  type: "",
});
const validation = ref("");
const { data, error, busy, load } = useResource((signal) =>
  api.transactions(
    {
      page: page.value,
      ...Object.fromEntries(fields.map((key) => [key, text(key)])),
    },
    signal,
  ),
);
watch(
  () => route.query,
  () => {
    fields.forEach((key) => {
      filters[key] = text(key);
    });
    validation.value = "";
    void load();
  },
  { immediate: true },
);
watch(financialVersion, () => {
  void load();
});
function reset() {
  fields.forEach((key) => {
    filters[key] = "";
  });
  validation.value = "";
  void router.push({ query: {} });
}
function filter() {
  if (
    filters.date_from &&
    filters.date_to &&
    filters.date_from > filters.date_to
  ) {
    validation.value =
      "A data inicial deve ser anterior ou igual à data final.";
    return;
  }
  validation.value = "";
  void router.push({
    query: Object.fromEntries(
      fields
        .filter((key) => filters[key])
        .map((key) => [key, filters[key].trim()]),
    ),
  });
}
</script>
<template>
  <PageHeader
    :title="
      text('account_number')
        ? `Extrato · conta #${text('account_number')}`
        : 'Extrato'
    "
    description="Consulte suas operações por conta, período e tipo."
    ><button class="secondary" :disabled="busy" @click="load()">
      Atualizar
    </button></PageHeader
  >
  <section class="panel">
    <form class="filter-bar" @submit.prevent="filter">
      <div class="field">
        <label for="tx-account">Conta</label
        ><input
          id="tx-account"
          v-model="filters.account_number"
          inputmode="numeric"
          pattern="[1-9][0-9]*"
          maxlength="20"
          placeholder="Todas"
        />
      </div>
      <div class="field">
        <label for="date-from">De</label
        ><input
          id="date-from"
          v-model="filters.date_from"
          type="date"
          min="1000-01-01"
          max="9999-12-31"
        />
      </div>
      <div class="field">
        <label for="date-to">Até</label
        ><input
          id="date-to"
          v-model="filters.date_to"
          type="date"
          min="1000-01-01"
          max="9999-12-31"
        />
      </div>
      <div class="field">
        <label for="tx-type">Tipo</label
        ><select id="tx-type" v-model="filters.type">
          <option value="">Todos</option>
          <option value="income">Receita</option>
          <option value="expense">Despesa</option>
        </select>
      </div>
      <button type="submit">Filtrar</button
      ><button type="button" class="secondary" @click="reset">Limpar</button>
    </form>
    <ErrorNotice v-if="validation" :message="validation" />
    <ErrorNotice v-if="error" :message="error" retry @retry="load()" />
    <p v-else-if="busy" role="status" class="loading">
      Carregando as operações…
    </p>
    <template v-else-if="data">
      <div v-if="data.data.length" class="table-scroll">
        <table>
          <caption class="sr-only">
            Operações do extrato
          </caption>
          <thead>
            <tr>
              <th>Data</th>
              <th>Descrição</th>
              <th>Conta</th>
              <th>Tipo</th>
              <th class="numeric">Valor</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="transaction in data.data"
              :key="transaction.id"
              data-testid="transaction-row"
            >
              <td class="nowrap">
                {{ formatDate(transaction.transaction_date) }}
              </td>
              <td class="description-cell">{{ transaction.description }}</td>
              <td>#{{ transaction.account_number }}</td>
              <td>
                <span class="badge" :class="transaction.type">{{
                  transaction.type === "income" ? "Receita" : "Despesa"
                }}</span>
              </td>
              <td
                class="numeric strong"
                :class="transaction.type === 'income' ? 'positive' : 'negative'"
              >
                {{ transaction.type === "expense" ? "−" : "+"
                }}{{ formatMoney(transaction.amount_minor) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <h2>Nenhuma operação encontrada</h2>
        <p class="muted">Ajuste os filtros ou importe um CSV para começar.</p>
        <RouterLink class="text-link" to="/imports"
          >Ir para importações →</RouterLink
        >
      </div>
      <PagePager :meta="data.meta" @page="go" />
    </template>
  </section>
</template>
