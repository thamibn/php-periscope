export const fmtMs = (us: number): string => {
  if (us < 1) return "0 ms";
  if (us < 1000) return `${us} µs`;
  if (us < 10_000) return `${(us / 1000).toFixed(2)} ms`;
  if (us < 1_000_000) return `${(us / 1000).toFixed(0)} ms`;
  return `${(us / 1_000_000).toFixed(2)} s`;
};

export const fmtBytes = (n: number): string => {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  if (n < 1024 * 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`;
  return `${(n / 1024 / 1024 / 1024).toFixed(2)} GB`;
};

export const relTime = (epochMicros: number): string => {
  const now = Date.now();
  const then = Math.floor(epochMicros / 1000);
  const diff = Math.max(0, now - then);
  if (diff < 5_000) return "just now";
  if (diff < 60_000) return `${Math.floor(diff / 1000)}s ago`;
  if (diff < 3_600_000) return `${Math.floor(diff / 60_000)}m ago`;
  if (diff < 86_400_000) return `${Math.floor(diff / 3_600_000)}h ago`;
  return new Date(then).toLocaleString();
};

export const truncate = (s: string, max = 80): string => (s.length > max ? `${s.slice(0, max)}…` : s);

export const statusTone = (code: number): "ok" | "warn" | "err" | "neutral" => {
  if (code === 0) return "neutral";
  if (code < 300) return "ok";
  if (code < 400) return "neutral";
  if (code < 500) return "warn";
  return "err";
};

export const safeJson = (v: unknown, indent = 2): string => {
  try {
    return JSON.stringify(v, null, indent);
  } catch {
    return String(v);
  }
};
