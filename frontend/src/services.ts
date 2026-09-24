import { inject, shallowReactive, ref, type InjectionKey } from "vue";
import { createAuth } from "./auth";
import { ApiClient } from "./api/client";
import type { CsvImport } from "./api/types";
import { isActive } from "./format";

export function createServices(baseUrl?: string) {
  const auth = createAuth();
  const api = new ApiClient(auth, baseUrl);
  const financialVersion = ref(0);
  const activeImports = shallowReactive(new Map<string, CsvImport>());
  function observeImport(record: CsvImport) {
    const previous = activeImports.get(record.id);
    if (
      previous &&
      (previous.inserted_rows !== record.inserted_rows ||
        previous.status !== record.status)
    )
      financialVersion.value++;
    if (isActive(record.status)) activeImports.set(record.id, record);
    else activeImports.delete(record.id);
  }
  return { auth, api, financialVersion, activeImports, observeImport };
}
export type Services = ReturnType<typeof createServices>;
export const servicesKey: InjectionKey<Services> = Symbol("ledger-services");
export function useServices(): Services {
  const services = inject(servicesKey);
  if (!services) throw new Error("Ledger services not provided");
  return services;
}
