# AI Instruction: Building a PHP Library for CodeIgniter 4 to Send Request Logs to an Analysis Server
## 1. Project Overview

You are to build a PHP library compatible with CodeIgniter 4 that intercepts incoming HTTP requests (via a Filter/Middleware), extracts metadata (headers, body, files, etc.), and asynchronously sends a structured JSON log to a dedicated Analysis Server. The Analysis Server runs in a containerized environment (PHP-FPM + Nginx on Alpine) and writes the logs to a mounted host directory in JSON Lines format. These logs are then consumed by a Wazuh Agent installed on the host.

Key requirements:

- The library must be manually attached to specific routes (not global).
- Request sending must be asynchronous using Guzzle async.
- If the Analysis Server is down, the request is queued locally (file-based) and retried later.
- Support multi‑tenant identification via subdomain.
- Redact sensitive fields (configurable, default: ```password```, ```nik```, ```Api-Key```, ```no_telp```).
- Truncate raw body to 3MB (cut and append ```... [truncated]```).
- For file uploads, capture all metadata (name, size, MIME, extension, hash, double‑extension check), but do not send the binary content.
- Logs are flushed immediately to disk.
- Daily log rotation with compression (gzip) of old logs.
- Authentication via static API key (shared across clients).
- Support PHP 7.4 and PHP 8.4+.
- Follow Semantic Versioning.
- Provide a Docker Compose setup for the Analysis Server.
- Include a k6 load test example.
- Support IP whitelisting to skip analysis for private networks.
---
## 2. Architecture

```
[Client App (CI4)] 
    │
    ├─> Filter intercepts request
    │   ├─> Gather data: headers, body, files, subdomain, srcip, user_agent, etc.
    │   ├─> Redact sensitive fields (by field name)
    │   ├─> Truncate body if > 3MB
    │   ├─> Extract file metadata (skip file content)
    │   ├─> Check IP whitelist: if IP is private/whitelisted, skip sending.
    │   └─> Build JSON log.
    │
    ├─> Send async via Guzzle to Analysis Server
    │   ├─> If successful (HTTP 200): done.
    │   └─> If fails (timeout, connection error, non-200): store in local queue.
    │
    └─> Local Queue (file-based)
        ├─> Each queued item is stored as a JSON file in a queue directory.
        ├─> A separate background process (or cron) retries sending.
        ├─> Max queue size configurable; if exceeded, drop oldest.
        └─> Retry with exponential backoff (optional).

[Analysis Server (Docker)]
    ├─> Nginx (Alpine) + PHP-FPM (Alpine)
    ├─> Receives POST /analyze with JSON payload (API Key in header).
    ├─> Validates API Key.
    ├─> Appends timestamp (if not present) and writes to log file.
    ├─> Log file: /var/log/analysis/requests.log (JSON Lines).
    ├─> Log rotation: daily via logrotate (or custom script) – compress old logs.
    └─> Volume mount: /var/log/analysis on host for Wazuh.

[Host]
    ├─> Wazuh Agent reads /var/log/analysis/requests.log (and rotated files).
    └─> Sends to Wazuh Manager.
```
---
## 3. Client Library (CodeIgniter 4)
### 3.1. Installation & Configuration

The library will be a Composer package (e.g., ```mrnaeem/ci4-request-analysis```) that can be installed via Composer. It will provide:

- A Config class (```AnalysisConfig```) to hold settings.
- A Filter class (```AnalysisFilter```) that implements ```CodeIgniter\Filters\FilterInterface```.
- A Service (```AnalysisService```) to handle data collection, redaction, sending, and queuing.

Environment variables (loaded via ```.env``` or system env) should be:

```ini
# Client settings
ANALYSIS_ENABLED = true
ANALYSIS_SERVER_URL = "http://host.docker.internal:8081/analyze"
ANALYSIS_API_KEY = "your-shared-api-key"
ANALYSIS_TIMEOUT = 2                     # seconds for HTTP request
ANALYSIS_MAX_QUEUE = 10000               # max items in local queue
ANALYSIS_QUEUE_DIR = "/tmp/analysis_queue"
ANALYSIS_REDACT_FIELDS = "password,nik,Api-Key,no_telp"
ANALYSIS_MAX_BODY_SIZE = 3145728         # 3 MB
ANALYSIS_WHITELIST_IPS = "10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1"
ANALYSIS_TRUNCATE_SUFFIX = "... [truncated]"
```

### 3.2. Filter Implementation

The filter will be manually attached to routes via the $filters array in app/Config/Filters.php or via route-specific filter option. Example:

```php
// In app/Config/Routes.php
$routes->group('api', ['filter' => 'analysis'], function($routes) {
    $routes->post('profile/update', 'Profile::update');
    $routes->post('search', 'Search::index');
    $routes->post('upload/avatar', 'Upload::avatar');
});
```

The filter must:

1. Check if analysis is enabled (via env).
2. Check IP whitelist: if the client IP matches a private/whitelisted range, skip all processing.
3. Gather request data:
    - ```timestamp``` (ISO 8601 with UTC, e.g., 2026-08-31T02:15:04+00:00)
    - ```domain```: extracted from subdomain (e.g., app.example.com – but we need tenant subdomain; use ```$_SERVER['HTTP_HOST']```).
    - ```path```: $request->getUri()->getPath()
    - ```method```: ```$request->getMethod()```
    - ```srcip```: client IP (use ```$request->getIPAddress()```)
    - ```user_agent```: ```$request->getUserAgent()```
    - ```query_string```: ```$request->getUri()->getQuery()```
    - ```headers```: all request headers (filter out ```Authorization```, ```Cookie```? but not required as per spec, but we can include all).
    - ```raw_body```: the request body as a string (for JSON, form data, etc.). For ```multipart/form-data```, we need to reconstruct the body without file contents? Actually spec says ```raw_body``` should be included, but they want to exclude binary file content. For multipart, we might either omit the body or only include textual fields. The requirement says: "raw_body" should be the body (maybe truncated). But for file uploads, they give an example where ```raw_body``` is ```{"avatar":"uploaded"}``` – perhaps they want a placeholder. We need to decide: for multipart, we can parse the request and capture all non-file fields as a JSON structure, and set ```raw_body``` to that JSON (or a representation). Also for URL-encoded and JSON, we capture the raw body string (after truncation). For multipart, we can either capture the raw body (which includes binary data) and then truncate at 3MB, but they want to avoid sending binary file content. So better: for multipart, we parse the POST fields (excluding files) and generate a JSON object of those fields, then set that as ```raw_body``` (after truncation). We'll do that.
    - ```file_count```: number of uploaded files.
    - ```file_names```: array of filenames (with metadata: ```original_name```, ```size```, ```mime_type```, ```extension```, ```hash``` (SHA-256), ```has_double_extension``` boolean).
4. Redact sensitive fields from the raw body (or from parsed data) based on ```ANALYSIS_REDACT_FIELDS```. The redaction should occur on the raw_body string (if it's JSON, we can parse, redact values by field name, then re-encode; for URL-encoded, we can parse and rebuild; for multipart, we redact the parsed fields). For simplicity, we can parse the body according to its Content-Type, redact, then re-encode. For raw body that is not parseable, we can just replace values matching field names? Better: if Content-Type is JSON, parse to array, recursively redact keys matching field list (case-insensitive?), then re-encode. For form-urlencoded, parse, redact, rebuild. For multipart, parse text fields, redact, rebuild as JSON.
5. Truncate the resulting ```raw_body``` (string) to ```ANALYSIS_MAX_BODY_SIZE``` characters, appending ```ANALYSIS_TRUNCATE_SUFFIX``` if cut.
6. Build the log array with all fields.
7. Send asynchronously via Guzzle (promise) to ```ANALYSIS_SERVER_URL``` with the API Key in a header (e.g., ```X-API-Key: ...```). The payload is JSON.
8. If the async request fails (timeout, connection error, or HTTP status != 200), store the log array in the local queue (file-based).

Note: The filter must not block the main request; the async sending should happen in the background (Guzzle promises). However, CI4's filter execution is synchronous; we can start the async request and then return the response, but the promise might not have completed. We need to ensure the filter does not wait for the response. We can use Guzzle's ```Client::sendAsync()``` and not wait for the promise. But we must handle errors: if the request fails, we need to queue the log. Since the promise runs asynchronously, we can attach a ```then``` callback to handle failures and queue the log. The main request will complete without waiting.

Potential issue: In a typical PHP process, the async request may not finish before the script ends. We can use ```GuzzleHttp\Promise\promise_for``` and add a shutdown function to wait for the promise? But that would block. Alternatively, we can use a background process (e.g., exec a separate PHP script) to send. However, the requirement says "Use Guzzle with async requests", so we'll use Guzzle's async and let the script end. The promise will run in the background as long as the process is alive; but if we don't wait, the process may exit and the promise is abandoned. In PHP, you need to either wait or use a non-blocking approach (like a queue worker). The simplest solution: we can use a spooling approach – write the log to a local queue immediately, and have a separate background cron/worker process that sends from the queue. That is more reliable. However, the requirement says "The request must be queued locally and retried later" – but they also said "Send async via Guzzle". We can combine: attempt async send, if fail, queue. But if we don't wait for the async send to finish, we might not know if it failed. We could use a synchronous send but with a short timeout, and if it fails, queue it. That would block the request for at most the timeout (e.g., 2 seconds). That might be acceptable. But they specifically said "asynchronous". I think we can implement a non-blocking approach by using a background process: the filter writes the log to a queue file and then uses ```exec``` to run a separate PHP script that reads the queue and sends asynchronously. That script would run in the background and not block the main request. This is common in CI4 (e.g., using ```spark``` commands). We'll adopt that: the filter writes the log to the queue (file) and triggers a background process to send it. The background process can use Guzzle async (or just sync) to send. The queue will be retried by the background process. This is more robust.

I'll design:
- The filter writes the log data to a queue file (JSON) in ```ANALYSIS_QUEUE_DIR```. Each file is named with a timestamp and unique ID.
- Then it immediately invokes a background command (e.g., ```php spark analysis:send``` with ```nohup``` or exec in background) to process the queue. This command runs and attempts to send all pending items.
- Alternatively, we can have a cron job that runs every few seconds to process the queue, but that might introduce delays. The background process approach allows near-real-time sending.

We'll create a CI4 command (```php spark analysis:send```) that reads all queue files, attempts to send to the Analysis Server, and if successful, deletes the file; if failed, it keeps the file for later retry (maybe with a retry count and exponential backoff). The command will be triggered from the filter, but also can be run periodically via cron for retries.

Thus, the filter only does the data collection and writes to the queue, then triggers the background send. The send is asynchronous from the client request perspective.

We'll need to ensure the queue directory has proper permissions.

### 3.3. Queue File Structure

Each queue item is a JSON file with the log data plus metadata:

```json
{
    "log_data": { ... },   // the log array
    "retry_count": 0,
    "last_attempt": null,
    "created_at": "2026-08-31T02:15:04+00:00"
}
```

The background command will loop through all files, attempt to send, and on success delete; on failure, increment retry_count and update last_attempt. If retry_count exceeds a limit (e.g., 5), move to a ```failed``` subdirectory.

## 3.4. Data Processing Details
#### 3.4.1. Redaction

1. Get the list of fields to redact from ```ANALYSIS_REDACT_FIELDS``` (comma-separated).
2. For JSON body: decode to array, recursively traverse and replace any key that matches (case-insensitive?) with ```"***REDACTED***"```.
3. For ```application/x-www-form-urlencoded```: parse, redact by key, rebuild.
4. For ```multipart/form-data```: we will collect all non-file fields, redact them, and rebuild as a JSON object (to be used as ```raw_body```). We will not include binary files in ```raw_body```.

#### 3.4.2. File Metadata

For each uploaded file (using ```$request->getFiles()```):

- ```original_name```: ```$file->getName()```

- ```size```: ```$file->getSize()```

- ```mime_type```: ```$file->getMimeType()```

- ```extension```: ```pathinfo(original_name, PATHINFO_EXTENSION)```

- ```hash```: ```hash_file('sha256', $file->getTempName())```

- ```has_double_extension```: check if filename has more than one dot and the last extension is a known executable? We'll simply check if there is more than one dot and the final extension is in a blacklist (e.g., php, phar, etc.) or just a boolean if more than one dot.

We'll collect all these in an array per file and include ```file_names``` as an array of such metadata objects (the spec shows ```file_names``` as an array of names, but we can extend to include metadata; the example shows ```file_names:["shell.php.jpg"]``` which is just names. They want metadata as well; we can add a separate field ```file_metadata``` or enhance ```file_names``` to be an array of objects. The spec says "file_names" should be list of names, but they also said "all of them must be collected". So we can have ```file_names``` as array of strings (original names) and ```file_metadata``` as array of objects. But the example only shows ```file_names```. To keep backward compatible, we can include both: ```file_names``` (list of names) and file_metadata (array of objects). We'll define it.

#### 3.4.3. Truncation

After constructing ```raw_body``` as a string, if its length > ```ANALYSIS_MAX_BODY_SIZE```, truncate to that length and append ```ANALYSIS_TRUNCATE_SUFFIX```.

#### 3.4.4. IP Whitelist

- Parse ```ANALYSIS_WHITELIST_IPS``` as comma-separated list of CIDR ranges.
- For the client IP, check if it falls into any of these ranges; if yes, skip sending (and don't even queue). This is to avoid logging internal traffic.

### 3.5. Filter Class Structure
```php
<?php

namespace YourVendor\Analysis\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use YourVendor\Analysis\Services\AnalysisService;

class AnalysisFilter implements FilterInterface
{
    protected $service;

    public function __construct(AnalysisService $service)
    {
        $this->service = $service;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        // Check if enabled
        if (! env('ANALYSIS_ENABLED', false)) {
            return;
        }

        // IP whitelist check
        $clientIP = $request->getIPAddress();
        if ($this->service->isIpWhitelisted($clientIP)) {
            return;
        }

        // Collect data and queue
        $logData = $this->service->collect($request);
        // Queue it
        $this->service->queue($logData);
        // Trigger background send
        $this->service->triggerSend();
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to do
    }
}
```

### 3.6. Service Class

The ```AnalysisService``` will contain methods:

- ```collect(RequestInterface $request)```: returns the log array.
- ```isIpWhitelisted($ip)```: checks against CIDR list.
- ```queue($logData)```: writes to queue file.
- ```triggerSend()```: executes background command (e.g., ```php spark analysis:send &```).
- ```sendFromQueue($file)```: used by the command to attempt sending.

### 3.7. Background Sender Command

Create a CI4 command ```AnalysisSend```. It will:

- Scan ```ANALYSIS_QUEUE_DIR``` for files.
- For each file, read JSON.
- Attempt to send to Analysis Server via Guzzle (synchronous with timeout).
- On success: delete file.
- On failure: increment retry count, update last_attempt. If retry_count > max_retries (e.g., 5), move to ```failed/``` subdirectory.
- Handle errors gracefully.

The command can be invoked from cron every minute to retry failed items. The filter triggers it immediately for new items.

---

## 4. Analysis Server (Containerized)
### 4.1. Dockerfile

We'll build a lightweight container with PHP 8.4 (Alpine) + Nginx (Alpine). Use ```php-fpm``` and Nginx in same container or separate? Best practice: separate containers, but to keep it simple we can have a single container with both (using supervisord). However, they said "php-fpm + nginx alpine based" – we can combine them in one image with supervisor.

We'll create a Dockerfile that:

- Uses ```alpine:3.19``` as base.
- Installs Nginx, PHP-FPM, and necessary extensions (curl, json, mbstring, etc.).
- Copies the server application (PHP script that handles ```/analyze```).
- Configures Nginx to proxy requests to PHP-FPM.
- Exposes port 8081.

We'll also install ```logrotate``` or use a custom script for daily rotation.

Simpler: Use official PHP-FPM Alpine image and Nginx Alpine image in separate services in docker-compose, sharing the code volume. That's more standard. We'll adopt that: two services: ```app``` (php-fpm) and ```nginx```, with a shared volume for code and logs.

### 4.2. Server Application

The server will be a simple PHP script (or use a micro-framework like Slim, but we can just use plain PHP) that handles POST requests to ```/analyze```.

It will:

1. Check the ```X-API-Key``` header against the environment variable ```ANALYSIS_API_KEY```.
2. Read the JSON payload from ```php://input```.
3. Validate JSON structure (must contain required fields: ```log_type```, ```timestamp```, etc. but we can accept any; we'll just append a server timestamp if not present).
4. Write the JSON line (with newline) to the log file ```/var/log/analysis/requests.log```.
5. Use ```flock``` or append safely (since multiple requests may write simultaneously, we can use ```file_put_contents``` with ```LOCK_EX```).
6. Return HTTP 200.

The server will also handle daily log rotation. We can use a cron job inside the container that runs ```logrotate``` daily, but simpler: we can rename the log file daily using a cron job that moves the file and reloads? Or we can use a script that runs on each request to check if a new day has started and rename the file. We'll use a cron job with ```logrotate``` installed in the container, configured to rotate daily, compress old logs, and keep 30 days.

We'll add a cron job (via a script) that runs logrotate daily.

### 4.3. Environment Variables for Server

- ```ANALYSIS_API_KEY```: shared key (required)
- ```LOG_FILE_PATH```: default ```/var/log/analysis/requests.log```
- ```LOG_RETENTION_DAYS```: default 30

### 4.4. Nginx Configuration

Nginx will listen on port 8081, proxy all requests to PHP-FPM (fastcgi). Only allow POST to ```/analyze``` (optional).
4.5. Docker Compose

We'll provide a docker-compose.yml:

```yml
version: '3.8'
services:
  analysis-php:
    build:
      context: .
      dockerfile: Dockerfile.php
    container_name: analysis-php
    volumes:
      - ./app:/var/www/html
      - ./logs:/var/log/analysis
    environment:
      - ANALYSIS_API_KEY=your-secret-key
      - LOG_FILE_PATH=/var/log/analysis/requests.log
    networks:
      - analysis-net
    restart: unless-stopped

  analysis-nginx:
    image: nginx:alpine
    container_name: analysis-nginx
    ports:
      - "8081:80"
    volumes:
      - ./app:/var/www/html
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf
      - ./logs:/var/log/analysis
    depends_on:
      - analysis-php
    networks:
      - analysis-net
    restart: unless-stopped

networks:
  analysis-net:
    driver: bridge
```
### 4.6. PHP Dockerfile

We'll use ```php:8.4-fpm-alpine``` as base, install required extensions.

Add cron for log rotation. We'll create a script that runs ```logrotate``` daily.

---
## 5. Integration with CI4 Application
### 5.1. Installation

- ```composer require mrnaeem/ci4-request-analysis```
- Publish configuration (optional) – but we use env vars.

### 5.2. Register Filter

In ```app/Config/Filters.php```:

```php
public $aliases = [
    'analysis' => \YourVendor\Analysis\Filters\AnalysisFilter::class,
];
```

### 5.3. Apply to Routes

In ```app/Config/Routes.php```:
```php
$routes->group('api', ['filter' => 'analysis'], function($routes) {
    $routes->post('profile/update', 'Profile::update');
    $routes->post('search', 'Search::index');
    $routes->post('upload/avatar', 'Upload::avatar');
});
```

### 5.4. Set Environment Variables

Add to ```.env```:
```ini
ANALYSIS_ENABLED = true
ANALYSIS_SERVER_URL = "http://host.docker.internal:8081/analyze"
ANALYSIS_API_KEY = "your-shared-key"
ANALYSIS_TIMEOUT = 2
ANALYSIS_MAX_QUEUE = 10000
ANALYSIS_QUEUE_DIR = "/tmp/analysis_queue"
ANALYSIS_REDACT_FIELDS = "password,nik,Api-Key,no_telp"
ANALYSIS_MAX_BODY_SIZE = 3145728
ANALYSIS_WHITELIST_IPS = "10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1"
ANALYSIS_TRUNCATE_SUFFIX = "... [truncated]"
```

### 5.5. Ensure Queue Directory is Writable

Create ```/tmp/analysis_queue``` and set permissions.

### 5.6. Create the Background Sender Command

Place a command file in ```app/Commands/AnalysisSend.php```.

The command will:
1. Read all files in ```ANALYSIS_QUEUE_DIR``` (excluding subdirectories).
2. For each, attempt to send.
3. Use Guzzle client with timeout.
4. On success: unlink.
5. On failure: increment retry, update file.

We'll also add a ```max_retries``` (default 5) and a ```failed``` subdirectory.

The filter will trigger this command using exec("```php spark analysis:send > /dev/null 2>&1 &```") to run in background.

Also set up a cron job on the host to run ```php spark analysis:send``` every minute to retry failed items.

---

## 6. Testing with k6

Create a sample ```loadtest.js``` script that sends POST requests to the CI4 application endpoints, simulating traffic. We'll include assertions to ensure the application responds quickly (the filter should not block). The load test can also target the Analysis Server directly.

Example k6 script:
```js
import http from 'k6/http';
import { check } from 'k6';

export const options = {
  vus: 10,
  duration: '30s',
};

export default function () {
  const url = 'http://ci4-app/api/profile/update';
  const payload = JSON.stringify({
    name: 'User',
    email: 'user@example.com',
    password: 'secret123'
  });
  const params = {
    headers: { 'Content-Type': 'application/json' },
  };
  const res = http.post(url, payload, params);
  check(res, { 'status was 200': (r) => r.status === 200 });
}
```
Run with k6 run ```loadtest.js```.

---

## 7. Versioning and Support

- Follow Semantic Versioning (MAJOR.MINOR.PATCH).
- Support PHP 7.4 and PHP 8.4+.
- Use ```php-compatibility``` to ensure compatibility.

---

## 8. Deliverables

The AI agent should produce:

1. Full source code for the CI4 library (Filter, Service, Command, Config).
2. Dockerfile for PHP-FPM and Nginx configuration.
3. docker-compose.yml.
4. Server PHP script (index.php) handling ```/analyze```.
5. Log rotation script (cron job) inside container.
6. Sample CI4 application showing integration (optional but recommended).
7. Documentation (README.md) with setup instructions, env variables, usage.
8. k6 load test script.
9. Step-by-step deployment guide.

All code must be production-ready, secure, and well-commented.

---

## 9. Final Notes

1. Ensure the library does not interfere with the main application's performance.
2. The queue directory must be on a persistent volume if using Docker.
3. For multi-tenant, subdomain is used for ```domain``` field – extract from ```$_SERVER['HTTP_HOST']```.
4. The asynchronous sending via background process is acceptable.

This instruction provides a complete blueprint. The AI agent should now implement all components as described.