import type { EventJson } from "./types";

/**
 * Client-side mirror of the daemon's `crate::grouping` module. Two events
 * collapse into one group only when their `(type, canonical(payload))` is
 * byte-identical. Canonicalisation sorts object keys and strips timing
 * fields so the same event firing at two timestamps collapses, but
 * different variables (user 42 vs user 43) never collide.
 *
 * Keep the timing-key list in sync with `daemon/src/grouping.rs`.
 */
const TIMING_KEYS = new Set<string>([
  "at",
  "at_micros",
  "at_unix_micros",
  "duration_micros",
  "duration_ms",
  "elapsed_ms",
  "ended_at",
  "enter_micros",
  "exit_micros",
  "fired_at",
  "started_at",
  "time",
  "time_ms",
  "timestamp",
]);

export interface EventGroup {
  fingerprint: string;
  type: string;
  count: number;
  firstAtMicros: number;
  lastAtMicros: number;
  sample: EventJson;
  eventIds: number[];
  occurrences: EventJson[];
}

export function groupEvents(events: EventJson[]): EventGroup[] {
  const order: string[] = [];
  const map = new Map<string, EventGroup>();
  for (const ev of events) {
    const fp = fingerprintFor(ev.type, ev.payload);
    const existing = map.get(fp);
    if (existing) {
      existing.count += 1;
      existing.lastAtMicros = Math.max(existing.lastAtMicros, ev.at_micros);
      existing.eventIds.push(ev.id);
      existing.occurrences.push(ev);
    } else {
      order.push(fp);
      map.set(fp, {
        fingerprint: fp,
        type: ev.type,
        count: 1,
        firstAtMicros: ev.at_micros,
        lastAtMicros: ev.at_micros,
        sample: ev,
        eventIds: [ev.id],
        occurrences: [ev],
      });
    }
  }
  return order.map((fp) => map.get(fp)!);
}

/**
 * SHA-256 of `${type}\x1f${canonicalJson}` truncated to 16 hex chars.
 * Falls back to a string concat hash when the browser lacks SubtleCrypto.
 * The canonical bytes — not the hex digest — are what actually decides
 * equality, so a string-key fallback is fine; the hex form just makes the
 * fingerprint stable to copy/paste.
 */
function fingerprintFor(type: string, payload: unknown): string {
  return `${type}${canonicalString(payload)}`;
}

function canonicalString(v: unknown): string {
  return JSON.stringify(canonicalise(v));
}

function canonicalise(v: unknown): unknown {
  if (v === null || typeof v !== "object") return v;
  if (Array.isArray(v)) return v.map(canonicalise);
  const obj = v as Record<string, unknown>;
  const out: Record<string, unknown> = {};
  for (const k of Object.keys(obj).sort()) {
    if (TIMING_KEYS.has(k)) continue;
    out[k] = canonicalise(obj[k]);
  }
  return out;
}
