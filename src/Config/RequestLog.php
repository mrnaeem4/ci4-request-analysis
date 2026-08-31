<?php

namespace MrNaeem\Ci4RequestAnalysis\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuration for the request logging library.
 *
 * Values are resolved from REQUEST_LOG_* environment variables and fall back
 * to the defaults declared here.
 */
class RequestLog extends BaseConfig
{
    /**
     * Master switch. When false the filter does nothing.
     *
     * @var bool
     */
    public $enabled = false;

    /**
     * Log directory. When empty it resolves to CI4's writable/logs.
     *
     * @var string
     */
    public $logDir = '';

    /**
     * Log file name (single file; rotated daily with gzip).
     *
     * @var string
     */
    public $logFile = 'analysis.log';

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
     * IP/CIDR list for which logging is skipped (internal traffic).
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
     * How many days of compressed logs are kept before pruning.
     *
     * @var int
     */
    public $retentionDays = 30;

    public function __construct()
    {
        parent::__construct();

        $this->enabled       = (bool) env('REQUEST_LOG_ENABLED', $this->enabled);
        $this->logDir        = (string) env('REQUEST_LOG_DIR', $this->logDir);
        $this->logFile       = (string) env('REQUEST_LOG_FILE', $this->logFile);
        $this->maxBodySize   = (int) env('REQUEST_LOG_MAX_BODY_SIZE', $this->maxBodySize);
        $this->truncateSuffix = (string) env('REQUEST_LOG_TRUNCATE_SUFFIX', $this->truncateSuffix);
        $this->retentionDays = (int) env('REQUEST_LOG_RETENTION_DAYS', $this->retentionDays);

        $redact = (string) env('REQUEST_LOG_REDACT_FIELDS', '');
        if ($redact !== '') {
            $this->redactFields = $this->parseCsv($redact);
        }

        $whitelist = (string) env('REQUEST_LOG_WHITELIST_IPS', '');
        if ($whitelist !== '') {
            $this->whitelistIps = $this->parseCsv($whitelist);
        }
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
}
