import { Show, createSignal } from "solid-js";
import type { TraceJson } from "../lib/types";
import { setSelectedTraceId } from "../lib/store";

// Drop a .json (TraceJson) or a previously-exported .html file. Drop a .cptrace
// and we tell the user the daemon needs to read it (we can't decode capnp here).
export function DropZone() {
  const [active, setActive] = createSignal(false);
  const [error, setError] = createSignal<string | null>(null);

  const onDragOver = (e: DragEvent) => {
    if (!e.dataTransfer?.types.includes("Files")) return;
    e.preventDefault();
    setActive(true);
  };
  const onDragLeave = (e: DragEvent) => {
    if (e.relatedTarget) return;
    setActive(false);
  };
  const onDrop = async (e: DragEvent) => {
    e.preventDefault();
    setActive(false);
    setError(null);
    const file = e.dataTransfer?.files?.[0];
    if (!file) return;
    if (file.name.endsWith(".cptrace")) {
      setError("Binary .cptrace files need the daemon. Drop the .cptrace into your trace dir or pass --format html when exporting.");
      setTimeout(() => setError(null), 6000);
      return;
    }
    if (file.name.endsWith(".html")) {
      const url = URL.createObjectURL(file);
      window.open(url, "_blank", "noopener");
      return;
    }
    if (!file.name.endsWith(".json")) {
      setError(`Unsupported file: ${file.name}`);
      setTimeout(() => setError(null), 4000);
      return;
    }
    try {
      const text = await file.text();
      const parsed = JSON.parse(text) as TraceJson;
      window.PERISCOPE_TRACE = { trace: parsed };
      setSelectedTraceId(parsed.id);
      // Re-hydrate from window: simplest is a reload.
      // We avoid that here so the consumer sees the trace immediately —
      // the static-mode resources read window.PERISCOPE_TRACE on next fetch.
    } catch (err) {
      setError(`Could not parse: ${(err as Error).message}`);
      setTimeout(() => setError(null), 4000);
    }
  };

  if (typeof window !== "undefined") {
    window.addEventListener("dragover", onDragOver);
    window.addEventListener("dragleave", onDragLeave);
    window.addEventListener("drop", onDrop);
  }

  return (
    <>
      <Show when={active()}>
        <div class="fixed inset-0 z-50 grid place-items-center bg-ink-950/80 backdrop-blur pointer-events-none">
          <div class="border-2 border-dashed border-accent rounded-2xl px-12 py-10 text-center">
            <div class="text-2xl font-semibold text-ink-100">Drop trace</div>
            <div class="mt-1 text-sm text-ink-300 mono">.json · .html · .cptrace</div>
          </div>
        </div>
      </Show>
      <Show when={error()}>
        <div class="fixed bottom-20 right-4 z-50 panel p-3 max-w-sm text-xs text-danger">
          {error()}
        </div>
      </Show>
    </>
  );
}
