<?php

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\service\system\AccountService;
use app\service\system\AdminMerchantService;
use app\service\system\AnnouncementService;
use app\service\system\FileService;
use app\service\system\ManualPaymentService;
use app\service\system\ManualRefundService;
use app\service\system\ManualTransferService;
use app\service\system\MerchantGroupService;
use app\service\system\PackageService;
use app\service\system\PluginService;
use app\service\system\ProviderRuntimeService;
use app\service\system\ResourceDataService;
use app\service\system\SettlementService;
use app\service\system\SettingsService;
use app\service\system\SystemCleanupService;
use app\service\system\TaskService;
use app\service\system\TicketService;
use app\service\payment\CallbackService;
use app\service\payment\OrderService;
use support\Request;

class ResourceController extends BaseApiController
{
    public function merchants(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(ResourceDataService::adminMerchants());
        });
    }

    public function merchantCreate(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(AdminMerchantService::create($this->payload($request)), '商户新增成功');
        });
    }

    public function merchantReview(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');

            return $this->success(
                AdminMerchantService::review($this->payload($request), (string)($claims['username'] ?? 'admin')),
                'merchant review processed'
            );
        });
    }

    public function merchantRealnameReview(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');

            return $this->success(
                [
                    'merchant' => AccountService::reviewMerchantRealname(
                        $this->payload($request),
                        (string)($claims['username'] ?? 'admin')
                    ),
                    'items' => ResourceDataService::adminMerchants(),
                ],
                '实名审核已处理'
            );
        });
    }

    public function orders(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(ResourceDataService::adminOrders());
        });
    }

    public function orderManualConfirm(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);

            return $this->success(
                ManualPaymentService::confirm(
                    (string)($payload['trade_no'] ?? ''),
                    (string)($claims['username'] ?? 'admin'),
                    $payload
                ),
                '订单已人工确认收款'
            );
        });
    }

    public function refundManualConfirm(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);

            return $this->success(
                ManualRefundService::confirm(
                    (string)($payload['refund_no'] ?? ''),
                    (string)($claims['username'] ?? 'admin'),
                    $payload
                ),
                '退款已人工确认'
            );
        });
    }

    public function refundSync(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);

            return $this->success(
                OrderService::syncPendingRefundByNo(
                    (string)($payload['refund_no'] ?? ''),
                    (string)($claims['username'] ?? 'admin')
                ),
                '退款状态已同步'
            );
        });
    }

    public function transferReview(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);

            return $this->success(
                ManualTransferService::review(
                    (string)($payload['biz_no'] ?? ''),
                    (string)($payload['action'] ?? ''),
                    (string)($claims['username'] ?? 'admin'),
                    $payload
                ),
                '代付审核已处理'
            );
        });
    }

    public function transferSync(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);

            return $this->success(
                OrderService::syncPendingTransferByBizNo(
                    (string)($payload['biz_no'] ?? ''),
                    (string)($claims['username'] ?? 'admin')
                ),
                '代付状态已同步'
            );
        });
    }

    public function settlementReview(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(
                SettlementService::review(
                    (string)($payload['settle_no'] ?? ''),
                    (string)($payload['action'] ?? ''),
                    (string)($claims['username'] ?? 'admin'),
                    (string)($payload['reason'] ?? '')
                ),
                '结算审核已处理'
            );
        });
    }

    public function fees(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(ResourceDataService::adminFees());
        });
    }

    public function packages(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(PackageService::all());
        });
    }

    public function packageSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(PackageService::save($this->payload($request)), '套餐保存成功');
        });
    }

    public function settings(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success([
                ...SettingsService::all(),
                'announcements' => AnnouncementService::all()['items'],
            ]);
        });
    }

    public function settingsSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(SettingsService::save($this->payload($request)), '保存成功');
        });
    }

    public function settingsCacheClear(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(SettingsService::clearCache(), '缓存清理成功');
        });
    }

    public function settingsCleanup(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(SystemCleanupService::execute($this->payload($request)), '清理执行完成');
        });
    }

    public function providerTest(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);

            return $this->success(
                ProviderRuntimeService::testProvider(
                    (string)($payload['type'] ?? ''),
                    (string)($payload['target'] ?? ''),
                    (string)($claims['username'] ?? 'admin'),
                    (string)$request->getRealIp()
                ),
                'provider 测试已执行'
            );
        });
    }

    public function tickets(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(TicketService::adminData());
        });
    }

    public function ticketCreate(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(TicketService::createAdminTicket($this->payload($request)), '工单创建成功');
        });
    }

    public function ticketUpdate(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(TicketService::updateTicket($this->payload($request)), '工单更新成功');
        });
    }

    public function ticketCategorySave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(TicketService::saveCategory($this->payload($request)), '保存成功');
        });
    }

    public function ticketCategoryDelete(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(TicketService::deleteCategory((int)($payload['id'] ?? 0)), '删除成功');
        });
    }

    public function files(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(FileService::adminList());
        });
    }

    public function fileDelete(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(FileService::deleteForAdmin((int)($payload['id'] ?? 0)), '文件删除成功');
        });
    }

    public function plugins(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(PluginService::adminCatalog());
        });
    }

    public function pluginScan(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(PluginService::scan(), '扫描成功');
        });
    }

    public function pluginToggle(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(PluginService::toggle((string)($payload['code'] ?? ''), (int)($payload['status_code'] ?? 0)), '状态更新成功');
        });
    }

    public function pluginDelete(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(PluginService::delete((string)($payload['code'] ?? '')), '删除成功');
        });
    }

    public function pluginSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(PluginService::save($this->payload($request)), '保存成功');
        });
    }

    public function paymentMethodSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(PluginService::saveMethod($this->payload($request)), '保存成功');
        });
    }

    public function paymentMethodToggle(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(PluginService::toggleMethod((string)($payload['code'] ?? ''), (int)($payload['status_code'] ?? 0)), '状态更新成功');
        });
    }

    public function paymentMethodDelete(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(PluginService::deleteMethod((string)($payload['code'] ?? '')), '删除成功');
        });
    }

    public function merchantGroupSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(MerchantGroupService::save($this->payload($request)), '保存成功');
        });
    }

    public function merchantGroupDelete(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(MerchantGroupService::delete((int)($payload['id'] ?? 0)), '删除成功');
        });
    }

    public function announcementSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(AnnouncementService::save($this->payload($request)), '保存成功');
        });
    }

    public function announcementToggle(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(AnnouncementService::toggle((int)($payload['id'] ?? 0), (int)($payload['status_code'] ?? 0)), '状态更新成功');
        });
    }

    public function announcementDelete(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(AnnouncementService::delete((int)($payload['id'] ?? 0)), '删除成功');
        });
    }

    public function tasks(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(TaskService::all());
        });
    }

    public function taskRun(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(TaskService::run((string)($payload['key'] ?? ''), (string)($claims['username'] ?? 'admin')), '任务执行成功');
        });
    }

    public function taskSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            return $this->success(TaskService::saveCron((string)($payload['key'] ?? ''), (string)($payload['cron'] ?? '')), '任务配置已保存');
        });
    }

    public function taskLogs(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(TaskService::logs((string)$request->get('key', '')));
        });
    }

    public function logs(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(ResourceDataService::adminLogs());
        });
    }

    public function callbackRetry(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);

            return $this->success(
                CallbackService::retryNow((int)($payload['id'] ?? $payload['callback_id'] ?? 0)),
                '回调重试已执行'
            );
        });
    }

    public function refundSyncBatch(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);

            return $this->success(
                OrderService::syncPendingRefundsBatch(
                    (array)($payload['refund_nos'] ?? []),
                    (int)($payload['limit'] ?? 20),
                    (string)($claims['username'] ?? 'admin')
                ),
                '退款状态已批量同步'
            );
        });
    }

    public function transferSyncBatch(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);

            return $this->success(
                OrderService::syncPendingTransfersBatch(
                    (array)($payload['biz_nos'] ?? []),
                    (int)($payload['limit'] ?? 20),
                    (string)($claims['username'] ?? 'admin')
                ),
                '代付状态已批量同步'
            );
        });
    }

    public function profile(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            return $this->success(AccountService::adminProfile((int)$claims['sub']));
        });
    }

    public function profileSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            return $this->success(AccountService::saveAdminProfile((int)$claims['sub'], $this->payload($request)), '保存成功');
        });
    }

    public function passwordSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'admin');
            $payload = $this->payload($request);
            AccountService::changeAdminPassword((int)$claims['sub'], (string)($payload['old_password'] ?? ''), (string)($payload['new_password'] ?? ''));
            return $this->success([], '密码修改成功');
        });
    }
}
