import { createResource } from "solid-js";
import { isStaticMode } from "./api";

interface Health {
  status: string;
  version: string;
  trace_dir: string;
}

export const [health] = createResource<Health | null>(async () => {
  if (isStaticMode()) return null;
  try {
    const res = await fetch("/api/health");
    if (!res.ok) return null;
    return (await res.json()) as Health;
  } catch {
    return null;
  }
});
