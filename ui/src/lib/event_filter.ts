/**
 * Tiny JSON-path query language — TypeScript mirror of
 * `daemon/src/event_filter.rs`. Keep the two in sync.
 *
 * Grammar:
 *   expr   := clause ( AND clause )*
 *   clause := <path>:<literal>   # structured, dotted path walk
 *           | <bare-word>        # free-text substring on stringified event
 *
 *   literal := "..." (exact)   |   bareword (loose, case-insensitive substring,
 *                                  numeric equality on numbers)
 *
 * `parseFilter(expr)` returns either `{ ok: true, filter }` or
 * `{ ok: false, error }`. Callers render parse errors inline rather than
 * silently match-everything so the user knows their query was rejected.
 */

import type { EventJson } from "./types";

export type ParseResult =
  | { ok: true; filter: EventFilter }
  | { ok: false; error: string };

export interface EventFilter {
  isEmpty: boolean;
  matches: (e: EventJson) => boolean;
}

type Clause =
  | { kind: "path"; path: string[]; lit: Literal }
  | { kind: "free"; needle: string };

type Literal =
  | { exact: string }
  | { loose: string };

export function parseFilter(input: string): ParseResult {
  const trimmed = input.trim();
  if (trimmed.length === 0) {
    return { ok: true, filter: { isEmpty: true, matches: () => true } };
  }
  let parts: string[];
  try {
    parts = splitTopLevelAnd(trimmed);
  } catch (e) {
    return { ok: false, error: (e as Error).message };
  }
  const clauses: Clause[] = [];
  for (const raw of parts) {
    const s = raw.trim();
    if (s.length === 0) continue;
    try {
      clauses.push(parseClause(s));
    } catch (e) {
      return { ok: false, error: (e as Error).message };
    }
  }
  return {
    ok: true,
    filter: {
      isEmpty: clauses.length === 0,
      matches: (e) => clauses.every((c) => matchClause(c, e)),
    },
  };
}

function splitTopLevelAnd(s: string): string[] {
  const parts: string[] = [];
  let start = 0;
  let inQuote = false;
  let i = 0;
  while (i < s.length) {
    const c = s[i]!;
    if (c === '"') {
      inQuote = !inQuote;
      i += 1;
      continue;
    }
    if (!inQuote && i + 5 <= s.length) {
      const win = s.substring(i, i + 5);
      if (/^[ \t]AND[ \t]$/i.test(win)) {
        parts.push(s.substring(start, i));
        i += 5;
        start = i;
        continue;
      }
    }
    i += 1;
  }
  if (inQuote) throw new Error("unterminated quoted string");
  parts.push(s.substring(start));
  return parts;
}

function parseClause(raw: string): Clause {
  let inQuote = false;
  let colon = -1;
  for (let i = 0; i < raw.length; i += 1) {
    const c = raw[i];
    if (c === '"') inQuote = !inQuote;
    else if (c === ":" && !inQuote) {
      colon = i;
      break;
    }
  }
  if (colon === -1) {
    return { kind: "free", needle: unquoteOrPass(raw).toLowerCase() };
  }
  const key = raw.substring(0, colon).trim();
  const val = raw.substring(colon + 1).trim();
  if (key.length === 0) throw new Error(`empty key before ':' in \`${raw}\``);
  const path = key.split(".").map((seg) => seg.trim());
  for (const seg of path) {
    if (seg.length === 0) throw new Error(`empty path segment in \`${key}\``);
    if (!/^[A-Za-z0-9_-]+$/.test(seg)) {
      throw new Error(`invalid path segment \`${seg}\` in \`${key}\``);
    }
  }
  const lit: Literal =
    val.length >= 2 && val.startsWith('"') && val.endsWith('"')
      ? { exact: val.substring(1, val.length - 1) }
      : { loose: val };
  return { kind: "path", path, lit };
}

function unquoteOrPass(s: string): string {
  const t = s.trim();
  if (t.length >= 2 && t.startsWith('"') && t.endsWith('"')) {
    return t.substring(1, t.length - 1);
  }
  return t;
}

function matchClause(c: Clause, e: EventJson): boolean {
  if (c.kind === "free") {
    const hay = JSON.stringify(e).toLowerCase();
    return hay.includes(c.needle);
  }
  const v = lookup(e as unknown as Record<string, unknown>, c.path);
  if (v === undefined) return false;
  return matchLiteral(c.lit, v);
}

function lookup(root: unknown, path: string[]): unknown {
  let cur: unknown = root;
  for (const seg of path) {
    if (cur === null || typeof cur !== "object" || Array.isArray(cur)) return undefined;
    cur = (cur as Record<string, unknown>)[seg];
    if (cur === undefined) return undefined;
  }
  return cur;
}

function matchLiteral(lit: Literal, v: unknown): boolean {
  if ("exact" in lit) {
    return valueToString(v) === lit.exact;
  }
  const wantLower = lit.loose.toLowerCase();
  const got = valueToString(v).toLowerCase();
  if (got.includes(wantLower)) return true;
  const wantNum = Number(lit.loose);
  if (!Number.isNaN(wantNum)) {
    if (typeof v === "number") return v === wantNum;
    if (typeof v === "string") {
      const asNum = Number(v);
      if (!Number.isNaN(asNum)) return asNum === wantNum;
    }
  }
  return false;
}

function valueToString(v: unknown): string {
  if (v === null || v === undefined) return "";
  if (typeof v === "string") return v;
  if (typeof v === "number" || typeof v === "boolean") return String(v);
  return JSON.stringify(v);
}
