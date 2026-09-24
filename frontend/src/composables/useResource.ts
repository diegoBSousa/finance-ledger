import { onScopeDispose, ref, shallowRef } from "vue";
import { errorMessage } from "../api/client";

export function useResource<T>(loader: (signal: AbortSignal) => Promise<T>) {
  const data = shallowRef<T | null>(null);
  const error = ref("");
  const busy = ref(false);
  let controller: AbortController | undefined;
  let sequence = 0;
  function cancel() {
    sequence++;
    controller?.abort();
    busy.value = false;
  }
  async function load(clear = true) {
    cancel();
    const current = sequence;
    controller = new AbortController();
    const signal = controller.signal;
    busy.value = true;
    error.value = "";
    if (clear) data.value = null;
    try {
      const result = await loader(signal);
      if (current === sequence && !signal.aborted) data.value = result;
    } catch (cause) {
      if (
        current === sequence &&
        !signal.aborted &&
        !(cause instanceof DOMException && cause.name === "AbortError")
      )
        error.value = errorMessage(cause);
    } finally {
      if (current === sequence) busy.value = false;
    }
  }
  onScopeDispose(cancel);
  return { data, error, busy, load, cancel };
}
