import { For, Show, createMemo, createSignal } from "solid-js";
import { eventsAtCursor } from "../lib/store";
import { fmtMs, safeJson, truncate } from "../lib/format";
import { groupEvents, type EventGroup } from "../lib/grouping";
import { parseFilter } from "../lib/event_filter";
import type { EventJson } from "../lib/types";
import { CodeView } from "../components/CodeView";
import { AfterResponseBadge } from "../components/AfterResponseBadge";

export function GenericEventList(props: { type: string; title: string; empty?: string }) {
  const [filter, setFilter] = createSignal("");
  const [open, setOpen] = createSignal<number | null>(null);
  const [grouped, setGrouped] = createSignal(false);
  const [expandedGroup, setExpandedGroup] = createSignal<string | null>(null);

  const items = createMemo(() => eventsAtCursor().filter((e) => e.type === props.type));
  const parsed = createMemo(() => parseFilter(filter()));
  const filterError = createMemo(() => {
    const r = parsed();
    return r.ok ? null : r.error;
  });
  const filtered = createMemo(() => {
    const r = parsed();
    if (!r.ok || r.filter.isEmpty) return items();
    return items().filter((e) => r.filter.matches(e));
  });
  const groups = createMemo<EventGroup[]>(() => groupEvents(filtered()));
  // Hide the grouping toggle when every event already has count=1; pressing
  // it wouldn't change anything visible.
  const canGroup = createMemo(() => groups().some((g) => g.count > 1));

  return (
    <article class="panel">
      <div class="panel-header">
        <div class="flex items-center gap-2 normal-case">
          <span class="text-ink-100">{props.title}</span>
          <span class="mono text-ink-400">{items().length}</span>
          <Show when={grouped() && groups().length !== items().length}>
            <span class="mono text-ink-500 text-[11px]">· {groups().length} unique</span>
          </Show>
        </div>
        <div class="flex items-center gap-2">
          <Show when={canGroup()}>
            <button
              type="button"
              class={`mono text-[11px] px-2 py-1 rounded border ${
                grouped()
                  ? "bg-accent/10 text-accent border-accent/40"
                  : "bg-ink-800 text-ink-300 border-ink-700 hover:border-ink-600"
              }`}
              title="Collapse identical events into a single row. Different variables stay separate."
              onClick={() => setGrouped(!grouped())}
            >
              {grouped() ? "grouped" : "group"}
            </button>
          </Show>
          <div class="flex flex-col items-end">
            <input
              class={`bg-ink-800 border text-ink-200 rounded px-2 py-1 text-xs mono w-72 focus:outline-none normal-case ${
                filterError() ? "border-rose-500/60 focus:border-rose-500" : "border-ink-700 focus:border-accent"
              }`}
              placeholder='filter — text or payload.level:error'
              title='Substring, or structured: payload.level:error AND payload.context.user_id:42'
              value={filter()}
              onInput={(e) => setFilter(e.currentTarget.value)}
            />
            <Show when={filterError()}>
              <span class="text-[10.5px] text-rose-400 mt-0.5 max-w-72 truncate" title={filterError() ?? ""}>
                {filterError()}
              </span>
            </Show>
          </div>
        </div>
      </div>
      <Show
        when={filtered().length > 0}
        fallback={<div class="px-4 py-6 text-sm text-ink-400 text-center">{props.empty ?? "Nothing to show."}</div>}
      >
        <Show
          when={grouped()}
          fallback={
            <ul class="divide-y divide-ink-700/60 text-[12.5px]">
              <For each={filtered()}>
                {(e) => (
                  <Row ev={e} expanded={open() === e.id} onToggle={() => setOpen(open() === e.id ? null : e.id)} />
                )}
              </For>
            </ul>
          }
        >
          <ul class="divide-y divide-ink-700/60 text-[12.5px]">
            <For each={groups()}>
              {(g) => (
                <GroupRow
                  group={g}
                  expanded={expandedGroup() === g.fingerprint}
                  onToggle={() => setExpandedGroup(expandedGroup() === g.fingerprint ? null : g.fingerprint)}
                  openOccurrence={open()}
                  onToggleOccurrence={(id) => setOpen(open() === id ? null : id)}
                />
              )}
            </For>
          </ul>
        </Show>
      </Show>
    </article>
  );
}

function GroupRow(props: {
  group: EventGroup;
  expanded: boolean;
  onToggle: () => void;
  openOccurrence: number | null;
  onToggleOccurrence: (id: number) => void;
}) {
  const single = () => props.group.count === 1;
  return (
    <li>
      <Show
        when={!single()}
        fallback={
          <Row
            ev={props.group.sample}
            expanded={props.openOccurrence === props.group.sample.id}
            onToggle={() => props.onToggleOccurrence(props.group.sample.id)}
          />
        }
      >
        <div
          class="row-hover cursor-pointer grid grid-cols-[5rem_1fr_6rem] items-center gap-3 px-3 py-1.5"
          onClick={props.onToggle}
        >
          <span class="pill ring-1 ring-inset normal-case bg-ink-700 text-ink-100 ring-ink-600 mono">
            {props.group.count}×
          </span>
          <span class="mono truncate text-ink-200 flex items-center gap-2">
            <span class="truncate" title={describe(props.group.sample).line}>
              {truncate(describe(props.group.sample).line, 140)}
            </span>
          </span>
          <span class="mono text-ink-500 text-right text-[11px]">
            +{fmtMs(props.group.firstAtMicros)}
            <Show when={props.group.lastAtMicros !== props.group.firstAtMicros}>
              <> … {fmtMs(props.group.lastAtMicros)}</>
            </Show>
          </span>
        </div>
        <Show when={props.expanded}>
          <ul class="divide-y divide-ink-700/40 bg-ink-900/40">
            <For each={props.group.occurrences}>
              {(e) => (
                <Row
                  ev={e}
                  expanded={props.openOccurrence === e.id}
                  onToggle={() => props.onToggleOccurrence(e.id)}
                />
              )}
            </For>
          </ul>
        </Show>
      </Show>
    </li>
  );
}

function Row(props: { ev: EventJson; expanded: boolean; onToggle: () => void }) {
  const meta = () => describe(props.ev);
  const afterResponse = () => {
    const p = props.ev.payload as Record<string, unknown> | undefined;
    return p?.after_response === true;
  };
  return (
    <li class={`row-hover cursor-pointer ${meta().rowClass}`} onClick={props.onToggle}>
      <div class="grid grid-cols-[5rem_1fr_6rem] items-center gap-3 px-3 py-1.5">
        <span class={`pill ring-1 ring-inset normal-case ${meta().pillClass}`}>{meta().tag}</span>
        <span class="mono truncate text-ink-200 flex items-center gap-2" title={meta().line}>
          <span class="truncate">{truncate(meta().line, 140)}</span>
          <Show when={afterResponse()}>
            <AfterResponseBadge />
          </Show>
        </span>
        <span class="mono text-ink-400 text-right">+{fmtMs(props.ev.at_micros)}</span>
      </div>
      <Show when={props.expanded}>
        <div class="px-3 pb-3 space-y-3">
          <Show
            when={props.ev.type === "dump"}
            fallback={
              <div>
                <div class="text-[11px] text-ink-400 uppercase tracking-wider mb-1">Payload</div>
                <pre class="mono text-[12px] text-ink-200 whitespace-pre-wrap bg-ink-950/60 border border-ink-700/60 rounded p-3 overflow-x-auto">
                  {safeJson(props.ev.payload)}
                </pre>
              </div>
            }
          >
            <div>
              <div class="text-[11px] text-ink-400 uppercase tracking-wider mb-1">Dumped value</div>
              <pre class="mono text-[12px] text-ink-200 whitespace-pre-wrap bg-ink-950/60 border border-ink-700/60 rounded p-3 overflow-x-auto">
                {stripAnsi(String((props.ev.payload as { rendered?: string } | undefined)?.rendered ?? ""))}
              </pre>
            </div>
          </Show>
          <Show when={props.ev.user_call_site}>
            {(cs) => (
              <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-3">
                <div>
                  <div class="text-[11px] text-ink-400 uppercase tracking-wider mb-1">
                    Source · <span class="normal-case text-ink-300">{cs().file}:{cs().line}</span>
                  </div>
                  <Show
                    when={cs().snippet.length > 0}
                    fallback={<div class="text-xs text-ink-400 px-2">no snippet captured</div>}
                  >
                    <CodeView
                      lines={cs().snippet}
                      filename={cs().file}
                      currentLine={cs().line}
                      lang="php"
                    />
                  </Show>
                </div>
                <div>
                  <div class="text-[11px] text-ink-400 uppercase tracking-wider mb-1">
                    Stack · <span class="normal-case text-ink-300">{(cs().stack ?? []).length} frames</span>
                  </div>
                  <Show
                    when={(cs().stack ?? []).length > 0}
                    fallback={<div class="text-xs text-ink-400 px-2">no stack</div>}
                  >
                    <ol class="space-y-0.5 text-[12px] mono">
                      <For each={cs().stack ?? []}>
                        {(s) => (
                          <li class="row-hover px-2 py-1 rounded">
                            <div class="text-ink-100 truncate" title={s.function}>{s.function}</div>
                            <div class="text-[11px] text-ink-400 truncate" title={`${s.file}:${s.line}`}>
                              {baseName(s.file)}:{s.line}
                            </div>
                          </li>
                        )}
                      </For>
                    </ol>
                  </Show>
                </div>
              </div>
            )}
          </Show>
        </div>
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
    case "dump": {
      const rendered = stripAnsi(String(p.rendered ?? "")).trim();
      // First non-empty line is usually a useful one-liner: type + brief value.
      // For multi-line dumps (arrays, objects), we show "type · N lines".
      const firstLine = rendered.split("\n").find((l) => l.trim().length > 0) ?? "";
      const lineCount = rendered.split("\n").filter((l) => l.trim().length > 0).length;
      const summary = lineCount > 1
        ? `${truncate(firstLine, 80)} … (${lineCount} lines)`
        : firstLine;
      return {
        tag: "dump",
        line: summary,
        pillClass: "bg-purple/10 text-purple ring-purple/30",
        rowClass: "",
      };
    }
    case "model": {
      const action = String(p.action ?? p.event ?? "");
      const cls = String(p.class ?? "");
      const key = p.key !== undefined && p.key !== null ? `#${String(p.key)}` : "";
      const changes = Array.isArray(p.changes) && p.changes.length > 0
        ? ` · ${(p.changes as unknown[]).join(", ")}`
        : "";
      return {
        tag: action || "model",
        line: `${cls}${key ? " " + key : ""}${changes}`,
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

function baseName(p: string): string {
  return p.split("/").pop() ?? p;
}

/**
 * Strip ANSI color escape sequences (e.g. `\x1b[33m`) so dump output
 * renders as readable plain text in the row title. The expanded panel
 * shows the original rendered string in a <pre>; if it contains ANSI
 * codes they appear as literal characters there too — we strip in the
 * row component.
 */
function stripAnsi(s: string): string {
  // eslint-disable-next-line no-control-regex
  return s.replace(/\x1b\[[0-9;]*m/g, "");
}
