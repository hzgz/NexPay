<?php

namespace app\controller\merchant;

use app\controller\BaseApiController;
use app\service\system\AccountService;
use app\service\system\AuthPolicyService;
use app\service\system\FileService;
use app\service\system\MerchantApiService;
use app\service\system\MerchantFundService;
use app\service\system\MerchantChannelService;
use app\service\system\MerchantChannelQrConfigService;
use app\service\system\PackageService;
use app\service\system\ResourceDataService;
use app\service\system\SettlementService;
use app\service\system\TicketService;
use support\Request;

class ResourceController extends BaseApiController
{
    public function channels(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            return $this->success(MerchantChannelService::all((int)$claims['merchant_id']));
        });
    }

    public function channelSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            return $this->success(MerchantChannelService::saveItem((int)$claims['merchant_id'], $this->payload($request)), '保存成功');
        });
    }

    public function channelToggle(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            $payload = $this->payload($request);

            return $this->success(
                MerchantChannelService::toggleItem(
                    (int)$claims['merchant_id'],
                    (int)($payload['id'] ?? 0),
                    (int)($payload['status_code'] ?? 0)
                ),
                '状态更新成功'
            );
        });
    }

    public function channelDelete(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            $payload = $this->payload($request);

            return $this->success(
                MerchantChannelService::deleteItem((int)$claims['merchant_id'], (int)($payload['id'] ?? 0)),
                '删除成功'
            );
        });
    }

    public function channelRotationSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            return $this->success(MerchantChannelService::saveRotation((int)$claims['merchant_id'], $this->payload($request)), '轮询设置已保存');
        });
    }

    public function channelPaymentSettingsSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            return $this->success(MerchantChannelService::savePaymentSettings((int)$claims['merchant_id'], $this->payload($request)), '支付设置已保存');
        });
    }

    public function channelTest(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            $payload = $this->payload($request);

            return $this->success(
                MerchantChannelService::testItem((int)$claims['merchant_id'], (int)($payload['id'] ?? 0), $payload),
                '测试订单已创建'
            );
        });
    }

    public function channelConfigUpload(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);

            return $this->success(
                MerchantChannelQrConfigService::upload(
                    (int)$claims['merchant_id'],
                    $request->all(),
                    $request->file('file')
                ),
                '二维码配置上传成功'
            );
        });
    }

    public function orders(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            return $this->success(ResourceDataService::merchantOrders((int)$claims['merchant_id']));
        });
    }

    public function funds(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            return $this->success(ResourceDataService::merchantFunds((int)$claims['merchant_id']));
        });
    }

    public function fundRecharge(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            $payload = $this->payload($request);
            $payload['client_ip'] = $request->getRealIp();
            return $this->success(
                MerchantFundService::createRechargeOrder((int)$claims['merchant_id'], $payload),
                '充值订单已创建'
            );
        });
    }

    public function fundWithdraw(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            return $this->success(
                SettlementService::requestWithdraw(
                    (int)$claims['merchant_id'],
                    (int)($claims['sub'] ?? 0),
                    $this->payload($request)
                ),
                '提现申请已提交，等待后台审核'
            );
        });
    }

    public function packages(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            return $this->success(ResourceDataService::merchantPackages((int)$claims['merchant_id']));
        });
    }

    public function packageBuy(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            return $this->success(
                PackageService::buy((int)$claims['merchant_id'], $this->payload($request)),
                '套餐购买成功'
            );
        });
    }

    public function apiInfo(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            return $this->success(ResourceDataService::merchantApiInfo((int)$claims['merchant_id'], (int)($claims['sub'] ?? 0)));
        });
    }

    public function apiInfoResetMd5(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            return $this->success(
                MerchantApiService::resetMd5Key((int)$claims['merchant_id'], (int)($claims['sub'] ?? 0)),
                'MD5 密钥已重置'
            );
        });
    }

    public function apiInfoGenerateRsa(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            return $this->success(
                MerchantApiService::generateRsaKeyPair((int)$claims['merchant_id'], (int)($claims['sub'] ?? 0)),
                'RSA 密钥对已生成'
            );
        });
    }

    public function apiInfoSaveSignMode(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $this->requireRealname($claims);
            return $this->success(
                MerchantApiService::saveSignMode((int)$claims['merchant_id'], (int)($claims['sub'] ?? 0), $this->payload($request)),
                '签名方式已保存'
            );
        });
    }

    public function telegram(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'merchant');
            return $this->success(ResourceDataService::merchantTelegram());
        });
    }

    public function profile(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            return $this->success(AccountService::merchantProfile((int)$claims['sub']));
        });
    }

    public function profileSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            return $this->success(AccountService::saveMerchantProfile((int)$claims['sub'], $this->payload($request)), '保存成功');
        });
    }

    public function passwordSave(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $payload = $this->payload($request);

            AccountService::changeMerchantPassword((int)$claims['sub'], (string)($payload['old_password'] ?? ''), (string)($payload['new_password'] ?? ''));

            return $this->success([], '密码修改成功');
        });
    }

    public function tickets(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            return $this->success(TicketService::merchantData((int)$claims['merchant_id']));
        });
    }

    public function ticketCreate(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');

            return $this->success(
                TicketService::createTicket((int)$claims['merchant_id'], (string)($claims['nickname'] ?? $claims['username'] ?? '商户'), $this->payload($request)),
                '工单已提交'
            );
        });
    }

    public function files(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            return $this->success(FileService::merchantList((int)$claims['merchant_id']));
        });
    }

    public function fileDelete(Request $request)
    {
        return $this->execute(function () use ($request) {
            $claims = $this->requireGuard($request, 'merchant');
            $payload = $this->payload($request);

            return $this->success(FileService::deleteForMerchant((int)$claims['merchant_id'], (int)($payload['id'] ?? 0)), '文件已删除');
        });
    }

    private function requireRealname(array $claims): void
    {
        AuthPolicyService::ensureMerchantRealnameAllowed((int)($claims['merchant_id'] ?? 0), (int)($claims['sub'] ?? 0));
    }
}
