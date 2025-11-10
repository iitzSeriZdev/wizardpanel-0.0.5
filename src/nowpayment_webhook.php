<?php
/**
 * Webhook handler for NOWPayments
 * بر اساس کد نمونه nowpayment.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/PaymentGateway.php';

// دریافت داده از webhook
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    die("Invalid request");
}

// بررسی payment_status
if (isset($data['payment_status']) && $data['payment_status'] == "finished") {
    $paymentId = $data['payment_id'] ?? null;
    
    if (!$paymentId) {
        http_response_code(400);
        die("payment_id is required");
    }
    
    // دریافت وضعیت پرداخت از API
    $paymentGateway = PaymentGateway::getInstance();
    $paymentStatus = $paymentGateway->getNowPaymentStatus($paymentId);
    
    if (!isset($paymentStatus['success']) || !$paymentStatus['success']) {
        error_log("NOWPayments: Failed to get payment status for payment_id: {$paymentId}");
        http_response_code(500);
        die("Failed to get payment status");
    }
    
    // پیدا کردن تراکنش در دیتابیس
    // اول سعی می‌کنیم از payment_id (authority) استفاده کنیم
    $stmt = pdo()->prepare("SELECT * FROM transactions WHERE gateway = 'newpayment' AND status = 'pending' AND authority = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$paymentId]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        // اگر با authority پیدا نشد، از metadata جستجو کن (order_id)
        $stmt = pdo()->prepare("SELECT * FROM transactions WHERE gateway = 'newpayment' AND status = 'pending' ORDER BY id DESC LIMIT 10");
        $stmt->execute();
        $transactions = $stmt->fetchAll();
        
        foreach ($transactions as $trans) {
            $metadata = json_decode($trans['metadata'] ?? '{}', true);
            // بررسی order_id در metadata
            if (isset($metadata['order_id'])) {
                // اگر order_id در paymentStatus وجود دارد و با metadata مطابقت دارد
                $orderIdFromStatus = $paymentStatus['order_id'] ?? $paymentStatus['invoice_id'] ?? null;
                if ($orderIdFromStatus && $metadata['order_id'] == $orderIdFromStatus) {
                    $transaction = $trans;
                    break;
                }
            }
            // همچنین می‌توانیم از payment_id در metadata استفاده کنیم
            if (isset($metadata['payment_id']) && $metadata['payment_id'] == $paymentId) {
                $transaction = $trans;
                break;
            }
        }
    }
    
    // اگر هنوز تراکنش پیدا نشد، از invoice_id در paymentStatus استفاده می‌کنیم
    if (!$transaction) {
        $invoiceId = $paymentStatus['invoice_id'] ?? $paymentStatus['order_id'] ?? null;
        if ($invoiceId) {
            $stmt = pdo()->prepare("SELECT * FROM transactions WHERE gateway = 'newpayment' AND status = 'pending' AND metadata LIKE ? ORDER BY id DESC LIMIT 1");
            $stmt->execute(['%"order_id":"' . $invoiceId . '"%']);
            $transaction = $stmt->fetch();
        }
    }
    
    if (!$transaction) {
        $invoiceId = $paymentStatus['invoice_id'] ?? $paymentStatus['order_id'] ?? 'N/A';
        error_log("NOWPayments: Transaction not found for payment_id: {$paymentId}, invoice_id: {$invoiceId}");
        http_response_code(404);
        die("Transaction not found");
    }
    
    // بررسی اینکه آیا تراکنش قبلاً پردازش شده است
    if ($transaction['status'] == 'completed') {
        http_response_code(200);
        echo "Transaction already processed";
        exit;
    }
    
    $gateway = $transaction['gateway'] ?? 'newpayment';
    $amount = (float)$transaction['amount'];
    
    // تایید پرداخت
    $verify_result = $paymentGateway->verifyPayment($gateway, $paymentId, $amount);
    
    if ($verify_result['success']) {
        $ref_id = $verify_result['ref_id'] ?? $paymentStatus['payin_hash'] ?? null;
        
        // آپدیت وضعیت تراکنش
        $stmt = pdo()->prepare("UPDATE transactions SET status = 'completed', ref_id = ?, verified_at = NOW() WHERE id = ?");
        $stmt->execute([$ref_id, $transaction['id']]);
        
        $metadata = json_decode($transaction['metadata'], true);
        
        // --- تشخیص هدف پرداخت ---
        if (isset($metadata['purpose']) && $metadata['purpose'] === 'complete_purchase') {
            $plan_id = $metadata['plan_id'];
            $user_id = $metadata['user_id'];
            $discount_code = $metadata['discount_code'] ?? null;
            $custom_volume_gb = $metadata['custom_volume_gb'] ?? null;
            $custom_duration_days = $metadata['custom_duration_days'] ?? null;
            
            $plan = getPlanById($plan_id);
            
            // محاسبه قیمت نهایی
            $final_price = (float)$plan['price'];
            $discount_applied = false;
            $discount_object = null;
            
            // اگر پلن قابل تنظیم باشد و حجم/روز سفارشی وجود داشته باشد
            if ($custom_volume_gb !== null && $custom_duration_days !== null) {
                $settings = getSettings();
                $price_per_gb = (float)($plan['price_per_gb'] ?? 0);
                $price_per_day = (float)($plan['price_per_day'] ?? 0);
                
                // اگر قیمت در پلن تنظیم نشده، از قیمت تمدید استفاده کن
                if ($price_per_gb == 0) {
                    $price_per_gb = (float)($settings['renewal_price_per_gb'] ?? 2000);
                }
                if ($price_per_day == 0) {
                    $price_per_day = (float)($settings['renewal_price_per_day'] ?? 1000);
                }
                
                $base_price = ($custom_volume_gb * $price_per_gb) + ($custom_duration_days * $price_per_day);
                $final_price = $base_price;
            }
            
            if ($discount_code) {
                $stmt_discount = pdo()->prepare("SELECT * FROM discount_codes WHERE code = ?");
                $stmt_discount->execute([$discount_code]);
                $discount_object = $stmt_discount->fetch();
                if ($discount_object) {
                    if ($discount_object['type'] == 'percent') {
                        $final_price = $final_price - ($final_price * $discount_object['value']) / 100;
                    } else {
                        $final_price = $final_price - $discount_object['value'];
                    }
                    $final_price = max(0, $final_price);
                    $discount_applied = true;
                }
            }
            
            // شارژ موقت حساب کاربر برای کسر هزینه
            updateUserBalance($user_id, $transaction['amount'], 'add');
            
            // نام دلخواه از متادیتا
            $custom_name = $metadata['custom_name'] ?? 'سرویس';
            $purchase_result = completePurchase($user_id, $plan_id, $custom_name, $final_price, $discount_code, $discount_object, $discount_applied, $custom_volume_gb, $custom_duration_days);
            
            if ($purchase_result['success']) {
                sendPhoto($user_id, $purchase_result['qr_code_url'], $purchase_result['caption'], $purchase_result['keyboard']);
                sendMessage(ADMIN_CHAT_ID, $purchase_result['admin_notification']);
            } else {
                sendMessage($user_id, "❌ پرداخت شما موفق بود اما در ایجاد سرویس خطایی رخ داد. مبلغ پرداخت شده به موجودی شما اضافه شد. لطفاً با پشتیبانی تماس بگیرید.");
            }
        } else {
            // پرداخت برای شارژ عادی حساب
            updateUserBalance($transaction['user_id'], $transaction['amount'], 'add');
            $new_balance_data = getUserData($transaction['user_id']);
            
            $message = "✅ پرداخت شما به مبلغ " . number_format($transaction['amount']) . " تومان با موفقیت انجام و حساب شما شارژ شد.\n\n" .
                       "▫️ شماره پیگیری: `" . ($ref_id ?? 'N/A') . "`\n" .
                       "💰 موجودی جدید: " . number_format($new_balance_data['balance']) . " تومان";
            sendMessage($transaction['user_id'], $message);
        }
        
        http_response_code(200);
        echo "Payment processed successfully";
    } else {
        // آپدیت وضعیت تراکنش به ناموفق
        $stmt = pdo()->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
        $stmt->execute([$transaction['id']]);
        
        $error_message = "خطا در تایید تراکنش: " . ($verify_result['error'] ?? 'خطای نامشخص');
        sendMessage($transaction['user_id'], "❌ تراکنش شما ناموفق بود. " . $error_message);
        
        http_response_code(400);
        echo "Payment verification failed";
    }
} else {
    http_response_code(200);
    echo "Payment status is not finished";
}

