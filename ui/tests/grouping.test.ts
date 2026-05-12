import { describe, expect, it } from "vitest";
import { groupEvents } from "../src/lib/grouping";
import type { EventJson } from "../src/lib/types";

function ev(id: number, at: number, type: string, payload: unknown): EventJson {
  return {
    id,
    at_micros: at,
    in_frame_id: 0,
    type,
    payload: payload as Record<string, unknown>,
  };
}

describe("groupEvents", () => {
  it("collapses same payload across different timestamps", () => {
    const groups = groupEvents([
      ev(1, 100, "log", { level: "error", message: "boom" }),
      ev(2, 250, "log", { level: "error", message: "boom" }),
    ]);
    expect(groups).toHaveLength(1);
    expect(groups[0]!.count).toBe(2);
    expect(groups[0]!.firstAtMicros).toBe(100);
    expect(groups[0]!.lastAtMicros).toBe(250);
    expect(groups[0]!.eventIds).toEqual([1, 2]);
  });

  it("keeps different variables as distinct rows", () => {
    const groups = groupEvents([
      ev(1, 100, "log", { message: "user 42 logged in" }),
      ev(2, 200, "log", { message: "user 43 logged in" }),
    ]);
    expect(groups).toHaveLength(2);
  });

  it("ignores object key order", () => {
    const groups = groupEvents([
      ev(1, 10, "sql", { sql: "select 1", connection: "mysql" }),
      ev(2, 20, "sql", { connection: "mysql", sql: "select 1" }),
    ]);
    expect(groups).toHaveLength(1);
  });

  it("strips time_ms before fingerprinting", () => {
    const groups = groupEvents([
      ev(1, 10, "sql", { sql: "select 1", time_ms: 5.2 }),
      ev(2, 20, "sql", { sql: "select 1", time_ms: 8.9 }),
    ]);
    expect(groups).toHaveLength(1);
  });

  it("treats different SQL bindings as different rows", () => {
    const groups = groupEvents([
      ev(1, 10, "sql", { sql: "select * from x where id=?", bindings: [1] }),
      ev(2, 20, "sql", { sql: "select * from x where id=?", bindings: [2] }),
    ]);
    expect(groups).toHaveLength(2);
  });

  it("preserves first-occurrence order", () => {
    const groups = groupEvents([
      ev(1, 10, "log", { message: "first" }),
      ev(2, 20, "log", { message: "second" }),
      ev(3, 30, "log", { message: "first" }),
    ]);
    expect(groups).toHaveLength(2);
    expect(groups[0]!.sample.payload).toEqual({ message: "first" });
    expect(groups[0]!.count).toBe(2);
    expect(groups[1]!.sample.payload).toEqual({ message: "second" });
  });
});
