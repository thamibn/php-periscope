// Tiny, dependency-free PHP/SQL highlighter. Good enough to make traces
// scan fast — not a full lexer. Token classes match the .tok-* CSS in styles.css.

const PHP_KEYWORDS = new Set([
  "abstract", "and", "array", "as", "break", "callable", "case", "catch", "class",
  "clone", "const", "continue", "declare", "default", "do", "echo", "else", "elseif",
  "empty", "enddeclare", "endfor", "endforeach", "endif", "endswitch", "endwhile",
  "enum", "extends", "final", "finally", "fn", "for", "foreach", "function", "global",
  "goto", "if", "implements", "include", "include_once", "instanceof", "insteadof",
  "interface", "isset", "list", "match", "namespace", "new", "null", "or", "print",
  "private", "protected", "public", "readonly", "require", "require_once", "return",
  "self", "static", "switch", "this", "throw", "trait", "true", "try", "unset", "use",
  "var", "while", "xor", "yield", "false", "void", "int", "string", "bool", "float",
  "mixed", "never", "object", "parent", "false", "true", "null",
]);

const SQL_KEYWORDS = new Set([
  "select", "from", "where", "and", "or", "not", "in", "between", "like",
  "is", "null", "as", "join", "left", "right", "inner", "outer", "full",
  "cross", "on", "group", "by", "order", "asc", "desc", "limit", "offset",
  "having", "distinct", "union", "all", "insert", "into", "values", "update",
  "set", "delete", "returning", "create", "table", "alter", "drop", "index",
  "primary", "key", "foreign", "constraint", "case", "when", "then", "else",
  "end", "with", "exists", "using",
]);

const escapeHtml = (s: string): string =>
  s.replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]!));

interface Tok {
  cls: string;
  text: string;
}

function pushPhp(out: Tok[], text: string): void {
  // Order matters: comments, strings, numbers, idents, ops/punct.
  let i = 0;
  while (i < text.length) {
    const c = text[i]!;
    // line comment
    if (c === "/" && text[i + 1] === "/") {
      const end = text.indexOf("\n", i);
      const stop = end === -1 ? text.length : end;
      out.push({ cls: "tok-cmt", text: text.slice(i, stop) });
      i = stop;
      continue;
    }
    if (c === "#" && text[i + 1] !== "[") {
      const end = text.indexOf("\n", i);
      const stop = end === -1 ? text.length : end;
      out.push({ cls: "tok-cmt", text: text.slice(i, stop) });
      i = stop;
      continue;
    }
    if (c === "/" && text[i + 1] === "*") {
      const end = text.indexOf("*/", i + 2);
      const stop = end === -1 ? text.length : end + 2;
      out.push({ cls: "tok-cmt", text: text.slice(i, stop) });
      i = stop;
      continue;
    }
    // strings
    if (c === '"' || c === "'") {
      const quote = c;
      let j = i + 1;
      while (j < text.length) {
        if (text[j] === "\\") {
          j += 2;
          continue;
        }
        if (text[j] === quote) {
          j++;
          break;
        }
        j++;
      }
      out.push({ cls: "tok-str", text: text.slice(i, j) });
      i = j;
      continue;
    }
    // variable
    if (c === "$") {
      let j = i + 1;
      while (j < text.length && /[A-Za-z0-9_]/.test(text[j]!)) j++;
      out.push({ cls: "tok-var", text: text.slice(i, j) });
      i = j;
      continue;
    }
    // number
    if (/[0-9]/.test(c)) {
      let j = i;
      while (j < text.length && /[0-9_.eExX+\-a-fA-F]/.test(text[j]!)) j++;
      out.push({ cls: "tok-num", text: text.slice(i, j) });
      i = j;
      continue;
    }
    // identifier / keyword / class
    if (/[A-Za-z_\\]/.test(c)) {
      let j = i;
      while (j < text.length && /[A-Za-z0-9_\\]/.test(text[j]!)) j++;
      const word = text.slice(i, j);
      const lower = word.toLowerCase();
      if (PHP_KEYWORDS.has(lower)) {
        out.push({ cls: "tok-kw", text: word });
      } else if (/^[A-Z]/.test(word)) {
        out.push({ cls: "tok-cls", text: word });
      } else {
        // function call?
        let k = j;
        while (k < text.length && text[k] === " ") k++;
        if (text[k] === "(") {
          out.push({ cls: "tok-fn", text: word });
        } else {
          out.push({ cls: "tok-pn", text: word });
        }
      }
      i = j;
      continue;
    }
    // operators / punctuation / whitespace
    out.push({ cls: /[(){}\[\];,:]/.test(c) ? "tok-pn" : "tok-op", text: c });
    i++;
  }
}

function pushSql(out: Tok[], text: string): void {
  let i = 0;
  while (i < text.length) {
    const c = text[i]!;
    if (c === "'") {
      let j = i + 1;
      while (j < text.length && text[j] !== "'") j++;
      out.push({ cls: "tok-str", text: text.slice(i, Math.min(j + 1, text.length)) });
      i = j + 1;
      continue;
    }
    if (c === '"') {
      let j = i + 1;
      while (j < text.length && text[j] !== '"') j++;
      out.push({ cls: "tok-cls", text: text.slice(i, Math.min(j + 1, text.length)) });
      i = j + 1;
      continue;
    }
    if (/[0-9]/.test(c)) {
      let j = i;
      while (j < text.length && /[0-9.]/.test(text[j]!)) j++;
      out.push({ cls: "tok-num", text: text.slice(i, j) });
      i = j;
      continue;
    }
    if (/[A-Za-z_]/.test(c)) {
      let j = i;
      while (j < text.length && /[A-Za-z0-9_]/.test(text[j]!)) j++;
      const word = text.slice(i, j);
      out.push({ cls: SQL_KEYWORDS.has(word.toLowerCase()) ? "tok-kw" : "tok-pn", text: word });
      i = j;
      continue;
    }
    out.push({ cls: "tok-op", text: c });
    i++;
  }
}

export function highlight(code: string, lang: "php" | "sql" = "php"): string {
  const out: Tok[] = [];
  if (lang === "sql") pushSql(out, code);
  else pushPhp(out, code);
  return out
    .map((t) => `<span class="${t.cls}">${escapeHtml(t.text)}</span>`)
    .join("");
}
