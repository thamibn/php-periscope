import { For, Show } from "solid-js";
import { trace } from "../lib/store";
import { fmtBytes } from "../lib/format";
import type { HeaderJson } from "../lib/types";

export function Request() {
  const r = () => trace()?.meta.request;
  return (
    <div class="space-y-3">
      <Show when={r()} fallback={<div class="panel p-6 text-ink-400">No request envelope (CLI?).</div>}>
        {(req) => (
          <>
            <article class="panel p-4 space-y-1 mono text-sm">
              <div class="flex items-center gap-2">
                <span class="pill bg-ink-800 text-ink-200 ring-1 ring-inset ring-ink-700">{req().method}</span>
                <span class="text-ink-100">{req().uri}</span>
              </div>
              <div class="text-xs text-ink-400">{req().scheme} · from {req().remote_addr}</div>
              <div class="text-xs text-ink-400">
                body: {fmtBytes(req().total_body_bytes)} {req().body_truncated ? "(truncated)" : ""}
              </div>
            </article>
            <HeaderTable title="Headers" rows={req().headers} />
            <HeaderTable title="Cookies" rows={req().cookies} />
            <HeaderTable title="Query string" rows={req().query} />
            <HeaderTable title="POST params" rows={req().post_params} />
          </>
        )}
      </Show>
    </div>
  );
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
