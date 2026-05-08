import { defineConfig } from "vite";
import solid from "vite-plugin-solid";
import tailwind from "@tailwindcss/vite";
import { fileURLToPath } from "node:url";

// Where the dev-server proxies `/api` and `/ws`. Override either of:
//   PERISCOPE_DAEMON_BASE=http://127.0.0.1:9001 bun run dev
//   PERISCOPE_DEV_PORT=5174 bun run dev
const daemonBase = (process.env.PERISCOPE_DAEMON_BASE ?? "http://127.0.0.1:9999").replace(/\/$/, "");
const wsBase = daemonBase.replace(/^http/, "ws");
const devPort = Number(process.env.PERISCOPE_DEV_PORT ?? "5173");

export default defineConfig({
  plugins: [tailwind(), solid()],
  resolve: {
    alias: {
      "~": fileURLToPath(new URL("./src", import.meta.url)),
    },
  },
  server: {
    port: devPort,
    proxy: {
      "/api": daemonBase,
      "/ws": { target: wsBase, ws: true },
    },
  },
  build: {
    target: "es2022",
    sourcemap: false,
    cssCodeSplit: false,
    chunkSizeWarningLimit: 200,
  },
});
