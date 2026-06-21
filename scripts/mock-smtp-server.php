<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "mock-smtp-server.php must run in CLI\n");
    exit(1);
}

$port = (int)($argv[1] ?? 0);
$logPath = trim((string)($argv[2] ?? ''));
$maxMessages = max(1, (int)($argv[3] ?? 4));

if ($port <= 0 || $logPath === '') {
    fwrite(STDERR, "usage: php scripts/mock-smtp-server.php <port> <log_path> [max_messages]\n");
    exit(1);
}

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($server === false) {
    fwrite(STDERR, 'failed to start smtp server: ' . $error . PHP_EOL);
    exit(1);
}

stream_set_blocking($server, true);

$messages = 0;
$idleRounds = 0;
while ($messages < $maxMessages && $idleRounds < 20) {
    $client = @stream_socket_accept($server, 1);
    if ($client === false) {
        $idleRounds++;
        continue;
    }

    $idleRounds = 0;
    $messages += handleClient($client, $logPath) ? 1 : 0;
}

fclose($server);
exit(0);

function handleClient($client, string $logPath): bool
{
    stream_set_timeout($client, 10);
    fwrite($client, "220 mock.smtp.local ESMTP\r\n");

    $mail = [
        'auth_user' => '',
        'auth_pass' => '',
        'from' => '',
        'to' => '',
        'data' => '',
        'headers' => [],
        'body' => '',
    ];
    $messageStored = false;

    while (!feof($client)) {
        $line = fgets($client, 2048);
        if ($line === false) {
            break;
        }

        $command = rtrim($line, "\r\n");
        $upper = strtoupper($command);

        if (str_starts_with($upper, 'EHLO')) {
            fwrite($client, "250-mock.smtp.local Hello\r\n250 AUTH LOGIN\r\n");
            continue;
        }

        if ($upper === 'AUTH LOGIN') {
            fwrite($client, "334 VXNlcm5hbWU6\r\n");
            $mail['auth_user'] = base64_decode(trim((string)fgets($client)), true) ?: '';
            fwrite($client, "334 UGFzc3dvcmQ6\r\n");
            $mail['auth_pass'] = base64_decode(trim((string)fgets($client)), true) ?: '';
            fwrite($client, "235 Authentication successful\r\n");
            continue;
        }

        if (str_starts_with($upper, 'MAIL FROM:')) {
            $mail['from'] = trim(substr($command, strlen('MAIL FROM:')), "<> \t");
            fwrite($client, "250 OK\r\n");
            continue;
        }

        if (str_starts_with($upper, 'RCPT TO:')) {
            $mail['to'] = trim(substr($command, strlen('RCPT TO:')), "<> \t");
            fwrite($client, "250 OK\r\n");
            continue;
        }

        if ($upper === 'DATA') {
            fwrite($client, "354 End data with <CR><LF>.<CR><LF>\r\n");
            $data = '';
            while (($dataLine = fgets($client, 4096)) !== false) {
                if (rtrim($dataLine, "\r\n") === '.') {
                    break;
                }
                $data .= $dataLine;
            }

            $mail['data'] = $data;
            [$rawHeaders, $body] = array_pad(explode("\r\n\r\n", $data, 2), 2, '');
            $mail['headers'] = preg_split("/\r\n/", trim($rawHeaders)) ?: [];
            $mail['body'] = $body;
            file_put_contents($logPath, json_encode($mail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
            $messageStored = true;
            fwrite($client, "250 Message accepted\r\n");
            continue;
        }

        if ($upper === 'QUIT') {
            fwrite($client, "221 Bye\r\n");
            break;
        }

        fwrite($client, "250 OK\r\n");
    }

    fclose($client);
    return $messageStored;
}
