<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

http_response_code(200);
header('Content-Type: text/plain; charset=UTF-8');

if ($path === '/success') {
    echo 'success';
    return;
}

if ($path === '/fail') {
    echo 'temporary_error';
    return;
}

http_response_code(404);
echo 'not_found';
