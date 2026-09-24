import {
  createRouter,
  createWebHashHistory,
  type RouterHistory,
} from "vue-router";
import type { Auth } from "./auth";
import LoginView from "./views/LoginView.vue";

export function createLedgerRouter(
  auth: Auth,
  history: RouterHistory = createWebHashHistory(),
) {
  const router = createRouter({
    history,
    routes: [
      { path: "/login", name: "login", component: LoginView },
      { path: "/", redirect: "/dashboard" },
      {
        path: "/dashboard",
        name: "dashboard",
        component: () => import("./views/DashboardView.vue"),
        meta: { auth: true },
      },
      {
        path: "/accounts",
        name: "accounts",
        component: () => import("./views/AccountsView.vue"),
        meta: { auth: true },
      },
      {
        path: "/transactions",
        name: "transactions",
        component: () => import("./views/TransactionsView.vue"),
        meta: { auth: true },
      },
      {
        path: "/imports",
        name: "imports",
        component: () => import("./views/ImportsView.vue"),
        meta: { auth: true },
      },
      {
        path: "/imports/:id(\\d+)",
        name: "import",
        component: () => import("./views/ImportDetailView.vue"),
        meta: { auth: true },
      },
      { path: "/:pathMatch(.*)*", redirect: "/" },
    ],
    scrollBehavior: () => ({ top: 0 }),
  });
  router.beforeEach((to) => {
    if (to.meta.auth && !auth.current())
      return { name: "login", query: { redirect: to.fullPath } };
    if (to.name === "login" && auth.current()) return { name: "dashboard" };
  });
  return router;
}
