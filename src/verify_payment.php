<?php

// --- فراخوانی فایل‌های مورد نیاز ---
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// دریافت پارامترها از درگاه پرداخت
// پارامترهای مختلف برای درگاه‌های مختلف:
// زرین‌پال: Authority, Status
// IDPay: id, status, order_id
// NextPay: trans_id, amount
// زیبال: trackId, success
// newPayment: payment_id, status, order_id
// آقای پرداخت: transid, invoice_id
$authority = $_GET['Authority'] ?? $_GET['authority'] ?? $_GET['payment_id'] ?? $_GET['trackId'] ?? $_GET['trans_id'] ?? $_GET['transid'] ?? $_GET['id'] ?? $_POST['id'] ?? $_POST['payment_id'] ?? $_POST['transid'] ?? null;
$status = $_GET['Status'] ?? $_GET['status'] ?? $_POST['status'] ?? null;

// اگر authority خالی است، سعی می‌کنیم از status برای تشخیص استفاده کنیم
// برخی درگاه‌ها ممکن است فقط status را برگردانند
if (empty($authority)) {
    // برای IDPay، ممکن است id در POST باشد
    if (isset($_POST['id']) && !empty($_POST['id'])) {
        $authority = $_POST['id'];
    }
    // برای زیبال، ممکن است trackId در POST باشد
    elseif (isset($_POST['trackId']) && !empty($_POST['trackId'])) {
        $authority = $_POST['trackId'];
    }
    // برای NextPay، ممکن است trans_id در POST باشد
    elseif (isset($_POST['trans_id']) && !empty($_POST['trans_id'])) {
        $authority = $_POST['trans_id'];
    }
    // برای آقای پرداخت، ممکن است transid در POST باشد
    elseif (isset($_POST['transid']) && !empty($_POST['transid'])) {
        $authority = $_POST['transid'];
    }
}

if (empty($authority)) {
    // اگر هنوز authority پیدا نشد، سعی می‌کنیم از تمام پارامترها استفاده کنیم
    $allParams = array_merge($_GET, $_POST);
    foreach (['Authority', 'authority', 'payment_id', 'trackId', 'trans_id', 'transid', 'id'] as $key) {
        if (isset($allParams[$key]) && !empty($allParams[$key])) {
            $authority = $allParams[$key];
            break;
        }
    }
}

if (empty($authority)) {
    error_log("Verify Payment: No authority/payment ID found in request. GET: " . json_encode($_GET) . " POST: " . json_encode($_POST));
    die("اطلاعات بازگشتی از درگاه ناقص است. لطفاً با پشتیبانی تماس بگیرید.");
}

// پیدا کردن تراکنش در دیتابیس
// ممکن است authority در دیتابیس متفاوت باشد (مثلاً برای IDPay، id و order_id)
$stmt = pdo()->prepare("SELECT * FROM transactions WHERE (authority = ? OR authority LIKE ?) AND status = 'pending' ORDER BY id DESC LIMIT 1");
$stmt->execute([$authority, '%' . $authority . '%']);
$transaction = $stmt->fetch();

if (!$transaction) {
    // اگر تراکنش با authority پیدا نشد، ممکن است در metadata باشد
    $stmt = pdo()->prepare("SELECT * FROM transactions WHERE status = 'pending' ORDER BY id DESC LIMIT 10");
    $stmt->execute();
    $transactions = $stmt->fetchAll();
    
    foreach ($transactions as $trans) {
        $metadata = json_decode($trans['metadata'] ?? '{}', true);
        if (isset($metadata['order_id']) && $metadata['order_id'] == $authority) {
            $transaction = $trans;
            break;
        }
        if (isset($metadata['payment_id']) && $metadata['payment_id'] == $authority) {
            $transaction = $trans;
            break;
        }
        // برای آقای پرداخت، invoice_id را بررسی می‌کنیم
        // برای آقای پرداخت، invoice_id را بررسی می‌کنیم
        if (isset($metadata['invoice_id'])) {
            // اگر authority همان invoice_id است یا transid در GET/POST است
            if ($metadata['invoice_id'] == $authority || 
                (isset($_GET['transid']) && $trans['authority'] == $_GET['transid']) ||
                (isset($_POST['transid']) && $trans['authority'] == $_POST['transid'])) {
                $transaction = $trans;
                break;
            }
        }
    }
}

if (!$transaction) {
    error_log("Verify Payment: Transaction not found for authority: {$authority}");
    die("تراکنش یافت نشد یا قبلاً پردازش شده است.");
}

$gateway = $transaction['gateway'] ?? 'zarinpal';
$amount = (float)$transaction['amount']; // مبلغ به تومان

// استفاده از PaymentGateway برای تایید پرداخت
if (class_exists('PaymentGateway')) {
    $paymentGateway = PaymentGateway::getInstance();
    $verify_result = $paymentGateway->verifyPayment($gateway, $authority, $amount);
    
    if ($verify_result['success']) {
        $ref_id = $verify_result['ref_id'] ?? null;
        
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
                echo "<h1>پرداخت و خرید موفق</h1><p>سرویس شما با موفقیت ایجاد شد. لطفاً به ربات تلگرام بازگردید.</p>";
            } else {
                 sendMessage($user_id, "❌ پرداخت شما موفق بود اما در ایجاد سرویس خطایی رخ داد. مبلغ پرداخت شده به موجودی شما اضافه شد. لطفاً با پشتیبانی تماس بگیرید.");
                 echo "<h1>خطا در ساخت سرویس</h1><p>پرداخت موفق بود اما سرویس ایجاد نشد. مبلغ به حساب شما اضافه شد.</p>";
            }

        } else {
            // پرداخت برای شارژ عادی حساب  
            updateUserBalance($transaction['user_id'], $transaction['amount'], 'add');
            $new_balance_data = getUserData($transaction['user_id']);
    
            $message = "✅ پرداخت شما به مبلغ " . number_format($transaction['amount']) . " تومان با موفقیت انجام و حساب شما شارژ شد.\n\n" .
                       "▫️ شماره پیگیری: `" . ($ref_id ?? 'N/A') . "`\n" .
                       "💰 موجودی جدید: " . number_format($new_balance_data['balance']) . " تومان";
            sendMessage($transaction['user_id'], $message);
    
            echo "<h1>پرداخت موفق</h1><p>تراکنش شما با موفقیت انجام شد و حساب شما شارژ گردید. شماره پیگیری: " . ($ref_id ?? 'N/A') . ". لطفاً به ربات تلگرام بازگردید.</p>";
        }

    } else {
        // آپدیت وضعیت تراکنش به ناموفق
        $stmt = pdo()->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
        $stmt->execute([$transaction['id']]);
        $error_message = "خطا در تایید تراکنش: " . ($verify_result['error'] ?? 'خطای نامشخص');
        sendMessage($transaction['user_id'], "❌ تراکنش شما ناموفق بود. " . $error_message);
        echo "<h1>پرداخت ناموفق</h1><p>{$error_message}</p>";
    }
} else {
    // Fallback به روش قدیمی زرین‌پال
    if ($status == 'OK') {
        $settings = getSettings();
        $merchant_id = $settings['zarinpal_merchant_id'] ?? '';
        
        // تراکنش موفق بود
        $data = [
            "merchant_id" => $merchant_id,
            "amount" => $amount * 10, // تبدیل تومان به ریال برای وریفای
            "authority" => $authority,
        ];
        $jsonData = json_encode($data);

        $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/verify.json');
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($jsonData)]);

        $result = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($result, true);

        if (empty($result['errors'])) {
            $code = $result['data']['code'];
            if ($code == 100 || $code == 101) { // 100: موفق, 101: قبلا وریفای شده
                $ref_id = $result['data']['ref_id'];

                // آپدیت وضعیت تراکنش
                $stmt = pdo()->prepare("UPDATE transactions SET status = 'completed', ref_id = ?, verified_at = NOW() WHERE id = ?");
                $stmt->execute([$ref_id, $transaction['id']]);

                $metadata = json_decode($transaction['metadata'], true);
                
                // --- تشخیص هدف پرداخت ---
                if (isset($metadata['purpose']) && $metadata['purpose'] === 'complete_purchase') {
                    
                    $plan_id = $metadata['plan_id'];
                    $user_id = $metadata['user_id'];
                    $discount_code = $metadata['discount_code'] ?? null;
                    
                    $plan = getPlanById($plan_id);
                    $final_price = (float)$plan['price'];
                    $discount_applied = false;
                    $discount_object = null;

                    if ($discount_code) {
                        $stmt_discount = pdo()->prepare("SELECT * FROM discount_codes WHERE code = ?");
                        $stmt_discount->execute([$discount_code]);
                        $discount_object = $stmt_discount->fetch();
                        if ($discount_object) {
                             if ($discount_object['type'] == 'percent') {
                                $final_price = $plan['price'] - ($plan['price'] * $discount_object['value']) / 100;
                            } else {
                                $final_price = $plan['price'] - $discount_object['value'];
                            }
                            $final_price = max(0, $final_price);
                            $discount_applied = true;
                        }
                    }
                    
                    // شارژ موقت حساب کاربر برای کسر هزینه
                    updateUserBalance($user_id, $transaction['amount'], 'add');

                    
                    // نام دلخواه از متادیتا 
                    $custom_name = $metadata['custom_name'] ?? 'سرویس';
                    $purchase_result = completePurchase($user_id, $plan_id, $custom_name, $final_price, $discount_code, $discount_object, $discount_applied);

                    if ($purchase_result['success']) {
                        sendPhoto($user_id, $purchase_result['qr_code_url'], $purchase_result['caption'], $purchase_result['keyboard']);
                        sendMessage(ADMIN_CHAT_ID, $purchase_result['admin_notification']);
                        echo "<h1>پرداخت و خرید موفق</h1><p>سرویس شما با موفقیت ایجاد شد. لطفاً به ربات تلگرام بازگردید.</p>";
                    } else {
                         sendMessage($user_id, "❌ پرداخت شما موفق بود اما در ایجاد سرویس خطایی رخ داد. مبلغ پرداخت شده به موجودی شما اضافه شد. لطفاً با پشتیبانی تماس بگیرید.");
                         echo "<h1>خطا در ساخت سرویس</h1><p>پرداخت موفق بود اما سرویس ایجاد نشد. مبلغ به حساب شما اضافه شد.</p>";
                    }

                } else {
                    // پرداخت برای شارژ عادی حساب  
                    updateUserBalance($transaction['user_id'], $transaction['amount'], 'add');
                    $new_balance_data = getUserData($transaction['user_id']);
        
                    $message = "✅ پرداخت شما به مبلغ " . number_format($transaction['amount']) . " تومان با موفقیت انجام و حساب شما شارژ شد.\n\n" .
                               "▫️ شماره پیگیری: `{$ref_id}`\n" .
                               "💰 موجودی جدید: " . number_format($new_balance_data['balance']) . " تومان";
                    sendMessage($transaction['user_id'], $message);
        
                    echo "<h1>پرداخت موفق</h1><p>تراکنش شما با موفقیت انجام شد و حساب شما شارژ گردید. شماره پیگیری: {$ref_id}. لطفاً به ربات تلگرام بازگردید.</p>";
                }

            } else {
                // آپدیت وضعیت تراکنش به ناموفق
                $stmt = pdo()->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
                $stmt->execute([$transaction['id']]);
                $error_message = "خطا در وریفای تراکنش. کد خطا: " . $code;
                sendMessage($transaction['user_id'], "❌ تراکنش شما ناموفق بود. " . $error_message);
                echo "<h1>پرداخت ناموفق</h1><p>{$error_message}</p>";
            }
        } else {
            // خطایی در ارتباط با زرین‌پال رخ داده
            $error_message = "خطا در ارتباط با درگاه پرداخت.";
            sendMessage($transaction['user_id'], "❌ " . $error_message);
            echo "<h1>خطا</h1><p>{$error_message}</p>";
        }

    } else {
        // کاربر تراکنش را لغو کرده
        $stmt = pdo()->prepare("UPDATE transactions SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$transaction['id']]);
        sendMessage($transaction['user_id'], "❌ شما تراکنش را لغو کردید.");
        echo "<h1>تراکنش لغو شد</h1><p>شما عملیات پرداخت را لغو کردید. لطفاً به ربات بازگردید.</p>";
    }
}
