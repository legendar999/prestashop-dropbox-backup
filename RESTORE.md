# Akva Backup — RESTORE runbook

How to rebuild a PrestaShop 9 install from an Akva Backup snapshot. This is the operator
procedure; the module never restores automatically. Read it top to bottom before you
start — a restore is done rarely and under pressure, so the order matters.

## What a snapshot looks like on Dropbox

Each run is one folder in the app folder root, named `YYYY-MM-DD_<id_run>` (the date is the
run's start date). Inside:

- `db.sql.gz.enc` — the gzipped SQL dump, encrypted.
- `files_<N>.zip.enc` — one or more file-archive volumes, encrypted (the first volume is
  `files_0.zip.enc`), each a complete ZIP of ~450 MB uncompressed, split in deterministic
  depth-first sorted order.
- `meta.json.enc` — the manifest, encrypted like everything else (it names the DB,
  PrestaShop version and run stats — Dropbox only ever holds opaque blobs): run stats, the
  ordered volume list with each file's size + content hash, PrestaShop version, module
  version, DB name and the run date. **Decrypt and read this first** — it tells you how
  many volumes there are, their order, and the hashes to check your downloads against.

Every `.enc` file is chunked AES-256-GCM (openssl, 8 MiB chunks, per-file HKDF subkey),
file format `AKVABACKUP2` magic + 32-byte salt + chunks. You need the **encryption key**
(the hex string revealed once in the back office / saved to the `.key` file). Without it
the data is unrecoverable — there is no server-side copy of the key.

## 0. Prerequisites

- The encryption key (hex). If you only have the downloaded `.key` file, its contents ARE
  the hex string.
- A machine with PHP 8.1+ (openssl extension with aes-256-gcm — standard) to run
  `tools/decrypt.php`. It runs standalone, WITHOUT a PrestaShop bootstrap, so any PHP CLI
  works.
- Enough disk space for the decrypted archive (roughly the size of your store's file tree
  plus the DB dump).
- Dropbox access to the app folder (the connected Dropbox account, or a shared link to the
  run folder).

## 1. Download the run folder from Dropbox

Download the entire `YYYY-MM-DD_<id_run>/` folder — `meta.json.enc`, `db.sql.gz.enc`, and
every `files_<N>.zip.enc`. Use the Dropbox web UI, desktop client, or the Dropbox API.
Confirm you have ALL volumes listed in the decrypted `meta.json` (the count is
`stats.volumes_n`); a missing volume means missing files.

Note (component toggles): if the "Database" toggle was OFF for this run, the folder has NO
`db.sql.gz.enc` — skip the DB decrypt/import steps below. Likewise a file component
toggled OFF (images/modules/themes/product files/mails) is simply absent from the volumes;
`meta.json` lists exactly what was captured.

## 2. Decrypt every .enc file

`tools/decrypt.php` (shipped inside the module) decrypts one file at a time and throws on
tamper/wrong-key:

```
php decrypt.php <hexkey> meta.json.enc         meta.json
php decrypt.php <hexkey> db.sql.gz.enc         db.sql.gz
php decrypt.php <hexkey> files_0.zip.enc       files_0.zip
php decrypt.php <hexkey> files_1.zip.enc       files_1.zip
...   (repeat for every volume in meta.json order)
```

`<hexkey>` is the encryption key hex (or the contents of your `.key` file). If decrypt
fails with a MAC/tamper error, the download is corrupt or the key is wrong — re-download
and check the key, do not proceed with a partial file.

Integrity note: the hashes in `meta.json` are the Dropbox content-hash of the ENCRYPTED
blob as uploaded, so the practical integrity check is: decrypt succeeds (the per-chunk GCM
tag covers every byte) = the plaintext is intact.

## 3. Unzip the file volumes over a fresh PrestaShop root

Order matters only in that all volumes extract into the SAME target root; within a volume
paths are relative to the PS root. Extract them ALL into one clean directory (a fresh PS
root, or the existing root if you are restoring in place after moving the broken one
aside):

```
unzip -o files_0.zip -d /path/to/ps_root
unzip -o files_1.zip -d /path/to/ps_root
...
```

The archive contains the site files EXCEPT the install-time excludes (`var/cache/*`,
`var/akvabackup/*`, `img/tmp/*`, and the admin dir's `backups/autoupgrade/import/export`
subfolders) — those are caches/working dirs PS regenerates, not data. After extraction,
make sure `var/`, `img/`, and the admin dir are writable by the web server user.

## 4. Import the database

Decrypt gave you `db.sql.gz`. Import it into a database whose name matches (see
`meta.json.db_name`), or into a new DB you then point `app/config/parameters.php` at:

```
gunzip -c db.sql.gz | mysql -u <user> -p <dbname>
```

Or via Adminer / phpMyAdmin (upload the gunzipped `.sql`). The dump carries
`SET FOREIGN_KEY_CHECKS = 0;` and `SET NAMES 'utf8mb4';` and drops+recreates each table,
so it imports cleanly onto an empty or existing schema. Row-excluded tables (by default
`connections*`, `guest`, `statssearch`) are present as empty `CREATE TABLE` — that is
intentional (matches PS core's `PS_BACKUP_ALL=0`), their rows are stats noise, not shop
data.

If you restored the DB under a different name/host/prefix than the original, update
`app/config/parameters.php` (database_name/host/user/password, `db_prefix`) accordingly.

## 5. Flush caches

The excluded `var/cache/*` is not in the archive, so PS regenerates it — but clear
anything stale first:

```
rm -rf /path/to/ps_root/var/cache/*
```

Then rebuild via the BO (Advanced Parameters > Performance > Clear cache) or by hitting
the site once. If the modern container fails to compile on first hit, clearing `var/cache`
and retrying is the standard fix.

## 6. Verify

- Front office loads, a product page renders, add-to-cart works.
- BO loads, order count / product count match `meta.json` expectations.
- If your site sits behind a CDN (Cloudflare etc.), purge its cache so visitors stop
  getting stale or broken cached pages.

## Recommendation: quarterly restore test

Backups you have never restored are backups you do not have. **Once a quarter**, take a
recent run folder and do steps 1-5 onto a throwaway clone (a local install or a scratch
subdomain), import the DB, and confirm the site boots. This proves the encryption key is
still correct, the volumes are complete, and the dump imports — before you ever need it
for real.
