import { Show, createEffect, createSignal, onCleanup, onMount } from "solid-js";
import { Header } from "./components/Header";
import { Sidebar } from "./components/Sidebar";
import { TracesList } from "./components/TracesList";
import { SettingsView } from "./components/SettingsView";
import { TimelineScrubber } from "./components/TimelineScrubber";
import { Overview } from "./panels/Overview";
import { Source } from "./panels/Source";
import { Queries } from "./panels/Queries";
import { GenericEventList } from "./panels/GenericEventList";
import { InsightsPanel } from "./panels/Insights";
import { Performance } from "./panels/Performance";
import { Request } from "./panels/Request";
import { Response } from "./panels/Response";
import { isStaticMode, subscribeWs } from "./lib/api";
import {
  activeTab,
  bootstrapCursor,
  bootstrapSelection,
  cursorMicros,
  selectedTraceId,
  setCursorMicros,
  setTracesRefreshKey,
  trace,
  traces,
} from "./lib/store";
import { DropZone } from "./components/DropZone";

type LandingView = "traces" | "settings";

export function App() {
  const [landingView, setLandingView] = createSignal<LandingView>("traces");
  // Auto-pick newest trace once the list arrives.
  createEffect(() => {
    void traces();
    bootstrapSelection();
  });
  // Once the trace itself loads, park the cursor at the end.
  createEffect(() => {
    void trace();
    bootstrapCursor();
  });

  // Live ext-link: when the daemon notifies "request_finished", refresh
  // the trace list and (if user hasn't pinned one) jump to the new trace.
  onMount(() => {
    const dispose = subscribeWs((msg) => {
      if (typeof msg !== "object" || !msg) return;
      const m = msg as { type?: string; trace_id?: string; at_micros?: number };
      if (m.type === "request_finished") {
        // Always refresh the list so the new trace appears, but never auto-
        // jump into it: that would yank the user out of whatever trace
        // they're currently inspecting, or skip past the landing page.
        setTracesRefreshKey((k) => k + 1);
      } else if (m.type === "cursor_set" && typeof m.at_micros === "number") {
        // Another tab moved the timeline cursor on the same trace. Mirror
        // the move locally so multi-tab debugging stays in sync. Skip when
        // the cursor is already there — avoids a publish/echo feedback loop.
        if (m.trace_id && m.trace_id !== selectedTraceId()) return;
        if (Math.abs(m.at_micros - cursorMicros()) < 1) return;
        setCursorMicros(m.at_micros);
      }
    });
    onCleanup(dispose);
  });

  return (
    <div class="min-h-screen flex flex-col">
      <Header />
      <Show
        when={selectedTraceId()}
        fallback={
          <main class="px-4 py-6 flex-1 min-h-0 max-w-5xl w-full mx-auto space-y-3">
            <div class="flex items-center justify-end gap-3 text-[12px]">
              <button
                type="button"
                class={`mono tracking-wider uppercase text-[11px] transition-colors ${
                  landingView() === "traces" ? "text-accent" : "text-ink-400 hover:text-ink-200"
                }`}
                onClick={() => setLandingView("traces")}
              >
                traces
              </button>
              <span class="text-ink-700">·</span>
              <button
                type="button"
                class={`mono tracking-wider uppercase text-[11px] transition-colors ${
                  landingView() === "settings" ? "text-accent" : "text-ink-400 hover:text-ink-200"
                }`}
                onClick={() => setLandingView("settings")}
              >
                settings
              </button>
            </div>
            <Show when={landingView() === "traces"}>
              <Show when={traces().length > 0} fallback={<EmptyState />}>
                <TracesList />
              </Show>
            </Show>
            <Show when={landingView() === "settings"}>
              <SettingsView onBack={() => setLandingView("traces")} />
            </Show>
          </main>
        }
      >
        <main class="grid grid-cols-[260px_minmax(0,1fr)] gap-6 px-4 py-4 flex-1 min-h-0 pb-24">
          <Sidebar />
          <section class="min-w-0 space-y-4">
            <Show when={trace()} fallback={<div class="panel p-6 text-ink-400">Loading trace…</div>}>
              <TabContent />
            </Show>
          </section>
        </main>
        <TimelineScrubber />
      </Show>
      <Show when={!isStaticMode()}>
        <DropZone />
      </Show>
    </div>
  );
}

function TabContent() {
  return (
    <>
      <Show when={activeTab() === "overview"}>
        <Overview />
      </Show>
      <Show when={activeTab() === "source"}>
        <Source />
      </Show>
      <Show when={activeTab() === "queries"}>
        <Queries />
      </Show>
      <Show when={activeTab() === "models"}>
        <GenericEventList type="model" title="Models" empty="No model events captured." />
      </Show>
      <Show when={activeTab() === "logs"}>
        <GenericEventList type="log" title="Logs" empty="No log lines." />
      </Show>
      <Show when={activeTab() === "cache"}>
        <GenericEventList type="cache" title="Cache" empty="No cache operations." />
      </Show>
      <Show when={activeTab() === "jobs"}>
        <GenericEventList type="job" title="Jobs" empty="No jobs dispatched." />
      </Show>
      <Show when={activeTab() === "events"}>
        <GenericEventList type="event" title="Events" empty="No events fired." />
      </Show>
      <Show when={activeTab() === "http"}>
        <GenericEventList type="http" title="HTTP calls" empty="No outbound HTTP." />
      </Show>
      <Show when={activeTab() === "redis"}>
        <GenericEventList type="redis" title="Redis" empty="No Redis commands." />
      </Show>
      <Show when={activeTab() === "mail"}>
        <GenericEventList type="mail" title="Mail" empty="No mail sent." />
      </Show>
      <Show when={activeTab() === "notifications"}>
        <GenericEventList type="notification" title="Notifications" empty="No notifications." />
      </Show>
      <Show when={activeTab() === "exceptions"}>
        <GenericEventList type="exception" title="Exceptions" empty="No exceptions thrown." />
      </Show>
      <Show when={activeTab() === "dumps"}>
        <GenericEventList type="dump" title="Dumps" empty="No dump() / dd() calls captured. Set PERISCOPE_HOOK_DUMP=true and call dump() in your code." />
      </Show>
      <Show when={activeTab() === "insights"}>
        <InsightsPanel />
      </Show>
      <Show when={activeTab() === "performance"}>
        <Performance />
      </Show>
      <Show when={activeTab() === "request"}>
        <Request />
      </Show>
      <Show when={activeTab() === "response"}>
        <Response />
      </Show>
    </>
  );
}

function EmptyState() {
  return (
    <div class="panel p-10 text-center">
      <div class="text-ink-300 text-lg">no trace selected</div>
      <p class="mt-2 text-sm text-ink-400 max-w-md mx-auto">
        Trigger a request against your Laravel app with the periscope adapter
        installed, or drop a <span class="mono">.cptrace</span> /
        <span class="mono"> .json</span> file anywhere on this page.
      </p>
    </div>
  );
}
