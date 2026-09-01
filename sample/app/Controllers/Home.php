<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Simple demo controller to exercise the ci4-request-analysis filter.
 *
 * Pair it with the 'requestlog' filter in app/Config/Routes.php:
 *
 *   $routes->group('', ['filter' => 'requestlog'], function ($routes) {
 *       $routes->get('/', 'Home::index');
 *       $routes->post('post', 'Home::post');
 *       $routes->post('upload', 'Home::upload');
 *   });
 *
 * After sending requests, inspect writable/logs/analysis.log (JSONL) to verify
 * that the request body, headers, and file metadata were captured.
 */
class Home extends Controller
{
    public function index()
    {
        return view('home/index');
    }

    /**
     * Simple JSON POST endpoint. Send e.g.:
     *
     *   curl -X POST http://localhost:8080/post \
     *        -H 'Content-Type: application/json' \
     *        -d '{"name":"User","email":"user@example.com","password":"secret123"}'
     *
     * The password field should be redacted as "***REDACTED***" in the log.
     */
    public function post()
    {
        $request = $this->request;

        if ($request->getMethod() !== 'POST') {
            return $this->response
                ->setStatusCode(405)
                ->setJSON(['status' => 'error', 'message' => 'POST only']);
        }

        $name     = $request->getPost('name') ?? $request->getJSONVar('name');
        $email    = $request->getPost('email') ?? $request->getJSONVar('email');
        $password = $request->getPost('password') ?? $request->getJSONVar('password');

        $data = [
            'status'  => 'ok',
            'message' => 'Post received',
            'payload' => [
                'name'     => $name,
                'email'    => $email,
                'password' => $password,
            ],
        ];

        return $this->response->setJSON($data);
    }

    /**
     * File upload endpoint. Send multipart/form-data, e.g.:
     *
     *   curl -X POST http://localhost:8080/upload \
     *        -F 'title=My avatar' \
     *        -F 'avatar=@/path/to/shell.php.jpg'
     *
     * The filter captures file metadata (name, size, MIME, extension, SHA-256
     * hash, double-extension flag) without storing the binary content.
     */
    public function upload()
    {
        $request = $this->request;

        if ($request->getMethod() !== 'POST') {
            return $this->response
                ->setStatusCode(405)
                ->setJSON(['status' => 'error', 'message' => 'POST only']);
        }

        $title  = $request->getPost('title') ?? '';
        $errors = [];
        $saved  = [];

        $files = $request->getFiles();
        foreach ($files as $field => $file) {
            $items = is_array($file) ? $file : [$file];
            foreach ($items as $item) {
                if (! $item instanceof UploadedFile) {
                    continue;
                }

                if (! $item->isValid()) {
                    $errors[] = [
                        'field'   => $field,
                        'error'   => $item->getErrorString(),
                        'message' => $item->getError(),
                    ];
                    continue;
                }

                $name = $item->getRandomName();
                $item->move(WRITEPATH . 'uploads', $name);

                $saved[] = [
                    'field'   => $field,
                    'saved'   => WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . $name,
                    'name'    => $item->getName(),
                    'size'    => $item->getSize(),
                    'type'    => $item->getMimeType(),
                    'ext'     => $item->getExtension(),
                ];
            }
        }

        return $this->response->setJSON([
            'status'  => $errors === [] ? 'ok' : 'partial',
            'title'   => $title,
            'saved'   => $saved,
            'errors'  => $errors,
            'file_count' => count($saved) + count($errors),
        ]);
    }
}
