<?php

namespace MrNaeem\Ci4RequestAnalysis\Services;

use CodeIgniter\HTTP\RequestInterface;
use MrNaeem\Ci4RequestAnalysis\Config\Analysis;

class AnalysisService
{
    protected $config;

    public function __construct(?Analysis $config = null)
    {
        $this->config = $config ?? config(Analysis::class);
    }

    public function getConfig(): Analysis
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

    public function queue(array $logData): bool
    {
        $dir = $this->config->queueDir;
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return false;
            }
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if (is_array($files) && count($files) >= $this->config->maxQueue) {
            usort($files, function ($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            $toDelete = count($files) - $this->config->maxQueue + 1;
            foreach (array_slice($files, 0, $toDelete) as $oldFile) {
                @unlink($oldFile);
            }
        }

        $queueItem = [
            'log_data'     => $logData,
            'retry_count'  => 0,
            'last_attempt' => null,
            'created_at'   => date('c'),
        ];

        $filename = $dir . DIRECTORY_SEPARATOR . uniqid('analysis_', true) . '.json';
        return @file_put_contents($filename, json_encode($queueItem), LOCK_EX) !== false;
    }

    public function triggerSend(): void
    {
        if (! defined('ROOTPATH')) {
            return;
        }

        $spark = ROOTPATH . 'spark';

        if (DIRECTORY_SEPARATOR === '\\') {
            @pclose(@popen('start /B php "' . $spark . '" analysis:send > NUL 2>&1', 'r'));
        } else {
            @exec('php ' . escapeshellarg($spark) . ' analysis:send > /dev/null 2>&1 &');
        }
    }

    public function sendFromQueue(string $file): bool
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (! is_array($data) || ! isset($data['log_data'])) {
            return false;
        }

        try {
            $client = new \GuzzleHttp\Client([
                'timeout'         => $this->config->timeout,
                'connect_timeout' => $this->config->timeout,
            ]);

            $response = $client->post($this->config->serverUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key'    => $this->config->apiKey,
                ],
                'body' => json_encode($data['log_data']),
            ]);

            return $response->getStatusCode() === 200;
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            return false;
        }
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