# Blurt

A tiny, anonymous, shared public feed — one communal timeline that any visitor
can post to without an account. Think anonymous shoutbox, not personal blog.

Blurt is deliberately lean: **plain PHP, no build step, no framework, no
database.** Every blurt is a single JSON file on disk. It runs with one command
and drops in behind an existing Nginx Proxy Manager (NPM) reverse proxy.

- A single post is a **blurt**.
- The compose button is the verb: **Blurt**.
- Each visitor gets an auto-generated per-session handle (e.g. `SwiftOtter42`)
  and accent color so posters are distinguishable — no logins, no usernames.

---

## Run it locally

No install step, no dependencies:

```bash
php -S localhost:8000 -t public
```

Then open <http://localhost:8000>. That's it — you can post immediately.

Requires **PHP 8.1+** (tested clean on 8.3 and 8.4), standard library only.
The `intl` (Unicode NFC) and `mbstring` extensions are used when present and
degrade gracefully when not.

The `data/` directory (holding `blurts/`, `hidden/`, and `rate/`) is created
automatically on first write.

---

## Admin area

Visit `/admin` and log in with the admin password. From there you can:

- see all **visible** blurts, with reported ones marked (`reported ×N`);
- see all **hidden** blurts and why they're hidden (`admin` or `reports`);
- **hide** a visible blurt, **restore** a hidden one (resets its reports), or
  **permanently delete** any blurt.

All admin mutations are POST + CSRF-protected.

### Generate the admin password hash

Admin login verifies the posted password against `ADMIN_PASSWORD_HASH` using
`password_verify()`. Generate a hash for your chosen password:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Set the resulting string as the `ADMIN_PASSWORD_HASH` environment variable.
**No real hash or secret is committed to this repo** — until you set it, admin
login is disabled.

> Since Blurt already sits behind NPM, you can *also* (or instead) protect
> `/admin` at the proxy with an NPM **Access List** (Basic Auth). The in-app
> session login is the built-in default so the app works standalone in dev.

---

## Configuration

Everything is read from environment variables in `config.php`, with safe
defaults and no secrets in source.

### Secrets & deployment

| Variable              | Default                  | What it does |
|-----------------------|--------------------------|--------------|
| `ADMIN_PASSWORD_HASH` | *(empty)*                | bcrypt/argon hash of the admin password. Empty disables admin login. |
| `APP_SALT`            | `change-me-in-production`| Salt mixed into the IP hash (`author_hash`). **Change this in production.** |
| `TRUSTED_PROXIES`     | *(empty)*                | Comma-separated proxy IPs whose `X-Forwarded-For` header we trust (e.g. NPM's container/host IP). Empty = trust none. |
| `SITE_TITLE`          | `Blurt`                  | Site name shown in the header and `<title>`. |
| `SITE_TAGLINE`        | `A tiny anonymous public feed.` | Optional line under the header; set empty to hide. |

### Tunable constants

| Variable               | Default | What it does |
|------------------------|---------|--------------|
| `MAX_POST_LEN`         | `280`   | Max blurt length in Unicode characters. |
| `MAX_POST_BYTES`       | `4096`  | Hard cap on raw byte length (guards multibyte bloat). |
| `MIN_SUBMIT_SECS`      | `2`     | Reject submissions faster than this after the form renders (bot speed-trap). |
| `RATE_MAX`             | `5`     | Max blurts allowed per client per `RATE_WINDOW`. |
| `RATE_WINDOW`          | `60`    | Rate-limit window in seconds. |
| `HIDE_REPORT_THRESHOLD`| `3`     | Distinct reporters needed to auto-hide a blurt. |
| `PER_PAGE`             | `20`    | Top-level blurts shown per feed page. |

Rate limiting and report-dedupe key off an **IP-based hash**, never the session
handle — so clearing a cookie won't dodge the limits.

---

## Deploying behind Nginx Proxy Manager

Blurt expects to run behind a reverse proxy that terminates SSL.

1. **Document root** must be `public/` — only that folder is web-facing. The
   `lib/`, `data/`, and `config.php` files live above it and are never served.
2. Set **`TRUSTED_PROXIES`** to NPM's IP (the address PHP sees in
   `REMOTE_ADDR`). This is required for real client-IP resolution from
   `X-Forwarded-For`; without it, every visitor looks like the proxy and rate
   limiting can't distinguish clients.
3. Terminate **SSL at NPM** and proxy plain HTTP to the app. Blurt reads
   `X-Forwarded-Proto` (only from a trusted proxy) to set the secure cookie flag.
4. Set **`APP_SALT`** and **`ADMIN_PASSWORD_HASH`** as environment variables.

### With Docker (optional)

A minimal `Dockerfile` based on `php:8.3-apache` is included, with the Apache
document root already pointed at `public/`:

```bash
docker build -t blurt .
docker run -d -p 8080:80 \
  -e APP_SALT="$(php -r 'echo bin2hex(random_bytes(16));')" \
  -e ADMIN_PASSWORD_HASH='<your-hash>' \
  -e TRUSTED_PROXIES='172.18.0.1' \
  -e SITE_TITLE='Blurt' \
  -v "$PWD/data:/var/www/html/data" \
  blurt
```

Mount a volume at `/var/www/html/data` to persist blurts across restarts.
This drops straight into a Docker/Coolify + NPM setup with no extra wiring.

---

## How it works

### Layout

```
blurt/
  public/            <-- docroot (the only web-facing folder)
    index.php        feed + compose form (server-rendered)
    submit.php       handle a new blurt / reply (POST)
    report.php       handle a report (POST)
    admin.php        session-gated admin area
    assets/
      style.css
      app.js         optional enhancement (live char counter)
  lib/               <-- NOT web-accessible
    helpers.php      escaping, ids, IP resolution, security headers
    storage.php      read/write/list/move/delete blurts; safe id->path
    moderation.php   blocklist, honeypot, time-trap, rate limit, normalization
    identity.php     per-session handle + color
    auth.php         admin session + CSRF helpers
    blocklist.php    starter blocked-terms list (expand it)
    words.php        adjective/animal wordlists for handles
  data/              <-- NOT web-accessible; created at runtime
    blurts/          visible blurts, one JSON file each
    hidden/          hidden blurts (3+ reports, or admin-hidden)
    rate/            per-client rate-limit tracking
  config.php         env vars + tunable constants + bootstrap
  Dockerfile         optional
```

### Data model

One file per blurt, named `{unix_ts}-{6-char-rand}.json` so filenames sort
chronologically:

```json
{
  "id": "1721001234-a1b2c3",
  "parent_id": null,
  "text": "raw user text, stored unescaped",
  "created_at": 1721001234,
  "display_name": "SwiftOtter42",
  "display_color": "#c2410c",
  "report_count": 0,
  "reporter_hashes": [],
  "hidden_by": null,
  "author_hash": "sha256(client_ip + APP_SALT)"
}
```

- Text is stored **raw** and escaped only at output with
  `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` — the primary XSS defense.
- `author_hash` never stores a raw IP and is never shown; it exists only so
  rate limiting and report-dedupe work without retaining PII.
- Visibility is determined by which directory the file lives in
  (`blurts/` vs `hidden/`); `hidden_by` records the reason for the admin view.

### Moderation lifecycle

`normal -> reported (still visible, shows a badge) -> hidden`

- The first distinct report flags a blurt as reported but keeps it visible.
- Reaching `HIDE_REPORT_THRESHOLD` distinct reporters moves it to `data/hidden/`
  with `hidden_by = "reports"`.
- An admin can hide, restore, or permanently delete any blurt at any time.

### Security summary

- **Output escaping** everywhere — posted HTML/JS renders as inert text.
- **Safe file handling** — client-supplied ids are validated against a strict
  pattern, `basename()`'d, and `realpath()`-checked inside `data/` before any
  read/write/delete. Path traversal (`../`) is rejected.
- **No code from data** — blurts are `.json` only; never `eval`/`include`/
  `unserialize`d, and always built with `json_encode()`.
- **Strict CSP** with no inline scripts/styles, plus `nosniff`,
  `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`.
- **Input normalization** — strips null bytes, control chars, bidi overrides,
  and zero-width chars; normalizes to NFC; blunts zalgo; caps length and bytes.
- **CSRF + time-trap** on every form, **honeypot** field, **blocklist**, and
  **per-IP rate limiting** — all enforced server-side.

---

## License

See [LICENSE](LICENSE).
