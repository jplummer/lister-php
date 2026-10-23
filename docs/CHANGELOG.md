# Changelog

Notable changes to Lister. Deployment builds show a **compact ID** (last 8 digits of Unix time) in the footer instead of semantic versions.

## Recent changes

### 2026-10-23

- **Security fix**: `lister/data/security.log` was downloadable by anyone because `.htaccess` blocked `/data/` but not `/lister/data/`. `.htaccess` now blocks `lister/{includes,data,config,templates}/`, dotfiles (except `.well-known`), and `*.log`; `Security` also writes a deny-all `.htaccess` into `lister/data/`.
- **Security fix**: `preview.php` would return the contents of any text-like file under the web root, including `lister/config/*.json` and `.htaccess`; `api.php` and page URLs would list `lister/` and dotfolders. All three now share `DirectoryLister::isPathVisible()`, so anything the listing hides (every path segment checked) gets the same "not found" as a missing file.
- **Security fix**: `api.php` no longer returns absolute server paths (`path`, `current_path`, `parent_path`).
- **Admin**: `admin.php` is off by default (`security.admin_enabled`). When on, it checks a `password_hash` in gitignored `lister/config/admin.json`, uses a session (HttpOnly, SameSite=Strict, 30-minute idle timeout), locks an IP out after 5 failed logins in 15 minutes, logs failures, and sends no-frame/no-cache/noindex headers. The hardcoded default password is gone.
- **Logs and data files**: `security.log` rotates at `security.max_log_bytes` (default 1 MB, one previous file kept). Expired `rate_*`, `blocked_*`, and `admin_fail_*` files are swept every five minutes. `block_duration_seconds` and `log_suspicious_activity` are now honored (they were ignored).
- **Deploy**: `scripts/deploy.sh` no longer uploads local `lister/data/`.
- **Requirements**: PHP 8.3 minimum (was 7.4).
- **404s**: Blocked paths in `.htaccess` return 404 instead of 403, and a URL naming a hidden file (e.g. `/.htaccess` routed to Lister by a server error page) gets Lister's 404 instead of a listing of its folder.
- **Rename**: `lister/admin.php` is now `lister/log.php`; config key `security.admin_enabled` is now `security.log_viewer_enabled`; password file `lister/config/admin.json` is now `lister/config/log_viewer.json`. `deploy.sh` removes the old `admin.php` from the server.
- **404s**: A disabled `log.php` returns the same 404 page, headers, and bot/rate-limit checks as any missing path (shared `App::renderNotFound()`), so it can't be told apart from a file that doesn't exist.
- **Naming**: `admin.php` is presented as the read-only "Security Log" viewer, not a "Security Admin" dashboard; there are no settings there.
- **Tests**: `php scripts/test_security.php` now runs real pass/fail checks (rate limit, bot UA, blocking, log rotation, pruning, admin lockout, path visibility) against temp dirs and exits non-zero on failure. `Security::evaluateRequest()` returns the denial instead of exiting, which is what made the old script stop at the first denied request.

### 2026-03-22

- **Assets**: Favicon JPEGs moved from `lister/assets/images/2021/02/` to `lister/assets/images/`; listing and error templates plus `scripts/diagnose_favicon.php` updated; query string `?v=3` for cache bust.
- **Docs**: `INSTALL.md` rewritten for current layout, `lister/data/` permissions, config links, and removal; `README.md` Quick Start matches security-aware curl; `docs/notes.md` project tree lists templates, `preview.php`, and `assets/images/`.

### 2026-03-21

- **Runtime errors**: `app.debug` false → generic 500 + `error_log`; true → full `runtime_error.php` detail; `docs/error-smoke-tests.md`; server vs Lister 404 in `configuration.md`.
- **Plan**: Phase 2 remaining trimmed; archive tightened and reordered (2.1–2.13 + host note); done error items only in archive.
- **Apache routing**: `.htaccess` catch-all sends non-file, non-directory URLs (e.g. `/neef`) to `index.php` so Lister’s in-app 404 runs; previously only `/` and real dirs were rewritten.
- **404 / errors**: Missing path under lister root returns HTTP 404 (`http_error_404.php`, shared chrome); `runtime_error.php` uses `lister.css` + same header/footer as listing; `DirectoryLister` no longer falls back to root for bad paths.
- **Accessibility**: Audit doc (`docs/accessibility-audit.md`); skip link; single page `h1`; table caption; `aria-expanded` on folder buttons; `aria-current` on sort; SR text for folder load; `plan.md` archive **2.12**.
- **Plan**: Shipping task to investigate non-Apache equivalents to `.htaccess` (nginx/Caddy/IIS, etc.) and honest drag-and-drop scope per host.
- **Plan / docs**: Optional anti-abuse expansion (curated bot lists, stricter rate limits) moved under Phase 4; autoindex not required for Lister (PHP enumeration) noted in plan, `requirements.md`, and `configuration.md`.
- **Plan**: Active Phase 2 trimmed to remaining work; shipped items stay in archive; **Explicitly cancelled** section for file filter by type/size (dropped).
- **Listing**: File and folder names use normal font weight (not bold). Preview modal prev/next applies to all previewable files in table order, not only images; capture-phase **←** / **→** on `<dialog>` unchanged.
- **Keyboard**: Roving row focus (↑↓, → / ← expand-collapse, Enter); **Space** opens preview when available, otherwise same as Enter; initial focus on first row; Esc closes preview; name-cell controls `tabindex="-1"`. Row focus: subtle cell background. Documented in `requirements.md`.
- **Keyboard (nested)**: **←** on a collapsed nested directory row or a nested file row moves focus to the parent folder row (empty nested folders included).
- **Modal previews**: `preview_kind` / `data-preview`; text via `lister/preview.php` (e.g. `.md`); PDF, **and** server-rendered web (`.html`, `.htm`, `.php`, `.shtml`, ASP/JSP/CFML/CGI where applicable) via `<iframe>` so output is rendered, not source; images; prev/next across all preview kinds in list order; `display.preview_text_max_bytes`.
- **Install surface**: Preflight install error rendered from root `index.php` only (removed extra root `install_error.php`). Docs: packaging favors clear upload target and runtime-only zips over minimizing file count.
- **Listing UX**: README block under the table (`ReadmePreview`, `display.readme_preview`); DR10 palette via `light-dark()`; Material Symbols file/folder icons (`IconSymbols.php`); sortable columns with query → cookie → config; API POST sends same `sort`/`dir` for expanded rows.
- **Expand / API**: Viewport loading indicator (≥500ms, overlapping fetches); `PathSanitizer`; when `security.enabled`, `lister/api.php` runs the same checks as pages and returns JSON 403 on deny.
- **Docs / scope**: `plan.md` + `requirements.md` reorganized (Phase 4 deferred commercialization); deployment context note in requirements.

### 2025-11-24

- Ongoing fixes and hook-driven deploy metadata updates (no single theme).

### 2025-11-23

- **URLs**: `rawurlencode` path segments so spaces become `%20` in links.
- **Deploy**: Data under `lister/data/`; footer deployment ID; favicon links + assets; git hooks in `scripts/`.
- **Docs / security**: Changelog and security dashboard documentation; sensitive-file hiding patterns.

## [1.0.0] — 2025-10-10

### Added

- **File hiding**: Dotfiles, sensitive names, OS cruft, app paths; patterns; hidden files still reachable by direct URL.
- **Security**: IP block after rate limit; exponential / per-IP limits; incident log (IP, UA, URI, referer, time); basic password admin at `/lister/admin.php`.
- **UX**: Loading states; clearer errors; empty-folder rows.
- **Dev**: `deploy.sh`, `teardown.sh`, security test script, pattern tests, `router.php` for `php -S`.

## [0.9.0] — 2025-10-08

### Added

- Expandable directories (AJAX), nesting, loading states, empty folders.
- Large extension map (~700), MIME detection; emoji icons (since replaced by Material Symbols in 2026-03 work).

### Changed

- Requirements: design principles, sorting, responsive as core, access-control noted as future.

## [0.8.0] — Initial release

- Core listing (sort, icons, sizes, dates, breadcrumbs).
- Basic rate limit, bot UA filter, `.htaccess` hardening, sanitization, suspicious-request logging.
- Theming aligned with jonplummer.com, dark mode, responsive layout.
- JSON config (`lister/config/default.json`).
