<?php

namespace app\controller\home;

use app\controller\BaseApiController;
use app\service\home\DemoCheckoutService;
use app\service\home\HomeService;
use app\service\system\ConfigService;
use support\Request;

class HomeController extends BaseApiController
{
    public function index()
    {
        return $this->success([
            'app' => ConfigService::get('app_name', 'NexPay 聚合支付系统'),
            'message' => 'NexPay 后端服务运行中。',
            'docs' => [
                'v1_submit' => '/submit.php',
                'v1_api' => '/mapi.php',
                'v2_create' => '/api/pay/create',
            ],
        ]);
    }

    public function health()
    {
        return $this->success(['status' => 'up']);
    }

    public function statistics()
    {
        return $this->success(HomeService::statistics());
    }

    public function announcements(Request $request)
    {
        $limit = max(1, min(20, (int)$request->get('limit', 10)));
        return $this->success([
            'items' => HomeService::announcements($limit),
        ]);
    }

    public function demoConfig()
    {
        return $this->success(DemoCheckoutService::config());
    }

    public function demoCreate(Request $request)
    {
        return $this->execute(function () use ($request) {
            return $this->success(DemoCheckoutService::create($this->payload($request)));
        });
    }

    public function demoStatus(Request $request, string $tradeNo)
    {
        return $this->execute(function () use ($tradeNo) {
            return $this->success(DemoCheckoutService::status($tradeNo));
        });
    }
}
