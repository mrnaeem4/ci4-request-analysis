<?php

namespace MrNaeem\Ci4RequestAnalysis\Services;

use CodeIgniter\HTTP\RequestInterface;
use MrNaeem\Ci4RequestAnalysis\Config\RequestLog;

/**
 * Core service: collects request metadata, applies redaction/truncation and
 * writes one JSON line (JSONL) directly to the CI4 logs directory with daily
 * rotation + gzip compression + retention pruning.
 */
class RequestLogService
{
    protected $config;

    public function __construct(?RequestLog $config = null)
    {
        $this->config = $config ?? config(RequestLog::class);
    }

    public function getConfig(): RequestLog
    {
        return $this->config;
    }

    public function isIpWhitelisted(string $ip): bool
    {
        foreach ($this->config->whitelistIps as $range) {
            if ($this->ipInCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    public function collect(RequestInterface $request): array
    {
        $contentType = '';
        $ctHeader = $request->header('Content-Type');
        if ($ctHeader) {
            $contentType = $ctHeader->getValue();
        }

        $isJson = strpos($contentType, 'application/json') !== false;
        $isMultipart = strpos($contentType, 'multipart/form-data') !== false;
        $isFormUrl = strpos($contentType, 'application/x-www-form-urlencoded') !== false;

        $rawBody = $this->buildBody($request, $isJson, $isMultipart, $isFormUrl);
        $rawBody = $this->truncate($rawBody, $this->config->maxBodySize, $this->config->truncateSuffix);

        $headers = [];
        foreach ($request->headers() as $name => $header) {
            $headers[$name] = $header->getValueLine();
        }

        $fileMeta = $this->extractFileMetadata($request->getFiles() ?? []);
        $fileNames = [];
        foreach ($fileMeta as $meta) {
            $fileNames[] = $meta['original_name'];
        }

        return [
            'timestamp'     => date('c'),
            'domain'        => (string) ($request->getServer('HTTP_HOST') ?? ''),
            'path'          => $request->getUri()->getPath(),
            'method'        => $request->getMethod(),
            'srcip'         => $request->getIPAddress(),
            'user_agent'    => (string) $request->getUserAgent(),
            'query_string'  => (string) $request->getUri()->getQuery(),
            'headers'       => $headers,
            'raw_body'      => $rawBody,
            'file_count'    => count($fileMeta),
            'file_names'    => $fileNames,
            'file_metadata' => $fileMeta,
        ];
    }

    /**
     * Append one JSONL line to the log file, rotating first if needed.
     */
    public function write(array $logData): bool
    {
        $this->rotateIfNeeded();

        $line = json_encode([
            'log_data'     => $logData,
            'retry_count'  => 0,
            'last_attempt' => null,
            'created_at'   => date('c'),
        ]) . "\n";

        $path = $this->logPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return false;
            }
        }

        return @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Rotate the active log file when it belongs to a previous day, compress
     * it with gzip and prune logs older than the retention window.
     *
     * Called automatically on write and manually via requestlog:rotate.
     */
    public function rotate(): void
    {
        $path = $this->logPath();

        if (file_exists($path)) {
            $day = date('Y-m-d', filemtime($path));
            if ($day !== date('Y-m-d')) {
                $rotated = $this->buildRotatedName($path, $day);
                if (@rename($path, $rotated)) {
                    $this->compress($rotated);
                }
            }
        }

        $this->prune();
    }

    public function redact(array $data, array $fields): array
    {
        $lowerFields = array_map('strtolower', $fields);

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $lowerFields, true)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value, $fields);
            }
        }

        return $data;
    }

    public function truncate(string $body, int $maxLength, string $suffix): string
    {
        if (strlen($body) > $maxLength) {
            $body = substr($body, 0, $maxLength) . $suffix;
        }
        return $body;
    }

    public function extractFileMetadata(array $files): array
    {
        $flattened = [];
        array_walk_recursive($files, function ($file) use (&$flattened) {
            if ($file instanceof \CodeIgniter\Files\File && $file->isValid()) {
                $flattened[] = $file;
            }
        });

        $metadata = [];
        foreach ($flattened as $file) {
            $name = $file->getName();
            $metadata[] = [
                'original_name'        => $name,
                'size'                 => $file->getSize(),
                'mime_type'            => $file->getMimeType(),
                'extension'            => pathinfo($name, PATHINFO_EXTENSION),
                'hash'                 => hash_file('sha256', $file->getTempName()),
                'has_double_extension' => $this->hasDoubleExtension($name),
            ];
        }

        return $metadata;
    }

    protected function rotateIfNeeded(): void
    {
        $path = $this->logPath();

        if (! file_exists($path)) {
            return;
        }

        $day = date('Y-m-d', filemtime($path));
        if ($day !== date('Y-m-d')) {
            $rotated = $this->buildRotatedName($path, $day);
            if (@rename($path, $rotated)) {
                $this->compress($rotated);
            }
        }

        $this->prune();
    }

    protected function compress(string $path): bool
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            return false;
        }

        if (@file_put_contents($path . '.gz', gzencode($data, 6)) === false) {
            return false;
        }

        @unlink($path);
        return true;
    }

    protected function prune(): void
    {
        $dir = dirname($this->logPath());
        $cutoff = strtotime('-' . $this->config->retentionDays . ' days');

        if ($cutoff === false) {
            return;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . 'analysis-*.log.gz') as $file) {
            if (@filemtime($file) !== false && @filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    protected function buildRotatedName(string $path, string $day): string
    {
        $info = pathinfo($path);
        $ext = isset($info['extension']) ? $info['extension'] : 'log';

        return $info['dirname'] . DIRECTORY_SEPARATOR
            . $info['filename'] . '-' . $day . '.' . $ext;
    }

    protected function logPath(): string
    {
        $dir = $this->logDirectory();
        $file = ltrim($this->config->logFile, '/\\');

        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $file;
    }

    protected function logDirectory(): string
    {
        if ($this->config->logDir !== '') {
            return $this->config->logDir;
        }

        if (defined('WRITEPATH')) {
            return rtrim(WRITEPATH, '/\\') . '/logs';
        }

        if (defined('ROOTPATH')) {
            return rtrim(ROOTPATH, '/\\') . '/writable/logs';
        }

        return rtrim(sys_get_temp_dir(), '/\\') . '/logs';
    }

    protected function ipInCidr(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        $parts = explode('/', $range, 2);
        $subnet = $parts[0];
        $bits = (int) $parts[1];

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            return ($ipLong & $mask) === ($subnetLong & $mask);
        }

        return $ip === $range;
    }

    protected function hasDoubleExtension(string $filename): bool
    {
        if (substr_count($filename, '.') < 2) {
            return false;
        }

        $dangerous = [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7',
            'phps', 'phar', 'pht', 'shtml', 'cgi', 'pl', 'py',
            'jsp', 'asp', 'aspx', 'htaccess', 'inc',
        ];

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return in_array(strtolower($ext), $dangerous, true);
    }

    protected function buildBody(RequestInterface $request, bool $isJson, bool $isMultipart, bool $isFormUrl): string
    {
        if ($isMultipart) {
            $postFields = $request->getPost() ?? [];
            $redacted = $this->redact($postFields, $this->config->redactFields);
            return json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $rawBody = $request->getBody() ?? '';

        if ($rawBody === '') {
            return '';
        }

        if ($isJson) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $redacted = $this->redact($decoded, $this->config->redactFields);
                return json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return $rawBody;
        }

        if ($isFormUrl) {
            parse_str($rawBody, $parsed);
            if (is_array($parsed) && $parsed !== []) {
                $redacted = $this->redact($parsed, $this->config->redactFields);
                return http_build_query($redacted, '', '&');
            }
        }

        return $rawBody;
    }
}
