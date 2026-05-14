import { defineConfig } from "vitepress";
import { tabsMarkdownPlugin } from "vitepress-plugin-tabs";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

// Version is resolved by scripts/resolve-version.mjs (runs in `prebuild`) and
// written to .vitepress/cache/version.json. That script tries DOCS_VERSION env
// var → GitHub API for latest release → git describe → "main". Reading
// synchronously here keeps the config simple.
const docsVersion = (() => {
  try {
    const versionFile = resolve(
      fileURLToPath(new URL("./cache/version.json", import.meta.url)),
    );
    const data = JSON.parse(readFileSync(versionFile, "utf8"));
    return typeof data.version === "string" && data.version.length > 0
      ? data.version
      : "main";
  } catch {
    return "main";
  }
})();

export default defineConfig({
  markdown: {
    config(md) {
      md.use(tabsMarkdownPlugin);
    },
  },

  lang: "en-US",
  title: "php-periscope",
  description:
    "Live observability + time-travel debugger for Laravel. Scrub any request, see every variable, query, log, job, event, cache hit, and HTTP call — with an AI co-pilot.",
  cleanUrls: true,
  lastUpdated: true,
  appearance: "dark",

  head: [
    ["meta", { name: "theme-color", content: "#22d3ee" }],
    ["meta", { property: "og:title", content: "php-periscope" }],
    [
      "meta",
      {
        property: "og:description",
        content:
          "Live observability + time-travel debugger for Laravel. Xdebug-tier debugging plus Telescope-tier observability, in one live UI.",
      },
    ],
  ],

  themeConfig: {
    siteTitle: "periscope",
    nav: [
      { text: "Guide", link: "/guide/getting-started", activeMatch: "/guide/" },
      { text: "Architecture", link: "/guide/architecture" },
      { text: "FAQ", link: "/guide/faq" },
      { text: "Roadmap", link: "/guide/roadmap" },
      {
        text: docsVersion,
        items: [
          { text: "Changelog", link: "https://github.com/thamibn/php-periscope/releases" },
          { text: "GitHub", link: "https://github.com/thamibn/php-periscope" },
        ],
      },
    ],
    sidebar: {
      "/guide/": [
        {
          text: "Start here",
          collapsed: false,
          items: [
            { text: "Getting started", link: "/guide/getting-started" },
            { text: "Architecture", link: "/guide/architecture" },
          ],
        },
        {
          text: "IDE setup",
          collapsed: false,
          items: [
            { text: "PhpStorm", link: "/guide/phpstorm" },
          ],
        },
        {
          text: "Reference",
          collapsed: false,
          items: [
            { text: "Known limitations", link: "/guide/known-limitations" },
            { text: "FAQ", link: "/guide/faq" },
            { text: "Roadmap", link: "/guide/roadmap" },
            { text: "Contributing", link: "/guide/contributing" },
          ],
        },
      ],
    },
    socialLinks: [
      { icon: "github", link: "https://github.com/thamibn/php-periscope" },
    ],
    search: { provider: "local" },
    editLink: {
      pattern: "https://github.com/thamibn/php-periscope/edit/main/docs/site/:path",
      text: "Edit this page on GitHub",
    },
    footer: {
      message: "Released under a proprietary license.",
      copyright: "© 2026 php-periscope contributors",
    },
    outline: { level: [2, 3] },
  },

  ignoreDeadLinks: "localhostLinks",
});
