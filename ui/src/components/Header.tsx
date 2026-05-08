import { Show } from "solid-js";
import { trace, summary } from "../lib/store";
import { fmtBytes, fmtMs, statusTone } from "../lib/format";
import { daemonLabel, isStaticMode } from "../lib/api";

export function Header() {
  return (
    <header class="sticky top-0 z-30 border-b border-ink-700/60 bg-ink-950/80 backdrop-blur">
      <div class="flex items-center gap-3 px-4 py-2.5">
        <div class="flex items-center gap-2">
          <div class="grid place-items-center w-8 h-8 rounded-lg bg-gradient-to-br from-accent to-purple text-ink-950 font-bold">
            P
          </div>
          <div class="leading-tight">
            <div class="text-sm font-semibold">periscope</div>
            <div class="text-[10.5px] text-ink-400 mono">
              {isStaticMode() ? "static export" : daemonLabel()}
            </div>
          </div>
        </div>
        <div class="h-6 w-px bg-ink-700 mx-2" />

        <Show when={trace()} fallback={<span class="text-ink-400 text-sm mono">no trace</span>}>
          {(t) => {
            const meta = () => t().meta;
            const status = () => meta().response?.status_code ?? 0;
            return (
              <div class="flex items-center gap-2 mono text-[12.5px]">
                <StatusPill code={status()} />
                <Show when={meta().request}>
                  {(r) => (
                    <>
                      <span class="pill bg-ink-800 text-ink-200 ring-1 ring-inset ring-ink-700">
                        {r().method}
                      </span>
                      <span class="text-ink-100 truncate max-w-[40ch]" title={r().uri}>
                        {r().uri}
                      </span>
                    </>
                  )}
                </Show>
                <span class="text-ink-400">·</span>
                <span class="text-ink-300">{meta().hostname}</span>
              </div>
            );
          }}
        </Show>

        <div class="ml-auto flex items-center gap-2">
          <Show when={summary()}>
            {(s) => (
              <>
                <Chip label="duration" value={fmtMs(s().duration_micros)} />
                <Chip
                  label="queries"
                  value={String(s().queries.count)}
                  tone={s().queries.slow_count + s().queries.n_plus_one_count > 0 ? "warn" : "neutral"}
                />
                <Chip label="mem" value={fmtBytes(s().response.peak_memory_bytes || 0)} />
              </>
            )}
          </Show>
          <Show when={trace()}>
            <Chip label="php" value={trace()!.meta.php_version} />
          </Show>
          <button class="chip">Rerun</button>
          <button class="chip">Export ▾</button>
        </div>
      </div>
    </header>
  );
}

function StatusPill(props: { code: number }) {
  const tone = () => statusTone(props.code);
  const cls = () =>
    ({
      ok: "bg-emerald-500/10 text-emerald-300 ring-emerald-500/30",
      warn: "bg-amber-500/10 text-amber-300 ring-amber-500/30",
      err: "bg-rose-500/10 text-rose-300 ring-rose-500/30",
      neutral: "bg-ink-800 text-ink-200 ring-ink-700",
    }[tone()]);
  return (
    <span class={`pill ring-1 ring-inset ${cls()}`}>{props.code === 0 ? "—" : props.code}</span>
  );
}

function Chip(props: { label: string; value: string; tone?: "warn" | "ok" | "err" | "neutral" }) {
  const valCls =
    props.tone === "warn"
      ? "text-warn"
      : props.tone === "err"
        ? "text-danger"
        : props.tone === "ok"
          ? "text-success"
          : "text-ink-100";
  return (
    <span class="chip">
      <span class="text-ink-400">{props.label}</span>
      <span class={valCls}>{props.value}</span>
    </span>
  );
}
