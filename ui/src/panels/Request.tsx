import { For, Show, createMemo, type JSX } from "solid-js";
import { eventsAtCursor, setActiveTab, summary, trace, type TabId } from "../lib/store";
import { fmtBytes, fmtMs } from "../lib/format";
import type { HeaderJson } from "../lib/types";

interface ResolvedRoute {
  route_action?: string;
  route_name?: string;
  route_uri?: string;
  parameters?: Record<string, unknown>;
  middleware?: string[];
  auth_user?: { class: string; key: string | number } | null;
  locale?: string;
}

export function Request() {
  const meta = () => trace()?.meta;
  const req  = () => meta()?.request;
  const resp = () => meta()?.response;

  const resolved = createMemo<ResolvedRoute | null>(() => {
    const e = eventsAtCursor().find((ev) => ev.type === "request_resolved");
    return (e?.payload as ResolvedRoute | undefined) ?? null;
  });

  const time = () => {
    const m = meta();
    if (!m) return "—";
    return new Date(m.started_at_unix_micros / 1000).toLocaleString();
  };

  return (
    <div class="space-y-3">
      <Show
        when={req()}
        fallback={<div class="panel p-6 text-ink-400">No request envelope (CLI?).</div>}
      >
        {(r) => (
          <>
            <article class="panel">
              <div class="panel-header flex items-center gap-2 normal-case">
                <span class="pill bg-ink-800 text-ink-200 ring-1 ring-inset ring-ink-700">{r().method}</span>
                <span class="mono text-ink-100 truncate">{r().uri}</span>
                <Show when={resp()?.status_code}>
                  <span class={`pill ring-1 ring-inset ml-auto ${statusClasses(resp()!.status_code)}`}>
                    {resp()!.status_code}
                  </span>
                </Show>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-6 px-5 py-4">
                <Section title="General">
                  <Row label="Date"          value={time()} />
                  <Show when={resp()?.status_code}>
                    <Row label="Status code" value={
                      <span class={`pill ring-1 ring-inset ${statusClasses(resp()!.status_code)}`}>{resp()!.status_code}</span>
                    } />
                  </Show>
                  <Row label="Method"        value={
                    <span class="pill bg-ink-800 text-ink-200 ring-1 ring-inset ring-ink-700">{r().method}</span>
                  } />
                  <Row label="Duration"      value={fmtMs(meta()!.duration_micros)} />
                  <Row label="Response size" value={fmtBytes(resp()?.total_body_bytes ?? 0)} />
                  <Show when={resp()?.peak_memory_bytes !== undefined}>
                    <Row label="Peak memory" value={fmtBytes(resp()!.peak_memory_bytes)} />
                  </Show>
                  <Row label="Host"          value={meta()!.hostname} />
                  <Row label="PHP"           value={meta()!.php_version} />
                </Section>

                <Section title="Request">
                  <Row label="Path"          value={r().uri} mono />
                  <Show when={resolved()?.route_name}>
                    <Row label="Route name"   value={resolved()!.route_name!} mono />
                  </Show>
                  <Show when={resolved()?.route_action}>
                    <Row label="Controller"   value={resolved()!.route_action!} mono />
                  </Show>
                  <Row label="IP address"    value={r().remote_addr} />
                  <Row label="Body size"     value={
                    `${fmtBytes(r().total_body_bytes)}${r().body_truncated ? " (truncated)" : ""}`
                  } />
                  <Row label="Params"        value={Object.keys(resolved()?.parameters ?? {}).length} />
                  <Show when={(resolved()?.middleware ?? []).length > 0}>
                    <div class="flex flex-col gap-1.5 pt-1">
                      <span class="text-[10px] tracking-[0.18em] text-ink-500 uppercase mono">Middleware</span>
                      <div class="flex flex-wrap gap-1">
                        <For each={resolved()!.middleware!}>
                          {(m) => (
                            <span class="pill bg-ink-800 text-ink-300 ring-1 ring-inset ring-ink-700 normal-case mono">
                              {m}
                            </span>
                          )}
                        </For>
                      </div>
                    </div>
                  </Show>
                </Section>

                <Section title="Events">
                  <CountRow label="Queries"           count={summary()?.queries.count ?? 0}      tab="queries"   suffix={summary() ? ` · ${summary()!.queries.total_ms.toFixed(1)} ms` : ""} />
                  <CountRow label="Outgoing HTTP"     count={countByType("http")}                tab="http" />
                  <CountRow label="Queued jobs"       count={countByType("job")}                 tab="jobs" />
                  <CountRow label="Cache events"      count={countByType("cache")}               tab="cache" />
                  <CountRow label="Models hydrated"   count={summary()?.models.hydrated_count ?? 0} tab="models" />
                  <CountRow label="Logs"              count={countByType("log")}                 tab="logs" />
                  <CountRow label="Events fired"      count={countByType("event")}               tab="events" />
                  <CountRow label="Mail"              count={countByType("mail")}                tab="mail" />
                  <CountRow label="Notifications"     count={countByType("notification")}        tab="notifications" />
                  <CountRow label="Exceptions"        count={countByType("exception")}           tab="exceptions" tone={countByType("exception") > 0 ? "danger" : undefined} />
                </Section>

                <Show when={resolved()?.auth_user}>
                  <Section title="User">
                    <Row label="Class" value={resolved()!.auth_user!.class} mono />
                    <Row label="Key"   value={String(resolved()!.auth_user!.key)} />
                    <Row label="IP"    value={r().remote_addr} />
                  </Section>
                </Show>

                <Show when={resolved()?.locale}>
                  <Section title="App">
                    <Row label="Locale" value={resolved()!.locale!} mono />
                  </Section>
                </Show>
              </div>
            </article>

            <HeaderTable title="Headers"      rows={r().headers} />
            <HeaderTable title="Cookies"      rows={r().cookies} />
            <HeaderTable title="Query string" rows={r().query} />
            <HeaderTable title="POST params"  rows={r().post_params} />
          </>
        )}
      </Show>
    </div>
  );
}

function Section(props: { title: string; children: JSX.Element }) {
  return (
    <section class="space-y-1.5">
      <h3 class="text-[10px] tracking-[0.18em] text-ink-500 uppercase mono">{props.title}</h3>
      <dl class="space-y-0.5">{props.children}</dl>
    </section>
  );
}

/**
 * Dotted-leader row:  KEY .................. VALUE
 */
function Row(props: { label: string; value: JSX.Element; mono?: boolean }) {
  return (
    <div class="flex items-baseline gap-2 text-[12.5px] py-0.5">
      <dt class="text-ink-400 normal-case shrink-0">{props.label}</dt>
      <span class="flex-1 border-b border-dotted border-ink-700/50" aria-hidden="true" />
      <dd class={`shrink-0 text-right truncate max-w-[60%] ${props.mono ? "mono text-ink-200" : "text-ink-200"}`}>
        {props.value}
      </dd>
    </div>
  );
}

/**
 * Count row with optional VIEW link that navigates to the matching panel.
 */
function CountRow(props: {
  label: string;
  count: number;
  tab: TabId;
  suffix?: string;
  tone?: "danger" | "warn";
}) {
  const toneClass = () =>
    props.tone === "danger" ? "text-rose-300" : props.tone === "warn" ? "text-warn" : "text-ink-200";
  return (
    <div class="flex items-baseline gap-2 text-[12.5px] py-0.5">
      <dt class="text-ink-400 normal-case shrink-0">{props.label}</dt>
      <span class="flex-1 border-b border-dotted border-ink-700/50" aria-hidden="true" />
      <dd class={`mono shrink-0 ${toneClass()}`}>
        {props.count}{props.suffix ?? ""}
        <Show when={props.count > 0}>
          <button
            type="button"
            class="ml-2 text-[10px] mono tracking-wider text-ink-500 hover:text-accent uppercase"
            onClick={() => setActiveTab(props.tab)}
          >
            view
          </button>
        </Show>
      </dd>
    </div>
  );
}

function countByType(type: string): number {
  return eventsAtCursor().filter((e) => e.type === type).length;
}

function statusClasses(code: number): string {
  if (code >= 500) return "bg-rose-500/10 text-rose-300 ring-rose-500/30";
  if (code >= 400) return "bg-amber-500/10 text-amber-300 ring-amber-500/30";
  if (code >= 300) return "bg-sky-500/10 text-sky-300 ring-sky-500/30";
  if (code >= 200) return "bg-emerald-500/10 text-emerald-300 ring-emerald-500/30";
  return "bg-ink-700 text-ink-200 ring-ink-600";
}

export function HeaderTable(props: { title: string; rows: HeaderJson[] }) {
  return (
    <Show when={props.rows.length > 0}>
      <article class="panel">
        <div class="panel-header"><span>{props.title}</span><span class="mono text-ink-400 normal-case">{props.rows.length}</span></div>
        <dl class="px-3 py-2 text-[12px] mono space-y-1">
          <For each={props.rows}>
            {(h) => (
              <div class="flex gap-3">
                <dt class="w-40 text-ink-400 shrink-0 truncate">{h.name}</dt>
                <dd class={`flex-1 truncate ${h.redacted ? "italic text-ink-400" : "text-ink-200"}`}>
                  {h.redacted ? "[redacted]" : h.value}
                </dd>
              </div>
            )}
          </For>
        </dl>
      </article>
    </Show>
  );
}
