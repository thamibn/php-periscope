import { createResource } from "solid-js";
import { daemonBase, isStaticMode } from "./api";

interface Health {
  status: string;
  version: string;
  trace_dir: string;
}

export const [health] = createResource<Health | null>(async () => {
  if (isStaticMode()) return null;
  try {
    // Always go through the same base the rest of the API client uses,
    // otherwise the request hits the host page (e.g. app.test) instead of
    // the daemon when the UI is mounted inside a Laravel app.
    const res = await fetch(`${daemonBase()}/api/health`);
    if (!res.ok) return null;
    return (await res.json()) as Health;
  } catch {
    return null;
  }
});
