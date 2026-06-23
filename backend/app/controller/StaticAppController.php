<?php

namespace app\controller;

use support\Request;
use support\Response;

class StaticAppController
{
    public function publicApp(Request $request): Response
    {
        return $this->renderBuild(public_path() . '/index.html');
    }

    public function home(Request $request): Response
    {
        return $this->publicApp($request);
    }

    public function admin(Request $request): Response
    {
        return $this->renderBuild(public_path() . '/admin/index.html');
    }

    public function user(Request $request): Response
    {
        return $this->renderBuild(public_path() . '/user/index.html');
    }

    public function doc(Request $request): Response
    {
        return $this->publicApp($request);
    }

    public function demo(Request $request): Response
    {
        return $this->publicApp($request);
    }

    private function renderBuild(string $path): Response
    {
        if (!is_file($path)) {
            $message = "Static build not found: {$path}\nRun: powershell -ExecutionPolicy Bypass -File .\\scripts\\build-release.ps1";
            return new Response(503, ['Content-Type' => 'text/plain; charset=utf-8'], $message);
        }

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ], file_get_contents($path));
    }
}
