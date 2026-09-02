<?php

namespace App\Payments;

use \Curl\Curl;

class BEasyPaymentUSDT {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'bepusdt_url' => [
                'label' => 'API 地址',
                'description' => '您的 BEPUSDT API 接口地址(例如: https://xxx.com)',
                'type' => 'input',
            ],
            'bepusdt_apitoken' => [
                'label' => 'API Token',
                'description' => '您的 BEPUSDT API Token',
                'type' => 'input',
            ],
            'bepusdt_trade_type' => [
                'label' => '交易类型',
                'description' => '您的 BEPUSDT 交易类型',
                'type' => 'input',
            ],
            'bepusdt_return_domain' => [
                'label' => '自定义跳转域名',
                'description' => '自定义支付成功后的跳转地址，留空使用默认',
                'type' => 'input',
            ],
            'bepusdt_min_amount' => [
                'label' => '最小付款金额（元）',
                'description' => '留空或填 0 表示不限制',
                'type' => 'input',
            ],
            'bepusdt_max_amount' => [
                'label' => '最大付款金额（元）',
                'description' => '留空或填 0 表示不限制',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        $amount = round(((int) $order['total_amount']) / 100, 2);
        $this->checkAmount($amount);

        // 创建return_url的副本用于修改
        $returnUrl = $order['return_url'];
        
        // 仅当配置了自定义域名时才修改跳转地址
        if (!empty($this->config['bepusdt_return_domain'])) {
            $customDomain = rtrim($this->config['bepusdt_return_domain'], '/');
            $parsedUrl = parse_url($returnUrl);
            
            // 构建新URL：自定义域名 + 原路径 + 原参数
            $newUrl = $customDomain;
            if (isset($parsedUrl['path'])) $newUrl .= $parsedUrl['path'];
            if (isset($parsedUrl['query'])) $newUrl .= '?' . $parsedUrl['query'];
            if (isset($parsedUrl['fragment'])) $newUrl .= '#' . $parsedUrl['fragment'];
            
            $returnUrl = $newUrl;
        }

        $params = [
            'amount' => $amount,
            'trade_type' => $this->config['bepusdt_trade_type'],
            'notify_url' => $order['notify_url'],
            'order_id' => $order['trade_no'],
            'redirect_url' => $returnUrl  // 使用可能修改后的跳转地址
        ];
        
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['bepusdt_apitoken'];
        $params['signature'] = md5($str);

        $curl = new Curl();
        $curl->setUserAgent('BEPUSDT');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->setOpt(CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        $curl->post($this->config['bepusdt_url'] . '/api/v1/order/create-transaction', json_encode($params));
        $result = $curl->response;
        $curl->close();

        if (!isset($result->status_code) || $result->status_code != 200) {
            abort(500, "Failed to create order. Error: {$result->message}");
        }

        $paymentURL = $result->data->payment_url;
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $paymentURL
        ];
    }

    public function notify($params)
    {
        // 【安全备份与异常恢复】
        if (is_string($params)) {
            // 1. 先保存一份原始字符串备份
            $originalParams = $params; 

            try {
                $decoded = json_decode($params, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $params = $decoded;
                } else {
                    // 如果没报错但解析结果不是数组，尝试从全局请求恢复
                    $params = request()->all();
                }
            } catch (\Throwable $e) {
                // 2. 异常恢复：发生致命异常时，日志里记录刚才备份的原始数据
                \Log::error('BEPUSDT Notify JSON decode exception: ' . $e->getMessage(), [
                    'original_string' => $originalParams
                ]);
                
                // 3. 尝试通过 Laravel 全局请求抓取数据进行恢复
                $params = request()->all();
            }
        }
        
        $sign = $params['signature'];
        unset($params['signature']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['bepusdt_apitoken'];
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            return('cannot pass verification');
        }
        $status = $params['status'];
        // 1: pending 2: success 3: expired
        if ($status != 2) {
            return('failed');
        }
        return [
            'trade_no' => $params['order_id'],
            'callback_no' => $params['trade_id'],
            'custom_result' => 'ok'
        ];
    }

    private function checkAmount($amount)
    {
        $min = $this->configuredAmount('bepusdt_min_amount');
        $max = $this->configuredAmount('bepusdt_max_amount');
        if ($min > 0 && $max > 0 && $min > $max) {
            abort(500, '支付金额限制配置错误');
        }
        if ($min > 0 && $amount < $min) {
            abort(500, '最小支付金额是' . $this->formatAmount($min) . '元');
        }
        if ($max > 0 && $amount > $max) {
            abort(500, '最大支付金额是' . $this->formatAmount($max) . '元');
        }
    }

    private function configuredAmount($key)
    {
        $value = $this->config[$key] ?? 0;
        if ($value === '' || $value === null) return 0.0;
        if (!is_numeric($value) || (float) $value < 0) {
            abort(500, '支付金额限制配置错误：' . $key);
        }
        return round((float) $value, 2);
    }

    private function formatAmount($value)
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
