<?php

namespace app\controller\api;

use app\service\payment\GatewayCompatService;
use support\Request;
use support\Response;
use Throwable;

class EpayV2Controller
{
    public function submit(Request $request)
    {
        try {
            $result = GatewayCompatService::createForV2(array_merge($request->get(), $request->post()));
            return redirect($result['pay_info']);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function create(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::createForV2(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function query(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::queryForV2(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function refund(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::refundForV2(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function refundQuery(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::refundQueryForV2(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function close(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::closeForV2(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function merchantInfo(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::merchantInfoForV2(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function merchantOrders(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::merchantOrdersForV2(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function transferSubmit(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::transferSubmitForV2(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function transferQuery(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::transferQueryForV2(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function transferBalance(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::transferBalanceForV2(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    private function errorResponse(Throwable $exception): Response
    {
        return $this->jsonResponse(GatewayCompatService::errorResponseForV2($exception));
    }

    private function jsonResponse(array $payload): Response
    {
        return json($payload)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
