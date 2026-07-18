# Blurt

A tiny, anonymous, shared public feed — one communal timeline that any visitor
can post to without an account. Think anonymous shoutbox, not personal blog.

Blurt is deliberately lean: **plain PHP, no build step, no framework, no
database.** Every blurt is a single JSON file on disk. It runs with one command
and drops in behind an existing Nginx Proxy Manager (NPM) reverse proxy.

- A single post is a **blurt**.
- The compose button is the verb: **Blurt**.
- **Every blurt vanishes 24 hours after it's posted** — the feed is always
  fresh, nothing lingers, and there's nothing to prune by hand. Ephemerality
  is core to what Blurt is.
- Each visitor gets an auto-generated per-session handle (e.g. `SwiftOtter42`)
  and accent color so posters are distinguishable — no logins, no usernames.
- Anyone can **react** to a blurt with an emoji (👍 👎 ❤️ 😂 😮 😢); reactions
  are deduped per visitor and work with or without JavaScript.

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

- see all **visible** blurts (with their reaction totals);
- see all **hidden** blurts (`hidden by admin`);
- **hide** a visible blurt, **restore** a hidden one, or **permanently
  delete** any blurt.

All admin mutations are POST + CSRF-protected. (There is no visitor-facing
report/flag flow — since every blurt self-deletes within 24 hours, moderation
is just the admin hide/delete controls plus automatic blocklist censoring.)

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
| `APP_SALT`            | `change-me-in-production`| Secret random string hashed with each visitor's IP so raw IPs are never stored. Optional to run; **set your own in production** (see [below](#about-app_salt)). |
| `TRUSTED_PROXIES`     | *(empty)*                | Comma-separated proxy IPs whose `X-Forwarded-For` header we trust (e.g. NPM's container/host IP). Empty = trust none. |
| `SITE_TITLE`          | `Blurt`                  | Site name shown in the header and `<title>`. |
| `SITE_TAGLINE`        | `Anonymous, and gone in 24 hours.` | Optional line under the header; set empty to hide. |

#### About `APP_SALT`

`APP_SALT` is a secret string that gets hashed together with each visitor's IP
address to produce the `author_hash` used for rate limiting. This is how Blurt
avoids ever storing a raw IP — and a secret salt keeps those hashes from being
reversed back into IPs.

- **Is it required?** No — the app runs without it (it falls back to a built-in
  default), so you can ignore it for local development. For any public
  deployment you should **set your own value**, because the default is public
  and therefore offers no protection.
- **What should go in it?** Any long, random, secret string — treat it like a
  password. There's no required format or length, but aim for 32+ random
  characters. Generate one with either of:

  ```bash
  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
  openssl rand -hex 32
  ```

  Then set it as the `APP_SALT` environment variable (don't commit it to
  source control).
- **Set it once and leave it.** It doesn't need to be memorable or rotated.
  Changing it later just resets everyone's rate-limit counters — harmless, but
  there's no reason to.

### Tunable constants

| Variable               | Default | What it does |
|------------------------|---------|--------------|
| `MAX_POST_LEN`         | `280`   | Max blurt length in Unicode characters. |
| `MAX_POST_BYTES`       | `4096`  | Hard cap on raw byte length (guards multibyte bloat). |
| `MIN_SUBMIT_SECS`      | `2`     | Reject submissions faster than this after the form renders (bot speed-trap). |
| `RATE_MAX`             | `5`     | Max blurts allowed per client per `RATE_WINDOW`. |
| `RATE_WINDOW`          | `60`    | Rate-limit window in seconds. |
| `REACT_MAX`            | `30`    | Max reactions per client per `RATE_WINDOW` (its own budget, separate from posting). |
| `PER_PAGE`             | `20`    | Top-level blurts shown per feed page. |
| `POST_TTL`             | `86400` | Blurt lifetime in seconds. Every blurt (replies included) is removed this long after it was posted. Default is 24 hours. |
| `PURGE_INTERVAL`       | `60`    | Minimum seconds between expiry sweeps. Cleanup is lazy (no cron); this throttles how often a request triggers a sweep. |

Rate limiting and reaction-dedupe key off an **IP-based hash**, never the
session handle — so clearing a cookie won't dodge the limits.

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
    react.php        toggle an emoji reaction (POST; JSON when enhanced)
    admin.php        session-gated admin area
    assets/
      style.css
      app.js         optional enhancement (char counter, live countdown, reactions)
      og-image.png   social-share preview image
  lib/               <-- NOT web-accessible
    helpers.php      escaping, ids, IP resolution, security headers, avatar/favicon
    storage.php      read/write/list/move/delete blurts; safe id->path; expiry sweep
    moderation.php   blocklist censoring, honeypot, time-trap, rate limit, reactions
    identity.php     per-session handle + color
    auth.php         admin session + CSRF helpers
    blocklist.php    starter blocked-terms list (expand it)
    words.php        adjective/animal wordlists for handles
  data/              <-- NOT web-accessible; created at runtime
    blurts/          visible blurts, one JSON file each
    hidden/          hidden blurts (admin-hidden)
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
  "text": "raw user text, blocklisted words masked",
  "created_at": 1721001234,
  "display_name": "SwiftOtter42",
  "display_color": "#7c3aed",
  "reactions": { "👍": ["<hash>", "<hash>"], "❤️": ["<hash>"] },
  "hidden_by": null,
  "author_hash": "sha256(client_ip + APP_SALT)"
}
```

- Text is stored **raw** and escaped only at output with
  `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` — the primary XSS defense.
  Blocklisted words are masked with asterisks before storing (see below).
- `reactions` maps each whitelisted emoji to the list of `author_hash`es that
  reacted; the public count is `count()` of that list, so a visitor can't
  inflate a tally. Empty on a fresh blurt, added lazily on first reaction.
- `author_hash` never stores a raw IP and is never shown; it exists only so
  rate limiting and reaction-dedupe work without retaining PII.
- Visibility is determined by which directory the file lives in
  (`blurts/` vs `hidden/`); `hidden_by` is `null` or `"admin"`.

### Reactions

Visitors react with a fixed, server-side whitelist of emoji (👍 👎 ❤️ 😂 😮 😢).

- `react.php` toggles one emoji for the caller: their `author_hash` is added to
  (or removed from) that emoji's list. Distinct reactors only.
- Every id is validated through `storage.php`, the emoji is checked against the
  whitelist, and reactions have their own per-client rate budget (`REACT_MAX`).
- Works as a plain form POST (redirects back to the blurt); when JavaScript is
  on, `app.js` submits via `fetch` and toggles the chip with no page reload.

### Moderation

Since every blurt self-deletes within `POST_TTL`, moderation is intentionally
light — there's no visitor-facing report/flag flow.

- **Blocklist censoring.** Blocklisted words (word-boundary, case-insensitive)
  are replaced with asterisks at store time, so a post goes through with the
  flagged words masked rather than being rejected.
- **Admin controls.** An admin can hide, restore, or permanently delete any
  blurt at any time (`hidden_by = "admin"`).

### Ephemerality

Every blurt lives for `POST_TTL` seconds (24 hours by default) and is then
removed — this is a defining feature, not just cleanup.

- **Lazy, no cron.** Expired blurts are swept away by ordinary web requests. A
  throttled sweep (`PURGE_INTERVAL`, default 60s) scans `data/blurts/` and
  `data/hidden/`, deletes anything past its lifetime, and also clears stale
  per-client rate files. Nothing external needs to run — no cron, no worker,
  no dependency to maintain. (If you prefer, you can still call
  `php -r 'require "config.php"; purge_expired();'` from cron; it's not needed.)
- **Never shows stale posts.** The feed and admin views also filter by
  expiry directly, so an expired blurt never appears even in the brief window
  before the next sweep deletes its file.
- **Replies count too.** A reply lives 24 hours from when *it* was posted, and
  you can't reply to a blurt that has already expired.
- **Visible countdown + fade.** Each blurt shows a "vanishes in 23h" chip that
  turns urgent in its final stretch, and every blurt gently fades as it nears
  its vanishing time (both kept live by `app.js` when JavaScript is on). The
  compose box notes the lifetime too — so the ephemerality is always visible,
  never a surprise.

Because everything self-expires, `data/` stays small on its own and backups
are naturally short-lived.

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
- **CSRF + time-trap** on every form, **honeypot** field, **blocklist
  censoring**, whitelisted emoji reactions, and **per-IP rate limiting** — all
  enforced server-side.
- **Social-share tags** (Open Graph + Twitter card) with a bundled preview
  image, so a shared link renders a proper card.

---

## License

See [LICENSE](LICENSE).
