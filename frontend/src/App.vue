<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { checkApiHealth } from './api/health'

const state = ref<'checking' | 'ready' | 'unavailable'>('checking')

async function checkConnection() {
  state.value = 'checking'
  try {
    await checkApiHealth()
    state.value = 'ready'
  } catch {
    state.value = 'unavailable'
  }
}

onMounted(checkConnection)
</script>

<template>
  <main>
    <p class="eyebrow">ETAPA 01 · DESENVOLVIMENTO</p>
    <h1>Finance Ledger</h1>
    <p class="intro">Base da aplicação financeira.</p>
    <section aria-labelledby="connection-title">
      <h2 id="connection-title">Conexão com a API</h2>
      <p role="status" aria-live="polite" :class="state">
        <template v-if="state === 'checking'">Verificando conexão…</template>
        <template v-else-if="state === 'ready'">Conexão estabelecida.</template>
        <template v-else>Não foi possível conectar à API. Verifique os serviços e tente novamente.</template>
      </p>
      <button type="button" :disabled="state === 'checking'" @click="checkConnection">
        {{ state === 'unavailable' ? 'Tentar novamente' : 'Verificar conexão' }}
      </button>
    </section>
    <p class="note">Ambiente inicial. Autenticação, contas e importação serão adicionadas nas próximas etapas.</p>
  </main>
</template>
