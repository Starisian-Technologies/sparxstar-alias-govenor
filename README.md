# sparxstar-alias-governor

An SEO-compatible, domain governor MU-plugin for WordPress Multisite with Mercator that enforces the alias domain on the frontend during runtime.

## Overview

This stack (sunrise + MU-plugin + `wp-config.php` constants) provides:

| Feature | Detail |
|---|---|
| **Mercator-safe** | No table guessing; uses Mercator's own sunrise for domain mapping |
| **Defender-safe** | Custom login slug supported via `SPX_DEFENDER_LOGIN_SLUG` |
| **Admin / login** | Always served on `sparxstar.com` (primary domain) |
| **Frontend** | Always served on the alias domain |
| **Canonical** | Set to alias domain (supports both WordPress core and Yoast SEO) |
| **Sitemap URLs** | Rewritten to alias domain (Yoast SEO sitemap filter) |
| **Hard 403 option** | Block alias login attempts instead of redirecting (commented toggle) |
| **Return-to-alias** | After login on primary, user is sent back to the alias |

---

## Files

```
wp-content/
├── sunrise.php                        ← early lock + Mercator include
└── mu-plugins/
    └── spx-alias-governor.php         ← frontend alias, canonical, post-login return
```

`wp-config.php` constants are documented below but the file itself is not committed (it is in `.gitignore`).

---

## Setup

### Step 0 – `wp-config.php` constants

Add these constants **once**, before the `/* That's all, stop editing! */` line:

```php
define( 'SUNRISE', true );

define( 'SPX_PRIMARY_DOMAIN', 'sparxstar.com' );

/**
 * Defender "Hide Login" slug (the custom login path).
 * Example: if your login URL is https://sparxstar.com/secure-access/
 * set this to: secure-access
 */
define( 'SPX_DEFENDER_LOGIN_SLUG', 'secure-access' );

/**
 * Cookie isolation – auth cookies stay on the primary domain so alias
 * domains cannot carry wp-admin sessions.
 * Remove COOKIE_DOMAIN only if it breaks something.
 */
define( 'COOKIE_DOMAIN', SPX_PRIMARY_DOMAIN );
```

### Step 1 – `wp-content/sunrise.php`

Copy `wp-content/sunrise.php` from this repository to your install.

This file runs **before WordPress loads** (no WP functions available).  It:

1. Detects whether the current request is for `wp-admin`, `wp-login.php`, or
   the Defender custom login slug.
2. If a sensitive URL is accessed on an alias domain, it redirects to the
   primary domain and appends `?spx_return=<alias-host>` so the MU-plugin can
   return the user to the alias after login.
3. Loads Mercator's own sunrise (checks both `mu-plugins/` and `plugins/`).

**Optional hard-block mode (403):**  
Open `wp-content/sunrise.php` and swap the commented-out 403 block for the
redirect block if you never want alias login attempts forwarded.

### Step 2 – `wp-content/mu-plugins/spx-alias-governor.php`

Copy `wp-content/mu-plugins/spx-alias-governor.php` from this repository to
your install.

This MU-plugin (loaded automatically by WordPress) handles:

- **Primary → alias redirect**: If a user hits the primary domain on a frontend
  page, they are 301-redirected to the canonical alias (Mercator provides the
  mapping via `home_url()`).
- **URL generator overrides**: `home_url` and `site_url` filters rewrite
  generated links to use the alias host when serving the alias.
- **Canonical tag**: Both the WordPress core canonical and the Yoast SEO
  canonical are overridden to the alias.
- **Sitemap URLs**: Yoast SEO sitemap entries are rewritten to the alias domain.
- **xmlrpc.php hardblock**: Returns 403 for XML-RPC requests on alias domains.
- **Post-login return**: The `login_redirect` filter reads `spx_return` (set by
  sunrise) and sends the user back to the alias after a successful login.

---

## How It Works (request flow)

```
Browser request
      │
      ▼
 sunrise.php (pre-WP)
      │
      ├── alias + admin/login? ──► 302 → primary domain (+ spx_return param)
      │
      └── ok ──► load Mercator sunrise (domain → blog mapping)
                        │
                        ▼
              WordPress / MU-plugin
                        │
              ┌─────────┴──────────┐
              │                    │
         primary domain        alias domain
         frontend?              frontend?
              │                    │
              ▼                    ▼
        301 → alias          override home_url,
                             site_url, canonical,
                             sitemap URLs
```

---

## Requirements

- WordPress Multisite
- [Mercator](https://github.com/humanmade/mercator) (mu-plugin or plugin install)
- WPMU DEV Defender (optional; `SPX_DEFENDER_LOGIN_SLUG` activates support)
- Yoast SEO (optional; canonical + sitemap filters activate automatically)
- PHP 7.4+

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
