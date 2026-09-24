import { describe, expect, it, vi } from "vitest";
import { effectScope, nextTick, ref } from "vue";
import { useResource } from "../src/composables/useResource";
import { usePolling } from "../src/composables/usePolling";
import { deferred } from "./helpers";

describe("request lifecycle", () => {
  it("aborts former requests and prevents an older page from replacing the current one", async () => {
    const first = deferred<string>();
    const second = deferred<string>();
    const loader = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise);
    const scope = effectScope();
    const resource = scope.run(() => useResource(loader))!;
    const old = resource.load();
    const current = resource.load();
    expect(loader.mock.calls[0][0].aborted).toBe(true);
    second.resolve("page 2");
    await current;
    first.resolve("page 1");
    await old;
    expect(resource.data.value).toBe("page 2");
    scope.stop();
  });
  it("removes former financial values on a failed refresh", async () => {
    const loader = vi
      .fn()
      .mockResolvedValueOnce("balance")
      .mockRejectedValueOnce(new Error("network"));
    const scope = effectScope();
    const resource = scope.run(() => useResource(loader))!;
    await resource.load();
    await resource.load();
    expect(resource.data.value).toBeNull();
    expect(resource.error.value).toContain("conectar");
    scope.stop();
  });
});

describe("import polling", () => {
  it("does not cancel an initial non-polling request when visibility changes", () => {
    const cancel = vi.fn();
    const scope = effectScope();
    scope.run(() => usePolling(vi.fn(), () => false, cancel));
    document.dispatchEvent(new Event("visibilitychange"));
    expect(cancel).not.toHaveBeenCalled();
    scope.stop();
  });

  it("never overlaps requests and stops for terminal status", async () => {
    vi.useFakeTimers();
    const active = ref(true);
    const pending = deferred<void>();
    const refresh = vi.fn().mockReturnValue(pending.promise);
    const cancel = vi.fn();
    const scope = effectScope();
    scope.run(() => usePolling(refresh, active, cancel));
    await vi.advanceTimersByTimeAsync(12_000);
    expect(refresh).toHaveBeenCalledOnce();
    active.value = false;
    await nextTick();
    pending.resolve();
    await vi.advanceTimersByTimeAsync(10_000);
    expect(refresh).toHaveBeenCalledOnce();
    scope.stop();
  });
  it("pauses and aborts while hidden, resumes when visible and cleans up on unmount", async () => {
    vi.useFakeTimers();
    const refresh = vi.fn().mockResolvedValue(undefined);
    const cancel = vi.fn();
    const scope = effectScope();
    scope.run(() => usePolling(refresh, () => true, cancel));
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      value: "hidden",
    });
    document.dispatchEvent(new Event("visibilitychange"));
    await vi.advanceTimersByTimeAsync(10_000);
    expect(refresh).not.toHaveBeenCalled();
    expect(cancel).toHaveBeenCalled();
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      value: "visible",
    });
    document.dispatchEvent(new Event("visibilitychange"));
    await nextTick();
    expect(refresh).toHaveBeenCalledOnce();
    scope.stop();
    await vi.advanceTimersByTimeAsync(10_000);
    expect(refresh).toHaveBeenCalledOnce();
  });
});
