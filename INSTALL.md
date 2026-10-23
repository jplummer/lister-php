# Lister installation

## What to upload

In your web root (or the directory that should run Lister), place:

- `index.php`
- `.htaccess`
- `lister/` (the whole directory)

Do not upload `docs/`, `scripts/`, `router.php`, or other development-only files from the repository. You may keep a copy of this file for reference.

## Permissions

```bash
chmod 644 index.php .htaccess
chmod 644 lister/config/*.json
```

Lister creates `lister/data/` at runtime if needed (security logs and related files). The PHP process must be able to create and write that directory. On many hosts the default upload permissions are enough; if you see errors mentioning `lister/data/`, try `chmod 755 lister` (and create `lister/data` with `chmod 755` if your host requires it).

## Check that it works

Visit your site in a browser. If optional `security` is enabled in config, a bare `curl` without a normal browser User-Agent may be rejected—use the browser for a first check.

## Layout on the server

```
your-domain.com/
├── index.php       # Entry point; preflight errors if setup is incomplete
├── .htaccess       # Apache routing and hardening (production; not router.php)
└── lister/
    ├── api.php         # AJAX (e.g. folder expand)
    ├── admin.php       # Security log viewer; off unless security.admin_enabled (see docs/configuration.md)
    ├── preview.php     # Text/PDF preview helper
    ├── config/         # default.json (required), extensions.json (optional)
    ├── includes/       # PHP classes
    ├── templates/      # Listing UI, HTTP 404, runtime error chrome
    ├── assets/         # CSS, images (e.g. favicons)
    └── data/           # Created at runtime; not in git
```

For the full repository map (including dev files), see `docs/notes.md`.

## Configuration

Edit `lister/config/default.json` for paths, display, optional `security`, and `app.debug`. For hosting variables and deploy scripts, see `docs/configuration.md`.

## Legacy file

Very old installs may still have `install_error.php` next to `index.php`. Remove it after upgrading; preflight messages come only from `index.php`.

## Removal

- **Automated:** from a machine with the repo and a configured `.env`, run `./scripts/teardown.sh` (removes `index.php`, `.htaccess`, and `lister/` on the remote path).
- **Manual:** delete those same paths.
- Delete `install_error.php` yourself if it is still there.

## More help

- `docs/configuration.md` — environment, hosting notes, pointers to error checks
- `docs/notes.md` — local development, deploy workflow, troubleshooting
