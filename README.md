# Lister - Directory Listing Application

A clean directory listing application for web servers.

## 📦 Install Package

**For installation, you only need these files:**

- `index.php` - Main application entry point (includes preflight install error UI)
- `.htaccess` - Apache security rules
- `lister/` - Application directory (all contents)
- `INSTALL.md` - Installation instructions

**Do not upload these development files:**
- `docs/` - Development documentation
- `scripts/` - Development and deployment scripts
- `router.php` - Development server router
- `test_security.php` - Test file

See [INSTALL.md](INSTALL.md) for detailed installation instructions.

## Traffic and scale

Lister is meant for low-traffic, personal browsing. Do not rely on it where sustained high request volume or heavy automated traffic is expected.

`lister/config/default.json` includes an optional `security` block (per-IP request counts, bot-like user-agent rejection, and related logging). That is a light guardrail for quiet sites, not hosting-grade rate limiting or bot defense. For real volume or abuse, use your host’s controls or a different arrangement. You can set `"security.enabled": false` if you prefer not to use it.

## Quick Start

### Local Development
```bash
# Start development server with router (recommended)
php -S localhost:8000 router.php
```

With optional `security` enabled, default `curl` is often blocked (bot-like User-Agent). Use a browser, or:

```bash
curl -H 'User-Agent: Mozilla/5.0 (local smoke test)' http://localhost:8000/
```

### Deployment
```bash
# Deploy to web server
./scripts/deploy.sh

# Remove from web server
./scripts/teardown.sh
```

## Project Structure

```
.
├── index.php              # Main application entry point (preflight install errors)
├── INSTALL.md             # Installation and removal (upload target)
├── .htaccess              # Apache security rules
├── lister/                # Application directory
│   ├── api.php            # AJAX (folder expand)
│   ├── admin.php          # Optional security log viewer (off by default)
│   ├── preview.php        # Modal text/PDF preview helper
│   ├── config/            # default.json, extensions.json
│   ├── includes/          # PHP classes
│   ├── templates/         # Listing UI, 404, runtime error chrome
│   └── assets/            # lister.css, images (favicons)
├── scripts/               # deploy.sh, teardown.sh, hooks, diagnostics
└── docs/
    ├── plan.md
    ├── requirements.md
    ├── notes.md
    ├── configuration.md
    ├── CHANGELOG.md
    ├── error-smoke-tests.md
    └── accessibility-audit.md
```

## Configuration

See [docs/configuration.md](docs/configuration.md) for hosting setup and environment configuration.

## Development

See [docs/notes.md](docs/notes.md) for development workflow and troubleshooting.

## Requirements

- PHP 8.3 or newer
- Apache with mod_php

## Acknowledgments

- **File Type Detection**: Uses [dyne/file-extension-list](https://github.com/dyne/file-extension-list) for comprehensive file extension to type mapping
- **Icons**: [Material Symbols Outlined](https://fonts.google.com/icons) via Google Fonts for file-type and folder glyphs
- **README rendering**: [Parsedown](https://github.com/erusev/parsedown) (MIT) for Markdown previews

## License

MIT License (Parsedown is bundled under its own MIT license in `lister/includes/Parsedown.php`)
