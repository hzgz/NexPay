<?php

declare(strict_types=1);

fwrite(STDERR, "[deprecated] Plugin notify fallback has been disabled; running strict rejection verification instead." . PHP_EOL);

require __DIR__ . DIRECTORY_SEPARATOR . 'verify-plugin-notify-strict-rejection.php';
