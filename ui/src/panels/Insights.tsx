import { For, Show } from "solid-js";
import { insights } from "../lib/store";
import { fmtMs } from "../lib/format";

export function InsightsPanel() {
  const i = () => insights();
  return (
    <article class="space-y-3">
      <Show when={i()} fallback={<div class="panel p-6 text-ink-400">computing insights…</div>}>
        {(d) => (
          <>
            <Group title="N+1 patterns" empty="No N+1 detected.">
              <For each={d().n_plus_one}>
                {(p) => (
                  <Card
                    badge="N+1"
                    badgeTone="warn"
                    headline={p.pattern}
                    sub={`Fired ${p.count}× in frame #${p.frame_id}`}
                    meta={p.call_site_file ? `${baseName(p.call_site_file)}:${p.call_site_line ?? "?"}` : undefined}
                    body={p.recommendation}
                  />
                )}
              </For>
            </Group>
            <Group title="Slow queries" empty="No slow queries.">
              <For each={d().slow_queries}>
                {(s) => (
                  <Card
                    badge={`${s.time_ms.toFixed(0)} ms`}
                    badgeTone="danger"
                    headline={s.sql}
                    sub={`event #${s.event_id}`}
                    body={s.recommendation}
                  />
                )}
              </For>
            </Group>
            <Group title="Slow frames" empty="No slow frames.">
              <For each={d().slow_frames}>
                {(s) => (
                  <Card
                    badge={fmtMs(s.duration_micros)}
                    badgeTone="warn"
                    headline={s.function}
                    sub={`${baseName(s.file)}:${s.line}`}
                    body={s.recommendation}
                  />
                )}
              </For>
            </Group>
            <Group title="DB-in-loop" empty="None.">
              <For each={d().db_in_loop}>
                {(s) => (
                  <Card
                    badge={`${s.query_count}×`}
                    badgeTone="warn"
                    headline={s.function}
                    sub={`frame #${s.frame_id}`}
                    body={s.recommendation}
                  />
                )}
              </For>
            </Group>
            <Group title="Serial HTTP" empty="None.">
              <For each={d().serial_http}>
                {(s) => (
                  <Card
                    badge={`${s.call_count} calls · ${s.total_ms.toFixed(0)} ms`}
                    badgeTone="warn"
                    headline={s.function}
                    sub={`frame #${s.frame_id}`}
                    body={s.recommendation}
                  />
                )}
              </For>
            </Group>
            <Group title="Cache miss storms" empty="None.">
              <For each={d().cache_miss_storm}>
                {(s) => (
                  <Card
                    badge={`${s.miss_count} misses`}
                    badgeTone="warn"
                    headline={s.key}
                    body={s.recommendation}
                  />
                )}
              </For>
            </Group>
          </>
        )}
      </Show>
    </article>
  );
}

function Group(props: { title: string; empty: string; children: any }) {
  return (
    <section class="panel">
      <div class="panel-header"><span>{props.title}</span></div>
      <div class="p-3 space-y-2 empty:hidden">{props.children}</div>
    </section>
  );
}

function Card(props: {
  badge: string;
  badgeTone: "warn" | "danger" | "accent" | "neutral";
  headline: string;
  sub?: string;
  meta?: string;
  body?: string;
}) {
  const cls =
    props.badgeTone === "danger"
      ? "bg-rose-500/10 text-rose-300 ring-rose-500/30"
      : props.badgeTone === "warn"
        ? "bg-amber-500/10 text-amber-300 ring-amber-500/30"
        : props.badgeTone === "accent"
          ? "bg-accent/10 text-accent ring-accent/30"
          : "bg-ink-700 text-ink-200 ring-ink-600";
  return (
    <article class="rounded border border-ink-700/60 bg-ink-900/70 p-3 space-y-1">
      <div class="flex items-center justify-between">
        <span class={`pill ring-1 ring-inset ${cls}`}>{props.badge}</span>
        <Show when={props.meta}>
          <span class="text-[11px] mono text-ink-400">{props.meta}</span>
        </Show>
      </div>
      <div class="text-sm text-ink-100 mono break-words">{props.headline}</div>
      <Show when={props.sub}>
        <div class="text-[11px] text-ink-400 mono">{props.sub}</div>
      </Show>
      <Show when={props.body}>
        <p class="text-xs text-ink-300">{props.body}</p>
      </Show>
    </article>
  );
}

function baseName(p: string): string {
  return p.split("/").pop() ?? p;
}
