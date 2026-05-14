// Resolves the latest published release tag from the GitHub API and writes
// it to .vitepress/cache/version.json so the VitePress config can read it
// synchronously at build time. Runs in `prebuild`.
//
// Resolution order:
//   1. DOCS_VERSION env var (release workflows can pin a specific version)
//   2. GitHub API: latest release of thamibn/php-periscope
//   3. `git describe --tags --abbrev=0` (works when CI does a full clone)
//   4. Fallback to "main" so previews still render
//
// Never throws — falling back is always safe.

import { execSync } from "node:child_process";
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const outPath = resolve(__dirname, "../.vitepress/cache/version.json");

async function resolveVersion() {
  if (process.env.DOCS_VERSION) {
    return process.env.DOCS_VERSION;
  }

  // GitHub Actions tag push: GITHUB_REF_NAME is the tag itself (e.g. "v0.1.5").
  // This MUST be checked before the API call — when a release tag is pushed,
  // the JetBrains+VSCode workflows attach artifacts in parallel, so the API's
  // "latest release" can lag by minutes. Trust the ref we were invoked with.
  if (
    process.env.GITHUB_REF_TYPE === "tag" &&
    typeof process.env.GITHUB_REF_NAME === "string" &&
    process.env.GITHUB_REF_NAME.startsWith("v")
  ) {
    return process.env.GITHUB_REF_NAME;
  }

  // GitHub API — for branch builds where no tag context exists.
  try {
    const res = await fetch(
      "https://api.github.com/repos/thamibn/php-periscope/releases/latest",
      {
        headers: {
          Accept: "application/vnd.github.v3+json",
          "User-Agent": "periscope-docs-build",
        },
      },
    );
    if (res.ok) {
      const data = await res.json();
      if (typeof data.tag_name === "string" && data.tag_name.length > 0) {
        return data.tag_name;
      }
    }
  } catch {
    // network unavailable, sandbox blocks egress, etc — fall through.
  }

  // Git tag — works on a full clone (local dev, GitHub Actions with fetch-depth: 0).
  try {
    return execSync("git describe --tags --abbrev=0", {
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"],
    }).trim();
  } catch {
    // shallow clone, no tags — fall through.
  }

  return "main";
}

const version = await resolveVersion();
mkdirSync(dirname(outPath), { recursive: true });
writeFileSync(outPath, JSON.stringify({ version }) + "\n");
// eslint-disable-next-line no-console
console.log(`[resolve-version] docs version → ${version}`);
