<?php

namespace app\service\system;

use app\constant\StatusCode;
use app\exception\BusinessException;

/**
 * Shared ticket storage for admin and merchant workflows.
 */
class TicketService
{
    private const CATEGORY_STORE_KEY = 'ticket_categories';
    private const TICKET_STORE_KEY = 'tickets';
    private const STATUS_PENDING = '待处理';
    private const STATUS_PROCESSING = '处理中';
    private const STATUS_REPLIED = '已回复';
    private const STATUS_CLOSED = '已关闭';

    public static function adminData(): array
    {
        return [
            'categories' => self::categories(),
            'items' => self::ticketSummaries(self::tickets()),
        ];
    }

    public static function merchantData(int $merchantId): array
    {
        return [
            'categories' => self::categories(),
            'items' => self::ticketSummaries(array_values(array_filter(
                self::tickets(),
                static fn(array $item): bool => (int)($item['merchant_id'] ?? 0) === $merchantId
            ))),
        ];
    }

    public static function adminTicketDetail(int $id): array
    {
        return ['ticket' => self::findTicketById($id)];
    }

    public static function merchantTicketDetail(int $merchantId, int $id): array
    {
        $ticket = self::findTicketById($id);
        if ((int)($ticket['merchant_id'] ?? 0) !== $merchantId) {
            throw new BusinessException('工单不存在或无权查看', StatusCode::NOT_FOUND);
        }

        return ['ticket' => $ticket];
    }

    public static function saveCategory(array $payload): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new BusinessException('工单分类名称不能为空', StatusCode::VALIDATION_ERROR);
        }

        $items = self::categories();
        $id = (int)($payload['id'] ?? 0);
        $updated = false;

        foreach ($items as &$item) {
            if ((int)$item['id'] === $id && $id > 0) {
                $item['name'] = $name;
                $item['status'] = trim((string)($payload['status'] ?? $item['status']));
                $item['description'] = trim((string)($payload['description'] ?? $item['description']));
                $updated = true;
                break;
            }
        }
        unset($item);

        if (!$updated) {
            $items[] = [
                'id' => self::nextId($items),
                'name' => $name,
                'status' => trim((string)($payload['status'] ?? '启用')),
                'description' => trim((string)($payload['description'] ?? '')),
            ];
        }

        JsonStoreService::save(self::CATEGORY_STORE_KEY, $items);
        return ['items' => $items];
    }

    public static function deleteCategory(int $id): array
    {
        $items = self::categories();
        $next = array_values(array_filter($items, static fn(array $item): bool => (int)($item['id'] ?? 0) !== $id));

        if (count($next) === count($items)) {
            throw new BusinessException('工单分类不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::CATEGORY_STORE_KEY, $next);
        return ['items' => $next];
    }

    public static function createTicket(int $merchantId, string $merchantName, array $payload): array
    {
        $title = trim((string)($payload['title'] ?? ''));
        $content = trim((string)($payload['content'] ?? ''));

        if ($title === '' || $content === '') {
            throw new BusinessException('工单标题和内容不能为空', StatusCode::VALIDATION_ERROR);
        }

        $merchant = self::merchantIdentity($merchantId, $merchantName);
        $categoryId = (int)($payload['category_id'] ?? 0);
        $items = self::tickets();
        $ticketNo = self::generateTicketNo($items);
        $now = date('Y-m-d H:i:s');

        $items[] = self::decorateTicket([
            'id' => self::nextId($items),
            'ticket_no' => $ticketNo,
            'merchant_id' => $merchantId,
            'merchant_name' => $merchant['name'],
            'merchant_avatar' => $merchant['avatar'],
            'category_id' => $categoryId,
            'category_name' => self::categoryName($categoryId),
            'title' => $title,
            'content' => $content,
            'priority' => trim((string)($payload['priority'] ?? '普通')),
            'status' => self::STATUS_PENDING,
            'created_by' => 'merchant',
            'created_at' => $now,
            'updated_at' => $now,
            'messages' => [
                self::messageRow(
                    1,
                    'merchant',
                    $merchant['name'],
                    $merchant['avatar'],
                    $content,
                    $now
                ),
            ],
        ]);

        JsonStoreService::save(self::TICKET_STORE_KEY, self::persistableTickets($items));
        return self::merchantData($merchantId);
    }

    public static function createAdminTicket(array $payload): array
    {
        $merchantId = (int)($payload['merchant_id'] ?? 0);
        $merchantName = trim((string)($payload['merchant_name'] ?? ''));
        $title = trim((string)($payload['title'] ?? ''));
        $content = trim((string)($payload['content'] ?? ''));
        $priority = trim((string)($payload['priority'] ?? '普通'));
        $categoryId = (int)($payload['category_id'] ?? 0);
        $adminName = trim((string)($payload['admin_name'] ?? '管理员'));
        $adminAvatar = trim((string)($payload['admin_avatar'] ?? ''));

        if ($merchantId <= 0 || $merchantName === '' || $title === '' || $content === '') {
            throw new BusinessException('请完整填写工单信息', StatusCode::VALIDATION_ERROR);
        }

        $merchant = self::merchantIdentity($merchantId, $merchantName);
        $items = self::tickets();
        $ticketNo = self::generateTicketNo($items);
        $now = date('Y-m-d H:i:s');

        $items[] = self::decorateTicket([
            'id' => self::nextId($items),
            'ticket_no' => $ticketNo,
            'merchant_id' => $merchantId,
            'merchant_name' => $merchant['name'],
            'merchant_avatar' => $merchant['avatar'],
            'category_id' => $categoryId,
            'category_name' => self::categoryName($categoryId),
            'title' => $title,
            'content' => $content,
            'priority' => $priority,
            'status' => self::STATUS_REPLIED,
            'created_by' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
            'messages' => [
                self::messageRow(
                    1,
                    'admin',
                    $adminName !== '' ? $adminName : '管理员',
                    $adminAvatar,
                    $content,
                    $now
                ),
            ],
        ]);

        JsonStoreService::save(self::TICKET_STORE_KEY, self::persistableTickets($items));
        return self::adminData();
    }

    public static function replyByMerchant(int $merchantId, string $merchantName, array $payload): array
    {
        $id = (int)($payload['id'] ?? 0);
        $content = trim((string)($payload['content'] ?? ''));
        if ($id <= 0 || $content === '') {
            throw new BusinessException('请填写回复内容', StatusCode::VALIDATION_ERROR);
        }

        $merchant = self::merchantIdentity($merchantId, $merchantName);
        $items = self::tickets();
        $found = false;

        foreach ($items as &$item) {
            if ((int)($item['id'] ?? 0) !== $id) {
                continue;
            }

            if ((int)($item['merchant_id'] ?? 0) !== $merchantId) {
                throw new BusinessException('工单不存在或无权回复', StatusCode::NOT_FOUND);
            }

            $item = self::appendMessage(
                $item,
                'merchant',
                $merchant['name'],
                $merchant['avatar'],
                $content,
                self::STATUS_PENDING,
                null
            );

            $found = true;
            break;
        }
        unset($item);

        if (!$found) {
            throw new BusinessException('工单不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::TICKET_STORE_KEY, self::persistableTickets($items));
        return ['ticket' => self::findTicketFromItems($items, $id)];
    }

    public static function replyByAdmin(string $adminName, string $adminAvatar, array $payload): array
    {
        $id = (int)($payload['id'] ?? 0);
        $content = trim((string)($payload['content'] ?? ''));
        $status = trim((string)($payload['status'] ?? self::STATUS_REPLIED));
        $priority = trim((string)($payload['priority'] ?? ''));

        if ($id <= 0) {
            throw new BusinessException('工单不存在', StatusCode::VALIDATION_ERROR);
        }

        $items = self::tickets();
        $found = false;

        foreach ($items as &$item) {
            if ((int)($item['id'] ?? 0) !== $id) {
                continue;
            }

            $replyStatus = $status !== '' ? $status : self::STATUS_REPLIED;
            if ($content !== '') {
                $item = self::appendMessage(
                    $item,
                    'admin',
                    $adminName !== '' ? $adminName : '管理员',
                    $adminAvatar,
                    $content,
                    $replyStatus,
                    $priority !== '' ? $priority : null
                );
            } else {
                if ($priority !== '') {
                    $item['priority'] = $priority;
                }
                if ($replyStatus !== '') {
                    $item['status'] = $replyStatus;
                }
                $item['updated_at'] = date('Y-m-d H:i:s');
                $item = self::decorateTicket($item);
            }

            $found = true;
            break;
        }
        unset($item);

        if (!$found) {
            throw new BusinessException('工单不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::TICKET_STORE_KEY, self::persistableTickets($items));
        return ['ticket' => self::findTicketFromItems($items, $id)];
    }

    public static function updateTicket(array $payload): array
    {
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new BusinessException('工单不存在', StatusCode::VALIDATION_ERROR);
        }

        $items = self::tickets();
        $found = false;

        foreach ($items as &$item) {
            if ((int)($item['id'] ?? 0) !== $id) {
                continue;
            }

            if (isset($payload['priority'])) {
                $item['priority'] = trim((string)$payload['priority']);
            }
            if (isset($payload['status'])) {
                $item['status'] = trim((string)$payload['status']);
            }

            $legacyReply = trim((string)($payload['reply'] ?? ''));
            if ($legacyReply !== '') {
                $latest = self::latestMessage($item);
                $latestType = (string)($latest['sender_type'] ?? '');
                $latestContent = trim((string)($latest['content'] ?? ''));

                if ($latestType !== 'admin' || $latestContent !== $legacyReply) {
                    $item = self::appendMessage(
                        $item,
                        'admin',
                        trim((string)($payload['admin_name'] ?? '管理员')) ?: '管理员',
                        trim((string)($payload['admin_avatar'] ?? '')),
                        $legacyReply,
                        trim((string)($payload['status'] ?? self::STATUS_REPLIED)) ?: self::STATUS_REPLIED,
                        trim((string)($payload['priority'] ?? '')) ?: null
                    );
                } else {
                    $item['updated_at'] = date('Y-m-d H:i:s');
                    $item = self::decorateTicket($item);
                }
            } else {
                $item['updated_at'] = date('Y-m-d H:i:s');
                $item = self::decorateTicket($item);
            }

            $found = true;
            break;
        }
        unset($item);

        if (!$found) {
            throw new BusinessException('工单不存在', StatusCode::NOT_FOUND);
        }

        JsonStoreService::save(self::TICKET_STORE_KEY, self::persistableTickets($items));
        return ['items' => self::ticketSummaries($items)];
    }

    private static function categories(): array
    {
        return JsonStoreService::load(self::CATEGORY_STORE_KEY, self::defaultCategories());
    }

    private static function tickets(): array
    {
        $items = array_values(array_filter(
            JsonStoreService::load(self::TICKET_STORE_KEY, []),
            static fn(array $item): bool => !self::isSeedTicket($item)
        ));

        $normalized = array_map(static fn(array $item): array => self::decorateTicket($item), $items);

        usort($normalized, static function (array $a, array $b): int {
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        });

        return $normalized;
    }

    private static function ticketSummaries(array $items): array
    {
        return array_map(static function (array $item): array {
            $summary = $item;
            unset($summary['reply']);
            return $summary;
        }, $items);
    }

    private static function persistableTickets(array $items): array
    {
        return array_map(static function (array $item): array {
            unset(
                $item['message_count'],
                $item['last_message'],
                $item['last_message_time'],
                $item['last_sender_name'],
                $item['last_sender_type'],
                $item['status_options']
            );

            return $item;
        }, $items);
    }

    private static function findTicketById(int $id): array
    {
        return self::findTicketFromItems(self::tickets(), $id);
    }

    private static function findTicketFromItems(array $items, int $id): array
    {
        foreach ($items as $item) {
            if ((int)($item['id'] ?? 0) === $id) {
                return self::decorateTicket($item);
            }
        }

        throw new BusinessException('工单不存在', StatusCode::NOT_FOUND);
    }

    private static function decorateTicket(array $item): array
    {
        $normalized = $item;
        $merchantIdentity = self::merchantIdentity((int)($normalized['merchant_id'] ?? 0), (string)($normalized['merchant_name'] ?? ''));
        $normalized['category_name'] = self::categoryName((int)($normalized['category_id'] ?? 0), (string)($normalized['category_name'] ?? ''));
        $normalized['priority'] = trim((string)($normalized['priority'] ?? '普通')) ?: '普通';
        $normalized['status'] = trim((string)($normalized['status'] ?? self::STATUS_PENDING)) ?: self::STATUS_PENDING;
        $normalized['merchant_name'] = trim((string)($normalized['merchant_name'] ?? '')) ?: ('商户' . (int)($normalized['merchant_id'] ?? 0));
        $normalized['merchant_avatar'] = trim((string)($normalized['merchant_avatar'] ?? self::merchantIdentity((int)($normalized['merchant_id'] ?? 0), $normalized['merchant_name'])['avatar']));
        $normalized['merchant_name'] = (string)($merchantIdentity['name'] ?? trim((string)($normalized['merchant_name'] ?? '')));
        if ($normalized['merchant_name'] === '') {
            $normalized['merchant_name'] = '商户' . (int)($normalized['merchant_id'] ?? 0);
        }
        $normalized['merchant_avatar'] = trim((string)($merchantIdentity['avatar'] ?? $normalized['merchant_avatar'] ?? ''));
        $normalized['messages'] = self::resolveTicketMessagesNormalized($normalized);

        $latest = self::latestMessage($normalized);
        $normalized['message_count'] = count($normalized['messages']);
        $normalized['last_message'] = trim((string)($latest['content'] ?? ''));
        $normalized['last_message_time'] = trim((string)($latest['created_at'] ?? (string)($normalized['updated_at'] ?? '')));
        $normalized['last_sender_name'] = trim((string)($latest['sender_name'] ?? ''));
        $normalized['last_sender_type'] = trim((string)($latest['sender_type'] ?? ''));
        $normalized['reply'] = self::latestAdminReplyText($normalized['messages']);
        $normalized['status_options'] = [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_REPLIED,
            self::STATUS_CLOSED,
        ];

        return $normalized;
    }

    private static function resolveTicketMessages(array $ticket): array
    {
        $messages = self::normalizeMessages($ticket);
        $merchantIdentity = self::merchantIdentity((int)($ticket['merchant_id'] ?? 0), (string)($ticket['merchant_name'] ?? ''));
        $merchantName = trim((string)($merchantIdentity['name'] ?? ''));
        $merchantAvatar = trim((string)($merchantIdentity['avatar'] ?? $ticket['merchant_avatar'] ?? ''));
        $adminIdentity = AccountService::adminIdentity(1, '系统管理员');

        foreach ($messages as &$message) {
            if (!is_array($message)) {
                continue;
            }

            $senderType = (string)($message['sender_type'] ?? '');
            if ($senderType === 'admin') {
                if (trim((string)($message['sender_name'] ?? '')) === '') {
                    $message['sender_name'] = (string)($adminIdentity['name'] ?? '系统管理员');
                }
                if (trim((string)($message['sender_avatar'] ?? '')) === '') {
                    $message['sender_avatar'] = trim((string)($adminIdentity['avatar'] ?? ''));
                }
                continue;
            }

            if (trim((string)($message['sender_name'] ?? '')) === '') {
                $message['sender_name'] = (string)($merchantIdentity['name'] ?? '商户');
            }
            if (trim((string)($message['sender_avatar'] ?? '')) === '') {
                $message['sender_avatar'] = trim((string)($merchantIdentity['avatar'] ?? $ticket['merchant_avatar'] ?? ''));
            }
        }
        unset($message);
        $adminAvatar = trim((string)($adminIdentity['avatar'] ?? ''));
        foreach ($messages as &$message) {
            if (!is_array($message)) {
                continue;
            }

            if ((string)($message['sender_type'] ?? '') === 'admin') {
                $message['sender_name'] = '管理员';
                if (trim((string)($message['sender_avatar'] ?? '')) === '') {
                    $message['sender_avatar'] = $adminAvatar;
                }
                continue;
            }

            $message['sender_name'] = $merchantName !== '' ? $merchantName : '商户';
            if (trim((string)($message['sender_avatar'] ?? '')) === '') {
                $message['sender_avatar'] = $merchantAvatar;
            }
        }
        unset($message);

        $adminLabel = "\u{7BA1}\u{7406}\u{5458}";
        $merchantLabel = "\u{5546}\u{6237}";
        foreach ($messages as &$message) {
            if (!is_array($message)) {
                continue;
            }

            if ((string)($message['sender_type'] ?? '') === 'admin') {
                $message['sender_name'] = $adminLabel;
            } else {
                $message['sender_name'] = $merchantName !== '' ? $merchantName : $merchantLabel;
            }
        }
        unset($message);

        return array_values($messages);
    }

    private static function normalizeMessages(array $ticket): array
    {
        $messages = [];
        $stored = $ticket['messages'] ?? [];

        if (is_array($stored) && $stored !== []) {
            foreach (array_values($stored) as $index => $message) {
                if (!is_array($message)) {
                    continue;
                }

                $senderType = trim((string)($message['sender_type'] ?? ''));
                $senderName = trim((string)($message['sender_name'] ?? ''));
                $senderAvatar = trim((string)($message['sender_avatar'] ?? ''));

                if ($senderType === '') {
                    $senderType = trim((string)($message['side'] ?? 'merchant'));
                }

                if ($senderName === '') {
                    $senderName = $senderType === 'admin'
                        ? '管理员'
                        : trim((string)($ticket['merchant_name'] ?? '商户'));
                }

                if ($senderAvatar === '' && $senderType !== 'admin') {
                    $senderAvatar = trim((string)($ticket['merchant_avatar'] ?? ''));
                }

                $content = trim((string)($message['content'] ?? $message['message'] ?? ''));
                if ($content === '') {
                    continue;
                }

                $messages[] = self::messageRow(
                    $index + 1,
                    $senderType,
                    $senderName,
                    $senderAvatar,
                    $content,
                    trim((string)($message['created_at'] ?? '')) ?: trim((string)($ticket['updated_at'] ?? $ticket['created_at'] ?? date('Y-m-d H:i:s')))
                );
            }
        }

        if ($messages === []) {
            $createdBy = trim((string)($ticket['created_by'] ?? 'merchant'));
            $merchantName = trim((string)($ticket['merchant_name'] ?? '商户'));
            $merchantAvatar = trim((string)($ticket['merchant_avatar'] ?? ''));
            $createdAt = trim((string)($ticket['created_at'] ?? date('Y-m-d H:i:s')));
            $content = trim((string)($ticket['content'] ?? ''));

            if ($content !== '') {
                $messages[] = self::messageRow(
                    1,
                    $createdBy === 'admin' ? 'admin' : 'merchant',
                    $createdBy === 'admin' ? '管理员' : $merchantName,
                    $createdBy === 'admin' ? '' : $merchantAvatar,
                    $content,
                    $createdAt
                );
            }

            $legacyReply = trim((string)($ticket['reply'] ?? ''));
            if ($legacyReply !== '') {
                $messages[] = self::messageRow(
                    count($messages) + 1,
                    'admin',
                    '管理员',
                    '',
                    $legacyReply,
                    trim((string)($ticket['updated_at'] ?? $createdAt)) ?: $createdAt
                );
            }
        }

        usort($messages, static function (array $a, array $b): int {
            $timeCompare = strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });

        return array_values($messages);
    }

    private static function latestMessage(array $ticket): array
    {
        $messages = is_array($ticket['messages'] ?? null) ? $ticket['messages'] : [];
        if ($messages === []) {
            return [];
        }

        return $messages[count($messages) - 1];
    }

    private static function latestAdminReplyText(array $messages): string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index] ?? null;
            if (!is_array($message)) {
                continue;
            }

            if ((string)($message['sender_type'] ?? '') === 'admin') {
                return trim((string)($message['content'] ?? ''));
            }
        }

        return '';
    }

    private static function appendMessage(
        array $ticket,
        string $senderType,
        string $senderName,
        string $senderAvatar,
        string $content,
        ?string $status,
        ?string $priority
    ): array {
        $ticket = self::decorateTicket($ticket);
        $messages = is_array($ticket['messages'] ?? null) ? $ticket['messages'] : [];
        $now = date('Y-m-d H:i:s');

        $messages[] = self::messageRow(
            self::nextId($messages),
            $senderType,
            $senderName,
            $senderAvatar,
            $content,
            $now
        );

        $ticket['messages'] = $messages;
        $ticket['updated_at'] = $now;

        if ($priority !== null && trim($priority) !== '') {
            $ticket['priority'] = trim($priority);
        }

        if ($status !== null && trim($status) !== '') {
            $ticket['status'] = trim($status);
        }

        if ($senderType === 'merchant' && ($status === null || trim($status) === '')) {
            $ticket['status'] = self::STATUS_PENDING;
        }

        if ($senderType === 'admin' && ($status === null || trim($status) === '')) {
            $ticket['status'] = self::STATUS_REPLIED;
        }

        return self::decorateTicket($ticket);
    }

    private static function messageRow(
        int $id,
        string $senderType,
        string $senderName,
        string $senderAvatar,
        string $content,
        string $createdAt
    ): array {
        $type = $senderType === 'admin' ? 'admin' : 'merchant';

        return [
            'id' => $id,
            'sender_type' => $type,
            'sender_name' => trim($senderName) !== '' ? trim($senderName) : ($type === 'admin' ? '管理员' : '商户'),
            'sender_avatar' => trim($senderAvatar),
            'content' => trim($content),
            'created_at' => trim($createdAt) !== '' ? trim($createdAt) : date('Y-m-d H:i:s'),
            'side' => $type === 'admin' ? 'left' : 'right',
        ];
    }

    private static function merchantIdentity(int $merchantId, string $fallbackName): array
    {
        $profile = AccountService::merchantCredentialById($merchantId);
        $name = trim((string)($profile['name'] ?? $profile['merchant_name'] ?? $profile['nickname'] ?? $profile['username'] ?? ''));
        $avatar = trim((string)($profile['avatar'] ?? ''));

        return [
            'name' => $name !== '' ? $name : (trim($fallbackName) !== '' ? trim($fallbackName) : ('商户' . $merchantId)),
            'avatar' => $avatar,
        ];
    }

    private static function categoryName(int $categoryId, string $fallback = '未分类'): string
    {
        foreach (self::categories() as $category) {
            if ((int)($category['id'] ?? 0) === $categoryId) {
                return trim((string)($category['name'] ?? '未分类')) ?: '未分类';
            }
        }

        return trim($fallback) !== '' ? trim($fallback) : '未分类';
    }

    private static function resolveTicketMessagesNormalized(array $ticket): array
    {
        $messages = self::normalizeMessages($ticket);
        $merchantId = (int)($ticket['merchant_id'] ?? 0);
        $merchantIdentity = self::merchantIdentity($merchantId, (string)($ticket['merchant_name'] ?? ''));
        $merchantName = trim((string)($merchantIdentity['name'] ?? ''));
        if ($merchantName === '') {
            $merchantName = self::merchantLabel($merchantId);
        }

        $merchantAvatar = trim((string)($merchantIdentity['avatar'] ?? $ticket['merchant_avatar'] ?? ''));
        $adminIdentity = AccountService::adminIdentity(1, self::adminLabel());
        $adminAvatar = trim((string)($adminIdentity['avatar'] ?? ''));

        foreach ($messages as &$message) {
            if (!is_array($message)) {
                continue;
            }

            $senderType = (string)($message['sender_type'] ?? '');
            if ($senderType === 'admin') {
                $message['sender_type'] = 'admin';
                $message['sender_name'] = self::adminLabel();
                $message['sender_avatar'] = $adminAvatar !== ''
                    ? $adminAvatar
                    : trim((string)($message['sender_avatar'] ?? ''));
                $message['side'] = 'left';
                continue;
            }

            $message['sender_type'] = 'merchant';
            $message['sender_name'] = $merchantName;
            $message['sender_avatar'] = $merchantAvatar !== ''
                ? $merchantAvatar
                : trim((string)($message['sender_avatar'] ?? ''));
            $message['side'] = 'right';
        }
        unset($message);

        return array_values($messages);
    }

    private static function adminLabel(): string
    {
        return "\u{7BA1}\u{7406}\u{5458}";
    }

    private static function merchantLabel(int $merchantId = 0): string
    {
        return $merchantId > 0 ? "\u{5546}\u{6237}" . $merchantId : "\u{5546}\u{6237}";
    }

    private static function generateTicketNo(array $items): string
    {
        $prefix = 'TK' . date('YmdHis');
        $suffix = 1;

        do {
            $ticketNo = $prefix . str_pad((string)$suffix, 2, '0', STR_PAD_LEFT);
            $exists = false;
            foreach ($items as $item) {
                if ((string)($item['ticket_no'] ?? '') === $ticketNo) {
                    $exists = true;
                    break;
                }
            }
            $suffix++;
        } while ($exists);

        return $ticketNo;
    }

    private static function nextId(array $items): int
    {
        $ids = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items);
        return ($ids ? max($ids) : 0) + 1;
    }

    private static function defaultCategories(): array
    {
        return [
            ['id' => 1, 'name' => '通道异常', 'status' => '启用', 'description' => '支付回调、到账异常、通道不可用'],
            ['id' => 2, 'name' => '套餐与计费', 'status' => '启用', 'description' => '套餐购买、续费、费率问题'],
            ['id' => 3, 'name' => '资料审核', 'status' => '启用', 'description' => '实名认证、资质审核、账号资料'],
        ];
    }

    private static function isSeedTicket(array $item): bool
    {
        $ticketNo = (string)($item['ticket_no'] ?? '');
        $title = (string)($item['title'] ?? '');

        return in_array($ticketNo, ['TK20260611001', 'TK20260609004', 'TK20260611002'], true)
            && in_array($title, ['TRC20 到账回调延迟', '申请上调单日限额', '企业资质补件'], true);
    }
}
