import { computed } from "vue";
import { useRoute, useRouter } from "vue-router";

export function usePageQuery() {
  const route = useRoute();
  const router = useRouter();
  const text = (key: string) =>
    typeof route.query[key] === "string" ? (route.query[key] as string) : "";
  const page = computed(() => {
    const value = Number(text("page") || "1");
    return Number.isSafeInteger(value) && value >= 1 ? value : 1;
  });
  const go = (value: number) =>
    router.push({ query: { ...route.query, page: String(value) } });
  return { route, router, text, page, go };
}
