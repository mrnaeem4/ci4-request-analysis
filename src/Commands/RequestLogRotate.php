<?php

namespace MrNaeem\Ci4RequestAnalysis\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use MrNaeem\Ci4RequestAnalysis\Config\RequestLog;
use MrNaeem\Ci4RequestAnalysis\Services\RequestLogService;

/**
 * Rotate and compress the request analysis log. The filter already rotates
 * automatically on write; this command is a manual/cron fallback that rotates
 * any previous-day file, gzips it and prunes files older than retention.
 */
class RequestLogRotate extends BaseCommand
{
    protected $group = 'RequestLog';
    protected $name = 'requestlog:rotate';
    protected $description = 'Rotate and compress the request analysis log (daily, gzip, retention).';

    protected $service;

    public function __construct()
    {
        $this->service = new RequestLogService(config(RequestLog::class));
    }

    public function run(array $params)
    {
        $this->service->rotate();

        CLI::write('requestlog:rotate done.', 'green');
    }
}
