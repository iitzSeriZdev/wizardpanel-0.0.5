<?php

/**
 * مدیریت درگاه‌های پرداخت
 */
class PaymentGateway
{
    private static ?PaymentGateway $instance = null;
    private array $gateways = [];
    private $logger = null;

    private function __construct()
    {
        if (file_exists(__DIR__ . '/Logger.php') && class_exists('Logger')) {
            try {
                $this->logger = Logger::getInstance();
            } catch (Exception $e) {
                // Logger در دسترس نیست
                $this->logger = null;
            }
        }
        $this->loadGateways();
    }

    public static function getInstance(): PaymentGateway
    {
        if (self::$instance === null) {
            self::$instance = new PaymentGateway();
        }
        return self::$instance;
    }

    /**
     * بارگذاری تنظیمات درگاه‌ها
     */
    private function loadGateways(): void
    {
        $settings = getSettings();
        
        // زرین‌پال
        if (!empty($settings['zarinpal_merchant_id'])) {
            $this->gateways['zarinpal'] = [
                'enabled' => ($settings['payment_gateway_status'] ?? 'off') === 'on',
                'merchant_id' => $settings['zarinpal_merchant_id'],
                'sandbox' => ($settings['zarinpal_sandbox'] ?? 'off') === 'on'
            ];
        }
        
        // آی‌دی‌پی
        if (!empty($settings['idpay_api_key'])) {
            $this->gateways['idpay'] = [
                'enabled' => ($settings['idpay_enabled'] ?? 'off') === 'on',
                'api_key' => $settings['idpay_api_key'],
                'sandbox' => ($settings['idpay_sandbox'] ?? 'off') === 'on'
            ];
        }
        
        // نکست‌پی
        if (!empty($settings['nextpay_api_key'])) {
            $this->gateways['nextpay'] = [
                'enabled' => ($settings['nextpay_enabled'] ?? 'off') === 'on',
                'api_key' => $settings['nextpay_api_key'],
                'sandbox' => ($settings['nextpay_sandbox'] ?? 'off') === 'on'
            ];
        }
        
        // زیبال
        if (!empty($settings['zibal_merchant_id'])) {
            $this->gateways['zibal'] = [
                'enabled' => ($settings['zibal_enabled'] ?? 'off') === 'on',
                'merchant_id' => $settings['zibal_merchant_id'],
                'sandbox' => ($settings['zibal_sandbox'] ?? 'off') === 'on'
            ];
        }
        
        // newPayment
        if (!empty($settings['newpayment_api_key'])) {
            $this->gateways['newpayment'] = [
                'enabled' => ($settings['newpayment_enabled'] ?? 'off') === 'on',
                'api_key' => $settings['newpayment_api_key'],
                'sandbox' => ($settings['newpayment_sandbox'] ?? 'off') === 'on'
            ];
        }
        
        // آقای پرداخت
        if (!empty($settings['aqayepardakht_pin'])) {
            $this->gateways['aqayepardakht'] = [
                'enabled' => ($settings['aqayepardakht_enabled'] ?? 'off') === 'on',
                'pin' => $settings['aqayepardakht_pin'],
                'sandbox' => ($settings['aqayepardakht_sandbox'] ?? 'off') === 'on'
            ];
        }
    }

    /**
     * دریافت لیست درگاه‌های فعال
     */
    public function getAvailableGateways(): array
    {
        return array_filter($this->gateways, function($gateway) {
            return $gateway['enabled'] ?? false;
        });
    }

    /**
     * ایجاد لینک پرداخت
     */
    public function createPaymentLink(int $userId, float $amount, string $description, array $metadata = [], string $gateway = 'zarinpal'): array
    {
        if (!isset($this->gateways[$gateway]) || !($this->gateways[$gateway]['enabled'] ?? false)) {
            return ['success' => false, 'error' => 'درگاه پرداخت انتخابی فعال نیست.'];
        }

        switch ($gateway) {
            case 'zarinpal':
                return $this->createZarinpalLink($userId, $amount, $description, $metadata);
            case 'idpay':
                return $this->createIdpayLink($userId, $amount, $description, $metadata);
            case 'nextpay':
                return $this->createNextpayLink($userId, $amount, $description, $metadata);
            case 'zibal':
                return $this->createZibalLink($userId, $amount, $description, $metadata);
            case 'newpayment':
                return $this->createNewPaymentLink($userId, $amount, $description, $metadata);
            case 'aqayepardakht':
                return $this->createAqayepardakhtLink($userId, $amount, $description, $metadata);
            default:
                return ['success' => false, 'error' => 'درگاه پرداخت پشتیبانی نمی‌شود.'];
        }
    }

    /**
     * ایجاد لینک پرداخت زرین‌پال
     */
    private function createZarinpalLink(int $userId, float $amount, string $description, array $metadata): array
    {
        $merchantId = $this->gateways['zarinpal']['merchant_id'];
        $sandbox = $this->gateways['zarinpal']['sandbox'];
        $baseUrl = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://api.zarinpal.com';
        
        $scriptUrl = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/verify_payment.php';
        
        $data = [
            'merchant_id' => $merchantId,
            'amount' => $amount * 10, // تبدیل تومان به ریال
            'callback_url' => $scriptUrl,
            'description' => $description,
            'metadata' => $metadata
        ];
        
        $jsonData = json_encode($data);
        $ch = curl_init($baseUrl . '/pg/v4/payment/request.json');
        curl_setopt_array($ch, [
            CURLOPT_USERAGENT => 'ZarinPal Rest Api v4',
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonData)
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            if ($this->logger) {
                $this->logger->error("Zarinpal API error", ['http_code' => $httpCode, 'response' => $result]);
            }
            return ['success' => false, 'error' => 'مشکل در اتصال به درگاه پرداخت زرین‌پال.'];
        }
        
        $response = json_decode($result, true);
        
        if (empty($response['errors'])) {
            $authority = $response['data']['authority'];
            
            // ثبت تراکنش در دیتابیس
            $stmt = pdo()->prepare("INSERT INTO transactions (user_id, amount, authority, description, metadata, gateway) VALUES (?, ?, ?, ?, ?, 'zarinpal')");
            $stmt->execute([$userId, $amount, $authority, $description, json_encode($metadata)]);
            
            $paymentUrl = ($sandbox ? 'https://sandbox.zarinpal.com' : 'https://www.zarinpal.com') . '/pg/StartPay/' . $authority;
            
            return ['success' => true, 'url' => $paymentUrl, 'authority' => $authority];
        } else {
            $errorCode = $response['errors']['code'];
            if ($this->logger) {
                $this->logger->error("Zarinpal payment error", ['error_code' => $errorCode]);
            }
            return ['success' => false, 'error' => "مشکل در ساخت لینک پرداخت. کد خطا: {$errorCode}"];
        }
    }

    private function createIdpayLink(int $userId, float $amount, string $description, array $metadata): array
    {
        $apiKey = $this->gateways['idpay']['api_key'];
        $sandbox = $this->gateways['idpay']['sandbox'];
        $baseUrl = 'https://api.idpay.ir';
        
        $callbackUrl = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/verify_payment.php';
        $orderId = uniqid('order_') . '_' . $userId . '_' . time();
        $metadata['order_id'] = $orderId;
        
        $data = [
            'order_id' => $orderId,
            'amount' => (int)($amount * 10),
            'callback' => $callbackUrl,
            'name' => $metadata['customer_name'] ?? '',
            'phone' => $metadata['phone'] ?? '',
            'mail' => $metadata['email'] ?? '',
            'desc' => $description
        ];
        
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            "X-API-KEY: {$apiKey}",
            'X-SANDBOX: ' . ($sandbox ? '1' : '0')
        ];
        
        if ($this->logger) {
            $this->logger->info("IDPay: Creating payment", ['url' => $baseUrl . '/v1.1/payment', 'order_id' => $orderId]);
        }
        
        $ch = curl_init($baseUrl . '/v1.1/payment');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if ($this->logger) {
                $this->logger->error("IDPay API cURL error", ['error' => $curlError]);
            }
            return ['success' => false, 'error' => 'مشکل در اتصال به درگاه پرداخت آی‌دی‌پی: ' . $curlError];
        }
        
        if ($httpCode !== 201 && $httpCode !== 200) {
            if ($this->logger) {
                $this->logger->error("IDPay API error", ['http_code' => $httpCode, 'response' => $result]);
            }
            return ['success' => false, 'error' => "مشکل در اتصال به درگاه پرداخت آی‌دی‌پی. کد خطا: {$httpCode}"];
        }
        
        $response = json_decode($result, true);
        
        if (!$response) {
            if ($this->logger) {
                $this->logger->error("IDPay API: Invalid JSON response", ['response' => $result]);
            }
            return ['success' => false, 'error' => 'پاسخ نامعتبر از درگاه پرداخت آی‌دی‌پی.'];
        }
        
        if (isset($response['status']) && $response['status'] == 1 && isset($response['id']) && isset($response['link'])) {
            $id = $response['id'];
            
            // ثبت تراکنش در دیتابیس با authority = id
            try {
                $stmt = pdo()->prepare("INSERT INTO transactions (user_id, amount, authority, description, metadata, gateway) VALUES (?, ?, ?, ?, ?, 'idpay')");
                $stmt->execute([$userId, $amount, $id, $description, json_encode($metadata)]);
                
                if ($this->logger) {
                    $this->logger->info("IDPay payment link created", ['user_id' => $userId, 'amount' => $amount, 'id' => $id, 'order_id' => $orderId]);
                }
                
                return ['success' => true, 'url' => $response['link'], 'authority' => $id];
            } catch (Exception $e) {
                if ($this->logger) {
                    $this->logger->error("IDPay transaction insert error", ['error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => 'مشکل در ثبت تراکنش در دیتابیس.'];
            }
        } else {
            $errorMsg = $response['error_message'] ?? $response['message'] ?? 'خطای نامشخص';
            $errorStatus = $response['status'] ?? 'N/A';
            if ($this->logger) {
                $this->logger->error("IDPay payment error", ['response' => $response, 'status' => $errorStatus]);
            }
            return ['success' => false, 'error' => "مشکل در ساخت لینک پرداخت آی‌دی‌پی. کد خطا: {$errorStatus} - {$errorMsg}"];
        }
    }

    private function createNextpayLink(int $userId, float $amount, string $description, array $metadata): array
    {
        $apiKey = $this->gateways['nextpay']['api_key'];
        $sandbox = $this->gateways['nextpay']['sandbox'];
        $baseUrl = 'https://api.nextpay.org';
        
        $callbackUrl = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/verify_payment.php';
        $orderId = uniqid('order_') . '_' . $userId . '_' . time();
        $metadata['order_id'] = $orderId;
        
        $data = [
            'api_key' => $apiKey,
            'order_id' => $orderId,
            'amount' => (int)($amount * 10),
            'redirect' => $callbackUrl,
            'payer_name' => $metadata['customer_name'] ?? '',
            'payer_phone' => $metadata['phone'] ?? '',
            'description' => $description
        ];
        
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $requestUrl = $baseUrl . '/v1/payment';
        
        if ($this->logger) {
            $this->logger->info("NextPay: Creating payment", ['url' => $requestUrl, 'order_id' => $orderId]);
        }
        
        $ch = curl_init($requestUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if ($this->logger) {
                $this->logger->error("NextPay API cURL error", ['error' => $curlError]);
            }
            return ['success' => false, 'error' => 'مشکل در اتصال به درگاه پرداخت نکست‌پی: ' . $curlError];
        }
        
        if ($httpCode !== 200) {
            if ($this->logger) {
                $this->logger->error("NextPay API error", ['http_code' => $httpCode, 'response' => $result]);
            }
            return ['success' => false, 'error' => "مشکل در اتصال به درگاه پرداخت نکست‌پی. کد خطا: {$httpCode}"];
        }
        
        $response = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($this->logger) {
                $this->logger->error("NextPay JSON decode error", ['error' => json_last_error_msg(), 'response' => $result]);
            }
            return ['success' => false, 'error' => 'پاسخ نامعتبر از درگاه پرداخت نکست‌پی.'];
        }
        
        if (isset($response['code']) && $response['code'] == -1 && isset($response['trans_id'])) {
            $transId = $response['trans_id'];
            
            try {
                $stmt = pdo()->prepare("INSERT INTO transactions (user_id, amount, authority, description, metadata, gateway) VALUES (?, ?, ?, ?, ?, 'nextpay')");
                $stmt->execute([$userId, $amount, $transId, $description, json_encode($metadata)]);
                
                if ($this->logger) {
                    $this->logger->info("NextPay payment link created", ['user_id' => $userId, 'amount' => $amount, 'trans_id' => $transId, 'order_id' => $orderId]);
                }
                
                $paymentUrl = $baseUrl . '/v1/payment/gateway/' . $transId;
                
                return ['success' => true, 'url' => $paymentUrl, 'authority' => $transId];
            } catch (Exception $e) {
                if ($this->logger) {
                    $this->logger->error("NextPay transaction insert error", ['error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => 'مشکل در ثبت تراکنش در دیتابیس.'];
            }
        } else {
            $errorMsg = $response['message'] ?? 'خطای نامشخص';
            $errorCode = $response['code'] ?? 'N/A';
            if ($this->logger) {
                $this->logger->error("NextPay payment error", ['response' => $response, 'code' => $errorCode]);
            }
            return ['success' => false, 'error' => "مشکل در ساخت لینک پرداخت نکست‌پی. کد خطا: {$errorCode} - {$errorMsg}"];
        }
    }

    private function createZibalLink(int $userId, float $amount, string $description, array $metadata): array
    {
        $merchantId = $this->gateways['zibal']['merchant_id'];
        $sandbox = $this->gateways['zibal']['sandbox'];
        $baseUrl = $sandbox ? 'https://sandbox.zibal.ir' : 'https://api.zibal.ir';
        
        $callbackUrl = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/verify_payment.php';
        $orderId = uniqid('order_') . '_' . $userId . '_' . time();
        $metadata['order_id'] = $orderId;
        
        $data = [
            'merchant' => $merchantId,
            'amount' => (int)($amount * 10),
            'callbackUrl' => $callbackUrl,
            'orderId' => $orderId,
            'description' => $description
        ];
        
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $requestUrl = $baseUrl . '/v1/request';
        
        if ($this->logger) {
            $this->logger->info("Zibal: Creating payment", ['url' => $requestUrl, 'order_id' => $orderId, 'merchant' => $merchantId]);
        }
        
        $ch = curl_init($requestUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if ($this->logger) {
                $this->logger->error("Zibal API cURL error", ['error' => $curlError]);
            }
            return ['success' => false, 'error' => 'مشکل در اتصال به درگاه پرداخت زیبال: ' . $curlError];
        }
        
        if ($httpCode !== 200) {
            if ($this->logger) {
                $this->logger->error("Zibal API error", ['http_code' => $httpCode, 'response' => $result]);
            }
            return ['success' => false, 'error' => "مشکل در اتصال به درگاه پرداخت زیبال. کد خطا: {$httpCode}"];
        }
        
        $response = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($this->logger) {
                $this->logger->error("Zibal JSON decode error", ['error' => json_last_error_msg(), 'response' => $result]);
            }
            return ['success' => false, 'error' => 'پاسخ نامعتبر از درگاه پرداخت زیبال.'];
        }
        
        if ((isset($response['status']) && $response['status'] == 1) || 
            (isset($response['result']) && $response['result'] == 100)) {
            $trackId = $response['trackId'] ?? null;
            
            if (!$trackId) {
                $errorMsg = $response['message'] ?? 'trackId یافت نشد';
                if ($this->logger) {
                    $this->logger->error("Zibal: trackId not found", ['response' => $response]);
                }
                return ['success' => false, 'error' => "مشکل در ساخت لینک پرداخت زیبال: {$errorMsg}"];
            }
            
            try {
                $stmt = pdo()->prepare("INSERT INTO transactions (user_id, amount, authority, description, metadata, gateway) VALUES (?, ?, ?, ?, ?, 'zibal')");
                $stmt->execute([$userId, $amount, $trackId, $description, json_encode($metadata)]);
                
                if ($this->logger) {
                    $this->logger->info("Zibal payment link created", ['user_id' => $userId, 'amount' => $amount, 'trackId' => $trackId, 'order_id' => $orderId]);
                }
                
                $paymentUrl = 'https://www.zibal.ir/start/' . $trackId;
                
                return ['success' => true, 'url' => $paymentUrl, 'authority' => $trackId];
            } catch (Exception $e) {
                if ($this->logger) {
                    $this->logger->error("Zibal transaction insert error", ['error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => 'مشکل در ثبت تراکنش در دیتابیس.'];
            }
        } else {
            $errorMsg = $response['message'] ?? 'خطای نامشخص';
            $errorStatus = $response['status'] ?? $response['result'] ?? 'N/A';
            if ($this->logger) {
                $this->logger->error("Zibal payment error", ['response' => $response, 'status' => $errorStatus]);
            }
            return ['success' => false, 'error' => "مشکل در ساخت لینک پرداخت زیبال. کد خطا: {$errorStatus} - {$errorMsg}"];
        }
    }

    /**
     * تایید پرداخت
     */
    public function verifyPayment(string $gateway, string $authority, float $amount): array
    {
        switch ($gateway) {
            case 'zarinpal':
                return $this->verifyZarinpalPayment($authority, $amount);
            case 'idpay':
                return $this->verifyIdpayPayment($authority, $amount);
            case 'nextpay':
                return $this->verifyNextpayPayment($authority, $amount);
            case 'zibal':
                return $this->verifyZibalPayment($authority, $amount);
            case 'newpayment':
                return $this->verifyNewPaymentPayment($authority, $amount);
            case 'aqayepardakht':
                return $this->verifyAqayepardakhtPayment($authority, $amount);
            default:
                return ['success' => false, 'error' => 'درگاه پرداخت پشتیبانی نمی‌شود.'];
        }
    }

    /**
     * تایید پرداخت زرین‌پال
     */
    private function verifyZarinpalPayment(string $authority, float $amount): array
    {
        $merchantId = $this->gateways['zarinpal']['merchant_id'];
        $sandbox = $this->gateways['zarinpal']['sandbox'];
        $baseUrl = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://api.zarinpal.com';
        
        $data = [
            'merchant_id' => $merchantId,
            'authority' => $authority,
            'amount' => $amount * 10
        ];
        
        $jsonData = json_encode($data);
        $ch = curl_init($baseUrl . '/pg/v4/payment/verify.json');
        curl_setopt_array($ch, [
            CURLOPT_USERAGENT => 'ZarinPal Rest Api v4',
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonData)
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $result = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($result, true);
        
        if (isset($response['data']['code']) && $response['data']['code'] == 100) {
            return [
                'success' => true,
                'ref_id' => $response['data']['ref_id'] ?? null,
                'card_hash' => $response['data']['card_hash'] ?? null
            ];
        }
        
        return ['success' => false, 'error' => $response['errors']['message'] ?? 'پرداخت تایید نشد.'];
    }

    private function verifyIdpayPayment(string $authority, float $amount): array
    {
        $apiKey = $this->gateways['idpay']['api_key'];
        $sandbox = $this->gateways['idpay']['sandbox'];
        $baseUrl = 'https://api.idpay.ir';
        
        $stmt = pdo()->prepare("SELECT metadata, authority FROM transactions WHERE authority = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$authority]);
        $transaction = $stmt->fetch();
        
        $orderId = null;
        $paymentId = $authority;
        
        if ($transaction) {
            $metadata = json_decode($transaction['metadata'] ?? '{}', true);
            $orderId = $metadata['order_id'] ?? null;
        }
        
        if (!$orderId && isset($_POST['order_id']) && !empty($_POST['order_id'])) {
            $orderId = $_POST['order_id'];
        }
        
        if (!$orderId) {
            if ($this->logger) {
                $this->logger->error("IDPay verify: order_id not found", ['payment_id' => $authority, 'transaction' => $transaction]);
            }
            return ['success' => false, 'error' => 'order_id یافت نشد.'];
        }
        
        $data = [
            'id' => $paymentId,
            'order_id' => $orderId
        ];
        
        $jsonData = json_encode($data);
        $ch = curl_init($baseUrl . '/v1.1/payment/verify');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-KEY: ' . $apiKey,
                'X-SANDBOX: ' . ($sandbox ? '1' : '0')
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if ($this->logger) {
                $this->logger->error("IDPay verify API cURL error", ['error' => $curlError]);
            }
            return ['success' => false, 'error' => 'مشکل در اتصال به درگاه پرداخت آی‌دی‌پی: ' . $curlError];
        }
        
        if ($httpCode !== 200) {
            if ($this->logger) {
                $this->logger->error("IDPay verify API error", ['http_code' => $httpCode, 'response' => $result]);
            }
            return ['success' => false, 'error' => "مشکل در اتصال به درگاه پرداخت آی‌دی‌پی برای تایید. کد خطا: {$httpCode}"];
        }
        
        $response = json_decode($result, true);
        
        if (!$response) {
            if ($this->logger) {
                $this->logger->error("IDPay verify: Invalid JSON response", ['response' => $result]);
            }
            return ['success' => false, 'error' => 'پاسخ نامعتبر از درگاه پرداخت آی‌دی‌پی.'];
        }
        
        if (isset($response['status']) && $response['status'] == 1) {
            if (isset($response['amount']) && $response['amount'] != (int)($amount * 10)) {
                if ($this->logger) {
                    $this->logger->error("IDPay verify amount mismatch", ['expected' => (int)($amount * 10), 'received' => $response['amount']]);
                }
                return ['success' => false, 'error' => 'پرداخت ناموفق یا مقدار نادرست.'];
            }
            
            if ($this->logger) {
                $this->logger->info("IDPay payment verified", ['payment_id' => $id, 'order_id' => $orderId, 'response' => $response]);
            }
            
            return [
                'success' => true,
                'ref_id' => $response['payment']['track_id'] ?? $response['track_id'] ?? $response['id'] ?? $authority,
                'card_hash' => $response['payment']['card_no'] ?? $response['card_no'] ?? $response['payment']['hashed_card_no'] ?? null
            ];
        } else {
            $errorMsg = $response['error_message'] ?? $response['message'] ?? 'پرداخت تایید نشد.';
            $errorStatus = $response['status'] ?? 'N/A';
            if ($this->logger) {
                $this->logger->error("IDPay verify error", ['response' => $response, 'status' => $errorStatus]);
            }
            return ['success' => false, 'error' => "پرداخت ناموفق بود یا مقدار نادرست. کد خطا: {$errorStatus} - {$errorMsg}"];
        }
    }

    private function verifyNextpayPayment(string $transId, float $amount): array
    {
        $apiKey = $this->gateways['nextpay']['api_key'];
        $sandbox = $this->gateways['nextpay']['sandbox'];
        $baseUrl = 'https://api.nextpay.org';
        
        $data = [
            'api_key' => $apiKey,
            'trans_id' => $transId,
            'amount' => (int)($amount * 10)
        ];
        
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $verifyUrl = $baseUrl . '/v1/payment/verify';
        
        if ($this->logger) {
            $this->logger->info("NextPay: Verifying payment", ['url' => $verifyUrl, 'trans_id' => $transId]);
        }
        
        $ch = curl_init($verifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if ($this->logger) {
                $this->logger->error("NextPay verify cURL error", ['error' => $curlError]);
            }
            return ['success' => false, 'error' => 'مشکل در اتصال به درگاه پرداخت نکست‌پی: ' . $curlError];
        }
        
        if ($httpCode !== 200) {
            if ($this->logger) {
                $this->logger->error("NextPay verify error", ['http_code' => $httpCode, 'response' => $result]);
            }
            return ['success' => false, 'error' => "مشکل در اتصال به درگاه پرداخت نکست‌پی برای تایید. کد خطا: {$httpCode}"];
        }
        
        $response = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($this->logger) {
                $this->logger->error("NextPay verify JSON decode error", ['error' => json_last_error_msg(), 'response' => $result]);
            }
            return ['success' => false, 'error' => 'پاسخ نامعتبر از درگاه پرداخت نکست‌پی.'];
        }
        
        if (isset($response['code']) && $response['code'] == 0) {
            if (isset($response['amount']) && $response['amount'] != (int)($amount * 10)) {
                if ($this->logger) {
                    $this->logger->error("NextPay verify amount mismatch", ['expected' => (int)($amount * 10), 'received' => $response['amount']]);
                }
                return ['success' => false, 'error' => 'مبلغ پرداخت با مبلغ درخواستی مطابقت ندارد.'];
            }
            
            if ($this->logger) {
                $this->logger->info("NextPay payment verified", ['trans_id' => $transId, 'response' => $response]);
            }
            
            return [
                'success' => true,
                'ref_id' => $response['Shaparak_Ref_Id'] ?? $response['ref_id'] ?? $response['refNumber'] ?? $transId,
                'card_hash' => $response['card_hashed'] ?? $response['card_hash'] ?? null
            ];
        } else {
            $errorMsg = $response['message'] ?? 'پرداخت تایید نشد.';
            $errorCode = $response['code'] ?? 'N/A';
            if ($this->logger) {
                $this->logger->error("NextPay verify error", ['response' => $response, 'code' => $errorCode]);
            }
            return ['success' => false, 'error' => "پرداخت تایید نشد. کد خطا: {$errorCode} - {$errorMsg}"];
        }
    }

    private function verifyZibalPayment(string $trackId, float $amount): array
    {
        $merchantId = $this->gateways['zibal']['merchant_id'];
        $sandbox = $this->gateways['zibal']['sandbox'];
        $baseUrl = $sandbox ? 'https://sandbox.zibal.ir' : 'https://api.zibal.ir';
        
        $data = [
            'merchant' => $merchantId,
            'trackId' => $trackId
        ];
        
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        if ($this->logger) {
            $this->logger->info("Zibal: Verifying payment", ['url' => $baseUrl . '/v1/verify', 'trackId' => $trackId]);
        }
        
        $ch = curl_init($baseUrl . '/v1/verify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if ($this->logger) {
                $this->logger->error("Zibal verify cURL error", ['error' => $curlError]);
            }
            return ['success' => false, 'error' => 'مشکل در اتصال به درگاه پرداخت زیبال: ' . $curlError];
        }
        
        if ($httpCode !== 200) {
            if ($this->logger) {
                $this->logger->error("Zibal verify error", ['http_code' => $httpCode, 'response' => $result]);
            }
            return ['success' => false, 'error' => "مشکل در اتصال به درگاه پرداخت زیبال برای تایید. کد خطا: {$httpCode}"];
        }
        
        $response = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($this->logger) {
                $this->logger->error("Zibal verify JSON decode error", ['error' => json_last_error_msg(), 'response' => $result]);
            }
            return ['success' => false, 'error' => 'پاسخ نامعتبر از درگاه پرداخت زیبال.'];
        }
        
        if ((isset($response['status']) && $response['status'] == 1) || 
            (isset($response['result']) && $response['result'] == 100)) {
            if (isset($response['amount']) && $response['amount'] != (int)($amount * 10)) {
                if ($this->logger) {
                    $this->logger->error("Zibal verify amount mismatch", ['expected' => (int)($amount * 10), 'received' => $response['amount']]);
                }
                return ['success' => false, 'error' => 'مبلغ پرداخت با مبلغ درخواستی مطابقت ندارد.'];
            }
            
            if ($this->logger) {
                $this->logger->info("Zibal payment verified", ['trackId' => $trackId, 'response' => $response]);
            }
            
            return [
                'success' => true,
                'ref_id' => $response['refNumber'] ?? $response['ref_id'] ?? $response['ref_number'] ?? $trackId,
                'card_hash' => $response['cardNumber'] ?? $response['card_hash'] ?? $response['card_number'] ?? null
            ];
        } else {
            $errorMsg = $response['message'] ?? 'پرداخت تایید نشد.';
            $errorStatus = $response['status'] ?? $response['result'] ?? 'N/A';
            if ($this->logger) {
                $this->logger->error("Zibal verify error", ['response' => $response, 'status' => $errorStatus]);
            }
            return ['success' => false, 'error' => "پرداخت تایید نشد. کد خطا: {$errorStatus} - {$errorMsg}"];
        }
    }

    private function createNewPaymentLink(int $userId, float $amount, string $description, array $metadata): array
    {
        $apiKey = $this->gateways['newpayment']['api_key'];
        $sandbox = $this->gateways['newpayment']['sandbox'];
        $baseUrl = $sandbox ? 'https://api-sandbox.newpayment.ir' : 'https://api.newpayment.ir';
        
        $callbackUrl = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/verify_payment.php';
        $orderId = 'ORDER_' . $userId . '_' . time();
        $metadata['order_id'] = $orderId;
        
        $data = [
            'amount' => (int)($amount * 10),
            'callback_url' => $callbackUrl,
            'description' => $description,
            'order_id' => $orderId
        ];
        
        if (isset($metadata['customer_name']) && !empty($metadata['customer_name'])) {
            $data['customer_name'] = $metadata['customer_name'];
        }
        if (isset($metadata['email']) && !empty($metadata['email'])) {
            $data['customer_email'] = $metadata['email'];
        }
        if (isset($metadata['phone']) && !empty($metadata['phone'])) {
            $data['customer_mobile'] = $metadata['phone'];
        }
        
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey
        ];
        
        $endpoint = '/api/v1/payment';
        $url = $baseUrl . $endpoint;
        
        if ($this->logger) {
            $this->logger->info("NewPayment: Creating payment", ['url' => $url, 'data' => $data]);
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if ($this->logger) {
                $this->logger->error("NewPayment API cURL error", ['error' => $curlError, 'endpoint' => $endpoint]);
            }
            return ['success' => false, 'error' => 'مشکل در اتصال به درگاه پرداخت newPayment: ' . $curlError];
        }
        
        if ($httpCode !== 200 && $httpCode !== 201) {
            if ($this->logger) {
                $this->logger->error("NewPayment API error", ['http_code' => $httpCode, 'response' => $result, 'endpoint' => $endpoint]);
            }
            return ['success' => false, 'error' => "مشکل در اتصال به درگاه پرداخت newPayment. کد خطا: {$httpCode}"];
        }
        
        $response = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($this->logger) {
                $this->logger->error("NewPayment JSON decode error", ['error' => json_last_error_msg(), 'response' => $result]);
            }
            return ['success' => false, 'error' => 'پاسخ نامعتبر از درگاه پرداخت newPayment.'];
        }
        
        $paymentId = null;
        $paymentUrl = null;
        
        if (isset($response['success']) && $response['success'] === true) {
            if (isset($response['data'])) {
                $paymentId = $response['data']['payment_id'] ?? $response['data']['id'] ?? null;
                $paymentUrl = $response['data']['payment_url'] ?? $response['data']['url'] ?? $response['data']['link'] ?? null;
            } else {
                $paymentId = $response['payment_id'] ?? $response['id'] ?? null;
                $paymentUrl = $response['payment_url'] ?? $response['url'] ?? $response['link'] ?? null;
            }
        } elseif (isset($response['status']) && ($response['status'] === 'success' || $response['status'] === 200)) {
            if (isset($response['data'])) {
                $paymentId = $response['data']['payment_id'] ?? $response['data']['id'] ?? null;
                $paymentUrl = $response['data']['payment_url'] ?? $response['data']['url'] ?? $response['data']['link'] ?? null;
            } else {
                $paymentId = $response['payment_id'] ?? $response['id'] ?? null;
                $paymentUrl = $response['payment_url'] ?? $response['url'] ?? $response['link'] ?? null;
            }
        } elseif (isset($response['code']) && ($response['code'] == 200 || $response['code'] == 0 || $response['code'] == '200')) {
            if (isset($response['data'])) {
                $paymentId = $response['data']['payment_id'] ?? $response['data']['id'] ?? null;
                $paymentUrl = $response['data']['payment_url'] ?? $response['data']['url'] ?? $response['data']['link'] ?? null;
            } else {
                $paymentId = $response['payment_id'] ?? $response['id'] ?? null;
                $paymentUrl = $response['payment_url'] ?? $response['url'] ?? $response['link'] ?? null;
            }
        } elseif (isset($response['payment_id']) && isset($response['payment_url'])) {
            $paymentId = $response['payment_id'];
            $paymentUrl = $response['payment_url'];
        } elseif (isset($response['id']) && isset($response['link'])) {
            $paymentId = $response['id'];
            $paymentUrl = $response['link'];
        }
        
        if ($paymentId && $paymentUrl) {
            $metadata['payment_id'] = $paymentId;
            
            try {
                $stmt = pdo()->prepare("INSERT INTO transactions (user_id, amount, authority, description, metadata, gateway) VALUES (?, ?, ?, ?, ?, 'newpayment')");
                $stmt->execute([$userId, $amount, $paymentId, $description, json_encode($metadata)]);
                
                if ($this->logger) {
                    $this->logger->info("NewPayment payment link created", ['user_id' => $userId, 'amount' => $amount, 'payment_id' => $paymentId, 'order_id' => $orderId]);
                }
                
                return ['success' => true, 'url' => $paymentUrl, 'authority' => $paymentId];
            } catch (Exception $e) {
                if ($this->logger) {
                    $this->logger->error("NewPayment transaction insert error", ['error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => 'مشکل در ثبت تراکنش در دیتابیس.'];
            }
        } else {
            $errorMsg = $response['message'] ?? $response['error'] ?? $response['error_message'] ?? 'خطای نامشخص';
            if ($this->logger) {
                $this->logger->error("NewPayment payment error", ['response' => $response]);
            }
            return ['success' => false, 'error' => "مشکل در ساخت لینک پرداخت newPayment: {$errorMsg}"];
        }
    }

    private function verifyNewPaymentPayment(string $paymentId, float $amount): array
    {
        $apiKey = $this->gateways['newpayment']['api_key'];
        $sandbox = $this->gateways['newpayment']['sandbox'];
        $baseUrl = $sandbox ? 'https://api-sandbox.newpayment.ir' : 'https://api.newpayment.ir';
        
        $stmt = pdo()->prepare("SELECT metadata, authority FROM transactions WHERE authority = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$paymentId]);
        $transaction = $stmt->fetch();
        
        $orderId = null;
        if ($transaction) {
            $metadata = json_decode($transaction['metadata'] ?? '{}', true);
            $orderId = $metadata['order_id'] ?? null;
        }
        
        if (!$orderId && isset($_POST['order_id']) && !empty($_POST['order_id'])) {
            $orderId = $_POST['order_id'];
        }
        
        $data = ['payment_id' => $paymentId];
        if ($orderId) {
            $data['order_id'] = $orderId;
        }
        
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey
        ];
        
        $endpoint = '/api/v1/payment/verify';
        $url = $baseUrl . $endpoint;
        
        if ($this->logger) {
            $this->logger->info("NewPayment: Verifying payment", ['url' => $url, 'data' => $data]);
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if ($this->logger) {
                $this->logger->error("NewPayment verify cURL error", ['error' => $curlError, 'endpoint' => $endpoint]);
            }
            return ['success' => false, 'error' => 'مشکل در اتصال به درگاه پرداخت newPayment: ' . $curlError];
        }
        
        if ($httpCode !== 200) {
            if ($this->logger) {
                $this->logger->error("NewPayment verify error", ['http_code' => $httpCode, 'response' => $result, 'endpoint' => $endpoint]);
            }
            return ['success' => false, 'error' => "خطا در اتصال به درگاه پرداخت newPayment برای تایید. کد خطا: {$httpCode}"];
        }
        
        $response = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($this->logger) {
                $this->logger->error("NewPayment verify JSON decode error", ['error' => json_last_error_msg(), 'response' => $result]);
            }
            return ['success' => false, 'error' => 'پاسخ نامعتبر از درگاه پرداخت newPayment.'];
        }
        
        if (isset($response['success']) && $response['success'] === true) {
            if ($this->logger) {
                $this->logger->info("NewPayment payment verified", ['payment_id' => $paymentId, 'order_id' => $orderId, 'response' => $response]);
            }
            return [
                'success' => true,
                'ref_id' => $response['data']['ref_id'] ?? $response['data']['transaction_id'] ?? $response['data']['tracking_code'] ?? $response['ref_id'] ?? $response['transaction_id'] ?? $paymentId,
                'card_hash' => $response['data']['card_hash'] ?? $response['data']['card_number'] ?? $response['data']['hashed_card_no'] ?? $response['card_hash'] ?? $response['card_number'] ?? null
            ];
        } elseif (isset($response['status']) && ($response['status'] === 'success' || $response['status'] === 200 || $response['status'] === 'verified')) {
            if ($this->logger) {
                $this->logger->info("NewPayment payment verified", ['payment_id' => $paymentId, 'order_id' => $orderId, 'response' => $response]);
            }
            return [
                'success' => true,
                'ref_id' => $response['data']['ref_id'] ?? $response['data']['transaction_id'] ?? $response['data']['tracking_code'] ?? $response['ref_id'] ?? $response['transaction_id'] ?? $paymentId,
                'card_hash' => $response['data']['card_hash'] ?? $response['data']['card_number'] ?? $response['data']['hashed_card_no'] ?? $response['card_hash'] ?? $response['card_number'] ?? null
            ];
        } elseif (isset($response['code']) && ($response['code'] == 200 || $response['code'] == 0 || $response['code'] == '200')) {
            if ($this->logger) {
                $this->logger->info("NewPayment payment verified", ['payment_id' => $paymentId, 'order_id' => $orderId, 'response' => $response]);
            }
            return [
                'success' => true,
                'ref_id' => $response['data']['ref_id'] ?? $response['data']['transaction_id'] ?? $response['data']['tracking_code'] ?? $response['ref_id'] ?? $response['transaction_id'] ?? $paymentId,
                'card_hash' => $response['data']['card_hash'] ?? $response['data']['card_number'] ?? $response['data']['hashed_card_no'] ?? $response['card_hash'] ?? $response['card_number'] ?? null
            ];
        } elseif (isset($response['verified']) && $response['verified'] === true) {
            if ($this->logger) {
                $this->logger->info("NewPayment payment verified", ['payment_id' => $paymentId, 'order_id' => $orderId, 'response' => $response]);
            }
            return [
                'success' => true,
                'ref_id' => $response['ref_id'] ?? $response['transaction_id'] ?? $response['tracking_code'] ?? $paymentId,
                'card_hash' => $response['card_hash'] ?? $response['card_number'] ?? $response['hashed_card_no'] ?? null
            ];
        } else {
            $errorMsg = $response['message'] ?? $response['error'] ?? $response['error_message'] ?? 'پرداخت تایید نشد.';
            $errorCode = $response['code'] ?? $response['status'] ?? 'N/A';
            if ($this->logger) {
                $this->logger->error("NewPayment verify error", ['response' => $response, 'error_code' => $errorCode]);
            }
            return ['success' => false, 'error' => "پرداخت تایید نشد. کد خطا: {$errorCode} - {$errorMsg}"];
        }
    }

    private function createAqayepardakhtLink(int $userId, float $amount, string $description, array $metadata): array
    {
        $pin = $this->gateways['aqayepardakht']['pin'];
        $sandbox = $this->gateways['aqayepardakht']['sandbox'];
        $baseUrl = 'https://panel.aqayepardakht.ir';
        
        $callbackUrl = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/verify_payment.php';
        $invoiceId = 'INV_' . $userId . '_' . time();
        $metadata['invoice_id'] = $invoiceId;
        
        $data = [
            'pin' => $pin,
            'amount' => (int)($amount * 10),
            'callback' => $callbackUrl,
            'invoice_id' => $invoiceId,
            'description' => $description
        ];
        
        if (isset($metadata['customer_name']) && !empty($metadata['customer_name'])) {
            $data['name'] = $metadata['customer_name'];
        }
        if (isset($metadata['email']) && !empty($metadata['email'])) {
            $data['email'] = $metadata['email'];
        }
        if (isset($metadata['phone']) && !empty($metadata['phone'])) {
            $data['mobile'] = $metadata['phone'];
        }
        
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        $endpoints = [
            '/api/v2/create',
            '/api/v2/payment/create',
            '/api/v2/payment',
            '/api/payment/create',
            '/api/create'
        ];
        
        $transId = null;
        $paymentUrl = null;
        $lastError = '';
        $lastResponse = null;
        
        foreach ($endpoints as $endpoint) {
            $url = $baseUrl . $endpoint;
            
            if ($this->logger) {
                $this->logger->info("Aqayepardakht: Creating payment", ['url' => $url, 'invoice_id' => $invoiceId, 'endpoint' => $endpoint]);
            }
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonData,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                $lastError = "cURL error: {$curlError}";
                if ($this->logger) {
                    $this->logger->error("Aqayepardakht API cURL error", ['error' => $curlError, 'endpoint' => $endpoint]);
                }
                continue;
            }
            
            if ($httpCode !== 200 && $httpCode !== 201) {
                $lastError = "HTTP {$httpCode}: " . substr($result, 0, 200);
                if ($this->logger) {
                    $this->logger->error("Aqayepardakht API error", ['http_code' => $httpCode, 'response' => $result, 'endpoint' => $endpoint]);
                }
                continue;
            }
            
            $response = json_decode($result, true);
            $lastResponse = $response;
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $lastError = "JSON decode error: " . json_last_error_msg();
                if ($this->logger) {
                    $this->logger->error("Aqayepardakht JSON decode error", ['error' => json_last_error_msg(), 'response' => $result, 'endpoint' => $endpoint]);
                }
                continue;
            }
            
            if (isset($response['code']) && ($response['code'] == '1' || $response['code'] == 1)) {
                $transId = $response['transid'] ?? $response['trans_id'] ?? $response['id'] ?? null;
                $paymentUrl = $response['payment_url'] ?? $response['url'] ?? $response['link'] ?? null;
                
                if (!$transId) {
                    $transId = $invoiceId;
                }
                
                if (!$paymentUrl) {
                    $paymentUrl = $baseUrl . '/payment/' . $transId;
                }
                
                if ($transId && $paymentUrl) {
                    try {
                        $stmt = pdo()->prepare("INSERT INTO transactions (user_id, amount, authority, description, metadata, gateway) VALUES (?, ?, ?, ?, ?, 'aqayepardakht')");
                        $stmt->execute([$userId, $amount, $transId, $description, json_encode($metadata)]);
                        
                        if ($this->logger) {
                            $this->logger->info("Aqayepardakht payment link created", ['user_id' => $userId, 'amount' => $amount, 'transid' => $transId, 'invoice_id' => $invoiceId, 'endpoint' => $endpoint]);
                        }
                        
                        return ['success' => true, 'url' => $paymentUrl, 'authority' => $transId];
                    } catch (Exception $e) {
                        if ($this->logger) {
                            $this->logger->error("Aqayepardakht transaction insert error", ['error' => $e->getMessage()]);
                        }
                        return ['success' => false, 'error' => 'مشکل در ثبت تراکنش در دیتابیس.'];
                    }
                }
            }
        }
        
        $errorMsg = $lastResponse['message'] ?? $lastResponse['error'] ?? $lastResponse['error_message'] ?? $lastError ?? 'خطای نامشخص';
        $errorCode = $lastResponse['code'] ?? 'N/A';
        if ($this->logger) {
            $this->logger->error("Aqayepardakht payment error", ['response' => $lastResponse, 'error_code' => $errorCode, 'last_error' => $lastError]);
        }
        return ['success' => false, 'error' => "خطا در ایجاد لینک پرداخت آقای پرداخت. کد خطا: {$errorCode} - {$errorMsg}"];
    }

    private function verifyAqayepardakhtPayment(string $transId, float $amount): array
    {
        $pin = $this->gateways['aqayepardakht']['pin'];
        $baseUrl = 'https://panel.aqayepardakht.ir';
        
        $data = [
            'pin' => $pin,
            'amount' => (int)($amount * 10),
            'transid' => $transId
        ];
        
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        $endpoint = '/api/v2/verify';
        $url = $baseUrl . $endpoint;
        
        if ($this->logger) {
            $this->logger->info("Aqayepardakht: Verifying payment", ['url' => $url, 'transid' => $transId]);
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if ($this->logger) {
                $this->logger->error("Aqayepardakht verify cURL error", ['error' => $curlError]);
            }
            return ['success' => false, 'error' => 'خطا در اتصال به درگاه پرداخت آقای پرداخت: ' . $curlError];
        }
        
        if ($httpCode !== 200) {
            if ($this->logger) {
                $this->logger->error("Aqayepardakht verify error", ['http_code' => $httpCode, 'response' => $result]);
            }
            return ['success' => false, 'error' => "خطا در اتصال به درگاه پرداخت آقای پرداخت برای تایید. کد خطا: {$httpCode}"];
        }
        
        $response = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($this->logger) {
                $this->logger->error("Aqayepardakht verify JSON decode error", ['error' => json_last_error_msg(), 'response' => $result]);
            }
            return ['success' => false, 'error' => 'پاسخ نامعتبر از درگاه پرداخت آقای پرداخت.'];
        }
        
        if (isset($response['code']) && ($response['code'] == '1' || $response['code'] == 1)) {
            if ($this->logger) {
                $this->logger->info("Aqayepardakht payment verified", ['transid' => $transId, 'response' => $response]);
            }
            return [
                'success' => true,
                'ref_id' => $response['refid'] ?? $response['ref_id'] ?? $response['transaction_id'] ?? $transId,
                'card_hash' => $response['card_hash'] ?? $response['card_number'] ?? null
            ];
        } else {
            $errorMessages = [
                '0' => 'پرداخت انجام نشد',
                '2' => 'تراکنش قبلا وریفای و پرداخت شده است'
            ];
            $errorCode = $response['code'] ?? 'N/A';
            $errorMsg = $errorMessages[$errorCode] ?? ($response['message'] ?? $response['error'] ?? 'پرداخت تایید نشد.');
            
            if ($this->logger) {
                $this->logger->error("Aqayepardakht verify error", ['response' => $response, 'error_code' => $errorCode]);
            }
            return ['success' => false, 'error' => "پرداخت تایید نشد. کد خطا: {$errorCode} - {$errorMsg}"];
        }
    }

    public function getNowPaymentStatus(string $paymentId): array
    {
        if (!isset($this->gateways['newpayment'])) {
            return ['success' => false, 'error' => 'NewPayment gateway is not configured'];
        }
        
        $apiKey = $this->gateways['newpayment']['api_key'] ?? '';
        $sandbox = $this->gateways['newpayment']['sandbox'] ?? false;
        $baseUrl = $sandbox ? 'https://api-sandbox.nowpayments.io' : 'https://api.nowpayments.io';
        
        $endpoints = [
            '/v1/payment/' . $paymentId,
            '/v1/payment/status',
            '/api/v1/payment/' . $paymentId,
            '/api/v1/payment/status'
        ];
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-key: ' . $apiKey
        ];
        
        foreach ($endpoints as $endpoint) {
            $url = $baseUrl . $endpoint;
            
            if ($this->logger) {
                $this->logger->info("NowPayment: Getting payment status", ['url' => $url, 'payment_id' => $paymentId]);
            }
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            if (strpos($endpoint, '/status') !== false) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['payment_id' => $paymentId]));
            }
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                if ($this->logger) {
                    $this->logger->error("NowPayment status cURL error", ['error' => $curlError, 'endpoint' => $endpoint]);
                }
                continue;
            }
            
            if ($httpCode !== 200) {
                if ($this->logger) {
                    $this->logger->error("NowPayment status error", ['http_code' => $httpCode, 'response' => $result, 'endpoint' => $endpoint]);
                }
                continue;
            }
            
            $response = json_decode($result, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                if ($this->logger) {
                    $this->logger->error("NowPayment status JSON decode error", ['error' => json_last_error_msg(), 'response' => $result]);
                }
                continue;
            }
            
            if (isset($response['payment_id']) || isset($response['invoice_id']) || isset($response['payment_status'])) {
                return array_merge($response, ['success' => true]);
            }
        }
        
        return ['success' => false, 'error' => 'Failed to get payment status from all endpoints'];
    }
}
