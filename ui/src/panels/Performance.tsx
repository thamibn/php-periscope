import { For, Show, createMemo } from "solid-js";
import { trace } from "../lib/store";
import { fmtMs } from "../lib/format";
import type { FrameJson } from "../lib/types";

// Frame-level flame graph derived from the existing Phase 2 enter/exit timings.
// One <div> per frame, sized + positioned by enter_micros / duration_micros.
// Plan §9b polish item 3: ships in v1, opcode-sampling profiler is v1.1.
export function Performance() {
  const total = () => Math.max(1, trace()?.meta.duration_micros ?? 1);
  const frames = () => trace()?.frames ?? [];

  const maxDepth = createMemo(() => frames().reduce((m, f) => Math.max(m, f.depth), 0));
  const rowHeight = 18;

  // Hottest functions (by cumulative duration). Cheap, fast, useful.
  const top = createMemo(() => {
    const acc = new Map<string, { fn: string; total: number; count: number; max: number }>();
    for (const f of frames()) {
      const key = f.function;
      const cur = acc.get(key) ?? { fn: key, total: 0, count: 0, max: 0 };
      cur.total += f.duration_micros;
      cur.count += 1;
      cur.max = Math.max(cur.max, f.duration_micros);
      acc.set(key, cur);
    }
    return [...acc.values()].sort((a, b) => b.total - a.total).slice(0, 15);
  });

  const colorFor = (f: FrameJson): string => {
    // Hue ramp by call count to make sibling cells visually distinct.
    const hue = (f.id * 47) % 360;
    return `hsl(${hue} 60% 38% / 0.85)`;
  };

  return (
    <div class="space-y-4">
      <article class="panel">
        <div class="panel-header">
          <span>Flame graph (frame-level)</span>
          <span class="mono normal-case text-ink-400">{frames().length} frames · {fmtMs(total())}</span>
        </div>
        <div class="p-3 overflow-x-auto">
          <Show when={frames().length > 0} fallback={<div class="text-sm text-ink-400 text-center py-6">No frames recorded.</div>}>
            <div class="relative" style={{ height: `${(maxDepth() + 1) * rowHeight}px`, "min-width": "100%" }}>
              <For each={frames()}>
                {(f) => {
                  const left = (f.enter_micros / total()) * 100;
                  const width = Math.max(0.05, (f.duration_micros / total()) * 100);
                  return (
                    <div
                      class="absolute rounded-sm overflow-hidden text-[10px] mono text-ink-100/95 px-1 leading-[18px] whitespace-nowrap"
                      style={{
                        left: `${left}%`,
                        width: `${width}%`,
                        top: `${f.depth * rowHeight}px`,
                        height: `${rowHeight - 1}px`,
                        background: colorFor(f),
                        "box-shadow": "inset 0 -1px 0 rgba(0,0,0,0.35)",
                      }}
                      title={`${f.function}\n${f.file}:${f.line}\n${fmtMs(f.duration_micros)}`}
                    >
                      {f.function}
                    </div>
                  );
                }}
              </For>
            </div>
          </Show>
        </div>
      </article>

      <article class="panel">
        <div class="panel-header">
          <span>Top functions</span>
          <span class="mono normal-case text-ink-400">by total time</span>
        </div>
        <ul class="divide-y divide-ink-700/60 text-[12.5px] mono">
          <For each={top()}>
            {(t) => {
              const pct = (t.total / total()) * 100;
              return (
                <li class="grid grid-cols-[1fr_5rem_4rem_5rem] items-center gap-3 px-3 py-1.5 row-hover">
                  <span class="text-ink-100 truncate" title={t.fn}>
                    <span class="inline-block align-middle h-1.5 rounded-sm mr-2 bg-accent" style={{ width: `${Math.min(40, pct * 2)}%` }} />
                    {t.fn}
                  </span>
                  <span class="text-ink-300 text-right">{fmtMs(t.total)}</span>
                  <span class="text-ink-400 text-right">{t.count}×</span>
                  <span class="text-ink-400 text-right">{fmtMs(t.max)} max</span>
                </li>
              );
            }}
          </For>
        </ul>
      </article>
    </div>
  );
}
