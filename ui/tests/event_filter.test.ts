import { describe, expect, it } from "vitest";
import { parseFilter } from "../src/lib/event_filter";
import type { EventJson } from "../src/lib/types";

function ev(type: string, payload: unknown): EventJson {
  return {
    id: 1,
    at_micros: 0,
    in_frame_id: 0,
    type,
    payload: payload as Record<string, unknown>,
  };
}

function mustParse(expr: string) {
  const r = parseFilter(expr);
  if (!r.ok) throw new Error(`parse failed for \`${expr}\`: ${r.error}`);
  return r.filter;
}

describe("parseFilter", () => {
  it("empty input matches everything", () => {
    const f = mustParse("");
    expect(f.isEmpty).toBe(true);
    expect(f.matches(ev("log", { x: 1 }))).toBe(true);
  });

  it("path:value matches when nested key equals", () => {
    const f = mustParse("payload.level:error");
    expect(f.matches(ev("log", { level: "error" }))).toBe(true);
    expect(f.matches(ev("log", { level: "info" }))).toBe(false);
  });

  it("loose value is case-insensitive substring", () => {
    const f = mustParse("payload.message:REFUSED");
    expect(f.matches(ev("log", { message: "connection refused" }))).toBe(true);
  });

  it("quoted value is exact", () => {
    const f = mustParse(`payload.message:"connection refused"`);
    expect(f.matches(ev("log", { message: "connection refused" }))).toBe(true);
    expect(f.matches(ev("log", { message: "CONNECTION REFUSED" }))).toBe(false);
  });

  it("conjunction with AND", () => {
    const f = mustParse("payload.level:error AND payload.context.user_id:42");
    expect(
      f.matches(ev("log", { level: "error", context: { user_id: 42 } })),
    ).toBe(true);
    expect(
      f.matches(ev("log", { level: "error", context: { user_id: 1 } })),
    ).toBe(false);
  });

  it("AND inside a quoted value is preserved", () => {
    const f = mustParse(`payload.message:"foo AND bar"`);
    expect(f.matches(ev("log", { message: "foo AND bar" }))).toBe(true);
  });

  it("free text matches substring of stringified event", () => {
    const f = mustParse("refused");
    expect(f.matches(ev("log", { message: "connection refused" }))).toBe(true);
    expect(f.matches(ev("log", { message: "ok" }))).toBe(false);
  });

  it("numeric loose match", () => {
    const f = mustParse("payload.user_id:42");
    expect(f.matches(ev("log", { user_id: 42 }))).toBe(true);
    expect(f.matches(ev("log", { user_id: "42" }))).toBe(true);
    expect(f.matches(ev("log", { user_id: 43 }))).toBe(false);
  });

  it("unterminated quote reports error", () => {
    const r = parseFilter(`payload.message:"oops`);
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.error).toMatch(/unterminated/i);
  });

  it("invalid path segment reports error", () => {
    const r = parseFilter(`payload.bad seg:value`);
    expect(r.ok).toBe(false);
  });
});
