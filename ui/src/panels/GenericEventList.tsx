import { For, Show, createMemo, createSignal } from "solid-js";
import { eventsAtCursor } from "../lib/store";
import { fmtMs, safeJson, truncate } from "../lib/format";
import type { EventJson } from "../lib/types";

export function GenericEventList(props: { type: string; title: string; empty?: string }) {
  const [filter, setFilter] = createSignal("");
  const [open, setOpen] = createSignal<number | null>(null);

  const items = createMemo(() => eventsAtCursor().filter((e) => e.type === props.type));
  const filtered = createMemo(() => {
    const q = filter().toLowerCase();
    if (!q) return items();
    return items().filter((e) => safeJson(e.payload, 0).toLowerCase().includes(q));
  });

  return (
    <article class="panel">
      <div class="panel-header">
        <div class="flex items-center gap-2 normal-case">
          <span class="text-ink-100">{props.title}</span>
          <span class="mono text-ink-400">{items().length}</span>
        </div>
        <input
          class="bg-ink-800 border border-ink-700 text-ink-200 rounded px-2 py-1 text-xs mono w-72 focus:outline-none focus:border-accent normal-case"
          placeholder="filter payload"
          value={filter()}
          onInput={(e) => setFilter(e.currentTarget.value)}
        />
      </div>
      <Show
        when={filtered().length > 0}
        fallback={<div class="px-4 py-6 text-sm text-ink-400 text-center">{props.empty ?? "Nothing to show."}</div>}
      >
        <ul class="divide-y divide-ink-700/60 text-[12.5px]">
          <For each={filtered()}>
            {(e) => (
              <Row ev={e} expanded={open() === e.id} onToggle={() => setOpen(open() === e.id ? null : e.id)} />
            )}
          </For>
        </ul>
      </Show>
    </article>
  );
}

function Row(props: { ev: EventJson; expanded: boolean; onToggle: () => void }) {
  const meta = () => describe(props.ev);
  return (
    <li class={`row-hover cursor-pointer ${meta().rowClass}`} onClick={props.onToggle}>
      <div class="grid grid-cols-[5rem_1fr_6rem] items-center gap-3 px-3 py-1.5">
        <span class={`pill ring-1 ring-inset normal-case ${meta().pillClass}`}>{meta().tag}</span>
        <span class="mono truncate text-ink-200" title={meta().line}>
          {truncate(meta().line, 140)}
        </span>
        <span class="mono text-ink-400 text-right">+{fmtMs(props.ev.at_micros)}</span>
      </div>
      <Show when={props.expanded}>
        <pre class="mono text-[12px] text-ink-200 whitespace-pre-wrap bg-ink-950/60 border-t border-ink-700/60 px-4 py-3 overflow-x-auto">
          {safeJson(props.ev.payload)}
        </pre>
        <Show when={props.ev.user_call_site}>
          {(cs) => (
            <div class="px-4 pb-3 text-[11px] mono text-ink-400">
              at <span class="text-ink-200">{cs().file}:{cs().line}</span>
            </div>
          )}
        </Show>
      </Show>
    </li>
  );
}

interface RowMeta {
  tag: string;
  line: string;
  pillClass: string;
  rowClass: string;
}

function describe(e: EventJson): RowMeta {
  const p = (e.payload ?? {}) as Record<string, unknown>;
  switch (e.type) {
    case "log": {
      const level = String(p.level ?? "info").toLowerCase();
      const msg = String(p.message ?? "");
      const isErr = level === "error" || level === "critical" || level === "emergency";
      const isWarn = level === "warning" || level === "warn";
      return {
        tag: level,
        line: msg,
        pillClass: isErr
          ? "bg-rose-500/10 text-rose-300 ring-rose-500/30"
          : isWarn
            ? "bg-amber-500/10 text-amber-300 ring-amber-500/30"
            : "bg-ink-700 text-ink-200 ring-ink-600",
        rowClass: isErr ? "bg-rose-500/5" : isWarn ? "bg-warn/5" : "",
      };
    }
    case "cache": {
      const op = String(p.op ?? p.event ?? "op").toUpperCase();
      const key = String(p.key ?? "");
      const isMiss = op.includes("MISS");
      return {
        tag: op,
        line: key,
        pillClass: isMiss
          ? "bg-amber-500/10 text-amber-300 ring-amber-500/30"
          : op === "HIT"
            ? "bg-emerald-500/10 text-emerald-300 ring-emerald-500/30"
            : "bg-ink-700 text-ink-200 ring-ink-600",
        rowClass: isMiss ? "bg-warn/5" : "",
      };
    }
    case "exception": {
      const cls = String(p.class ?? "Throwable");
      const msg = String(p.message ?? "");
      return {
        tag: "exc",
        line: `${cls}: ${msg}`,
        pillClass: "bg-rose-500/10 text-rose-300 ring-rose-500/30",
        rowClass: "bg-rose-500/5",
      };
    }
    case "http": {
      const method = String(p.method ?? "GET");
      const url = String(p.url ?? "");
      const status = p.status ?? p.status_code ?? "?";
      const t = (p.time_ms as number | undefined) ?? 0;
      const slow = t >= 250;
      return {
        tag: method,
        line: `${url} → ${status} · ${fmtMs(t * 1000)}`,
        pillClass: "bg-ink-700 text-ink-200 ring-ink-600",
        rowClass: slow ? "bg-warn/5" : "",
      };
    }
    case "redis": {
      return {
        tag: "redis",
        line: `${String(p.command ?? "")} ${String(p.key ?? "")}`,
        pillClass: "bg-ink-700 text-ink-200 ring-ink-600",
        rowClass: "",
      };
    }
    case "job": {
      return {
        tag: "job",
        line: `${String(p.class ?? p.job ?? "")} → ${String(p.queue ?? "default")}`,
        pillClass: "bg-pink/10 text-pink ring-pink/30",
        rowClass: "",
      };
    }
    case "event": {
      return {
        tag: "event",
        line: String(p.class ?? p.name ?? ""),
        pillClass: "bg-purple/10 text-purple ring-purple/30",
        rowClass: "",
      };
    }
    case "mail": {
      return {
        tag: "mail",
        line: `${String(p.subject ?? p.class ?? "")} → ${String(p.to ?? "")}`,
        pillClass: "bg-accent/10 text-accent ring-accent/30",
        rowClass: "",
      };
    }
    case "notification": {
      return {
        tag: "notify",
        line: `${String(p.class ?? "")} via ${String(p.channel ?? "")}`,
        pillClass: "bg-accent/10 text-accent ring-accent/30",
        rowClass: "",
      };
    }
    case "model": {
      return {
        tag: "model",
        line: `${String(p.class ?? "")} ${String(p.event ?? "")}`,
        pillClass: "bg-ink-700 text-ink-200 ring-ink-600",
        rowClass: "",
      };
    }
    default:
      return {
        tag: e.type,
        line: safeJson(e.payload, 0).slice(0, 200),
        pillClass: "bg-ink-700 text-ink-200 ring-ink-600",
        rowClass: "",
      };
  }
}
