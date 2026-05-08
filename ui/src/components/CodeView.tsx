import { For, Show } from "solid-js";
import { highlight } from "../lib/syntax";

interface Line {
  number: number;
  source: string;
}

export function CodeView(props: {
  lines: Line[];
  currentLine?: number;
  filename?: string;
  lang?: "php" | "sql";
  breakpoints?: Set<number>;
}) {
  const lang = () => props.lang ?? "php";
  return (
    <div class="code-pre">
      <div class="code-bar">
        <div class="flex items-center gap-1.5">
          <span class="dot bg-rose-400/70" />
          <span class="dot bg-amber-300/70" />
          <span class="dot bg-emerald-400/70" />
        </div>
        <Show when={props.filename}>
          <div class="mono truncate max-w-[60%]" title={props.filename}>{props.filename}</div>
        </Show>
        <div class="mono uppercase text-ink-400">{lang()}</div>
      </div>
      <div class="grid">
        <For each={props.lines}>
          {(l) => {
            const cur = props.currentLine === l.number;
            const bp = !!props.breakpoints?.has(l.number);
            const html = highlight(l.source || " ", lang());
            return (
              <div class={`code-row ${cur ? "is-current" : ""} ${bp ? "is-bp" : ""}`}>
                <span class="ln">{l.number}</span>
                {/* eslint-disable-next-line solid/no-innerhtml */}
                <span class="src" innerHTML={html} />
              </div>
            );
          }}
        </For>
      </div>
    </div>
  );
}
