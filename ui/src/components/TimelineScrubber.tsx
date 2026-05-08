import { For, Show, createMemo } from "solid-js";
import { cursorMicros, setCursorMicros, timeline, trace } from "../lib/store";
import { fmtMs } from "../lib/format";

export function TimelineScrubber() {
  const totalMicros = () => trace()?.meta.duration_micros ?? 1;
  const events = () => timeline() ?? [];

  const positions = createMemo(() => {
    const total = Math.max(1, totalMicros());
    return events()
      .filter((e) => e.kind === "event" || (e.kind === "frame_enter" && e.label !== "{main}"))
      .map((e) => ({
        pct: (e.at_micros / total) * 100,
        kind: e.kind,
        label: e.label,
      }));
  });

  let trackEl: HTMLDivElement | undefined;
  let dragging = false;

  const handle = (clientX: number) => {
    if (!trackEl) return;
    const r = trackEl.getBoundingClientRect();
    const pct = Math.max(0, Math.min(1, (clientX - r.left) / r.width));
    setCursorMicros(Math.round(pct * totalMicros()));
  };

  const onMouseDown = (e: MouseEvent) => {
    dragging = true;
    handle(e.clientX);
  };
  const onMouseMove = (e: MouseEvent) => {
    if (!dragging) return;
    handle(e.clientX);
  };
  const onMouseUp = () => {
    dragging = false;
  };

  if (typeof window !== "undefined") {
    window.addEventListener("mousemove", onMouseMove);
    window.addEventListener("mouseup", onMouseUp);
  }

  const eventClass = (label: string): string => {
    if (label === "sql") return "sql";
    if (label === "log") return "log";
    if (label === "cache") return "cache";
    if (label === "http") return "http";
    if (label === "exception") return "exception";
    if (label === "event") return "event";
    if (label === "job") return "job";
    if (label === "redis") return "log";
    if (label === "mail") return "event";
    return "frame";
  };

  return (
    <footer class="fixed inset-x-0 bottom-0 z-30 border-t border-ink-700/70 bg-ink-950/85 backdrop-blur">
      <div class="px-4 py-2.5 flex items-center gap-3">
        <div class="flex items-center gap-2 text-[11.5px] mono text-ink-300">
          <button class="chip" onClick={() => setCursorMicros(0)}>⏮</button>
          <button
            class="chip"
            onClick={() => setCursorMicros(Math.max(0, cursorMicros() - Math.max(1, totalMicros() / 100)))}
          >
            ◀
          </button>
          <button
            class="chip"
            onClick={() => setCursorMicros(Math.min(totalMicros(), cursorMicros() + Math.max(1, totalMicros() / 100)))}
          >
            ▶
          </button>
          <button class="chip" onClick={() => setCursorMicros(totalMicros())}>⏭</button>
          <span class="text-ink-100">{fmtMs(cursorMicros())}</span>
          <span class="text-ink-500">/ {fmtMs(totalMicros())}</span>
        </div>
        <div
          ref={trackEl}
          class="relative flex-1 h-9 timeline-track rounded-md border border-ink-700/70 overflow-hidden cursor-crosshair"
          onMouseDown={onMouseDown}
        >
          <Show when={trace()}>
            <For each={positions()}>
              {(p) => (
                <span
                  class={`timeline-event ${eventClass(p.label)}`}
                  style={{ left: `${p.pct}%` }}
                  title={p.label}
                />
              )}
            </For>
            <span
              class="timeline-cursor"
              style={{ left: `${(cursorMicros() / Math.max(1, totalMicros())) * 100}%` }}
            />
          </Show>
        </div>
        <div class="hidden xl:flex items-center gap-1.5 text-[11px] mono text-ink-400">
          <Legend color="bg-accent" label="sql" />
          <Legend color="bg-success" label="cache" />
          <Legend color="bg-warn" label="http" />
          <Legend color="bg-purple" label="event" />
          <Legend color="bg-pink" label="job" />
          <Legend color="bg-danger" label="exception" />
        </div>
      </div>
    </footer>
  );
}

function Legend(props: { color: string; label: string }) {
  return (
    <span class="inline-flex items-center gap-1">
      <i class={`inline-block w-2 h-2 rounded-sm ${props.color}`} />
      {props.label}
    </span>
  );
}
