import { For, Show, createResource } from "solid-js";
import { getMountedJson } from "../lib/api";

interface SettingsResponse {
  environment: Record<string, string | number | boolean>;
  engine: Record<string, unknown>;
  framework: Record<string, unknown>;
}

/**
 * Read-only configuration view: shows the merged engine + framework state
 * so users can see how their tool is configured without grepping
 * `99-periscope.ini` and `config/periscope.php` separately.
 *
 * Sourced from the adapter at `/{mount}/api/settings`. Sensitive values
 * (UI token) are redacted server-side.
 */
export function SettingsView(props: { onBack: () => void }) {
  const [data] = createResource(() => getMountedJson<SettingsResponse>("api/settings"));

  return (
    <article class="panel">
      <div class="panel-header">
        <span class="flex items-center gap-2">
          Settings
          <span class="mono normal-case text-ink-400">read-only</span>
        </span>
        <button
          type="button"
          class="text-[11px] mono tracking-wider text-ink-400 hover:text-accent uppercase normal-case"
          onClick={props.onBack}
        >
          ← back to traces
        </button>
      </div>

      <Show
        when={!data.loading}
        fallback={<div class="px-4 py-10 text-center text-sm text-ink-400">loading…</div>}
      >
        <Show
          when={data()}
          fallback={
            <div class="px-4 py-10 text-center text-sm text-ink-400">
              <p>Settings endpoint not reachable.</p>
              <p class="mt-2 text-[12px]">
                Available only when the UI is mounted via the Laravel adapter (i.e. at <span class="mono">/periscope</span>).
                When loading directly from the daemon at <span class="mono">:9999</span>, the adapter's
                settings API isn't on the same origin.
              </p>
            </div>
          }
        >
          {(d) => (
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-6 px-5 py-4">
              <Section title="Environment" rows={d().environment} />
              <Section title="Engine (php.ini)" rows={d().engine} hint="Edit /opt/homebrew/etc/php/8.3/conf.d/99-periscope.ini and reload PHP-FPM." />
              <Section title="Framework (config/periscope.php)" rows={flatten(d().framework, "")} hint="Edit your app's .env / config/periscope.php." />
            </div>
          )}
        </Show>
      </Show>
    </article>
  );
}

function Section(props: {
  title: string;
  rows: Record<string, unknown>;
  hint?: string;
}) {
  const entries = () => Object.entries(props.rows);
  return (
    <section class="space-y-1.5 min-w-0">
      <h3 class="text-[10px] tracking-[0.18em] text-ink-500 uppercase mono">{props.title}</h3>
      <Show when={props.hint}>
        <p class="text-[11px] text-ink-500 italic">{props.hint}</p>
      </Show>
      <dl class="space-y-0.5">
        <For each={entries()}>
          {([k, v]) => (
            <Row label={k} value={v} />
          )}
        </For>
      </dl>
    </section>
  );
}

function Row(props: { label: string; value: unknown }) {
  return (
    <div class="flex items-baseline gap-2 text-[12.5px] py-0.5 min-w-0">
      <dt class="text-ink-400 normal-case shrink-0 max-w-[14rem] truncate" title={props.label}>
        {props.label}
      </dt>
      <span class="flex-1 border-b border-dotted border-ink-700/50" aria-hidden="true" />
      <dd class={`shrink-0 text-right max-w-[60%] ${valueColor(props.value)}`}>
        <ValueRender value={props.value} />
      </dd>
    </div>
  );
}

function ValueRender(props: { value: unknown }) {
  const v = props.value;
  if (typeof v === "boolean") {
    return <span class={`pill ring-1 ring-inset normal-case mono ${v ? "bg-emerald-500/10 text-emerald-300 ring-emerald-500/30" : "bg-ink-700 text-ink-400 ring-ink-600"}`}>
      {v ? "true" : "false"}
    </span>;
  }
  if (Array.isArray(v)) {
    if (v.length === 0) return <span class="text-ink-500 mono italic">empty</span>;
    return (
      <span class="flex flex-wrap gap-1 justify-end">
        <For each={v}>
          {(item) => (
            <span class="pill mono normal-case bg-ink-800 text-ink-200 ring-1 ring-inset ring-ink-700">
              {String(item)}
            </span>
          )}
        </For>
      </span>
    );
  }
  if (v === null || v === undefined || v === "") {
    return <span class="text-ink-500 mono italic">unset</span>;
  }
  if (typeof v === "object") {
    return <span class="mono text-[11px] text-ink-300 break-words">{JSON.stringify(v)}</span>;
  }
  return <span class="mono text-ink-200 truncate inline-block max-w-full">{String(v)}</span>;
}

function valueColor(v: unknown): string {
  return typeof v === "object" ? "" : "text-ink-200";
}

/**
 * Flatten a nested object into dot-notation keys so the framework config
 * (which has `hooks.queries`, `ui.path`, etc.) renders as a flat list of
 * dotted-leader rows instead of unfriendly nested cards.
 */
function flatten(obj: Record<string, unknown>, prefix: string): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(obj)) {
    const key = prefix ? `${prefix}.${k}` : k;
    if (v !== null && typeof v === "object" && !Array.isArray(v)) {
      Object.assign(out, flatten(v as Record<string, unknown>, key));
    } else {
      out[key] = v;
    }
  }
  return out;
}
