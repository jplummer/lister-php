# Lister Development Notes

## Quick Start

### Local Development
```bash
# Start local PHP server with router (required)
php -S localhost:8000 router.php

# Test the application (browser is reliable; default curl User-Agent is blocked when security is on)
curl -H 'User-Agent: Mozilla/5.0 (local smoke test)' http://localhost:8000/
# or visit http://localhost:8000 in a browser

# Stop server (if running in background)
pkill -f "php -S localhost:8000"
```

**Note: router.php**: Required for local development. Located in your project root (same folder as `index.php`). The PHP built-in server doesn't handle directory URLs the same way Apache does, so router.php ensures directory requests are properly routed to `index.php`. **Don't deploy to production** as remote servers use `.htaccess` for routing instead.

### PHP Syntax Check
```bash
# Check PHP syntax
php -l index.php

# Check all PHP files
find . -name "*.php" -exec php -l {} \;
```

## Project Structure

Repository root (same directory as `index.php`, `router.php`, and `.htaccess`):

```
├── index.php              # Main application entry point – DEPLOYABLE (preflight install errors inline)
├── router.php             # PHP built-in server router (dev only)
├── .htaccess              # Apache security rules – DEPLOYABLE
├── lister/                # Application directory – DEPLOYABLE
│   ├── config/
│   │   ├── default.json   # Default configuration
│   │   └── extensions.json # File type mappings
│   ├── includes/          # PHP classes
│   │   ├── App.php        # Main application class
│   │   ├── ConfigLoader.php # Loads default.json
│   │   ├── PathSanitizer.php # Traversal strip + URL decode for paths
│   │   ├── IconSymbols.php # File-type icon key → Material symbol name (App + template JS)
│   │   ├── DirectoryLister.php # Directory scanning
│   │   ├── ReadmePreview.php # README file detection + render (Markdown / plain)
│   │   ├── Parsedown.php  # Bundled Markdown parser (vendor, MIT)
│   │   ├── Security.php   # Security & rate limiting
│   │   └── SortPreference.php # Sort cookie / query handling
│   ├── templates/
│   │   ├── index.php      # Main listing template
│   │   ├── http_error_404.php # In-app 404 body
│   │   ├── runtime_error.php # Uncaught error body (index.php catch)
│   │   ├── _error_chrome_top.php # Shared error chrome: head, header, <main> open
│   │   └── _error_chrome_bottom.php # Shared error chrome: </main>, footer
│   ├── assets/
│   │   ├── lister.css     # Stylesheet
│   │   └── images/        # Favicon JPEGs (flat under images/)
│   ├── api.php            # AJAX API endpoint
│   ├── admin.php          # Security admin panel
│   ├── preview.php        # Modal text/PDF preview helper
│   └── data/              # Runtime data (git ignored)
│       ├── security.log   # Security incidents
│       └── rate_*.json    # Rate limiting data
├── scripts/               # Utility scripts
│   ├── deploy.sh          # Deployment script
│   ├── teardown.sh        # Removal script
│   ├── diagnose_favicon.php
│   ├── setup-git-hooks.sh
│   └── pre-commit-changelog.sh
└── docs/
    ├── plan.md            # Development plan
    ├── requirements.md    # Project requirements
    ├── notes.md           # This file
    ├── configuration.md   # Configuration guide
    ├── CHANGELOG.md       # Release-style change log
    ├── error-smoke-tests.md # Post-deploy error checks
    └── accessibility-audit.md # A11y notes
```

## Development Workflow

### Standard Process
1. **Edit files** in your IDE
2. **Test locally**: Start server with `php -S localhost:8000 router.php`, then visit `http://localhost:8000` in browser
3. **Test on host**: If local testing looks good, deploy and test on production server
4. **Commit changes**: If everything works, commit your changes

**About the router**: `router.php` is in your local project root (same folder as `index.php` and `lister/`) and is only used for local development. Always use it when starting the local server - the PHP built-in server doesn't handle directory URLs the same way Apache does, so without the router, directory listings won't work properly. **Do not deploy `router.php` to your remote server** - production uses `.htaccess` for routing instead.

### Optional: Syntax Checking
Before testing, you can check for PHP syntax errors: `php -l filename.php`. This catches typos and parse errors that would prevent the server from starting. Useful if the server won't start and you're not sure why.

### Optional: API Testing
The app uses `lister/api.php` for AJAX requests when expanding directories. If directory expansion isn't working, you can test the API directly:
```bash
# With security enabled, curl is treated as a bot (User-Agent contains "curl").
# Use a browser-like User-Agent for local checks, e.g.:
curl -X POST -d 'path=some/relative/folder' \
  -H 'User-Agent: Mozilla/5.0 (local API test)' \
  http://localhost:8000/lister/api.php
```
Or use browser dev tools Network tab to inspect API responses. Only needed when debugging directory expansion issues.

### 3. Deployment to Web Server
```bash
# Deploy to web server
./scripts/deploy.sh

# Remove from web server
./scripts/teardown.sh

# Option 1: Direct upload via SFTP
# Upload all files to target directory on your web server

# Option 2: Git-based deployment
git add .
git commit -m "Your commit message"
git push origin main
# Then pull on your web server

# Option 3: Automated deployment (future)
# Set up CI/CD pipeline
```

### 4. Configuration
- Edit `lister/config/default.json` for app settings (loaded via `lister/includes/ConfigLoader.php`)
- Modify `.htaccess` for Apache rules
- Update `lister/includes/App.php` for request handling and page rendering
- Security admin at `yourdomain.com/lister/admin.php` is off by default; see `security.admin_enabled` in `docs/configuration.md`

## Common Commands

### Tests
```bash
php scripts/test_security.php   # exits 1 on any failure
```

### PHP Development
```bash
# Start development server with router
php -S localhost:8000 router.php

# Check PHP version
php --version

# Run PHP with specific ini file
php -c /path/to/php.ini -S localhost:8000 router.php

# Check loaded extensions
php -m

```

### File Operations
```bash
# Check file permissions
ls -la

# Set proper permissions (if needed)
chmod 644 *.php
chmod 644 lister/config/*.json
chmod 644 .htaccess
chmod 755 lister/
chmod 755 lister/includes/
chmod 755 lister/templates/
chmod 755 lister/assets/

# Check security logs
tail -f lister/data/security.log
```

### Git Operations
```bash
# Check status
git status

# Add changes
git add .

# Commit changes
git commit -m "Descriptive commit message"

# Push to remote
git push origin main

# Pull latest changes
git pull origin main
```

## Troubleshooting

### Common Issues

**PHP Server Won't Start**
- Check if port 8000 is in use: `lsof -i :8000`
- Try different port: `php -S localhost:8080 router.php`
- Check PHP installation: `which php`

**Syntax Errors**
- Run `php -l filename.php` to check syntax
- Check for missing semicolons, brackets, quotes
- Verify PHP version compatibility

**Permission Issues**
- Check file permissions: `ls -la`
- Ensure web server can read files
- Check `.htaccess` rules

**Configuration Issues**
- Verify `lister/config/default.json` is valid JSON
- Check file paths are correct
- Ensure all required directories exist
- Check security configuration in admin panel

### Debug Mode
```bash
# Enable error reporting in PHP (still pass router for directory URLs)
php -d display_errors=1 -S localhost:8000 router.php

# Check PHP error log (path depends on install; Homebrew PHP on macOS often uses:)
tail -f /opt/homebrew/var/log/php/error.log
```


## Environment Setup

### Local Development
- **PHP**: 8.x CLI (exact version depends on your install, e.g. Homebrew)
- **Server**: Built-in PHP development server with `router.php`
- **OS**: macOS (darwin version varies with OS updates)

### Production (Dreamhost)
- **PHP**: 8.x (Dreamhost compatible)
- **Server**: Apache with mod_php
- **OS**: Linux

## File Types & Extensions

### PHP Files
- `.php` - Main application files
- `.json` - Configuration files
- `.htaccess` - Apache configuration

### Frontend Files
- `.css` - Stylesheets
- `.js` - JavaScript
- `.html` - HTML templates (if needed)

### Documentation
- `.md` - Markdown documentation
- `.txt` - Plain text files

## Security Considerations

### .htaccess Rules
- Deny access to config files
- Hide PHP includes from web access
- Block hidden files
- Enable clean URLs

### PHP Security
- Input validation and sanitization
- Rate limiting (`max_requests_per_minute` in config; default 30 per minute)
- Bot detection (matches curl, wget, and other tool-like user agents when enabled)
- Suspicious request detection
- Security logging and admin panel
- Error handling

## Performance Tips

### Local Development
- Use PHP's built-in server for development
- Enable opcache in production
- Minimize file I/O operations
- Use efficient data structures

### Production
- Enable gzip compression
- Use CDN for static assets
- Implement caching strategies
- Monitor server resources


## Technology Choices

The application is installed in a web folder on any PHP-compatible hosting provider. Technologies considered during development:
* cURL
* Git
* SOAP
* CGI
* .htaccess (selected)
* JSON
* Perl
* PHP (selected)
* Laravel
* Cake
* Python
* Ruby
* mod-rewrite
* SFTP
* SSH

## Useful Resources

- [PHP Manual](https://www.php.net/manual/)
- [Apache .htaccess Guide](https://httpd.apache.org/docs/current/howto/htaccess.html)
- [Dreamhost PHP Documentation](https://help.dreamhost.com/hc/en-us/categories/115000252511-PHP)
- [JSON Configuration](https://www.json.org/)

