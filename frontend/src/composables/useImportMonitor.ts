import { watch } from "vue";
import type { Services } from "../services";
import { ApiError } from "../api/client";
import { useResource } from "./useResource";
import { usePolling } from "./usePolling";

export function useImportMonitor(services: Services) {
  const { api, auth, activeImports, observeImport } = services;
  const monitor = useResource(async (signal) => {
    // Rotate in batches of ten, including imports observed on older list pages.
    const batch = [...activeImports.keys()].slice(0, 10);
    for (const id of batch) {
      try {
        const result = await api.import(id, signal);
        if (signal.aborted) break;
        observeImport(result.data);
        if (activeImports.has(id)) {
          activeImports.delete(id);
          activeImports.set(id, result.data);
        }
      } catch (error) {
        if (error instanceof ApiError && error.status === 404)
          activeImports.delete(id);
        else throw error;
      }
    }
  });
  const seed = useResource(async (signal) => {
    const result = await api.imports({ page: 1 }, signal);
    if (!signal.aborted) result.data.forEach(observeImport);
  });
  watch(
    () => auth.state.user,
    (user) => {
      monitor.cancel();
      seed.cancel();
      activeImports.clear();
      monitor.error.value = "";
      if (user) void seed.load();
    },
    { immediate: true },
  );
  usePolling(
    () => monitor.load(false),
    () => !!auth.state.user && activeImports.size > 0,
    monitor.cancel,
  );
  return monitor;
}
