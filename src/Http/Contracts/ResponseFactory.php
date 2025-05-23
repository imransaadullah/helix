<?php

namespace Helix\Http\Contracts;

use Helix\Http\Response;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class ResponseFactory implements ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, [], null, '1.1', $reasonPhrase);
    }

    public function html(string $html, int $status = 200): Response
    {
        return Response::html($html, $status);
    }

    public function json($data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    public function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    public function file(string $path, ?string $filename = null): Response
    {
        return Response::file($path, $filename);
    }
}