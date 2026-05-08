import { For, Show } from "solid-js";
import { insights, summary, trace } from "../lib/store";
import { fmtBytes, fmtMs } from "../lib/format";

export function Overview() {
  return (
    <div class="space-y-4">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <Stat label="Duration" value={() => fmtMs(summary()?.duration_micros ?? trace()?.meta.duration_micros ?? 0)} />
        <Stat
          label="Queries"
          value={() => String(summary()?.queries.count ?? 0)}
          tone={() => ((summary()?.queries.slow_count ?? 0) > 0 ? "warn" : "neutral")}
          extra={() => `${summary()?.queries.total_ms.toFixed(1) ?? "0"} ms`}
        />
        <Stat
          label="Cache"
          value={() => String((summary()?.cache.hits ?? 0) + (summary()?.cache.misses ?? 0))}
          extra={() => `${summary()?.cache.hits ?? 0} hit · ${summary()?.cache.misses ?? 0} miss`}
        />
        <Stat
          label="Peak memory"
          value={() => fmtBytes(summary()?.response.peak_memory_bytes ?? 0)}
        />
        <Stat label="Frames" value={() => String(summary()?.frame_count ?? trace()?.frames.length ?? 0)} />
        <Stat label="Events" value={() => String(summary()?.event_count ?? trace()?.observability_events.length ?? 0)} />
        <Stat label="Jobs" value={() => String(summary()?.jobs.count ?? 0)} />
        <Stat
          label="Exceptions"
          value={() => String(summary()?.exceptions.count ?? 0)}
          tone={() => ((summary()?.exceptions.count ?? 0) > 0 ? "danger" : "neutral")}
        />
      </div>

      <Show when={(insights()?.n_plus_one.length ?? 0) > 0 || (insights()?.slow_queries.length ?? 0) > 0 || (insights()?.slow_frames.length ?? 0) > 0}>
        <section class="panel">
          <div class="panel-header"><span>Top insights</span></div>
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 p-3">
            <For each={insights()?.n_plus_one.slice(0, 1) ?? []}>
              {(p) => (
                <article class="rounded border border-ink-700/60 bg-ink-900/70 p-3">
                  <div class="text-[11px] uppercase tracking-wider text-warn">N+1 detected</div>
                  <p class="mt-2 text-sm text-ink-100 mono break-words">{p.pattern}</p>
                  <p class="mt-1 text-xs text-ink-300">Fired {p.count}× — {p.recommendation}</p>
                </article>
              )}
            </For>
            <For each={insights()?.slow_queries.slice(0, 1) ?? []}>
              {(s) => (
                <article class="rounded border border-ink-700/60 bg-ink-900/70 p-3">
                  <div class="text-[11px] uppercase tracking-wider text-warn">Slow query · {s.time_ms.toFixed(0)} ms</div>
                  <p class="mt-2 text-sm text-ink-100 mono break-words">{s.sql}</p>
                  <p class="mt-1 text-xs text-ink-300">{s.recommendation}</p>
                </article>
              )}
            </For>
            <For each={insights()?.slow_frames.slice(0, 1) ?? []}>
              {(s) => (
                <article class="rounded border border-ink-700/60 bg-ink-900/70 p-3">
                  <div class="text-[11px] uppercase tracking-wider text-warn">Slow frame · {fmtMs(s.duration_micros)}</div>
                  <p class="mt-2 text-sm text-ink-100 mono break-words">{s.function}</p>
                  <p class="mt-1 text-xs text-ink-300">{s.recommendation}</p>
                </article>
              )}
            </For>
          </div>
        </section>
      </Show>

      <Show when={trace()}>
        {(t) => (
          <section class="panel p-4 grid grid-cols-2 lg:grid-cols-4 gap-3 text-[12px] mono">
            <KV k="php" v={t().meta.php_version} />
            <KV k="sapi" v={t().meta.sapi} />
            <KV k="periscope" v={t().meta.periscope_version} />
            <KV k="host" v={t().meta.hostname} />
            <KV k="pid" v={String(t().meta.pid)} />
            <KV k="entry" v={baseName(t().meta.entry_point)} />
            <KV k="cwd" v={t().meta.working_dir} />
            <KV k="started" v={new Date(Math.floor(t().meta.started_at_unix_micros / 1000)).toLocaleString()} />
          </section>
        )}
      </Show>
    </div>
  );
}

function Stat(props: {
  label: string;
  value: () => string;
  extra?: () => string;
  tone?: () => "warn" | "danger" | "neutral";
}) {
  const tone = () => props.tone?.() ?? "neutral";
  const cls = () =>
    tone() === "danger" ? "text-danger" : tone() === "warn" ? "text-warn" : "text-ink-100";
  return (
    <article class="panel p-3">
      <div class="text-[11px] uppercase tracking-wider text-ink-400">{props.label}</div>
      <div class={`mt-1 text-2xl font-semibold mono ${cls()}`}>{props.value()}</div>
      <Show when={props.extra}>
        <div class="text-[11px] mono text-ink-400">{props.extra!()}</div>
      </Show>
    </article>
  );
}

function KV(props: { k: string; v: string }) {
  return (
    <div class="flex gap-2">
      <span class="text-ink-400 w-20 shrink-0">{props.k}</span>
      <span class="text-ink-200 truncate" title={props.v}>{props.v}</span>
    </div>
  );
}

function baseName(p: string): string {
  return p.split("/").pop() ?? p;
}
