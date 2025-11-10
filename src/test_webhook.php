<?php
/**
 * فایل تست برای بررسی وضعیت Webhook
 */

require_once __DIR__ . '/includes/config.php';

echo "🔍 تست Webhook - Wizard Panel\n\n";

// 1. بررسی SECRET_TOKEN
echo "1️⃣ بررسی SECRET_TOKEN\n";
echo "SECRET_TOKEN تنظیم شده: " . (defined('SECRET_TOKEN') && !empty(SECRET_TOKEN) ? '✅ بله' : '❌ خیر') . "\n";
if (defined('SECRET_TOKEN')) {
    echo "مقدار SECRET_TOKEN: " . substr(SECRET_TOKEN, 0, 10) . "...\n";
}
echo "\n";

// 2. بررسی Header
echo "2️⃣ بررسی Header ها\n";
$secret_token_header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
echo "HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN: " . ($secret_token_header ? '✅ موجود' : '❌ موجود نیست') . "\n";
if ($secret_token_header) {
    echo "مقدار Header: " . substr($secret_token_header, 0, 10) . "...\n";
}
echo "\n";

// 3. بررسی تطابق
echo "3️⃣ بررسی تطابق SECRET_TOKEN\n";
if (defined('SECRET_TOKEN') && $secret_token_header) {
    if ($secret_token_header === SECRET_TOKEN) {
        echo "✅ SECRET_TOKEN با Header مطابقت دارد!\n";
    } else {
        echo "❌ SECRET_TOKEN با Header مطابقت ندارد!\n";
        echo "SECRET_TOKEN: " . substr(SECRET_TOKEN, 0, 20) . "...\n";
        echo "Header: " . substr($secret_token_header, 0, 20) . "...\n";
    }
} else {
    echo "⚠️ نمی‌توان تطابق را بررسی کرد (یکی از مقادیر موجود نیست)\n";
}
echo "\n";

// 4. بررسی Webhook از Telegram
echo "4️⃣ بررسی Webhook از Telegram\n";
$bot_token = defined('BOT_TOKEN') ? BOT_TOKEN : '';
if (empty($bot_token) || $bot_token === 'TOKEN') {
    echo "❌ BOT_TOKEN تنظیم نشده است!\n";
} else {
    $webhook_url = 'https://api.telegram.org/bot' . $bot_token . '/getWebhookInfo';
    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $data = json_decode($response, true);
        if ($data['ok']) {
            $info = $data['result'];
            echo "✅ Webhook Info:\n";
            echo "   URL: " . ($info['url'] ?? 'N/A') . "\n";
            echo "   Pending Updates: " . ($info['pending_update_count'] ?? 0) . "\n";
            echo "   Last Error Date: " . ($info['last_error_date'] ?? 'N/A') . "\n";
            echo "   Last Error Message: " . ($info['last_error_message'] ?? 'N/A') . "\n";
            echo "   Max Connections: " . ($info['max_connections'] ?? 'N/A') . "\n";
            
            // بررسی secret_token در URL
            if (isset($info['url'])) {
                $url_parts = parse_url($info['url']);
                if (isset($url_parts['query'])) {
                    parse_str($url_parts['query'], $query_params);
                    if (isset($query_params['secret_token'])) {
                        echo "   Secret Token در URL: ✅ موجود\n";
                    } else {
                        echo "   Secret Token در URL: ❌ موجود نیست\n";
                    }
                }
            }
        } else {
            echo "❌ خطا در دریافت اطلاعات Webhook: " . ($data['description'] ?? 'Unknown') . "\n";
        }
    } else {
        echo "❌ خطا در اتصال به Telegram API (HTTP Code: $http_code)\n";
    }
}
echo "\n";

// 5. بررسی دیتابیس
echo "5️⃣ بررسی دیتابیس\n";
try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = pdo();
    echo "✅ اتصال به دیتابیس موفق بود!\n";
    
    // بررسی وجود جدول settings
    $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
    if ($stmt->rowCount() > 0) {
        echo "✅ جدول settings وجود دارد\n";
    } else {
        echo "❌ جدول settings وجود ندارد!\n";
    }
} catch (Exception $e) {
    echo "❌ خطا در اتصال به دیتابیس: " . $e->getMessage() . "\n";
}
echo "\n";

// 6. بررسی فایل bot.php
echo "6️⃣ بررسی فایل bot.php\n";
if (file_exists(__DIR__ . '/bot.php')) {
    echo "✅ فایل bot.php موجود است\n";
    $bot_content = file_get_contents(__DIR__ . '/bot.php');
    if (strpos($bot_content, 'SECRET_TOKEN') !== false) {
        echo "✅ چک SECRET_TOKEN در bot.php موجود است\n";
    } else {
        echo "⚠️ چک SECRET_TOKEN در bot.php یافت نشد\n";
    }
} else {
    echo "❌ فایل bot.php موجود نیست!\n";
}
echo "\n";

// 7. پیشنهادات
echo "📋 پیشنهادات:\n";
if (!defined('SECRET_TOKEN') || empty(SECRET_TOKEN) || SECRET_TOKEN === 'SECRET') {
    echo "   1. SECRET_TOKEN را در config.php تنظیم کنید\n";
}
if (!$secret_token_header) {
    echo "   2. Webhook را با secret_token تنظیم کنید:\n";
    echo "      curl -X POST \"https://api.telegram.org/bot" . (defined('BOT_TOKEN') && BOT_TOKEN !== 'TOKEN' ? BOT_TOKEN : 'YOUR_BOT_TOKEN') . "/setWebhook\" \\\n";
    echo "           -H \"Content-Type: application/json\" \\\n";
    echo "           -d '{\"url\":\"https://yourdomain.com/bot.php\",\"secret_token\":\"" . (defined('SECRET_TOKEN') && SECRET_TOKEN !== 'SECRET' ? SECRET_TOKEN : 'YOUR_SECRET_TOKEN') . "\"}'\n";
}
echo "\n";

echo "✅ تست کامل شد!\n";

