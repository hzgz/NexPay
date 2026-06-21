<?php

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\service\system\DashboardDataService;
use support\Request;

class DashboardController extends BaseApiController
{
    public function overview(Request $request)
    {
        return $this->execute(function () use ($request) {
            $this->requireGuard($request, 'admin');
            return $this->success(DashboardDataService::adminOverview());
        });
    }
}
