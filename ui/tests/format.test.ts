import { describe, expect, it } from "vitest";
import { fmtBytes, fmtMs, statusTone, truncate } from "../src/lib/format";

describe("fmtMs", () => {
  it("scales by magnitude", () => {
    expect(fmtMs(0)).toBe("0 ms");
    expect(fmtMs(900)).toBe("900 µs");
    expect(fmtMs(8_500)).toBe("8.50 ms");
    expect(fmtMs(412_000)).toBe("412 ms");
    expect(fmtMs(2_500_000)).toBe("2.50 s");
  });
});

describe("fmtBytes", () => {
  it("scales by magnitude", () => {
    expect(fmtBytes(800)).toBe("800 B");
    expect(fmtBytes(2048)).toBe("2.0 KB");
    expect(fmtBytes(20 * 1024 * 1024)).toBe("20.0 MB");
  });
});

describe("statusTone", () => {
  it("maps codes to tones", () => {
    expect(statusTone(0)).toBe("neutral");
    expect(statusTone(204)).toBe("ok");
    expect(statusTone(301)).toBe("neutral");
    expect(statusTone(404)).toBe("warn");
    expect(statusTone(500)).toBe("err");
  });
});

describe("truncate", () => {
  it("appends ellipsis past max", () => {
    expect(truncate("abcdef", 3)).toBe("abc…");
    expect(truncate("abc", 5)).toBe("abc");
  });
});
