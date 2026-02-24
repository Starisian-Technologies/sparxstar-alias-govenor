
<img width="1280" height="640" alt="sparxstar-alias-governor" src="https://github.com/user-attachments/assets/845ce9d1-aa0d-455f-8b2e-d9f049ca79fd" />

# SPARXSTAR Alias Governor 

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
| **Asset URLs** | `wp_get_attachment_url`, `srcset`, `script_loader_src`, `style_loader_src`, and more rewritten to alias via deep WP filters |
| **HTML buffer** | Final safety net: `ob_start` on `template_redirect` catches any hard-coded primary-domain URLs in post content or theme templates |
| **Hard 403 option** | Block alias login attempts instead of redirecting (commented toggle) |
| **Return-to-alias** | After login on primary, user is sent back to the alias |

---

## Repository Layout

```
mu-plugins/
└── spx-alias-governor.php   ← frontend alias, canonical, post-login return
sunrise.php                  ← early lock + Mercator include
```

`wp-config.php` constants are documented below but the file itself is not
committed to this repository.

---

## Installation

### Option A – Download a release (recommended)

1. Go to the [**Releases**](../../releases) page and download the latest
   `spx-alias-governor-{version}-{sha}.zip`.
2. Extract the zip. It contains a `wp-content/` folder with the files already
   in the correct locations:
   ```
   wp-content/
   ├── sunrise.php
   └── mu-plugins/
       └── spx-alias-governor.php
   ```
3. Copy both files into your WordPress installation's `wp-content/` directory,
   preserving the sub-paths shown above.

### Option B – Clone / copy from source

Copy the files manually to your WordPress installation:

| Repository path | WordPress install path |
|---|---|
| `sunrise.php` | `wp-content/sunrise.php` |
| `mu-plugins/spx-alias-governor.php` | `wp-content/mu-plugins/spx-alias-governor.php` |

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
 * DO NOT define COOKIE_DOMAIN when using Mercator.
 * Mercator manages cookie domains internally; defining COOKIE_DOMAIN
 * will cause the error:
 *   "The constant COOKIE_DOMAIN is defined (probably in wp-config.php).
 *    Please remove or comment out that define() line."
 * Leave this constant undefined so Mercator can function correctly.
 */
// define( 'COOKIE_DOMAIN', SPX_PRIMARY_DOMAIN ); // ← MUST remain commented out with Mercator
```

### Step 1 – `wp-content/sunrise.php`

Place `sunrise.php` (from this repository) at `wp-content/sunrise.php` in
your WordPress installation.

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

Place `mu-plugins/spx-alias-governor.php` (from this repository) at
`wp-content/mu-plugins/spx-alias-governor.php` in your WordPress installation.

This MU-plugin (loaded automatically by WordPress) handles:

- **Primary → alias redirect**: If a user hits the primary domain on a frontend
  page, they are 301-redirected to the canonical alias (Mercator provides the
  mapping via `home_url()`).
- **URL generator overrides**: `home_url` and `site_url` filters rewrite
  generated links to use the alias host when serving the alias.
- **Canonical tag**: Both the WordPress core canonical and the Yoast SEO
  canonical are overridden to the alias.
- **Deep asset filters**: `wp_get_attachment_url`, `wp_get_attachment_image_src`,
  `wp_calculate_image_srcset`, `content_url`, `plugins_url`, `theme_file_uri`,
  `stylesheet_directory_uri`, `template_directory_uri`, `script_loader_src`,
  `style_loader_src`, and `rest_url` are all rewritten to the alias domain so
  that `srcset` and similar attributes never leak the primary domain.
- **HTML output buffer**: An `ob_start` callback registered on `template_redirect`
  replaces any remaining hard-coded primary-domain URLs in the final HTML
  document (post content, theme templates, etc.) while leaving uploads-subdomain
  URLs and non-HTML responses (REST, feeds, AJAX) untouched.
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
                             asset filters (srcset,
                             script/style src, etc.),
                             HTML output buffer,
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
