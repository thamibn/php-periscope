import { Show } from "solid-js";
import { trace } from "../lib/store";
import { fmtBytes, fmtMs, statusTone } from "../lib/format";
import { HeaderTable } from "./Request";

export function Response() {
  const r = () => trace()?.meta.response;
  return (
    <div class="space-y-3">
      <Show when={r()} fallback={<div class="panel p-6 text-ink-400">No response envelope.</div>}>
        {(res) => {
          const tone = () => statusTone(res().status_code);
          const cls = () =>
            ({
              ok: "bg-emerald-500/10 text-emerald-300 ring-emerald-500/30",
              warn: "bg-amber-500/10 text-amber-300 ring-amber-500/30",
              err: "bg-rose-500/10 text-rose-300 ring-rose-500/30",
              neutral: "bg-ink-800 text-ink-200 ring-ink-700",
            }[tone()]);
          return (
            <>
              <article class="panel p-4 space-y-1 mono text-sm">
                <div class="flex items-center gap-2">
                  <span class={`pill ring-1 ring-inset ${cls()}`}>{res().status_code}</span>
                  <span class="text-ink-300">·</span>
                  <span class="text-ink-100">{fmtMs(res().duration_micros)}</span>
                  <span class="text-ink-400">·</span>
                  <span class="text-ink-100">{fmtBytes(res().peak_memory_bytes)} peak mem</span>
                </div>
                <div class="text-xs text-ink-400">
                  body: {fmtBytes(res().total_body_bytes)} {res().body_truncated ? "(truncated)" : ""}
                </div>
              </article>
              <HeaderTable title="Headers" rows={res().headers} />
            </>
          );
        }}
      </Show>
    </div>
  );
}
