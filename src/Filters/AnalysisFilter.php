<?php

namespace MrNaeem\Ci4RequestAnalysis\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use MrNaeem\Ci4RequestAnalysis\Config\Analysis;
use MrNaeem\Ci4RequestAnalysis\Services\AnalysisService;

class AnalysisFilter implements FilterInterface
{
    protected $service;
    protected $config;

    public function __construct(?AnalysisService $service = null, ?Analysis $config = null)
    {
        $this->config  = $config ?? config(Analysis::class);
        $this->service = $service ?? new AnalysisService($this->config);
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        try {
            if (! $this->config->enabled) {
                return;
            }

            if ($this->service->isIpWhitelisted($request->getIPAddress())) {
                return;
            }

            $this->service->queue($this->service->collect($request));
            $this->service->triggerSend();
        } catch (\Throwable $e) {
            log_message('error', 'RequestAnalysis: ' . $e->getMessage());
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}