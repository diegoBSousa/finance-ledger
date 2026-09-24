<script setup lang="ts">
import type { PageMeta } from "../api/types";
import { formatCount } from "../format";
defineProps<{ meta: PageMeta; busy?: boolean }>();
defineEmits<{ page: [value: number] }>();
</script>
<template>
  <nav class="pager" aria-label="Paginação">
    <p class="muted">
      {{ formatCount(meta.total) }} registros · até 10 por página
    </p>
    <div>
      <button
        class="secondary"
        :disabled="busy || meta.current_page <= 1"
        @click="$emit('page', meta.current_page - 1)"
      >
        Anterior
      </button>
      <span aria-live="polite"
        >Página {{ meta.current_page }} de
        {{ Math.max(meta.last_page, 1) }}</span
      >
      <button
        class="secondary"
        :disabled="busy || !meta.has_next_page"
        @click="$emit('page', meta.current_page + 1)"
      >
        Próxima
      </button>
    </div>
  </nav>
</template>
