# Lister Variants — Strategic Overview

> Status: **parked** — capturing direction for future work. `lister-php` is the active project.

## The drop-in promise

The original project goal is "drop-in install": put files on a server, get a working directory lister. Different hosting environments require different approaches to fulfill (or approximate) that promise. Rather than one project trying to serve everyone, the plan is separate projects per deployment model.

## User × hosting matrix

| Skill level | Likely hosting | Variant | Status |
|---|---|---|---|
| Non-technical | Shared PHP hosting | `lister-php` | Active |
| GitHub-comfortable | GitHub Pages / Netlify free | `lister-static` | Planned |
| Somewhat technical | Cloudflare Pages | `lister-pages` | Planned |
| Technical | VPS / Docker / anything | Many options, high tolerance | Out of scope |

## Drop-in ceiling

The target bar for all planned variants: **one config step** (set one env var, paste a token, or edit one config block), then upload. No CLI, no build tools, no npm required.

Technical users (VPS and above) have many fuller-featured options available and aren't the target audience for these drop-in variants.

---

## Planned variants

### `lister-static`

**Target:** GitHub-comfortable users on GitHub Pages, Netlify free, Cloudflare Pages, or any static host.

**How it works:** A single self-contained `index.html`. Edit a config block at the top (repo owner, repo name, optional branch), upload anywhere that serves HTML. The browser calls the GitHub API directly — no server, no Functions, no build step.

**Config step:** Edit 4 lines in the HTML file.

**Tradeoffs:**
- For public repos: no token needed (60 req/hr per visitor, fine for personal use)
- For private repos: token would live in the HTML — a security exposure on public hosts. Not recommended.
- File source: GitHub repo contents only (not the host's own filesystem)
- File dates: limited (GitHub API doesn't return mtime; would show commit date or nothing)

**Why first:** Most drop-in of all options — one file, any host, no server runtime required.

---

### `lister-pages`

**Target:** Cloudflare Pages users comfortable with pasting an env var into a dashboard.

**How it works:** `index.html` + a Pages Function (`functions/lister/api.js`) that proxies the GitHub API server-side. Token lives in Cloudflare Pages environment variables, never exposed to the browser.

**Config step:** Add `GITHUB_TOKEN` in the Cloudflare Pages dashboard.

**Tradeoffs:**
- Works for public and private repos
- No client-side rate limit concerns
- Tied to Cloudflare Pages specifically
- File source: GitHub repo contents

**Details:** See `docs/cloudflare-pages-migration.md` for full approach, file changes, and verification checklist.

---

### `lister-netlify` (possible future)

Nearly identical to `lister-pages` but using Netlify Functions. Same one-config-step story, different function syntax and deployment UI. Low-effort adaptation of `lister-pages` if Netlify demand exists.

---

## File source considerations

All static variants use the **GitHub API** as the file source — they list a repo's committed contents, not files on the host's filesystem. This is the key difference from `lister-php`, which lists actual server files.

Implications:
- Files must be committed to git to appear
- Generated, uploaded, or ignored files won't show
- The repo being listed is typically the same one hosting the site
