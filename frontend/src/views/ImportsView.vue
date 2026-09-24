<script setup lang="ts">
import { onScopeDispose, ref, shallowRef, watch } from "vue";
import { useServices } from "../services";
import { useResource } from "../composables/useResource";
import { usePageQuery } from "../composables/usePageQuery";
import { usePolling } from "../composables/usePolling";
import { errorMessage } from "../api/client";
import { formatCount, formatDateTime, isActive, validateFile } from "../format";
import PageHeader from "../components/PageHeader.vue";
import ErrorNotice from "../components/ErrorNotice.vue";
import PagePager from "../components/PagePager.vue";
import ImportBadge from "../components/ImportBadge.vue";

const { api, observeImport } = useServices();
const { route, router, page, go } = usePageQuery();
const file = shallowRef<File | null>(null);
const uploadError = ref("");
const sending = ref(false);
const percent = ref(0);
let uploadController: AbortController | undefined;
const { data, error, busy, load, cancel } = useResource(async (signal) => {
  const response = await api.imports({ page: page.value }, signal);
  if (!signal.aborted) response.data.forEach(observeImport);
  return response;
});
usePolling(
  () => (busy.value ? Promise.resolve() : load(false)),
  () => !!data.value?.data.some((record) => isActive(record.status)),
  cancel,
);
watch(
  () => route.query,
  () => {
    void load();
  },
  { immediate: true },
);
onScopeDispose(() => uploadController?.abort());
function choose(event: Event) {
  const input = event.target as HTMLInputElement;
  file.value = input.files?.item(0) ?? null;
  uploadError.value = validateFile(file.value);
}
async function upload() {
  if (sending.value) return;
  uploadError.value = validateFile(file.value);
  if (uploadError.value || !file.value) return;
  sending.value = true;
  percent.value = 0;
  uploadController = new AbortController();
  const signal = uploadController.signal;
  try {
    const response = await api.upload(
      file.value,
      (value) => {
        percent.value = value;
      },
      signal,
    );
    if (signal.aborted) return;
    observeImport(response.data);
    await router.push({ name: "import", params: { id: response.data.id } });
  } catch (cause) {
    if (!signal.aborted) {
      uploadError.value = `${errorMessage(cause)} Se o envio foi interrompido, confira o histórico antes de reenviar. Registros já importados não serão duplicados.`;
      void load();
    }
  } finally {
    sending.value = false;
  }
}
</script>
<template>
  <PageHeader
    title="Importações"
    description="Envie um CSV e acompanhe o resultado de cada registro."
  />
  <section class="panel upload-panel">
    <div>
      <p class="eyebrow">NOVO ARQUIVO</p>
      <h2>Importar movimentações</h2>
      <p class="muted">
        Um arquivo .csv de até 100 MB. Valores inteiros representam centavos de
        real; o número após # na descrição identifica a conta.
      </p>
      <p class="small muted">
        Reenvios completos ou parciais preservam os registros já importados.
      </p>
    </div>
    <form @submit.prevent="upload">
      <label for="csv-file">Arquivo CSV</label
      ><input
        id="csv-file"
        type="file"
        accept=".csv,text/csv"
        :disabled="sending"
        aria-describedby="file-limit"
        @change="choose"
      />
      <p id="file-limit" class="small muted">
        Limite: 100.000.000 bytes. O conteúdo será validado durante a
        importação.
      </p>
      <ErrorNotice v-if="uploadError" :message="uploadError" />
      <div v-if="sending" class="upload-progress" role="status">
        <label for="upload-progress">{{
          percent < 100
            ? `Enviando arquivo · ${percent}%`
            : "Arquivo enviado. Aguardando confirmação…"
        }}</label
        ><progress id="upload-progress" :value="percent" max="100" />
        <p class="small muted">
          O processamento começa após a confirmação do envio.
        </p>
      </div>
      <button type="submit" :disabled="sending || !file">
        {{ sending ? "Enviando…" : "Enviar CSV" }}
      </button>
    </form>
  </section>
  <section class="panel history-panel">
    <div class="panel-heading">
      <h2>Histórico de importações</h2>
      <button class="secondary" :disabled="busy" @click="load()">
        Atualizar
      </button>
    </div>
    <ErrorNotice v-if="error" :message="error" retry @retry="load()" />
    <p v-else-if="!data && busy" role="status" class="loading">
      Carregando as importações…
    </p>
    <template v-else-if="data">
      <div v-if="data.data.length" class="table-scroll">
        <table>
          <caption class="sr-only">
            Arquivos importados
          </caption>
          <thead>
            <tr>
              <th>Arquivo</th>
              <th>Enviado em</th>
              <th>Situação</th>
              <th class="numeric">Processados</th>
              <th><span class="sr-only">Ações</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in data.data" :key="record.id">
              <td class="filename">{{ record.original_name }}</td>
              <td class="nowrap">{{ formatDateTime(record.created_at) }}</td>
              <td><ImportBadge :status="record.status" /></td>
              <td class="numeric">{{ formatCount(record.processed_rows) }}</td>
              <td>
                <RouterLink
                  class="text-link"
                  :to="{ name: 'import', params: { id: record.id } }"
                  :aria-label="`Acompanhar ${record.original_name}, importação ${record.id}`"
                  >Detalhes →</RouterLink
                >
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty">
        <h2>Seu primeiro arquivo começa aqui</h2>
        <p class="muted">As importações enviadas aparecerão nesta lista.</p>
      </div>
      <PagePager :meta="data.meta" :busy="busy" @page="go" />
    </template>
  </section>
</template>
