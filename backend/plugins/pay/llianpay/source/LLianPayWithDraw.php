<?php

declare(strict_types=1);

namespace plugins\payment\llianpay;

use think\facade\Db;

/**
 * 记账结算
 * @see https://api-doc.lianlianpay.com/openplatform/api-3477861
 */
class LLianPayWithDraw
{
    private array $channel;
    private LLianPayClient $client;

    public function __construct(array $channel)
    {
        $this->channel = $channel;
        $this->client = new LLianPayClient($channel['appid'], $channel['appkey']);
    }

    //记账结算申请
    public function withdrawal(array $data): array
    {
        $payerInfo = [
            'payer_id' => $data['user_id'],
            'payer_type' => 'USER',
            'payer_accttype' => 'USEROWN',
        ];
        if (!empty($data['pap_agree_no'])) {
            $payerInfo['pap_agree_no'] = $this->client->rsaPublicEncrypt($data['pap_agree_no']);
        } elseif (!empty($data['random_key'])) {
            if (empty($data['password'])) return ['code' => -1, 'msg' => '请输入支付密码！'];
            $payerInfo['random_key'] = $data['random_key'];
            $payerInfo['password'] = $data['password'];
        }
        $params = [
            'mch_id' => $this->channel['appid'],
            'txn_seqno' => $data['order_no'],
            'txn_time' => date('YmdHis'),
            'order_amount' => $data['money'],
            'notify_url' => config_get('localurl') . 'pay/drawnotify/' . $this->channel['id'] . '/',
            'risk_item' => json_encode([
                'frms_ware_category' => '1004',
                'frms_ip_addr' => request()->clientip,
                'user_info_mercht_userno' => $data['user_id'],
                'user_info_dt_register' => '20240801210500',
                'goods_name' => '提现',
            ]),
            'linked_acctno' => $data['card_no'],
            'payer_info' => $payerInfo,
            'payee_info' => [
                'linked_acctno' => $data['card_no'],
                'postscript' => $data['desc'],
            ],
        ];
        try {
            $result = $this->client->sendRequest('/mch/v1/accp/txn/withdrawal', $params);
            if ($result['ret_code'] == '0000') {

                $this->insert_withdraw($data['order_no'], $data['user_id'], $data['money'], $result['platform_txno']);

                return ['code' => 0, 'msg' => '提现申请成功！', 'order_no' => $data['order_no']];
            } elseif ($result['ret_code'] == '8889') {
                return ['code' => -1, 'msg' => '提现申请待确认成功', 'order_no' => $data['order_no']];
            } elseif ($result['ret_code'] == '8888') {
                return ['code' => -2, 'msg' => '请输入短信验证码！', 'order_no' => $data['order_no'], 'token' => $result['token']];
            }
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => '提现申请失败，' . $e->getMessage()];
        }
        return ['code' => -1, 'msg' => '未知错误'];
    }

    //交易二次短信验证
    public function validate_sms(string $order_no, string $user_id, $money, string $token, string $verify_code): array
    {
        $params = [
            'mch_id' => $this->channel['appid'],
            'txn_seqno' => $order_no,
            'token' => $token,
            'verify_code' => $verify_code,
        ];
        try {
            $result = $this->client->sendRequest('/mch/v1/accp/txn/validation-sms', $params);

            $this->insert_withdraw($order_no, $user_id, $money, $result['platform_txno']);

            return ['code' => 0, 'msg' => '验证成功！'];
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => '验证失败，' . $e->getMessage()];
        }
    }

    private function insert_withdraw(string $order_no, string $user_id, $money, string $accp_no): void
    {
        $data = [
            'channel' => $this->channel['id'],
            'user_id' => $user_id,
            'order_no' => $order_no,
            'accp_no' => $accp_no,
            'money' => $money,
            'addtime' => date('Y-m-d H:i:s'),
            'status' => 0,
        ];
        Db::name('llianpay_withdraw')->insert($data);
    }

    //提现结果查询
    public function queryWithDrawal(array $row): array
    {
        $params = [
            'mch_id' => $this->channel['appid'],
            'platform_txno' => $row['accp_no'],
        ];
        try {
            $result = $this->client->sendRequest('/query/mch/v1/accp/txn/withdrawal-query', $params);
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => '提现结果查询失败，' . $e->getMessage()];
        }

        if ($result['txn_status'] == 'SUCCESS') {
            Db::name('llianpay_withdraw')->where('id', $row['id'])->update(['status' => 1, 'endtime' => date('Y-m-d H:i:s')]);
            return ['code' => 0, 'msg' => '提现成功！'];
        } elseif ($result['txn_status'] == 'FAILURE' || $result['txn_status'] == 'CANCEL') {
            Db::name('llianpay_withdraw')->where('id', $row['id'])->update(['status' => 2, 'reason' => $result['fail_reason'], 'endtime' => date('Y-m-d H:i:s')]);
            $msg = $result['txn_status'] == 'CANCEL' ? '提现已退回！' : '提现失败！';
            $msg .= $result['fail_reason'] ? '原因：' . $result['fail_reason'] : '';
            return ['code' => 0, 'msg' => $msg];
        } else {
            return ['code' => -1, 'msg' => '提现处理中，当前状态：' . $result['txn_status']];
        }
    }

    //账户信息查询
    public function queryAcctInfo(string $user_id): array
    {
        $params = [
            'mch_id' => $this->channel['appid'],
            'user_id' => $user_id,
            'user_type' => 'INNERUSER',
        ];
        try {
            $result = $this->client->sendRequest('/mch/v1/accp/customer/query-acctinfo', $params);
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => '账户信息查询失败，' . $e->getMessage()];
        }
        $acct = array_filter($result['acct_list'], function ($v) {
            return $v['acct_type'] == 'USEROWN_AVAILABLE';
        });
        if (empty($acct)) return ['code' => -1, 'msg' => '未查询到用户自有可用账户'];
        return ['code' => 0, 'amount' => $acct[array_key_first($acct)]['amt_balaval']];
    }

    //绑卡信息查询
    public function queryBankInfo(string $user_id): array
    {
        $params = [
            'mch_id' => $this->channel['appid'],
            'user_id' => $user_id,
        ];
        try {
            $result = $this->client->sendRequest('/mch/v1/accp/customer/query-linkedacct', $params);
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => '绑卡信息查询失败，' . $e->getMessage()];
        }
        return ['code' => 0, 'list' => $result['linked_acct_list']];
    }

    //随机密码因子获取
    public function getRandom(string $user_id): array
    {
        $params = [
            'mch_id' => $this->channel['appid'],
            'user_id' => $user_id,
            'flag_chnl' => 'PCH5',
            'pkg_name' => request()->host(),
            'app_name' => request()->host(),
            'encrypt_algorithm' => 'SM2',
        ];
        try {
            $result = $this->client->sendRequest('/mch/v1/accp/acctmgr/get-random', $params);
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => '随机密码因子获取失败，' . $e->getMessage()];
        }
        return ['code' => 0, 'data' => $result];
    }

    //申请密码控件TOKEN
    public function getPwdToken(string $user_id, $amount, string $name, string $order_no): array
    {
        $params = [
            'mch_id' => $this->channel['appid'],
            'user_id' => $user_id,
            'flag_chnl' => 'PCH5',
            'password_scene' => 'cashout_password',
            'txn_seqno' => $order_no,
            'amount' => $amount,
            'encrypt_algorithm' => 'SM2',
            'payee_name' => $name,
        ];
        try {
            $result = $this->client->sendRequest('/mch/v1/accp/acctmgr/get-apply-password-element', $params);
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => '密码控件TOKEN获取失败，' . $e->getMessage()];
        }
        return ['code' => 0, 'data' => ['password_scene' => $params['password_scene'], 'password_element_token' => $result['password_element_token'], 'mch_id' => $this->channel['appid'], 'user_id' => $user_id]];
    }

    //账户管理H5页面
    public function customer(string $user_id): array
    {
        $siteurl = request()->siteurl;
        $params = [
            'mch_id' => $this->channel['appid'],
            'user_id' => $user_id,
            'txn_time' => date('YmdHis'),
            'txn_seqno' => date('YmdHis') . rand(100000, 999999),
            'return_url' => $siteurl,
            'support_functions' => [
                'ACCOUNT' => ['MODIFY_REG_PHONE' => 'Y', 'MODIFY_PWD' => 'Y', 'PASSWORD_RECOVER' => 'Y'],
                'BALANCE' => ['WITHDRAWAL' => 'Y', 'ACCOUNT_SERIAL' => 'Y'],
                'BANK_CARD' => ['MODIFY_LINKED_PHONE' => 'Y', 'BIND_CHANGE_CARD' => 'Y', 'UNBIND_CARD' => 'Y'],
            ],
        ];
        try {
            $result = $this->client->sendRequest('/mch/v1/accp/customer/h5-acct-apply', $params);
        } catch (\Exception $e) {
            return ['code' => -1, 'msg' => '账户管理页面获取失败，' . $e->getMessage()];
        }
        return ['code' => 0, 'data' => $result['gateway_url']];
    }

    //提现回调通知
    public function notify(array $data): void
    {
        $order_no = $data['txn_seqno'];
        $row = Db::name('llianpay_withdraw')->where('order_no', $order_no)->find();
        if (!$row) return;

        if ($data['txn_status'] == 'SUCCESS') {
            Db::name('llianpay_withdraw')->where('id', $row['id'])->update(['status' => 1, 'endtime' => date('Y-m-d H:i:s')]);
        } elseif ($data['txn_status'] == 'FAILURE' || $data['txn_status'] == 'CANCEL') {
            Db::name('llianpay_withdraw')->where('id', $row['id'])->update(['status' => 2, 'reason' => $data['fail_reason'], 'endtime' => date('Y-m-d H:i:s')]);
        }
    }
}
