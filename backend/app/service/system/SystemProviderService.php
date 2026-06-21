<?php

namespace app\service\system;

class SystemProviderService
{
    private const PLUGIN_ROOT = 'plugins';
    private const PROVIDER_SETTING_KEY_MAP = [
        'mail' => ['group' => 'mail', 'provider_key' => 'provider_code'],
        'sms' => ['group' => 'sms', 'provider_key' => 'provider_code'],
        'oauth' => ['group' => 'oauth', 'provider_key' => 'provider_code'],
        'captcha' => ['group' => 'auth', 'provider_key' => 'captcha_provider_code'],
        'geetest' => ['group' => 'verify', 'provider_key' => 'provider_code'],
        'realname' => ['group' => 'realname', 'provider_key' => 'provider'],
    ];

    public static function all(): array
    {
        return [
            'notify' => self::discoverGroup('notify'),
            'oauth' => self::discoverGroup('oauth'),
            'verify' => self::discoverGroup('verify'),
        ];
    }

    public static function settingOptions(): array
    {
        $groups = self::all();
        $notify = $groups['notify'];
        $verify = $groups['verify'];

        return [
            'mail' => self::filterByCapability($notify, 'send_mail'),
            'sms' => self::filterByCapability($notify, 'send_sms'),
            'oauth' => array_values($groups['oauth']),
            'geetest' => array_values(array_filter($verify, static fn(array $item): bool => str_contains((string)($item['code'] ?? ''), 'geetest'))),
            'captcha' => array_values(array_filter($verify, static fn(array $item): bool => !str_contains((string)($item['code'] ?? ''), 'geetest'))),
            'realname' => self::realnameProviders(),
        ];
    }

    public static function attachSelections(array $settings): array
    {
        $providerOptions = self::settingOptions();

        foreach (self::PROVIDER_SETTING_KEY_MAP as $bucket => $meta) {
            $groupKey = $meta['group'];
            $providerKey = $meta['provider_key'];
            $group = is_array($settings[$groupKey] ?? null) ? $settings[$groupKey] : [];
            $providers = is_array($providerOptions[$bucket] ?? null) ? $providerOptions[$bucket] : [];

            $selectedCode = trim((string)($group[$providerKey] ?? ''));
            if ($selectedCode === '' && $providers !== []) {
                $selectedCode = trim((string)($providers[0]['code'] ?? ''));
            }

            $selected = null;
            foreach ($providers as $provider) {
                if (($provider['code'] ?? '') === $selectedCode) {
                    $selected = $provider;
                    break;
                }
            }

            if ($selectedCode !== '') {
                $group[$providerKey] = $selectedCode;
            }

            $providerOptions[$bucket] = [
                'selected_code' => $selectedCode,
                'selected' => $selected,
                'items' => array_values($providers),
            ];

            $settings[$groupKey] = $group;
        }

        $settings['provider_options'] = $providerOptions;
        return $settings;
    }

    private static function discoverGroup(string $group): array
    {
        $root = base_path() . DIRECTORY_SEPARATOR . self::PLUGIN_ROOT . DIRECTORY_SEPARATOR . $group;
        if (!is_dir($root)) {
            return [];
        }

        $items = [];
        $plugins = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        foreach ($plugins as $pluginDir) {
            $manifestPath = $pluginDir . DIRECTORY_SEPARATOR . 'plugin.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifest = self::readJsonFile($manifestPath);
            if ($manifest === []) {
                continue;
            }

            $config = self::readConfigFile($pluginDir . DIRECTORY_SEPARATOR . 'config.php');
            $code = trim((string)($manifest['code'] ?? basename($pluginDir)));
            $name = trim((string)($manifest['name'] ?? $code));
            if ($code === '' || $name === '') {
                continue;
            }

            $items[] = [
                'code' => $code,
                'name' => $name,
                'group' => $group,
                'kind' => trim((string)($manifest['kind'] ?? ($config['kind'] ?? 'general'))),
                'description' => trim((string)($manifest['description'] ?? '')),
                'capabilities' => self::normalizeList($manifest['capabilities'] ?? ($config['capabilities'] ?? [])),
                'settings_schema' => is_array($config['settings_schema'] ?? null) ? $config['settings_schema'] : [],
                'default_settings' => is_array($config['default_settings'] ?? null) ? $config['default_settings'] : [],
            ];
        }

        usort($items, static fn(array $left, array $right): int => strcmp((string)$left['name'], (string)$right['name']));
        return $items;
    }

    private static function filterByCapability(array $items, string $capability): array
    {
        return array_values(array_filter($items, static function (array $item) use ($capability): bool {
            return in_array($capability, (array)($item['capabilities'] ?? []), true);
        }));
    }

    private static function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = (string)file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function readConfigFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $payload = include $path;
        return is_array($payload) ? $payload : [];
    }

    private static function normalizeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return array_values(array_unique($items));
    }

    private static function realnameProviders(): array
    {
        return [
            [
                'code' => 'manual',
                'name' => '人工审核',
                'group' => 'system',
                'kind' => 'realname',
                'description' => '仅保存实名资料，由后台人工审核处理。',
                'capabilities' => ['realname_review'],
                'settings_schema' => [],
                'default_settings' => [
                    'provider' => 'manual',
                ],
            ],
            [
                'code' => 'api',
                'name' => '实名接口',
                'group' => 'system',
                'kind' => 'realname',
                'description' => '调用外部实名接口自动完成身份证与姓名核验。',
                'capabilities' => ['realname_api'],
                'settings_schema' => [
                    ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text'],
                    ['key' => 'app_id', 'label' => '应用 ID', 'type' => 'text'],
                    ['key' => 'app_key', 'label' => '应用 Key', 'type' => 'text'],
                    ['key' => 'app_secret', 'label' => '应用 Secret', 'type' => 'password'],
                ],
                'default_settings' => [
                    'provider' => 'api',
                    'api_url' => '',
                    'app_id' => '',
                    'app_key' => '',
                    'app_secret' => '',
                ],
            ],
        ];
    }
}
