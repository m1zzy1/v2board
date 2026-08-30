<?php

namespace App\Payments;

class EPay {
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => '支付类型，如: alipay, wxpay, qqpay',
                'type' => 'input',
            ],
            'return_domain' => [
                'label' => '自定义跳转域名',
                'description' => '支付完成后用户跳转的域名（如：https://yourdomain.com），留空使用默认域名',
                'type' => 'input',
            ],
            'min_amount' => ['label' => '最小付款金额（元）', 'description' => '留空或0表示不限制', 'type' => 'input'],
            'max_amount' => ['label' => '最大付款金额（元）', 'description' => '留空或0表示不限制', 'type' => 'input'],
        ];
    }

    public function pay($order)
    {
        $money = round(((int) $order['total_amount']) / 100, 2);
        $this->checkAmount($money);

        // 仅修改支付完成后的跳转地址，回调地址保持不变。
        $returnUrl = $order['return_url'];
        if (!empty($this->config['return_domain'])) {
            $parsedUrl = parse_url($returnUrl);
            $returnUrl = rtrim((string) $this->config['return_domain'], '/')
                . ($parsedUrl['path'] ?? '')
                . (isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '')
                . (isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '');
        }

        $params = [
            'money' => number_format($money, 2, '.', ''),
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $returnUrl,
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];
        if (!empty($this->config['type'])) {
            $params['type'] = $this->config['type'];
        }
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => rtrim((string) $this->config['url'], '/') . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        $sign = $params['sign'] ?? '';
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            return false;
        }

        // 强制要求交易状态为成功，避免未支付/处理中状态被误入账
        $tradeStatus = $params['trade_status'] ?? '';
        if ($tradeStatus !== 'TRADE_SUCCESS') {
            return('fail');
        }

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }

    private function checkAmount($amount)
    {
        $min = $this->amount('min_amount');
        $max = $this->amount('max_amount');
        if ($min > 0 && $max > 0 && $min > $max) abort(500, '支付金额限制配置错误');
        if ($min > 0 && $amount < $min) abort(500, '最小支付金额是' . $this->formatAmount($min) . '元');
        if ($max > 0 && $amount > $max) abort(500, '最大支付金额是' . $this->formatAmount($max) . '元');
    }

    private function amount($key)
    {
        $value = $this->config[$key] ?? 0;
        if ($value === '' || $value === null) return 0.0;
        if (!is_numeric($value) || (float) $value < 0) abort(500, '支付金额限制配置错误：' . $key);
        return round((float) $value, 2);
    }

    private function formatAmount($value)
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
