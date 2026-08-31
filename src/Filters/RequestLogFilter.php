<?php

namespace MrNaeem\Ci4RequestAnalysis\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use MrNaeem\Ci4RequestAnalysis\Config\RequestLog;
use MrNaeem\Ci4RequestAnalysis\Services\RequestLogService;

/**
 * CI4 filter that captures request metadata and writes one JSONL line to the
 * CI4 logs directory. Attach manually to specific routes, e.g.:
 *
 *   $routes->group('api', ['filter' => 'requestlog'], function ($routes) {
 *       $routes->post('profile/update', 'Profile::update');
 *   });
 */
class RequestLogFilter implements FilterInterface
{
    protected $service;
    protected $config;

    public function __construct(?RequestLogService $service = null, ?RequestLog $config = null)
    {
        $this->config  = $config ?? config(RequestLog::class);
        $this->service = $service ?? new RequestLogService($this->config);
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

            $this->service->write($this->service->collect($request));
        } catch (\Throwable $e) {
            log_message('error', 'RequestLog: ' . $e->getMessage());
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
