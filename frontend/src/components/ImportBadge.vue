<script setup lang="ts">
import type { ImportStatus } from "../api/types";
import { importLabel } from "../format";
defineProps<{ status: ImportStatus }>();
</script>
<template>
  <span class="badge" :class="status" data-testid="import-status">{{
    importLabel[status]
  }}</span>
</template>
