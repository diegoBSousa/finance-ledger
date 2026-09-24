<script setup lang="ts">
defineProps<{ title: string; description: string; eyebrow?: string }>();
</script>
<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">{{ eyebrow ?? "SEU FINANCEIRO" }}</p>
      <h1>{{ title }}</h1>
      <p class="muted">{{ description }}</p>
    </div>
    <div class="header-actions"><slot /></div>
  </header>
</template>
