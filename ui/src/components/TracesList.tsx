import { For, Show } from "solid-js";
import { selectedTraceId, setSelectedTraceId, setTracesRefreshKey, traces } from "../lib/store";
import { fmtBytes, fmtMs, relTime, statusTone } from "../lib/format";
import { api, isStaticMode } from "../lib/api";

/**
 * Card-style table of recorded traces. Used as the landing page — clicking
 * a row drills into the dashboard for that trace. Columns:
 *
 *   verb · path                            status · duration · mem · events · when
 *
 * Layout: a CSS grid template that keeps every row aligned. Header row uses
 * the same template so column edges line up.
 */
export function TracesList() {
  const onDelete = async (id: string, ev: MouseEvent) => {
    ev.stopPropagation();
    if (!confirm(`Delete trace ${id}?`)) return;
    await api.deleteTrace(id);
    setTracesRefreshKey((k) => k + 1);
  };

  // grid cols: verb | path (flex) | status | duration | mem | events | when | delete
  const grid =
    "grid grid-cols-[3.5rem_minmax(0,1fr)_3.5rem_4.5rem_5rem_4.5rem_5.5rem_2rem] items-center gap-3 px-4";

  return (
    <article class="panel">
      <div class="panel-header">
        <span class="flex items-center gap-2">
          Traces
          <span class="mono normal-case text-ink-400">{traces().length}</span>
        </span>
        <button
          type="button"
          class="text-[11px] mono tracking-wider text-ink-400 hover:text-accent uppercase normal-case"
          onClick={() => setTracesRefreshKey((k) => k + 1)}
        >
          refresh
        </button>
      </div>

      <Show
        when={traces().length > 0}
        fallback={<div class="px-4 py-10 text-center text-sm text-ink-400">no traces yet</div>}
      >
        <div class={`${grid} py-2 text-[10px] tracking-[0.18em] uppercase mono text-ink-500 border-b border-ink-700/60`}>
          <span>Verb</span>
          <span>Path</span>
          <span class="text-right">Status</span>
          <span class="text-right">Duration</span>
          <span class="text-right">Memory</span>
          <span class="text-right">Events</span>
          <span class="text-right">When</span>
          <span aria-hidden="true" />
        </div>
        <ul class="divide-y divide-ink-700/60">
          <For each={traces()}>
            {(t) => (
              <li
                class={`group ${grid} py-2 cursor-pointer transition-colors text-[12.5px] ${
                  selectedTraceId() === t.id ? "bg-ink-800/70" : "hover:bg-ink-800/40"
                }`}
                onClick={() => setSelectedTraceId(t.id)}
              >
                <span>
                  <Show
                    when={t.method}
                    fallback={<span class="pill bg-ink-800 text-ink-300 ring-1 ring-inset ring-ink-700">CLI</span>}
                  >
                    <span class="pill bg-ink-800 text-ink-200 ring-1 ring-inset ring-ink-700">{t.method}</span>
                  </Show>
                </span>
                <span class="mono text-ink-100 truncate" title={t.uri || t.path}>
                  {t.uri || t.path}
                </span>
                <span class="text-right">
                  <StatusBadge code={t.status_code} hasException={t.has_exception} />
                </span>
                <span class="mono text-right text-ink-200">{fmtMs(t.duration_micros)}</span>
                <span class="mono text-right text-ink-300">{fmtBytes(t.size_bytes)}</span>
                <span class="mono text-right text-ink-300">{t.event_count}</span>
                <span class="mono text-right text-ink-400 text-[11px]">
                  {relTime(t.started_at_unix_micros)}
                </span>
                <span class="text-right">
                  <Show when={!isStaticMode()}>
                    <button
                      type="button"
                      title="delete trace"
                      aria-label="delete trace"
                      class="opacity-0 group-hover:opacity-100 focus:opacity-100 text-ink-400 hover:text-danger transition-opacity p-1 -m-1"
                      onClick={(e) => onDelete(t.id, e)}
                    >
                      <TrashIcon />
                    </button>
                  </Show>
                </span>
              </li>
            )}
          </For>
        </ul>
      </Show>
    </article>
  );
}

function TrashIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5" aria-hidden="true">
      <path d="M3 6h18" />
      <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
      <path d="M10 11v6" />
      <path d="M14 11v6" />
      <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" />
    </svg>
  );
}

function StatusBadge(props: { code: number; hasException: boolean }) {
  const tone = () => (props.hasException ? "err" : statusTone(props.code));
  const cls = () =>
    ({
      ok:      "bg-emerald-500/10 text-emerald-300 ring-emerald-500/30",
      warn:    "bg-amber-500/10 text-amber-300 ring-amber-500/30",
      err:     "bg-rose-500/10 text-rose-300 ring-rose-500/30",
      neutral: "bg-ink-700 text-ink-200 ring-ink-600",
    }[tone()]);
  const label = props.code === 0 ? "—" : String(props.code);
  return <span class={`pill ring-1 ring-inset ${cls()}`}>{label}</span>;
}
