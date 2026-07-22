# Akva Backup (Dropbox) — encrypted full-store backups for PrestaShop 9

Daily **full snapshot** of a PrestaShop 9 store — complete database dump plus the whole
file tree — uploaded to a **Dropbox App folder**, with everything **encrypted client-side
before it leaves your server**. Built for cheap shared hosting: no shell access, no real
cron, no `exec()`, no sodium extension required.

Technical module name: `akvabackup`.

## What it does

- **Daily full backup**: resumable pure-PHP database dump (all tables, batched INSERTs)
  plus the complete PrestaShop file tree packed into ~450 MB ZIP volumes.
- **Client-side encryption**: every artifact (DB dump, file volumes, manifest) is encrypted
  with chunked AES-256-GCM (openssl + HKDF per-file subkeys) before upload. Dropbox only
  ever stores opaque blobs. There is **no key escrow** — you reveal the key once in the
  back office and store it offline; losing it makes the backups unrecoverable by design.
- **Runs on shared hosting**: the whole job is a state machine driven by short HTTP "cron
  ticks" (default 60 s work budget per call). Any external scheduler that can hit a URL
  every few minutes works — a Cloudflare Worker, a cron service, uptime monitor, etc.
  A killed tick loses at most one unit of work; the next tick resumes from a persisted
  cursor (this includes resuming a half-uploaded multi-hundred-MB volume).
- **Verified uploads**: every uploaded file is checked against Dropbox's `content_hash`;
  a mismatch re-uploads once, then fails the run loudly.
- **GFS retention**: keeps the last N daily backups, plus the first backup of each week
  for N weeks, plus the first backup of each month for N months (default 14 / 8 / 6);
  everything older is pruned from Dropbox automatically.
- **Component toggles**: choose what gets backed up — database, images (`img/`), modules,
  themes, product files (`download/`, `upload/`), email templates (`mails/`). Everything
  is on by default; file-level exclude globs are also configurable.
- **Multistore aware**: one PrestaShop installation = one backup covering **all shops**
  (shared database, shared files).
- **Failure alerts**: a failed run logs to PrestaShopLogger and emails the configured
  address (English and Slovenian mail templates ship; other languages fall back to English).
- **Standalone restore tool**: `tools/decrypt.php` decrypts any backup artifact on any
  PHP 8.1+ box with openssl — no PrestaShop, no Dropbox SDK, no module needed.

## Requirements

- PrestaShop 9.x
- PHP 8.1+ with `openssl`, `curl`, `zip` (standard on virtually all hosting)
- A free or paid Dropbox account
- An external scheduler that can call a URL every ~5 minutes during a nightly window

No Composer dependencies, no external SDK, no shell access needed.

## Installation

1. Download the release ZIP (or clone this repository into a folder named `akvabackup` —
   the folder name must be exactly `akvabackup`).
2. Install it via Back office > Modules, or copy the folder to `modules/akvabackup/` and
   install from the module manager.
3. The module adds a BO page under **Advanced Parameters > Akva Backup Dropbox**.

## Setup

1. **Create a Dropbox app** at [dropbox.com/developers](https://www.dropbox.com/developers):
   *Scoped access* + *App folder*. In the Permissions tab enable `files.content.write`,
   `files.content.read`, `files.metadata.read`, `account_info.read`.
2. In the module's BO page enter the **App key** and **App secret**, save, then click
   **Open Dropbox authorization**, approve, and paste the returned code. The module stores
   an offline refresh token (encrypted at rest) — the connection survives indefinitely.
3. **Reveal and save the encryption key** (shown exactly once, with a `.key` file
   download). Store it outside the server. Without it a disaster restore is impossible.
4. Copy the **Cron URL** from the Status panel into your scheduler: call it every
   5 minutes during your chosen nightly window (e.g. 02:00-07:00). Idle ticks are cheap
   JSON no-ops; the module itself decides when to start the daily run.
5. Enable the module switch. Done — the first run uploads a full snapshot, then nightly.

## Restore

See [RESTORE.md](RESTORE.md) for the full operator runbook. Short version: download the
run folder from Dropbox, decrypt each file with `php tools/decrypt.php <hexkey> <in> <out>`,
extract the volumes over a fresh PrestaShop root, import the database dump, clear caches.

Do a test restore periodically. A backup you have never restored is a hope, not a backup.

## Security model

- Everything is encrypted before upload (chunked AES-256-GCM, per-file HKDF subkeys,
  position-bound AAD — chunks cannot be truncated, reordered or spliced undetected).
- The master key is generated at install, stored encrypted at rest, revealed once, and
  never logged. Dropbox credentials (app secret, refresh token) are encrypted at rest and
  never logged or echoed.
- The cron endpoint requires HTTPS and a per-install random token compared in constant
  time. The staging directory is denied web access and purged after each run.

## Languages

English source strings; Slovenian translation ships in `translations/` (both `sl` and the
nonstandard `si` ISO variant some stores use). Contributions of further languages are
welcome — the strings live in standard PrestaShop module translation files.

## License

Academic Free License 3.0 (AFL-3.0), the standard PrestaShop module license.
See [LICENSE](LICENSE).
