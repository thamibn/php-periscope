import { For, Show, createMemo, createResource } from "solid-js";
import { CodeView } from "../components/CodeView";
import { state, trace } from "../lib/store";
import { api, isStaticMode } from "../lib/api";
import { fmtMs, truncate } from "../lib/format";

export function Source() {
  const cur = () => state()?.current_frame ?? null;

  // Pull a 50-line slice of the source file the cursor is in.
  const [slice] = createResource(
    () => {
      const c = cur();
      return c ? { file: c.file, line: c.line } : null;
    },
    async (key) => {
      if (!key || !key.file || isStaticMode()) return null;
      try {
        return await api.readFile(key.file, key.line, 24);
      } catch {
        return null;
      }
    },
  );

  // Fallback: when /api/file is unavailable (static mode, missing file),
  // show the snippet from the call_site of the most recent event in this frame.
  const fallbackLines = createMemo(() => {
    const c = cur();
    if (!c) return [];
    const events = trace()?.observability_events ?? [];
    const recent = events
      .slice()
      .reverse()
      .find((e) => e.in_frame_id === c.id && e.user_call_site);
    return recent?.user_call_site?.snippet ?? [];
  });

  return (
    <article class="panel">
      <div class="panel-header">
        <Show when={cur()} fallback={<span>Source</span>}>
          {(c) => (
            <div class="flex items-center gap-2 normal-case">
              <span class="text-ink-100">Source</span>
              <span class="mono text-ink-400 truncate max-w-[60ch]" title={c().file}>
                {c().file}
              </span>
              <span class="text-ink-500">:</span>
              <span class="mono text-accent">{c().line}</span>
            </div>
          )}
        </Show>
        <Show when={cur()}>
          {(c) => (
            <div class="flex items-center gap-2 text-[11px] text-ink-400 normal-case">
              <span class="mono">frame #{c().id}</span>
              <span>·</span>
              <span class="mono">{c().function}</span>
              <span>·</span>
              <span class="mono">{fmtMs(c().duration_micros)}</span>
            </div>
          )}
        </Show>
      </div>
      <div class="p-3 grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-3">
        <div>
          <Show
            when={(slice()?.lines.length ?? 0) > 0}
            fallback={
              <Show
                when={fallbackLines().length > 0}
                fallback={
                  <div class="text-sm text-ink-400 p-6 text-center">
                    No source available. The frame's file may be outside the project root.
                  </div>
                }
              >
                <CodeView
                  lines={fallbackLines()}
                  filename={cur()?.file}
                  currentLine={cur()?.line}
                  lang="php"
                />
              </Show>
            }
          >
            <CodeView
              lines={slice()!.lines}
              filename={slice()!.path}
              currentLine={cur()?.line}
              lang="php"
            />
          </Show>
        </div>

        <div class="space-y-3 min-w-0">
          <div>
            <div class="text-[11px] uppercase tracking-wider text-ink-400 mb-1.5">Call stack</div>
            <ol class="space-y-0.5 text-[12px] mono">
              <For each={state()?.stack ?? []}>
                {(f) => (
                  <li class="flex justify-between row-hover px-2 py-1 rounded gap-2">
                    <span class="text-ink-100 truncate" title={f.function}>{f.function}</span>
                    <span class="text-ink-400 truncate text-right" title={`${f.file}:${f.line}`}>
                      {truncate(`${baseName(f.file)}:${f.line}`, 30)}
                    </span>
                  </li>
                )}
              </For>
            </ol>
          </div>
          <div>
            <div class="text-[11px] uppercase tracking-wider text-ink-400 mb-1.5">Scope</div>
            <Show
              when={(state()?.scope_variables.length ?? 0) > 0}
              fallback={<div class="text-xs text-ink-400 px-2">no captured variables</div>}
            >
              <ul class="space-y-0.5 text-[12px] mono">
                <For each={state()?.scope_variables ?? []}>
                  {(v) => (
                    <li class="row-hover px-2 py-1 rounded">
                      <div class="flex justify-between gap-2">
                        <span class="text-ink-300">{v.name}</span>
                        <span class="text-ink-500">{v.kind}</span>
                      </div>
                      <pre class="mt-0.5 whitespace-pre-wrap break-all text-ink-200">{v.value}</pre>
                    </li>
                  )}
                </For>
              </ul>
            </Show>
          </div>
        </div>
      </div>
    </article>
  );
}

function baseName(p: string): string {
  return p.split("/").pop() ?? p;
}
