<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

use app\model\AdminUser;
use app\service\system\JsonStoreService;
use Dotenv\Dotenv;
use support\App;

$rootPath = dirname(__DIR__);
if (class_exists(Dotenv::class) && is_file($rootPath . '/.env')) {
    if (method_exists(Dotenv::class, 'createUnsafeImmutable')) {
        Dotenv::createUnsafeImmutable($rootPath)->load();
    } else {
        Dotenv::createMutable($rootPath)->load();
    }
}

if (class_exists(App::class) && method_exists(App::class, 'loadAllConfig')) {
    App::loadAllConfig(['route']);
}

$options = getopt('', ['username::', 'password:', 'nickname::', 'email::']);

$username = trim((string)($options['username'] ?? 'admin'));
$password = (string)($options['password'] ?? '');
$nickname = trim((string)($options['nickname'] ?? 'PlatformAdmin'));
$email = trim((string)($options['email'] ?? ''));

if ($username === '') {
    fwrite(STDERR, "The --username value can not be empty.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "The --password value must be at least 8 characters.\n");
    exit(1);
}

if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "The --email value is not a valid email address.\n");
    exit(1);
}

$passwordHash = password_hash($password, PASSWORD_BCRYPT);
if (!is_string($passwordHash) || $passwordHash === '') {
    fwrite(STDERR, "Failed to create password hash.\n");
    exit(1);
}

$result = createOrUpdateAdmin($username, $passwordHash, $nickname, $email);
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

function createOrUpdateAdmin(string $username, string $passwordHash, string $nickname, string $email): array
{
    try {
        \think\facade\Db::query('SELECT 1');

        $admin = AdminUser::where('username', $username)->find();
        $action = 'created';
        if ($admin) {
            $action = 'updated';
        } else {
            $admin = new AdminUser();
            $admin->username = $username;
        }

        $admin->nickname = $nickname !== '' ? $nickname : $username;
        $admin->email = $email !== '' ? $email : null;
        $admin->password_hash = $passwordHash;
        $admin->status = 1;
        $admin->save();

        return [
            'storage' => 'database',
            'action' => $action,
            'id' => (int)$admin->id,
            'username' => (string)$admin->username,
            'nickname' => (string)$admin->nickname,
            'email' => (string)($admin->email ?? ''),
        ];
    } catch (Throwable) {
        return createOrUpdateAdminLocal($username, $passwordHash, $nickname, $email);
    }
}

function createOrUpdateAdminLocal(string $username, string $passwordHash, string $nickname, string $email): array
{
    $admins = JsonStoreService::load('admin_accounts', []);
    $maxId = 0;

    foreach ($admins as $index => $admin) {
        if (!is_array($admin)) {
            continue;
        }

        $maxId = max($maxId, (int)($admin['id'] ?? 0));
        if (trim((string)($admin['username'] ?? '')) !== $username) {
            continue;
        }

        $admins[$index] = array_replace($admin, [
            'username' => $username,
            'nickname' => $nickname !== '' ? $nickname : $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'status' => 1,
        ]);

        JsonStoreService::save('admin_accounts', $admins);

        return [
            'storage' => 'json',
            'action' => 'updated',
            'id' => (int)($admins[$index]['id'] ?? 0),
            'username' => $username,
            'nickname' => (string)($admins[$index]['nickname'] ?? $username),
            'email' => (string)($admins[$index]['email'] ?? ''),
        ];
    }

    $nextId = $maxId + 1;
    $admins[] = [
        'id' => $nextId,
        'username' => $username,
        'nickname' => $nickname !== '' ? $nickname : $username,
        'email' => $email,
        'phone' => '',
        'role' => 'super_admin',
        'avatar' => '',
        'password_hash' => $passwordHash,
        'status' => 1,
    ];

    JsonStoreService::save('admin_accounts', $admins);

    return [
        'storage' => 'json',
        'action' => 'created',
        'id' => $nextId,
        'username' => $username,
        'nickname' => $nickname !== '' ? $nickname : $username,
        'email' => $email,
    ];
}
