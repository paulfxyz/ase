# Installing ASE

ASE is plain HTML, CSS, JavaScript and PHP. There is no npm, no database and no build step. This page is the short version; the [README](./README.md) has the reasoning behind each choice.

## What you need

- An Apache host with PHP 7.4 or newer (SiteGround, cPanel, most shared hosting)
- FTP or file-manager access
- Optional: a [Resend](https://resend.com) API key for email alerts

Without PHP the dashboard still opens in a static mode: live DNS checks and the table work, but there is no sign-in, saved history, SSL handshake or alerting.

## 1. Upload

Upload the repository contents to a directory on your host. The marketing site files (`landing.html`, `i18n.js`, `avatar.jpg`) and the docs are not needed. Keep `.htaccess`, `_ase.php` and every `*.php` file.

```
index.html  app.css  app.js  domains.list  .htaccess
_ase.php  auth.php  config-write.php  uptime-write.php
ssl-check.php  notify.php  update-stats.php
favicon.ico  favicon-32.png  favicon-48.png  apple-touch-icon.png  icon-192.png  icon-512.png
```

The directory must be writable by PHP: ASE creates `ase_config.json`, `uptime.json` and a few small state files on its own. If your host does not use Apache, replicate the deny rules in `.htaccess` (see the README, Setup step 2).

## 2. Choose your PIN

Fastest and safest, from a shell on the server:

```bash
php auth.php set-pin 482915
```

Or open the site, sign in with the factory PIN `123456`, and ASE asks for your own straight away. Until you set it, that session can do nothing else, but do it immediately on a site that is publicly reachable.

- Forgot the PIN: delete `ase_config.json` over FTP (resets saved settings too), or run the command above.
- Locked out by the rate limiter: delete `auth_rate.json`.

## 3. Add your domains

Edit `domains.list` (one domain per line, `#` for comments), or use Add Domain in the dashboard. Domains you add in the dashboard are stored on the server and included in scheduled checks.

## 4. Schedule checks (recommended)

A browser tab only checks while it is open. For round-the-clock history and alert emails, schedule `update-stats.php`.

**Server cron (best, no token):**

```
php /home/USER/public_html/PATH/update-stats.php >/dev/null 2>&1
```

On SiteGround: Site Tools, Devops, Cron Jobs. Every 10 minutes is plenty. If you moved ASE to a new domain or folder, update the path in your existing cron entry.

**External scheduler over HTTP (cron-job.org, GitHub Actions, UptimeRobot):**

1. Create `cron_token.key` in the install directory containing a random 24+ character string (`openssl rand -hex 24`).
2. Call `https://yourdomain.com/update-stats.php` with the header `X-ASE-Token: <that string>`.
3. Without `cron_token.key` the HTTP route is closed.

Check the result by opening `uptime.json` over FTP or watching the uptime percentage column fill in. Run `php update-stats.php` by hand once to see a full log.

## 5. Email alerts (optional)

In the dashboard open More, Notifications, paste a Resend API key and a recipient. The key is encrypted at rest and never sent back to the browser. Alerts are sent by scheduled runs and by the open dashboard, with cooldowns to avoid floods.

## 6. Public showcase (optional)

Create an empty file named `demo.flag` next to `index.html` to turn the install into a read-only demo with no sign-in. This is how [ase.live](https://ase.live) runs. Demo mode is decided by that file, not the hostname.

## Hardening for public sites

A 6-digit PIN is a gate, not strong authentication. For anything sensitive, add HTTP Basic Auth or an IP allow-list in `.htaccess`, and exempt `update-stats.php` or use the CLI cron. Details are in the README's Security Model section.

## Updating

Upload the new files over the old ones. Never overwrite `ase_config.json`, `uptime.json`, `notify_secret.key`, `auth_secret.key`, `cron_token.key` or `domains.list` if you have customised it. Version 6.1.0 migrates an old SHA-256 PIN hash to bcrypt on the first successful sign-in. The old `webhook.do` file can be deleted.
