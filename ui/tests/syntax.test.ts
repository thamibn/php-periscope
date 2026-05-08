import { describe, expect, it } from "vitest";
import { highlight } from "../src/lib/syntax";

describe("highlight(php)", () => {
  it("tags keywords, strings, numbers, variables", () => {
    const html = highlight("public function show(User $user): JsonResponse", "php");
    expect(html).toContain('class="tok-kw"');
    expect(html).toContain('class="tok-fn"');
    expect(html).toContain('class="tok-cls"');
    expect(html).toContain('class="tok-var"');
  });
  it("tags single-line comments", () => {
    const html = highlight("// hello", "php");
    expect(html).toContain('class="tok-cmt"');
  });
  it("escapes HTML entities", () => {
    const html = highlight("$x = '<script>'", "php");
    expect(html).toContain("&lt;script&gt;");
    expect(html).not.toContain("<script>");
  });
});

describe("highlight(sql)", () => {
  it("highlights SQL keywords case-insensitively", () => {
    const html = highlight("SELECT * FROM users WHERE id = 1", "sql");
    expect(html).toContain('<span class="tok-kw">SELECT</span>');
    expect(html).toContain('<span class="tok-kw">FROM</span>');
    expect(html).toContain('<span class="tok-num">1</span>');
  });
});
