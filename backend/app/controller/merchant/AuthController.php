<?php

namespace app\controller\merchant;

use app\constant\StatusCode;
use app\controller\BaseApiController;
use app\exception\BusinessException;
use app\service\auth\TokenService;
use app\service\system\AuthPolicyService;
use app\service\system\MerchantAuthService;
use app\service\system\OAuthRuntimeService;
use support\Request;
use support\Response;
use Throwable;

class AuthController extends BaseApiController
{
    public function config(Request $request)
    {
        return $this->execute(function () {
            return $this->success(MerchantAuthService::authSettings());
        });
    }

    public function captcha(Request $request)
    {
        return $this->execute(function () use ($request) {
            $scene = trim((string)$request->get('scene', 'merchant_login'));
            $force = in_array(strtolower((string)$request->get('force', '0')), ['1', 'true', 'yes'], true);
            return $this->success(AuthPolicyService::buildCaptchaPayload($scene, $force));
        });
    }

    public function login(Request $request)
    {
        return $this->execute(function () use ($request) {
            $payload = $this->payload($request);
            $user = MerchantAuthService::login($payload, (string)$request->getRealIp());

            $token = TokenService::issue('merchant', (int)$user['id'], [
                'merchant_id' => (int)$user['merchant_id'],
                'username' => (string)$user['username'],
                'nickname' => (string)$user['nickname'],
            ]);

            return $this->success([
                'token' => $token,
                'user' => $user,
            ], '登录成功');
        });
    }

    public function oauthStart(Request $request): Response
    {
        return $this->execute(function () use ($request) {
            $payload = $this->payload($request);
            $mode = trim((string)($payload['mode'] ?? $request->get('mode', 'login')));
            $channel = trim((string)($payload['channel'] ?? $request->get('channel', '')));
            $merchantUserId = 0;
            $merchantId = 0;

            if ($mode === 'bind') {
                $claims = $this->requireGuard($request, 'merchant');
                $merchantUserId = (int)($claims['sub'] ?? 0);
                $merchantId = (int)($claims['merchant_id'] ?? 0);
            }

            return $this->success(
                OAuthRuntimeService::start($channel, $mode, $merchantUserId, $merchantId),
                '授权地址已生成'
            );
        });
    }

    public function oauthCallback(Request $request): Response
    {
        try {
            $result = OAuthRuntimeService::callback($request->all());
            $mode = (string)($result['mode'] ?? 'login');

            if ($mode === 'bind') {
                return redirect($this->oauthResultUrl([
                    'code' => StatusCode::SUCCESS,
                    'mode' => 'bind',
                    'message' => '第三方账号绑定成功',
                    'channel' => (string)($result['channel'] ?? ''),
                ]));
            }

            $user = is_array($result['user'] ?? null) ? $result['user'] : [];
            if ($user === []) {
                throw new BusinessException('聚合登录未返回商户账号信息', StatusCode::BUSINESS_ERROR);
            }

            $token = $this->issueMerchantToken($user);

            return redirect($this->oauthResultUrl([
                'code' => StatusCode::SUCCESS,
                'mode' => 'login',
                'message' => '聚合登录成功',
                'token' => $token,
                'user' => $this->base64UrlEncode((string)json_encode($user, JSON_UNESCAPED_UNICODE)),
            ]));
        } catch (BusinessException $exception) {
            return redirect($this->oauthResultUrl([
                'code' => $exception->errorCode(),
                'mode' => (string)$request->get('mode', 'login'),
                'message' => $exception->getMessage(),
            ]));
        } catch (Throwable $exception) {
            return redirect($this->oauthResultUrl([
                'code' => StatusCode::BUSINESS_ERROR,
                'mode' => (string)$request->get('mode', 'login'),
                'message' => $exception->getMessage(),
            ]));
        }
    }

    public function register(Request $request)
    {
        return $this->execute(function () use ($request) {
            $user = MerchantAuthService::register($this->payload($request), (string)$request->getRealIp());
            if (!empty($user['payment_required'])) {
                return $this->success([
                    'token' => '',
                    'user' => $user,
                    'payment_required' => true,
                    'payment_order' => $user['payment_order'] ?? [],
                ], '注册成功，请支付注册费');
            }

            if ((int)($user['status'] ?? 0) !== 1) {
                return $this->success([
                    'token' => '',
                    'user' => $user,
                    'audit_required' => true,
                ], '注册成功，请等待管理员审核');
            }

            $token = TokenService::issue('merchant', (int)$user['id'], [
                'merchant_id' => (int)$user['merchant_id'],
                'username' => (string)$user['username'],
                'nickname' => (string)$user['nickname'],
            ]);

            return $this->success([
                'token' => $token,
                'user' => $user,
            ], '注册成功');
        });
    }

    public function forgotPassword(Request $request)
    {
        return $this->execute(function () use ($request) {
            MerchantAuthService::resetPassword($this->payload($request));
            return $this->success([], '密码已重置');
        });
    }

    public function forgotCode(Request $request)
    {
        return $this->execute(function () use ($request) {
            return $this->success(MerchantAuthService::sendForgotCode($this->payload($request)), '验证码已发送');
        });
    }

    private function issueMerchantToken(array $user): string
    {
        return TokenService::issue('merchant', (int)$user['id'], [
            'merchant_id' => (int)$user['merchant_id'],
            'username' => (string)$user['username'],
            'nickname' => (string)$user['nickname'],
        ]);
    }

    private function oauthResultUrl(array $query): string
    {
        return '/user/oauth-result?' . http_build_query($query);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
