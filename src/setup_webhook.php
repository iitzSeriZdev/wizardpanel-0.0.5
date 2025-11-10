<?php
/**
 * فایل تنظیم Webhook با SECRET_TOKEN
 * این فایل را یکبار اجرا کنید تا webhook تنظیم شود
 */

require_once __DIR__ . '/includes/config.php';

// بررسی تنظیمات
if (!defined('BOT_TOKEN') || BOT_TOKEN === 'TOKEN') {
    die("❌ خطا: BOT_TOKEN در config.php تنظیم نشده است!\n");
}

if (!defined('SECRET_TOKEN') || SECRET_TOKEN === 'SECRET') {
    die("❌ خطا: SECRET_TOKEN در config.php تنظیم نشده است!\n");
}

// دریافت URL وبسایت
// اگر از طریق command line اجرا می‌شود، از پارامتر استفاده کن
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $webhook_url = $argv[1];
} else {
    $webhook_url = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/bot.php';
}

echo "🔧 تنظیم Webhook برای ربات\n\n";
echo "Bot Token: " . substr(BOT_TOKEN, 0, 10) . "...\n";
echo "Secret Token: " . substr(SECRET_TOKEN, 0, 10) . "...\n";
echo "Webhook URL: $webhook_url\n\n";

// اگر از command line اجرا می‌شود، URL را از کاربر بگیر
if (php_sapi_name() === 'cli' && !isset($argv[1])) {
    echo "⚠️ لطفا URL وبسایت خود را وارد کنید:\n";
    echo "مثال: https://serizdl.ir/WizardPanleTest/bot.php\n";
    echo "یا URL را به عنوان پارامتر وارد کنید: php setup_webhook.php https://yourdomain.com/bot.php\n";
    exit(1);
}

// تنظیم Webhook
$set_webhook_url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/setWebhook';
$data = [
    'url' => $webhook_url,
    'secret_token' => SECRET_TOKEN,
    'drop_pending_updates' => true
];

$ch = curl_init($set_webhook_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    die("❌ خطا در اتصال: $curl_error\n");
}

$result = json_decode($response, true);

if ($http_code === 200 && isset($result['ok']) && $result['ok']) {
    echo "✅ Webhook با موفقیت تنظیم شد!\n\n";
    
    // دریافت اطلاعات Webhook
    $get_webhook_url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/getWebhookInfo';
    $ch = curl_init($get_webhook_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $webhook_info = curl_exec($ch);
    curl_close($ch);
    
    $webhook_data = json_decode($webhook_info, true);
    if ($webhook_data['ok']) {
        $info = $webhook_data['result'];
        echo "📋 اطلاعات Webhook:\n";
        echo "   URL: " . ($info['url'] ?? 'N/A') . "\n";
        echo "   Pending Updates: " . ($info['pending_update_count'] ?? 0) . "\n";
        echo "   Max Connections: " . ($info['max_connections'] ?? 'N/A') . "\n";
        if (isset($info['last_error_message'])) {
            echo "   Last Error: " . $info['last_error_message'] . "\n";
        }
    }
    
    echo "\n✅ حالا ربات شما آماده استفاده است!\n";
    echo "⚠️ بعد از تست، این فایل را حذف کنید!\n";
} else {
    echo "❌ خطا در تنظیم Webhook:\n";
    echo "HTTP Code: $http_code\n";
    if (isset($result['description'])) {
        echo "Error: " . $result['description'] . "\n";
    } else {
        echo "Response: $response\n";
    }
}

