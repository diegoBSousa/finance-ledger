<script setup lang="ts">
import { computed, watch } from "vue";
import { useServices } from "../services";
import { useResource } from "../composables/useResource";
import { usePageQuery } from "../composables/usePageQuery";
import { usePolling } from "../composables/usePolling";
import { formatCount, formatDateTime, isActive, rowLabel } from "../format";
import PageHeader from "../components/PageHeader.vue";
import ErrorNotice from "../components/ErrorNotice.vue";
import PagePager from "../components/PagePager.vue";
import ImportBadge from "../components/ImportBadge.vue";

const { api, observeImport } = useServices();
const { route, router, page, text, go } = usePageQuery();
const id = computed(() => String(route.params.id));
const summary = useResource(async (signal) => {
  const response = await api.import(id.value, signal);
  if (!signal.aborted) observeImport(response.data);
  return response.data;
});
const rows = useResource((signal) =>
  api.rows(id.value, { page: page.value, status: text("status") }, signal),
);
const record = summary.data;
const rowPage = rows.data;
usePolling(
  () => (summary.busy.value ? Promise.resolve() : summary.load(false)),
  () => !!record.value && isActive(record.value.status),
  summary.cancel,
);
watch(
  id,
  () => {
    void summary.load();
  },
  { immediate: true },
);
watch(
  [
    () => route.fullPath,
    () => record.value?.processed_rows,
    () => record.value?.status,
  ],
  () => {
    void rows.load();
  },
  { immediate: true },
);
function filter(event: Event) {
  void router.push({
    query: { status: (event.target as HTMLSelectElement).value },
  });
}
</script>
<template>
  <PageHeader
    title="Acompanhar importação"
    :description="
      record?.original_name ?? 'Resultado e andamento do arquivo enviado.'
    "
    eyebrow="IMPORTAÇÕES"
    ><RouterLink class="button secondary" to="/imports"
      >Voltar às importações</RouterLink
    ></PageHeader
  >
  <ErrorNotice
    v-if="summary.error.value"
    :message="summary.error.value"
    retry
    @retry="summary.load(false)"
  />
  <p v-if="!record && summary.busy.value" role="status" class="loading">
    Carregando a importação…
  </p>
  <template v-if="record">
    <section class="panel import-summary">
      <div class="panel-heading">
        <div>
          <p class="eyebrow">IMPORTAÇÃO #{{ record.id }}</p>
          <ImportBadge :status="record.status" />
        </div>
        <p class="small muted">
          Enviado em {{ formatDateTime(record.created_at) }}
        </p>
      </div>
      <p v-if="isActive(record.status)" role="status" class="notice">
        {{
          record.status === "pending"
            ? "Arquivo recebido. Aguardando o início do processamento."
            : "Processamento em andamento. Os registros confirmados já estão disponíveis no extrato."
        }}
        Você pode navegar para outras telas.
      </p>
      <p
        v-else-if="record.status === 'failed'"
        role="alert"
        class="notice error"
      >
        A importação foi interrompida. Os registros já confirmados foram
        preservados. Confira o arquivo e envie novamente; registros repetidos
        serão ignorados.<span v-if="record.error_code" class="small">
          Referência: {{ record.error_code }}.</span
        >
      </p>
      <p v-else-if="record.status === 'completed_with_errors'" class="notice">
        Processamento concluído com rejeições. Consulte os registros rejeitados
        abaixo, corrija-os e envie um CSV com os dados corrigidos.
      </p>
      <p v-else class="notice success">
        Processamento concluído. Seus saldos e extratos estão disponíveis.
      </p>
      <dl class="import-counts">
        <div>
          <dt>Processados</dt>
          <dd data-testid="processed-count">
            {{ formatCount(record.processed_rows) }}
          </dd>
        </div>
        <div>
          <dt>Importados</dt>
          <dd class="positive" data-testid="inserted-count">
            {{ formatCount(record.inserted_rows) }}
          </dd>
        </div>
        <div>
          <dt>Duplicados</dt>
          <dd data-testid="duplicate-count">
            {{ formatCount(record.duplicate_rows) }}
          </dd>
        </div>
        <div>
          <dt>Rejeitados</dt>
          <dd
            :class="{ negative: record.rejected_rows !== '0' }"
            data-testid="rejected-count"
          >
            {{ formatCount(record.rejected_rows) }}
          </dd>
        </div>
      </dl>
      <div class="summary-links">
        <RouterLink class="text-link" to="/accounts"
          >Ver contas e saldos →</RouterLink
        ><RouterLink class="text-link" to="/transactions"
          >Ver extrato →</RouterLink
        ><button
          class="secondary"
          :disabled="summary.busy.value"
          @click="
            summary.load(false);
            rows.load();
          "
        >
          Atualizar
        </button>
      </div>
    </section>
    <section class="panel history-panel">
      <div class="panel-heading">
        <div>
          <h2>Resultado por registro</h2>
          <p class="small muted">
            A numeração inclui o cabeçalho. Em campos com quebras de linha,
            contamos o registro completo.
          </p>
        </div>
        <div class="field">
          <label for="row-status">Situação</label
          ><select id="row-status" :value="text('status')" @change="filter">
            <option value="">Todos</option>
            <option value="inserted">Importados</option>
            <option value="duplicate">Duplicados</option>
            <option value="rejected">Rejeitados</option>
          </select>
        </div>
      </div>
      <ErrorNotice
        v-if="rows.error.value"
        :message="rows.error.value"
        retry
        @retry="rows.load()"
      />
      <p v-else-if="rows.busy.value" role="status" class="loading">
        Carregando os registros…
      </p>
      <template v-else-if="rowPage">
        <div v-if="rowPage.data.length" class="table-scroll">
          <table>
            <caption class="sr-only">
              Resultados dos registros do CSV
            </caption>
            <thead>
              <tr>
                <th>Registro</th>
                <th>Situação</th>
                <th>Detalhe</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in rowPage.data"
                :key="row.id"
                data-testid="import-row"
              >
                <td>{{ formatCount(row.source_record_number) }}</td>
                <td>
                  <span class="badge" :class="row.status">{{
                    rowLabel[row.status]
                  }}</span>
                </td>
                <td class="description-cell">
                  {{
                    row.status === "inserted"
                      ? "Operação registrada."
                      : row.status === "duplicate"
                        ? "Este registro já foi importado."
                        : "Registro não importado. Confira conta, data, tipo e valor."
                  }}<span v-if="row.error_code" class="small muted">
                    ({{ row.error_code }})</span
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="empty">
          <h2>Nenhum registro para exibir</h2>
          <p class="muted">
            {{
              isActive(record.status)
                ? "Os resultados aparecerão após a confirmação de cada lote."
                : "Não há registros com o filtro selecionado."
            }}
          </p>
        </div>
        <PagePager :meta="rowPage.meta" @page="go" />
      </template>
    </section>
  </template>
</template>
