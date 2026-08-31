# ci4-request-analysis

CodeIgniter 4 library that intercepts incoming HTTP requests, extracts structured
metadata (headers, body, files, tenant, source IP, etc.), and asynchronously sends a
JSON log to a dedicated Analysis Server — with local file-based queuing, retry,
sensitive-field redaction, body truncation, and IP whitelisting.

> Client side only. The Analysis Server (Docker: PHP-FPM + Nginx on Alpine, log
> rotation, Wazuh consumption) is delivered in the `server/` directory of this
> repository.

## Features

- Attachable per-route (manual), not global.
- Non-blocking delivery: log is spooled locally, then sent by a background
  `analysis:send` command (triggered by the filter and/or cron).
- Local file-based queue with max-size enforcement (oldest dropped) and
  exponential retry; items are moved to `failed/` after `max_retries`.
- Multi-tenant identification via subdomain (`HTTP_HOST`).
- Configurable sensitive-field redaction (default: `password`, `nik`, `Api-Key`, `no_telp`).
- Raw body truncation at 3 MB (configurable) with `... [truncated]` suffix.
- File upload metadata captured without binary content (name, size, MIME,
  extension, SHA-256 hash, double-extension detection).
- IP/CIDR whitelist to skip private/internal traffic.
- API-key authentication on the Analysis Server.
- PHP 7.4 and PHP 8.4+.

## Requirements

- PHP 7.4+ / 8.4+ (with `exec` enabled)
- CodeIgniter 4 (>= 4.3)
- Guzzle 7
- A writable local queue directory

## Installation

```bash
composer require mrnaeem4/ci4-request-analysis
```

## Configuration

Set the following environment variables in your CI4 application `.env`
(or system environment):

```ini
ANALYSIS_ENABLED          = true
ANALYSIS_SERVER_URL       = "http://host.docker.internal:8081/analyze"
ANALYSIS_API_KEY          = "your-shared-api-key"
ANALYSIS_TIMEOUT          = 2
ANALYSIS_MAX_QUEUE        = 10000
ANALYSIS_QUEUE_DIR        = "/tmp/analysis_queue"
ANALYSIS_REDACT_FIELDS    = "password,nik,Api-Key,no_telp"
ANALYSIS_MAX_BODY_SIZE    = 3145728
ANALYSIS_WHITELIST_IPS    = "10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1"
ANALYSIS_TRUNCATE_SUFFIX  = "... [truncated]"
ANALYSIS_MAX_RETRIES      = 5
```

### Config reference

| Variable | Default | Description |
|---|---|---|
| `ANALYSIS_ENABLED` | `false` | Master switch for the filter. |
| `ANALYSIS_SERVER_URL` | `''` | Analysis Server endpoint (POST `/analyze`). |
| `ANALYSIS_API_KEY` | `''` | Shared static API key sent in the `X-API-Key` header. |
| `ANALYSIS_TIMEOUT` | `2` | HTTP timeout (seconds) when sending a log entry. |
| `ANALYSIS_MAX_QUEUE` | `10000` | Max queued items; oldest are dropped when exceeded. |
| `ANALYSIS_QUEUE_DIR` | `sys_get_temp_dir()/analysis_queue` | Directory for the local spool. |
| `ANALYSIS_REDACT_FIELDS` | `password,nik,Api-Key,no_telp` | Comma-separated sensitive fields (case-insensitive). |
| `ANALYSIS_MAX_BODY_SIZE` | `3145728` (3 MB) | `raw_body` truncation length (bytes). |
| `ANALYSIS_WHITELIST_IPS` | RFC1918 + localhost | CIDR ranges to skip. |
| `ANALYSIS_TRUNCATE_SUFFIX` | `... [truncated]` | Appended when the body is truncated. |
| `ANALYSIS_MAX_RETRIES` | `5` | Send attempts before moving to `failed/`. |

## Usage

### 1. Register the filter alias

In `app/Config/Filters.php`:

```php
public $aliases = [
    // ...
    'analysis' => \MrNaeem\Ci4RequestAnalysis\Filters\AnalysisFilter::class,
];
```

### 2. Attach to specific routes

```php
$routes->group('api', ['filter' => 'analysis'], function ($routes) {
    $routes->post('profile/update', 'Profile::update');
    $routes->post('search', 'Search::index');
    $routes->post('upload/avatar', 'Upload::avatar');
});
```

Only these routes will be analyzed — nothing is logged globally.

### 3. Ensure the queue directory is writable

The directory must be writable by the web server. It is created automatically,
but on a persistent/containerized deployment mount it to a volume:

```bash
mkdir -p /tmp/analysis_queue && chown -R www-data:www-data /tmp/analysis_queue
```

### 4. Run the sender

The filter spawns `analysis:send` in the background on each analyzed request.
For retries of failed items, schedule it via cron (every minute):

```cron
* * * * * cd /path/to/app && php spark analysis:send >/dev/null 2>&1
```

Or run it manually:

```bash
php spark analysis:send
```

## Log payload

Each entry sent to the Analysis Server is a JSON object:

```json
{
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
}
```

## How it works

```
Client (CI4)
  Filter (before hook)
    ├─ enabled? ──no──► done
    ├─ IP whitelisted? ──yes──► done
    ├─ collect: headers, body, files, tenant, srcip...
    │    ├─ redact sensitive fields
    │    ├─ truncate body at max size
    │    └─ extract file metadata (no binary)
    ├─ queue: write JSON to ANALYSIS_QUEUE_DIR
    └─ triggerSend: background `php spark analysis:send`

AnalysisSend (spark command)
  └─ for each queue file:
       ├─ send POST to ANALYSIS_SERVER_URL (X-API-Key header)
       ├─ success → delete file
       └─ failure → retry_count++, move to failed/ after max_retries
```

## Security notes

- Redaction happens client-side before the body is sent; binary file content is
  never transmitted.
- Do not log `Authorization`/`Cookie` values unless required — redact them by
  adding their field names to `ANALYSIS_REDACT_FIELDS`.
- Keep `ANALYSIS_API_KEY` out of version control (use `.env`).
- The `exec` PHP function must be enabled for background sending; otherwise use
  cron only.

## Multi-tenant

The tenant is derived from the subdomain via `HTTP_HOST` and stored in the
`domain` field of the payload. No extra configuration is required.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

[MIT](LICENSE)
