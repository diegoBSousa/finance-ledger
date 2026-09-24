import { onScopeDispose, watch, type WatchSource } from "vue";

// One request at a time; no polling while hidden, unmounted or terminal.
export function usePolling(
  refresh: () => Promise<unknown>,
  enabled: WatchSource<boolean>,
  cancel: () => void,
  interval = 2500,
) {
  let active = false;
  let disposed = false;
  let generation = 0;
  let timer: ReturnType<typeof setTimeout> | undefined;
  function stop(abort = true) {
    generation++;
    clearTimeout(timer);
    if (abort) cancel();
  }
  function schedule() {
    clearTimeout(timer);
    if (active && !disposed && document.visibilityState !== "hidden")
      timer = setTimeout(tick, interval);
  }
  async function tick() {
    const current = generation;
    if (!active || disposed || document.visibilityState === "hidden") return;
    await refresh();
    if (current === generation) schedule();
  }
  const unwatch = watch(
    enabled,
    (value) => {
      active = value;
      if (value) schedule();
      else stop(false);
    },
    { immediate: true },
  );
  function visibility() {
    if (!active) return;
    stop();
    if (document.visibilityState !== "hidden" && active) void tick();
  }
  document.addEventListener("visibilitychange", visibility);
  onScopeDispose(() => {
    disposed = true;
    stop();
    unwatch();
    document.removeEventListener("visibilitychange", visibility);
  });
}
