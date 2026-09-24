<script setup lang="ts">
defineProps<{ message: string; retry?: boolean }>();
defineEmits<{ retry: [] }>();
</script>
<template>
  <div class="notice error" role="alert">
    <p>{{ message }}</p>
    <button
      v-if="retry"
      type="button"
      class="secondary"
      @click="$emit('retry')"
    >
      Tentar novamente
    </button>
  </div>
</template>
