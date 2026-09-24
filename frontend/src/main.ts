import { createApp } from "vue";
import App from "./App.vue";
import { createServices, servicesKey } from "./services";
import { createLedgerRouter } from "./router";
import "./style.css";

const services = createServices();
createApp(App)
  .provide(servicesKey, services)
  .use(createLedgerRouter(services.auth))
  .mount("#app");
