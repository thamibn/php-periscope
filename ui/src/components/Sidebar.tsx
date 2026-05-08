import { For, Show } from "solid-js";
import { fmtBytes, fmtMs, relTime, statusTone } from "../lib/format";
import { selectedTraceId, setSelectedTraceId, setTracesRefreshKey, traces } from "../lib/store";
import { api, isStaticMode } from "../lib/api";
import { health } from "../lib/health";

export function Sidebar() {
  const totalSize = () => traces().reduce((acc, t) => acc + t.size_bytes, 0);

  const onDelete = async (id: string, ev: MouseEvent) => {
    ev.stopPropagation();
    if (!confirm(`Delete trace ${id}?`)) return;
    await api.deleteTrace(id);
    setTracesRefreshKey((k) => k + 1);
  };

  const onClearAll = async () => {
    const list = traces();
    if (
      list.length >= 10 ||
      list.reduce((s, t) => s + t.size_bytes, 0) >= 10 * 1024 * 1024
    ) {
      if (!confirm(`Delete all ${list.length} traces (${fmtBytes(totalSize())})? This cannot be undone.`)) return;
    } else if (!confirm(`Delete all ${list.length} traces?`)) {
      return;
    }
    await api.clearTraces();
    setSelectedTraceId(null);
    setTracesRefreshKey((k) => k + 1);
  };

  return (
    <aside class="space-y-3">
      <section class="panel">
        <div class="panel-header">
          <span>Traces</span>
          <span class="mono normal-case text-ink-400">{traces().length}</span>
        </div>
        <ul class="divide-y divide-ink-700/60 max-h-[55vh] overflow-y-auto scroll-thin">
          <Show
            when={traces().length > 0}
            fallback={<li class="px-3 py-6 text-center text-xs text-ink-400">no traces yet</li>}
          >
            <For each={traces()}>
              {(t) => (
                <li
                  class={`row-hover px-2.5 py-2 cursor-pointer ${
                    selectedTraceId() === t.id ? "bg-ink-800/70" : ""
                  }`}
                  onClick={() => setSelectedTraceId(t.id)}
                >
                  <div class="flex items-center justify-between text-[12px] gap-2">
                    <span class="mono text-ink-100 truncate flex-1">
                      {t.method ? `${t.method} ${t.uri}` : t.path}
                    </span>
                    <StatusBadge code={t.status_code} hasException={t.has_exception} />
                  </div>
                  <div class="mt-0.5 flex items-center justify-between text-[11px] text-ink-400 mono">
                    <span>
                      {fmtMs(t.duration_micros)} · {t.event_count} ev · {relTime(t.started_at_unix_micros)}
                    </span>
                    <Show when={!isStaticMode()}>
                      <button
                        title="delete"
                        class="opacity-0 hover:opacity-100 group-hover:opacity-100 hover:text-danger px-1"
                        onClick={(e) => onDelete(t.id, e)}
                      >
                        ×
                      </button>
                    </Show>
                  </div>
                </li>
              )}
            </For>
          </Show>
        </ul>
      </section>

      <Show when={!isStaticMode()}>
        <section class="panel">
          <div class="panel-header">
            <span>Storage</span>
            <span class="mono normal-case text-ink-400 truncate max-w-[12rem]" title={health()?.trace_dir ?? ""}>
              {health()?.trace_dir ?? "—"}
            </span>
          </div>
          <div class="p-3 space-y-2 text-xs text-ink-200">
            <div class="flex justify-between">
              <span class="text-ink-400">files</span>
              <span class="mono">{traces().length}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-ink-400">size</span>
              <span class="mono">{fmtBytes(totalSize())}</span>
            </div>
            <div class="flex gap-2 pt-1.5">
              <button
                class="chip flex-1 justify-center"
                onClick={() => setTracesRefreshKey((k) => k + 1)}
              >
                Refresh
              </button>
              <button
                class="chip flex-1 justify-center hover:!border-danger hover:!text-danger"
                onClick={onClearAll}
              >
                Clear all
              </button>
            </div>
          </div>
        </section>
      </Show>

      <Show when={isStaticMode()}>
        <section class="panel p-3 text-xs text-ink-300 space-y-1">
          <div class="font-semibold text-ink-100">Static export</div>
          <p>This page reads a snapshot inlined at export time. Live mode and breakpoints are disabled.</p>
        </section>
      </Show>
    </aside>
  );
}

function StatusBadge(props: { code: number; hasException: boolean }) {
  const tone = () => (props.hasException ? "err" : statusTone(props.code));
  const cls = () =>
    ({
      ok: "bg-emerald-500/10 text-emerald-300 ring-emerald-500/30",
      warn: "bg-amber-500/10 text-amber-300 ring-amber-500/30",
      err: "bg-rose-500/10 text-rose-300 ring-rose-500/30",
      neutral: "bg-ink-700 text-ink-200 ring-ink-600",
    }[tone()]);
  const label = props.code === 0 ? "cli" : String(props.code);
  return <span class={`pill ring-1 ring-inset ${cls()}`}>{label}</span>;
}
