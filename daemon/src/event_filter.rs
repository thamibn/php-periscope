//! Tiny JSON-path query language for
//! `GET /api/traces/{id}/events?filter=<expr>`.
//!
//! Grammar (intentionally small — this is a triage filter, not Datadog
//! Query Language):
//!
//! ```text
//! expr   := clause ( AND clause )*
//! clause := path ':' literal           # structured: walk dotted path, compare
//!         | bare-word                  # free text: case-insensitive substring on the
//!                                      #            stringified payload
//! path   := ident ( '.' ident )*       # ident = [A-Za-z0-9_-]+
//! literal:= '"' ... '"'   # exact-equal (string compare)
//!         | unquoted      # case-insensitive substring on the value at the path
//! ```
//!
//! Examples:
//!   - `payload.level:error`
//!   - `payload.context.user_id:42`
//!   - `payload.message:"connection refused"`
//!   - `payload.level:error AND payload.context.user_id:42`
//!   - `payload.level:error AND boom`               (last clause = free text)
//!
//! Parse errors yield `Err(String)` carrying a short message — the API
//! layer turns this into a 400 so the UI can render it inline.

use serde_json::Value;

#[derive(Debug, Clone)]
pub struct Filter {
    clauses: Vec<Clause>,
}

#[derive(Debug, Clone)]
enum Clause {
    /// `path:literal`
    Path { path: Vec<String>, lit: Literal },
    /// bare word — substring against the whole event JSON
    FreeText(String),
}

#[derive(Debug, Clone)]
enum Literal {
    /// Quoted — exact string equality after to-string of the JSON value.
    Exact(String),
    /// Unquoted — case-insensitive substring of the to-string of the JSON
    /// value. Numeric literals also match if numerically equal.
    Loose(String),
}

impl Filter {
    pub fn parse(input: &str) -> Result<Self, String> {
        let trimmed = input.trim();
        if trimmed.is_empty() {
            return Ok(Self { clauses: Vec::new() });
        }
        let mut clauses = Vec::new();
        for raw in split_top_level_and(trimmed)? {
            let raw = raw.trim();
            if raw.is_empty() {
                continue;
            }
            clauses.push(parse_clause(raw)?);
        }
        Ok(Self { clauses })
    }

    pub fn is_empty(&self) -> bool {
        self.clauses.is_empty()
    }

    /// Conjunction over all clauses. Empty filter = match-everything.
    pub fn matches(&self, event_json: &Value) -> bool {
        for c in &self.clauses {
            if !match_clause(c, event_json) {
                return false;
            }
        }
        true
    }
}

fn split_top_level_and(s: &str) -> Result<Vec<&str>, String> {
    // Split on " AND " case-insensitively, but only outside double quotes so
    // a literal like `message:"AND clause inside"` doesn't break the parse.
    let bytes = s.as_bytes();
    let mut parts = Vec::new();
    let mut start = 0usize;
    let mut i = 0usize;
    let mut in_quote = false;
    while i < bytes.len() {
        let c = bytes[i];
        if c == b'"' {
            in_quote = !in_quote;
            i += 1;
            continue;
        }
        if !in_quote && i + 5 <= bytes.len() {
            let win = &bytes[i..i + 5];
            // Match space + A/a + N/n + D/d + space
            if (win[0] == b' ' || win[0] == b'\t')
                && (win[1] == b'A' || win[1] == b'a')
                && (win[2] == b'N' || win[2] == b'n')
                && (win[3] == b'D' || win[3] == b'd')
                && (win[4] == b' ' || win[4] == b'\t')
            {
                parts.push(&s[start..i]);
                i += 5;
                start = i;
                continue;
            }
        }
        i += 1;
    }
    if in_quote {
        return Err("unterminated quoted string".into());
    }
    parts.push(&s[start..]);
    Ok(parts)
}

fn parse_clause(raw: &str) -> Result<Clause, String> {
    // Find a ':' that's outside quotes. If absent → free-text clause.
    let bytes = raw.as_bytes();
    let mut in_quote = false;
    let mut colon = None;
    for (i, &c) in bytes.iter().enumerate() {
        if c == b'"' {
            in_quote = !in_quote;
        } else if c == b':' && !in_quote {
            colon = Some(i);
            break;
        }
    }
    let Some(idx) = colon else {
        return Ok(Clause::FreeText(unquote_or_pass(raw).to_lowercase()));
    };
    let key = raw[..idx].trim();
    let val = raw[idx + 1..].trim();
    if key.is_empty() {
        return Err(format!("empty key before ':' in `{raw}`"));
    }
    let path = key
        .split('.')
        .map(|s| s.trim())
        .map(|s| {
            if s.is_empty() {
                Err(format!("empty path segment in `{key}`"))
            } else if !s.chars().all(|c| c.is_alphanumeric() || c == '_' || c == '-') {
                Err(format!("invalid path segment `{s}` in `{key}`"))
            } else {
                Ok(s.to_string())
            }
        })
        .collect::<Result<Vec<_>, _>>()?;
    let lit = if val.starts_with('"') && val.ends_with('"') && val.len() >= 2 {
        Literal::Exact(val[1..val.len() - 1].to_string())
    } else {
        Literal::Loose(val.to_string())
    };
    Ok(Clause::Path { path, lit })
}

fn unquote_or_pass(s: &str) -> &str {
    let t = s.trim();
    if t.len() >= 2 && t.starts_with('"') && t.ends_with('"') {
        &t[1..t.len() - 1]
    } else {
        t
    }
}

fn match_clause(c: &Clause, event_json: &Value) -> bool {
    match c {
        Clause::FreeText(needle) => {
            let hay = serde_json::to_string(event_json).unwrap_or_default().to_lowercase();
            hay.contains(needle)
        }
        Clause::Path { path, lit } => {
            // The first path segment is matched against either the event's
            // own top-level keys (e.g. `type`, `id`) or — by convention from
            // the plan's examples — the special prefix `payload`. We treat
            // `payload.x.y` as `<event_json>.payload.x.y`, which means we
            // always include "payload" in the walk implicitly when the
            // first segment is `payload`. Otherwise walk from the root.
            let value_at = lookup(event_json, path);
            match value_at {
                None => false,
                Some(v) => match_literal(lit, v),
            }
        }
    }
}

fn lookup<'a>(root: &'a Value, path: &[String]) -> Option<&'a Value> {
    let mut cur = root;
    for seg in path {
        match cur {
            Value::Object(map) => {
                cur = map.get(seg)?;
            }
            _ => return None,
        }
    }
    Some(cur)
}

fn match_literal(lit: &Literal, v: &Value) -> bool {
    match lit {
        Literal::Exact(want) => value_to_string(v) == *want,
        Literal::Loose(want) => {
            let want_lower = want.to_lowercase();
            let got = value_to_string(v).to_lowercase();
            if got.contains(&want_lower) {
                return true;
            }
            // Numeric loose-match: if both parse as f64, compare values.
            if let (Ok(a), Ok(b)) = (want.parse::<f64>(), value_as_f64(v)) {
                return (a - b).abs() < f64::EPSILON;
            }
            false
        }
    }
}

fn value_to_string(v: &Value) -> String {
    match v {
        Value::String(s) => s.clone(),
        Value::Null => String::new(),
        other => other.to_string(),
    }
}

fn value_as_f64(v: &Value) -> Result<f64, ()> {
    match v {
        Value::Number(n) => n.as_f64().ok_or(()),
        Value::String(s) => s.parse::<f64>().map_err(|_| ()),
        _ => Err(()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn ev(type_tag: &str, payload: serde_json::Value) -> Value {
        json!({
            "id": 1,
            "at_micros": 0,
            "in_frame_id": 0,
            "type": type_tag,
            "payload": payload,
        })
    }

    #[test]
    fn empty_filter_matches_everything() {
        let f = Filter::parse("").unwrap();
        assert!(f.is_empty());
        assert!(f.matches(&ev("log", json!({"message": "anything"}))));
    }

    #[test]
    fn structured_clause_matches_path() {
        let f = Filter::parse("payload.level:error").unwrap();
        assert!(f.matches(&ev("log", json!({"level": "error", "message": "boom"}))));
        assert!(!f.matches(&ev("log", json!({"level": "info"}))));
    }

    #[test]
    fn loose_match_is_case_insensitive_substring() {
        let f = Filter::parse("payload.message:REFUSED").unwrap();
        assert!(f.matches(&ev("log", json!({"message": "connection refused"}))));
    }

    #[test]
    fn quoted_value_is_exact_match() {
        let f = Filter::parse(r#"payload.message:"connection refused""#).unwrap();
        assert!(f.matches(&ev("log", json!({"message": "connection refused"}))));
        assert!(!f.matches(&ev("log", json!({"message": "CONNECTION REFUSED"}))));
    }

    #[test]
    fn nested_path_walks_objects() {
        let f = Filter::parse("payload.context.user_id:42").unwrap();
        assert!(f.matches(&ev("log", json!({"context": {"user_id": 42}}))));
        assert!(f.matches(&ev("log", json!({"context": {"user_id": "42"}}))));
        assert!(!f.matches(&ev("log", json!({"context": {"user_id": 43}}))));
    }

    #[test]
    fn missing_path_does_not_match() {
        let f = Filter::parse("payload.context.user_id:42").unwrap();
        assert!(!f.matches(&ev("log", json!({"level": "info"}))));
    }

    #[test]
    fn and_conjunction() {
        let f = Filter::parse("payload.level:error AND payload.context.user_id:42").unwrap();
        assert!(f.matches(&ev(
            "log",
            json!({"level": "error", "context": {"user_id": 42}}),
        )));
        assert!(!f.matches(&ev(
            "log",
            json!({"level": "info", "context": {"user_id": 42}}),
        )));
        assert!(!f.matches(&ev(
            "log",
            json!({"level": "error", "context": {"user_id": 1}}),
        )));
    }

    #[test]
    fn free_text_clause_matches_substring() {
        let f = Filter::parse("refused").unwrap();
        assert!(f.matches(&ev("log", json!({"message": "connection refused"}))));
        assert!(!f.matches(&ev("log", json!({"message": "ok"}))));
    }

    #[test]
    fn and_with_inside_quoted_value_does_not_split() {
        let f = Filter::parse(r#"payload.message:"foo AND bar""#).unwrap();
        assert!(f.matches(&ev("log", json!({"message": "foo AND bar"}))));
    }

    #[test]
    fn top_level_keys_match_too() {
        let f = Filter::parse("type:exception").unwrap();
        assert!(f.matches(&ev("exception", json!({"class": "RuntimeException"}))));
        assert!(!f.matches(&ev("log", json!({"class": "RuntimeException"}))));
    }

    #[test]
    fn unterminated_quote_errors() {
        let err = Filter::parse(r#"payload.message:"unterminated"#).unwrap_err();
        assert!(err.contains("unterminated"));
    }

    #[test]
    fn invalid_path_segment_errors() {
        let err = Filter::parse("payload.bad path:value").unwrap_err();
        assert!(err.to_lowercase().contains("invalid"));
    }
}
