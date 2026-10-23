# Lister Development Plan

## Current Status: WORKS GOOD ✅
- **Deployed**: misc.jonplummer.com
- **Phase 1**: Complete — [archive](#archive-completed-work)
- **Phase 2**: In progress — ops/shipping docs; optional polish ([remaining](#phase-2-remaining-work))
- **Phase 3**: Not started; one item [cancelled](#explicitly-cancelled-wont-do)
- **Phase 4**: Deferred — [commercialization / multi-user](#phase-4-commercialization-and-multi-user)
- **Next**: Ops/shipping documentation

## Phase 2: Remaining work

**Context**: Personal, convenient file access; main abuse concern is automation, not casual humans. Phase 4 for multi-tenant / product depth.

Shipped listing UX (previews, keyboard, a11y, 404, runtime `app.debug`, error docs—see [archive](#phase-2-completed-items) **2.14**) lives in the archive.

### Operations: traffic, links, and delete safety
**Goal**: Use logs to avoid breaking bookmarks/embeds; discover inbound links.

- [ ] Document reading **access logs** for path/prefix hits (host notes, e.g. DreamHost).
- [ ] Document **safe-delete** workflow and what logs cannot prove.
- [ ] Document **Referer** limits + Search Console / URL search for link discovery.

### Shipping & installation
- [ ] **Non-Apache**: Investigate nginx/Caddy/IIS snippets vs `.htaccess`; document drag-and-drop limits per stack.
- [ ] Delivered package excludes deploy/dev-only tooling.
- [ ] Re-verify drag-and-drop install end-to-end.
- [ ] **Low**: OpenSSH PQ KEX warning on SFTP — https://openssh.com/pq.html
- [ ] **Optional**: README upload target, purposeful top-level zip, no dev files in release.

### Visual polish (optional)
- [ ] Styling closer to jonplummer.com

### Customization & integration
- [ ] Easier third-party styling: document CSS variables, optional minimal CSS, template notes, optional header/footer config, class hooks.

## Phase 3: Future Enhancements
**Goal**: Personal browsing/sharing only (no multi-user product).

### 3.1 Search & Discovery
- Client-side file search; advanced sort options

### 3.2 File Operations
- Multi-select; zip download; file hashes

### 3.3 Other
- MCP integration

### 3.4 Log analysis helpers (optional)
- [ ] grep/awk recipes for log formats (optional if raw logs suffice)

## Explicitly cancelled (won’t do)

* **File filtering by type/size** — Sorting + browser find enough.

## Phase 4: Commercialization and multi-user
**Goal**: Out of scope until sold, multi-tenant, or confidential data.

**Requirements**: [§5 deferred](requirements.md#5-commercialization-and-multi-user-deferred).

### Optional in-app anti-abuse (not default for drag-and-drop)
`security.enabled` = coarse UA + light limits only.

- [ ] Curated malicious-bot lists (update story for static zip).
- [ ] Stricter rate-limit knobs when traffic warrants it.

### Access and hardening
- [ ] Directory access control
- [ ] OWASP secure headers

### Authentication and file management
- [ ] Auth design; SSH key auth; upload UI; DnD upload/rearrange

### Security log viewer (product-depth)
- [ ] Richer log viewer: incidents, export, filters (password, session, and login lockout shipped 2026-10-23)

## Technical Architecture

### Technology Stack
- **Backend**: PHP 8.x (Dreamhost compatible)
- **Frontend**: Vanilla JS, CSS3, HTML5
- **Styling**: Custom CSS, jonplummer.com alignment
- **Configuration**: JSON
- **Security**: .htaccess, PHP validation

### Design Principles
- Semantic HTML, minimal classes; custom properties; minimal JS; a11y via semantics first; mobile-first

### File Structure
```
lister/
├── index.php
├── config/
│   ├── default.json
│   └── .htaccess
├── assets/ (css, js, icons)
├── includes/ (App, DirectoryLister, Security, …)
└── .listerignore
```

### Installation Process
1. Upload tree; 2. `.htaccess`; 3. Optional config; 4. Browse.

---

## Archive: completed work

### Phase 1: Core Foundation (MVP)

#### 1.1–1.2 Structure & engine
- [x] App structure, `.htaccess`, JSON config, logging
- [x] Directory scan, type/icons, sizes, dates

#### 1.3 UI
- [x] Semantic table + sort; breadcrumbs

#### 1.4 Theming
- [x] jonplummer patterns, dark mode, responsive

#### 1.5 Security & performance
- [x] Rate limit, bot UA filter, .htaccess hardening, sanitization, security log + basic admin, suspicious-request logging

#### 1.6 Expandable dirs
- [x] AJAX expand/collapse, empty folders, nesting, loading indicator

#### 1.7 File types
- [x] Large extension map, MIME, Material Symbols icons

#### 1.8 Dev tools
- [x] deploy/teardown scripts, security test, pattern tests, `router.php` for `php -S`

### Phase 2: completed items

#### 2.1 Styling completeness
- [x] Favicons, design system, link to jonplummer.com

#### 2.2 File management
- [x] `.listerignore`

#### 2.3 User experience
- [x] Loading states, clearer errors, shareable file URLs

#### 2.4 Advanced security
- [x] Rate limit + IP block + request logging + basic admin dashboard

#### 2.5 Polish
- [x] Directory disclosure caret

#### 2.6 Directory table & README
- [x] README panel (Parsedown safe, config); column layout, gutters, empty-folder rows

#### 2.7 Modal previews
- [x] `<dialog>` text/PDF/iframe/image; prev/next all `data-preview` rows; primary vs modified click

#### 2.8 Install & packaging
- [x] Preflight errors in root `index.php`; packaging goals in docs

#### 2.9 Favicon
- [x] `scripts/diagnose_favicon.php` + verification checklist

#### 2.10 Listing keyboard
- [x] Roving `tr` focus; ↑↓ →← Enter Space; preview carousel + dialog capture; first-row focus; row focus styling; browser find

#### 2.11 Typography
- [x] File/folder names `font-weight: 400`

#### 2.12 Accessibility
- [x] Skip link, one `h1`, table caption, `aria-current` / `aria-expanded`, loading SR text — `docs/accessibility-audit.md`

#### 2.13 HTTP errors & Apache routing
- [x] Missing listing path → HTTP 404 (`http_error_404.php`, shared chrome); `runtime_error.php` uses `lister.css` + same shell
- [x] `.htaccess` rewrite: `!-f` `!-d` → `index.php` so bogus paths hit Lister (not only `/` and real dirs)

#### 2.14 Production runtime + error documentation
- [x] `app.debug` false → generic 500 page + `error_log` with real exception; true → full Details + suggestions (`index.php`, `runtime_error.php`)
- [x] Server-level 404 vs Lister 404 documented in `docs/configuration.md`
- [x] Checklist: `docs/error-smoke-tests.md`

#### Host note (done)
- [x] Server autoindex **not** required — PHP enumerates; double autoindex undesirable

### Success criteria (met)
- [x] Sortable listing, jonplummer-aligned UI, mobile, icons, direct URLs, light anti-abuse, quick install, hides infra, works from subfolder URLs
