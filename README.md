# 👁️ The All Seeing Eye

<div align="center">

![HTML](https://img.shields.io/badge/HTML-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)
![Version](https://img.shields.io/badge/version-2.0.2-brightgreen?style=for-the-badge)
![Self-hosted](https://img.shields.io/badge/self--hosted-no_server_needed-blue?style=for-the-badge)

**Open-source uptime, DNS, SSL and latency monitor. One HTML file. Zero dependencies.**

Know what's up — and what isn't — across all your domains, at a glance. 🌐

<a href="https://paulfleury.com/github/the-all-seeing-eye.png">
  <img src="https://paulfleury.com/github/the-all-seeing-eye.png" alt="The All Seeing Eye — domain monitor dashboard" width="700" />
</a>

*Click image to view full resolution*

</div>

---

## 👨‍💻 The Story Behind This

I'm **Paul Fleury** — founder, builder, and someone who manages a lot of domains across several projects and businesses. Between personal domains, client sites, SaaS products, and holding registrations, I had **30+ domains I cared about** — and no single place to see their health at a glance.

Commercial uptime monitors are either overkill (full APM suites) or too simple (just HTTP pings). I wanted something that showed:
- **Is it up?** with real latency numbers
- **Who hosts the DNS?** (SiteGround, Cloudflare, AWS…)
- **Who handles the mail?** (ProtonMail, Google, Microsoft…)
- **Is DMARC configured?** (surprisingly many domains have this missing)
- **When does the SSL expire?**

And I wanted it to be **self-hosted**, **PIN-protected**, and look good.

This project was designed and built **in collaboration with [Perplexity Computer](https://www.perplexity.ai/computer)** — from architecture through implementation, debugging the SHA-256 caching bug, fixing sandboxed iframe PIN issues, and designing the live progressive DNS scan. A real example of human + AI building something genuinely useful.

> 💡 If you manage multiple domains and want a beautiful, self-hosted status page — this is for you. Free, open-source, one HTML file.

---

## 🌟 What is this?

A **self-hosted infrastructure dashboard** that monitors uptime, DNS records, SSL certificates and mail security for any list of domains — entirely in the browser, with no backend required.

- 🔍 **Live DNS checks** via Cloudflare DoH (HTTPS, no CORS issues)
- 🔐 **PIN-protected** dashboard (SHA-256 hashed — no plaintext stored)
- 🌓 **Light / Dark mode** toggle (light by default)
- ⚡ **Progressive scan** — rows light up one batch at a time as results arrive
- 🔄 **Per-row refresh** — re-scan any single domain with the ↺ button
- ⏱️ **Auto-refresh** every 3 minutes with live countdown
- 🚦 **Rate limiting** — anti-spam guards prevent firewall-triggering burst queries
- 📋 **Export CSV** — download a timestamped snapshot any time
- ➕ **Add domains live** — type any domain, it's checked immediately
- 📁 **`domains.list`** — edit a plain text file to manage your watchlist
- 🤖 **PHP cron script** — runs server-side on SiteGround/cPanel, no chmod tricks
- 🔗 **Webhook endpoint** — point any external cron (cron-job.org) at `webhook.do`

---

## 🎬 What it monitors

For every domain, five DNS queries fire in parallel:

| Record | What it reveals |
|---|---|
| `A` | Is the domain resolving? Round-trip latency? |
| `NS` | Nameserver provider (Cloudflare, AWS, SiteGround, Azure…) |
| `MX` | Mail provider (Google, ProtonMail, Microsoft, Amazon SES…) |
| `TXT` | SPF record (`v=spf1 … ~all`) |
| `_dmarc TXT` | DMARC policy (`reject` / `quarantine` / `none` / `missing`) |

Results appear **progressively** as each batch of 5 domains resolves — you see the table fill in live.

---

## 🛠️ What's in the box

| File | Purpose |
|---|---|
| `index.html` | The full application — HTML shell that loads `app.css` and `app.js` |
| `app.css` | All styles (41 KB) |
| `app.js` | All JavaScript (82 KB) |
| `domains.list` | Your domain watchlist — one domain per line, `#` for comments |
| `domains.stats` | CSV snapshot updated after every check (requires server write access) |
| `domains.json` | Written by `update-stats.php` — feeds SSL expiry data to the browser |
| `update-stats.php` | Server-side cron script — real TLS cert checks, writes `domains.json` |
| `webhook.do` | Headless endpoint for external cron services (cron-job.org etc.) |
| `INSTALL.md` | Full installation guide |
---

## 📦 Quick Start

### Drop-in install (any web server)

```bash
# 1. Clone the repo (or download the ZIP — link below)
git clone https://github.com/paulfxyz/the-all-seeing-eye.git
cd the-all-seeing-eye

# 2. Upload all files to your web server
# scp -r . user@yourhost:/public_html/uptime/

# 3. Visit https://yourdomain.com/uptime/
# Enter PIN 123456 → you'll be prompted to set a personal PIN
```

No npm, no Composer, no build step. Upload `index.html`, `app.css`, `app.js`, and `domains.list` — that's everything you need.

### Using as a local file

```bash
open index.html
# Requires a local web server for domains.list to load.
# Built-in top-30 list is used as fallback.
```
---

## 🔑 Default PIN

The default PIN is **`123456`**.

On your **first login**, after entering `123456` you will be automatically prompted to set a personal PIN — you can set one or click "Skip" to keep using the default. **Change it before deploying publicly.**

Once inside the dashboard, the **⚙️ cog icon** in the header lets you change your PIN at any time — enter your current PIN, then your new PIN twice.

**Change it before deploying publicly** — see [INSTALL.md](./INSTALL.md#changing-the-pin).

The PIN is stored as a SHA-256 hash in `index.html` — no plaintext, ever.
## ⚙️ Automated Checks (cron)

The dashboard auto-refreshes every 3 minutes when open. For 24/7 monitoring:

### Option A — cPanel / SiteGround (PHP script)

Add to cPanel → Cron Jobs:
```bash
*/10 * * * * php /home/YOURUSER/public_html/uptime/update-stats.php >> /home/YOURUSER/public_html/uptime/cron.log 2>&1
```

Runs as your user — no `chmod 666` needed. Writes `domains.stats` + `domains.json`.

### Option B — cron-job.org (free, no server config)

1. Create free account at [cron-job.org](https://cron-job.org)
2. Add cron job: `GET https://yourdomain.com/uptime/webhook.do` every 10 minutes
3. Done — works on any host including static sites

Full setup guide: [INSTALL.md](./INSTALL.md)

---

## 🎨 Customisation

| What | Where in `index.html` | Default |
|---|---|---|
| 🔐 PIN | `var PIN_HASH = '...'` | `123456` |
| ⏱️ Auto-refresh interval | `var refreshTimer = 180` | 180 seconds |
| 🚦 Rate limit (full refresh) | `var CHECK_ALL_MIN_GAP = 10000` | 10 seconds |
| 🚦 Rate limit (per-row) | `var CHECK_ROW_MIN_GAP = 5000` | 5 seconds |
| 📦 Batch size | `var DNS_BATCH_SIZE = 5` | 5 domains/batch |
| ⏳ Batch pause | `var DNS_BATCH_DELAY = 300` | 300ms between batches |
| 🌐 DoH resolver | `var DOH = '...'` | Cloudflare (`1.1.1.1`) |
| 📄 Domain list file | `DOMAINS_LIST` in PHP | `domains.list` |

---

## 🧠 How it works under the hood

### DNS-over-HTTPS (DoH)

Instead of raw DNS sockets (blocked in browsers), the app queries [Cloudflare's DoH API](https://developers.cloudflare.com/1.1.1.1/encryption/dns-over-https/) over HTTPS — no CORS issues, no browser permissions, works everywhere. Each domain gets 5 parallel queries: `A`, `NS`, `MX`, `TXT`, and `_dmarc.TXT`. Results are parsed from Cloudflare's JSON response format (`application/dns-json`).

NS and MX answers are passed through pattern-matching provider detection: a lookup table maps known nameserver/mail hostnames to friendly labels (`Google`, `Cloudflare`, `SiteGround`, `ProtonMail`, `Amazon SES`…). For unknown providers, the second-level domain of the first NS/MX record is extracted and used as the label — more informative than the old `"Own"` fallback.

### Progressive batch scanning

Instead of firing 30+ parallel DNS queries at once (which would look like a DoH flood and could trip firewalls), checks run in **batches of 5** with a 300ms pause between batches. After each batch the table re-renders — you see rows come alive progressively, one batch at a time. Total time for 34 domains: ~3–4 seconds.

Batch size and delay are configurable constants (`DNS_BATCH_SIZE`, `DNS_BATCH_DELAY`).

### SSL certificate checking — three-tier strategy

The browser cannot open raw TLS sockets, so SSL expiry data comes from up to three sources, tried in priority order:

1. **`ssl-check.php?domains=dom1,dom2,...` (batch, same-origin PHP)** — `fetchAllSSLExpiry()` sends a single batch request after all DNS checks complete. PHP calls `stream_socket_client()` per domain to open a real TLS handshake, reads the certificate with `openssl_x509_parse()`, and returns a JSON array. One HTTP round-trip for up to 50 domains. Fast (~50ms/domain server-side, sequential).

2. **`crt.sh` per-domain (certificate transparency log lookup)** — fallback for static hosts where no PHP is available. Can time out or have gaps for low-traffic/private domains.

3. **`domains.json` (written by server-side cron)** — seeded at page load from `update-stats.php` output. Gives instant SSL data on first render before any live checks run.

If none of the above returns data, the SSL cell shows `—`.

### Uptime persistence — cookie-based history

Uptime data is stored in a browser cookie (`ase_uptime`, JSON-encoded, 1-year TTL). This was chosen over `localStorage` because localStorage is blocked in sandboxed iframes.

On every `checkDomain()` result, `uptimeRecord(domain, isUp)` increments the domain's `checks` and `ups` counters and records the last-down timestamp if the domain is unreachable. `uptimeSave()` serialises the entire map back to the cookie after each full scan.

On hover of the **STATUS** column, `uptimeTooltipHTML()` renders a tooltip showing:
- Uptime percentage (1 decimal place)
- Total checks run
- Days monitored since first check
- Last recorded downtime date

Cookie size is auto-trimmed to the 40 most-checked domains if it approaches 4KB.

### The SHA-256 caching bug (and fix)

The original SHA-256 implementation cached its prime tables on `sha256.h` and `sha256.k` as properties of the function object. This works on the first call but corrupts on subsequent calls — producing wrong hashes and breaking PIN verification. The fix: a fully **stateless implementation** that recomputes primes fresh on every call. No mutation, no side effects. This is why PIN verification is reliable across multiple attempts.

### Why `onclick` instead of `addEventListener`

The PIN numpad uses `onclick="pinDigit('1')"` directly in the HTML rather than `addEventListener`. The reason: when deployed in a sandboxed iframe (as in Perplexity Computer's preview), `DOMContentLoaded` fires before the script is fully evaluated — meaning listeners attached in that callback silently never execute. Inline `onclick` attributes bypass this entirely — one click, one call, always.

A related trap: binding the same event via *multiple* event types (e.g. both `click` and `touchstart`) causes double-firing on mobile. The PIN numpad uses only `click` (plus keyboard `keydown` handlers) to avoid this.

### Header dropdown — CSS stacking context escape

The sticky header (`position: sticky; z-index: 100`) creates its own CSS stacking context. Child elements, no matter how high their own `z-index`, cannot visually exceed the header's `z-index: 100` from the root document's perspective. This means a dropdown rendered inside the header would be covered by any root-level overlay above z-index 100.

The fix: the dropdown uses `position: fixed` (which is positioned relative to the viewport, not the header's containing block) with `z-index: 9999`. `toggleHeaderMenu()` reads the toggle button's position via `getBoundingClientRect()` and sets `top` / `right` dynamically — so the menu always appears correctly aligned regardless of scroll position. Outside-click detection uses a `document.addEventListener('click', ...)` handler rather than a backdrop `<div>` (which would itself be trapped in the same stacking context problem).

### Rate limiting

Two guards prevent accidental DNS flood:
- **Global:** `_checkRunning` flag blocks overlapping full scans; `CHECK_ALL_MIN_GAP` (5s) prevents re-runs fired too close together
- **Per-row:** `_domainLastCheck[domain]` timestamps every per-row refresh; `CHECK_ROW_MIN_GAP` (5s) prevents hammering a single domain
- **Auto-refresh countdown:** When the 3-minute countdown expires it auto-fires `checkAll()` — no second click needed. The button HTML is snapshotted as `REFRESH_BTN_ORIGINAL` at page load to guarantee correct restoration after each countdown cycle.

### SPF / DMARC interpretation

SPF and DMARC are parsed from `TXT` and `_dmarc.TXT` records respectively:

- **SPF:** The `all` mechanism qualifier is extracted (`~all`, `-all`, `+all`, `?all`). Any present and parseable SPF record renders as ✓ green — both `~all` (soft fail, industry standard) and `-all` (hard fail, stricter) are equally valid. The full raw SPF record is shown in the hover tooltip. Only a missing SPF renders red.
- **DMARC:** The `p=` tag is extracted (`reject`, `quarantine`, `none`). `reject` and `quarantine` render green; `none` renders yellow (policy defined but no enforcement). Missing DMARC renders red with `✕ missing`.

### The `domains.list` / fallback pattern

On startup, `loadDomainList()` tries `fetch('./domains.list')`. If the file is present and non-empty, it loads those domains and also seeds SSL expiry from `domains.json` (if available). If not (static host, local file, 404), it silently falls back to the built-in top-30 list. Custom domains added via the UI are pushed directly into the live DOMAINS array with a `fullScan=true` flag, triggering a full NS/MX/TXT/DMARC check immediately.

---

## 📝 Changelog

> Full changelog: **[CHANGELOG.md](./CHANGELOG.md)**

### 🔖 v2.0.2 — 2026-03-22
- 🌟 **feat:** Light theme is now the default on first load

### 🔖 v2.0.1 — 2026-03-22
- 🐛 **fix:** SPF badge — both `~all` and `-all` now render green; only missing SPF is red
- 🐛 **fix:** More menu — items now fully clickable; root cause was header's CSS stacking context blocking click events
- 🐛 **fix:** More menu — backdrop div replaced with `document.addEventListener` outside-click handler; menu uses `position: fixed` + `getBoundingClientRect()`
- 🎨 **fix:** Theme toggle moved to right of logo (before action buttons), per preference

### 🔖 v2.0.0 — 2026-03-22
- 🚀 **feat:** Batch SSL — single `ssl-check.php?domains=...` request covers all domains (no more per-domain races)
- 📊 **feat:** Uptime persistence via cookie — hover STATUS to see uptime %, total checks, days monitored, last-down date
- 🎛 **feat:** Header dropdown — secondary actions (GitHub, CSV, Webhook, PIN, Help) in "More ⋮" menu; primary stays clean
- 🗑️ **fix:** Category dropdown removed from Add Domain modal
- 🎨 **fix:** Theme toggle height aligned with buttons; version badge corrected to 2.0.0

### 🔖 v1.9.0 — 2026-03-22
- 🐛 **fix:** Refresh button no longer stuck on "1s…" — `REFRESH_BTN_ORIGINAL` snapshot guarantees correct restoration after countdown
- 🎨 **fix:** Header button consistency — cog shows "PIN", ? shows "Help", both with SVG icons matching other buttons
- 🎨 **fix:** Theme toggle border-radius aligned with button style

### 🔖 v1.8.0 — 2026-03-22
- 🔐 **feat:** `ssl-check.php` — same-origin PHP endpoint for fast, reliable SSL cert checks (replaces crt.sh as primary source)
- ⚙️ **feat:** PIN change modal — cog icon in header: enter current PIN → new PIN → confirm
- 🔑 **docs:** README explains first-login PIN prompt and ⚙️ change flow

### 🔖 v1.7.0 — 2026-03-22
- 🐛 **fix:** Refresh countdown now **auto-fires** `checkAll()` when it expires — no second click needed
- 🗑️ **feat:** Category column removed from the table
- 🌐 **fix:** NS/MX labels now show the registrar/provider name instead of generic "Own"
- ✨ **feat:** Row shimmer animation during scan (faint accent pulse + opacity dim)
- ⚡ Rate-limit reduced from 10s → 5s

### 🔖 v1.6.0 — 2026-03-22
- 🐛 **fix:** Removed IIFE that forced set-PIN modal on every incognito visit — login now works normally
- 🗑️ Removed `index.standalone.html` — three-file structure (`index.html` + `app.css` + `app.js`) only

### 🔖 v1.5.0 — 2026-03-22
- 🔐 **feat:** First visit skips default PIN — set-PIN modal shown directly if no custom PIN is set
- 🔐 **feat:** `showPinSuccessModal()` replaces browser `alert()` after PIN change
- 📊 **feat:** `loadDomainList()` reads `domains.json` to seed SSL expiry before first DNS check
- 🐛 **fix:** `update-stats.php` `$results[]` now includes `ssl_expiry` and `ssl_issuer`

### 🔖 v1.4.0 — 2026-03-22
- 📊 **feat:** `_sslChecked` session cache — prevents redundant crt.sh queries on every refresh
- 🎨 **feat:** Refresh button shows spinning icon + "Checking…" during scan
- ⏱️ crt.sh timeout reduced 8s → 5s

### 🔖 v1.3.0 — 2026-03-22
- 📦 **feat:** CSS + JS split into `app.css` / `app.js` — `index.html` reduced 130KB → 29KB (−78%)
- ✨ **feat:** 500ms minimum row loading animation; animated sweep progress bar during full scan
- 📝 **fix:** INSTALL.md — `.htaccess` rule for `webhook.do` documented for cron-job.org / Option B

### 🔖 v1.2.0 — 2026-03-22
- 🔐 **feat:** Live SSL expiry via crt.sh; `LE` badge for Let's Encrypt; PHP TLS handshake in `update-stats.php`
- 🌐 **fix:** 7 BUILTIN NS entries corrected to `Domain` (facebook, apple, cloudflare…)

### 🔖 v1.1.0 — 2026-03-22
- 🔐 **feat:** Smart NS detection — SiteGround, AWS, Azure, Cloudflare… "Domain" for self-hosted
- 🐛 **fix:** DNS parsing hardened — TXT/DMARC quote stripping, MX priority prefix stripping
- 🔐 **feat:** Set-PIN prompt appears after first login with default PIN

### 🔖 v1.0.0 — 2026-03-22
- 🎉 Initial release — live DNS checks, PIN gate, dark/light mode, `domains.list`, PHP cron, webhook, CSV export
## ⬇️ Download

**No git required.** Download the latest release as a ZIP:

👉 **[Download the ZIP](https://github.com/paulfxyz/the-all-seeing-eye/archive/refs/heads/main.zip)**

Unzip and upload `index.html` + `app.css` + `app.js` + `domains.list` to your server. See [INSTALL.md](./INSTALL.md) for the full guide.

---
## 🤝 Contributing

Pull requests are very welcome! Ideas: SSL expiry live check, ping history graphs, Slack/email alerts, multi-user support, mobile layout improvements.

1. 🍴 Fork the repo
2. 🌿 Create your branch: `git checkout -b feature/my-improvement`
3. 💾 Commit: `git commit -m 'Add amazing feature'`
4. 🚀 Push: `git push origin feature/my-improvement`
5. 📬 Open a Pull Request

---

## 📜 License

MIT License — free to use, modify, and distribute. See [`LICENSE`](./LICENSE) for details.

---

## 👤 Author

Made with ❤️ by **Paul Fleury** — designed and built in collaboration with **[Perplexity Computer](https://www.perplexity.ai/computer)**.

- 🌐 Website: **[paulfleury.com](https://paulfleury.com)**
- 🔗 LinkedIn: **[linkedin.com/in/paulfxyz](https://www.linkedin.com/in/paulfxyz/)**
- 🐦 All platforms: **[@paulfxyz](https://github.com/paulfxyz)**
- 📧 Email: **[hello@paulfleury.com](mailto:hello@paulfleury.com)**

---

⭐ **If this saved you time, drop a star — it helps others find it!** ⭐
