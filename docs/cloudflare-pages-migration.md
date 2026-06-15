# Cloudflare Pages Migration — Parked Plan

> Status: **parked** — current migration work takes priority. Revisit after it's stable.
>
> Longer-term, these approaches may become separate projects to preserve the original "drop-in install" promise under different deployment models.

## Can lister move to Cloudflare Pages?

Yes. Pages doesn't support PHP, but the PHP layer is replaceable via one of two approaches:

| | Approach A: Build step + Pages Functions | Approach B: GitHub API + Pages Functions |
|---|---|---|
| **Build required** | Yes — Node.js scan at deploy time | No |
| **File data source** | Project source tree | GitHub Contents API (live) |
| **Drop-in install** | Requires build tooling | Single HTML + one Function |
| **File dates** | Accurate (filesystem mtime) | Limited (commit date, extra API call) |
| **Works for non-git files** | Yes | No |
| **Rate limits** | None | 5,000 req/hr with token; 60 without |

Both approaches leave UX and client-side behavior unchanged.

---

## What PHP does today

| Component | Role |
|---|---|
| `index.php` + `App.php` | Bootstrap, config load, render HTML |
| `DirectoryLister.php` | `scandir()` the requested path, build file metadata |
| `lister/api.php` | POST endpoint — expand a folder dynamically, return JSON |
| `lister/preview.php` | Read file from disk, render markdown, return JSON |
| `Security.php` | Per-IP rate limiting + bot detection (writes to `lister/data/`) |
| `templates/index.php` | HTML template (mixed PHP/HTML) |
| `.htaccess` | Route all requests through `index.php` |

The JavaScript already handles: keyboard navigation, modal rendering, dynamic row insertion, sort cookie persistence. PHP is purely the data supplier and HTML renderer.

---

## Recommended approach: Static HTML + Pages Functions

### Build step (replaces DirectoryLister.php)

A Node.js build script (`scripts/build.js`) runs at deploy time:

1. Recursively scans the source directory (same rules as `ConfigLoader` + `DirectoryLister`)
2. Applies `file_hiding` patterns from `lister/config/default.json`
3. Maps extensions to icons via `lister/config/extensions.json`
4. Outputs:
   - `dist/index.html` — rendered HTML for the root directory
   - `dist/lister/manifest.json` — full nested directory tree with metadata (path, type, size, mtime, icon)

### Pages Functions (replaces PHP endpoints)

| Current PHP | Replacement |
|---|---|
| `lister/api.php` | `functions/lister/api.js` — reads `manifest.json`, returns same JSON shape the client already expects |
| `lister/preview.php` | `functions/lister/preview.js` — fetches file via `fetch()` on same origin, renders markdown with `marked`, returns same JSON shape |
| `lister/admin.php` | Drop |

Pages Functions can `fetch()` static assets on the same Pages deployment, so `preview.js` can read text files without a filesystem API.

### What gets dropped

- **`Security.php`** — replaced for free by Cloudflare's network-level WAF and DDoS protection on any Pages deployment
- **`router.php`** — local dev replaced by `wrangler pages dev`
- **`.htaccess`** — replaced by `_redirects`: `/* /index.html 200`
- **`admin.php`** — drop (or move to a Cloudflare Access–protected Worker later)
- **`lister/data/`** — security log files; no longer needed

### What stays unchanged

- All client-side JavaScript (POSTs to the same paths — no changes needed)
- `lister/assets/lister.css`
- `lister/config/extensions.json` (consumed by build script)
- `lister/config/default.json` (consumed by build script)
- Favicon assets

---

## File changes summary

**New:**
- `scripts/build.js` — Node.js directory scanner + HTML renderer
- `functions/lister/api.js` — Pages Function
- `functions/lister/preview.js` — Pages Function
- `_redirects` — SPA fallback route
- `package.json` — build deps (`marked`, possibly `handlebars`)

**Removed:**
- All `*.php` files
- `.htaccess`
- `lister/data/`
- `lister/includes/Security.php`, `PathSanitizer.php`, `ConfigLoader.php`, `App.php`, `DirectoryLister.php`
- `lister/includes/Parsedown.php` (replaced by `marked`)

**Adapted:**
- `lister/templates/index.php` → plain HTML template (consumed by build script)

---

## Approach B: GitHub API (no build step)

A Pages Function calls the GitHub Contents API at request time — no manifest, no scan, no build tooling. The closest thing to "drop in and it works."

### How it works

- **`functions/lister/api.js`** receives a path, calls `https://api.github.com/repos/{owner}/{repo}/contents/{path}`, returns file metadata in the same JSON shape the client already expects
- **`functions/lister/preview.js`** fetches raw file content from `raw.githubusercontent.com`; markdown is rendered client-side with `marked.js` (no server-side rendering needed)
- **`index.html`** is the only deployed file — JavaScript populates the UI from API responses exactly as today

The GitHub API token (for the 5,000 req/hr tier) is stored as a Cloudflare Pages secret — never exposed to the browser.

### What you lose vs. Approach A

- **File mtime** — GitHub Contents API doesn't return last-modified per file. You'd need a second call to the Commits API per file to get it, or drop the date column. This is the most significant UX regression.
- **Non-git files** — anything not committed to the repo is invisible (uploaded assets, generated files, etc.)
- **Latency** — GitHub API adds ~100–300ms per expand; cacheable with Cloudflare's Cache API using short TTLs

### File changes vs. current PHP version

**New:**
- `functions/lister/api.js` — Pages Function (GitHub API proxy)
- `functions/lister/preview.js` — Pages Function (raw content proxy)
- `index.html` — static shell; JS hydrates from API

**Removed:**
- All `*.php` files
- `.htaccess` → `_redirects`
- `lister/data/`, `lister/includes/`, `lister/config/` (config baked into Function or Pages env vars)
- `lister/includes/Parsedown.php` → `marked.js` client-side

**No build tooling needed** — `package.json` not required unless you want local `wrangler pages dev`.

---

## Open questions before starting

1. **What files is lister listing?** Approach A scans the project source tree. Approach B only sees git-committed files. If lister needs to show files outside the repo, neither approach works as described — you'd need R2 or KV as the file store.
2. **File dates (Approach B)** — decide whether to drop the date column, show commit date (requires extra API call per directory), or show nothing.
3. **Preview of large files** — Pages Functions have a 30s CPU limit / 128MB memory. Current 512 KiB preview limit is fine; enforce it in the Function.

---

## Verification checklist (when the time comes)

- `npm run build` — confirm `dist/` has `index.html` + `manifest.json`
- `wrangler pages dev dist` — run locally
- Root listing renders with correct icons, sizes, dates
- Expand a subdirectory — dynamic rows insert correctly
- Click text/markdown file — preview modal renders
- Keyboard navigation: arrows, Enter, carousel cycle
- Sort by name/size/date — cookie persists across reload
- Deploy: `wrangler pages deploy dist`
- Smoke-test against `docs/error-smoke-tests.md`
