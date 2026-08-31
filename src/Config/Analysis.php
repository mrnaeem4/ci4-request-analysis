<?php

namespace MrNaeem\Ci4RequestAnalysis\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuration for the request analysis library.
 *
 * Values are resolved from environment variables (ANALYSIS_*) and fall back
 * to the defaults declared here.
 */
class Analysis extends BaseConfig
{
    /**
     * Master switch. When false the filter does nothing.
     *
     * @var bool
     */
    public $enabled = false;

    /**
     * URL of the Analysis Server endpoint (POST /analyze).
     *
     * @var string
     */
    public $serverUrl = '';

    /**
     * Shared static API key sent in the X-API-Key header.
     *
     * @var string
     */
    public $apiKey = '';

    /**
     * HTTP timeout in seconds for sending a log entry.
     *
     * @var int
     */
    public $timeout = 2;

    /**
     * Maximum number of queued items. Oldest items are dropped when exceeded.
     *
     * @var int
     */
    public $maxQueue = 10000;

    /**
     * Directory where queued log entries are stored as JSON files.
     *
     * @var string
     */
    public $queueDir = '';

    /**
     * Sensitive field names that must be redacted (case-insensitive).
     *
     * @var array
     */
    public $redactFields = ['password', 'nik', 'Api-Key', 'no_telp'];

    /**
     * Maximum raw_body length in bytes before truncation.
     *
     * @var int
     */
    public $maxBodySize = 3145728; // 3 MB

    /**
     * IP/CIDR list for which analysis is skipped (internal traffic).
     *
     * @var array
     */
    public $whitelistIps = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1'];

    /**
     * Suffix appended when raw_body is truncated.
     *
     * @var string
     */
    public $truncateSuffix = '... [truncated]';

    /**
     * Maximum send attempts before a queued item is moved to failed/.
     *
     * @var int
     */
    public $maxRetries = 5;

    public function __construct()
    {
        parent::__construct();

        $this->enabled    = (bool) env('ANALYSIS_ENABLED', $this->enabled);
        $this->serverUrl  = (string) env('ANALYSIS_SERVER_URL', $this->serverUrl);
        $this->apiKey     = (string) env('ANALYSIS_API_KEY', $this->apiKey);
        $this->timeout    = (int) env('ANALYSIS_TIMEOUT', $this->timeout);
        $this->maxQueue   = (int) env('ANALYSIS_MAX_QUEUE', $this->maxQueue);
        $this->queueDir   = (string) env('ANALYSIS_QUEUE_DIR', $this->queueDir);
        $this->maxBodySize = (int) env('ANALYSIS_MAX_BODY_SIZE', $this->maxBodySize);
        $this->truncateSuffix = (string) env('ANALYSIS_TRUNCATE_SUFFIX', $this->truncateSuffix);

        $redact = (string) env('ANALYSIS_REDACT_FIELDS', '');
        if ($redact !== '') {
            $this->redactFields = $this->parseCsv($redact);
        }

        $whitelist = (string) env('ANALYSIS_WHITELIST_IPS', '');
        if ($whitelist !== '') {
            $this->whitelistIps = $this->parseCsv($whitelist);
        }

        $this->queueDir = $this->resolveQueueDir($this->queueDir);
    }

    /**
     * Split a comma separated environment value into a trimmed array.
     */
    protected function parseCsv(string $value): array
    {
        $items = array_map('trim', explode(',', $value));

        return array_values(array_filter($items, static function ($item) {
            return $item !== '';
        }));
    }

    /**
     * Normalize the queue directory, falling back to a temp directory.
     */
    protected function resolveQueueDir(string $dir): string
    {
        if ($dir === '') {
            $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'analysis_queue';
        }

        return $dir;
    }
}
