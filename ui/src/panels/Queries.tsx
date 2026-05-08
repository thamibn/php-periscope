import { For, Show, createMemo, createSignal } from "solid-js";
import { eventsAtCursor, summary } from "../lib/store";
import { highlight } from "../lib/syntax";
import { fmtMs, truncate } from "../lib/format";
import type { EventJson } from "../lib/types";

interface SqlPayload {
  sql?: string;
  bindings?: unknown[];
  time_ms?: number;
  connection?: string;
}

export function Queries() {
  const [filter, setFilter] = createSignal("");
  const [open, setOpen] = createSignal<number | null>(null);

  const queries = createMemo(() =>
    eventsAtCursor().filter((e) => e.type === "sql"),
  );

  const filtered = createMemo(() => {
    const q = filter().toLowerCase();
    if (!q) return queries();
    return queries().filter((e) => {
      const p = (e.payload ?? {}) as SqlPayload;
      const sql = (p.sql ?? "").toLowerCase();
      const conn = (p.connection ?? "").toLowerCase();
      return sql.includes(q) || conn.includes(q);
    });
  });

  return (
    <article class="panel">
      <div class="panel-header">
        <div class="flex items-center gap-2 normal-case">
          <span class="text-ink-100">Queries</span>
          <span class="mono text-warn">{queries().length}</span>
          <Show when={summary()?.queries.total_ms}>
            <span class="text-ink-400">·</span>
            <span class="mono text-ink-300">{summary()!.queries.total_ms.toFixed(1)} ms total</span>
          </Show>
          <Show when={(summary()?.queries.slow_count ?? 0) > 0}>
            <span class="text-ink-400">·</span>
            <span class="mono text-warn">{summary()!.queries.slow_count} slow</span>
          </Show>
        </div>
        <input
          class="bg-ink-800 border border-ink-700 text-ink-200 rounded px-2 py-1 text-xs mono w-72 focus:outline-none focus:border-accent normal-case"
          placeholder="filter   table or connection"
          value={filter()}
          onInput={(e) => setFilter(e.currentTarget.value)}
        />
      </div>

      <Show
        when={filtered().length > 0}
        fallback={<div class="px-4 py-6 text-sm text-ink-400 text-center">No queries.</div>}
      >
        <ul class="divide-y divide-ink-700/60 mono text-[12.5px]">
          <For each={filtered()}>
            {(e, idx) => <QueryRow ev={e} index={idx() + 1} expanded={open() === e.id} onToggle={() => setOpen(open() === e.id ? null : e.id)} />}
          </For>
        </ul>
      </Show>
    </article>
  );
}

function QueryRow(props: { ev: EventJson; index: number; expanded: boolean; onToggle: () => void }) {
  const p = () => (props.ev.payload ?? {}) as SqlPayload;
  const sql = () => p().sql ?? "";
  const tone = () => {
    const t = p().time_ms ?? 0;
    if (t >= 100) return "danger";
    if (t >= 25) return "warn";
    return "neutral";
  };
  const rowClass = () =>
    tone() === "danger"
      ? "bg-rose-500/5"
      : tone() === "warn"
        ? "bg-warn/5"
        : "";
  const timeClass = () =>
    tone() === "danger" ? "text-danger" : tone() === "warn" ? "text-warn" : "text-ink-300";
  const callSite = () => {
    const cs = props.ev.user_call_site;
    return cs ? `${baseName(cs.file)}:${cs.line}` : "";
  };

  return (
    <li class={`row-hover cursor-pointer ${rowClass()}`} onClick={props.onToggle}>
      <div class="grid grid-cols-[3rem_1fr_5rem_5rem_8rem] items-center gap-3 px-3 py-1.5">
        <span class="text-ink-400 text-right">#{props.index}</span>
        {/* eslint-disable-next-line solid/no-innerhtml */}
        <span class="truncate" innerHTML={highlight(sql(), "sql")} />
        <span class={`text-right ${timeClass()}`}>{fmtMs((p().time_ms ?? 0) * 1000)}</span>
        <span class="text-ink-400 text-right">{p().connection ?? "—"}</span>
        <span class="text-ink-400 text-right truncate">{truncate(callSite(), 18)}</span>
      </div>
      <Show when={props.expanded}>
        <div class="px-3 pb-3">
          <pre class="mono text-[12px] text-ink-200 whitespace-pre-wrap bg-ink-950/60 border border-ink-700/60 rounded p-3 overflow-x-auto">{sql()}</pre>
          <Show when={(p().bindings ?? []).length > 0}>
            <div class="mt-2 text-[11px] text-ink-400 uppercase tracking-wider">Bindings</div>
            <pre class="mono text-[12px] text-ink-300 whitespace-pre-wrap">{JSON.stringify(p().bindings, null, 2)}</pre>
          </Show>
          <Show when={props.ev.user_call_site}>
            {(cs) => (
              <div class="mt-2 text-[11px] mono text-ink-400">
                called at <span class="text-ink-200">{cs().file}:{cs().line}</span>
              </div>
            )}
          </Show>
        </div>
      </Show>
    </li>
  );
}

function baseName(p: string): string {
  return p.split("/").pop() ?? p;
}
