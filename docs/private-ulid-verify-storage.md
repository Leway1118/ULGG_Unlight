# Private ULID Verification Storage

Deployment notes for the ULID ownership-verification image store that lives
**outside** the public web root. This document contains no credentials.

## Paths and permissions

| Path | Owner:Group | Mode | Notes |
| --- | --- | --- | --- |
| `/var/www/html/unlight-private` | `root:nginx` | `0750` | Container; readable/traversable by nginx only |
| `/var/www/html/unlight-private/ulid-verify` | `nginx:nginx` | `0750` | Writable by nginx (php-fpm/mail uploads) |
| `/var/www/html/unlight-private/ulid-verify/<64-hex>.(png\|jpg)` | `nginx:nginx` | `0600` | One file per verification image |

The directory is intentionally **not** under `/var/www/html/unlight` so it is
never served directly by nginx. Access goes through the application only.

## Provisioning (idempotent)

```sh
install -d -o root  -g nginx -m 0750 /var/www/html/unlight-private
install -d -o nginx -g nginx -m 0750 /var/www/html/unlight-private/ulid-verify
runuser -u nginx -- test -w /var/www/html/unlight-private/ulid-verify && echo NGINX_WRITE=PASS
namei -l /var/www/html/unlight-private/ulid-verify
```

Do not change the mode of the container to group-writable; write access is
granted only to the inner directory and only to `nginx`.

## Application configuration

The application resolves the store from the `ULID_VERIFY_UPLOAD_DIR`
environment variable, defined in `/var/www/html/unlight/.env` (never committed).
When unset, the code falls back to `<webroot>/../unlight-private/ulid-verify`.

References:
- `pages/bind_ulid.php` (`appEnv('ULID_VERIFY_UPLOAD_DIR')`)
- `pages/admin/ajax/verify_image.php` (`appEnv('ULID_VERIFY_UPLOAD_DIR')`)

## Integrity verification

Content manifest over the store (matches the import acceptance check):

```sh
cd /var/www/html/unlight-private/ulid-verify
find . -maxdepth 1 -type f -exec sha256sum {} + | LC_ALL=C sort -k2 | sha256sum
```

## Import rules

- Never overwrite an existing verification image.
- Create new files exclusively and atomically (hard-link from a pre-created
  temp file is preferred, so ownership/mode are already correct).
- Compare same-name files first; stop on any same-name content conflict.
- Verify each file's SHA-256 after creation and re-check the manifest.
- Files created by a rejected import must be removed; existing data stays.

## Security boundary

- Never store credentials, tokens, `.env` files, DB dumps, or backups here.
- Never add this directory (or its contents) to the Git repository.
- This store is member data and must not be exposed publicly.
