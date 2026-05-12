/* periscope floating toolbar — Phase 9b
 *
 * A tiny chip in the page corner showing the current request's duration,
 * query count, peak memory, and status. Click opens the UI for that trace.
 *
 * The PHP middleware injects a `<script>` tag with `window.__PERISCOPE_TB__`
 * pre-populated. We read that and render. No build step, no external deps.
 *
 * Layout note: this lives inside the host app's page DOM, so we keep our
 * styles inline and namespaced under `data-periscope-toolbar` to avoid
 * stomping the host's CSS or React-style class collisions.
 */
(function () {
  if (typeof window === "undefined" || typeof document === "undefined") return;
  if (window.__PERISCOPE_TB_INSTALLED__) return;
  window.__PERISCOPE_TB_INSTALLED__ = true;

  var data = window.__PERISCOPE_TB__ || {};
  if (data.disabled) return;

  function fmtMs(us) {
    var ms = (us || 0) / 1000;
    if (ms < 1) return "<1 ms";
    if (ms < 1000) return Math.round(ms) + " ms";
    return (ms / 1000).toFixed(2) + " s";
  }

  function fmtBytes(b) {
    if (!b || b < 1024) return (b || 0) + " B";
    var kb = b / 1024;
    if (kb < 1024) return Math.round(kb) + " KB";
    return (kb / 1024).toFixed(1) + " MB";
  }

  function tone(status, exceptions) {
    if (exceptions > 0 || status >= 500) return "danger";
    if (status >= 400) return "warn";
    if (status >= 300) return "muted";
    return "ok";
  }

  var TONE = {
    ok:     { bg: "#10151c", border: "#1e7a4b", text: "#a8e6c1" },
    warn:   { bg: "#1a1610", border: "#a87223", text: "#f3c777" },
    danger: { bg: "#1a1014", border: "#9a3146", text: "#ff9aae" },
    muted:  { bg: "#10151c", border: "#2a3140", text: "#b1b9c9" }
  };

  var t = TONE[tone(data.status || 0, data.exceptions || 0)];

  var bar = document.createElement("div");
  bar.setAttribute("data-periscope-toolbar", "");
  bar.style.cssText = [
    "position:fixed","right:14px","bottom:14px","z-index:2147483646",
    "display:flex","align-items:center","gap:10px",
    "padding:6px 10px",
    "background:" + t.bg, "color:" + t.text,
    "border:1px solid " + t.border, "border-radius:6px",
    "font:500 11.5px/1.2 ui-monospace,SFMono-Regular,Menlo,monospace",
    "box-shadow:0 4px 16px rgba(0,0,0,.4)",
    "cursor:pointer", "user-select:none",
    "transition:transform .12s ease"
  ].join(";");
  bar.title = "open in periscope";

  function chip(label, value) {
    var s = document.createElement("span");
    s.style.cssText = "display:inline-flex;align-items:center;gap:5px";
    var k = document.createElement("span");
    k.textContent = label;
    k.style.cssText = "color:#7a8499;text-transform:uppercase;letter-spacing:.06em;font-size:9.5px";
    var v = document.createElement("span");
    v.textContent = value;
    s.appendChild(k);
    s.appendChild(v);
    return s;
  }

  // Always-on chips first.
  bar.appendChild(chip("dur", fmtMs(data.duration_us)));
  bar.appendChild(chip("mem", fmtBytes(data.peak_memory_bytes)));
  bar.appendChild(chip("sql", String(data.queries || 0)));
  if ((data.exceptions || 0) > 0) bar.appendChild(chip("err", String(data.exceptions)));
  bar.appendChild(chip("status", String(data.status || "?")));

  // Logo dot.
  var dot = document.createElement("span");
  dot.style.cssText = "width:6px;height:6px;border-radius:50%;background:" + t.border;
  bar.insertBefore(dot, bar.firstChild);

  bar.addEventListener("mouseenter", function () {
    bar.style.transform = "translateY(-1px)";
  });
  bar.addEventListener("mouseleave", function () {
    bar.style.transform = "";
  });
  bar.addEventListener("click", function () {
    var url = data.open_url;
    if (!url) return;
    window.open(url, "_blank", "noopener");
  });

  function mount() {
    if (document.body) document.body.appendChild(bar);
    else document.addEventListener("DOMContentLoaded", function () {
      if (document.body) document.body.appendChild(bar);
    });
  }
  mount();

  // ---- Web Vitals + navigation timing ---------------------------------
  //
  // No external `web-vitals` package — we use PerformanceObserver directly
  // for LCP / CLS / FCP / INP, and the Navigation Timing API for TTFB and
  // wall-clock phases. Each metric updates on its own timeline; we POST
  // the snapshot once at `pagehide` (or `visibilitychange:hidden` on iOS,
  // since pagehide isn't reliable there).
  //
  // We don't run unless the page knows where to send the data: the PHP
  // middleware passes a `metrics_endpoint` URL — empty means "skip."

  if (!data.metrics_endpoint) return;

  var vitals = { lcp: null, cls: 0, fcp: null, inp: null };

  function safeObserve(type, cb) {
    try {
      var po = new PerformanceObserver(function (list) {
        list.getEntries().forEach(cb);
      });
      po.observe({ type: type, buffered: true });
    } catch (_) { /* unsupported entry type */ }
  }

  // LCP — keep updating; the latest entry wins until the page hides.
  safeObserve("largest-contentful-paint", function (e) { vitals.lcp = e.startTime; });
  // CLS — sum of session-window layout-shift values that aren't input-driven.
  safeObserve("layout-shift", function (e) {
    if (!e.hadRecentInput) vitals.cls += e.value || 0;
  });
  // FCP — first contentful paint.
  safeObserve("paint", function (e) {
    if (e.name === "first-contentful-paint") vitals.fcp = e.startTime;
  });
  // INP — Interaction to Next Paint, max of all interactions.
  safeObserve("event", function (e) {
    if (typeof e.duration === "number" && (vitals.inp == null || e.duration > vitals.inp)) {
      vitals.inp = e.duration;
    }
  });

  function navTiming() {
    try {
      var nav = performance.getEntriesByType("navigation")[0];
      if (!nav) return null;
      return {
        ttfb_ms:                 Math.max(0, nav.responseStart - nav.requestStart),
        dom_content_loaded_ms:   Math.max(0, nav.domContentLoadedEventEnd - nav.startTime),
        load_event_ms:           Math.max(0, nav.loadEventEnd - nav.startTime),
        transfer_size_bytes:     nav.transferSize || 0,
        decoded_body_size_bytes: nav.decodedBodySize || 0,
        type:                    nav.type
      };
    } catch (_) { return null; }
  }

  var sent = false;
  function sendMetrics() {
    if (sent) return;
    sent = true;

    var body = {
      pid:                    data.pid || 0,
      started_at_unix_micros: data.started_at_unix_micros || 0,
      uri:                    location.pathname + location.search,
      navigation:             navTiming(),
      vitals: {
        lcp_ms: vitals.lcp == null ? null : Math.round(vitals.lcp),
        cls:    Math.round((vitals.cls || 0) * 1000) / 1000,
        fcp_ms: vitals.fcp == null ? null : Math.round(vitals.fcp),
        inp_ms: vitals.inp == null ? null : Math.round(vitals.inp)
      }
    };

    var json = JSON.stringify(body);
    // Use sendBeacon when available — it survives unload, where fetch may not.
    if (navigator.sendBeacon) {
      try {
        var blob = new Blob([json], { type: "application/json" });
        if (navigator.sendBeacon(data.metrics_endpoint, blob)) return;
      } catch (_) { /* fall through */ }
    }
    try {
      fetch(data.metrics_endpoint, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: json,
        keepalive: true
      }).catch(function () { /* swallow */ });
    } catch (_) { /* swallow */ }
  }

  // Fire on `visibilitychange:hidden` so iOS Safari ships too. `pagehide`
  // catches BFCache cases on other browsers.
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") sendMetrics();
  });
  window.addEventListener("pagehide", sendMetrics);
})();
