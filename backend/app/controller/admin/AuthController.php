<?php

namespace app\controller\admin;

use app\constant\StatusCode;
use app\controller\BaseApiController;
use app\exception\BusinessException;
use app\model\AdminUser;
use app\service\auth\TokenService;
use app\service\system\AccountService;
use app\service\system\AuthPolicyService;
use support\Request;
use Throwable;

class AuthController extends BaseApiController
{
    public function config(Request $request)
    {
        return $this->execute(function () {
            return $this->success(AuthPolicyService::adminConfig());
        });
    }

    public function captcha(Request $request)
    {
        return $this->execute(function () use ($request) {
            $scene = trim((string)$request->get('scene', 'admin_login'));
            $force = in_array(strtolower((string)$request->get('force', '0')), ['1', 'true', 'yes'], true);
            return $this->success(AuthPolicyService::buildCaptchaPayload($scene, $force));
        });
    }

    public function login(Request $request)
    {
        return $this->execute(function () use ($request) {
            $payload = $this->payload($request);
            $username = trim((string)($payload['username'] ?? ''));
            $password = (string)($payload['password'] ?? '');

            if ($username === '' || $password === '') {
                throw new BusinessException('请输入管理员账号和登录密码', StatusCode::VALIDATION_ERROR);
            }

            AuthPolicyService::ensureAdminLoginAllowed($payload);

            if (database_available()) {
                try {
                    $user = AdminUser::where('username', $username)->where('status', 1)->find();
                    if ($user && password_verify($password, (string)$user->password_hash)) {
                        $token = TokenService::issue('admin', (int)$user->id, [
                            'username' => (string)$user->username,
                            'nickname' => (string)$user->nickname,
                        ]);

                        return $this->success([
                            'token' => $token,
                            'user' => $user->hidden(['password_hash'])->toArray(),
                        ], '登录成功');
                    }
                } catch (Throwable) {
                }
            }

            $fallback = AccountService::adminLogin($username, $password);
            $token = TokenService::issue('admin', (int)$fallback['id'], [
                'username' => (string)$fallback['username'],
                'nickname' => (string)$fallback['nickname'],
            ]);

            return $this->success([
                'token' => $token,
                'user' => $fallback,
            ], '登录成功');
        });
    }
}
