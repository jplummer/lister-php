# The basic idea 

Lister exposes the contents of any web-accessible folder and subfolders for browsing and sharing. With minimal configuration, installation takes less than a minute. It's meant to be a custom from-scratch work-alike of https://www.directorylister.com/

**Deployment context**: The product priority is **personal, convenient file access**. Automated abuse (bots, scrapers) matters more than hiding listings from casual humans. Multi-tenant access, sellable hosting, and similar scenarios are **out of scope** for the current deployment; they are captured in [section 5](#5-commercialization-and-multi-user-deferred) and **Phase 4** in `plan.md`.

## 1. Core Features

* Directory listing shows a sortable list of files for any web-accessible directory or subdirectory; column headers change sort. The active sort is stored in an HttpOnly cookie so navigation without query parameters stays consistent, while `sort` and `dir` query parameters override the cookie and refresh it so URLs can encode a specific view
* File type icons show file types at a glance
* Drag-and-drop installation allows you to be up and running in less than a minute and is straightforward to remove
* **Host configuration**: Web server **autoindex** (Apache `Options Indexes`, nginx autoindex, etc.) does **not** need to be enabled. Lister builds the file table in PHP from the filesystem; only PHP execution and read access to the served directory tree are required
* **Accessibility**: Listing UI follows common WCAG-oriented patterns (semantic structure, skip link, table caption, sort and expand state for assistive tech, keyboard behavior in §1). Residual risks and follow-ups are in `docs/accessibility-audit.md`
* Shipped packages should minimize installer confusion: clear instructions for what to upload and where, a purposeful top-level layout in the delivered tree, and runtime-only contents in release artifacts. Total file count is not a primary packaging goal
* Sortable columns: default alphabetical by filename, with options to sort by type, size, or date
* Theme and styling integrate with https://jonplummer.com/, including dark mode support
* Direct URL access for sharing files
* **Not found**: A URL path under the listing that does not match any file or directory returns HTTP **404** and an HTML page using the same lister chrome and design tokens as the main view (message, requested path, links to listing root and jonplummer.com—pattern aligned with the site’s [404 page](https://jonplummer.com/404.html))
* **Runtime errors**: With `app.debug` **false** (default in `lister/config/default.json`), uncaught errors from the main app show a **generic** message to visitors; the real exception is written to the PHP **error log**. With `app.debug` **true**, the same page shows full details and install hints (development only). Host-level 404 for URLs that never reach PHP is documented in `docs/configuration.md`; smoke checks in `docs/error-smoke-tests.md`.
* **Traffic scope**: Lister is not positioned for high request volume or serious abuse. Optional `security` in config may apply light per-IP limits and bot-like UA handling; that does not replace edge or host-level controls. Drag-and-drop install does not require tuning `security`. Broader anti-abuse / admin tooling remains deferred (see §5, Phase 4 in `plan.md`)
* Responsive design for mobile and desktop
* README preview: if the listed directory contains a recognized README file (`README.md`, `README.txt`, etc.), rendered content appears below the listing (Markdown via Parsedown safe mode, plain text escaped)
* **Keyboard (listing table)**: The file/folder table supports **roving focus** on rows (one row in the tab order at a time; name-cell controls use `tabindex="-1"` so activation is **Enter** / **Space** as below). When the listing loads, focus moves to the first row (without scrolling the page) so arrow keys apply immediately. **↑** / **↓** move to the previous/next row. **→** expands the focused folder row when it has a disclosure; **←** collapses it when that row is expanded; **←** on a **collapsed** nested directory row (including an empty nested folder) or on a **nested file** row moves focus to the **parent** directory row. **Enter** activates the row: folders toggle expand/collapse (same as the disclosure control); files follow the link, or open the modal preview when `data-preview` is set. **Space** opens the modal preview when the file supports preview; otherwise **Space** activates the row the same way as **Enter** (so non-preview files and folders still respond). **Escape** closes an open preview modal and returns focus to the listing row. With the preview modal open and more than one previewable file listed, **←** / **→** (and the prev/next controls) move to the previous/next preview in **table order** among all rows with `data-preview` (text, PDF, images, etc., including under expanded folders). Keys are handled on the dialog in the capture phase so they work while focus is on modal controls (e.g. Close). Embedded PDF **iframes** still consume keys when focus is inside the viewer. **Search** is the browser’s own find-in-page (e.g. ⌘F); no separate in-app search shortcut is required for this behavior.

## 2. Features to be considered

* Text preview opens in a **modal** for a text or markdown document (single file, not the directory README); content handling aligns with README preview rules where applicable
* Image preview opens in the **same modal pattern**, with a way to move to other images in the **currently listed** directory
* PDF preview opens in that **modal** (e.g. embedded viewer) when the user clicks the file, without granting access beyond the existing file URL

## 3. Features to be considered later

* File search to locate files
* File hashes for download verification
* Multi-file download: select multiple files/folders and download as zip
* MCP (Model Context Protocol) integration for AI agents

## 4. Nice to have features

* Shared design system package to coordinate theme data with jonplummer-11ty and other projects

## 5. Commercialization and multi-user (deferred)

Not required for the current personal deployment. Revisit if Lister is offered as a product or service, serves multiple tenants, or holds sensitive data. Roadmap: **Phase 4** in `plan.md`.

* Directory access control: ability to restrict access to specific directories
* OWASP-oriented security headers and related hardening (see plan Phase 4)
* Authenticated file management:
  * Upload files from computer to selected folder
  * Drag-and-drop file upload and rearrangement
  * Delete files or folders
  * Authentication via SSH key or user/pass (sshpass)
* Enhanced security log viewer features (beyond the current basic viewer at `lister/log.php`):
  * **Security concerns to prevent, trap, and/or log:**
    * Bot scraping and automated access (curl, wget, scrapers, crawlers, spiders)
    * Rate limit violations (excessive requests per minute)
    * Directory traversal attempts (path manipulation with `..` or `//`)
    * Suspicious query parameters (cmd, exec, system, eval, shell, passwd, shadow)
    * Suspicious file extension requests (.php, .asp, .jsp, .py, .sh, .exe in URLs)
    * Missing or suspicious user agents
    * IP-based blocking and access attempts from blocked IPs
  * **Dashboard should display:**
    * Total security incidents count
    * Recent security incidents with details (type, IP, timestamp, user agent, request URI, referer)
    * Current security configuration settings
    * Ability to view blocked IPs and their status
    * Filtering and search capabilities for incident logs
    * Export capabilities for security logs
  * **Access control:**
    * Password-protected access
    * Session management to avoid repeated password entry
    * Secure authentication mechanism

## Changelog

* **2026-03-21** — **Production runtime errors**: `app.debug` gates visitor-visible 500 detail vs `error_log`; `docs/configuration.md` server 404 section; `docs/error-smoke-tests.md`. Core features bullet updated.

* **2026-03-21** — **404 + error chrome**: Invalid listing paths return HTTP 404 with dedicated page; runtime errors use shared lister chrome and `lister.css`. Core features bullet describes behavior and jonplummer.com 404 reference.

* **2026-03-21** — **Accessibility**: `docs/accessibility-audit.md`; template/CSS updates (skip link, one `h1`, table caption, `aria-expanded` / `aria-current`, loading text). Core features bullet references audit doc.

* **2026-03-21** — **Deployment**: Documented that server autoindex / “directory listing” is not required; Lister enumerates via PHP. Plan: deferred optional anti-abuse (curated bot lists, stricter rate limits) to Phase 4; removed open Phase 2 “abuse” line in favor of that framing.

* **2026-03-21** — **Listing typography**: File and folder names in the table use normal (400) weight instead of bold. Rationale: calmer listing, filenames remain readable via color/link styling.

* **2026-03-21** — **Keyboard (nested folder ←)**: On a collapsed nested directory row, **←** moves roving focus to the parent folder row (matches tree-style keyboard expectations). Rationale: clearer hierarchy navigation without tabbing.

* **2026-03-21** — **Keyboard (nested file ←)**: On a nested file row, **←** moves roving focus to the parent folder row (same `data-parent-path` as nested folders). Rationale: consistent “up the tree” behavior for files under expanded folders.

* **2026-03-21** — **Preview carousel**: Modal prev/next and **←** / **→** step through every `data-preview` file in list order, not images only. Rationale: consistent keyboard and control behavior across preview kinds.

* **2026-03-21** — **Keyboard (listing + preview)**: Initial focus on first listing row (`focus({ preventScroll: true })`); **Space** on non-preview rows matches **Enter**; image preview **←** / **→** use capture-phase `keydown` on the `<dialog>` so keys work when focus is on chrome; row focus styling uses a subtle cell background instead of outline. Rationale: fix modal shortcuts and non-preview **Space**; clearer focus affordance in tables.

* **2026-03-21** — **Keyboard navigation (core)**: Documented listing keyboard behavior: roving row focus; ↑↓; → / ← expand/collapse; Enter activate; Space preview-only for previewable files; Esc close modal; ← / → in image preview; search via browser find. Rationale: match agreed UX without Command-key chords; keep scope to the single-column table.

* **2026-03-21** — **Traffic scope & README**: Core features no longer claim robust in-app rate limiting; README states low-traffic intent and that optional `security` is a guardrail only. Rationale: honest positioning for shared hosting and drag-and-drop install; heavier anti-abuse remains §5 / Phase 4.

* **2026-03-21** — **Previews & listing (§2, plan 2.6–2.7)**: Modal text/image/PDF previews (`preview_kind`, `lister/preview.php`, `preview_text_max_bytes`); README panel; Material Symbols icons; column sort with cookie + API POST; API subject to Security when enabled. **Install & scope**: Packaging goals (upload target, runtime-only artifacts); preflight errors only in `index.php`; personal deployment context; deferred multi-user / commercialization in §5 and Phase 4.
