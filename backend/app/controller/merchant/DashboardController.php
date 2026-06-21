<?php

namespace app\controller\merchant;

use app\controller\BaseApiController;
use app\service\system\DashboardDataService;
use support\Request;

class DashboardController extends BaseApiController
{
    public function overview(Request $request)
    {
        return $this->execute(function () use ($request) {
            $payload = $this->requireGuard($request, 'merchant');
            $merchantId = (int)$payload['merchant_id'];
            return $this->success(DashboardDataService::merchantOverview($merchantId));
        });
    }
}
