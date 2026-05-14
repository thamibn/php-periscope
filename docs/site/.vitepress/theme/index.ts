import DefaultTheme from "vitepress/theme";
import { enhanceAppWithTabs } from "vitepress-plugin-tabs/client";

// vitepress-plugin-tabs 0.7+ bundles its styles inside the client export —
// no separate `./styles` import needed (and trying to import it errors out
// with "Missing './styles' specifier" against the v0.7+ package exports).

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    enhanceAppWithTabs(app);
  },
};
