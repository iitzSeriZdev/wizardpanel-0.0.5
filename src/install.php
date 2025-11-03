<?php
// --- بخش حذف خودکار ---
if (isset($_GET['action']) && $_GET['action'] === 'self_delete') {
    if (file_exists(__FILE__) && is_writable(__FILE__)) {
        unlink(__FILE__);
    }
    exit();
}

// برای جلوگیری از نمایش خطاها به کاربر نهایی در محیط عملیاتی
error_reporting(0);
ini_set('display_errors', 0);

// --- متغیرهای اولیه ---
$configFile = __DIR__ . '/includes/config.php';
$botFileUrl = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/bot.php';

$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;
$errors = [];
$successMessages = [];

// --- داده‌های فرم ---
$bot_token = trim($_POST['bot_token'] ?? '');
$admin_id = trim($_POST['admin_id'] ?? '');

function generateRandomString(int $length = 32): string {
    return bin2hex(random_bytes($length / 2));
}

function getDbBaseSchemaSQL(): string {
    return "
    CREATE TABLE IF NOT EXISTS `users` ( `chat_id` BIGINT NOT NULL, `first_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci, `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `user_state` VARCHAR(255) DEFAULT 'main_menu', `state_data` TEXT, `status` VARCHAR(20) NOT NULL DEFAULT 'active', `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`chat_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `admins` ( `chat_id` BIGINT NOT NULL PRIMARY KEY, `first_name` VARCHAR(255), `permissions` TEXT, `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `categories` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(255) NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `servers` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(255) NOT NULL, `url` VARCHAR(255) NOT NULL, `username` VARCHAR(255) NOT NULL, `password` VARCHAR(255) NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `plans` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `server_id` INT NOT NULL, `category_id` INT NOT NULL, `name` VARCHAR(255) NOT NULL, `price` DECIMAL(10,2) NOT NULL, `volume_gb` INT NOT NULL, `duration_days` INT NOT NULL, `description` TEXT, `show_sub_link` TINYINT(1) NOT NULL DEFAULT 1, `show_conf_links` TINYINT(1) NOT NULL DEFAULT 1, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `services` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `server_id` INT NOT NULL, `owner_chat_id` BIGINT NOT NULL, `marzban_username` VARCHAR(255) NOT NULL, `custom_name` VARCHAR(255) NULL DEFAULT NULL, `plan_id` INT NOT NULL, `sub_url` TEXT, `purchase_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `expire_timestamp` BIGINT, `volume_gb` INT, `warning_sent` TINYINT(1) NOT NULL DEFAULT 0 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `settings` ( `setting_key` VARCHAR(255) NOT NULL PRIMARY KEY, `setting_value` TEXT ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `tickets` ( `id` VARCHAR(50) NOT NULL PRIMARY KEY, `user_id` BIGINT NOT NULL, `user_name` VARCHAR(255), `subject` VARCHAR(255), `status` VARCHAR(20) NOT NULL DEFAULT 'open', `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `ticket_conversations` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `ticket_id` VARCHAR(50) NOT NULL, `sender` VARCHAR(10) NOT NULL, `message_text` TEXT, `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `cache` ( `cache_key` VARCHAR(255) NOT NULL PRIMARY KEY, `cache_value` TEXT, `expire_at` INT ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `discount_codes` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `code` VARCHAR(50) NOT NULL UNIQUE, `type` VARCHAR(10) NOT NULL, `value` DECIMAL(10,2) NOT NULL, `max_usage` INT NOT NULL, `usage_count` INT NOT NULL DEFAULT 0, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `guides` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `button_name` VARCHAR(255) NOT NULL, `content_type` VARCHAR(10) NOT NULL, `message_text` TEXT, `photo_id` VARCHAR(255) DEFAULT NULL, `inline_keyboard` TEXT, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `payment_requests` ( 
  `id` INT AUTO_INCREMENT PRIMARY KEY, 
  `user_id` BIGINT NOT NULL, 
  `amount` DECIMAL(10,2) NOT NULL, 
  `photo_file_id` VARCHAR(255) NOT NULL, 
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending', 
  `metadata` TEXT DEFAULT NULL, 
  `processed_by_admin_id` BIGINT, 
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
  `processed_at` TIMESTAMP NULL 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `renewal_requests` ( `id` int(11) NOT NULL AUTO_INCREMENT, `user_id` bigint(20) NOT NULL, `service_username` varchar(255) NOT NULL, `days_to_add` int(11) NOT NULL, `gb_to_add` int(11) NOT NULL, `total_cost` decimal(10,2) NOT NULL, `status` varchar(20) NOT NULL DEFAULT 'pending', `photo_file_id` varchar(255) DEFAULT NULL, `processed_by_admin_id` bigint(20) DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, `processed_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `authority` varchar(50) NOT NULL,
  `ref_id` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `metadata` TEXT DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `authority` (`authority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tableName` LIKE ?");
        $stmt->execute([$columnName]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function runDbUpgrades(PDO $pdo): array {
    $messages = [];

    if (columnExists($pdo, 'users', 'state') && !columnExists($pdo, 'users', 'user_state')) {
        $pdo->exec("ALTER TABLE `users` CHANGE `state` `user_state` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'main_menu';");
        $messages[] = "✅ ستون `state` در جدول `users` به `user_state` تغییر نام یافت.";
    }

    // --- ارتقا برای پشتیبانی از چند پنل ---
    if (!columnExists($pdo, 'servers', 'type')) {
        $pdo->exec("ALTER TABLE `servers` ADD `type` VARCHAR(20) NOT NULL DEFAULT 'marzban' AFTER `password`;");
        $messages[] = "✅ ستون `type` برای پشتیبانی از چند نوع پنل به جدول `servers` اضافه شد.";
    }
    if (!columnExists($pdo, 'plans', 'inbound_id')) {
        $pdo->exec("ALTER TABLE `plans` ADD `inbound_id` INT NULL DEFAULT NULL AFTER `category_id`;");
        $messages[] = "✅ ستون `inbound_id` برای پنل سنایی به جدول `plans` اضافه شد.";
    }
    if (!columnExists($pdo, 'plans', 'marzneshin_service_id')) {
        $pdo->exec("ALTER TABLE `plans` ADD `marzneshin_service_id` INT NULL DEFAULT NULL AFTER `inbound_id`;");
        $messages[] = "✅ ستون `marzneshin_service_id` برای پنل مرزنشین به جدول `plans` اضافه شد.";
    }
    if (!columnExists($pdo, 'services', 'sanaei_inbound_id')) {
        $pdo->exec("ALTER TABLE `services` ADD `sanaei_inbound_id` INT NULL DEFAULT NULL AFTER `volume_gb`;");
        $messages[] = "✅ ستون `sanaei_inbound_id` برای پنل سنایی به جدول `services` اضافه شد.";
    }
    if (!columnExists($pdo, 'services', 'sanaei_uuid')) {
        $pdo->exec("ALTER TABLE `services` ADD `sanaei_uuid` VARCHAR(255) NULL DEFAULT NULL AFTER `sanaei_inbound_id`;");
        $messages[] = "✅ ستون `sanaei_uuid` برای پنل سنایی به جدول `services` اضافه شد.";
    }
    
    // --- ارتقاهای مربوط به اعلان‌ها و ردیابی کاربران ---
    if (!columnExists($pdo, 'users', 'last_seen_at')) {
        $pdo->exec("ALTER TABLE `users` ADD `last_seen_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`;");
        $messages[] = "✅ ستون `last_seen_at` برای ردیابی آخرین فعالیت کاربران اضافه شد.";
    }
    if (!columnExists($pdo, 'users', 'reminder_sent')) {
        $pdo->exec("ALTER TABLE `users` ADD `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_seen_at`;");
        $messages[] = "✅ ستون `reminder_sent` برای ارسال یادآور عدم فعالیت اضافه شد.";
    }
    if (!columnExists($pdo, 'services', 'warning_sent')) {
        $pdo->exec("ALTER TABLE `services` ADD `warning_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `volume_gb`;");
        $messages[] = "✅ ستون `warning_sent` برای ارسال هشدار انقضا به جدول `services` اضافه شد.";
    }
    if (!columnExists($pdo, 'users', 'test_config_count')) {
        $pdo->exec("ALTER TABLE `users` ADD `test_config_count` INT NOT NULL DEFAULT 0 AFTER `status`;");
        $messages[] = "✅ ستون `test_config_count` برای کانفیگ تست به جدول `users` اضافه شد.";
    }
    if (!columnExists($pdo, 'plans', 'is_test_plan')) {
        $pdo->exec("ALTER TABLE `plans` ADD `is_test_plan` TINYINT(1) NOT NULL DEFAULT 0 AFTER `show_conf_links`;");
        $messages[] = "✅ ستون `is_test_plan` برای کانفیگ تست به جدول `plans` اضافه شد.";
    }
    if (!columnExists($pdo, 'plans', 'purchase_limit')) {
        $pdo->exec("ALTER TABLE `plans` ADD `purchase_limit` INT NOT NULL DEFAULT 0 AFTER `is_test_plan`;");
        $messages[] = "✅ ستون `purchase_limit` برای محدودیت خرید پلن‌ها اضافه شد.";
    }
    if (!columnExists($pdo, 'plans', 'purchase_count')) {
        $pdo->exec("ALTER TABLE `plans` ADD `purchase_count` INT NOT NULL DEFAULT 0 AFTER `purchase_limit`;");
        $messages[] = "✅ ستون `purchase_count` برای شمارش خرید پلن‌ها اضافه شد.";
    }
    if (!columnExists($pdo, 'users', 'is_verified')) {
        $pdo->exec("ALTER TABLE `users` ADD `is_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `test_config_count`;");
        $messages[] = "✅ ستون `is_verified` برای وضعیت احراز هویت کاربران اضافه شد.";
    }
    if (!columnExists($pdo, 'users', 'phone_number')) {
        $pdo->exec("ALTER TABLE `users` ADD `phone_number` VARCHAR(20) NULL DEFAULT NULL AFTER `is_verified`;");
        $messages[] = "✅ ستون `phone_number` برای ذخیره شماره تلفن کاربران اضافه شد.";
    }
    if (!columnExists($pdo, 'admins', 'is_super_admin')) {
        $pdo->exec("ALTER TABLE `admins` ADD `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0;");
        $messages[] = "✅ ستون `is_super_admin` برای مدیریت ادمین اصلی اضافه شد.";
    }
    if (!columnExists($pdo, 'users', 'inline_keyboard')) {
        $pdo->exec("ALTER TABLE `users` ADD `inline_keyboard` TINYINT(1) NOT NULL DEFAULT 0;");
        $messages[] = "✅ ستون `inline_keyboard` برای مدیریت نوع کیبورد کاربران اضافه شد.";
    }
    if (!columnExists($pdo, 'servers', 'sub_host')) {
        $pdo->exec("ALTER TABLE `servers` ADD `sub_host` VARCHAR(255) NULL DEFAULT NULL AFTER `url`;");
        $messages[] = "✅ ستون `sub_host` برای لینک اشتراک سفارشی به جدول `servers` اضافه شد.";
    }
    if (!columnExists($pdo, 'servers', 'marzban_protocols')) {
        $pdo->exec("ALTER TABLE `servers` ADD `marzban_protocols` VARCHAR(255) NULL DEFAULT NULL AFTER `sub_host`;");
        $messages[] = "✅ ستون `marzban_protocols` برای تنظیم پروتکل‌های مرزبان اضافه شد.";
    }
    if (!columnExists($pdo, 'services', 'custom_name')) {
        $pdo->exec("ALTER TABLE `services` ADD `custom_name` VARCHAR(255) NULL DEFAULT NULL AFTER `marzban_username`;");
        $messages[] = "✅ ستون `custom_name` برای نام دلخواه سرویس به جدول `services` اضافه شد.";
    }

    return $messages;
}

// --- مدیریت منطق مراحل ---
if ($step === 2) {
    if (empty($bot_token)) $errors[] = 'توکن ربات الزامی است.';
    if (empty($admin_id) || !is_numeric($admin_id)) $errors[] = 'آیدی عددی ادمین الزامی و باید عدد باشد.';
    if (!empty($errors)) $step = 1;
}
elseif ($step === 3) {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = trim($_POST['db_pass'] ?? '');

    if (empty($db_name)) $errors[] = 'نام دیتابیس الزامی است.';
    if (empty($db_user)) $errors[] = 'نام کاربری دیتابیس الزامی است.';
    
    if (empty($errors)) {
        if (!is_dir(__DIR__ . '/includes')) @mkdir(__DIR__ . '/includes', 0755, true);
        if (!file_exists($configFile)) @file_put_contents($configFile, "<?php" . PHP_EOL);
        
        if (!is_writable($configFile)) $errors[] = 'فایل کانفیگ قابل نوشتن نیست! لطفاً دسترسی (Permission) فایل includes/config.php را روی 666 یا 777 تنظیم کنید.';
    }

    if (empty($errors)) {
        try {
            $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
            $pdo->exec("USE `$db_name`");
            $successMessages[] = "✅ اتصال به دیتابیس برقرار و دیتابیس `{$db_name}` بررسی/ایجاد شد.";

            $pdo->exec(getDbBaseSchemaSQL());
            $successMessages[] = "✅ ساختار پایه جداول با موفقیت ایجاد/بررسی شد.";
            
            $secretToken = generateRandomString(64);
            $config_content = '<?php' . PHP_EOL . PHP_EOL;
            $config_content .= "define('DB_HOST', '{$db_host}');" . PHP_EOL;
            $config_content .= "define('DB_NAME', '{$db_name}');" . PHP_EOL;
            $config_content .= "define('DB_USER', '{$db_user}');" . PHP_EOL;
            $config_content .= "define('DB_PASS', '{$db_pass}');" . PHP_EOL . PHP_EOL;
            $config_content .= "define('BOT_TOKEN', '{$bot_token}');" . PHP_EOL;
            $config_content .= "define('ADMIN_CHAT_ID', {$admin_id});" . PHP_EOL;
            $config_content .= "define('SECRET_TOKEN', '{$secretToken}');" . PHP_EOL;
            file_put_contents($configFile, $config_content);
            $successMessages[] = "✅ فایل کانفیگ با موفقیت ایجاد شد.";
            
            $upgradeMessages = runDbUpgrades($pdo);
            if (!empty($upgradeMessages)) {
                $successMessages = array_merge($successMessages, $upgradeMessages);
            } else {
                $successMessages[] = "ℹ️ دیتابیس شما از قبل کاملاً به‌روز بود.";
            }

            $pdo->exec("INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
                ('bot_status', 'on'), ('sales_status', 'on'), ('join_channel_status', 'off'), ('join_channel_id', '@'),
                ('welcome_gift_balance', '0'), ('inline_keyboard', 'on'), ('verification_method', 'off'),
                ('verification_iran_only', 'off'), ('test_config_usage_limit', '1'), ('notification_expire_status', 'off'),
                ('notification_expire_days', '3'), ('notification_expire_gb', '1'), ('notification_inactive_status', 'off'),
                ('notification_inactive_days', '30'),
                ('renewal_status', 'off'), ('renewal_price_per_day', '1000'), ('renewal_price_per_gb', '2000'), ('payment_gateway_status', 'off'), ('zarinpal_merchant_id', '');");
            $successMessages[] = "✅ تنظیمات پیش‌فرض با موفقیت افزوده شد.";
            
            $apiUrl = "https://api.telegram.org/bot$bot_token/setWebhook?secret_token=$secretToken&url=" . urlencode($botFileUrl);
            $response = @file_get_contents($apiUrl);
            $response_data = json_decode($response, true);
            
            if (!$response || !$response_data['ok']) {
                $errors[] = 'خطا در ثبت وبهوک: ' . ($response_data['description'] ?? 'پاسخ نامعتبر از تلگرام. از صحت توکن مطمئن شوید.');
            } else {
                $successMessages[] = "✅ وبهوک با موفقیت در تلگرام ثبت شد.";
                $successMessages[] = "🎉 نصب/ارتقا با موفقیت به پایان رسید!";
            }

        } catch (PDOException $e) {
            $errors[] = "خطا در اتصال به دیتابیس یا اجرای کوئری‌ها: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نصب و راه‌اندازی ربات</title>
    <link href="https://cdn.jsdelivr.net/npm/vazirmatn@33.0.3/Vazirmatn-font-face.css" rel="stylesheet" type="text/css">
    <style>
        :root {
            --bg-main: #0a0e1a; --bg-container: #1e293b; --bg-input: #111827;
            --primary: #8b5cf6; --primary-hover: #7c3aed; --active: #2dd4bf;
            --success: #10b981; --danger: #ef4444; --warning: #f59e0b;
            --text-light: #f8fafc; --text-muted: #94a3b8;
            --border-color: rgba(148, 163, 184, 0.2); --shadow-color: rgba(0, 0, 0, 0.5);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: Vazirmatn, sans-serif; }
        body {
            background-color: var(--bg-main);
            background-image: url('data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"%3E%3Cg fill-rule="evenodd"%3E%3Cg fill="%231e293b" fill-opacity="0.2"%3E%3Cpath d="M0 38.59l2.83-2.83 1.41 1.41L1.41 40H0v-1.41zM0 1.4l2.83 2.83 1.41-1.41L1.41 0H0v1.41zM38.59 40l-2.83-2.83 1.41-1.41L40 38.59V40h-1.41zM40 1.41l-2.83 2.83-1.41-1.41L38.59 0H40v1.41zM20 18.6l2.83-2.83 1.41 1.41L21.41 20l2.83 2.83-1.41 1.41L20 21.41l-2.83 2.83-1.41-1.41L18.59 20l-2.83-2.83 1.41-1.41L20 18.59z"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 20px; color: var(--text-light);
        }
        .container {
            width: 100%; max-width: 700px; background: var(--bg-container);
            border-radius: 20px; border: 1px solid var(--border-color);
            box-shadow: 0 10px 40px var(--shadow-color); padding: 40px;
        }
        .header h1 { text-align: center; font-size: 2rem; font-weight: 700; margin-bottom: 40px; }
        .progress-steps { display: flex; justify-content: space-between; position: relative; margin-bottom: 50px; padding: 0 10px; }
        .progress-line { position: absolute; top: 20px; transform: translateY(-50%); height: 4px; border-radius: 4px; right: 40px; left: 40px; }
        .progress-line-bg { background-color: var(--border-color); width: 90%; }
        .progress-line-fg { background-color: var(--active); transition: width 0.4s ease-in-out, background-color 0.4s ease-in-out; }
        .progress-line-fg.completed-install { background-color: var(--success); }
        .step { display: flex; flex-direction: column; align-items: center; position: relative; z-index: 10; }
        .step-icon {
            width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            background-color: var(--bg-container); border: 2px solid var(--border-color);
            color: var(--text-muted); font-weight: 700; margin-bottom: 10px; transition: all 0.3s ease;
        }
        .step.active .step-icon { background-color: var(--active); border-color: var(--active); color: var(--bg-input); }
        .step.completed .step-icon { background-color: var(--success); border-color: var(--success); color: white; }
        .step-label { font-size: 0.9rem; font-weight: 500; color: var(--text-muted); }
        .step.active .step-label { color: var(--text-light); }
        .form-area, .result-area { background: rgba(0,0,0,0.1); border: 1px solid var(--border-color); border-radius: 12px; padding: 30px; }
        .section-title { font-weight: 600; font-size: 1.25rem; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); }
        .form-group { margin-bottom: 25px; }
        label { display: block; margin-bottom: 10px; color: var(--text-muted); }
        input[type="text"], input[type="password"] {
            width: 100%; padding: 14px; background: var(--bg-input); border: 1px solid var(--border-color);
            border-radius: 8px; color: var(--text-light); font-size: 1rem; transition: border-color 0.3s, box-shadow 0.3s;
        }
        input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.3); outline: none; }
        .example-text { font-size: 0.85rem; color: var(--text-muted); margin-top: 8px; }
        .btn {
            width: 100%; padding: 15px; color: white; border: none; border-radius: 8px; font-size: 1.1rem;
            font-weight: 600; cursor: pointer; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2); transition: transform 0.2s, box-shadow 0.2s;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover)); position: relative; overflow: hidden;
        }
        .btn::before {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent); transition: left 0.8s ease-in-out;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3); }
        .btn:hover::before { left: 100%; }
        .webhook-info {
            padding: 15px; border-radius: 8px; margin-bottom: 30px; border-right: 4px solid var(--active);
            background-color: rgba(45, 212, 191, 0.1);
        }
        .webhook-info code { display: block; direction: ltr; text-align: left; word-break: break-all; margin-top: 8px; color: var(--active); }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; border-right-width: 4px; border-right-style: solid; }
        .alert ul { list-style-type: none; padding: 0; margin-top: 10px; }
        .alert li { margin-bottom: 5px; font-size: 0.9rem;}
        .alert-success { background-color: rgba(16, 185, 129, 0.1); border-right-color: var(--success); color: #a7f3d0; }
        .alert-danger { background-color: rgba(239, 68, 68, 0.1); border-right-color: var(--danger); color: #fca5a5; }
        .alert-warning { background-color: rgba(245, 158, 11, 0.1); border-right-color: var(--warning); color: #fcd34d; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>نصب و راه‌اندازی ربات تلگرام</h1>
    </div>
    
    <div class="content">
        <!-- Progress Bar -->
        <div class="progress-steps">
            <div class="progress-line progress-line-bg"></div>
            <?php
                $progress_width = '0%';
                if ($step === 2) $progress_width = '45%';
                if ($step === 3) $progress_width = '90%';
                $install_complete_class = ($step === 3 && empty($errors)) ? 'completed-install' : '';
            ?>
            <div class="progress-line progress-line-fg <?php echo $install_complete_class; ?>" style="width: <?php echo $progress_width; ?>;"></div>
            
            <div class="step <?php if($step > 1 || ($step==3 && empty($errors))) echo 'completed'; if($step==1) echo 'active'; ?>">
                <div class="step-icon">۱</div>
                <div class="step-label">اطلاعات ربات</div>
            </div>
            <div class="step <?php if($step > 2 || ($step==3 && empty($errors))) echo 'completed'; if($step==2) echo 'active'; ?>">
                <div class="step-icon">۲</div>
                <div class="step-label">دیتابیس</div>
            </div>
            <div class="step <?php if($step==3) echo 'active'; if($step==3 && empty($errors)) echo 'completed'; ?>">
                <div class="step-icon">۳</div>
                <div class="step-label">پایان نصب</div>
            </div>
        </div>

        <?php if (!empty($errors) && $step !== 3): // Show errors on step 1 & 2 if they exist ?>
            <div class="alert alert-danger">
                <strong>خطا!</strong>
                <ul><?php foreach ($errors as $error) echo "<li>- " . htmlspecialchars($error) . "</li>"; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <div class="webhook-info">
                <strong>آدرس وبهوک شما:</strong>
                <code><?php echo htmlspecialchars($botFileUrl); ?></code>
            </div>
            <div class="form-area">
                <div class="section-title">مرحله ۱: اطلاعات ربات تلگرام</div>
                <form action="" method="post">
                    <input type="hidden" name="step" value="2">
                    <div class="form-group">
                        <label for="bot_token">توکن ربات (Bot Token)</label>
                        <input type="text" id="bot_token" name="bot_token" value="<?php echo htmlspecialchars($bot_token); ?>" required>
                        <p class="example-text">مثال: 123456789:ABCdefGHIjklMnOpQRstUvWxYz</p>
                    </div>
                    <div class="form-group">
                        <label for="admin_id">آیدی عددی ادمین اصلی</label>
                        <input type="text" id="admin_id" name="admin_id" value="<?php echo htmlspecialchars($admin_id); ?>" required>
                        <p class="example-text">مثال: 123456789</p>
                    </div>
                    <button type="submit" class="btn">ادامه به مرحله بعد</button>
                </form>
            </div>
        <?php elseif ($step === 2): ?>
            <div class="form-area">
                <div class="section-title">مرحله ۲: تنظیمات پایگاه داده</div>
                <form action="" method="post">
                    <input type="hidden" name="step" value="3">
                    <input type="hidden" name="bot_token" value="<?php echo htmlspecialchars($bot_token); ?>">
                    <input type="hidden" name="admin_id" value="<?php echo htmlspecialchars($admin_id); ?>">
                    <div class="form-group">
                        <label for="db_host">هاست دیتابیس</label>
                        <input type="text" id="db_host" name="db_host" value="localhost">
                    </div>
                    <div class="form-group">
                        <label for="db_name">نام دیتابیس</label>
                        <input type="text" id="db_name" name="db_name" required>
                    </div>
                    <div class="form-group">
                        <label for="db_user">نام کاربری دیتابیس</label>
                        <input type="text" id="db_user" name="db_user" required>
                    </div>
                    <div class="form-group">
                        <label for="db_pass">رمز عبور دیتابیس</label>
                        <input type="password" id="db_pass" name="db_pass">
                    </div>
                    <button type="submit" class="btn">نصب و راه‌اندازی</button>
                </form>
            </div>
        <?php elseif ($step === 3): ?>
            <div class="result-area">
                 <?php if (empty($errors)): ?>
                    <div class="alert alert-success">
                        <strong>نصب با موفقیت به پایان رسید!</strong>
                        <ul><?php foreach ($successMessages as $msg) echo "<li>" . $msg . "</li>"; ?></ul>
                    </div>
                    <div class="alert alert-warning">
                        <strong>مهم:</strong> این فایل جهت افزایش امنیت تا چند ثانیه دیگر <strong>به صورت خودکار حذف خواهد شد</strong>.
                    </div>
                <?php else: // This block will show if there were errors in step 3 ?>
                    <div class="alert alert-danger">
                        <strong>نصب با خطا مواجه شد!</strong>
                        <ul><?php foreach ($errors as $error) echo "<li>- " . htmlspecialchars($error) . "</li>"; ?></ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($step === 3 && empty($errors)): ?>
<script>
    setTimeout(function () {
        fetch('?action=self_delete')
            .then(function() {
                document.querySelector('.content').innerHTML = `
                    <div class="alert alert-success">
                        <strong>فایل نصب با موفقیت حذف شد.</strong>
                        <p style="margin-top:10px;">اکنون می‌توانید با خیال راحت این صفحه را ببندید.</p>
                    </div>`;
            })
            .catch(function(error) { console.error('خطا در حذف خودکار فایل:', error); });
    }, 5000); // 5-second delay
</script>
<?php endif; ?>

</body>
</html>