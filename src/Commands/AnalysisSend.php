<?php

namespace MrNaeem\Ci4RequestAnalysis\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use MrNaeem\Ci4RequestAnalysis\Config\Analysis;
use MrNaeem\Ci4RequestAnalysis\Services\AnalysisService;

class AnalysisSend extends BaseCommand
{
    protected $group = 'Analysis';
    protected $name = 'analysis:send';
    protected $description = 'Send queued request logs to the Analysis Server.';

    protected $service;

    public function __construct()
    {
        $this->service = new AnalysisService(config(Analysis::class));
    }

    public function run(array $params)
    {
        $config = $this->service->getConfig();
        $dir = $config->queueDir;

        if (! is_dir($dir)) {
            CLI::write("Queue directory not found: {$dir}", 'yellow');
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if (! is_array($files) || $files === []) {
            CLI::write('No queued items to send.', 'green');
            return;
        }

        $success = 0;
        $failed = 0;

        foreach ($files as $file) {
            if ($this->service->sendFromQueue($file)) {
                @unlink($file);
                $success++;
            } else {
                $this->handleFailed($file);
                $failed++;
            }
        }

        CLI::write("analysis:send — sent: {$success}, failed: {$failed}.");
    }

    protected function handleFailed(string $file): void
    {
        $config = $this->service->getConfig();
        $content = @file_get_contents($file);
        if ($content === false) {
            return;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            @unlink($file);
            return;
        }

        $data['retry_count'] = ($data['retry_count'] ?? 0) + 1;
        $data['last_attempt'] = date('c');

        if ($data['retry_count'] > $config->maxRetries) {
            $failedDir = $config->queueDir . DIRECTORY_SEPARATOR . 'failed';
            if (! is_dir($failedDir)) {
                @mkdir($failedDir, 0755, true);
            }
            @rename($file, $failedDir . DIRECTORY_SEPARATOR . basename($file));
        } else {
            @file_put_contents($file, json_encode($data), LOCK_EX);
        }
    }
}