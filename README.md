# ci4-request-analysis

CodeIgniter 4 library that intercepts incoming HTTP requests, extracts structured
metadata (headers, body, files, tenant, source IP, etc.), and writes one JSON
Line per request directly to the CI4 `writable/logs` directory — with
sensitive-field redaction, body truncation, IP whitelisting, and daily log
rotation with gzip compression.

> Local logging only. No external server, no queue, no Guzzle.

## Features

- Attachable per-route (manual), not global.
- Writes directly to `writable/logs/analysis.log` as JSON Lines (JSONL).
- Daily rotation: a file from a previous day is renamed and gzip-compressed
  automatically on the next write.
- Retention pruning: compressed logs older than `REQUEST_LOG_RETENTION_DAYS`
  (default 30) are deleted.
- Configurable sensitive-field redaction (default: `password`, `nik`, `Api-Key`, `no_telp`).
- Raw body truncation at 3 MB (configurable) with `... [truncated]` suffix.
- File upload metadata captured without binary content (name, size, MIME,
  extension, SHA-256 hash, double-extension detection).
- IP/CIDR whitelist to skip private/internal traffic.
- PHP 7.4 and PHP 8.4+.

## Requirements

- PHP 7.4+ / 8.4+
- CodeIgniter 4 (>= 4.3)
- A writable `writable/logs` directory

## Installation

```bash
composer require mrnaeem4/ci4-request-analysis
```

## Configuration

Set the following environment variables in your CI4 application `.env`
(or system environment):

```ini
REQUEST_LOG_ENABLED          = true
REQUEST_LOG_DIR              = "writable/logs"
REQUEST_LOG_FILE             = "analysis.log"
REQUEST_LOG_REDACT_FIELDS    = "password,nik,Api-Key,no_telp"
REQUEST_LOG_MAX_BODY_SIZE    = 3145728
REQUEST_LOG_WHITELIST_IPS    = "10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1"
REQUEST_LOG_TRUNCATE_SUFFIX  = "... [truncated]"
REQUEST_LOG_RETENTION_DAYS   = 30
```

### Config reference

| Variable | Default | Description |
|---|---|---|
| `REQUEST_LOG_ENABLED` | `false` | Master switch for the filter. |
| `REQUEST_LOG_DIR` | `''` (→ `writable/logs`) | Directory for the log file. |
| `REQUEST_LOG_FILE` | `analysis.log` | Log file name (single file, rotated daily). |
| `REQUEST_LOG_REDACT_FIELDS` | `password,nik,Api-Key,no_telp` | Comma-separated sensitive fields (case-insensitive). |
| `REQUEST_LOG_MAX_BODY_SIZE` | `3145728` (3 MB) | `raw_body` truncation length (bytes). |
| `REQUEST_LOG_WHITELIST_IPS` | RFC1918 + localhost | CIDR ranges to skip. |
| `REQUEST_LOG_TRUNCATE_SUFFIX` | `... [truncated]` | Appended when the body is truncated. |
| `REQUEST_LOG_RETENTION_DAYS` | `30` | Days of compressed logs kept before pruning. |

## Usage

### 1. Register the filter alias

In `app/Config/Filters.php`:

```php
public $aliases = [
    // ...
    'requestlog' => \MrNaeem\Ci4RequestAnalysis\Filters\RequestLogFilter::class,
];
```

### 2. Attach to specific routes

```php
$routes->group('api', ['filter' => 'requestlog'], function ($routes) {
    $routes->post('profile/update', 'Profile::update');
    $routes->post('search', 'Search::index');
    $routes->post('upload/avatar', 'Upload::avatar');
});
```

Only these routes are logged — nothing is logged globally.

### 3. Verify writable logs directory

The web server must be able to write to `writable/logs`. The directory is
created automatically if missing:

```bash
mkdir -p writable/logs && chown -R www-data:www-data writable/logs
```

### 4. (Optional) Run rotation via cron

Rotation happens automatically on write. For a guaranteed nightly pass and
retention cleanup, schedule `requestlog:rotate` daily:

```cron
0 0 * * * cd /path/to/app && php spark requestlog:rotate >/dev/null 2>&1
```

Or run it manually:

```bash
php spark requestlog:rotate
```

## Log payload

Each line in `analysis.log` is a JSON object (envelope + `log_data`):

```json
{
  "log_data": {
    "timestamp": "2026-08-31T02:15:04+00:00",
    "domain": "app.example.com",
    "path": "/api/profile/update",
    "method": "POST",
    "srcip": "203.0.113.10",
    "user_agent": "Mozilla/5.0 ...",
    "query_string": "page=1",
    "headers": { "Content-Type": "application/json", ... },
    "raw_body": "{\"name\":\"User\",\"email\":\"user@example.com\",\"password\":\"***REDACTED***\"}",
    "file_count": 1,
    "file_names": ["shell.php.jpg"],
    "file_metadata": [
      {
        "original_name": "shell.php.jpg",
        "size": 20480,
        "mime_type": "image/jpeg",
        "extension": "jpg",
        "hash": "3c98...",
        "has_double_extension": true
      }
    ]
  },
  "retry_count": 0,
  "last_attempt": null,
  "created_at": "2026-08-31T02:15:04+00:00"
}
```

## Log rotation

- Active file: `writable/logs/analysis.log`
- Rotated file: `writable/logs/analysis-YYYY-MM-DD.log.gz`

When a write detects the active file belongs to a previous day, it is renamed
to `analysis-YYYY-MM-DD.log`, compressed with gzip, and the original removed.
Compressed files older than `REQUEST_LOG_RETENTION_DAYS` are pruned.

## How it works

```
Request → RequestLogFilter (before hook)
  ├─ enabled? ──no──► done
  ├─ IP whitelisted? ──yes──► done
  ├─ collect: headers, body, files, tenant, srcip...
  │    ├─ redact sensitive fields
  │    ├─ truncate body at max size
  │    └─ extract file metadata (no binary)
  ├─ rotate if the active log is from a previous day (rename + gzip + prune)
  └─ append one JSONL line to writable/logs/analysis.log
```

The write is a single append with `LOCK_EX`, so it does not block the request
and is safe for concurrent PHP-FPM workers.

## Security notes

- Redaction happens before the body is written; binary file content is never
  stored.
- Do not log `Authorization`/`Cookie` values unless required — redact them by
  adding their field names to `REQUEST_LOG_REDACT_FIELDS`.
- `writable/logs` should not be publicly accessible (CI4 already blocks it by
  default); logs are git-ignored via `/writable/`.

## Wazuh integration

The library ships with a ready-to-use Wazuh setup: a JSON decoder for the
`analysis.log` envelope, detection rules, and agent config that points to
`writable/logs/analysis.log`. See [docs/wazuh.md](docs/wazuh.md).

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

[MIT](LICENSE)
