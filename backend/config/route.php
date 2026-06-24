<?php

use app\controller\admin\AuthController as AdminAuthController;
use app\controller\admin\DashboardController as AdminDashboardController;
use app\controller\admin\ResourceController as AdminResourceController;
use app\controller\StaticAppController;
use app\controller\api\AlipayBridgeController;
use app\controller\api\CheckoutController;
use app\controller\api\EpayV1Controller;
use app\controller\api\EpayV2Controller;
use app\controller\api\LegacyPayController;
use app\controller\api\SoftwareCompatController;
use app\controller\home\HomeController;
use app\controller\merchant\AuthController as MerchantAuthController;
use app\controller\merchant\DashboardController as MerchantDashboardController;
use app\controller\merchant\ResourceController as MerchantResourceController;
use app\service\system\PluginRuntimeService;
use app\service\system\RuntimeToggleService;
use Webman\Route;

Route::disableDefaultRoute();

Route::get('/', [StaticAppController::class, 'home']);
Route::any('/doc', [StaticAppController::class, 'doc']);
Route::any('/demo', [StaticAppController::class, 'demo']);
Route::any('/user/login', [StaticAppController::class, 'user']);
Route::any('/user/register', [StaticAppController::class, 'user']);
Route::any('/user/forgot-password', [StaticAppController::class, 'user']);
Route::any('/admin/login', [StaticAppController::class, 'admin']);
Route::any('/admin', [StaticAppController::class, 'admin']);
Route::any('/admin/{path:.+}', [StaticAppController::class, 'admin']);
Route::any('/user', [StaticAppController::class, 'user']);
Route::any('/user/{path:.+}', [StaticAppController::class, 'user']);
Route::get('/healthz', [HomeController::class, 'health']);

Route::group('/api/home', static function (): void {
    Route::get('/statistics', [HomeController::class, 'statistics']);
    Route::get('/announcements', [HomeController::class, 'announcements']);
    Route::get('/demo-config', [HomeController::class, 'demoConfig']);
    Route::post('/demo-create', [HomeController::class, 'demoCreate']);
    Route::get('/demo-status/:tradeNo', [HomeController::class, 'demoStatus']);
});

Route::group('/api/admin', static function (): void {
    Route::get('/auth/config', [AdminAuthController::class, 'config']);
    Route::get('/auth/captcha', [AdminAuthController::class, 'captcha']);
    Route::post('/auth/login', [AdminAuthController::class, 'login']);
    Route::get('/dashboard/overview', [AdminDashboardController::class, 'overview']);
    Route::get('/merchants', [AdminResourceController::class, 'merchants']);
    Route::post('/merchants/create', [AdminResourceController::class, 'merchantCreate']);
    Route::post('/merchants/review', [AdminResourceController::class, 'merchantReview']);
    Route::post('/merchants/realname/review', [AdminResourceController::class, 'merchantRealnameReview']);
    Route::post('/merchants/groups/save', [AdminResourceController::class, 'merchantGroupSave']);
    Route::post('/merchants/groups/delete', [AdminResourceController::class, 'merchantGroupDelete']);
    Route::get('/orders', [AdminResourceController::class, 'orders']);
    Route::post('/orders/manual-confirm', [AdminResourceController::class, 'orderManualConfirm']);
    Route::post('/orders/callback-retry', [AdminResourceController::class, 'orderCallbackRetry']);
    Route::post('/orders/delete', [AdminResourceController::class, 'orderDelete']);
    Route::post('/refunds/manual-confirm', [AdminResourceController::class, 'refundManualConfirm']);
    Route::post('/refunds/sync', [AdminResourceController::class, 'refundSync']);
    Route::post('/refunds/sync-batch', [AdminResourceController::class, 'refundSyncBatch']);
    Route::post('/transfers/review', [AdminResourceController::class, 'transferReview']);
    Route::post('/transfers/sync', [AdminResourceController::class, 'transferSync']);
    Route::post('/transfers/sync-batch', [AdminResourceController::class, 'transferSyncBatch']);
    Route::post('/settlements/review', [AdminResourceController::class, 'settlementReview']);
    Route::get('/fees', [AdminResourceController::class, 'fees']);
    Route::get('/packages', [AdminResourceController::class, 'packages']);
    Route::post('/packages/save', [AdminResourceController::class, 'packageSave']);
    Route::get('/settings', [AdminResourceController::class, 'settings']);
    Route::post('/settings/save', [AdminResourceController::class, 'settingsSave']);
    Route::post('/settings/cache-clear', [AdminResourceController::class, 'settingsCacheClear']);
    Route::post('/settings/cleanup', [AdminResourceController::class, 'settingsCleanup']);
    Route::post('/settings/provider-test', [AdminResourceController::class, 'providerTest']);
    Route::get('/tickets', [AdminResourceController::class, 'tickets']);
    Route::get('/tickets/detail', [AdminResourceController::class, 'ticketDetail']);
    Route::post('/tickets/create', [AdminResourceController::class, 'ticketCreate']);
    Route::post('/tickets/update', [AdminResourceController::class, 'ticketUpdate']);
    Route::post('/tickets/reply', [AdminResourceController::class, 'ticketReply']);
    Route::post('/tickets/category/save', [AdminResourceController::class, 'ticketCategorySave']);
    Route::post('/tickets/category/delete', [AdminResourceController::class, 'ticketCategoryDelete']);
    Route::get('/files', [AdminResourceController::class, 'files']);
    Route::post('/files/delete', [AdminResourceController::class, 'fileDelete']);
    Route::get('/plugins', [AdminResourceController::class, 'plugins']);
    Route::post('/plugins/scan', [AdminResourceController::class, 'pluginScan']);
    Route::post('/plugins/toggle', [AdminResourceController::class, 'pluginToggle']);
    Route::post('/plugins/delete', [AdminResourceController::class, 'pluginDelete']);
    Route::post('/plugins/save', [AdminResourceController::class, 'pluginSave']);
    Route::post('/plugins/method/save', [AdminResourceController::class, 'paymentMethodSave']);
    Route::post('/plugins/method/toggle', [AdminResourceController::class, 'paymentMethodToggle']);
    Route::post('/plugins/method/delete', [AdminResourceController::class, 'paymentMethodDelete']);
    Route::post('/announcements/save', [AdminResourceController::class, 'announcementSave']);
    Route::post('/announcements/toggle', [AdminResourceController::class, 'announcementToggle']);
    Route::post('/announcements/delete', [AdminResourceController::class, 'announcementDelete']);
    Route::get('/tasks', [AdminResourceController::class, 'tasks']);
    Route::post('/tasks/run', [AdminResourceController::class, 'taskRun']);
    Route::post('/tasks/save', [AdminResourceController::class, 'taskSave']);
    Route::get('/tasks/logs', [AdminResourceController::class, 'taskLogs']);
    Route::get('/logs', [AdminResourceController::class, 'logs']);
    Route::post('/callbacks/retry', [AdminResourceController::class, 'callbackRetry']);
    Route::get('/profile', [AdminResourceController::class, 'profile']);
    Route::post('/profile/save', [AdminResourceController::class, 'profileSave']);
    Route::post('/profile/avatar/upload', [AdminResourceController::class, 'profileAvatarUpload']);
    Route::post('/profile/password', [AdminResourceController::class, 'passwordSave']);
});

Route::group('/api/merchant', static function (): void {
    Route::post('/info', [EpayV2Controller::class, 'merchantInfo']);
    Route::post('/orders', [EpayV2Controller::class, 'merchantOrders']);
    Route::get('/auth/config', [MerchantAuthController::class, 'config']);
    Route::get('/auth/captcha', [MerchantAuthController::class, 'captcha']);
    Route::post('/auth/login', [MerchantAuthController::class, 'login']);
    Route::any('/auth/oauth/start', [MerchantAuthController::class, 'oauthStart']);
    Route::any('/auth/oauth/callback', [MerchantAuthController::class, 'oauthCallback']);
    Route::post('/auth/register', [MerchantAuthController::class, 'register']);
    Route::post('/auth/forgot-code', [MerchantAuthController::class, 'forgotCode']);
    Route::post('/auth/forgot-password', [MerchantAuthController::class, 'forgotPassword']);
    Route::get('/dashboard/overview', [MerchantDashboardController::class, 'overview']);
    Route::get('/channels', [MerchantResourceController::class, 'channels']);
    Route::post('/channels/save', [MerchantResourceController::class, 'channelSave']);
    Route::post('/channels/toggle', [MerchantResourceController::class, 'channelToggle']);
    Route::post('/channels/delete', [MerchantResourceController::class, 'channelDelete']);
    Route::post('/channels/test', [MerchantResourceController::class, 'channelTest']);
    Route::post('/channels/config/upload', [MerchantResourceController::class, 'channelConfigUpload']);
    Route::post('/channels/alipay-ck/qrcode', [MerchantResourceController::class, 'channelAlipayCkQrcode']);
    Route::post('/channels/alipay-ck/status', [MerchantResourceController::class, 'channelAlipayCkStatus']);
    Route::post('/channels/rotation/save', [MerchantResourceController::class, 'channelRotationSave']);
    Route::post('/channels/payment-settings/save', [MerchantResourceController::class, 'channelPaymentSettingsSave']);
    Route::get('/orders', [MerchantResourceController::class, 'orders']);
    Route::post('/orders/callback-retry', [MerchantResourceController::class, 'orderCallbackRetry']);
    Route::post('/orders/delete', [MerchantResourceController::class, 'orderDelete']);
    Route::get('/funds', [MerchantResourceController::class, 'funds']);
    Route::post('/funds/recharge', [MerchantResourceController::class, 'fundRecharge']);
    Route::post('/funds/withdraw', [MerchantResourceController::class, 'fundWithdraw']);
    Route::get('/packages', [MerchantResourceController::class, 'packages']);
    Route::post('/packages/buy', [MerchantResourceController::class, 'packageBuy']);
    Route::get('/api-info', [MerchantResourceController::class, 'apiInfo']);
    Route::post('/api-info/md5/reset', [MerchantResourceController::class, 'apiInfoResetMd5']);
    Route::post('/api-info/rsa/generate', [MerchantResourceController::class, 'apiInfoGenerateRsa']);
    Route::post('/api-info/sign-mode/save', [MerchantResourceController::class, 'apiInfoSaveSignMode']);
    Route::get('/telegram', [MerchantResourceController::class, 'telegram']);
    Route::get('/profile', [MerchantResourceController::class, 'profile']);
    Route::post('/profile/save', [MerchantResourceController::class, 'profileSave']);
    Route::post('/profile/avatar/upload', [MerchantResourceController::class, 'profileAvatarUpload']);
    Route::post('/profile/password', [MerchantResourceController::class, 'passwordSave']);
    Route::get('/tickets', [MerchantResourceController::class, 'tickets']);
    Route::get('/tickets/detail', [MerchantResourceController::class, 'ticketDetail']);
    Route::post('/tickets/create', [MerchantResourceController::class, 'ticketCreate']);
    Route::post('/tickets/reply', [MerchantResourceController::class, 'ticketReply']);
    Route::get('/files', [MerchantResourceController::class, 'files']);
    Route::post('/files/delete', [MerchantResourceController::class, 'fileDelete']);
});

Route::any('/submit.php', [EpayV1Controller::class, 'submit']);
Route::any('/submit2.php', [EpayV1Controller::class, 'submit2']);
Route::post('/mapi.php', [EpayV1Controller::class, 'create']);
Route::any('/api.php', [EpayV1Controller::class, 'query']);

Route::group('/api/pay', static function (): void {
    Route::post('/submit', [EpayV2Controller::class, 'submit']);
    Route::post('/create', [EpayV2Controller::class, 'create']);
    Route::post('/query', [EpayV2Controller::class, 'query']);
    Route::post('/refund', [EpayV2Controller::class, 'refund']);
    Route::post('/refundquery', [EpayV2Controller::class, 'refundQuery']);
    Route::post('/close', [EpayV2Controller::class, 'close']);
});

Route::group('/api/transfer', static function (): void {
    Route::post('/submit', [EpayV2Controller::class, 'transferSubmit']);
    Route::post('/query', [EpayV2Controller::class, 'transferQuery']);
    Route::post('/balance', [EpayV2Controller::class, 'transferBalance']);
});

Route::any('/api/Software/verify', [SoftwareCompatController::class, 'verify']);
Route::any('/api/Software/heartbeat', [SoftwareCompatController::class, 'heartbeat']);
Route::any('/api/Software/checkOrder', [SoftwareCompatController::class, 'checkOrder']);
Route::any('/api/Software/PCNotify', [SoftwareCompatController::class, 'pcNotify']);
Route::any('/api/report', [SoftwareCompatController::class, 'report']);
Route::any('/api/report/', [SoftwareCompatController::class, 'report']);
Route::any('/api/report/{merchantId}', [SoftwareCompatController::class, 'report']);
Route::any('/api/report/{merchantId}/', [SoftwareCompatController::class, 'report']);
Route::get('/alipay/bridge', [AlipayBridgeController::class, 'show']);

Route::get('/pay/checkout/{trade_no}', [CheckoutController::class, 'show']);
Route::get('/pay/checkout/{trade_no}/', [CheckoutController::class, 'show']);
Route::get('/pay/status/{trade_no}', [CheckoutController::class, 'status']);
Route::get('/pay/status/{trade_no}/', [CheckoutController::class, 'status']);
Route::get('/pay/qr-image/{trade_no}', [CheckoutController::class, 'qrImage']);
Route::get('/pay/qr-image/{trade_no}/', [CheckoutController::class, 'qrImage']);
Route::post('/pay/mock/{trade_no}', [CheckoutController::class, 'mockComplete']);
Route::post('/pay/mock/{trade_no}/', [CheckoutController::class, 'mockComplete']);
Route::any('/pay/submit/{trade_no}', [LegacyPayController::class, 'submit']);
Route::any('/pay/qrcode/{trade_no}', [LegacyPayController::class, 'qrcode']);
Route::any('/pay/submitwap/{trade_no}', [LegacyPayController::class, 'submitWap']);
Route::any('/pay/jspay/{trade_no}', [LegacyPayController::class, 'jsPay']);
Route::any('/pay/pay/{trade_no}', [LegacyPayController::class, 'pay']);
Route::any('/pay/alipay/{trade_no}', [LegacyPayController::class, 'alipay']);
Route::any('/pay/wxpay/{trade_no}', [LegacyPayController::class, 'wxpay']);
Route::any('/pay/qqpay/{trade_no}', [LegacyPayController::class, 'qqpay']);
Route::any('/pay/bank/{trade_no}', [LegacyPayController::class, 'bank']);
Route::any('/pay/jdpay/{trade_no}', [LegacyPayController::class, 'jdpay']);
Route::any('/pay/douyinpay/{trade_no}', [LegacyPayController::class, 'douyinpay']);
Route::any('/pay/notify/{trade_no}', [LegacyPayController::class, 'notify']);
Route::any('/pay/refundnotify/{trade_no}', [LegacyPayController::class, 'refundNotify']);
Route::any('/pay/transfernotify/{trade_no}', [LegacyPayController::class, 'transferNotify']);
Route::any('/pay/preauthnotify/{trade_no}', [LegacyPayController::class, 'preauthNotify']);
Route::any('/pay/complainnotify/{trade_no}', [LegacyPayController::class, 'complainNotify']);
Route::any('/pay/dividenotify/{trade_no}', [LegacyPayController::class, 'divideNotify']);
Route::any('/pay/cashiernotify/{trade_no}', [LegacyPayController::class, 'cashierNotify']);
Route::any('/pay/return/{trade_no}', [LegacyPayController::class, 'legacyReturn']);
Route::any('/pay/ok/{trade_no}', [LegacyPayController::class, 'ok']);
Route::any('/pay/{action}/{trade_no}/', [LegacyPayController::class, 'dispatch']);
Route::any('/pay/{action}/{trade_no}', [LegacyPayController::class, 'dispatch']);
Route::get('/callback/success', static function () {
    return response('success', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
});

if (RuntimeToggleService::pluginRuntimeEnabled()) {
    PluginRuntimeService::loadRoutes();
}
