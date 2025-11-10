<?php

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}

// --- فراخوانی فایل‌های مورد نیاز ---
require_once __DIR__ . '/includes/config.php';

// بررسی SECRET_TOKEN - با logging برای debug
$received_token = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
$expected_token = defined('SECRET_TOKEN') ? SECRET_TOKEN : '';

// اگر SECRET_TOKEN تنظیم نشده باشد، اجازه می‌دهیم (برای backward compatibility)
if (!empty($expected_token) && $expected_token !== 'SECRET') {
    if ($received_token !== $expected_token) {
        // Log error برای debug (فقط در صورت وجود کلاس Logger)
        if (file_exists(__DIR__ . '/includes/Logger.php')) {
            require_once __DIR__ . '/includes/Logger.php';
            if (class_exists('Logger')) {
                Logger::getInstance()->error('SECRET_TOKEN mismatch', [
                    'received' => substr($received_token, 0, 10) . '...',
                    'expected' => substr($expected_token, 0, 10) . '...'
                ]);
            }
        }
        // همچنین در error log هم بنویس
        error_log("Wizard Panel: SECRET_TOKEN mismatch. Received: " . substr($received_token, 0, 10) . ", Expected: " . substr($expected_token, 0, 10));
        die;
    }
} else if (empty($expected_token) || $expected_token === 'SECRET') {
    // اگر SECRET_TOKEN تنظیم نشده، warning می‌دهیم اما اجازه می‌دهیم
    error_log("Wizard Panel: WARNING - SECRET_TOKEN is not set or is default value. Webhook is not secure!");
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// ---------------------------------------------------------------------
// ---                     شروع منطق اصلی ربات                         ---
// ---------------------------------------------------------------------

$apiRequest = false;
$oneTimeEdit = true;
$update = json_decode(file_get_contents('php://input'), true);

if (!$update) {
    die;
}

// --- آماده‌سازی متغیرهای اولیه ---
$isAnAdmin = false;
$chat_id = null;
$user_data = null;
$user_state = 'none';
$first_name = 'کاربر';

if (isset($update['callback_query'])) {
    $chat_id = $update['callback_query']['message']['chat']['id'];
    $first_name = $update['callback_query']['from']['first_name'];
}
elseif (isset($update['message']['chat']['id'])) {
    $chat_id = $update['message']['chat']['id'];
    $first_name = $update['message']['from']['first_name'];
}

if ($chat_id) {
    $isAnAdmin = isUserAdmin($chat_id);
    $user_data = getUserData($chat_id, $first_name);
    $user_state = $user_data['state'] ?? 'none';
    $settings = getSettings();

    define('USER_INLINE_KEYBOARD', $settings['inline_keyboard'] === 'on');

    // --- بررسی ضد اسپم (فقط برای کاربران عادی) ---
    if (!$isAnAdmin && file_exists(__DIR__ . '/includes/AntiSpam.php')) {
        require_once __DIR__ . '/includes/AntiSpam.php';
        if (class_exists('AntiSpam')) {
            $antiSpam = AntiSpam::getInstance();
            $actionType = isset($update['callback_query']) ? 'callback' : 'message';
            $spamCheck = $antiSpam->checkAndHandle($chat_id, $actionType);
            
            if (!$spamCheck['allowed']) {
                if ($spamCheck['message']) {
                    sendMessage($chat_id, $spamCheck['message']);
                }
                die; // توقف پردازش
            }
        }
    }

    // --- بررسی‌های اولیه (وضعیت ربات، مسدود بودن، عضویت در کانال) ---
    if ($settings['bot_status'] === 'off' && !$isAnAdmin) {
        sendMessage($chat_id, "🛠 ربات در حال حاضر در دست تعمیر است. لطفا بعدا مراجعه کنید.");
        die;
    }
    if (($user_data['status'] ?? 'active') === 'banned') {
        sendMessage($chat_id, "🚫 شما توسط ادمین از ربات مسدود شده‌اید.");
        die;
    }

    if (!$isAnAdmin && !checkJoinStatus($chat_id)) {
        $channel_id = str_replace('@', '', $settings['join_channel_id']);
        $message = "💡 کاربر گرامی برای استفاده از ربات ابتدا باید در کانال ما عضو شوید.";

        $keyboard = ['inline_keyboard' => [[['text' => ' عضویت در کانال 📢', 'url' => "https://t.me/{$channel_id}"]], [['text' => '✅ عضو شدم', 'callback_data' => 'check_join']]]];
        sendMessage($chat_id, $message, $keyboard);
        die;
    }
}

$cancelKeyboard = ['keyboard' => [[['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];

// ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~
// ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ پردازش CALLBACK QUERY ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~
// ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~
if (isset($update['callback_query'])) {
    $callback_id = $update['callback_query']['id'];
    $data = $update['callback_query']['data'];
    $message_id = $update['callback_query']['message']['message_id'];
    $from_id = $update['callback_query']['from']['id'];
    $first_name = $update['callback_query']['from']['first_name'];

    if ($data === 'check_join') {
        if (checkJoinStatus($chat_id)) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            deleteMessage($chat_id, $message_id);
            handleMainMenu($chat_id, $first_name, true);
        }
        else {
            apiRequest('answerCallbackQuery', [
                'callback_query_id' => $callback_id,
                'text' => '❌ شما هنوز در کانال عضو نشده‌اید!',
                'show_alert' => true,
            ]);
        }
        die;
    }

    if ($data === 'verify_by_button') {
        $stmt = pdo()->prepare("UPDATE users SET is_verified = 1 WHERE chat_id = ?");
        $stmt->execute([$chat_id]);

        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        editMessageText($chat_id, $message_id, "✅ هویت شما با موفقیت تایید شد. خوش آمدید!");
        handleMainMenu($chat_id, $first_name);
        die;
    }

    $is_verified = $user_data['is_verified'] ?? 0;
    $verification_method = $settings['verification_method'] ?? 'off';

    if ($verification_method !== 'off' && !$is_verified && !$isAnAdmin) {
        apiRequest('answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'text' => 'برای استفاده از دکمه‌ها، ابتدا باید هویت خود را تایید کنید.',
            'show_alert' => true,
        ]);
        die;
    }
    
    // --- مدیریت پرداخت مستقیم برای خرید پلن ---
    if (strpos($data, 'charge_plan_custom_') === 0) {
        // پرداخت آنلاین برای پلن قابل تنظیم
        // فرمت: charge_plan_custom_{gateway}_{amount}_{plan_id}_{volume}_{duration}_{name}_{discount}
        $parts = explode('_', $data);
        $gateway = $parts[3] ?? 'zarinpal'; // zarinpal, idpay, nextpay, zibal, newpayment, aqayepardakht
        $amount_to_charge = (int)$parts[4];
        $plan_id_to_buy = (int)$parts[5];
        $custom_volume_encoded = $parts[6] ?? '';
        $custom_duration_encoded = $parts[7] ?? '';
        $custom_name_encoded = $parts[8] ?? '';
        $discount_code_to_use = (isset($parts[9]) && !empty($parts[9])) ? $parts[9] : null;
        
        $custom_volume = (int)base64_decode($custom_volume_encoded);
        $custom_duration = (int)base64_decode($custom_duration_encoded);
        $custom_name = base64_decode($custom_name_encoded);

        $description = "تکمیل خرید پلن قابل تنظیم #{$plan_id_to_buy}";
        $metadata = [
            "purpose" => "complete_purchase",
            "plan_id" => $plan_id_to_buy,
            "user_id" => $chat_id,
            "custom_name" => $custom_name,
            "custom_volume_gb" => $custom_volume,
            "custom_duration_days" => $custom_duration
        ];
        if ($discount_code_to_use) {
            $metadata["discount_code"] = $discount_code_to_use;
        }

        // استفاده از PaymentGateway
        if (class_exists('PaymentGateway')) {
            $paymentGateway = PaymentGateway::getInstance();
            $result = $paymentGateway->createPaymentLink($chat_id, $amount_to_charge, $description, $metadata, $gateway);
        } else {
            // Fallback به زرین‌پال
            $result = createZarinpalLink($chat_id, $amount_to_charge, $description, $metadata);
        }
        
        if ($result['success']) {
            $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
            $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $result['url']]]]];
            editMessageText($chat_id, $message_id, $message, $keyboard);
        } else {
            editMessageText($chat_id, $message_id, $result['error'] ?? 'خطا در ایجاد لینک پرداخت.');
        }
        die;
    }
    elseif (strpos($data, 'charge_plan_') === 0 && strpos($data, 'charge_plan_custom_') !== 0) {
        // پرداخت آنلاین برای پلن معمولی
        // فرمت: charge_plan_{gateway}_{amount}_{plan_id}_{name}_{discount}
        $parts = explode('_', $data);
        $gateway = $parts[2] ?? 'zarinpal'; // zarinpal, idpay, nextpay, zibal, newpayment
        $amount_to_charge = (int)$parts[3];
        $plan_id_to_buy = (int)$parts[4];
        $custom_name_encoded = $parts[5] ?? '';
        $discount_code_to_use = (isset($parts[6]) && !empty($parts[6])) ? $parts[6] : null;
        $custom_name = base64_decode($custom_name_encoded);

        $description = "تکمیل خرید پلن #{$plan_id_to_buy}";
        $metadata = [
            "purpose" => "complete_purchase",
            "plan_id" => $plan_id_to_buy,
            "user_id" => $chat_id,
            "custom_name" => $custom_name
        ];
        if ($discount_code_to_use) {
            $metadata["discount_code"] = $discount_code_to_use;
        }

        // استفاده از PaymentGateway
        if (class_exists('PaymentGateway')) {
            $paymentGateway = PaymentGateway::getInstance();
            $result = $paymentGateway->createPaymentLink($chat_id, $amount_to_charge, $description, $metadata, $gateway);
        } else {
            // Fallback به زرین‌پال
            $result = createZarinpalLink($chat_id, $amount_to_charge, $description, $metadata);
        }
        
        if ($result['success']) {
            $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
            $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $result['url']]]]];
            editMessageText($chat_id, $message_id, $message, $keyboard);
        } else {
            editMessageText($chat_id, $message_id, $result['error'] ?? 'خطا در ایجاد لینک پرداخت.');
        }
        die;
    }
    // پشتیبانی از فرمت قدیمی برای سازگاری با backward compatibility
    elseif (strpos($data, 'charge_for_plan_custom_') === 0) {
        // پرداخت آنلاین برای پلن قابل تنظیم (فرمت قدیمی)
        $parts = explode('_', $data);
        $amount_to_charge = (int)$parts[4];
        $plan_id_to_buy = (int)$parts[5];
        $custom_volume_encoded = $parts[6] ?? '';
        $custom_duration_encoded = $parts[7] ?? '';
        $custom_name_encoded = $parts[8] ?? '';
        $discount_code_to_use = (isset($parts[9]) && !empty($parts[9])) ? $parts[9] : null;
        
        $custom_volume = (int)base64_decode($custom_volume_encoded);
        $custom_duration = (int)base64_decode($custom_duration_encoded);
        $custom_name = base64_decode($custom_name_encoded);

        $description = "تکمیل خرید پلن قابل تنظیم #{$plan_id_to_buy}";
        $metadata = [
            "purpose" => "complete_purchase",
            "plan_id" => $plan_id_to_buy,
            "user_id" => $chat_id,
            "custom_name" => $custom_name,
            "custom_volume_gb" => $custom_volume,
            "custom_duration_days" => $custom_duration
        ];
        if ($discount_code_to_use) {
            $metadata["discount_code"] = $discount_code_to_use;
        }

        // استفاده از زرین‌پال به عنوان پیش‌فرض
        $result = createZarinpalLink($chat_id, $amount_to_charge, $description, $metadata);
        if ($result['success']) {
            $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
            $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $result['url']]]]];
            editMessageText($chat_id, $message_id, $message, $keyboard);
        } else {
            editMessageText($chat_id, $message_id, $result['error']);
        }
        die;
    }
    elseif (strpos($data, 'charge_for_plan_') === 0) {
        // پرداخت آنلاین برای پلن معمولی (فرمت قدیمی)
        $parts = explode('_', $data);
        $amount_to_charge = (int)$parts[3];
        $plan_id_to_buy = (int)$parts[4];
        $discount_code_to_use = (isset($parts[5]) && !empty($parts[5])) ? $parts[5] : null;
        $custom_name_encoded = $parts[6] ?? '';
        $custom_name = base64_decode($custom_name_encoded);

        $description = "تکمیل خرید پلن #{$plan_id_to_buy}";
        $metadata = [
            "purpose" => "complete_purchase",
            "plan_id" => $plan_id_to_buy,
            "user_id" => $chat_id,
            "custom_name" => $custom_name
        ];
        if ($discount_code_to_use) {
            $metadata["discount_code"] = $discount_code_to_use;
        }

        // استفاده از زرین‌پال به عنوان پیش‌فرض
        $result = createZarinpalLink($chat_id, $amount_to_charge, $description, $metadata);
        if ($result['success']) {
            $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
            $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $result['url']]]]];
            editMessageText($chat_id, $message_id, $message, $keyboard);
        } else {
            editMessageText($chat_id, $message_id, $result['error']);
        }
        die;
    }
    elseif (strpos($data, 'manual_pay_for_plan_custom_') === 0) {
        // پرداخت دستی برای پلن قابل تنظیم
        $parts = explode('_', $data);
        $amount_to_charge = (int)$parts[5];
        $plan_id_to_buy = (int)$parts[6];
        $custom_volume_encoded = $parts[7] ?? '';
        $custom_duration_encoded = $parts[8] ?? '';
        $custom_name_encoded = $parts[9] ?? '';
        $discount_code_to_use = (isset($parts[10]) && !empty($parts[10])) ? $parts[10] : null;
        
        $custom_volume = (int)base64_decode($custom_volume_encoded);
        $custom_duration = (int)base64_decode($custom_duration_encoded);
        $custom_name = base64_decode($custom_name_encoded);

        $state_data = [
            'charge_amount' => $amount_to_charge,
            'purpose' => 'complete_purchase',
            'plan_id' => $plan_id_to_buy,
            'custom_name' => $custom_name,
            'custom_volume_gb' => $custom_volume,
            'custom_duration_days' => $custom_duration
        ];
        if ($discount_code_to_use) {
            $state_data['discount_code'] = $discount_code_to_use;
        }

        updateUserData($chat_id, 'awaiting_payment_screenshot', $state_data);

        $settings = getSettings();
        $payment_method = $settings['payment_method'];
        $card_number_display = ($payment_method['copy_enabled'] ?? false) ? "<code>{$payment_method['card_number']}</code>" : $payment_method['card_number'];
        $message = "برای تکمیل خرید به مبلغ <b>" . number_format($amount_to_charge) . " تومان</b>، لطفا مبلغ را به اطلاعات زیر واریز نمایید:\n\n" .
                   "💳 شماره کارت:\n" . $card_number_display . "\n" .
                   "👤 صاحب حساب: {$payment_method['card_holder']}\n\n" .
                   "پس از واریز، لطفا از رسید پرداخت خود اسکرین‌شات گرفته و در همینجا ارسال کنید. پس از تایید، سرویس شما به صورت خودکار ایجاد خواهد شد.";
        editMessageText($chat_id, $message_id, $message);
        die;
    }
    elseif (strpos($data, 'manual_pay_for_plan_') === 0) {
        // پرداخت دستی برای پلن معمولی
        $parts = explode('_', $data);
        $amount_to_charge = (int)$parts[4];
        $plan_id_to_buy = (int)$parts[5];
        $discount_code_to_use = (isset($parts[6]) && !empty($parts[6])) ? $parts[6] : null;
        $custom_name_encoded = $parts[7] ?? '';
        $custom_name = base64_decode($custom_name_encoded);

        $state_data = [
            'charge_amount' => $amount_to_charge,
            'purpose' => 'complete_purchase',
            'plan_id' => $plan_id_to_buy,
            'custom_name' => $custom_name
        ];
        if ($discount_code_to_use) {
            $state_data['discount_code'] = $discount_code_to_use;
        }

        updateUserData($chat_id, 'awaiting_payment_screenshot', $state_data);

        $settings = getSettings();
        $payment_method = $settings['payment_method'];
        $card_number_display = ($payment_method['copy_enabled'] ?? false) ? "<code>{$payment_method['card_number']}</code>" : $payment_method['card_number'];
        $message = "برای تکمیل خرید به مبلغ <b>" . number_format($amount_to_charge) . " تومان</b>، لطفا مبلغ را به اطلاعات زیر واریز نمایید:\n\n" .
                   "💳 شماره کارت:\n" . $card_number_display . "\n" .
                   "👤 صاحب حساب: {$payment_method['card_holder']}\n\n" .
                   "پس از واریز، لطفا از رسید پرداخت خود اسکرین‌شات گرفته و در همینجا ارسال کنید. پس از تایید، سرویس شما به صورت خودکار ایجاد خواهد شد.";
        editMessageText($chat_id, $message_id, $message);
        die;
    }

    // --- دکمه‌های مخصوص ادمین‌ها ---
    if ($isAnAdmin) {
        // --- بخش جدید: مدیریت کاربران از طریق دکمه شیشه‌ای ---
        if (strpos($data, 'add_balance_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('add_balance_', '', $data);
            updateUserData($chat_id, 'admin_awaiting_amount_for_add_balance', ['target_user_id' => $target_id, 'admin_view' => 'admin']);
            sendMessage($chat_id, "لطفا مبلغی که می‌خواهید به موجودی کاربر <code>$target_id</code> اضافه کنید را به تومان وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif (strpos($data, 'show_user_services_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('show_user_services_', '', $data);
            $services = getUserServices($target_id);
            
            $target_user_info = getUserData($target_id);
            $target_user_name = htmlspecialchars($target_user_info['first_name'] ?? "کاربر $target_id");
            
            if (empty($services)) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "کاربر {$target_user_name} هیچ سرویسی ندارد.", 'show_alert' => true]);
            } else {
                $message_text = "<b>لیست سرویس‌های کاربر: {$target_user_name}</b>\n\n";
                $now = time();
                foreach ($services as $service) {
                    // پشتیبانی از زمان نامحدود (اگر expire_timestamp صفر باشد)
                    $expire_date = 'نامحدود';
                    if (!empty($service['expire_timestamp']) && $service['expire_timestamp'] > 0) {
                        $expire_date = date('Y-m-d', $service['expire_timestamp']);
                    }
                    
                    $status_icon = '✅';
                    if (!empty($service['expire_timestamp']) && $service['expire_timestamp'] > 0) {
                        $status_icon = $service['expire_timestamp'] < $now ? '❌' : '✅';
                    }
                    $message_text .= "{$status_icon} <b>{$service['plan_name']}</b>\n";
                    $message_text .= "▫️ نام کاربری پنل: <code>{$service['marzban_username']}</code>\n";
                    $message_text .= "▫️ تاریخ انقضا: {$expire_date}\n---\n";
                }
                
                // پیام را در یک پیام جدید ارسال می‌کنیم تا منوی مدیریت اصلی حفظ شود
                sendMessage($chat_id, $message_text);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            }
            die;
        }
        elseif (strpos($data, 'deduct_balance_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('deduct_balance_', '', $data);
            updateUserData($chat_id, 'admin_awaiting_amount_for_deduct_balance', ['target_user_id' => $target_id, 'admin_view' => 'admin']);
            sendMessage($chat_id, "لطفا مبلغی که می‌خواهید از موجودی کاربر <code>$target_id</code> کسر کنید را به تومان وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif (strpos($data, 'message_user_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('message_user_', '', $data);
            updateUserData($chat_id, 'admin_awaiting_message_for_user', ['target_user_id' => $target_id, 'admin_view' => 'admin']);
            sendMessage($chat_id, "پیام خود را برای ارسال به کاربر <code>$target_id</code> وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif (strpos($data, 'ban_user_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('ban_user_', '', $data);
            if ($target_id == ADMIN_CHAT_ID) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ شما نمی‌توانید خودتان را مسدود کنید!', 'show_alert' => true]);
            } else {
                setUserStatus($target_id, 'banned');
                sendMessage($target_id, "شما توسط ادمین از ربات مسدود شده‌اید.");
                editMessageText($chat_id, $message_id, $update['callback_query']['message']['text'] . "\n\n---\n✅ کاربر با موفقیت مسدود شد.");
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'کاربر مسدود شد']);
            }
            die;
        }
        elseif (strpos($data, 'unban_user_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('unban_user_', '', $data);
            setUserStatus($target_id, 'active');
            sendMessage($target_id, "✅ شما توسط ادمین از حالت مسدودیت خارج شدید.");
            editMessageText($chat_id, $message_id, $update['callback_query']['message']['text'] . "\n\n---\n✅ کاربر با موفقیت آزاد شد.");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'کاربر آزاد شد']);
            die;
        }
        elseif ($data === 'search_another_user' && hasPermission($chat_id, 'manage_users')) {
            deleteMessage($chat_id, $message_id);
            updateUserData($chat_id, 'admin_awaiting_user_search', ['admin_view' => 'admin']);
            sendMessage($chat_id, "لطفاً شناسه عددی (Chat ID) کاربر بعدی را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        // end
        
        if (strpos($data, 'delete_cat_') === 0 && hasPermission($chat_id, 'manage_categories')) {
            $cat_id = str_replace('delete_cat_', '', $data);
            pdo()
                ->prepare("DELETE FROM categories WHERE id = ?")
                ->execute([$cat_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ حذف شد']);
            deleteMessage($chat_id, $message_id);
            generateCategoryList($chat_id);
        }
    elseif (strpos($data, 'charge_zarinpal_') === 0) {
        $amount = (int)str_replace('charge_zarinpal_', '', $data);
        $settings = getSettings();
        $merchant_id = $settings['zarinpal_merchant_id'];
        
        $script_url = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/verify_payment.php';
        
        $data = [
            "merchant_id" => $merchant_id,
            "amount" => $amount * 10, // تبدیل تومان به ریال
            "callback_url" => $script_url,
            "description" => "شارژ حساب کاربری - " . $chat_id,
            "metadata" => ["order_id" => "user_{$chat_id}_" . time()]
        ];
        $jsonData = json_encode($data);

        $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/request.json');
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($jsonData)]);
        
        $result = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($result, true);
        
        if (empty($result['errors'])) {
            $authority = $result['data']['authority'];
            
            // ثبت تراکنش در دیتابیس
            $stmt = pdo()->prepare("INSERT INTO transactions (user_id, amount, authority, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([$chat_id, $amount, $authority, "شارژ حساب"]);
            
            $payment_url = 'https://www.zarinpal.com/pg/StartPay/' . $authority;
            
            $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
            $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $payment_url]]]];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            
        } else {
            $error_code = $result['errors']['code'];
            editMessageText($chat_id, $message_id, "❌ خطا در اتصال به درگاه پرداخت. کد خطا: {$error_code}");
        }
    }
    elseif (strpos($data, 'charge_idpay_') === 0) {
        $amount = (int)str_replace('charge_idpay_', '', $data);
        if (class_exists('PaymentGateway')) {
            $paymentGateway = PaymentGateway::getInstance();
            $result = $paymentGateway->createPaymentLink($chat_id, $amount, "شارژ حساب کاربری - " . $chat_id, ["order_id" => "user_{$chat_id}_" . time()], 'idpay');
            if ($result['success']) {
                $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
                $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $result['url']]]]];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            } else {
                editMessageText($chat_id, $message_id, "❌ " . $result['error']);
            }
        } else {
            editMessageText($chat_id, $message_id, "❌ خطا: سیستم پرداخت در دسترس نیست.");
        }
    }
    elseif (strpos($data, 'charge_nextpay_') === 0) {
        $amount = (int)str_replace('charge_nextpay_', '', $data);
        if (class_exists('PaymentGateway')) {
            $paymentGateway = PaymentGateway::getInstance();
            $result = $paymentGateway->createPaymentLink($chat_id, $amount, "شارژ حساب کاربری - " . $chat_id, ["order_id" => "user_{$chat_id}_" . time()], 'nextpay');
            if ($result['success']) {
                $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
                $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $result['url']]]]];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            } else {
                editMessageText($chat_id, $message_id, "❌ " . $result['error']);
            }
        } else {
            editMessageText($chat_id, $message_id, "❌ خطا: سیستم پرداخت در دسترس نیست.");
        }
    }
        elseif (strpos($data, 'charge_zibal_') === 0) {
        $amount = (int)str_replace('charge_zibal_', '', $data);
        if (class_exists('PaymentGateway')) {
            $paymentGateway = PaymentGateway::getInstance();
            $result = $paymentGateway->createPaymentLink($chat_id, $amount, "شارژ حساب کاربری - " . $chat_id, ["order_id" => "user_{$chat_id}_" . time()], 'zibal');
            if ($result['success']) {
                $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
                $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $result['url']]]]];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            } else {
                editMessageText($chat_id, $message_id, "❌ " . $result['error']);
            }
        } else {
            editMessageText($chat_id, $message_id, "❌ خطا: سیستم پرداخت در دسترس نیست.");
        }
    }
    elseif (strpos($data, 'charge_newpayment_') === 0) {
        $amount = (int)str_replace('charge_newpayment_', '', $data);
        if (class_exists('PaymentGateway')) {
            $paymentGateway = PaymentGateway::getInstance();
            $result = $paymentGateway->createPaymentLink($chat_id, $amount, "شارژ حساب کاربری - " . $chat_id, ["order_id" => "user_{$chat_id}_" . time()], 'newpayment');
            if ($result['success']) {
                $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
                $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $result['url']]]]];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            } else {
                editMessageText($chat_id, $message_id, "❌ " . $result['error']);
            }
        } else {
            editMessageText($chat_id, $message_id, "❌ خطا: سیستم پرداخت در دسترس نیست.");
        }
    }
    elseif (strpos($data, 'charge_aqayepardakht_') === 0) {
        $amount = (int)str_replace('charge_aqayepardakht_', '', $data);
        if (class_exists('PaymentGateway')) {
            $paymentGateway = PaymentGateway::getInstance();
            $result = $paymentGateway->createPaymentLink($chat_id, $amount, "شارژ حساب کاربری - " . $chat_id, ["order_id" => "user_{$chat_id}_" . time()], 'aqayepardakht');
            if ($result['success']) {
                $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
                $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $result['url']]]]];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            } else {
                editMessageText($chat_id, $message_id, "❌ " . $result['error']);
            }
        } else {
            editMessageText($chat_id, $message_id, "❌ خطا: سیستم پرداخت در دسترس نیست.");
        }
    }
        elseif ($data === 'toggle_gateway_status') {
            $settings = getSettings();
            $settings['payment_gateway_status'] = ($settings['payment_gateway_status'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            // Refresh menu
            $status_icon = $settings['payment_gateway_status'] == 'on' ? '✅' : '❌';
            $merchant_id = $settings['zarinpal_merchant_id'] ?? 'تنظیم نشده';
            $sandbox_icon = ($settings['zarinpal_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            $message = "💎 <b>تنظیمات زرین‌پال</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ مرچنت کد: <code>{$merchant_id}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_gateway_status']],
                    [['text' => '✏️ تنظیم مرچنت کد', 'callback_data' => 'set_zarinpal_merchant_id']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_zarinpal_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'set_zarinpal_merchant_id') {
            updateUserData($chat_id, 'admin_awaiting_merchant_id');
            editMessageText($chat_id, $message_id, "💎 <b>تنظیم زرین‌پال</b>\n\nلطفا مرچنت کد ۳۶ کاراکتری زرین‌پال خود را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'setup_gateway_zarinpal') {
            $settings = getSettings();
            $status_icon = ($settings['payment_gateway_status'] ?? 'off') == 'on' ? '✅' : '❌';
            $merchant_id = $settings['zarinpal_merchant_id'] ?? 'تنظیم نشده';
            $sandbox_icon = ($settings['zarinpal_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            
            $message = "💎 <b>تنظیمات زرین‌پال</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ مرچنت کد: <code>{$merchant_id}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_gateway_status']],
                    [['text' => '✏️ تنظیم مرچنت کد', 'callback_data' => 'set_zarinpal_merchant_id']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_zarinpal_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'setup_gateway_idpay') {
            $settings = getSettings();
            $status_icon = ($settings['idpay_enabled'] ?? 'off') == 'on' ? '✅' : '❌';
            $api_key = !empty($settings['idpay_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = ($settings['idpay_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            
            $message = "🔷 <b>تنظیمات IDPay</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ API Key: <code>{$api_key}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_idpay_status']],
                    [['text' => '✏️ تنظیم API Key', 'callback_data' => 'set_idpay_api_key']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_idpay_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'setup_gateway_nextpay') {
            $settings = getSettings();
            $status_icon = ($settings['nextpay_enabled'] ?? 'off') == 'on' ? '✅' : '❌';
            $api_key = !empty($settings['nextpay_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = ($settings['nextpay_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            
            $message = "🔶 <b>تنظیمات NextPay</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ API Key: <code>{$api_key}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_nextpay_status']],
                    [['text' => '✏️ تنظیم API Key', 'callback_data' => 'set_nextpay_api_key']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_nextpay_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'toggle_zarinpal_sandbox') {
            $settings = getSettings();
            $settings['zarinpal_sandbox'] = ($settings['zarinpal_sandbox'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ حالت تست تغییر کرد.']);
            // Refresh menu
            $status_icon = ($settings['payment_gateway_status'] ?? 'off') == 'on' ? '✅' : '❌';
            $merchant_id = $settings['zarinpal_merchant_id'] ?? 'تنظیم نشده';
            $sandbox_icon = $settings['zarinpal_sandbox'] == 'on' ? '✅' : '❌';
            $message = "💎 <b>تنظیمات زرین‌پال</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ مرچنت کد: <code>{$merchant_id}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_gateway_status']],
                    [['text' => '✏️ تنظیم مرچنت کد', 'callback_data' => 'set_zarinpal_merchant_id']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_zarinpal_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'toggle_idpay_status') {
            $settings = getSettings();
            $settings['idpay_enabled'] = ($settings['idpay_enabled'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            // Refresh menu
            $status_icon = $settings['idpay_enabled'] == 'on' ? '✅' : '❌';
            $api_key = !empty($settings['idpay_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = ($settings['idpay_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            $message = "🔷 <b>تنظیمات IDPay</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ API Key: <code>{$api_key}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_idpay_status']],
                    [['text' => '✏️ تنظیم API Key', 'callback_data' => 'set_idpay_api_key']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_idpay_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'set_idpay_api_key') {
            updateUserData($chat_id, 'admin_awaiting_idpay_api_key', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🔷 <b>تنظیم IDPay</b>\n\nلطفا API Key IDPay خود را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'toggle_idpay_sandbox') {
            $settings = getSettings();
            $settings['idpay_sandbox'] = ($settings['idpay_sandbox'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ حالت تست تغییر کرد.']);
            // Refresh menu
            $status_icon = ($settings['idpay_enabled'] ?? 'off') == 'on' ? '✅' : '❌';
            $api_key = !empty($settings['idpay_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = $settings['idpay_sandbox'] == 'on' ? '✅' : '❌';
            $message = "🔷 <b>تنظیمات IDPay</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ API Key: <code>{$api_key}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_idpay_status']],
                    [['text' => '✏️ تنظیم API Key', 'callback_data' => 'set_idpay_api_key']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_idpay_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'toggle_nextpay_status') {
            $settings = getSettings();
            $settings['nextpay_enabled'] = ($settings['nextpay_enabled'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            // Refresh menu
            $status_icon = $settings['nextpay_enabled'] == 'on' ? '✅' : '❌';
            $api_key = !empty($settings['nextpay_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = ($settings['nextpay_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            $message = "🔶 <b>تنظیمات NextPay</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ API Key: <code>{$api_key}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_nextpay_status']],
                    [['text' => '✏️ تنظیم API Key', 'callback_data' => 'set_nextpay_api_key']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_nextpay_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'set_nextpay_api_key') {
            updateUserData($chat_id, 'admin_awaiting_nextpay_api_key', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🔶 <b>تنظیم NextPay</b>\n\nلطفا API Key NextPay خود را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'toggle_nextpay_sandbox') {
            $settings = getSettings();
            $settings['nextpay_sandbox'] = ($settings['nextpay_sandbox'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ حالت تست تغییر کرد.']);
            // Refresh menu
            $status_icon = ($settings['nextpay_enabled'] ?? 'off') == 'on' ? '✅' : '❌';
            $api_key = !empty($settings['nextpay_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = $settings['nextpay_sandbox'] == 'on' ? '✅' : '❌';
            $message = "🔶 <b>تنظیمات NextPay</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ API Key: <code>{$api_key}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_nextpay_status']],
                    [['text' => '✏️ تنظیم API Key', 'callback_data' => 'set_nextpay_api_key']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_nextpay_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'back_to_gateway_menu') {
            $settings = getSettings();
            $message = "<b>💳 مدیریت درگاه‌های پرداخت</b>\n\n";
            $message .= "درگاه‌های پرداخت موجود:\n\n";
            $zarinpal_enabled = ($settings['payment_gateway_status'] ?? 'off') == 'on' && !empty($settings['zarinpal_merchant_id']);
            $zarinpal_icon = $zarinpal_enabled ? '✅' : '❌';
            $zarinpal_merchant = $settings['zarinpal_merchant_id'] ?? 'تنظیم نشده';
            $message .= "{$zarinpal_icon} <b>زرین‌پال</b>\n   مرچنت کد: <code>{$zarinpal_merchant}</code>\n\n";
            $idpay_enabled = ($settings['idpay_enabled'] ?? 'off') == 'on' && !empty($settings['idpay_api_key']);
            $idpay_icon = $idpay_enabled ? '✅' : '❌';
            $idpay_api = !empty($settings['idpay_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $message .= "{$idpay_icon} <b>IDPay</b>\n   API Key: <code>{$idpay_api}</code>\n\n";
            $nextpay_enabled = ($settings['nextpay_enabled'] ?? 'off') == 'on' && !empty($settings['nextpay_api_key']);
            $nextpay_icon = $nextpay_enabled ? '✅' : '❌';
            $nextpay_api = !empty($settings['nextpay_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $message .= "{$nextpay_icon} <b>NextPay</b>\n   API Key: <code>{$nextpay_api}</code>\n\n";
            $zibal_enabled = ($settings['zibal_enabled'] ?? 'off') == 'on' && !empty($settings['zibal_merchant_id']);
            $zibal_icon = $zibal_enabled ? '✅' : '❌';
            $zibal_merchant = !empty($settings['zibal_merchant_id']) ? 'تنظیم شده' : 'تنظیم نشده';
            $message .= "{$zibal_icon} <b>زیبال</b>\n   مرچنت کد: <code>{$zibal_merchant}</code>\n\n";
            $newpayment_enabled = ($settings['newpayment_enabled'] ?? 'off') == 'on' && !empty($settings['newpayment_api_key']);
            $newpayment_icon = $newpayment_enabled ? '✅' : '❌';
            $newpayment_api = !empty($settings['newpayment_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $message .= "{$newpayment_icon} <b>newPayment</b>\n   API Key: <code>{$newpayment_api}</code>\n\n";
            $aqayepardakht_enabled = ($settings['aqayepardakht_enabled'] ?? 'off') == 'on' && !empty($settings['aqayepardakht_pin']);
            $aqayepardakht_icon = $aqayepardakht_enabled ? '✅' : '❌';
            $aqayepardakht_pin = !empty($settings['aqayepardakht_pin']) ? 'تنظیم شده' : 'تنظیم نشده';
            $message .= "{$aqayepardakht_icon} <b>آقای پرداخت</b>\n   PIN: <code>{$aqayepardakht_pin}</code>\n\n";
            $message .= "برای تنظیم هر درگاه، گزینه مورد نظر را انتخاب کنید:";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '💎 تنظیم زرین‌پال', 'callback_data' => 'setup_gateway_zarinpal']],
                    [['text' => '🔷 تنظیم IDPay', 'callback_data' => 'setup_gateway_idpay']],
                    [['text' => '🔶 تنظیم NextPay', 'callback_data' => 'setup_gateway_nextpay']],
                    [['text' => '💛 تنظیم زیبال', 'callback_data' => 'setup_gateway_zibal']],
                    [['text' => '🆕 تنظیم newPayment', 'callback_data' => 'setup_gateway_newpayment']],
                    [['text' => '👨‍💼 تنظیم آقای پرداخت', 'callback_data' => 'setup_gateway_aqayepardakht']],
                    [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']],
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'setup_gateway_zibal') {
            $settings = getSettings();
            $status_icon = ($settings['zibal_enabled'] ?? 'off') == 'on' ? '✅' : '❌';
            $merchant_id = !empty($settings['zibal_merchant_id']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = ($settings['zibal_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            
            $message = "💛 <b>تنظیمات زیبال</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ مرچنت کد: <code>{$merchant_id}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_zibal_status']],
                    [['text' => '✏️ تنظیم مرچنت کد', 'callback_data' => 'set_zibal_merchant_id']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_zibal_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'toggle_zibal_status') {
            $settings = getSettings();
            $settings['zibal_enabled'] = ($settings['zibal_enabled'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            // Refresh menu
            $status_icon = $settings['zibal_enabled'] == 'on' ? '✅' : '❌';
            $merchant_id = !empty($settings['zibal_merchant_id']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = ($settings['zibal_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            $message = "💛 <b>تنظیمات زیبال</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ مرچنت کد: <code>{$merchant_id}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_zibal_status']],
                    [['text' => '✏️ تنظیم مرچنت کد', 'callback_data' => 'set_zibal_merchant_id']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_zibal_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'set_zibal_merchant_id') {
            updateUserData($chat_id, 'admin_awaiting_zibal_merchant_id', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "💛 <b>تنظیم زیبال</b>\n\nلطفا مرچنت کد زیبال خود را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'toggle_zibal_sandbox') {
            $settings = getSettings();
            $settings['zibal_sandbox'] = ($settings['zibal_sandbox'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ حالت تست تغییر کرد.']);
            // Refresh menu
            $status_icon = ($settings['zibal_enabled'] ?? 'off') == 'on' ? '✅' : '❌';
            $merchant_id = !empty($settings['zibal_merchant_id']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = $settings['zibal_sandbox'] == 'on' ? '✅' : '❌';
            $message = "💛 <b>تنظیمات زیبال</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ مرچنت کد: <code>{$merchant_id}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_zibal_status']],
                    [['text' => '✏️ تنظیم مرچنت کد', 'callback_data' => 'set_zibal_merchant_id']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_zibal_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'setup_gateway_newpayment') {
            $settings = getSettings();
            $status_icon = ($settings['newpayment_enabled'] ?? 'off') == 'on' ? '✅' : '❌';
            $api_key = !empty($settings['newpayment_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = ($settings['newpayment_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            
            $message = "🆕 <b>تنظیمات newPayment</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ API Key: <code>{$api_key}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_newpayment_status']],
                    [['text' => '✏️ تنظیم API Key', 'callback_data' => 'set_newpayment_api_key']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_newpayment_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'toggle_newpayment_status') {
            $settings = getSettings();
            $settings['newpayment_enabled'] = ($settings['newpayment_enabled'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            // Refresh menu
            $status_icon = $settings['newpayment_enabled'] == 'on' ? '✅' : '❌';
            $api_key = !empty($settings['newpayment_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = ($settings['newpayment_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            $message = "🆕 <b>تنظیمات newPayment</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ API Key: <code>{$api_key}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_newpayment_status']],
                    [['text' => '✏️ تنظیم API Key', 'callback_data' => 'set_newpayment_api_key']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_newpayment_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'set_newpayment_api_key') {
            updateUserData($chat_id, 'admin_awaiting_newpayment_api_key', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🆕 <b>تنظیم newPayment</b>\n\nلطفا API Key جدید newPayment خود را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'setup_gateway_aqayepardakht') {
            $settings = getSettings();
            $status_icon = ($settings['aqayepardakht_enabled'] ?? 'off') == 'on' ? '✅' : '❌';
            $pin = !empty($settings['aqayepardakht_pin']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = ($settings['aqayepardakht_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            
            $message = "👨‍💼 <b>تنظیمات آقای پرداخت</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ PIN: <code>{$pin}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_aqayepardakht_status']],
                    [['text' => '✏️ تنظیم PIN', 'callback_data' => 'set_aqayepardakht_pin']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_aqayepardakht_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'toggle_aqayepardakht_status') {
            $settings = getSettings();
            $settings['aqayepardakht_enabled'] = ($settings['aqayepardakht_enabled'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            // Refresh menu
            $status_icon = $settings['aqayepardakht_enabled'] == 'on' ? '✅' : '❌';
            $pin = !empty($settings['aqayepardakht_pin']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = ($settings['aqayepardakht_sandbox'] ?? 'off') == 'on' ? '✅' : '❌';
            $message = "👨‍💼 <b>تنظیمات آقای پرداخت</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ PIN: <code>{$pin}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_aqayepardakht_status']],
                    [['text' => '✏️ تنظیم PIN', 'callback_data' => 'set_aqayepardakht_pin']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_aqayepardakht_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'set_aqayepardakht_pin') {
            updateUserData($chat_id, 'admin_awaiting_aqayepardakht_pin', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "👨‍💼 <b>تنظیم آقای پرداخت</b>\n\nلطفا PIN جدید آقای پرداخت خود را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data === 'toggle_aqayepardakht_sandbox') {
            $settings = getSettings();
            $settings['aqayepardakht_sandbox'] = ($settings['aqayepardakht_sandbox'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ حالت تست تغییر کرد.']);
            // Refresh menu
            $status_icon = ($settings['aqayepardakht_enabled'] ?? 'off') == 'on' ? '✅' : '❌';
            $pin = !empty($settings['aqayepardakht_pin']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = $settings['aqayepardakht_sandbox'] == 'on' ? '✅' : '❌';
            $message = "👨‍💼 <b>تنظیمات آقای پرداخت</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ PIN: <code>{$pin}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_aqayepardakht_status']],
                    [['text' => '✏️ تنظیم PIN', 'callback_data' => 'set_aqayepardakht_pin']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_aqayepardakht_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'toggle_newpayment_sandbox') {
            $settings = getSettings();
            $settings['newpayment_sandbox'] = ($settings['newpayment_sandbox'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ حالت تست تغییر کرد.']);
            // Refresh menu
            $status_icon = ($settings['newpayment_enabled'] ?? 'off') == 'on' ? '✅' : '❌';
            $api_key = !empty($settings['newpayment_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
            $sandbox_icon = $settings['newpayment_sandbox'] == 'on' ? '✅' : '❌';
            $message = "🆕 <b>تنظیمات newPayment</b>\n\n";
            $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $message .= "▫️ API Key: <code>{$api_key}</code>\n";
            $message .= "▫️ حالت تست: " . ($sandbox_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => $status_icon . ' فعال/غیرفعال', 'callback_data' => 'toggle_newpayment_status']],
                    [['text' => '✏️ تنظیم API Key', 'callback_data' => 'set_newpayment_api_key']],
                    [['text' => $sandbox_icon . ' حالت تست', 'callback_data' => 'toggle_newpayment_sandbox']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_gateway_menu']]
                ]
            ];
            editMessageText($chat_id, $message_id, $message, $keyboard);
            die;
        }
        elseif ($data === 'toggle_renewal_status') {
    $settings = getSettings();
    $settings['renewal_status'] = ($settings['renewal_status'] ?? 'off') == 'on' ? 'off' : 'on';
    saveSettings($settings);
    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
    showRenewalManagementMenu($chat_id, $message_id);
}
        elseif ($data === 'set_renewal_price_day') {
            updateUserData($chat_id, 'admin_awaiting_renewal_price_day');
            editMessageText($chat_id, $message_id, "لطفا هزینه تمدید به ازای هر **روز** را به تومان وارد کنید (فقط عدد):");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif ($data === 'set_renewal_price_gb') {
            updateUserData($chat_id, 'admin_awaiting_renewal_price_gb');
            editMessageText($chat_id, $message_id, "لطفا هزینه تمدید به ازای هر **گیگابایت** را به تومان وارد کنید (فقط عدد):");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'toggle_cat_') === 0 && hasPermission($chat_id, 'manage_categories')) {
            $cat_id = str_replace('toggle_cat_', '', $data);
            pdo()
                ->prepare("UPDATE categories SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")
                ->execute([$cat_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد']);
            deleteMessage($chat_id, $message_id);
            generateCategoryList($chat_id);
        }
        elseif (strpos($data, 'delete_plan_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('delete_plan_', '', $data);
            pdo()
                ->prepare("DELETE FROM plans WHERE id = ?")
                ->execute([$plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ پلن حذف شد']);
            deleteMessage($chat_id, $message_id);
        }
        elseif (strpos($data, 'toggle_plan_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('toggle_plan_', '', $data);
            pdo()
                ->prepare("UPDATE plans SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")
                ->execute([$plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif ($data === 'back_to_plan_list' && hasPermission($chat_id, 'manage_plans')) {
            updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'open_plan_editor_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('open_plan_editor_', '', $data);
            showPlanEditor($chat_id, $message_id, $plan_id);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'edit_plan_field_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/edit_plan_field_(\d+)_(\w+)/', $data, $matches);
            $plan_id = $matches[1];
            $field = $matches[2];
            
            $field_map = [
                'name' => ['prompt' => '👇 لطفا نام جدید پلن را وارد کنید:', 'column' => 'name', 'validation' => 'text'],
                'price' => ['prompt' => '👇 لطفا قیمت جدید را به تومان وارد کنید (فقط عدد):', 'column' => 'price', 'validation' => 'numeric'],
                'volume_gb' => ['prompt' => '👇 لطفا حجم جدید را به گیگابایت وارد کنید (فقط عدد):', 'column' => 'volume_gb', 'validation' => 'numeric'],
                'duration_days' => ['prompt' => '👇 لطفا مدت زمان جدید را به روز وارد کنید (فقط عدد):', 'column' => 'duration_days', 'validation' => 'numeric'],
                'purchase_limit' => ['prompt' => '👇 لطفا محدودیت خرید جدید را وارد کنید (0 برای نامحدود):', 'column' => 'purchase_limit', 'validation' => 'numeric_zero'],
            ];

            if (array_key_exists($field, $field_map)) {
                $field_info = $field_map[$field];
                $state_data = [
                    'editing_plan_id' => $plan_id,
                    'editing_field_info' => $field_info,
                    'editor_message_id' => $message_id 
                ];
                updateUserData($chat_id, 'admin_awaiting_plan_edit_input', $state_data);
                showPlanEditor($chat_id, $message_id, $plan_id, $field_info['prompt']);
            }
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'back_to_plan_view_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif (strpos($data, 'edit_plan_field_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/edit_plan_field_(\d+)_(\w+)/', $data, $matches);
            $plan_id = $matches[1];
            $field = $matches[2];

            $state_data = ['editing_plan_id' => $plan_id];

            switch ($field) {
                case 'name':
                    updateUserData($chat_id, 'admin_editing_plan_name', $state_data);
                    sendMessage($chat_id, "لطفا نام جدید پلن را وارد کنید:", $cancelKeyboard);
                    break;
                case 'price':
                    updateUserData($chat_id, 'admin_editing_plan_price', $state_data);
                    sendMessage($chat_id, "لطفا قیمت جدید را به تومان وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'volume':
                    updateUserData($chat_id, 'admin_editing_plan_volume', $state_data);
                    sendMessage($chat_id, "لطفا حجم جدید را به گیگابایت وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'duration':
                    updateUserData($chat_id, 'admin_editing_plan_duration', $state_data);
                    sendMessage($chat_id, "لطفا مدت زمان جدید را به روز وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'limit':
                    updateUserData($chat_id, 'admin_editing_plan_limit', $state_data);
                    sendMessage($chat_id, "لطفا محدودیت خرید جدید را وارد کنید (0 برای نامحدود):", $cancelKeyboard);
                    break;
                case 'category':
                    $categories = getCategories();
                    if (empty($categories)) {
                        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'هیچ دسته‌بندی برای انتخاب وجود ندارد!', 'show_alert' => true]);
                        break;
                    }
                    $keyboard_buttons = [];
                    foreach ($categories as $category) {
                        $keyboard_buttons[] = [['text' => $category['name'], 'callback_data' => "set_plan_category_{$plan_id}_{$category['id']}"]];
                    }
                    editMessageText($chat_id, $message_id, "دسته‌بندی جدید را برای این پلن انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
                    break;
                case 'server':
                    $servers = pdo()
                        ->query("SELECT id, name FROM servers")
                        ->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($servers)) {
                        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'هیچ سروری برای انتخاب وجود ندارد!', 'show_alert' => true]);
                        break;
                    }
                    $keyboard_buttons = [];
                    foreach ($servers as $server) {
                        $keyboard_buttons[] = [['text' => $server['name'], 'callback_data' => "set_plan_server_{$plan_id}_{$server['id']}"]];
                    }
                    editMessageText($chat_id, $message_id, "سرور جدید را برای این پلن انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
                    break;
            }
            if ($field !== 'category' && $field !== 'server') {
                deleteMessage($chat_id, $message_id);
            }
            
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif (strpos($data, 'set_plan_category_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/set_plan_category_(\d+)_(\d+)/', $data, $matches);
            $plan_id = $matches[1];
            $category_id = $matches[2];
            pdo()
                ->prepare("UPDATE plans SET category_id = ? WHERE id = ?")
                ->execute([$category_id, $plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ دسته‌بندی پلن با موفقیت تغییر کرد.']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif (strpos($data, 'set_plan_server_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/set_plan_server_(\d+)_(\d+)/', $data, $matches);
            $plan_id = $matches[1];
            $server_id = $matches[2];
            pdo()
                ->prepare("UPDATE plans SET server_id = ? WHERE id = ?")
                ->execute([$server_id, $plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ سرور پلن با موفقیت تغییر کرد.']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif (strpos($data, 'p_cat_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $category_id = str_replace('p_cat_', '', $data);
            $servers = pdo()
                ->query("SELECT id, name FROM servers WHERE status = 'active'")
                ->fetchAll(PDO::FETCH_ASSOC);
            if (empty($servers)) {
                editMessageText($chat_id, $message_id, "❌ ابتدا باید حداقل یک سرور در بخش «مدیریت سرورها» اضافه کنید.");
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                die;
            }
            $keyboard_buttons = [];
            foreach ($servers as $server) {
                $keyboard_buttons[] = [['text' => $server['name'], 'callback_data' => "p_server_{$server['id']}_cat_{$category_id}"]];
            }
            editMessageText($chat_id, $message_id, "این پلن روی کدام سرور ساخته شود؟", ['inline_keyboard' => $keyboard_buttons]);
        }
        elseif (strpos($data, 'p_server_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/p_server_(\d+)_cat_(\d+)/', $data, $matches);
            $server_id = $matches[1];
            $category_id = $matches[2];
            
            $stmt = pdo()->prepare("SELECT type FROM servers WHERE id = ?");
            $stmt->execute([$server_id]);
            $server_type = $stmt->fetchColumn();

            if ($server_type === 'sanaei' || $server_type === 'txui') {
                if ($server_type === 'sanaei') {
                    $inbounds = getSanaeiInbounds($server_id);
                } else {
                    require_once __DIR__ . '/api/txui_api.php';
                    $inbounds = getTxuiInbounds($server_id);
                }
                if (empty($inbounds)) {
                    $panel_name = $server_type === 'sanaei' ? 'Sanaei (3X-UI)' : 'TX-UI';
                    editMessageText($chat_id, $message_id, "❌ هیچ اینباند فعالی روی این سرور {$panel_name} یافت نشد. لطفا ابتدا یک اینباند در پنل خود بسازید.");
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                    die;
                }
                $keyboard_buttons = [];
                foreach ($inbounds as $inbound) {
                    $keyboard_buttons[] = [['text' => $inbound['remark'] . " (ID: {$inbound['id']})", 'callback_data' => "p_inbound_{$inbound['id']}_server_{$server_id}_cat_{$category_id}"]];
                }
                editMessageText($chat_id, $message_id, "این پلن به کدام اینباند اضافه شود؟", ['inline_keyboard' => $keyboard_buttons]);
            } elseif ($server_type === 'marzneshin') {
                $services = getMarzneshinServices($server_id);
                 if (empty($services)) {
                    editMessageText($chat_id, $message_id, "❌ هیچ سرویسی روی این سرور مرزنشین یافت نشد. لطفا ابتدا یک سرویس در پنل خود بسازید.");
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                    die;
                }
                $keyboard_buttons = [];
                foreach ($services as $service) {
                    $keyboard_buttons[] = [['text' => $service['name'] . " (ID: {$service['id']})", 'callback_data' => "p_service_{$service['id']}_server_{$server_id}_cat_{$category_id}"]];
                }
                editMessageText($chat_id, $message_id, "کاربران این پلن به کدام سرویس اضافه شوند؟", ['inline_keyboard' => $keyboard_buttons]);
            } else {
                $state_data = [
                    'new_plan_category_id' => $category_id,
                    'new_plan_server_id' => $server_id,
                ];
                updateUserData($chat_id, 'awaiting_plan_name', $state_data);
                sendMessage($chat_id, "1/7 - لطفا نام پلن را وارد کنید:", $cancelKeyboard);
                deleteMessage($chat_id, $message_id);
            }
        }
        elseif (strpos($data, 'p_inbound_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/p_inbound_(\d+)_server_(\d+)_cat_(\d+)/', $data, $matches);
            $inbound_id = $matches[1];
            $server_id = $matches[2];
            $category_id = $matches[3];

            $state_data = [
                'new_plan_category_id' => $category_id,
                'new_plan_server_id' => $server_id,
                'new_plan_inbound_id' => $inbound_id,
            ];
            updateUserData($chat_id, 'awaiting_plan_name', $state_data);
            sendMessage($chat_id, "1/7 - لطفا نام پلن را وارد کنید:", $cancelKeyboard);
            deleteMessage($chat_id, $message_id);
        }
        elseif (strpos($data, 'p_service_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/p_service_(\d+)_server_(\d+)_cat_(\d+)/', $data, $matches);
            $service_id = $matches[1];
            $server_id = $matches[2];
            $category_id = $matches[3];

            $state_data = [
                'new_plan_category_id' => $category_id,
                'new_plan_server_id' => $server_id,
                'new_plan_marzneshin_service_id' => $service_id,
            ];
            updateUserData($chat_id, 'awaiting_plan_name', $state_data);
            sendMessage($chat_id, "1/7 - لطفا نام پلن را وارد کنید:", $cancelKeyboard);
            deleteMessage($chat_id, $message_id);
        }
        elseif (strpos($data, 'copy_toggle_') === 0 && hasPermission($chat_id, 'manage_payment')) {
            $toggle = str_replace('copy_toggle_', '', $data) === 'yes';
            $settings = getSettings();
            $settings['payment_method'] = ['card_number' => $user_data['state_data']['temp_card_number'], 'card_holder' => $user_data['state_data']['temp_card_holder'], 'copy_enabled' => $toggle];
            saveSettings($settings);
            updateUserData($chat_id, 'main_menu');
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ تنظیمات ذخیره شد']);
            editMessageText($chat_id, $message_id, "✅ تنظیمات روش پرداخت با موفقیت ذخیره شد.");
            handleMainMenu($chat_id, $first_name);
        }
        elseif (strpos($data, 'approve_') === 0 || strpos($data, 'reject_') === 0) {
            list($action, $request_id) = explode('_', $data);

            $stmt = pdo()->prepare("SELECT * FROM payment_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();

            if (!$request) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'خطا: درخواست یافت نشد.']);
                die;
            }

            if ($request['status'] !== 'pending') {
                $processed_admin_info = getUserData($request['processed_by_admin_id']);
                $processed_admin_name = htmlspecialchars($processed_admin_info['first_name'] ?? 'ادمین');
                $status_fa = $request['status'] == 'approved' ? 'تایید' : 'رد';

                apiRequest('answerCallbackQuery', [
                    'callback_query_id' => $callback_id,
                    'text' => "این درخواست قبلاً توسط {$processed_admin_name} {$status_fa} شده است.",
                    'show_alert' => true,
                ]);
                die;
            }

            $user_id_to_charge = $request['user_id'];
            $amount_to_charge = $request['amount'];
            $admin_who_processed = $update['callback_query']['from']['id'];
            $metadata = json_decode($request['metadata'], true);

            if ($action == 'approve') {
                pdo()->prepare("UPDATE payment_requests SET status = 'approved', processed_by_admin_id = ?, processed_at = NOW() WHERE id = ?")->execute([$admin_who_processed, $request_id]);

                if (isset($metadata['purpose']) && $metadata['purpose'] === 'complete_purchase') {
                    // این پرداخت برای تکمیل خرید یک پلن
                    $plan_id = $metadata['plan_id'];
                    $discount_code = $metadata['discount_code'] ?? null;
                    $custom_volume = $metadata['custom_volume_gb'] ?? null;
                    $custom_duration = $metadata['custom_duration_days'] ?? null;
                    
                    $plan = getPlanById($plan_id);
                    
                    // محاسبه قیمت - اگر پلن قابل تنظیم باشد
                    if ($custom_volume !== null && $custom_duration !== null) {
                        // استفاده از قیمت تمدید اگر در پلن تنظیم نشده باشد
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
                        
                        $base_price = ($custom_volume * $price_per_gb) + ($custom_duration * $price_per_day);
                    } else {
                        $base_price = (float)$plan['price'];
                    }
                    
                    $final_price = $base_price;
                    $discount_applied = false;
                    $discount_object = null;

                    if ($discount_code) {
                        $stmt_discount = pdo()->prepare("SELECT * FROM discount_codes WHERE code = ?");
                        $stmt_discount->execute([$discount_code]);
                        $discount_object = $stmt_discount->fetch();
                        if ($discount_object) {
                             if ($discount_object['type'] == 'percent') {
                                $final_price = $base_price - ($base_price * $discount_object['value']) / 100;
                            } else {
                                $final_price = $base_price - $discount_object['value'];
                            }
                            $final_price = max(0, $final_price);
                            $discount_applied = true;
                        }
                    }
                    
                    // شارژ موقت حساب کاربر با مبلغ پرداختی
                    updateUserBalance($user_id_to_charge, $amount_to_charge, 'add');

                    $custom_name = $metadata['custom_name'] ?? 'سرویس'; 
                    $purchase_result = completePurchase($user_id_to_charge, $plan_id, $custom_name, $final_price, $discount_code, $discount_object, $discount_applied, $custom_volume, $custom_duration);

                    if ($purchase_result['success']) {
                        // اگر keyboard از completePurchase برگشته باشد، دکمه web_app در آن است
                        $final_keyboard = $purchase_result['keyboard'] ?? null;
                        
                        // ارسال عکس QR code با keyboard
                        sendPhoto($user_id_to_charge, $purchase_result['qr_code_url'], $purchase_result['caption'], $final_keyboard);
                        
                        // ارسال اعلان به ادمین
                        sendMessage(ADMIN_CHAT_ID, $purchase_result['admin_notification']);
                    } else {
                         sendMessage($user_id_to_charge, "❌ پرداخت شما تایید شد اما در ایجاد سرویس خطایی رخ داد. مبلغ پرداخت شده به موجودی شما اضافه شد. لطفاً با پشتیبانی تماس بگیرید.");
                         
                         // ارسال خطای دقیق به ادمین
                         $admin_error_message = "⚠️ <b>خطای ساخت سرویس بعد از پرداخت</b>\n\n";
                         $admin_error_message .= "👤 کاربر: <code>{$user_id_to_charge}</code>\n";
                         $admin_error_message .= "📦 پلن: <b>{$plan['name']}</b>\n";
                         $admin_error_message .= "💰 مبلغ: <b>" . number_format($final_price) . " تومان</b>\n";
                         $admin_error_message .= "🖥️ سرور: <b>{$plan['server_id']}</b>\n\n";
                         
                         if (isset($purchase_result['error_details'])) {
                             $admin_error_message .= "❌ خطا: <code>" . htmlspecialchars($purchase_result['error_details']) . "</code>\n\n";
                         }
                         
                         if (isset($purchase_result['panel_error']) && is_array($purchase_result['panel_error'])) {
                             $panel_error = $purchase_result['panel_error'];
                             if (isset($panel_error['error'])) {
                                 $admin_error_message .= "🔍 جزئیات: <code>" . htmlspecialchars($panel_error['error']) . "</code>\n";
                             }
                             if (isset($panel_error['http_code'])) {
                                 $admin_error_message .= "📡 HTTP Code: <code>{$panel_error['http_code']}</code>\n";
                             }
                         }
                         
                         sendMessage(ADMIN_CHAT_ID, $admin_error_message);
                    }
                    updateUserData($user_id_to_charge, 'main_menu');

                } else {
                    // پرداخت برای شارژ عادی حساب بوده است
                    updateUserBalance($user_id_to_charge, $amount_to_charge, 'add');
                    $new_balance_data = getUserData($user_id_to_charge);
                    sendMessage($user_id_to_charge, "✅ حساب شما به مبلغ " . number_format($amount_to_charge) . " تومان شارژ شد.\nموجودی جدید: " . number_format($new_balance_data['balance']) . " تومان");
                }

                editMessageCaption($chat_id, $message_id, $update['callback_query']['message']['caption'] . "\n\n<b>✅ توسط شما تایید شد.</b>", null);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ درخواست تایید شد']);

            }
            elseif ($action == 'reject') {
                pdo()->prepare("UPDATE payment_requests SET status = 'rejected', processed_by_admin_id = ?, processed_at = NOW() WHERE id = ?")->execute([$admin_who_processed, $request_id]);

                sendMessage($user_id_to_charge, "❌ درخواست شارژ حساب شما به مبلغ " . number_format($amount_to_charge) . " تومان توسط ادمین رد شد.");

                editMessageCaption($chat_id, $message_id, $update['callback_query']['message']['caption'] . "\n\n<b>❌ توسط شما رد شد.</b>", null);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ درخواست رد شد']);
            }
        }
        elseif ($data === 'manage_servers' && hasPermission($chat_id, 'manage_marzban')) {
            $servers = pdo()
                ->query("SELECT id, name FROM servers")
                ->fetchAll(PDO::FETCH_ASSOC);
            $keyboard_buttons = [[['text' => '➕ افزودن سرور جدید', 'callback_data' => 'add_server_select_type']]];
            foreach ($servers as $server) {
                $keyboard_buttons[] = [['text' => "🖥 {$server['name']}", 'callback_data' => "view_server_{$server['id']}"]];
            }
            $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']];

            editMessageText($chat_id, $message_id, "<b>🌐 مدیریت سرورها</b>\n\nسرور مورد نظر را برای مشاهده یا حذف انتخاب کنید، یا یک سرور جدید اضافه کنید:", ['inline_keyboard' => $keyboard_buttons]);
        }
        elseif ($data === 'add_server_select_type' && hasPermission($chat_id, 'manage_marzban')) {
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '🔵 مرزبان', 'callback_data' => 'add_server_type_marzban'],
                    ['text' => '🟠 سنایی', 'callback_data' => 'add_server_type_sanaei']
                ],
                [
                    ['text' => '🟢 مرزنشین', 'callback_data' => 'add_server_type_marzneshin'],
                    ['text' => '🟣 هیدیفای', 'callback_data' => 'add_server_type_hiddify']
                ],
                [
                    ['text' => '🔶 علی رضا', 'callback_data' => 'add_server_type_alireza'],
                    ['text' => '🔴 PasarGuard (به زودی)', 'callback_data' => 'add_server_type_pasargad']
                ],
                [
                    ['text' => '🟡 TX-UI', 'callback_data' => 'add_server_type_txui'],
                    ['text' => '🟣 Rebecca (به زودی)', 'callback_data' => 'add_server_type_rebecca']
                ],
                [['text' => '◀️ بازگشت', 'callback_data' => 'manage_servers']],
            ]];
            editMessageText($chat_id, $message_id, "🌐 <b>انتخاب نوع پنل سرور</b>\n\nلطفا نوع پنل سرور را انتخاب کنید:", $keyboard);
        }
        elseif ($data === 'add_server_type_txui' && hasPermission($chat_id, 'manage_marzban')) {
            updateUserData($chat_id, 'admin_awaiting_server_name', ['selected_server_type' => 'txui', 'admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🌐 <b>افزودن سرور TX-UI</b>\n\nمرحله ۱/۴: لطفا نام سرور را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif ($data === 'add_server_type_pasargad' && hasPermission($chat_id, 'manage_marzban')) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '⚠️ این پنل در حال حاضر در دست توسعه است. (به زودی)', 'show_alert' => true]);
        }
        elseif ($data === 'add_server_type_rebecca' && hasPermission($chat_id, 'manage_marzban')) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '⚠️ این پنل در حال حاضر در دست توسعه است. (به زودی)', 'show_alert' => true]);
        }
        elseif ($data === 'add_server_type_hiddify' && hasPermission($chat_id, 'manage_marzban')) {
            updateUserData($chat_id, 'admin_awaiting_server_name', ['selected_server_type' => 'hiddify', 'admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🌐 <b>افزودن سرور هیدیفای</b>\n\nمرحله ۱/۳: لطفا نام سرور را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif ($data === 'add_server_type_alireza' && hasPermission($chat_id, 'manage_marzban')) {
            updateUserData($chat_id, 'admin_awaiting_server_name', ['selected_server_type' => 'alireza', 'admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🌐 <b>افزودن سرور علی رضا</b>\n\nمرحله ۱/۴: لطفا نام سرور را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'edit_protocols_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            $server_id = str_replace('edit_protocols_', '', $data);
            showMarzbanProtocolEditor($chat_id, $message_id, $server_id);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'toggle_protocol_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            preg_match('/toggle_protocol_(\d+)_(\w+)/', $data, $matches);
            $server_id = $matches[1];
            $protocol = $matches[2];
            
            $stmt_get = pdo()->prepare("SELECT marzban_protocols FROM servers WHERE id = ?");
            $stmt_get->execute([$server_id]);
            $protocols_json = $stmt_get->fetchColumn();
            
            $current_protocols = $protocols_json ? json_decode($protocols_json, true) : [];
            if (!is_array($current_protocols)) $current_protocols = [];

            if (in_array($protocol, $current_protocols)) {
                $current_protocols = array_diff($current_protocols, [$protocol]);
            } else {
                $current_protocols[] = $protocol;
            }
            
            $new_protocols_json = json_encode(array_values($current_protocols));
            $stmt_update = pdo()->prepare("UPDATE servers SET marzban_protocols = ? WHERE id = ?");
            $stmt_update->execute([$new_protocols_json, $server_id]);
            
            showMarzbanProtocolEditor($chat_id, $message_id, $server_id);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'add_server_type_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            $type = str_replace('add_server_type_', '', $data);
            // برای پنل‌های "به زودی"، فقط پیام نمایش بده
            if (in_array($type, ['pasargad', 'rebecca'])) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '⚠️ این پنل در حال حاضر در دست توسعه است. (به زودی)', 'show_alert' => true]);
                die;
            }
            deleteMessage($chat_id, $message_id);
            // تعیین نام فارسی پنل
            $panel_names = [
                'marzban' => 'مرزبان',
                'sanaei' => 'سنایی',
                'marzneshin' => 'مرزنشین',
                'hiddify' => 'هیدیفای',
                'alireza' => 'علی رضا',
                'txui' => 'TX-UI'
            ];
            $panel_name = $panel_names[$type] ?? ucfirst($type);
            updateUserData($chat_id, 'admin_awaiting_server_name', ['selected_server_type' => $type]);
            sendMessage($chat_id, "🌐 <b>افزودن سرور {$panel_name}</b>\n\nمرحله ۱/۴: یک نام دلخواه برای شناسایی سرور وارد کنید (مثال: آلمان-هتزنر):", $cancelKeyboard);
        }
                    elseif (strpos($data, 'view_server_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            $server_id = str_replace('view_server_', '', $data);
            $stmt = pdo()->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$server_id]);
            $server = $stmt->fetch();
            if ($server) {
                $panel_type_text = ucfirst($server['type']);
                if ($server['type'] === 'marzban') $panel_type_text = 'مرزبان';
                if ($server['type'] === 'sanaei') $panel_type_text = 'سنایی';
                if ($server['type'] === 'marzneshin') $panel_type_text = 'مرزنشین';
                if ($server['type'] === 'hiddify') $panel_type_text = 'هیدیفای';
                if ($server['type'] === 'alireza') $panel_type_text = 'علی رضا';
                if ($server['type'] === 'pasargad') $panel_type_text = 'PasarGuard';
                if ($server['type'] === 'rebecca') $panel_type_text = 'Rebecca';
                if ($server['type'] === 'txui') $panel_type_text = 'TX-UI';
                
                $msg = "<b>مشخصات سرور: {$server['name']}</b>\n\n";
                $msg .= "▫️ نوع پنل: <b>{$panel_type_text}</b>\n";
                $msg .= "▫️ آدرس مدیریت پنل: <code>{$server['url']}</code>\n";
                
                $keyboard_buttons = [];
                
                
                if ($server['type'] === 'sanaei' || $server['type'] === 'marzban') {
                    $sub_host_text = !empty($server['sub_host']) ? "<code>{$server['sub_host']}</code>" : "<i>پیش‌فرض (مانند آدرس پنل)</i>";
                    $msg .= "▫️ آدرس لینک اشتراک: {$sub_host_text}\n";
                    $keyboard_buttons[] = [['text' => '🔗 ویرایش آدرس ساب', 'callback_data' => "edit_sub_host_{$server_id}"]];
                }
                
                if ($server['type'] === 'marzban') {
                    $keyboard_buttons[] = [['text' => '⚙️ تنظیم پروتکل‌ها', 'callback_data' => "edit_protocols_{$server_id}"]];
                }
                
                $msg .= "▫️ نام کاربری: <code>{$server['username']}</code>\n";

                $keyboard_buttons[] = [['text' => '🗑 حذف این سرور', 'callback_data' => "delete_server_{$server_id}"]];
                $keyboard_buttons[] = [['text' => '◀️ بازگشت به لیست سرورها', 'callback_data' => 'manage_servers']];
                
                $keyboard = ['inline_keyboard' => $keyboard_buttons];
                editMessageText($chat_id, $message_id, $msg, $keyboard);
            }
        }
        elseif (strpos($data, 'edit_sub_host_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            $server_id = str_replace('edit_sub_host_', '', $data);
            updateUserData($chat_id, 'admin_awaiting_sub_host', ['editing_server_id' => $server_id]);
            $prompt = "لطفا آدرس کامل و عمومی که برای لینک اشتراک استفاده می‌شود را وارد کنید.\nاین آدرس باید شامل http/https و پورت صحیح باشد (مثال: http://your.domain.com:2096).\n\n💡 برای بازگشت به حالت پیش‌فرض (استفاده از همان آدرس پنل)، کلمه `reset` را ارسال کنید.";
            editMessageText($chat_id, $message_id, $prompt);
        }
        elseif (strpos($data, 'delete_server_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            $server_id = str_replace('delete_server_', '', $data);
            $stmt_check = pdo()->prepare("SELECT COUNT(*) FROM plans WHERE server_id = ?");
            $stmt_check->execute([$server_id]);
            if ($stmt_check->fetchColumn() > 0) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ نمی‌توانید این سرور را حذف کنید زیرا یک یا چند پلن به آن متصل هستند.', 'show_alert' => true]);
            }
            else {
                $stmt = pdo()->prepare("DELETE FROM servers WHERE id = ?");
                $stmt->execute([$server_id]);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ سرور با موفقیت حذف شد.']);
                $data = 'manage_servers'; 
            }
        }
        elseif (strpos($data, 'plan_set_sub_') === 0) {
            $show_sub = str_replace('plan_set_sub_', '', $data) === 'yes';
            $state_data = $user_data['state_data'];
            $state_data['temp_plan_data']['show_sub_link'] = $show_sub;
            updateUserData($chat_id, 'awaiting_plan_conf_link_setting', $state_data);
            $keyboard = ['inline_keyboard' => [[['text' => '✅ بله', 'callback_data' => 'plan_set_conf_yes'], ['text' => '❌ خیر', 'callback_data' => 'plan_set_conf_no']]]];
            editMessageText($chat_id, $message_id, "7/7 - سوال ۲/۲: آیا لینک‌های تکی کانفیگ‌ها به کاربر نمایش داده شود؟\n(پیشنهادی: بله)", $keyboard);
        }
        elseif (strpos($data, 'plan_custom_volume_enabled_') === 0) {
            $custom_enabled = str_replace('plan_custom_volume_enabled_', '', $data) === 'yes';
            $state_data = $user_data['state_data'];
            $state_data['new_plan_custom_volume_enabled'] = $custom_enabled ? 1 : 0;
            updateUserData($chat_id, $custom_enabled ? 'awaiting_plan_min_volume' : 'awaiting_plan_volume', $state_data);
            if ($custom_enabled) {
                // برای پلن قابل تنظیم، همه مقادیر مرتبط را 0 می‌گذاریم تا بعداً پر شوند
                $state_data['new_plan_min_volume_gb'] = 0;
                $state_data['new_plan_max_volume_gb'] = 0;
                $state_data['new_plan_min_duration_days'] = 0;
                $state_data['new_plan_max_duration_days'] = 0;
                $state_data['new_plan_price_per_gb'] = 0.00;
                $state_data['new_plan_price_per_day'] = 0.00;
                updateUserData($chat_id, 'awaiting_plan_min_volume', $state_data);
                $keyboard = ['keyboard' => [[['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                editMessageText($chat_id, $message_id, "✅ پلن قابل تنظیم فعال شد.\n\n3.1/7 - حداقل حجم را به گیگابایت (GB) وارد کنید (فقط عدد):", ['inline_keyboard' => []]);
                sendMessage($chat_id, "✅ پلن قابل تنظیم فعال شد.\n\n3.1/7 - حداقل حجم را به گیگابایت (GB) وارد کنید (فقط عدد):", $keyboard);
            } else {
                // برای پلن عادی، مقادیر قابل تنظیم را 0 می‌گذاریم
                $state_data['new_plan_min_volume_gb'] = 0;
                $state_data['new_plan_max_volume_gb'] = 0;
                $state_data['new_plan_min_duration_days'] = 0;
                $state_data['new_plan_max_duration_days'] = 0;
                $state_data['new_plan_price_per_gb'] = 0.00;
                $state_data['new_plan_price_per_day'] = 0.00;
                updateUserData($chat_id, 'awaiting_plan_volume', $state_data);
                $keyboard = ['inline_keyboard' => [
                    [['text' => '♾️ نامحدود', 'callback_data' => 'plan_volume_unlimited']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_admin_panel']]
                ]];
                editMessageText($chat_id, $message_id, "✅ پلن عادی انتخاب شد.\n\n3/7 - لطفا حجم پلن را به گیگابایت (GB) وارد کنید (فقط عدد) یا دکمه نامحدود را انتخاب کنید:", $keyboard);
            }
        }
        elseif (strpos($data, 'plan_set_conf_') === 0) {
            $show_conf = str_replace('plan_set_conf_', '', $data) === 'yes';
            $final_plan_data = $user_data['state_data']['temp_plan_data'] ?? null;
            if ($final_plan_data) {
                $final_plan_data['show_conf_links'] = $show_conf;
                $stmt = pdo()->prepare(
                    "INSERT INTO plans (server_id, inbound_id, marzneshin_service_id, category_id, name, price, volume_gb, duration_days, description, show_sub_link, show_conf_links, status, purchase_limit, custom_volume_enabled, min_volume_gb, max_volume_gb, min_duration_days, max_duration_days, price_per_gb, price_per_day) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $final_plan_data['server_id'],
                    $final_plan_data['inbound_id'] ?? null,
                    $final_plan_data['marzneshin_service_id'] ?? null,
                    $final_plan_data['category_id'],
                    $final_plan_data['name'],
                    $final_plan_data['price'],
                    $final_plan_data['volume_gb'],
                    $final_plan_data['duration_days'],
                    $final_plan_data['description'],
                    $final_plan_data['show_sub_link'],
                    $final_plan_data['show_conf_links'],
                    $final_plan_data['purchase_limit'],
                    $final_plan_data['custom_volume_enabled'] ?? 0,
                    $final_plan_data['min_volume_gb'] ?? 0,
                    $final_plan_data['max_volume_gb'] ?? 0,
                    $final_plan_data['min_duration_days'] ?? 0,
                    $final_plan_data['max_duration_days'] ?? 0,
                    $final_plan_data['price_per_gb'] ?? 0.00,
                    $final_plan_data['price_per_day'] ?? 0.00,
                ]);
                editMessageText($chat_id, $message_id, "✅ پلن جدید با تمام تنظیمات با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
            }
            else {
                editMessageText($chat_id, $message_id, "❌ خطا در ذخیره‌سازی پلن. لطفا مجددا تلاش کنید.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
            }
        }
        elseif (strpos($data, 'discount_type_') === 0) {
            $type = str_replace('discount_type_', '', $data);
            $state_data = $user_data['state_data'];
            $state_data['new_discount_type'] = $type;
            updateUserData($chat_id, 'admin_awaiting_discount_value', $state_data);
            $unit = $type == 'percent' ? 'درصد' : 'تومان';
            editMessageText($chat_id, $message_id, "3/4 - لطفاً مقدار تخفیف را به $unit وارد کنید (فقط عدد):");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'delete_discount_') === 0) {
            $code_id = str_replace('delete_discount_', '', $data);
            pdo()
                ->prepare("DELETE FROM discount_codes WHERE id = ?")
                ->execute([$code_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ کد تخفیف حذف شد.']);
            deleteMessage($chat_id, $message_id);
        }
        elseif (strpos($data, 'toggle_discount_') === 0) {
            $code_id = str_replace('toggle_discount_', '', $data);
            pdo()
                ->prepare("UPDATE discount_codes SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")
                ->execute([$code_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت کد تخفیف تغییر کرد.']);
            deleteMessage($chat_id, $message_id);
            generateDiscountCodeList($chat_id);
        }
        elseif (strpos($data, 'delete_guide_') === 0 && hasPermission($chat_id, 'manage_guides')) {
            $guide_id = str_replace('delete_guide_', '', $data);
            pdo()
                ->prepare("DELETE FROM guides WHERE id = ?")
                ->execute([$guide_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ راهنما حذف شد.']);
            deleteMessage($chat_id, $message_id);
            generateGuideList($chat_id);
        }
        elseif (strpos($data, 'toggle_guide_') === 0 && hasPermission($chat_id, 'manage_guides')) {
            $guide_id = str_replace('toggle_guide_', '', $data);
            pdo()
                ->prepare("UPDATE guides SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")
                ->execute([$guide_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت راهنما تغییر کرد.']);
            deleteMessage($chat_id, $message_id);
            generateGuideList($chat_id);
        }
        elseif (strpos($data, 'reset_plan_count_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('reset_plan_count_', '', $data);
            pdo()
                ->prepare("UPDATE plans SET purchase_count = 0 WHERE id = ?")
                ->execute([$plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ تعداد خرید با موفقیت ریست شد.']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif ($data == 'set_config_naming' && hasPermission($chat_id, 'manage_settings')) {
            updateUserData($chat_id, 'admin_awaiting_config_prefix', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🏷️ <b>تنظیم نام کانفیگ</b>\n\nمرحله ۱/۲: لطفاً پیشوند (Prefix) نام کانفیگ را وارد کنید:\n\nمثال: <code>itzVPN_</code>\n\n⚠️ فقط از حروف انگلیسی، اعداد، خط تیره و زیرخط استفاده کنید.", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data == 'reset_config_counter' && hasPermission($chat_id, 'manage_settings')) {
            if (class_exists('ConfigNaming')) {
                $configNaming = ConfigNaming::getInstance();
                $settings = getSettings();
                $currentStart = (int)($settings['config_start_number'] ?? 0);
                $configNaming->resetCounter($currentStart);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ شمارنده با موفقیت ریست شد.']);
                editMessageText($chat_id, $message_id, "✅ شمارنده نام کانفیگ با موفقیت ریست شد.\nشماره بعدی: <b>{$currentStart}</b>", ['inline_keyboard' => [[['text' => '◀️ بازگشت', 'callback_data' => 'back_to_admin_panel']]]]);
            } else {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ خطا در دسترسی به سیستم نام‌گذاری.', 'show_alert' => true]);
            }
            die;
        }

        // --- مدیریت ضد اسپم ---
        elseif ($data == 'toggle_antispam_status' && hasPermission($chat_id, 'manage_settings')) {
            if (file_exists(__DIR__ . '/includes/AntiSpam.php') && class_exists('AntiSpam')) {
                require_once __DIR__ . '/includes/AntiSpam.php';
                $antiSpam = AntiSpam::getInstance();
                $settings = getSettings();
                $currentStatus = $settings['antispam_enabled'] ?? 'off';
                $newStatus = ($currentStatus == 'on') ? 'off' : 'on';
                $settings['antispam_enabled'] = $newStatus;
                saveSettings($settings);
                $antiSpam->updateSettings(['enabled' => $newStatus]);
                
                $statusText = $newStatus == 'on' ? 'فعال' : 'غیرفعال';
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "✅ ضد اسپم {$statusText} شد."]);
                
                // به‌روزرسانی منو
                $antiSpamSettings = $antiSpam->getSettings();
                $status_icon = ($antiSpamSettings['enabled'] ?? 'off') == 'on' ? '✅' : '❌';
                $message = "<b>🛡️ مدیریت ضد اسپم</b>\n\n";
                $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
                $message .= "▫️ حداکثر اعمال: <b>" . ($antiSpamSettings['max_actions'] ?? 10) . "</b>\n";
                $message .= "▫️ بازه زمانی: <b>" . ($antiSpamSettings['time_window'] ?? 5) . " ثانیه</b>\n";
                $message .= "▫️ مدت زمان میوت: <b>" . ($antiSpamSettings['mute_duration'] ?? 60) . " دقیقه</b>\n";
                $message .= "▫️ پیام مسدودیت: <code>" . htmlspecialchars(substr($antiSpamSettings['message'] ?? '', 0, 50)) . "...</code>\n\n";
                $message .= "برای تنظیم ضد اسپم، گزینه مورد نظر را انتخاب کنید:";
                
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => $status_icon . ' فعال/غیرفعال کردن', 'callback_data' => 'toggle_antispam_status']],
                        [['text' => '⚙️ تنظیم حداکثر اعمال', 'callback_data' => 'set_antispam_max_actions']],
                        [['text' => '⏱️ تنظیم بازه زمانی', 'callback_data' => 'set_antispam_time_window']],
                        [['text' => '🔇 تنظیم مدت زمان میوت', 'callback_data' => 'set_antispam_mute_duration']],
                        [['text' => '💬 تنظیم پیام مسدودیت', 'callback_data' => 'set_antispam_message']],
                        [['text' => '◀️ بازگشت به تنظیمات', 'callback_data' => 'back_to_admin_panel']]
                    ]
                ];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            }
            die;
        }
        elseif ($data == 'set_antispam_max_actions' && hasPermission($chat_id, 'manage_settings')) {
            updateUserData($chat_id, 'admin_awaiting_antispam_max_actions', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🛡️ <b>تنظیم حداکثر اعمال</b>\n\nلطفاً حداکثر تعداد اعمال مجاز در بازه زمانی را وارد کنید:\n\nمثال: <code>10</code>\n\n⚠️ اگر کاربر در بازه زمانی مشخص شده بیشتر از این تعداد عمل انجام دهد، مسدود می‌شود.", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data == 'set_antispam_time_window' && hasPermission($chat_id, 'manage_settings')) {
            updateUserData($chat_id, 'admin_awaiting_antispam_time_window', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🛡️ <b>تنظیم بازه زمانی</b>\n\nلطفاً بازه زمانی را به ثانیه وارد کنید:\n\nمثال: <code>5</code> (برای 5 ثانیه)\n\n⚠️ این بازه زمانی برای شمارش اعمال کاربر استفاده می‌شود.", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data == 'set_antispam_mute_duration' && hasPermission($chat_id, 'manage_settings')) {
            updateUserData($chat_id, 'admin_awaiting_antispam_mute_duration', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🛡️ <b>تنظیم مدت زمان میوت</b>\n\nلطفاً مدت زمان میوت را به دقیقه وارد کنید:\n\nمثال: <code>60</code> (برای 60 دقیقه)\n\n⚠️ بعد از این مدت زمان، کاربر میوت شده می‌تواند مجدداً از ربات استفاده کند.", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif ($data == 'set_antispam_message' && hasPermission($chat_id, 'manage_settings')) {
            updateUserData($chat_id, 'admin_awaiting_antispam_message', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "🛡️ <b>تنظیم پیام مسدودیت</b>\n\nلطفاً پیامی که می‌خواهید به کاربر مسدود شده نمایش داده شود را وارد کنید:\n\n⚠️ این پیام به کاربری که اسپم کرده است نمایش داده می‌شود.", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }

        // --- مدیریت لاگ‌ها ---
        elseif ($data == 'set_log_group' && hasPermission($chat_id, 'manage_settings')) {
            updateUserData($chat_id, 'admin_awaiting_log_group_id', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "📋 <b>تنظیم گروه لاگ‌ها</b>\n\nلطفاً آیدی عددی گروه خصوصی که می‌خواهید لاگ‌ها در آن ارسال شوند را وارد کنید:\n\n⚠️ نکته: ابتدا ربات را به گروه اضافه کنید و سپس آیدی گروه را ارسال کنید.", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif (in_array($data, ['toggle_log_server', 'toggle_log_error', 'toggle_log_purchase', 'toggle_log_transaction', 'toggle_log_user_new', 'toggle_log_user_ban', 'toggle_log_admin_action', 'toggle_log_payment', 'toggle_log_config_create', 'toggle_log_config_delete']) && hasPermission($chat_id, 'manage_settings')) {
            if (class_exists('LogManager')) {
                $logManager = LogManager::getInstance();
                $logType = str_replace('toggle_log_', '', $data);
                $currentStatus = $logManager->isLogTypeEnabled($logType);
                $newStatus = !$currentStatus;
                
                if ($logManager->toggleLogType($logType, $newStatus)) {
                    $statusText = $newStatus ? 'فعال' : 'غیرفعال';
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "✅ لاگ {$logType} {$statusText} شد."]);
                    
                    // به‌روزرسانی منو
                    $logSettings = $logManager->getLogSettings();
                    $groupId = $logSettings['group_id'] ?? null;
                    $logTypes = $logSettings['types'] ?? [];
                    
                    $message = "<b>📋 مدیریت لاگ‌ها</b>\n\n";
                    if ($groupId) {
                        $message .= "👥 گروه لاگ‌ها: <code>{$groupId}</code>\n\n";
                    } else {
                        $message .= "⚠️ گروه لاگ‌ها تنظیم نشده است.\n\n";
                    }
                    $message .= "برای تنظیم گروه لاگ‌ها و فعال/غیرفعال کردن انواع لاگ‌ها، گزینه مورد نظر را انتخاب کنید:";
                    
                    $keyboard = [
                        'inline_keyboard' => [
                            [['text' => '👥 تنظیم گروه لاگ‌ها', 'callback_data' => 'set_log_group']],
                            [['text' => ($logTypes['server'] ?? false ? '✅' : '❌') . ' لاگ سرور', 'callback_data' => 'toggle_log_server']],
                            [['text' => ($logTypes['error'] ?? false ? '✅' : '❌') . ' لاگ خطاها', 'callback_data' => 'toggle_log_error']],
                            [['text' => ($logTypes['purchase'] ?? false ? '✅' : '❌') . ' لاگ خریدها', 'callback_data' => 'toggle_log_purchase']],
                            [['text' => ($logTypes['transaction'] ?? false ? '✅' : '❌') . ' لاگ تراکنش‌ها', 'callback_data' => 'toggle_log_transaction']],
                            [['text' => ($logTypes['user_new'] ?? false ? '✅' : '❌') . ' لاگ کاربران جدید', 'callback_data' => 'toggle_log_user_new']],
                            [['text' => ($logTypes['user_ban'] ?? false ? '✅' : '❌') . ' لاگ مسدود کردن کاربر', 'callback_data' => 'toggle_log_user_ban']],
                            [['text' => ($logTypes['admin_action'] ?? false ? '✅' : '❌') . ' لاگ اقدامات ادمین', 'callback_data' => 'toggle_log_admin_action']],
                            [['text' => ($logTypes['payment'] ?? false ? '✅' : '❌') . ' لاگ پرداخت‌ها', 'callback_data' => 'toggle_log_payment']],
                            [['text' => ($logTypes['config_create'] ?? false ? '✅' : '❌') . ' لاگ ایجاد کانفیگ', 'callback_data' => 'toggle_log_config_create']],
                            [['text' => ($logTypes['config_delete'] ?? false ? '✅' : '❌') . ' لاگ حذف کانفیگ', 'callback_data' => 'toggle_log_config_delete']],
                            [['text' => '◀️ بازگشت به تنظیمات', 'callback_data' => 'back_to_admin_panel']]
                        ]
                    ];
                    
                    editMessageText($chat_id, $message_id, $message, $keyboard);
                } else {
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ خطا در تغییر وضعیت لاگ.', 'show_alert' => true]);
                }
            } else {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ سیستم مدیریت لاگ‌ها در دسترس نیست.', 'show_alert' => true]);
            }
            die;
        }

        if (strpos($data, 'set_as_test_plan_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('set_as_test_plan_', '', $data);
            pdo()->exec("UPDATE plans SET is_test_plan = 0");
            pdo()
                ->prepare("UPDATE plans SET is_test_plan = 1, price = 0, status = 'active' WHERE id = ?")
                ->execute([$plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ این پلن به عنوان پلن تست تنظیم شد.']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif (strpos($data, 'make_plan_normal_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('make_plan_normal_', '', $data);
            pdo()
                ->prepare("UPDATE plans SET is_test_plan = 0 WHERE id = ?")
                ->execute([$plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ این پلن به یک پلن عادی تبدیل شد.']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }

        // --- مدیریت ارسال پیام به ادمین‌ها ---
        if ($data == 'admin_notifications_menu') {
            if (!hasPermission($chat_id, 'broadcast')) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'شما دسترسی لازم را ندارید.', 'show_alert' => true]);
                die;
            }
            
            $adminMessenger = AdminMessenger::getInstance();
            $admins = $adminMessenger->getAdminsList();
            
            $message = "<b>👨‍💼 ارسال پیام به ادمین‌ها</b>\n\n";
            $message .= "لطفاً نوع ارسال را انتخاب کنید:";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '📢 ارسال به همه ادمین‌ها', 'callback_data' => 'send_to_all_admins']],
                    [['text' => '👤 ارسال به ادمین خاص', 'callback_data' => 'send_to_specific_admin']],
                    [['text' => '📋 لیست ادمین‌ها', 'callback_data' => 'list_admins']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_admin_panel']]
                ]
            ];
            
            editMessageText($chat_id, $message_id, $message, $keyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        
        if ($data == 'send_to_all_admins') {
            if (!hasPermission($chat_id, 'broadcast')) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'شما دسترسی لازم را ندارید.', 'show_alert' => true]);
                die;
            }
            
            updateUserData($chat_id, 'admin_awaiting_message_for_all_admins', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "📢 <b>ارسال پیام به همه ادمین‌ها</b>\n\nلطفاً پیامی که می‌خواهید به تمام ادمین‌ها ارسال شود را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        
        if ($data == 'send_to_specific_admin') {
            if (!hasPermission($chat_id, 'broadcast')) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'شما دسترسی لازم را ندارید.', 'show_alert' => true]);
                die;
            }
            
            updateUserData($chat_id, 'admin_awaiting_admin_id_for_message', ['admin_view' => 'admin']);
            editMessageText($chat_id, $message_id, "👤 <b>ارسال پیام به ادمین خاص</b>\n\nلطفاً آیدی عددی ادمین مورد نظر را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        
        if ($data == 'list_admins') {
            if (!hasPermission($chat_id, 'broadcast')) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'شما دسترسی لازم را ندارید.', 'show_alert' => true]);
                die;
            }
            
            $adminMessenger = AdminMessenger::getInstance();
            $admins = $adminMessenger->getAdminsList();
            
            $message = "<b>📋 لیست ادمین‌ها</b>\n\n";
            foreach ($admins as $admin) {
                $role = $admin['is_super_admin'] ? '👑 ادمین اصلی' : '👤 ادمین';
                $message .= "{$role}: " . htmlspecialchars($admin['first_name']) . " (<code>{$admin['chat_id']}</code>)\n";
            }
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '◀️ بازگشت', 'callback_data' => 'admin_notifications_menu']]
                ]
            ];
            
            editMessageText($chat_id, $message_id, $message, $keyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif (($data == 'user_notifications_menu' || $data == 'config_expire_warning' || $data == 'config_inactive_reminder') && hasPermission($chat_id, 'manage_notifications')) {
            $settings = getSettings();
            $expire_status_icon = ($settings['notification_expire_status'] ?? 'off') == 'on' ? '✅' : '❌';
            $inactive_status_icon = ($settings['notification_inactive_status'] ?? 'off') == 'on' ? '✅' : '❌';

            if ($data == 'user_notifications_menu') {
                $message =
                    "<b>📢 مدیریت اعلان‌های کاربران</b>\n\n" .
                    "<b>- هشدار انقضا:</b> " .
                    ($expire_status_icon == '✅' ? 'فعال' : 'غیرفعال') .
                    "\n" .
                    "<b>- یادآور عدم فعالیت:</b> " .
                    ($inactive_status_icon == '✅' ? 'فعال' : 'غیرفعال') .
                    "\n\n" .
                    "گزینه مورد نظر را برای مدیریت انتخاب کنید:";
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '⚙️ تنظیمات هشدار انقضا', 'callback_data' => 'config_expire_warning']],
                        [['text' => '⚙️ تنظیمات یادآور عدم فعالیت', 'callback_data' => 'config_inactive_reminder']],
                        [['text' => '◀️ بازگشت به پنل مدیریت', 'callback_data' => 'back_to_admin_panel']],
                    ],
                ];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            }
            elseif ($data == 'config_expire_warning') {
                $message =
                    "<b>⚙️ تنظیمات هشدار انقضا</b>\n\nاین پیام زمانی برای کاربر ارسال می‌شود که حجم یا زمان سرویس او رو به اتمام باشد.\n\n" .
                    "▫️وضعیت: <b>" .
                    ($expire_status_icon == '✅' ? 'فعال' : 'غیرفعال') .
                    "</b>\n" .
                    "▫️ارسال هشدار <b>{$settings['notification_expire_days']}</b> روز مانده به انقضا\n" .
                    "▫️ارسال هشدار وقتی حجم کمتر از <b>{$settings['notification_expire_gb']}</b> گیگابایت باشد";
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => $expire_status_icon . " فعال/غیرفعال کردن", 'callback_data' => 'toggle_expire_notification']],
                        [['text' => '⏰ تنظیم روز', 'callback_data' => 'set_expire_days'], ['text' => '📊 تنظیم حجم', 'callback_data' => 'set_expire_gb']],
                        [['text' => '✍️ ویرایش متن پیام', 'callback_data' => 'edit_expire_message']],
                        [['text' => '◀️ بازگشت', 'callback_data' => 'user_notifications_menu']],
                    ],
                ];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            }
            elseif ($data == 'config_inactive_reminder') {
                $message =
                    "<b>⚙️ تنظیمات یادآور عدم فعالیت</b>\n\nاین پیام زمانی برای کاربر ارسال می‌شود که برای مدت طولانی از ربات استفاده نکرده باشد.\n\n" .
                    "▫️وضعیت: <b>" .
                    ($inactive_status_icon == '✅' ? 'فعال' : 'غیرفعال') .
                    "</b>\n" .
                    "▫️ارسال یادآور پس از <b>{$settings['notification_inactive_days']}</b> روز عدم فعالیت";
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => $inactive_status_icon . " فعال/غیرفعال کردن", 'callback_data' => 'toggle_inactive_notification']],
                        [['text' => '⏰ تنظیم روز', 'callback_data' => 'set_inactive_days']],
                        [['text' => '✍️ ویرایش متن پیام', 'callback_data' => 'edit_inactive_message']],
                        [['text' => '◀️ بازگشت', 'callback_data' => 'user_notifications_menu']],
                    ],
                ];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            }
        }
        elseif (strpos($data, 'toggle_expire_notification') === 0 && hasPermission($chat_id, 'manage_notifications')) {
            $settings = getSettings();
            $settings['notification_expire_status'] = ($settings['notification_expire_status'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            $data = 'config_expire_warning';
        }
        elseif (strpos($data, 'toggle_inactive_notification') === 0 && hasPermission($chat_id, 'manage_notifications')) {
            $settings = getSettings();
            $settings['notification_inactive_status'] = ($settings['notification_inactive_status'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            $data = 'config_inactive_reminder';
        }
        elseif (in_array($data, ['set_expire_days', 'set_expire_gb', 'edit_expire_message', 'set_inactive_days', 'edit_inactive_message']) && hasPermission($chat_id, 'manage_notifications')) {
            deleteMessage($chat_id, $message_id);
            switch ($data) {
                case 'set_expire_days':
                    updateUserData($chat_id, 'admin_awaiting_expire_days');
                    sendMessage($chat_id, "لطفا تعداد روز مانده به انقضا برای ارسال هشدار را وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'set_expire_gb':
                    updateUserData($chat_id, 'admin_awaiting_expire_gb');
                    sendMessage($chat_id, "لطفا حجم باقیمانده (به گیگابایت) برای ارسال هشدار را وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'edit_expire_message':
                    updateUserData($chat_id, 'admin_awaiting_expire_message');
                    sendMessage($chat_id, "لطفا متن کامل پیام هشدار انقضا را وارد کنید:", $cancelKeyboard);
                    break;
                case 'set_inactive_days':
                    updateUserData($chat_id, 'admin_awaiting_inactive_days');
                    sendMessage($chat_id, "لطفا تعداد روز عدم فعالیت برای ارسال یادآور را وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'edit_inactive_message':
                    updateUserData($chat_id, 'admin_awaiting_inactive_message');
                    sendMessage($chat_id, "لطفا متن کامل پیام یادآور عدم فعالیت را وارد کنید:", $cancelKeyboard);
                    break;
            }
        }
        if (
            in_array($user_state, ['admin_awaiting_expire_days', 'admin_awaiting_expire_gb', 'admin_awaiting_expire_message', 'admin_awaiting_inactive_days', 'admin_awaiting_inactive_message']) ||
            in_array($data, ['toggle_expire_notification', 'toggle_inactive_notification', 'manage_servers'])
        ) {
            if ($data === 'manage_servers') {
                $servers = pdo()
                    ->query("SELECT id, name FROM servers")
                    ->fetchAll(PDO::FETCH_ASSOC);
                $keyboard_buttons = [[['text' => '➕ افزودن سرور جدید', 'callback_data' => 'add_server_select_type']]];
                foreach ($servers as $server) {
                    $keyboard_buttons[] = [['text' => "🖥 {$server['name']}", 'callback_data' => "view_server_{$server['id']}"]];
                }
                $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']];
                editMessageText($chat_id, $message_id, "<b>🌐 مدیریت سرورها</b>\n\nسرور مورد نظر را برای مشاهده یا حذف انتخاب کنید، یا یک سرور جدید اضافه کنید:", ['inline_keyboard' => $keyboard_buttons]);
            }
            else {
                $menu_to_refresh = strpos($data, 'inactive') !== false || strpos($user_state, 'inactive') !== false ? 'config_inactive_reminder' : 'config_expire_warning';
                $message_id = sendMessage($chat_id, "درحال بارگذاری مجدد منو...")['result']['message_id'];
                $data = $menu_to_refresh;
            }
        }

        if (strpos($data, 'set_verification_') === 0 && hasPermission($chat_id, 'manage_verification')) {
            $method = str_replace('set_verification_', '', $data);
            $settings = getSettings();
            $settings['verification_method'] = $method;
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ روش احراز هویت تغییر کرد.']);
            showVerificationManagementMenu($chat_id);
            die;
        }
        if ($data == 'toggle_verification_iran_only' && hasPermission($chat_id, 'manage_verification')) {
            $settings = getSettings();
            $settings['verification_iran_only'] = $settings['verification_iran_only'] == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ تنظیمات ذخیره شد.']);
            showVerificationManagementMenu($chat_id);
            die;
        }

        if ($chat_id == ADMIN_CHAT_ID) {
            if ($data == 'add_admin') {
                $admins = getAdmins();
                if (count($admins) >= 9) {
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ حداکثر تعداد ادمین‌ها (۱۰) ثبت شده است.', 'show_alert' => true]);
                }
                else {
                    updateUserData($chat_id, 'admin_awaiting_new_admin_id');
                    editMessageText($chat_id, $message_id, "لطفا شناسه عددی (Chat ID) کاربر مورد نظر را برای افزودن به لیست ادمین‌ها وارد کنید:");
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                }
            }
            elseif (strpos($data, 'edit_admin_permissions_') === 0) {
                $target_admin_id = str_replace('edit_admin_permissions_', '', $data);
                showPermissionEditor($chat_id, $message_id, $target_admin_id);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            }
            elseif (strpos($data, 'toggle_perm_') === 0) {
                $payload = substr($data, strlen('toggle_perm_'));
                $parts = explode('_', $payload, 2);
                if (count($parts) === 2) {
                    $target_admin_id = $parts[0];
                    $permission_key = $parts[1];
                    $admins = getAdmins();
                    if (isset($admins[$target_admin_id])) {
                        $current_permissions = $admins[$target_admin_id]['permissions'] ?? [];
                        if (($key = array_search($permission_key, $current_permissions)) !== false) {
                            unset($current_permissions[$key]);
                        }
                        else {
                            $current_permissions[] = $permission_key;
                        }
                        updateAdminPermissions($target_admin_id, array_values($current_permissions));
                        showPermissionEditor($chat_id, $message_id, $target_admin_id);
                    }
                }
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            }
            elseif (strpos($data, 'delete_admin_confirm_') === 0) {
                $target_admin_id = str_replace('delete_admin_confirm_', '', $data);
                $keyboard = ['inline_keyboard' => [[['text' => '✅ بله، حذف کن', 'callback_data' => "delete_admin_do_{$target_admin_id}"]], [['text' => '❌ انصراف', 'callback_data' => "edit_admin_permissions_{$target_admin_id}"]]]];
                editMessageText($chat_id, $message_id, "⚠️ آیا از حذف این ادمین مطمئن هستید؟", $keyboard);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            }
            elseif (strpos($data, 'delete_admin_do_') === 0) {
                $target_admin_id = str_replace('delete_admin_do_', '', $data);
                $result = removeAdmin($target_admin_id);
                if ($result) {
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ ادمین با موفقیت حذف شد.']);
                    $admins = getAdmins();
                    $message = "<b>👨‍💼 مدیریت ادمین‌ها</b>\n\nادمین مورد نظر حذف شد. لیست جدید ادمین‌ها:";
                    $keyboard_buttons = [];
                    if (count($admins) < 9) {
                        $keyboard_buttons[] = [['text' => '➕ افزودن ادمین جدید', 'callback_data' => 'add_admin']];
                    }
                    foreach ($admins as $admin_id => $admin_data) {
                        $admin_name = htmlspecialchars($admin_data['first_name'] ?? "ادمین $admin_id");
                        $keyboard_buttons[] = [['text' => "👤 {$admin_name}", 'callback_data' => "edit_admin_permissions_{$admin_id}"]];
                    }
                    $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل مدیریت', 'callback_data' => 'back_to_admin_panel']];
                    editMessageText($chat_id, $message_id, $message, ['inline_keyboard' => $keyboard_buttons]);
                }
                else {
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ خطا در حذف ادمین.', 'show_alert' => true]);
                }
            }
            elseif ($data == 'back_to_admin_list') {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                $admins = getAdmins();
                $message = "<b>👨‍💼 مدیریت ادمین‌ها</b>\n\nدر این بخش می‌توانید ادمین‌های ربات و دسترسی‌های آن‌ها را مدیریت کنید. (حداکثر ۱۰ ادمین)";
                $keyboard_buttons = [];
                if (count($admins) < 9) {
                    $keyboard_buttons[] = [['text' => '➕ افزودن ادمین جدید', 'callback_data' => 'add_admin']];
                }
                foreach ($admins as $admin_id => $admin_data) {
                    $admin_name = htmlspecialchars($admin_data['first_name'] ?? "ادمین $admin_id");
                    $keyboard_buttons[] = [['text' => "👤 {$admin_name}", 'callback_data' => "edit_admin_permissions_{$admin_id}"]];
                }
                $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل مدیریت', 'callback_data' => 'back_to_admin_panel']];
                editMessageText($chat_id, $message_id, $message, ['inline_keyboard' => $keyboard_buttons]);
            }
            elseif ($data == 'back_to_admin_panel') {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                deleteMessage($chat_id, $message_id);
                handleMainMenu($chat_id, $first_name);
            }
        }
    }

    // --- منطق دکمه‌های تیکت پشتیبانی ---
    if (strpos($data, 'reply_ticket_') === 0) {
        if ($isAnAdmin && !hasPermission($chat_id, 'view_tickets')) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'شما دسترسی لازم برای پاسخ به تیکت‌ها را ندارید.', 'show_alert' => true]);
            die;
        }
        $ticket_id = str_replace('reply_ticket_', '', $data);
        $stmt = pdo()->prepare("SELECT status FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $ticket_status = $stmt->fetchColumn();
        if (!$ticket_status || $ticket_status == 'closed') {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'این تیکت بسته شده است.', 'show_alert' => true]);
        }
        else {
            if ($isAnAdmin) {
                updateUserData($chat_id, 'admin_replying_to_ticket', ['replying_to_ticket' => $ticket_id]);
                sendMessage($chat_id, "لطفا پاسخ خود را برای تیکت <code>$ticket_id</code> وارد کنید:", $cancelKeyboard);
            }
            else {
                updateUserData($chat_id, 'user_replying_to_ticket', ['replying_to_ticket' => $ticket_id]);
                sendMessage($chat_id, "لطفا پاسخ خود را برای تیکت <code>$ticket_id</code> وارد کنید:", $cancelKeyboard);
            }
        }
    }
    elseif (strpos($data, 'approve_renewal_') === 0 || strpos($data, 'reject_renewal_') === 0) {
            list($action, $type, $request_id) = explode('_', $data);

            $stmt = pdo()->prepare("SELECT * FROM renewal_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();

            if (!$request || $request['status'] !== 'pending') {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'این درخواست قبلا پردازش شده است.', 'show_alert' => true]);
                die;
            }
            
            $admin_who_processed = $update['callback_query']['from']['id'];

            if ($action == 'approve') {
                $result = applyRenewal($request['user_id'], $request['service_username'], $request['days_to_add'], $request['gb_to_add']);
                if ($result['success']) {
                    pdo()->prepare("UPDATE renewal_requests SET status = 'approved', processed_by_admin_id = ?, processed_at = NOW() WHERE id = ?")->execute([$admin_who_processed, $request_id]);
                    sendMessage($request['user_id'], "✅ درخواست تمدید شما برای سرویس `{$request['service_username']}` تایید و با موفقیت اعمال شد.");
                    editMessageCaption($chat_id, $message_id, $update['callback_query']['message']['caption'] . "\n\n<b>✅ توسط شما تایید شد.</b>", null);
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ تمدید تایید شد.']);
                } else {
                    sendMessage($chat_id, "❌ خطا در اعمال تمدید: " . $result['message']);
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'خطا در اعمال تمدید.', 'show_alert' => true]);
                }
            } elseif ($action == 'reject') {
                pdo()->prepare("UPDATE renewal_requests SET status = 'rejected', processed_by_admin_id = ?, processed_at = NOW() WHERE id = ?")->execute([$admin_who_processed, $request_id]);
                sendMessage($request['user_id'], "❌ درخواست تمدید شما برای سرویس `{$request['service_username']}` توسط ادمین رد شد.");
                editMessageCaption($chat_id, $message_id, $update['callback_query']['message']['caption'] . "\n\n<b>❌ توسط شما رد شد.</b>", null);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ درخواست رد شد.']);
            }
        }
    elseif ($data === 'plan_volume_unlimited' && hasPermission($chat_id, 'manage_plans')) {
        $user_data = getUserData($chat_id);
        $state_data = $user_data['state_data'];
        $state_data['new_plan_volume'] = 0; // 0 به معنای نامحدود
        updateUserData($chat_id, 'awaiting_plan_duration', $state_data);
        $keyboard = ['inline_keyboard' => [
            [['text' => '♾️ نامحدود', 'callback_data' => 'plan_duration_unlimited']],
            [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_admin_panel']]
        ]];
        editMessageText($chat_id, $message_id, "✅ حجم نامحدود تنظیم شد.\n\n4/7 - لطفا مدت زمان پلن را به روز وارد کنید (فقط عدد) یا دکمه نامحدود را انتخاب کنید:", $keyboard);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ حجم نامحدود تنظیم شد']);
        die;
    }
    elseif ($data === 'plan_duration_unlimited' && hasPermission($chat_id, 'manage_plans')) {
        $user_data = getUserData($chat_id);
        $state_data = $user_data['state_data'];
        $state_data['new_plan_duration'] = 0; // 0 به معنای نامحدود
        updateUserData($chat_id, 'awaiting_plan_description', $state_data);
        $keyboard = ['keyboard' => [[['text' => 'رد شدن'], ['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
        sendMessage($chat_id, "✅ مدت زمان نامحدود تنظیم شد.\n\n4/7 - در صورت تمایل، توضیحات مختصری برای پلن وارد کنید (اختیاری):", $keyboard);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ مدت زمان نامحدود تنظیم شد']);
        die;
    }
    elseif (strpos($data, 'close_ticket_') === 0) {
        if ($isAnAdmin && !hasPermission($chat_id, 'view_tickets')) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'شما دسترسی لازم برای بستن تیکت‌ها را ندارید.', 'show_alert' => true]);
            die;
        }
        $ticket_id = str_replace('close_ticket_', '', $data);
        $stmt = pdo()->prepare("SELECT user_id, user_name FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $ticket_data = $stmt->fetch();
        if ($ticket_data) {
            $stmt_close = pdo()->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?");
            $stmt_close->execute([$ticket_id]);
            $closer_name = $isAnAdmin ? 'ادمین' : $ticket_data['user_name'];
            $message = "✅ تیکت <code>$ticket_id</code> توسط <b>$closer_name</b> بسته شد.";
            sendMessage($ticket_data['user_id'], $message);
            $all_admins = getAdmins();
            foreach ($all_admins as $admin_id => $admin_data) {
                if ($admin_id != $chat_id && hasPermission($admin_id, 'view_tickets')) {
                    sendMessage($admin_id, $message);
                }
            }
            editMessageText($chat_id, $message_id, $update['callback_query']['message']['text'] . "\n\n<b>-- ➖ این تیکت بسته شد ➖ --</b>", null);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'تیکت با موفقیت بسته شد.']);
        }
        else {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'خطا: تیکت یافت نشد.', 'show_alert' => true]);
        }
    }

    // --- دکمه‌های عمومی کاربران ---
    elseif (strpos($data, 'get_configs_') === 0) {
        $username = str_replace('get_configs_', '', $data);
        
        $stmt_service = pdo()->prepare("SELECT server_id FROM services WHERE owner_chat_id = ? AND marzban_username = ?");
        $stmt_service->execute([$chat_id, $username]);
        $server_id = $stmt_service->fetchColumn();

        if (!$server_id) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ سرویس یافت نشد.', 'show_alert' => true]);
            die;
        }

        $panel_user = getPanelUser($username, $server_id);
        
        if ($panel_user && !empty($panel_user['links'])) {
            // --- ارسال مستقیم همه کانفیگ‌ها ---
            $all_links_text = implode("\n\n", $panel_user['links']);
            sendMessage($chat_id, "<b>تمام کانفیگ‌های شما (برای کپی آسان):</b>\n\nبا کلیک روی متن زیر، تمام لینک‌ها به صورت خودکار کپی می‌شوند.\n\n<code>" . htmlspecialchars($all_links_text) . "</code>");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ تمام کانفیگ‌ها برای شما ارسال شد!']);

        } else {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ هیچ لینک کانفیگی برای این سرویس یافت نشد.', 'show_alert' => true]);
        }
        die;
    }
    elseif (strpos($data, 'show_guide_') === 0) {
        $guide_id = str_replace('show_guide_', '', $data);
        $stmt = pdo()->prepare("SELECT * FROM guides WHERE id = ? AND status = 'active'");
        $stmt->execute([$guide_id]);
        $guide = $stmt->fetch();
        if ($guide) {
            deleteMessage($chat_id, $message_id);
            $keyboard = null;
            if (!empty($guide['inline_keyboard'])) {
                $keyboard = json_decode($guide['inline_keyboard'], true);
            }
            if ($guide['content_type'] === 'photo' && !empty($guide['photo_id'])) {
                sendPhoto($chat_id, $guide['photo_id'], $guide['message_text'], $keyboard);
            }
            else {
                sendMessage($chat_id, $guide['message_text'], $keyboard);
            }
        }
        else {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ این راهنما یافت نشد یا غیرفعال شده است.', 'show_alert' => true]);
        }
    }
    elseif (strpos($data, 'charge_manual_') === 0) {
        $amount = (int)str_replace('charge_manual_', '', $data);
        $settings = getSettings();
        $payment_method = $settings['payment_method'] ?? [];
        $card_number = $payment_method['card_number'] ?? '';
        $card_holder = $payment_method['card_holder'] ?? '';
        $copy_enabled = $payment_method['copy_enabled'] ?? false;

        if (empty($card_number)) {
             editMessageText($chat_id, $message_id, "❌ روش پرداخت دستی توسط ادمین تنظیم نشده است.");
             apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
             die;
        }

        $card_number_display = $copy_enabled ? "<code>{$card_number}</code>" : $card_number;
        $message = "برای شارژ حساب به مبلغ <b>" . number_format($amount) . " تومان</b>، لطفا مبلغ را به اطلاعات زیر واریز نمایید:\n\n" .
                   "💳 شماره کارت:\n" . $card_number_display . "\n" .
                   "👤 صاحب حساب: {$card_holder}\n\n" .
                   "پس از واریز، لطفا از رسید پرداخت خود اسکرین‌شات گرفته و در همینجا ارسال کنید.";
        editMessageText($chat_id, $message_id, $message);
        updateUserData($chat_id, 'awaiting_payment_screenshot', ['charge_amount' => $amount]);
    }
    elseif (strpos($data, 'cat_') === 0) {
        $categoryId = str_replace('cat_', '', $data);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        showServersForCategory($chat_id, $categoryId, $message_id);
    }
    elseif (strpos($data, 'show_plans_cat_') === 0) {
        preg_match('/show_plans_cat_(\d+)_srv_(\d+)/', $data, $matches);
        $category_id = $matches[1];
        $server_id = $matches[2];
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        showPlansForCategoryAndServer($chat_id, $category_id, $server_id, $message_id);
    }
    elseif (strpos($data, 'apply_discount_code_') === 0) {
        $parts = explode('_', $data);
        $category_id = $parts[3];
        $server_id = $parts[4]; // server_id اضافه شد
        updateUserData($chat_id, 'user_awaiting_discount_code', [
            'target_category_id' => $category_id,
            'target_server_id' => $server_id // server_id در state ذخیره می‌شود
        ]);
        editMessageText($chat_id, $message_id, "🎁 لطفاً کد تخفیف خود را وارد کنید:");
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
    }
    elseif (strpos($data, 'buy_plan_') === 0) {
    $parts = explode('_', $data);
    $plan_id = $parts[2];
    $discount_code = null;
    if (isset($parts[5]) && $parts[3] == 'with' && $parts[4] == 'code') {
        $discount_code = strtoupper($parts[5]);
    }
    
    $plan = getPlanById($plan_id);
    if (!$plan) {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ خطا: پلن یافت نشد.']);
        die;
    }

    if ($plan['purchase_limit'] > 0 && $plan['purchase_count'] >= $plan['purchase_limit']) {
        apiRequest('answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'text' => '❌ متاسفانه ظرفیت خرید این پلن به اتمام رسیده است.',
            'show_alert' => true,
        ]);
        die;
    }

    // بررسی نوع پنل - اگر پنل جدید باشد، اجازه خرید نده
    $server_stmt = pdo()->prepare("SELECT type FROM servers WHERE id = ?");
    $server_stmt->execute([$plan['server_id']]);
    $server_type = $server_stmt->fetchColumn();
    
    if (in_array($server_type, ['pasargad', 'rebecca'])) {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '⚠️ متاسفانه در حال حاضر این پنل در دست توسعه است و امکان خرید وجود ندارد. (به زودی)', 'show_alert' => true]);
        die;
    }

    // بررسی اینکه آیا پلن قابل تنظیم است یا نه
    if (!empty($plan['custom_volume_enabled']) && $plan['custom_volume_enabled'] == 1) {
        // پلن قابل تنظیم - کاربر باید حجم و روز را انتخاب کند
        $state_data = [
            'purchasing_plan_id' => $plan_id,
            'discount_code' => $discount_code
        ];
        updateUserData($chat_id, 'awaiting_custom_volume', $state_data);
        
        $min_vol = $plan['min_volume_gb'] ?? 1;
        $max_vol = $plan['max_volume_gb'] ?? 1000;
        $min_days = $plan['min_duration_days'] ?? 1;
        $max_days = $plan['max_duration_days'] ?? 365;
        
        $message = "✅ پلن قابل تنظیم انتخاب شد.\n\n";
        $message .= "📊 <b>محدوده مجاز:</b>\n";
        $message .= "▫️ حجم: {$min_vol} تا {$max_vol} گیگابایت\n";
        $message .= "▫️ مدت زمان: {$min_days} تا {$max_days} روز\n\n";
        $message .= "👇 لطفا حجم مورد نظر خود را به گیگابایت وارد کنید:";
        
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        editMessageText($chat_id, $message_id, $message, $cancelKeyboard);
        die;
    } else {
        // پلن معمولی - خرید مستقیم
        $state_data = [
            'purchasing_plan_id' => $plan_id,
            'discount_code' => $discount_code
        ];
        updateUserData($chat_id, 'awaiting_service_name', $state_data);
        
        $message = "✅ پلن انتخاب شد.\n\nلطفاً یک نام دلخواه برای این سرویس وارد کنید (مثلاً: سرویس شخصی). این نام در لیست سرویس‌های شما نمایش داده خواهد شد.";
        
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        editMessageText($chat_id, $message_id, $message, $cancelKeyboard);
        die;
    }
}
    elseif ($data === 'confirm_renewal_payment') {
        $state_data = $user_data['state_data'];
        $total_cost = $state_data['renewal_total_cost'];

        if ($user_data['balance'] >= $total_cost) {
            // پرداخت از موجودی
            editMessageText($chat_id, $message_id, "⏳ در حال تمدید سرویس با استفاده از موجودی شما...");
            updateUserBalance($chat_id, $total_cost, 'deduct');
            
            $result = applyRenewal($chat_id, $state_data['renewal_username'], $state_data['renewal_days'], $state_data['renewal_gb']);
            
            if ($result['success']) {
                $new_balance = number_format($user_data['balance'] - $total_cost);
                $success_msg = "✅ سرویس شما با موفقیت تمدید شد.\n\n" .
                               "💰 مبلغ " . number_format($total_cost) . " تومان از حساب شما کسر گردید.\n" .
                               "موجودی جدید: {$new_balance} تومان.";
                editMessageText($chat_id, $message_id, $success_msg);
            } else {
                editMessageText($chat_id, $message_id, "❌ خطایی در تمدید سرویس رخ داد: " . $result['message']);
                
                updateUserBalance($chat_id, $total_cost, 'add');
            }
            updateUserData($chat_id, 'main_menu');

        } else {
            
            $stmt = pdo()->prepare(
                "INSERT INTO renewal_requests (user_id, service_username, days_to_add, gb_to_add, total_cost) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$chat_id, $state_data['renewal_username'], $state_data['renewal_days'], $state_data['renewal_gb'], $total_cost]);
            $request_id = pdo()->lastInsertId();
            
            $state_data['renewal_request_id'] = $request_id;
            updateUserData($chat_id, 'awaiting_renewal_screenshot', $state_data);

           
            $settings = getSettings();
            $payment_method = $settings['payment_method'] ?? [];
            if (empty($payment_method['card_number'])) {
                editMessageText($chat_id, $message_id, "موجودی شما کافی نیست و روش پرداخت کارت به کارت نیز توسط ادمین تنظیم نشده است. لطفا ابتدا حساب خود را شارژ کنید.");
            } else {
                 $card_number = $payment_method['card_number'] ?? '';
                 $card_holder = $payment_method['card_holder'] ?? '';
                 $copy_enabled = $payment_method['copy_enabled'] ?? false;
                 $card_number_display = $copy_enabled ? "<code>{$card_number}</code>" : $card_number;
                 $message = "موجودی شما کافی نیست. لطفا مبلغ <b>" . number_format($total_cost) . " تومان</b> را به اطلاعات زیر واریز کرده و سپس اسکرین‌شات رسید را ارسال کنید:\n\n" .
                            "💳 شماره کارت:\n" . $card_number_display . "\n" .
                            "👤 صاحب حساب: {$card_holder}";
                 editMessageText($chat_id, $message_id, $message);
            }
        }
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
    }
    elseif ($data == 'back_to_categories') {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        $categories = getCategories(true);
        $keyboard_buttons = [];
        foreach ($categories as $category) {
            $keyboard_buttons[] = [['text' => '🛍 ' . $category['name'], 'callback_data' => 'cat_' . $category['id']]];
        }
        editMessageText($chat_id, $message_id, "لطفا یکی از دسته‌بندی‌های زیر را انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
    }
            elseif (strpos($data, 'service_details_') === 0) {
                $username = str_replace('service_details_', '', $data);
                if (isset($update['callback_query']['message']['photo'])) {
                    editMessageCaption($chat_id, $message_id, "⏳ در حال دریافت اطلاعات به‌روز سرویس، لطفا صبر کنید...");
                } else {
                    editMessageText($chat_id, $message_id, "⏳ در حال دریافت اطلاعات به‌روز سرویس، لطفا صبر کنید...");
                }

                $stmt_local = pdo()->prepare("SELECT s.*, p.name as plan_name, p.show_sub_link, p.show_conf_links FROM services s JOIN plans p ON s.plan_id = p.id WHERE s.owner_chat_id = ? AND s.marzban_username = ?");
                $stmt_local->execute([$chat_id, $username]);
                $local_service = $stmt_local->fetch();

                if ($local_service) {
                    $stmt_server = pdo()->prepare("SELECT * FROM servers WHERE id = ?");
                    $stmt_server->execute([$local_service['server_id']]);
                    $server_info = $stmt_server->fetch();

                    $dynamic_sub_url = $local_service['sub_url'];
                    if ($server_info) {
                        $base_sub_url = !empty($server_info['sub_host']) ? rtrim($server_info['sub_host'], '/') : rtrim($server_info['url'], '/');
                        $sub_path = strstr($local_service['sub_url'], '/sub/');
                        if ($sub_path === false) { 
                            $sub_path = parse_url($local_service['sub_url'], PHP_URL_PATH);
                        }
                        $dynamic_sub_url = $base_sub_url . $sub_path;
                    }

                    $panel_user = getPanelUser($username, $local_service['server_id']);

                    if ($panel_user && !isset($panel_user['detail'])) {
                        $qr_code_url = generateQrCodeUrl($dynamic_sub_url);
                        
                        $total_gb_from_db = $local_service['volume_gb'];
                        $used_bytes_from_panel = $panel_user['used_traffic'];
                        
                        // پشتیبانی از حجم نامحدود (اگر volume_gb صفر باشد)
                        $total_text = ($total_gb_from_db > 0) ? number_format($total_gb_from_db) . " گیگابایت" : 'نامحدود';
                        $used_text = formatBytes($used_bytes_from_panel);
                        
                        $remaining_text = 'نامحدود';
                        if ($total_gb_from_db > 0) {
                            $total_bytes_from_db = $total_gb_from_db * 1024 * 1024 * 1024;
                            $remaining_bytes = $total_bytes_from_db - $used_bytes_from_panel;
                            $remaining_text = formatBytes(max(0, $remaining_bytes));
                        } else {
                            // اگر حجم نامحدود باشد، remaining_text هم نامحدود است
                            $remaining_text = 'نامحدود';
                        }

                        // پشتیبانی از زمان نامحدود
                        $expire_date = 'نامحدود';
                        if (!empty($panel_user['expire']) && $panel_user['expire'] > 0) {
                            $expire_date = date('Y-m-d', $panel_user['expire']);
                        }
                        
                        // بررسی وضعیت - اگر expire صفر یا null باشد، یعنی نامحدود و همیشه فعال
                        $is_expired = false;
                        if (!empty($panel_user['expire']) && $panel_user['expire'] > 0) {
                            $is_expired = $panel_user['expire'] <= time();
                        }
                        $status_text = ($panel_user['status'] === 'active' && !$is_expired) ? 'فعال' : 'غیرفعال';

                        $caption =
                            "<b>مشخصات سرویس: {$local_service['plan_name']}</b>\n" .
                            "➖➖➖➖➖➖➖➖➖➖\n" .
                            "▫️ وضعیت: <b>{$status_text}</b>\n" .
                            "🗓 تاریخ انقضا: <b>{$expire_date}</b>\n\n" .
                            "📊 حجم کل: " . $total_text . "\n" .
                            "📈 حجم مصرفی: " . $used_text . "\n" .
                            "📉 حجم باقی‌مانده: " . $remaining_text . "\n" .
                            "➖➖➖➖➖➖➖➖➖➖\n";
                            
                        if ($local_service['show_sub_link']) {
                            $caption .= "\n🔗 لینک اشتراک (Subscription):\n<code>" . htmlspecialchars($dynamic_sub_url) . "</code>\n";
                        } else {
                            $caption .= "\n🔗 لینک اشتراک برای این پلن نمایش داده نمی‌شود.\n";
                        }

         
                        $keyboard_buttons = [
                            [['text' => '♻️ تمدید سرویس', 'callback_data' => "renew_service_{$username}"]],
                        ];

                        if ($local_service['show_conf_links'] && !empty($panel_user['links'])) {
                             $keyboard_buttons[0][] = ['text' => '📋 دریافت کانفیگ‌ها', 'callback_data' => "get_configs_{$username}"];
                        }
                        
                        $keyboard_buttons[] = [['text' => '🗑 حذف سرویس', 'callback_data' => "delete_service_confirm_{$username}"]];
                        $keyboard_buttons[] = [['text' => '◀️ بازگشت به لیست', 'callback_data' => 'back_to_services']];
                     

                        $keyboard = ['inline_keyboard' => $keyboard_buttons];

                        deleteMessage($chat_id, $message_id);
                        sendPhoto($chat_id, $qr_code_url, trim($caption), $keyboard);
                    } else {
                        editMessageText($chat_id, $message_id, "❌ خطایی در دریافت اطلاعات سرویس از سرور رخ داد یا سرویس یافت نشد. ممکن است توسط ادمین حذف شده باشد.");
                    }
                } else {
                    editMessageText($chat_id, $message_id, "❌ سرویس در دیتابیس ربات یافت نشد.");
                }
            }
    elseif (strpos($data, 'renew_service_') === 0) {
        $settings = getSettings();
        if (($settings['renewal_status'] ?? 'off') !== 'on') {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ قابلیت تمدید سرویس در حال حاضر غیرفعال است.', 'show_alert' => true]);
            die;
        }

        $username = str_replace('renew_service_', '', $data);
        updateUserData($chat_id, 'user_awaiting_renewal_days', ['renewal_username' => $username]);
        
        $price_day = number_format($settings['renewal_price_per_day'] ?? 1000);
        $message = "<b>تمدید سرویس</b>\n\n" .
                   "۱. چند **روز** به اعتبار سرویس شما اضافه شود؟\n\n" .
                   "▫️ هزینه هر روز: {$price_day} تومان\n" .
                   "💡 برای رد شدن و عدم تمدید زمان، عدد `0` را وارد کنید.";
        
        editMessageCaption($chat_id, $message_id, $message, null);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
    }
    elseif (strpos($data, 'delete_service_confirm_') === 0) {
        $username = str_replace('delete_service_confirm_', '', $data);
        $keyboard = ['inline_keyboard' => [[['text' => '✅ بله، حذف کن', 'callback_data' => "delete_service_do_{$username}"], ['text' => '❌ خیر، لغو', 'callback_data' => "service_details_{$username}"]]]];
        editMessageCaption($chat_id, $message_id, "⚠️ <b>آیا از حذف این سرویس مطمئن هستید؟</b>\nاین عمل غیرقابل بازگشت است و تمام اطلاعات سرویس پاک خواهد شد.", $keyboard);
    }
    elseif (strpos($data, 'delete_service_do_') === 0) {
        $username = str_replace('delete_service_do_', '', $data);
        editMessageCaption($chat_id, $message_id, "⏳ در حال حذف سرویس...");

        $stmt = pdo()->prepare("SELECT server_id FROM services WHERE owner_chat_id = ? AND marzban_username = ?");
        $stmt->execute([$chat_id, $username]);
        $server_id = $stmt->fetchColumn();

        if ($server_id) {
            $result_panel = deletePanelUser($username, $server_id);
            deleteUserService($chat_id, $username, $server_id);
            if ($result_panel) {
                editMessageCaption($chat_id, $message_id, "✅ سرویس شما با موفقیت حذف شد.");
            }
            else {
                editMessageCaption($chat_id, $message_id, "⚠️ سرویس از لیست شما حذف شد، اما ممکن است در حذف از پنل اصلی مشکلی رخ داده باشد. لطفا به پشتیبانی اطلاع دهید.");
                error_log("Failed to delete panel user {$username} on server {$server_id}. Response: " . json_encode($result_panel));
            }
        }
        else {
            editMessageCaption($chat_id, $message_id, "❌ خطایی در یافتن اطلاعات سرور برای این سرویس رخ داد.");
        }
    }
    elseif ($data == 'back_to_services') {
        deleteMessage($chat_id, $message_id);
        $services = getUserServices($chat_id);
        if (empty($services)) {
            sendMessage($chat_id, "شما هیچ سرویس فعالی ندارید.");
        }
        else {
            $keyboard_buttons = [];
            $now = time();
            foreach ($services as $service) {
                // پشتیبانی از زمان نامحدود (اگر expire_timestamp صفر باشد)
                $expire_date = 'نامحدود';
                if (!empty($service['expire_timestamp']) && $service['expire_timestamp'] > 0) {
                    $expire_date = date('Y-m-d', $service['expire_timestamp']);
                }
                
                $status_icon = '✅';
                if (!empty($service['expire_timestamp']) && $service['expire_timestamp'] > 0) {
                    $status_icon = $service['expire_timestamp'] < $now ? '❌' : '✅';
                }
                
                $button_text = "{$status_icon} {$service['plan_name']} (انقضا: {$expire_date})";
                $keyboard_buttons[] = [['text' => $button_text, 'callback_data' => 'service_details_' . $service['marzban_username']]];
            }
            sendMessage($chat_id, "سرویس مورد نظر خود را برای مشاهده جزئیات انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
        }
    }

    if (!USER_INLINE_KEYBOARD && !$apiRequest) {
        handleMainMenu($chat_id, $first_name, true);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        die;
    }
    elseif ($apiRequest) {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        die;
    }
}

// ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~
// ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ پردازش پیام‌ها ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~
// ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~
if (isset($update['message']) || USER_INLINE_KEYBOARD) {
    $is_verified = $user_data['is_verified'] ?? 0;
    $verification_method = $settings['verification_method'] ?? 'off';

    if ($verification_method !== 'off' && !$is_verified && !$isAnAdmin) {
        $is_phone_verification_action = isset($update['message']['contact']);

        if (!$is_phone_verification_action) {
            if ($verification_method === 'phone') {
                $message = "سلام! برای استفاده از امکانات ربات، لطفاً با کلیک روی دکمه زیر شماره تلفن خود را با ما به اشتراک بگذارید.";
                $keyboard = ['keyboard' => [[['text' => '🔒 اشتراک‌گذاری شماره تلفن', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
                sendMessage($chat_id, $message, $keyboard);
                die;
            }
            elseif ($verification_method === 'button') {
                $message = "سلام! برای اطمینان از اینکه شما یک کاربر واقعی هستید، لطفاً روی دکمه زیر کلیک کنید.";
                $keyboard = ['inline_keyboard' => [[['text' => '✅ تایید می‌کنم', 'callback_data' => 'verify_by_button']]]];
                sendMessage($chat_id, $message, $keyboard);
                die;
            }
        }
    }

    if (isset($update['message']['photo'])) {
        if ($user_state == 'awaiting_payment_screenshot') {
            $state_data = $user_data['state_data'];
            $amount = $state_data['charge_amount'];
            $user_id = $update['message']['from']['id'];
            $photo_id = $update['message']['photo'][count($update['message']['photo']) - 1]['file_id'];
            
            // --- آماده‌سازی metadata ---
            $metadata_to_save = null;
            if (isset($state_data['purpose']) && $state_data['purpose'] === 'complete_purchase') {
                $metadata = [
                    'purpose' => 'complete_purchase',
                    'plan_id' => $state_data['plan_id'],
                    'discount_code' => $state_data['discount_code'] ?? null,
                    'custom_name' => $state_data['custom_name'] ?? 'سرویس'
                ];
                
                // اگر پلن قابل تنظیم باشد، حجم و روز را اضافه کن
                if (isset($state_data['custom_volume_gb']) && isset($state_data['custom_duration_days'])) {
                    $metadata['custom_volume_gb'] = $state_data['custom_volume_gb'];
                    $metadata['custom_duration_days'] = $state_data['custom_duration_days'];
                }
                
                $metadata_to_save = json_encode($metadata);
            }
     

            $stmt = pdo()->prepare("INSERT INTO payment_requests (user_id, amount, photo_file_id, metadata) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $amount, $photo_id, $metadata_to_save]);
            $request_id = pdo()->lastInsertId();

            $caption = "<b>درخواست شارژ حساب جدید</b>\n\n" . "👤 کاربر: " . htmlspecialchars($first_name) . "\n" . "🆔 شناسه: <code>$user_id</code>\n" . "💰 مبلغ: " . number_format($amount) . " تومان\n" . "▫️ شماره درخواست: #{$request_id}";
            $keyboard = ['inline_keyboard' => [[['text' => '✅ تایید', 'callback_data' => "approve_{$request_id}"], ['text' => '❌ رد', 'callback_data' => "reject_{$request_id}"]]]];

            $all_admins = getAdmins();
            $all_admins[ADMIN_CHAT_ID] = [];
            foreach (array_keys($all_admins) as $admin_id) {
                if (hasPermission($admin_id, 'manage_payment')) {
                    sendPhoto($admin_id, $photo_id, $caption, $keyboard);
                }
            }

            sendMessage($chat_id, "✅ رسید شما برای ادمین ارسال شد. پس از بررسی، نتیجه به شما اطلاع داده خواهد شد.");
            updateUserData($chat_id, 'main_menu');
            handleMainMenu($chat_id, $first_name);
            die;
        }
    }

    if (isset($update['message']['contact'])) {
        $contact = $update['message']['contact'];

        if ($contact['user_id'] != $chat_id) {
            sendMessage($chat_id, "❌ لطفا فقط شماره تلفن خود را از طریق دکمه مخصوص به اشتراک بگذارید.");
            die;
        }

        $phone_number = $contact['phone_number'];
        $settings = getSettings();
        $is_valid = true;

        if ($settings['verification_iran_only'] === 'on') {
            $cleaned_phone = ltrim($phone_number, '+');
            if (strpos($cleaned_phone, '98') !== 0) {
                $is_valid = false;
            }
        }

        if ($is_valid) {
            $stmt = pdo()->prepare("UPDATE users SET is_verified = 1, phone_number = ? WHERE chat_id = ?");
            $stmt->execute([$phone_number, $chat_id]);
            sendMessage($chat_id, "✅ احراز هویت شما با موفقیت انجام شد. از همراهی شما سپاسگزاریم!");
            handleMainMenu($chat_id, $first_name);
        }
        else {
            $message = "❌ متاسفانه شماره ارسالی شما مورد تایید نیست. این ربات فقط برای شماره‌های ایران (+98) فعال است.";
            $keyboard = ['keyboard' => [[['text' => '🔒 اشتراک‌گذاری شماره تلفن', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
            sendMessage($chat_id, $message, $keyboard);
        }
        die;
    }

    if (!isset($update['message']['text']) && !isset($update['message']['forward_from']) && $user_state !== 'admin_awaiting_guide_content' && !USER_INLINE_KEYBOARD) {
        die;
    }

    $text = trim($update['message']['text'] ?? ($update['callback_query']['data'] ?? ''));

    if ($text == '/start') {
        updateUserData($chat_id, 'main_menu', ['admin_view' => 'user']);
        handleMainMenu($chat_id, $first_name, true);
        die;
    }

    if ($text == 'لغو' || $text == '◀️ بازگشت به منوی اصلی') {
        $admin_view_mode = $user_data['state_data']['admin_view'] ?? 'user';

        if ($isAnAdmin && (strpos($user_state, 'admin_') === 0 || $admin_view_mode === 'admin')) {
            updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
            handleMainMenu($chat_id, $first_name, false);
        }
        else {
            updateUserData($chat_id, 'main_menu', ['admin_view' => 'user']);
            handleMainMenu($chat_id, $first_name, false);
        }
        die;
    }

    if (isset($update['message']['forward_from']) || isset($update['message']['forward_from_chat'])) {
        if ($isAnAdmin && $user_state == 'admin_awaiting_forward_message' && hasPermission($chat_id, 'broadcast')) {
            $user_ids = getAllUsers();
            $from_chat_id = $update['message']['chat']['id'];
            $message_id = $update['message']['message_id'];
            $success_count = 0;
            sendMessage($chat_id, "⏳ در حال شروع فروارد همگانی...");
            foreach ($user_ids as $user_id) {
                $result = forwardMessage($user_id, $from_chat_id, $message_id);
                $decoded_result = json_decode($result, true);
                if ($decoded_result && $decoded_result['ok']) {
                    $success_count++;
                }
                usleep(100000);
            }
            sendMessage($chat_id, "✅ پیام شما با موفقیت به $success_count کاربر فروارد شد.");
            updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
            handleMainMenu($chat_id, $first_name);
        }
        die;
    }

    if ($user_state !== 'main_menu') {
        switch ($user_state) {
            
            case 'awaiting_custom_volume':
                // دریافت حجم انتخابی کاربر
                if (!is_numeric($text) || (int)$text <= 0) {
                    sendMessage($chat_id, "❌ لطفا یک عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                
                $state_data = $user_data['state_data'];
                $plan_id = $state_data['purchasing_plan_id'];
                $plan = getPlanById($plan_id);
                
                if (!$plan) {
                    sendMessage($chat_id, "❌ خطایی رخ داد. پلن یافت نشد.");
                    updateUserData($chat_id, 'main_menu');
                    break;
                }
                
                $selected_volume = (int)$text;
                $min_vol = $plan['min_volume_gb'] ?? 1;
                $max_vol = $plan['max_volume_gb'] ?? 1000;
                
                if ($selected_volume < $min_vol || $selected_volume > $max_vol) {
                    sendMessage($chat_id, "❌ حجم وارد شده خارج از محدوده مجاز است.\n\nمحدوده مجاز: {$min_vol} تا {$max_vol} گیگابایت", $cancelKeyboard);
                    break;
                }
                
                $state_data['custom_volume_gb'] = $selected_volume;
                $state_data['custom_duration_days'] = null; // هنوز دریافت نشده
                updateUserData($chat_id, 'awaiting_custom_duration', $state_data);
                
                $min_days = $plan['min_duration_days'] ?? 1;
                $max_days = $plan['max_duration_days'] ?? 365;
                
                $message = "✅ حجم " . number_format($selected_volume) . " گیگابایت انتخاب شد.\n\n";
                $message .= "👇 حالا لطفا مدت زمان مورد نظر خود را به روز وارد کنید:\n";
                $message .= "محدوده مجاز: {$min_days} تا {$max_days} روز";
                
                sendMessage($chat_id, $message, $cancelKeyboard);
                break;
            
            case 'awaiting_custom_duration':
                // دریافت روز انتخابی کاربر
                if (!is_numeric($text) || (int)$text <= 0) {
                    sendMessage($chat_id, "❌ لطفا یک عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                
                $state_data = $user_data['state_data'];
                $plan_id = $state_data['purchasing_plan_id'];
                $selected_volume = $state_data['custom_volume_gb'];
                $plan = getPlanById($plan_id);
                
                if (!$plan) {
                    sendMessage($chat_id, "❌ خطایی رخ داد. پلن یافت نشد.");
                    updateUserData($chat_id, 'main_menu');
                    break;
                }
                
                $selected_duration = (int)$text;
                $min_days = $plan['min_duration_days'] ?? 1;
                $max_days = $plan['max_duration_days'] ?? 365;
                
                if ($selected_duration < $min_days || $selected_duration > $max_days) {
                    sendMessage($chat_id, "❌ مدت زمان وارد شده خارج از محدوده مجاز است.\n\nمحدوده مجاز: {$min_days} تا {$max_days} روز", $cancelKeyboard);
                    break;
                }
                
                // محاسبه قیمت بر اساس حجم و روز - استفاده از قیمت تمدید اگر در پلن تنظیم نشده باشد
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
                
                $base_price = ($selected_volume * $price_per_gb) + ($selected_duration * $price_per_day);
                
                $state_data['custom_duration_days'] = $selected_duration;
                $state_data['custom_calculated_price'] = $base_price;
                updateUserData($chat_id, 'awaiting_service_name_custom', $state_data);
                
                $message = "✅ مدت زمان " . number_format($selected_duration) . " روز انتخاب شد.\n\n";
                $message .= "📊 <b>خلاصه انتخاب شما:</b>\n";
                $message .= "▫️ حجم: " . number_format($selected_volume) . " گیگابایت\n";
                $message .= "▫️ مدت زمان: " . number_format($selected_duration) . " روز\n";
                $message .= "💰 قیمت محاسبه شده: <b>" . number_format($base_price) . " تومان</b>\n\n";
                $message .= "👇 لطفا یک نام دلخواه برای این سرویس وارد کنید:";
                
                sendMessage($chat_id, $message, $cancelKeyboard);
                break;
            
            case 'awaiting_service_name_custom':
                // دریافت نام سرویس برای پلن قابل تنظیم
                $custom_name = trim($text);
                if (empty($custom_name) || mb_strlen($custom_name) > 50) {
                    sendMessage($chat_id, "❌ نام وارد شده نامعتبر است. لطفاً یک نام کوتاه‌تر (حداکثر 50 کاراکتر) وارد کنید.", $cancelKeyboard);
                    break;
                }
                
                $state_data = $user_data['state_data'];
                $plan_id = $state_data['purchasing_plan_id'];
                $discount_code = $state_data['discount_code'] ?? null;
                $custom_volume = $state_data['custom_volume_gb'];
                $custom_duration = $state_data['custom_duration_days'];
                $base_price = $state_data['custom_calculated_price'];
                
                $plan = getPlanById($plan_id);
                if (!$plan) {
                    sendMessage($chat_id, "❌ خطایی رخ داد. پلن یافت نشد.");
                    updateUserData($chat_id, 'main_menu');
                    break;
                }
                
                // اعمال تخفیف اگر وجود دارد
                $final_price = $base_price;
                $discount_applied = false;
                $discount_object = null;
                if ($discount_code) {
                    $stmt = pdo()->prepare("SELECT * FROM discount_codes WHERE code = ? AND status = 'active' AND usage_count < max_usage");
                    $stmt->execute([$discount_code]);
                    $discount = $stmt->fetch();
                    if ($discount) {
                        if ($discount['type'] == 'percent') {
                            $final_price = $base_price - ($base_price * $discount['value']) / 100;
                        } else {
                            $final_price = $base_price - $discount['value'];
                        }
                        $final_price = max(0, $final_price);
                        $discount_applied = true;
                        $discount_object = $discount;
                    }
                }
                
                $user_balance = $user_data['balance'];
                
                if ($user_balance >= $final_price) {
                    sendMessage($chat_id, "⏳ نام سرویس تایید شد. لطفاً صبر کنید... در حال ایجاد سرویس شما هستیم.");
                    // استفاده از حجم و روز انتخابی کاربر
                    $purchase_result = completePurchase($chat_id, $plan_id, $custom_name, $final_price, $discount_code, $discount_object, $discount_applied, $custom_volume, $custom_duration);
                    
                    if ($purchase_result['success']) {
                        sendPhoto($chat_id, $purchase_result['qr_code_url'], $purchase_result['caption'], $purchase_result['keyboard']);
                        sendMessage(ADMIN_CHAT_ID, $purchase_result['admin_notification']);
                    } else {
                        sendMessage($chat_id, $purchase_result['error_message']);
                        
                        // ارسال خطای دقیق به ادمین
                        $admin_error_message = "⚠️ <b>خطای ساخت سرویس</b>\n\n";
                        $admin_error_message .= "👤 کاربر: <code>{$chat_id}</code>\n";
                        $admin_error_message .= "📦 پلن: <b>{$plan['name']}</b>\n";
                        $admin_error_message .= "🖥️ سرور: <b>{$plan['server_id']}</b>\n\n";
                        
                        if (isset($purchase_result['error_details'])) {
                            $admin_error_message .= "❌ خطا: <code>" . htmlspecialchars($purchase_result['error_details']) . "</code>\n\n";
                        }
                        
                        if (isset($purchase_result['panel_error']) && is_array($purchase_result['panel_error'])) {
                            $panel_error = $purchase_result['panel_error'];
                            if (isset($panel_error['error'])) {
                                $admin_error_message .= "🔍 جزئیات: <code>" . htmlspecialchars($panel_error['error']) . "</code>\n";
                            }
                            if (isset($panel_error['http_code'])) {
                                $admin_error_message .= "📡 HTTP Code: <code>{$panel_error['http_code']}</code>\n";
                            }
                        }
                        
                        sendMessage(ADMIN_CHAT_ID, $admin_error_message);
                    }
                    updateUserData($chat_id, 'main_menu');
                    handleMainMenu($chat_id, $first_name);
                } else {
                    // کاربر موجودی کافی ندارد
                    $needed_amount = $final_price - $user_balance;
                    $settings = getSettings();
                    
                    $encoded_name = base64_encode($custom_name);
                    $encoded_volume = base64_encode($custom_volume);
                    $encoded_duration = base64_encode($custom_duration);
                    
                    $keyboard_buttons = [];
                    // زرین‌پال
                    if (($settings['payment_gateway_status'] ?? 'off') == 'on' && !empty($settings['zarinpal_merchant_id'])) {
                        $callback_data_online = "charge_plan_custom_zarinpal_{$needed_amount}_{$plan_id}_{$encoded_volume}_{$encoded_duration}_{$encoded_name}";
                        if ($discount_code) $callback_data_online .= "_{$discount_code}";
                        $keyboard_buttons[] = [['text' => '🌐 پرداخت آنلاین (زرین‌پال)', 'callback_data' => $callback_data_online]];
                    }
                    // IDPay
                    if (($settings['idpay_enabled'] ?? 'off') == 'on' && !empty($settings['idpay_api_key'])) {
                        $callback_data_online = "charge_plan_custom_idpay_{$needed_amount}_{$plan_id}_{$encoded_volume}_{$encoded_duration}_{$encoded_name}";
                        if ($discount_code) $callback_data_online .= "_{$discount_code}";
                        $keyboard_buttons[] = [['text' => '🔷 پرداخت آنلاین (IDPay)', 'callback_data' => $callback_data_online]];
                    }
                    // NextPay
                    if (($settings['nextpay_enabled'] ?? 'off') == 'on' && !empty($settings['nextpay_api_key'])) {
                        $callback_data_online = "charge_plan_custom_nextpay_{$needed_amount}_{$plan_id}_{$encoded_volume}_{$encoded_duration}_{$encoded_name}";
                        if ($discount_code) $callback_data_online .= "_{$discount_code}";
                        $keyboard_buttons[] = [['text' => '🔶 پرداخت آنلاین (NextPay)', 'callback_data' => $callback_data_online]];
                    }
                    // زیبال
                    if (($settings['zibal_enabled'] ?? 'off') == 'on' && !empty($settings['zibal_merchant_id'])) {
                        $callback_data_online = "charge_plan_custom_zibal_{$needed_amount}_{$plan_id}_{$encoded_volume}_{$encoded_duration}_{$encoded_name}";
                        if ($discount_code) $callback_data_online .= "_{$discount_code}";
                        $keyboard_buttons[] = [['text' => '💛 پرداخت آنلاین (زیبال)', 'callback_data' => $callback_data_online]];
                    }
                    // newPayment
                    if (($settings['newpayment_enabled'] ?? 'off') == 'on' && !empty($settings['newpayment_api_key'])) {
                        $callback_data_online = "charge_plan_custom_newpayment_{$needed_amount}_{$plan_id}_{$encoded_volume}_{$encoded_duration}_{$encoded_name}";
                        if ($discount_code) $callback_data_online .= "_{$discount_code}";
                        $keyboard_buttons[] = [['text' => '🆕 پرداخت آنلاین (newPayment)', 'callback_data' => $callback_data_online]];
                    }
                    // آقای پرداخت
                    if (($settings['aqayepardakht_enabled'] ?? 'off') == 'on' && !empty($settings['aqayepardakht_pin'])) {
                        $callback_data_online = "charge_plan_custom_aqayepardakht_{$needed_amount}_{$plan_id}_{$encoded_volume}_{$encoded_duration}_{$encoded_name}";
                        if ($discount_code) $callback_data_online .= "_{$discount_code}";
                        $keyboard_buttons[] = [['text' => '👨‍💼 پرداخت آنلاین (آقای پرداخت)', 'callback_data' => $callback_data_online]];
                    }
                    // پرداخت کارت به کارت
                    if (!empty($settings['payment_method']['card_number'])) {
                        $callback_data_manual = "manual_pay_for_plan_custom_{$needed_amount}_{$plan_id}_{$encoded_volume}_{$encoded_duration}_{$encoded_name}";
                        if ($discount_code) $callback_data_manual .= "_{$discount_code}";
                        $keyboard_buttons[] = [['text' => '💳 پرداخت کارت به کارت', 'callback_data' => $callback_data_manual]];
                    }
                    
                    if (empty($keyboard_buttons)) {
                        sendMessage($chat_id, "موجودی شما کافی نیست و هیچ روش پرداختی توسط ادمین فعال نشده است. لطفا ابتدا حساب خود را شارژ کنید.");
                        updateUserData($chat_id, 'main_menu');
                        handleMainMenu($chat_id, $first_name);
                    } else {
                        $message = "💰 موجودی شما کافی نیست.\n\n";
                        $message .= "مبلغ مورد نیاز: <b>" . number_format($needed_amount) . " تومان</b>\n";
                        $message .= "موجودی شما: " . number_format($user_balance) . " تومان\n\n";
                        $message .= "لطفا روش پرداخت را انتخاب کنید:";
                        sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
                    }
                }
                break;
            
            case 'awaiting_service_name':
    $custom_name = trim($text);
    if (empty($custom_name) || mb_strlen($custom_name) > 50) {
        sendMessage($chat_id, "❌ نام وارد شده نامعتبر است. لطفاً یک نام کوتاه‌تر (حداکثر 50 کاراکتر) وارد کنید.", $cancelKeyboard);
        break;
    }

    $state_data = $user_data['state_data'];
    $plan_id = $state_data['purchasing_plan_id'] ?? null;
    $discount_code = $state_data['discount_code'] ?? null;
    
    if (!$plan_id) {
        error_log("awaiting_service_name: plan_id not found in state_data for user {$chat_id}");
        sendMessage($chat_id, "❌ خطایی رخ داد. اطلاعات خرید یافت نشد. لطفاً مجدداً تلاش کنید.");
        updateUserData($chat_id, 'main_menu');
        handleMainMenu($chat_id, $first_name);
        break;
    }
    
    $plan = getPlanById($plan_id);
    if (!$plan) {
        error_log("awaiting_service_name: plan not found for plan_id {$plan_id}");
        sendMessage($chat_id, "❌ خطایی رخ داد. پلن یافت نشد.");
        updateUserData($chat_id, 'main_menu');
        handleMainMenu($chat_id, $first_name);
        break;
    }

    // بررسی نوع پنل - اگر پنل جدید باشد، اجازه خرید نده
    $server_stmt = pdo()->prepare("SELECT type FROM servers WHERE id = ?");
    $server_stmt->execute([$plan['server_id']]);
    $server_type = $server_stmt->fetchColumn();
    
    if (in_array($server_type, ['pasargad', 'rebecca'])) {
        sendMessage($chat_id, "⚠️ متاسفانه در حال حاضر این پنل در دست توسعه است و امکان خرید وجود ندارد. (به زودی)");
        updateUserData($chat_id, 'main_menu');
        handleMainMenu($chat_id, $first_name);
        break;
    }

    // --- کپی کردن منطق بررسی موجودی و قیمت نهایی از کد قبلی ---
    $final_price = (float)$plan['price'];
    $discount_applied = false;
    $discount_object = null;
    if ($discount_code) {
        $stmt = pdo()->prepare("SELECT * FROM discount_codes WHERE code = ? AND status = 'active' AND usage_count < max_usage");
        $stmt->execute([$discount_code]);
        $discount = $stmt->fetch();
        if ($discount) {
            if ($discount['type'] == 'percent') {
                $final_price = $plan['price'] - ($plan['price'] * $discount['value']) / 100;
            } else {
                $final_price = $plan['price'] - $discount['value'];
            }
            $final_price = max(0, $final_price);
            $discount_applied = true;
            $discount_object = $discount;
        }
    }
    
    $user_balance = $user_data['balance'] ?? 0;

    if ($user_balance >= $final_price) {
        // استفاده از sendMessage برای نمایش پیام در حال پردازش
        $processing_msg = sendMessage($chat_id, "⏳ نام سرویس تایید شد. لطفاً صبر کنید... در حال ایجاد سرویس شما هستیم.", null);
        $processing_msg_id = null;
        if ($processing_msg) {
            $processing_data = json_decode($processing_msg, true);
            if ($processing_data && isset($processing_data['result']['message_id'])) {
                $processing_msg_id = $processing_data['result']['message_id'];
            }
        }
        
        try {
            $purchase_result = completePurchase($chat_id, $plan_id, $custom_name, $final_price, $discount_code, $discount_object, $discount_applied);
            
            if ($purchase_result && isset($purchase_result['success']) && $purchase_result['success']) {
                // حذف پیام "در حال پردازش"
                if ($processing_msg_id) {
                    try {
                        apiRequest('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $processing_msg_id]);
                    } catch (Exception $e) {
                        // اگر نتوانستیم پیام را حذف کنیم، مشکلی نیست
                    }
                }
                
                // ارسال QR code و اطلاعات
                if (isset($purchase_result['qr_code_url']) && !empty($purchase_result['qr_code_url'])) {
                    sendPhoto($chat_id, $purchase_result['qr_code_url'], $purchase_result['caption'] ?? '', $purchase_result['keyboard'] ?? null);
                } else {
                    // اگر QR code نبود، فقط متن را ارسال کن
                    sendMessage($chat_id, $purchase_result['caption'] ?? '✅ سرویس شما با موفقیت ایجاد شد.', $purchase_result['keyboard'] ?? null);
                }
                
                // ارسال اعلان به ادمین
                if (isset($purchase_result['admin_notification']) && !empty($purchase_result['admin_notification'])) {
                    sendMessage(ADMIN_CHAT_ID, $purchase_result['admin_notification']);
                }
            } else {
                // خطا در ایجاد سرویس
                $error_message = $purchase_result['error_message'] ?? '❌ خطایی در ایجاد سرویس رخ داد. لطفاً با پشتیبانی تماس بگیرید.';
                
                // حذف پیام "در حال پردازش" و ارسال پیام خطا
                if ($processing_msg_id) {
                    try {
                        editMessageText($chat_id, $processing_msg_id, $error_message, null);
                    } catch (Exception $e) {
                        sendMessage($chat_id, $error_message);
                    }
                } else {
                    sendMessage($chat_id, $error_message);
                }
                
                // ارسال خطای دقیق به ادمین
                $admin_error_message = "⚠️ <b>خطای ساخت سرویس</b>\n\n";
                $admin_error_message .= "👤 کاربر: <code>{$chat_id}</code>\n";
                $admin_error_message .= "📦 پلن: <b>{$plan['name']}</b> (ID: {$plan_id})\n";
                $admin_error_message .= "🖥️ سرور: <b>{$plan['server_id']}</b> (Type: {$server_type})\n\n";
                
                if (isset($purchase_result['error_details'])) {
                    $admin_error_message .= "❌ خطا: <code>" . htmlspecialchars($purchase_result['error_details']) . "</code>\n\n";
                }
                
                if (isset($purchase_result['panel_error']) && is_array($purchase_result['panel_error'])) {
                    $panel_error = $purchase_result['panel_error'];
                    if (isset($panel_error['error'])) {
                        $admin_error_message .= "🔍 جزئیات: <code>" . htmlspecialchars($panel_error['error']) . "</code>\n";
                    }
                    if (isset($panel_error['http_code'])) {
                        $admin_error_message .= "📡 HTTP Code: <code>{$panel_error['http_code']}</code>\n";
                    }
                    if (isset($panel_error['details']) && is_string($panel_error['details'])) {
                        $admin_error_message .= "📋 جزئیات بیشتر: <code>" . htmlspecialchars(substr($panel_error['details'], 0, 500)) . "</code>\n";
                    }
                }
                
                sendMessage(ADMIN_CHAT_ID, $admin_error_message);
            }
        } catch (Exception $e) {
            error_log("Exception in awaiting_service_name for user {$chat_id}: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            $error_msg = "❌ خطای غیرمنتظره‌ای رخ داد: " . $e->getMessage();
            
            if ($processing_msg_id) {
                try {
                    editMessageText($chat_id, $processing_msg_id, $error_msg, null);
                } catch (Exception $e2) {
                    sendMessage($chat_id, $error_msg);
                }
            } else {
                sendMessage($chat_id, $error_msg);
            }
            
            // ارسال خطا به ادمین
            sendMessage(ADMIN_CHAT_ID, "⚠️ <b>خطای Exception در خرید</b>\n\n👤 کاربر: <code>{$chat_id}</code>\n📦 پلن: <b>{$plan['name']}</b>\n❌ خطا: <code>" . htmlspecialchars($e->getMessage()) . "</code>");
        }
        
        updateUserData($chat_id, 'main_menu');
        handleMainMenu($chat_id, $first_name);

    } else {
    // کاربر موجودی کافی ندارد، فاکتور صادر شود
    $needed_amount = $final_price - $user_balance;
    $settings = getSettings();
    
    $encoded_name = base64_encode($custom_name);

    $keyboard_buttons = [];
    // زرین‌پال
    if (($settings['payment_gateway_status'] ?? 'off') == 'on' && !empty($settings['zarinpal_merchant_id'])) {
        $callback_data_online = "charge_plan_zarinpal_{$needed_amount}_{$plan_id}_{$encoded_name}";
        if ($discount_code) $callback_data_online .= "_{$discount_code}";
        $keyboard_buttons[] = [['text' => '🌐 پرداخت آنلاین (زرین‌پال)', 'callback_data' => $callback_data_online]];
    }
    // IDPay
    if (($settings['idpay_enabled'] ?? 'off') == 'on' && !empty($settings['idpay_api_key'])) {
        $callback_data_online = "charge_plan_idpay_{$needed_amount}_{$plan_id}_{$encoded_name}";
        if ($discount_code) $callback_data_online .= "_{$discount_code}";
        $keyboard_buttons[] = [['text' => '🔷 پرداخت آنلاین (IDPay)', 'callback_data' => $callback_data_online]];
    }
    // NextPay
    if (($settings['nextpay_enabled'] ?? 'off') == 'on' && !empty($settings['nextpay_api_key'])) {
        $callback_data_online = "charge_plan_nextpay_{$needed_amount}_{$plan_id}_{$encoded_name}";
        if ($discount_code) $callback_data_online .= "_{$discount_code}";
        $keyboard_buttons[] = [['text' => '🔶 پرداخت آنلاین (NextPay)', 'callback_data' => $callback_data_online]];
    }
    // زیبال
    if (($settings['zibal_enabled'] ?? 'off') == 'on' && !empty($settings['zibal_merchant_id'])) {
        $callback_data_online = "charge_plan_zibal_{$needed_amount}_{$plan_id}_{$encoded_name}";
        if ($discount_code) $callback_data_online .= "_{$discount_code}";
        $keyboard_buttons[] = [['text' => '💛 پرداخت آنلاین (زیبال)', 'callback_data' => $callback_data_online]];
    }
    // newPayment
    if (($settings['newpayment_enabled'] ?? 'off') == 'on' && !empty($settings['newpayment_api_key'])) {
        $callback_data_online = "charge_plan_newpayment_{$needed_amount}_{$plan_id}_{$encoded_name}";
        if ($discount_code) $callback_data_online .= "_{$discount_code}";
        $keyboard_buttons[] = [['text' => '🆕 پرداخت آنلاین (newPayment)', 'callback_data' => $callback_data_online]];
    }
    // آقای پرداخت
    if (($settings['aqayepardakht_enabled'] ?? 'off') == 'on' && !empty($settings['aqayepardakht_pin'])) {
        $callback_data_online = "charge_plan_aqayepardakht_{$needed_amount}_{$plan_id}_{$encoded_name}";
        if ($discount_code) $callback_data_online .= "_{$discount_code}";
        $keyboard_buttons[] = [['text' => '👨‍💼 پرداخت آنلاین (آقای پرداخت)', 'callback_data' => $callback_data_online]];
    }
    // پرداخت کارت به کارت
    if (!empty($settings['payment_method']['card_number'])) {
        $callback_data_manual = "manual_pay_for_plan_{$needed_amount}_{$plan_id}_{$encoded_name}";
        if ($discount_code) $callback_data_manual .= "_{$discount_code}";
        $keyboard_buttons[] = [['text' => '💳 پرداخت کارت به کارت', 'callback_data' => $callback_data_manual]];
    }

        if (empty($keyboard_buttons)) {
            sendMessage($chat_id, "❌ موجودی شما کافی نیست و هیچ روش پرداختی توسط ادمین فعال نشده است.");
        } else {
            $message = "⚠️ موجودی شما کافی نیست!\n\n" .
                       "▫️ قیمت پلن: " . number_format($final_price) . " تومان\n" .
                       "▫️ موجودی شما: " . number_format($user_balance) . " تومان\n" .
                       "<b>💰 مبلغ مورد نیاز: " . number_format($needed_amount) . " تومان</b>\n\n" .
                       "لطفاً روش پرداخت برای تکمیل خرید را انتخاب کنید:";
            sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
        }
    }
    break;
            
            case 'admin_awaiting_user_search':
                if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                    if (!is_numeric($text)) {
                        sendMessage($chat_id, "❌ شناسه عددی نامعتبر است. لطفاً فقط عدد وارد کنید.", $cancelKeyboard);
                        break;
                    }
                    $target_user = getUserData($text, '');
                    if (!$target_user || !isset($target_user['chat_id'])) {
                        sendMessage($chat_id, "❌ کاربری با این شناسه یافت نشد. لطفاً شناسه را بررسی کرده و مجدداً تلاش کنید.", $cancelKeyboard);
                        break;
                    }
                    
                    $chat_info_response = apiRequest('getChat', ['chat_id' => $target_user['chat_id']]);
                    $chat_info = json_decode($chat_info_response, true);
                    
                    $profile_link_html = '';
                    if ($chat_info['ok'] && !empty($chat_info['result']['username'])) {
                        $username = $chat_info['result']['username'];
                        $profile_link_html = "👤 حساب کاربری: <a href='https://t.me/{$username}'>@{$username}</a>\n";
                    } else {
                        $profile_link_html = "👤 حساب کاربری: <a href='tg://user?id={$target_user['chat_id']}'>مشاهده پروفایل (بدون یوزرنیم)</a>\n";
                    }
                    

                    // نمایش اطلاعات و دکمه‌های مدیریتی
                    $balance = $target_user['balance'] ?? 0;
                    $status_text = ($target_user['status'] ?? 'active') === 'active' ? 'فعال ✅' : 'مسدود 🚫';

                    $message = "<b>اطلاعات کاربر:</b> " . htmlspecialchars($target_user['first_name']) . "\n\n" .
                               "▫️ شناسه: <code>{$target_user['chat_id']}</code>\n" .
                               $profile_link_html . 
                               "💰 موجودی: " . number_format($balance) . " تومان\n" .
                               "▫️ وضعیت: <b>{$status_text}</b>\n\n" .
                               "لطفاً عملیات مورد نظر را انتخاب کنید:";

                    $status_button_text = ($target_user['status'] ?? 'active') === 'active' ? '🚫 مسدود کردن' : '✅ آزاد کردن';
                    $status_callback = ($target_user['status'] ?? 'active') === 'active' ? "ban_user_{$target_user['chat_id']}" : "unban_user_{$target_user['chat_id']}";

                    $keyboard = ['inline_keyboard' => [
                        [
                            ['text' => '➕ افزایش موجودی', 'callback_data' => "add_balance_{$target_user['chat_id']}"],
                            ['text' => '➖ کاهش موجودی', 'callback_data' => "deduct_balance_{$target_user['chat_id']}"]
                        ],
                        [
                            ['text' => '✉️ ارسال پیام', 'callback_data' => "message_user_{$target_user['chat_id']}"],
                            ['text' => '🔧 سرویس‌های کاربر', 'callback_data' => "show_user_services_{$target_user['chat_id']}"]
                        ],
                        [
                             ['text' => $status_button_text, 'callback_data' => $status_callback]
                        ],
                        [
                            ['text' => '🔎 جستجوی کاربر دیگر', 'callback_data' => 'search_another_user']
                        ]
                    ]];

                    sendMessage($chat_id, $message, $keyboard);
                    // وضعیت را به حالت انتظار برای جستجوی بعدی برمی‌گردانیم تا ادمین بتواند پشت سر هم جستجو کند
                    updateUserData($chat_id, 'admin_awaiting_user_search', ['admin_view' => 'admin']);
                }
                break;
            
            case 'admin_awaiting_renewal_price_day':
    if ($isAnAdmin && is_numeric($text) && $text >= 0) {
        $settings = getSettings();
        $settings['renewal_price_per_day'] = (int)$text;
        saveSettings($settings);
        sendMessage($chat_id, "✅ قیمت با موفقیت تنظیم شد.");
        updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
        showRenewalManagementMenu($chat_id);
    } else {
        sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.");
    }
    break;
    
                case 'admin_awaiting_merchant_id':
                if ($isAnAdmin && strlen($text) === 36) {
                    $settings = getSettings();
                    $settings['zarinpal_merchant_id'] = $text;
                    saveSettings($settings);
                    sendMessage($chat_id, "✅ مرچنت کد با موفقیت ذخیره شد.");
                    updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                 
                } else {
                    sendMessage($chat_id, "❌ مرچنت کد نامعتبر است. باید دقیقا ۳۶ کاراکتر باشد.");
                }
                break;

            case 'admin_awaiting_idpay_api_key':
                if (!hasPermission($chat_id, 'manage_payment')) {
                    break;
                }
                $settings = getSettings();
                $settings['idpay_api_key'] = $text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ API Key IDPay با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_nextpay_api_key':
                if (!hasPermission($chat_id, 'manage_payment')) {
                    break;
                }
                $settings = getSettings();
                $settings['nextpay_api_key'] = $text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ API Key NextPay با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_zibal_merchant_id':
                if (!hasPermission($chat_id, 'manage_payment')) {
                    break;
                }
                $settings = getSettings();
                $settings['zibal_merchant_id'] = $text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ مرچنت کد زیبال با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_newpayment_api_key':
                if (!hasPermission($chat_id, 'manage_payment')) {
                    break;
                }
                $settings = getSettings();
                $settings['newpayment_api_key'] = $text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ API Key newPayment با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;
    
            case 'admin_awaiting_renewal_price_gb':
    if ($isAnAdmin && is_numeric($text) && $text >= 0) {
        $settings = getSettings();
        $settings['renewal_price_per_gb'] = (int)$text;
        saveSettings($settings);
        sendMessage($chat_id, "✅ قیمت با موفقیت تنظیم شد.");
        updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
        showRenewalManagementMenu($chat_id);
    } else {
        sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.");
    }
    break;
    
            case 'admin_awaiting_category_name':
                if (!hasPermission($chat_id, 'manage_categories')) {
                    break;
                }
                $stmt = pdo()->prepare("INSERT INTO categories (name) VALUES (?)");
                $stmt->execute([$text]);
                sendMessage($chat_id, "✅ دسته‌بندی « $text » با موفقیت اضافه شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'awaiting_plan_name':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_name'] = $text;
                // مقداردهی اولیه برای فیلدهای پلن قابل تنظیم
                $state_data['new_plan_custom_volume_enabled'] = 0;
                $state_data['new_plan_min_volume_gb'] = 0;
                $state_data['new_plan_max_volume_gb'] = 0;
                $state_data['new_plan_min_duration_days'] = 0;
                $state_data['new_plan_max_duration_days'] = 0;
                $state_data['new_plan_price_per_gb'] = 0.00;
                $state_data['new_plan_price_per_day'] = 0.00;
                updateUserData($chat_id, 'awaiting_plan_price', $state_data);
                sendMessage($chat_id, "2/7 - لطفا قیمت پلن را به تومان وارد کنید (فقط عدد):\n\n⚠️ توجه: برای پلن‌های قابل تنظیم، این قیمت به عنوان قیمت پایه در نظر گرفته می‌شود.", $cancelKeyboard);
                break;

            case 'awaiting_plan_price':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text)) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_price'] = (int)$text;
                updateUserData($chat_id, 'awaiting_plan_custom_volume_enabled', $state_data);
                $keyboard = ['inline_keyboard' => [
                    [['text' => '✅ بله', 'callback_data' => 'plan_custom_volume_enabled_yes'], ['text' => '❌ خیر', 'callback_data' => 'plan_custom_volume_enabled_no']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_admin_panel']]
                ]];
                sendMessage($chat_id, "3/7 - آیا این پلن قابل تنظیم باشد؟ (کاربر می‌تواند حجم و روز مورد نظرش را انتخاب کند)\n\nاگر بله را انتخاب کنید، کاربر می‌تواند در محدوده مشخص شده، حجم و روز مورد نظرش را انتخاب کند و قیمت به صورت خودکار محاسبه می‌شود.", $keyboard);
                break;

            case 'awaiting_plan_min_volume':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text) || (int)$text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_min_volume_gb'] = (int)$text;
                updateUserData($chat_id, 'awaiting_plan_max_volume', $state_data);
                $keyboard = ['keyboard' => [[['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "✅ حداقل حجم " . number_format($text) . " GB تنظیم شد.\n\n3.2/7 - حداکثر حجم را به گیگابایت (GB) وارد کنید (فقط عدد، باید بزرگتر یا مساوی حداقل حجم باشد):", $keyboard);
                break;

            case 'awaiting_plan_max_volume':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text) || (int)$text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $min_vol = $state_data['new_plan_min_volume_gb'] ?? 0;
                $max_vol = (int)$text;
                if ($max_vol < $min_vol) {
                    sendMessage($chat_id, "❌ حداکثر حجم باید بزرگتر یا مساوی حداقل حجم ({$min_vol} GB) باشد.", $cancelKeyboard);
                    break;
                }
                $state_data['new_plan_max_volume_gb'] = $max_vol;
                updateUserData($chat_id, 'awaiting_plan_min_duration', $state_data);
                $keyboard = ['keyboard' => [[['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "✅ حداکثر حجم " . number_format($text) . " GB تنظیم شد.\n\n3.3/7 - حداقل روز را وارد کنید (فقط عدد):", $keyboard);
                break;

            case 'awaiting_plan_min_duration':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text) || (int)$text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_min_duration_days'] = (int)$text;
                updateUserData($chat_id, 'awaiting_plan_max_duration', $state_data);
                $keyboard = ['keyboard' => [[['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "✅ حداقل روز " . number_format($text) . " روز تنظیم شد.\n\n3.4/7 - حداکثر روز را وارد کنید (فقط عدد، باید بزرگتر یا مساوی حداقل روز باشد):", $keyboard);
                break;

            case 'awaiting_plan_max_duration':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text) || (int)$text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $min_days = $state_data['new_plan_min_duration_days'] ?? 0;
                $max_days = (int)$text;
                if ($max_days < $min_days) {
                    sendMessage($chat_id, "❌ حداکثر روز باید بزرگتر یا مساوی حداقل روز ({$min_days} روز) باشد.", $cancelKeyboard);
                    break;
                }
                $state_data['new_plan_max_duration_days'] = $max_days;
                updateUserData($chat_id, 'awaiting_plan_price_per_gb', $state_data);
                $keyboard = ['keyboard' => [[['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "✅ حداکثر روز " . number_format($text) . " روز تنظیم شد.\n\n3.5/7 - قیمت هر گیگابایت (GB) را به تومان وارد کنید (فقط عدد):", $keyboard);
                break;

            case 'awaiting_plan_price_per_gb':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text) || (float)$text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_price_per_gb'] = (float)$text;
                updateUserData($chat_id, 'awaiting_plan_price_per_day', $state_data);
                $keyboard = ['keyboard' => [[['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "✅ قیمت هر GB " . number_format($text) . " تومان تنظیم شد.\n\n3.6/7 - قیمت هر روز را به تومان وارد کنید (فقط عدد):", $keyboard);
                break;

            case 'awaiting_plan_price_per_day':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text) || (float)$text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_price_per_day'] = (float)$text;
                // برای پلن قابل تنظیم، volume_gb و duration_days را 0 می‌گذاریم (نامحدود) چون کاربر خودش انتخاب می‌کند
                $state_data['new_plan_volume'] = 0;
                $state_data['new_plan_duration'] = 0;
                updateUserData($chat_id, 'awaiting_plan_description', $state_data);
                $keyboard = ['keyboard' => [[['text' => 'رد شدن'], ['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "✅ قیمت هر روز " . number_format($text) . " تومان تنظیم شد.\n\n4/7 - در صورت تمایل، توضیحات مختصری برای پلن وارد کنید (اختیاری):", $keyboard);
                break;

            case 'awaiting_plan_volume':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                // بررسی دکمه نامحدود
                if ($text === 'نامحدود' || strtolower($text) === 'unlimited' || $text === '0') {
                    $state_data = $user_data['state_data'];
                    $state_data['new_plan_volume'] = 0; // 0 به معنای نامحدود
                    updateUserData($chat_id, 'awaiting_plan_duration', $state_data);
                    $keyboard = ['inline_keyboard' => [
                        [['text' => '♾️ نامحدود', 'callback_data' => 'plan_duration_unlimited']],
                        [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_admin_panel']]
                    ]];
                    sendMessage($chat_id, "✅ حجم نامحدود تنظیم شد.\n\n4/7 - لطفا مدت زمان پلن را به روز وارد کنید (فقط عدد) یا دکمه نامحدود را انتخاب کنید:", $keyboard);
                    break;
                }
                if (!is_numeric($text) || (int)$text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد مثبت وارد کنید یا دکمه نامحدود را انتخاب کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_volume'] = (int)$text;
                updateUserData($chat_id, 'awaiting_plan_duration', $state_data);
                $keyboard = ['inline_keyboard' => [
                    [['text' => '♾️ نامحدود', 'callback_data' => 'plan_duration_unlimited']],
                    [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_admin_panel']]
                ]];
                sendMessage($chat_id, "✅ حجم " . number_format($text) . " GB تنظیم شد.\n\n4/7 - لطفا مدت زمان پلن را به روز وارد کنید (فقط عدد) یا دکمه نامحدود را انتخاب کنید:", $keyboard);
                break;

            case 'awaiting_plan_duration':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                // بررسی دکمه نامحدود
                if ($text === 'نامحدود' || strtolower($text) === 'unlimited' || $text === '0') {
                    $state_data = $user_data['state_data'];
                    $state_data['new_plan_duration'] = 0; // 0 به معنای نامحدود
                    updateUserData($chat_id, 'awaiting_plan_description', $state_data);
                    $keyboard = ['keyboard' => [[['text' => 'رد شدن'], ['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                    sendMessage($chat_id, "✅ مدت زمان نامحدود تنظیم شد.\n\n4/7 - در صورت تمایل، توضیحات مختصری برای پلن وارد کنید (اختیاری):", $keyboard);
                    break;
                }
                if (!is_numeric($text) || (int)$text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد مثبت وارد کنید یا دکمه نامحدود را انتخاب کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_duration'] = (int)$text;
                updateUserData($chat_id, 'awaiting_plan_description', $state_data);
                $keyboard = ['keyboard' => [[['text' => 'رد شدن'], ['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "✅ مدت زمان " . number_format($text) . " روز تنظیم شد.\n\n4/7 - در صورت تمایل، توضیحات مختصری برای پلن وارد کنید (اختیاری):", $keyboard);
                break;

            case 'awaiting_plan_description':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                $description = $text == 'رد شدن' ? '' : $text;
                $state_data = $user_data['state_data'];

                $state_data['new_plan_description'] = $description;
                updateUserData($chat_id, 'awaiting_plan_purchase_limit', $state_data);

                $keyboard = ['keyboard' => [[['text' => '0 (نامحدود)'], ['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "5/7 - تعداد مجاز خرید برای این پلن را وارد کنید (فقط عدد).\n\nبرای فروش نامحدود، عدد `0` را وارد کنید.", $keyboard);
                break;

                        case 'awaiting_plan_purchase_limit':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text) || (int)$text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط یک عدد صحیح (مثبت یا صفر) وارد کنید.", $cancelKeyboard);
                    break;
                }

                $state_data = $user_data['state_data'];
                $new_plan_data = [
                    'server_id' => $state_data['new_plan_server_id'],
                    'inbound_id' => $state_data['new_plan_inbound_id'] ?? null,
                    'marzneshin_service_id' => $state_data['new_plan_marzneshin_service_id'] ?? null,
                    'category_id' => $state_data['new_plan_category_id'],
                    'name' => $state_data['new_plan_name'],
                    'price' => $state_data['new_plan_price'],
                    'volume_gb' => $state_data['new_plan_volume'],
                    'duration_days' => $state_data['new_plan_duration'],
                    'description' => $state_data['new_plan_description'],
                    'purchase_limit' => (int)$text,
                    'custom_volume_enabled' => $state_data['new_plan_custom_volume_enabled'] ?? 0,
                    'min_volume_gb' => $state_data['new_plan_min_volume_gb'] ?? 0,
                    'max_volume_gb' => $state_data['new_plan_max_volume_gb'] ?? 0,
                    'min_duration_days' => $state_data['new_plan_min_duration_days'] ?? 0,
                    'max_duration_days' => $state_data['new_plan_max_duration_days'] ?? 0,
                    'price_per_gb' => $state_data['new_plan_price_per_gb'] ?? 0.00,
                    'price_per_day' => $state_data['new_plan_price_per_day'] ?? 0.00,
                ];

                updateUserData($chat_id, 'awaiting_plan_sub_link_setting', ['temp_plan_data' => $new_plan_data]);

                $keyboard = ['inline_keyboard' => [[['text' => '✅ بله', 'callback_data' => 'plan_set_sub_yes'], ['text' => '❌ خیر', 'callback_data' => 'plan_set_sub_no']]]];
                sendMessage($chat_id, "6/7 - سوال ۱/۲: آیا لینک اشتراک (Subscription) به کاربر نمایش داده شود؟\n(پیشنهادی: بله)", $keyboard);
                break;
            
                case 'admin_awaiting_sub_host':
                if (!hasPermission($chat_id, 'manage_marzban')) break;

                $state_data = $user_data['state_data'];
                $server_id = $state_data['editing_server_id'];
                $new_sub_host = null;
                $message_text = "";
                
                if (strtolower($text) === 'reset') {
                    $new_sub_host = null;
                    $message_text = "✅ آدرس لینک اشتراک با موفقیت به حالت پیش‌فرض بازنشانی شد.";
                } elseif (!filter_var($text, FILTER_VALIDATE_URL)) {
                    sendMessage($chat_id, "❌ آدرس وارد شده نامعتبر است. لطفا آدرس را به همراه http یا https و پورت صحیح وارد کنید.", $cancelKeyboard);
                    break;
                } else {
                    $new_sub_host = rtrim($text, '/');
                    $message_text = "✅ آدرس لینک اشتراک با موفقیت روی `{$new_sub_host}` تنظیم شد.";
                }

                $stmt = pdo()->prepare("UPDATE servers SET sub_host = ? WHERE id = ?");
                $stmt->execute([$new_sub_host, $server_id]);
                
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                sendMessage($chat_id, $message_text);
                
                $servers = pdo()->query("SELECT id, name FROM servers")->fetchAll(PDO::FETCH_ASSOC);
                $keyboard_buttons = [[['text' => '➕ افزودن سرور جدید', 'callback_data' => 'add_server_select_type']]];
                foreach ($servers as $server) {
                    $keyboard_buttons[] = [['text' => "🖥 {$server['name']}", 'callback_data' => "view_server_{$server['id']}"]];
                }
                $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']];
                sendMessage($chat_id, "<b>🌐 لیست سرورها به‌روز شد.</b>", ['inline_keyboard' => $keyboard_buttons]);
                break;
            
            case 'admin_awaiting_card_number':
                if (!hasPermission($chat_id, 'manage_payment')) {
                    break;
                }
                if (!preg_match('/^\d{16}$/', str_replace(['-', ' '], '', $text))) {
                    sendMessage($chat_id, "❌ شماره کارت نامعتبر است. لطفا یک شماره ۱۶ رقمی صحیح وارد کنید.", $cancelKeyboard);
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_card_holder', ['temp_card_number' => $text]);
                sendMessage($chat_id, "مرحله ۲/۳: نام و نام خانوادگی صاحب حساب را وارد کنید:", $cancelKeyboard);
                break;

            case 'admin_awaiting_card_holder':
                if (!hasPermission($chat_id, 'manage_payment')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['temp_card_holder'] = $text;
                updateUserData($chat_id, 'admin_awaiting_copy_toggle', $state_data);
                $keyboard = ['inline_keyboard' => [[['text' => '✅ فعال', 'callback_data' => 'copy_toggle_yes'], ['text' => '❌ غیرفعال', 'callback_data' => 'copy_toggle_no']]]];
                sendMessage($chat_id, "مرحله ۳/۳: آیا کاربر بتواند با کلیک روی شماره کارت آن را کپی کند؟", $keyboard);
                break;

            case 'admin_awaiting_server_name':
                if (!hasPermission($chat_id, 'manage_marzban')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $server_type = $state_data['selected_server_type'] ?? 'marzban';
                $state_data['temp_server_name'] = $text;
                
                // برای Hiddify، مرحله‌ها متفاوت است (نام، URL، API Key)
                if ($server_type === 'hiddify') {
                    updateUserData($chat_id, 'admin_awaiting_server_url', $state_data);
                    sendMessage($chat_id, "مرحله ۲/۳: لطفا آدرس کامل پنل Hiddify را وارد کنید (مثال: https://example.com):", $cancelKeyboard);
                } else {
                    // برای سایر پنل‌ها (شامل AliReza)
                    updateUserData($chat_id, 'admin_awaiting_server_url', $state_data);
                    sendMessage($chat_id, "مرحله ۲/۴: لطفا آدرس کامل پنل را وارد کنید (مثال: https://example.com:2053):", $cancelKeyboard);
                }
                break;
            case 'admin_awaiting_server_url':
                if (!hasPermission($chat_id, 'manage_marzban')) {
                    break;
                }
                if (!filter_var($text, FILTER_VALIDATE_URL)) {
                    sendMessage($chat_id, "❌ آدرس وارد شده نامعتبر است. لطفا آدرس را به همراه http یا https وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $server_type = $state_data['selected_server_type'] ?? 'marzban';
                $state_data['temp_server_url'] = rtrim($text, '/');
                
            if ($server_type === 'hiddify') {
                // برای هیدیفای، بعد از URL، مستقیماً API Key می‌خواهیم
                updateUserData($chat_id, 'admin_awaiting_server_pass', $state_data);
                sendMessage($chat_id, "مرحله ۳/۳: لطفا API Key (secret_code) پنل هیدیفای را وارد کنید:", $cancelKeyboard);
                } else {
                    // برای سایر پنل‌ها (شامل AliReza)
                    updateUserData($chat_id, 'admin_awaiting_server_user', $state_data);
                    sendMessage($chat_id, "مرحله ۳/۴: لطفا نام کاربری ادمین پنل را وارد کنید:", $cancelKeyboard);
                }
                break;
            case 'admin_awaiting_server_user':
                if (!hasPermission($chat_id, 'manage_marzban')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['temp_server_user'] = $text;
                updateUserData($chat_id, 'admin_awaiting_server_pass', $state_data);
                sendMessage($chat_id, "مرحله ۴/۴: لطفا رمز عبور ادمین پنل را وارد کنید:", $cancelKeyboard);
                break;
            case 'admin_awaiting_server_pass':
                if (!hasPermission($chat_id, 'manage_marzban')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $server_type = $state_data['selected_server_type'] ?? 'marzban';
                
                // برای Hiddify، password همان API Key است و username خالی است
                if ($server_type === 'hiddify') {
                    $stmt = pdo()->prepare("INSERT INTO servers (name, url, username, password, type) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$state_data['temp_server_name'], $state_data['temp_server_url'], '', $text, 'hiddify']);
                } else {
                    // برای سایر پنل‌ها (شامل AliReza)
                    $stmt = pdo()->prepare("INSERT INTO servers (name, url, username, password, type) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$state_data['temp_server_name'], $state_data['temp_server_url'], $state_data['temp_server_user'], $text, $server_type]);
                }
                
                $new_server_id = pdo()->lastInsertId();
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                sendMessage($chat_id, "✅ سرور جدید با موفقیت ذخیره شد.\n\n⏳ در حال تست ارتباط با سرور...");

                $tokenResult = false;
                $connection_error = null;

                if ($server_type === 'marzban') {
                    $tokenResult = getMarzbanToken($new_server_id);
                } elseif ($server_type === 'sanaei') {
                    $tokenResult = getSanaeiCookie($new_server_id);
                } elseif ($server_type === 'marzneshin') {
                    $tokenResult = getMarzneshinToken($new_server_id);
                    // برای مرزنشین، اگر نتیجه یک آرایه باشد، یعنی خطا است
                    if (is_array($tokenResult) && isset($tokenResult['error'])) {
                        $connection_error = $tokenResult['error'];
                        $tokenResult = false; // ست کردن روی false تا شرط پایین برقرار شود
                    }
                } elseif ($server_type === 'hiddify') {
                    // برای Hiddify، تست اتصال با API v2
                    require_once __DIR__ . '/api/hiddify_api.php';
                    $test_response = hiddifyApiRequest('/api/v2/admin/user/', $new_server_id, 'GET');
                    $tokenResult = ($test_response !== false && !isset($test_response['error']) && is_array($test_response));
                    if (!$tokenResult) {
                        if (isset($test_response['error'])) {
                            $connection_error = $test_response['error'];
                        } else {
                            $connection_error = 'Failed to connect to Hiddify panel';
                        }
                    }
                } elseif ($server_type === 'alireza') {
                    // برای AliReza، تست دریافت Cookie
                    require_once __DIR__ . '/api/alireza_api.php';
                    $tokenResult = getAlirezaCookie($new_server_id);
                    if (!$tokenResult) {
                        $connection_error = 'Failed to login to AliReza panel';
                    }
                } elseif ($server_type === 'pasargad') {
                    // برای پاسارگاد، تست دریافت Token
                    require_once __DIR__ . '/api/pasargad_api.php';
                    $stmt = pdo()->prepare("SELECT username, password FROM servers WHERE id = ?");
                    $stmt->execute([$new_server_id]);
                    $server_info = $stmt->fetch();
                    if ($server_info) {
                        $tokenResult = getPasargadToken($new_server_id, $server_info['username'], $server_info['password']);
                    }
                } elseif ($server_type === 'txui') {
                    // برای TX-UI، تست اتصال با دریافت لیست inbounds
                    require_once __DIR__ . '/api/txui_api.php';
                    $test_response = txuiApiRequest('/panel/api/inbounds/list', $new_server_id, 'GET');
                    $tokenResult = ($test_response !== false && isset($test_response['success']) && $test_response['success'] === true);
                    if (!$tokenResult) {
                        if (isset($test_response['msg'])) {
                            $connection_error = $test_response['msg'];
                        } else {
                            $connection_error = 'Failed to connect to TX-UI panel';
                        }
                    }
                }

                if ($tokenResult) {
                    sendMessage($chat_id, "✅ ارتباط با سرور '{$state_data['temp_server_name']}' با موفقیت برقرار شد.");
                } else {
                    $error_message = "⚠️ <b>هشدار:</b> ربات نتوانست به سرور جدید متصل شود. لطفا اطلاعات وارد شده را بررسی کرده و در صورت نیاز سرور را حذف و مجدداً اضافه کنید.";
                    if ($connection_error) {
                       $error_message .= "\n\n<b>جزئیات خطا:</b>\n<code>" . htmlspecialchars($connection_error) . "</code>";
                    }
                    sendMessage($chat_id, $error_message);
                }
                handleMainMenu($chat_id, $first_name);
                break;
                
                        case 'admin_awaiting_plan_edit_input':
                if (!hasPermission($chat_id, 'manage_plans')) break;

                $state_data = $user_data['state_data'];
                $plan_id = $state_data['editing_plan_id'];
                $field_info = $state_data['editing_field_info'];
                $editor_message_id = $state_data['editor_message_id'];
                $column = $field_info['column'];
                $validation = $field_info['validation'];
                $value = $text;
                $user_message_id = $update['message']['message_id'];
                
                $is_valid = false;
                if ($validation === 'text' && !empty($value)) {
                    $is_valid = true;
                } elseif (($validation === 'numeric' || $validation === 'numeric_zero') && is_numeric($value) && $value >= 0) {
                    $is_valid = true;
                }

                if (!$is_valid) {
                    showPlanEditor($chat_id, $editor_message_id, $plan_id, "❌ ورودی نامعتبر! " . $field_info['prompt']);
                    deleteMessage($chat_id, $user_message_id);
                    break;
                }
                
                $stmt = pdo()->prepare("UPDATE plans SET `{$column}` = ? WHERE id = ?");
                $stmt->execute([$value, $plan_id]);
                
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                showPlanEditor($chat_id, $editor_message_id, $plan_id, "✅ مقدار با موفقیت به‌روز شد.");
                deleteMessage($chat_id, $user_message_id);
                break;

                        case 'awaiting_charge_amount':
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفا یک مبلغ معتبر (عدد مثبت) به تومان وارد کنید.", $cancelKeyboard);
                    break;
                }
                $amount = (int)$text;
                $settings = getSettings();
                
                $keyboard_buttons = [];
               
                if (!empty($settings['payment_method']['card_number'])) {
                    $keyboard_buttons[] = [['text' => '💳 پرداخت کارت به کارت', 'callback_data' => "charge_manual_{$amount}"]];
                }

                if (($settings['payment_gateway_status'] ?? 'off') == 'on' && !empty($settings['zarinpal_merchant_id'])) {
                    $keyboard_buttons[] = [['text' => '🌐 پرداخت آنلاین (زرین‌پال)', 'callback_data' => "charge_zarinpal_{$amount}"]];
                }
                if (($settings['idpay_enabled'] ?? 'off') == 'on' && !empty($settings['idpay_api_key'])) {
                    $keyboard_buttons[] = [['text' => '🔷 پرداخت آنلاین (IDPay)', 'callback_data' => "charge_idpay_{$amount}"]];
                }
                if (($settings['nextpay_enabled'] ?? 'off') == 'on' && !empty($settings['nextpay_api_key'])) {
                    $keyboard_buttons[] = [['text' => '🔶 پرداخت آنلاین (NextPay)', 'callback_data' => "charge_nextpay_{$amount}"]];
                }
                if (($settings['zibal_enabled'] ?? 'off') == 'on' && !empty($settings['zibal_merchant_id'])) {
                    $keyboard_buttons[] = [['text' => '💛 پرداخت آنلاین (زیبال)', 'callback_data' => "charge_zibal_{$amount}"]];
                }
                if (($settings['newpayment_enabled'] ?? 'off') == 'on' && !empty($settings['newpayment_api_key'])) {
                    $keyboard_buttons[] = [['text' => '🆕 پرداخت آنلاین (newPayment)', 'callback_data' => "charge_newpayment_{$amount}"]];
                }
                if (($settings['aqayepardakht_enabled'] ?? 'off') == 'on' && !empty($settings['aqayepardakht_pin'])) {
                    $keyboard_buttons[] = [['text' => '👨‍💼 پرداخت آنلاین (آقای پرداخت)', 'callback_data' => "charge_aqayepardakht_{$amount}"]];
                }
                
                if (empty($keyboard_buttons)) {
                    sendMessage($chat_id, "متاسفانه هیچ روش پرداختی توسط ادمین فعال نشده است.");
                    updateUserData($chat_id, 'main_menu');
                    handleMainMenu($chat_id, $first_name);
                } else {
                    $message = "لطفا روش پرداخت برای شارژ به مبلغ " . number_format($amount) . " تومان را انتخاب کنید:";
                    sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
                }
                break;

            case 'awaiting_ticket_subject':
                updateUserData($chat_id, 'awaiting_ticket_message', ['ticket_subject' => $text]);
                sendMessage($chat_id, "✅ موضوع ثبت شد.\n\nحالا لطفا متن پیام خود را به طور کامل وارد کنید:", $cancelKeyboard);
                break;

            case 'awaiting_ticket_message':
                $state_data = $user_data['state_data'];
                $subject = $state_data['ticket_subject'];
                $ticket_id = 'T' . time();

                $stmt = pdo()->prepare("INSERT INTO tickets (id, user_id, user_name, subject, status) VALUES (?, ?, ?, ?, 'open')");
                $stmt->execute([$ticket_id, $chat_id, $first_name, $subject]);

                $stmt2 = pdo()->prepare("INSERT INTO ticket_conversations (ticket_id, sender, message_text) VALUES (?, 'user', ?)");
                $stmt2->execute([$ticket_id, $text]);

                $admin_message =
                    "<b>🎫 تیکت پشتیبانی جدید</b>\n\n" . "▫️ شماره تیکت: <code>$ticket_id</code>\n" . "👤 از طرف: $first_name (<code>$chat_id</code>)\n" . "▫️ موضوع: <b>$subject</b>\n\n" . "✉️ پیام:\n" . htmlspecialchars($text);
                $admin_keyboard = ['inline_keyboard' => [[['text' => '💬 پاسخ', 'callback_data' => "reply_ticket_{$ticket_id}"], ['text' => '✖️ بستن تیکت', 'callback_data' => "close_ticket_{$ticket_id}"]]]];
                $all_admins = getAdmins();
                $all_admins[ADMIN_CHAT_ID] = [];
                foreach (array_keys($all_admins) as $admin_id) {
                    if (hasPermission($admin_id, 'view_tickets')) {
                        sendMessage($admin_id, $admin_message, $admin_keyboard);
                    }
                }
                sendMessage($chat_id, "✅ تیکت شما با شماره <code>$ticket_id</code> با موفقیت ثبت شد. به زودی توسط پشتیبانی پاسخ داده خواهد شد.");
                updateUserData($chat_id, 'main_menu');
                handleMainMenu($chat_id, $first_name);
                break;

            case 'user_replying_to_ticket':
                $state_data = $user_data['state_data'];
                $ticket_id = $state_data['replying_to_ticket'];

                $stmt = pdo()->prepare("INSERT INTO ticket_conversations (ticket_id, sender, message_text) VALUES (?, 'user', ?)");
                $stmt->execute([$ticket_id, $text]);
                $stmt_update = pdo()->prepare("UPDATE tickets SET status = 'user_reply' WHERE id = ?");
                $stmt_update->execute([$ticket_id]);

                $admin_message = "<b>💬 پاسخ جدید از کاربر</b>\n\n" . "▫️ شماره تیکت: <code>$ticket_id</code>\n" . "👤 کاربر: $first_name (<code>$chat_id</code>)\n\n" . "✉️ پیام:\n" . htmlspecialchars($text);
                $admin_keyboard = ['inline_keyboard' => [[['text' => '💬 پاسخ مجدد', 'callback_data' => "reply_ticket_{$ticket_id}"], ['text' => '✖️ بستن تیکت', 'callback_data' => "close_ticket_{$ticket_id}"]]]];
                $all_admins = getAdmins();
                $all_admins[ADMIN_CHAT_ID] = [];
                foreach (array_keys($all_admins) as $admin_id) {
                    if (hasPermission($admin_id, 'view_tickets')) {
                        sendMessage($admin_id, $admin_message, $admin_keyboard);
                    }
                }
                sendMessage($chat_id, "✅ پاسخ شما ارسال شد.");
                updateUserData($chat_id, 'main_menu');
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_replying_to_ticket':
                if (!$isAnAdmin) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $ticket_id = $state_data['replying_to_ticket'];

                $stmt = pdo()->prepare("SELECT user_id FROM tickets WHERE id = ?");
                $stmt->execute([$ticket_id]);
                $target_user_id = $stmt->fetchColumn();

                if ($target_user_id) {
                    $stmt_insert = pdo()->prepare("INSERT INTO ticket_conversations (ticket_id, sender, message_text) VALUES (?, 'admin', ?)");
                    $stmt_insert->execute([$ticket_id, $text]);
                    $stmt_update = pdo()->prepare("UPDATE tickets SET status = 'admin_reply' WHERE id = ?");
                    $stmt_update->execute([$ticket_id]);

                    $user_message = "<b>💬 پاسخ از پشتیبانی</b>\n\n" . "▫️ شماره تیکت: <code>$ticket_id</code>\n\n" . "✉️ پیام:\n" . htmlspecialchars($text);
                    $user_keyboard = ['inline_keyboard' => [[['text' => '💬 پاسخ مجدد', 'callback_data' => "reply_ticket_{$ticket_id}"], ['text' => '✖️ بستن تیکت', 'callback_data' => "close_ticket_{$ticket_id}"]]]];
                    sendMessage($target_user_id, $user_message, $user_keyboard);
                    sendMessage($chat_id, "✅ پاسخ شما برای کاربر ارسال شد.");
                }
                else {
                    sendMessage($chat_id, "❌ خطایی در ارسال پاسخ رخ داد. تیکت یا کاربر یافت نشد.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_user_id_for_add_balance':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || !getUserData($text, '')) {
                    sendMessage($chat_id, "❌ شناسه عددی نامعتبر است یا کاربری با این شناسه یافت نشد.", $cancelKeyboard);
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_amount_for_add_balance', ['target_user_id' => $text]);
                sendMessage($chat_id, "لطفا مبلغی که می‌خواهید به موجودی کاربر اضافه کنید را به تومان وارد کنید:", $cancelKeyboard);
                break;

            case 'admin_awaiting_amount_for_add_balance':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفا یک مبلغ عددی و مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $target_id = $state_data['target_user_id'];
                updateUserBalance($target_id, (int)$text, 'add');
                $new_balance_data = getUserData($target_id, '');
                sendMessage($chat_id, "✅ مبلغ " . number_format($text) . " تومان با موفقیت به موجودی کاربر <code>$target_id</code> اضافه شد.");
                sendMessage($target_id, "✅ مبلغ " . number_format($text) . " تومان توسط ادمین به موجودی شما اضافه شد.\nموجودی جدید: " . number_format($new_balance_data['balance']) . " تومان.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_user_id_for_deduct_balance':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || !getUserData($text, '')) {
                    sendMessage($chat_id, "❌ شناسه عددی نامعتبر است یا کاربری با این شناسه یافت نشد.", $cancelKeyboard);
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_amount_for_deduct_balance', ['target_user_id' => $text]);
                sendMessage($chat_id, "لطفا مبلغی که می‌خواهید از موجودی کاربر کسر کنید را به تومان وارد کنید:", $cancelKeyboard);
                break;

            case 'admin_awaiting_amount_for_deduct_balance':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفا یک مبلغ عددی و مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $target_id = $state_data['target_user_id'];
                $target_user_data = getUserData($target_id, '');
                if ($target_user_data['balance'] < (int)$text) {
                    sendMessage($chat_id, "❌ موجودی کاربر برای کسر این مبلغ کافی نیست.\nموجودی فعلی: " . number_format($target_user_data['balance']) . " تومان", $cancelKeyboard);
                    break;
                }
                updateUserBalance($target_id, (int)$text, 'deduct');
                $new_balance_data = getUserData($target_id, '');
                sendMessage($chat_id, "✅ مبلغ " . number_format($text) . " تومان با موفقیت از موجودی کاربر <code>$target_id</code> کسر شد.");
                sendMessage($target_id, "❗️ مبلغ " . number_format($text) . " تومان توسط ادمین از موجودی شما کسر شد.\nموجودی جدید: " . number_format($new_balance_data['balance']) . " تومان.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_user_id_for_message':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || !getUserData($text, '')) {
                    sendMessage($chat_id, "❌ شناسه عددی نامعتبر است یا کاربری با این شناسه یافت نشد.", $cancelKeyboard);
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_message_for_user', ['target_user_id' => $text]);
                sendMessage($chat_id, "پیام خود را برای ارسال به کاربر <code>$text</code> وارد کنید:", $cancelKeyboard);
                break;

            case 'admin_awaiting_message_for_user':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $target_id = $state_data['target_user_id'];
                $message_to_send = "<b>پیامی از طرف پشتیبانی:</b>\n\n" . htmlspecialchars($text);
                $result = sendMessage($target_id, $message_to_send);
                $decoded_result = json_decode($result, true);
                if ($decoded_result && $decoded_result['ok']) {
                    sendMessage($chat_id, "✅ پیام شما با موفقیت به کاربر <code>$target_id</code> ارسال شد.");
                }
                else {
                    sendMessage($chat_id, "❌ ارسال پیام به کاربر <code>$target_id</code> ناموفق بود. ممکن است کاربر ربات را بلاک کرده باشد.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_user_id_for_ban':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || !getUserData($text, '')) {
                    sendMessage($chat_id, "❌ شناسه عددی نامعتبر است یا کاربری با این شناسه یافت نشد.", $cancelKeyboard);
                    break;
                }
                if ($text == ADMIN_CHAT_ID) {
                    sendMessage($chat_id, "❌ شما نمی‌توانید خودتان را مسدود کنید!", $cancelKeyboard);
                    break;
                }
                setUserStatus($text, 'banned');
                sendMessage($chat_id, "✅ کاربر با شناسه <code>$text</code> با موفقیت مسدود شد.");
                sendMessage($text, "شما توسط ادمین از ربات مسدود شده‌اید.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_user_id_for_unban':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || !getUserData($text, '')) {
                    sendMessage($chat_id, "❌ شناسه عددی نامعتبر است یا کاربری با این شناسه یافت نشد.", $cancelKeyboard);
                    break;
                }
                setUserStatus($text, 'active');
                sendMessage($chat_id, "✅ کاربر با شناسه <code>$text</code> با موفقیت از حالت مسدودیت خارج شد.");
                sendMessage($text, "✅ شما توسط ادمین از حالت مسدودیت خارج شدید. می‌توانید از ربات استفاده کنید.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_broadcast_message':
                if (!hasPermission($chat_id, 'broadcast')) {
                    break;
                }
                $user_ids = getAllUsers();
                $success_count = 0;
                sendMessage($chat_id, "⏳ در حال شروع ارسال پیام همگانی...");
                foreach ($user_ids as $user_id) {
                    $result = sendMessage($user_id, $text);
                    $decoded_result = json_decode($result, true);
                    if ($decoded_result && $decoded_result['ok']) {
                        $success_count++;
                    }
                    usleep(100000);
                }
                sendMessage($chat_id, "✅ پیام شما با موفقیت به $success_count کاربر ارسال شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_message_for_all_admins':
                if (!hasPermission($chat_id, 'broadcast')) {
                    break;
                }
                $adminMessenger = AdminMessenger::getInstance();
                $result = $adminMessenger->sendToAllAdmins($text);
                sendMessage($chat_id, "✅ پیام شما به {$result['success_count']} ادمین ارسال شد.\n❌ تعداد ناموفق: {$result['failed_count']}");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_admin_id_for_message':
                if (!hasPermission($chat_id, 'broadcast')) {
                    break;
                }
                if (!is_numeric($text)) {
                    sendMessage($chat_id, "❌ لطفاً فقط عدد وارد کنید (آیدی عددی ادمین).", $cancelKeyboard);
                    break;
                }
                $target_admin_id = (int)$text;
                if (!isUserAdmin($target_admin_id)) {
                    sendMessage($chat_id, "❌ کاربری با این آیدی ادمین نیست.", $cancelKeyboard);
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_message_for_specific_admin', ['admin_view' => 'admin', 'target_admin_id' => $target_admin_id]);
                sendMessage($chat_id, "✅ آیدی ادمین تایید شد.\n\nلطفاً پیامی که می‌خواهید به این ادمین ارسال شود را وارد کنید:", $cancelKeyboard);
                break;

            case 'admin_awaiting_message_for_specific_admin':
                if (!hasPermission($chat_id, 'broadcast')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $target_admin_id = $state_data['target_admin_id'] ?? null;
                if (!$target_admin_id) {
                    sendMessage($chat_id, "❌ خطا: آیدی ادمین یافت نشد.");
                    updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                    handleMainMenu($chat_id, $first_name);
                    break;
                }
                $adminMessenger = AdminMessenger::getInstance();
                $success = $adminMessenger->sendToAdmin($target_admin_id, $text);
                if ($success) {
                    sendMessage($chat_id, "✅ پیام شما با موفقیت به ادمین ارسال شد.");
                } else {
                    sendMessage($chat_id, "❌ خطا در ارسال پیام به ادمین.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_join_channel_id':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                if (strpos($text, '@') !== 0) {
                    sendMessage($chat_id, "❌ شناسه کانال باید با @ شروع شود (مثال: @YourChannel).", $cancelKeyboard);
                    break;
                }
                $settings = getSettings();
                $settings['join_channel_id'] = $text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ کانال عضویت اجباری با موفقیت روی <code>$text</code> تنظیم شد.\nفراموش نکنید که ربات باید در این کانال ادمین باشد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_welcome_gift_amount':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفا یک مبلغ عددی (مثبت یا صفر) به تومان وارد کنید.", $cancelKeyboard);
                    break;
                }
                $settings = getSettings();
                $settings['welcome_gift_balance'] = (int)$text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ هدیه عضویت برای کاربران جدید روی " . number_format($text) . " تومان تنظیم شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_config_prefix':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                // پاکسازی prefix
                $prefix = preg_replace('/[^a-zA-Z0-9_.-]/', '', trim($text));
                if (empty($prefix)) {
                    sendMessage($chat_id, "❌ پیشوند نامعتبر است. لطفاً فقط از حروف انگلیسی، اعداد، خط تیره و زیرخط استفاده کنید.", $cancelKeyboard);
                    break;
                }
                // ذخیره prefix در state
                updateUserData($chat_id, 'admin_awaiting_config_start_number', ['admin_view' => 'admin', 'config_prefix' => $prefix]);
                sendMessage($chat_id, "✅ پیشوند <code>{$prefix}</code> تایید شد.\n\nمرحله ۲/۲: لطفاً شماره شروع را وارد کنید (فقط عدد):\n\nمثال: <code>0</code> یا <code>1</code>", $cancelKeyboard);
                break;

            case 'admin_awaiting_config_start_number':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفاً یک عدد صحیح (مثبت یا صفر) وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $prefix = $state_data['config_prefix'] ?? '';
                if (empty($prefix)) {
                    sendMessage($chat_id, "❌ خطا: پیشوند یافت نشد.");
                    updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                    handleMainMenu($chat_id, $first_name);
                    break;
                }
                $startNumber = (int)$text;
                if (class_exists('ConfigNaming')) {
                    $configNaming = ConfigNaming::getInstance();
                    $success = $configNaming->setConfigNaming($prefix, $startNumber);
                    if ($success) {
                        sendMessage($chat_id, "✅ تنظیمات نام کانفیگ با موفقیت ذخیره شد.\n\n▫️ پیشوند: <code>{$prefix}</code>\n▫️ شماره شروع: <b>{$startNumber}</b>\n\nنام کانفیگ‌های بعدی به صورت <code>{$prefix}{$startNumber}</code>، <code>{$prefix}" . ($startNumber + 1) . "</code> و ... خواهد بود.");
                    } else {
                        sendMessage($chat_id, "❌ خطا در ذخیره تنظیمات.");
                    }
                } else {
                    sendMessage($chat_id, "❌ سیستم نام‌گذاری کانفیگ در دسترس نیست.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_antispam_max_actions':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                if (!is_numeric($text) || (int)$text <= 0) {
                    sendMessage($chat_id, "❌ لطفاً یک عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $maxActions = (int)$text;
                if (file_exists(__DIR__ . '/includes/AntiSpam.php') && class_exists('AntiSpam')) {
                    require_once __DIR__ . '/includes/AntiSpam.php';
                    $antiSpam = AntiSpam::getInstance();
                    $antiSpam->updateSettings(['max_actions' => $maxActions]);
                    sendMessage($chat_id, "✅ حداکثر اعمال با موفقیت به <b>{$maxActions}</b> تنظیم شد.");
                } else {
                    sendMessage($chat_id, "❌ سیستم ضد اسپم در دسترس نیست.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_antispam_time_window':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                if (!is_numeric($text) || (int)$text <= 0) {
                    sendMessage($chat_id, "❌ لطفاً یک عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $timeWindow = (int)$text;
                if (file_exists(__DIR__ . '/includes/AntiSpam.php') && class_exists('AntiSpam')) {
                    require_once __DIR__ . '/includes/AntiSpam.php';
                    $antiSpam = AntiSpam::getInstance();
                    $antiSpam->updateSettings(['time_window' => $timeWindow]);
                    sendMessage($chat_id, "✅ بازه زمانی با موفقیت به <b>{$timeWindow} ثانیه</b> تنظیم شد.");
                } else {
                    sendMessage($chat_id, "❌ سیستم ضد اسپم در دسترس نیست.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_antispam_mute_duration':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                if (!is_numeric($text) || (int)$text <= 0) {
                    sendMessage($chat_id, "❌ لطفاً یک عدد مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $muteDuration = (int)$text;
                if (file_exists(__DIR__ . '/includes/AntiSpam.php') && class_exists('AntiSpam')) {
                    require_once __DIR__ . '/includes/AntiSpam.php';
                    $antiSpam = AntiSpam::getInstance();
                    $antiSpam->updateSettings(['mute_duration' => $muteDuration]);
                    sendMessage($chat_id, "✅ مدت زمان میوت با موفقیت به <b>{$muteDuration} دقیقه</b> تنظیم شد.");
                } else {
                    sendMessage($chat_id, "❌ سیستم ضد اسپم در دسترس نیست.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_antispam_message':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                if (empty(trim($text))) {
                    sendMessage($chat_id, "❌ لطفاً یک پیام وارد کنید.", $cancelKeyboard);
                    break;
                }
                $message = trim($text);
                if (file_exists(__DIR__ . '/includes/AntiSpam.php') && class_exists('AntiSpam')) {
                    require_once __DIR__ . '/includes/AntiSpam.php';
                    $antiSpam = AntiSpam::getInstance();
                    $antiSpam->updateSettings(['message' => $message]);
                    sendMessage($chat_id, "✅ پیام مسدودیت با موفقیت تنظیم شد.");
                } else {
                    sendMessage($chat_id, "❌ سیستم ضد اسپم در دسترس نیست.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_log_group_id':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                if (!is_numeric($text)) {
                    sendMessage($chat_id, "❌ لطفاً فقط عدد وارد کنید (آیدی عددی گروه).", $cancelKeyboard);
                    break;
                }
                $groupId = (int)$text;
                if (class_exists('LogManager')) {
                    $logManager = LogManager::getInstance();
                    if ($logManager->setLogGroupId($groupId)) {
                        sendMessage($chat_id, "✅ گروه لاگ‌ها با موفقیت تنظیم شد.\n\n👥 آیدی گروه: <code>{$groupId}</code>\n\nاز این پس لاگ‌ها به این گروه ارسال می‌شوند.");
                    } else {
                        sendMessage($chat_id, "❌ خطا در تنظیم گروه لاگ‌ها.");
                    }
                } else {
                    sendMessage($chat_id, "❌ سیستم مدیریت لاگ‌ها در دسترس نیست.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;


            case 'admin_awaiting_bulk_data_amount':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفا یک حجم معتبر (عدد مثبت) به گیگابایت وارد کنید.", $cancelKeyboard);
                    break;
                }
                sendMessage($chat_id, "⏳ عملیات افزودن حجم به تمام سرویس‌ها شروع شد. این فرآیند ممکن است کمی طول بکشد...");
                $data_to_add_gb = (float)$text;
                $bytes_to_add = $data_to_add_gb * 1024 * 1024 * 1024;
                $all_services = pdo()
                    ->query("SELECT marzban_username, server_id FROM services")
                    ->fetchAll(PDO::FETCH_ASSOC);
                $success_count = 0;
                $fail_count = 0;
                foreach ($all_services as $service) {
                    $username = $service['marzban_username'];
                    $server_id = $service['server_id'];
                    if (!$server_id) {
                        $fail_count++;
                        continue;
                    }

                    $current_user_data = getPanelUser($username, $server_id);
                    if ($current_user_data && !isset($current_user_data['detail'])) {
                        $current_limit = $current_user_data['data_limit'];
                        if ($current_limit > 0) {
                            $new_limit = $current_limit + $bytes_to_add;
                            $result = modifyPanelUser($username, $server_id, ['data_limit' => $new_limit]);
                            if ($result && !isset($result['detail'])) {
                                $success_count++;
                            }
                            else {
                                $fail_count++;
                            }
                        }
                    }
                    else {
                        $fail_count++;
                    }
                    usleep(100000);
                }
                sendMessage($chat_id, "✅ عملیات با موفقیت انجام شد.\nحجم <b>{$data_to_add_gb} گیگابایت</b> به <b>{$success_count}</b> سرویس اضافه گردید.\nتعداد ناموفق: {$fail_count}");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_bulk_time_amount':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفا تعداد روز معتبر (عدد مثبت) را وارد کنید.", $cancelKeyboard);
                    break;
                }
                sendMessage($chat_id, "⏳ عملیات افزودن زمان به تمام سرویس‌ها شروع شد. این فرآیند ممکن است کمی طول بکشد...");
                $days_to_add = (int)$text;
                $seconds_to_add = $days_to_add * 86400;
                $all_services = pdo()
                    ->query("SELECT marzban_username, server_id FROM services")
                    ->fetchAll(PDO::FETCH_ASSOC);
                $success_count = 0;
                $fail_count = 0;
                foreach ($all_services as $service) {
                    $username = $service['marzban_username'];
                    $server_id = $service['server_id'];
                    if (!$server_id) {
                        $fail_count++;
                        continue;
                    }

                    $current_user_data = getPanelUser($username, $server_id);
                    if ($current_user_data && !isset($current_user_data['detail'])) {
                        $current_expire = $current_user_data['expire'] ?? 0;
                        if ($current_expire > 0) {
                            $new_expire = $current_expire < time() ? time() + $seconds_to_add : $current_expire + $seconds_to_add;
                            $result = modifyPanelUser($username, $server_id, ['expire' => $new_expire]);
                            if ($result && !isset($result['detail'])) {
                                $success_count++;
                            }
                            else {
                                $fail_count++;
                            }
                        }
                    }
                    else {
                        $fail_count++;
                    }
                    usleep(100000);
                }
                sendMessage($chat_id, "✅ عملیات با موفقیت انجام شد.\nمدت <b>{$days_to_add} روز</b> به <b>{$success_count}</b> سرویس اضافه گردید.\nتعداد ناموفق: {$fail_count}");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_new_admin_id':
                if ($chat_id != ADMIN_CHAT_ID) {
                    break;
                }
                if (!is_numeric($text)) {
                    sendMessage($chat_id, "❌ شناسه وارد شده نامعتبر است. لطفا فقط عدد وارد کنید.", $cancelKeyboard);
                    break;
                }
                $target_id = (int)$text;
                if ($target_id == ADMIN_CHAT_ID) {
                    sendMessage($chat_id, "❌ شما نمی‌توانید خودتان را به عنوان ادمین اضافه کنید.", $cancelKeyboard);
                    break;
                }
                $admins = getAdmins();
                if (isset($admins[$target_id])) {
                    sendMessage($chat_id, "❌ این کاربر در حال حاضر ادمین است.", $cancelKeyboard);
                    break;
                }
                $stmt_check_user = pdo()->prepare("SELECT COUNT(*) FROM users WHERE chat_id = ?");
                $stmt_check_user->execute([$target_id]);
                if ($stmt_check_user->fetchColumn() == 0) {
                    sendMessage($chat_id, "❌ کاربری با این شناسه یافت نشد. این کاربر باید حداقل یک بار ربات را استارت کرده باشد.", $cancelKeyboard);
                    break;
                }
                $response = apiRequest('getChat', ['chat_id' => $target_id]);
                $chat_info = json_decode($response, true);
                $target_first_name = "کاربر {$target_id}";
                if ($chat_info['ok'] && isset($chat_info['result']['first_name'])) {
                    $target_first_name = $chat_info['result']['first_name'];
                }
                else {
                    sendMessage($chat_id, "⚠️ نتوانستم نام کاربر را از تلگرام دریافت کنم. با نام پیش‌فرض ثبت شد.");
                }
                addAdmin($target_id, $target_first_name);
                sendMessage($chat_id, "✅ کاربر <code>$target_id</code> (" . htmlspecialchars($target_first_name) . ") با موفقیت به لیست ادمین‌ها اضافه شد. حالا دسترسی‌های او را مشخص کنید.");
                sendMessage($target_id, "🎉 تبریک! شما توسط ادمین اصلی به عنوان ادمین ربات انتخاب شدید.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                showAdminManagementMenu($chat_id);
                break;

            case 'admin_awaiting_discount_code':
                updateUserData($chat_id, 'admin_awaiting_discount_type', ['new_discount_code' => $text]);
                $keyboard = ['inline_keyboard' => [[['text' => 'درصدی ٪', 'callback_data' => 'discount_type_percent']], [['text' => 'مبلغ ثابت (تومان)', 'callback_data' => 'discount_type_amount']]]];
                sendMessage($chat_id, "2/4 - نوع تخفیف را مشخص کنید:", $keyboard);
                break;

            case 'admin_awaiting_discount_value':
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفاً فقط یک عدد مثبت وارد کنید.");
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_discount_value'] = (int)$text;
                updateUserData($chat_id, 'admin_awaiting_discount_usage', $state_data);
                sendMessage($chat_id, "4/4 - حداکثر تعداد استفاده از این کد را وارد کنید (فقط عدد):", $cancelKeyboard);
                break;

            case 'admin_awaiting_discount_usage':
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفاً فقط یک عدد مثبت وارد کنید.");
                    break;
                }
                $discount_data = $user_data['state_data'];
                $stmt = pdo()->prepare("INSERT INTO discount_codes (code, type, value, max_usage) VALUES (?, ?, ?, ?)");
                $stmt->execute([$discount_data['new_discount_code'], $discount_data['new_discount_type'], $discount_data['new_discount_value'], (int)$text]);
                sendMessage($chat_id, "✅ کد تخفیف `{$discount_data['new_discount_code']}` با موفقیت ایجاد شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $current_first_name = $update['message']['from']['first_name'];
                handleMainMenu($chat_id, $current_first_name);
                break;

                case 'user_awaiting_discount_code':
                $code = strtoupper(trim($text));
                $category_id = $user_data['state_data']['target_category_id'];
                $server_id = $user_data['state_data']['target_server_id'];
                
                $stmt = pdo()->prepare("SELECT * FROM discount_codes WHERE code = ? AND status = 'active' AND usage_count < max_usage");
                $stmt->execute([$code]);
                $discount = $stmt->fetch();
                if (!$discount) {
                    sendMessage($chat_id, "❌ کد تخفیف وارد شده نامعتبر یا منقضی شده است.");
         
                    showPlansForCategoryAndServer($chat_id, $category_id, $server_id); 
                    updateUserData($chat_id, 'main_menu');
                    break;
                }

              
                $plan_stmt = pdo()->prepare("SELECT * FROM plans WHERE category_id = ? AND server_id = ? AND status = 'active' AND is_test_plan = 0");
                $plan_stmt->execute([$category_id, $server_id]);
                $active_plans = $plan_stmt->fetchAll(PDO::FETCH_ASSOC);

                $user_balance = $user_data['balance'] ?? 0;
                $message = "✅ کد تخفیف `{$code}` با موفقیت اعمال شد!\n\n";
                $message .= "🛍️ <b>پلن‌ها با قیمت جدید:</b>\nموجودی شما: " . number_format($user_balance) . " تومان\n\n";
                $keyboard_buttons = [];
                foreach ($active_plans as $plan) {
                    $original_price = $plan['price'];
                    $discounted_price = 0;
                    if ($discount['type'] == 'percent') {
                        $discounted_price = $original_price - ($original_price * $discount['value']) / 100;
                    }
                    else {
                        $discounted_price = $original_price - $discount['value'];
                    }
                    $discounted_price = max(0, $discounted_price);
                    $button_text = "{$plan['name']} | " . number_format($original_price) . " ⬅️ " . number_format($discounted_price) . " تومان";
                    $keyboard_buttons[] = [['text' => $button_text, 'callback_data' => "buy_plan_{$plan['id']}_with_code_{$code}"]];
                }
             
                $keyboard_buttons[] = [['text' => '◀️ بازگشت', 'callback_data' => "show_plans_cat_{$category_id}_srv_{$server_id}"]];
                sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
                updateUserData($chat_id, 'main_menu');
                break;

            case 'admin_awaiting_bulk_balance_amount':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفا یک مبلغ معتبر (عدد مثبت) به تومان وارد کنید.", $cancelKeyboard);
                    break;
                }
                $amount_to_add = (int)$text;
                sendMessage($chat_id, "⏳ عملیات افزایش موجودی همگانی شروع شد...");
                $updated_users_count = increaseAllUsersBalance($amount_to_add);
                sendMessage($chat_id, "✅ عملیات با موفقیت انجام شد.\nمبلغ <b>" . number_format($amount_to_add) . " تومان</b> به موجودی <b>{$updated_users_count}</b> کاربر فعال اضافه گردید.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_guide_button_name':
                if (!hasPermission($chat_id, 'manage_guides')) {
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_guide_content', ['new_guide_button_name' => $text]);
                sendMessage($chat_id, "2/3 - عالی! حالا محتوای راهنما را ارسال کنید.\n\nمی‌توانید یک <b>متن خالی</b> یا یک <b>عکس همراه با کپشن</b> ارسال کنید.", $cancelKeyboard);
                break;

            case 'admin_awaiting_guide_content':
                if (!hasPermission($chat_id, 'manage_guides')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                if (isset($update['message']['photo'])) {
                    $state_data['new_guide_content_type'] = 'photo';
                    $state_data['new_guide_photo_id'] = $update['message']['photo'][count($update['message']['photo']) - 1]['file_id'];
                    $state_data['new_guide_message_text'] = $update['message']['caption'] ?? '';
                }
                else {
                    $state_data['new_guide_content_type'] = 'text';
                    $state_data['new_guide_photo_id'] = null;
                    $state_data['new_guide_message_text'] = $text;
                }
                updateUserData($chat_id, 'admin_awaiting_guide_inline_buttons', $state_data);
                $msg =
                    "3/3 - محتوا ذخیره شد. در صورت تمایل، دکمه‌های شیشه‌ای (لینک) را برای نمایش زیر پیام وارد کنید.\n\n<b>فرمت ارسال:</b>\nهر دکمه در یک خط جداگانه به شکل زیر:\n<code>متن دکمه - https://example.com</code>\n\nمثال:\n<code>کانال تلگرام - https://t.me/channel\nسایت ما - https://google.com</code>\n\nاگر نمی‌خواهید دکمه‌ای داشته باشید، کلمه `رد شدن` را ارسال کنید.";
                $keyboard = ['keyboard' => [[['text' => 'رد شدن']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, $msg, $keyboard);
                break;

            case 'admin_awaiting_test_limit':
                if (!hasPermission($chat_id, 'manage_test_config')) {
                    break;
                }
                if (!is_numeric($text) || $text < 1) {
                    sendMessage($chat_id, "❌ لطفا یک عدد صحیح و مثبت (حداقل ۱) وارد کنید.", $cancelKeyboard);
                    break;
                }
                $settings = getSettings();
                $settings['test_config_usage_limit'] = (int)$text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ تعداد مجاز برای هر کاربر روی <b>{$text}</b> بار تنظیم شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_guide_inline_buttons':
                if (!hasPermission($chat_id, 'manage_guides')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $inline_keyboard = null;

                if ($text !== 'رد شدن') {
                    $lines = explode("\n", $text);
                    $buttons = [];
                    foreach ($lines as $line) {
                        $parts = explode(' - ', trim($line), 2);
                        if (count($parts) === 2 && filter_var(trim($parts[1]), FILTER_VALIDATE_URL)) {
                            $buttons[] = [['text' => trim($parts[0]), 'url' => trim($parts[1])]];
                        }
                    }
                    if (!empty($buttons)) {
                        $inline_keyboard = json_encode(['inline_keyboard' => $buttons]);
                    }
                }

                $stmt = pdo()->prepare("INSERT INTO guides (button_name, content_type, message_text, photo_id, inline_keyboard) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$state_data['new_guide_button_name'], $state_data['new_guide_content_type'], $state_data['new_guide_message_text'], $state_data['new_guide_photo_id'], $inline_keyboard]);

                sendMessage($chat_id, "✅ راهنمای جدید با موفقیت ایجاد شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_expire_days':
                if (!hasPermission($chat_id, 'manage_notifications')) {
                    break;
                }
                if (!is_numeric($text) || $text < 1) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد صحیح و مثبت وارد کنید.");
                    break;
                }
                $settings = getSettings();
                $settings['notification_expire_days'] = (int)$text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ با موفقیت روی <b>{$text}</b> روز تنظیم شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $data = 'config_expire_warning';
                break;

            case 'admin_awaiting_expire_gb':
                if (!hasPermission($chat_id, 'manage_notifications')) {
                    break;
                }
                if (!is_numeric($text) || $text < 1) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد صحیح و مثبت وارد کنید.");
                    break;
                }
                $settings = getSettings();
                $settings['notification_expire_gb'] = (int)$text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ با موفقیت روی <b>{$text}</b> گیگابایت تنظیم شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $data = 'config_expire_warning';
                break;

            case 'admin_awaiting_expire_message':
                if (!hasPermission($chat_id, 'manage_notifications')) {
                    break;
                }
                $settings = getSettings();
                $settings['notification_expire_message'] = $text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ متن پیام هشدار انقضا با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $data = 'config_expire_warning';
                break;

            case 'admin_awaiting_inactive_days':
                if (!hasPermission($chat_id, 'manage_notifications')) {
                    break;
                }
                if (!is_numeric($text) || $text < 1) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد صحیح و مثبت وارد کنید.");
                    break;
                }
                $settings = getSettings();
                $settings['notification_inactive_days'] = (int)$text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ با موفقیت روی <b>{$text}</b> روز تنظیم شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $data = 'config_inactive_reminder';
                break;

            case 'admin_awaiting_inactive_message':
                if (!hasPermission($chat_id, 'manage_notifications')) {
                    break;
                }
                $settings = getSettings();
                $settings['notification_inactive_message'] = $text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ متن پیام یادآور با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $data = 'config_inactive_reminder';
                break;
                
            case 'user_awaiting_renewal_days':
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط یک عدد صحیح (مثبت یا صفر) وارد کنید.");
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['renewal_days'] = (int)$text;
                updateUserData($chat_id, 'user_awaiting_renewal_gb', $state_data);

                $settings = getSettings();
                $price_gb = number_format($settings['renewal_price_per_gb'] ?? 2000);
                $message = "<b>تمدید سرویس</b>\n\n" .
                           "۲. چند **گیگابایت** به حجم سرویس شما اضافه شود؟\n\n" .
                           "▫️ هزینه هر گیگ: {$price_gb} تومان\n" .
                           "💡 برای رد شدن و عدم تمدید حجم، عدد `0` را وارد کنید.";
                sendMessage($chat_id, $message);
                break;

            case 'user_awaiting_renewal_gb':
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط یک عدد صحیح (مثبت یا صفر) وارد کنید.");
                    break;
                }
                $state_data = $user_data['state_data'];
                $days_to_add = $state_data['renewal_days'];
                $gb_to_add = (int)$text;
                
                if ($days_to_add == 0 && $gb_to_add == 0) {
                    sendMessage($chat_id, "شما هیچ مقداری برای تمدید وارد نکردید. عملیات لغو شد.");
                    updateUserData($chat_id, 'main_menu');
                    handleMainMenu($chat_id, $first_name);
                    break;
                }
                
                $settings = getSettings();
                $cost_days = $days_to_add * (int)($settings['renewal_price_per_day'] ?? 1000);
                $cost_gb = $gb_to_add * (int)($settings['renewal_price_per_gb'] ?? 2000);
                $total_cost = $cost_days + $cost_gb;

                $state_data['renewal_gb'] = $gb_to_add;
                $state_data['renewal_total_cost'] = $total_cost;
                updateUserData($chat_id, 'user_confirming_renewal', $state_data);

                $summary = "<b>خلاصه درخواست تمدید شما:</b>\n\n" .
                           "▫️ افزایش زمان: <b>{$days_to_add} روز</b>\n" .
                           "▫️ افزایش حجم: <b>{$gb_to_add} گیگابایت</b>\n\n" .
                           "💰 هزینه کل: <b>" . number_format($total_cost) . " تومان</b>\n\n" .
                           "موجودی فعلی شما: " . number_format($user_data['balance']) . " تومان\n\n" .
                           "آیا تایید می‌کنید؟";

                $keyboard = ['inline_keyboard' => [[['text' => '✅ بله، پرداخت کن', 'callback_data' => 'confirm_renewal_payment']]]];
                sendMessage($chat_id, $summary, $keyboard);
                break;
            
            case 'awaiting_renewal_screenshot':
                if (isset($update['message']['photo'])) {
                    $state_data = $user_data['state_data'];
                    $photo_id = $update['message']['photo'][count($update['message']['photo']) - 1]['file_id'];
                    
                    $stmt = pdo()->prepare("UPDATE renewal_requests SET photo_file_id = ? WHERE id = ?");
                    $stmt->execute([$photo_id, $state_data['renewal_request_id']]);
                    

                    $request_id = $state_data['renewal_request_id'];
                    $caption = "<b>درخواست تمدید سرویس جدید</b>\n\n" .
                               "👤 کاربر: " . htmlspecialchars($first_name) . " (<code>{$chat_id}</code>)\n" .
                               "▫️ سرویس: <code>{$state_data['renewal_username']}</code>\n" .
                               "⏰ تمدید زمان: {$state_data['renewal_days']} روز\n" .
                               "📊 تمدید حجم: {$state_data['renewal_gb']} گیگ\n" .
                               "💰 هزینه: " . number_format($state_data['renewal_total_cost']) . " تومان\n" .
                               "▫️ شماره درخواست: #R-{$request_id}";
                    
                    $keyboard = ['inline_keyboard' => [[
                        ['text' => '✅ تایید تمدید', 'callback_data' => "approve_renewal_{$request_id}"],
                        ['text' => '❌ رد تمدید', 'callback_data' => "reject_renewal_{$request_id}"]
                    ]]];

                    $all_admins = getAdmins();
                    $all_admins[ADMIN_CHAT_ID] = [];
                    foreach (array_keys($all_admins) as $admin_id) {
                        if (hasPermission($admin_id, 'manage_payment')) {
                           sendPhoto($admin_id, $photo_id, $caption, $keyboard);
                        }
                    }

                    sendMessage($chat_id, "✅ رسید شما برای ادمین ارسال شد. پس از بررسی، سرویس شما تمدید خواهد شد.");
                    updateUserData($chat_id, 'main_menu');
                    handleMainMenu($chat_id, $first_name);
                }
                break;
        }
        die;
    }

    switch ($text) {
        case '🛒 خرید سرویس':
            if ($settings['sales_status'] === 'off') {
                sendMessage($chat_id, "🛍 بخش فروش موقتا توسط مدیر غیرفعال شده است.");
                break;
            }
            $categories = getCategories(true);
            if (empty($categories)) {
                sendMessage($chat_id, "متاسفانه در حال حاضر هیچ سرویسی برای فروش موجود نیست.");
            }
            else {
                $keyboard_buttons = [];
                foreach ($categories as $category) {
                    $keyboard_buttons[] = [['text' => '🛍 ' . $category['name'], 'callback_data' => 'cat_' . $category['id']]];
                }
                sendMessage($chat_id, "لطفا یکی از دسته‌بندی‌های زیر را انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
            }
            break;

        case '👑 ورود به پنل مدیریت':
            if ($isAnAdmin) {
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name, true);
            }
            break;

        case '↩️ بازگشت به منوی کاربری':
            if ($isAnAdmin) {
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'user']);
                handleMainMenu($chat_id, $first_name);
            }
            break;

        case '🗂 مدیریت دسته‌بندی‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_categories')) {
                $keyboard = ['keyboard' => [[['text' => '➕ افزودن دسته‌بندی']], [['text' => '📋 لیست دسته‌بندی‌ها']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "گزینه مورد نظر را برای مدیریت دسته‌بندی‌ها انتخاب کنید:", $keyboard);
            }
            break;

        case '➕ افزودن دسته‌بندی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_categories')) {
                updateUserData($chat_id, 'admin_awaiting_category_name', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا نام دسته‌بندی جدید را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '📋 لیست دسته‌بندی‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_categories')) {
                generateCategoryList($chat_id);
            }
            break;

        case '📝 مدیریت پلن‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_plans')) {
                $keyboard = ['keyboard' => [[['text' => '➕ افزودن پلن']], [['text' => '📋 لیست پلن‌ها']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "گزینه مورد نظر را برای مدیریت پلن‌ها انتخاب کنید:", $keyboard);
            }
            break;

        case '➕ افزودن پلن':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_plans')) {
                $categories = getCategories();
                if (empty($categories)) {
                    sendMessage($chat_id, "❌ ابتدا باید حداقل یک دسته‌بندی ایجاد کنید!");
                    break;
                }
                $keyboard_buttons = [];
                foreach ($categories as $category) {
                    $keyboard_buttons[] = [['text' => $category['name'], 'callback_data' => 'p_cat_' . $category['id']]];
                }
                sendMessage($chat_id, "این پلن را به کدام دسته‌بندی می‌خواهید اضافه کنید؟", ['inline_keyboard' => $keyboard_buttons]);
            }
            break;

        case '📋 لیست پلن‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_plans')) {
                generatePlanList($chat_id);
            }
            break;

        case '📋 لیست کدهای تخفیف':
            if ($isAnAdmin) {
                generateDiscountCodeList($chat_id);
            }
            break;

        case '👥 مدیریت کاربران':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                $keyboard = [
                    'keyboard' => [
                        [['text' => '🔎 جستجوی کاربر (مدیریت فردی)']],
                        [['text' => '➕ افزودن حجم همگانی'], ['text' => '➕ افزودن زمان همگانی']],
                        [['text' => '💰 افزایش موجودی همگانی']],
                        [['text' => '◀️ بازگشت به منوی اصلی']],
                    ],
                    'resize_keyboard' => true,
                ];
                sendMessage($chat_id, "لطفاً نوع عملیات مدیریت کاربران را انتخاب کنید:", $keyboard);
            }
            break;

        case '➕ افزایش موجودی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_id_for_add_balance', ['admin_view' => 'admin']);
                sendMessage($chat_id, "شناسه عددی کاربری که می‌خواهید موجودی‌اش را افزایش دهید، وارد کنید:", $cancelKeyboard);
            }
            break;

        case '➖ کاهش موجودی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_id_for_deduct_balance', ['admin_view' => 'admin']);
                sendMessage($chat_id, "شناسه عددی کاربری که می‌خواهید از موجودی‌اش کسر کنید، وارد کنید:", $cancelKeyboard);
            }
            break;

        case '💰 افزایش موجودی همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_balance_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا مبلغی که می‌خواهید به موجودی تمام کاربران فعال اضافه شود را به تومان وارد کنید:", $cancelKeyboard);
            }
            break;

        case '➕ افزودن حجم همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_data_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا مقدار حجمی که می‌خواهید به تمام سرویس‌ها اضافه شود را به گیگابایت (GB) وارد کنید:", $cancelKeyboard);
            }
            break;

        case '➕ افزودن زمان همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_time_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا تعداد روزی که می‌خواهید به تمام سرویس‌ها اضافه شود را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '✉️ ارسال پیام به کاربر':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_id_for_message', ['admin_view' => 'admin']);
                sendMessage($chat_id, "شناسه عددی کاربری که می‌خواهید به او پیام دهید را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '🚫 مسدود کردن کاربر':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_id_for_ban', ['admin_view' => 'admin']);
                sendMessage($chat_id, "شناسه عددی کاربری که می‌خواهید مسدود کنید را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '✅ آزاد کردن کاربر':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_id_for_unban', ['admin_view' => 'admin']);
                sendMessage($chat_id, "شناسه عددی کاربری که می‌خواهید از مسدودیت خارج کنید را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '🔎 جستجوی کاربر (مدیریت فردی)':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_search', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفاً شناسه عددی (Chat ID) کاربر مورد نظر را برای جستجو وارد کنید:", $cancelKeyboard);
            }
            break;
            
        case '💰 افزایش موجودی همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_balance_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا مبلغی که می‌خواهید به موجودی تمام کاربران فعال اضافه شود را به تومان وارد کنید:", $cancelKeyboard);
            }
            break;

        case '➕ افزودن حجم همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_data_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا مقدار حجمی که می‌خواهید به تمام سرویس‌ها اضافه شود را به گیگابایت (GB) وارد کنید:", $cancelKeyboard);
            }
            break;

        case '➕ افزودن زمان همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_time_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا تعداد روزی که می‌خواهید به تمام سرویس‌ها اضافه شود را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '📣 ارسال همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'broadcast')) {
                $keyboard = ['keyboard' => [[['text' => '✍️ ارسال پیام همگانی'], ['text' => '▶️ فروارد همگانی']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "نوع ارسال همگانی را انتخاب کنید:", $keyboard);
            }
            break;

        case '✍️ ارسال پیام همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'broadcast')) {
                updateUserData($chat_id, 'admin_awaiting_broadcast_message', ['admin_view' => 'admin']);
                sendMessage($chat_id, "پیامی که می‌خواهید به تمام کاربران ارسال شود را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '▶️ فروارد همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'broadcast')) {
                updateUserData($chat_id, 'admin_awaiting_forward_message', ['admin_view' => 'admin']);
                sendMessage($chat_id, "پیامی که می‌خواهید به تمام کاربران فروارد شود را به همینجا فروارد کنید:", $cancelKeyboard);
            }
            break;

        case '⚙️ تنظیمات کلی ربات':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                $bot_status_text = $settings['bot_status'] == 'on' ? '🔴 خاموش کردن ربات' : '🟢 روشن کردن ربات';
                $inline_keyboard_text = $settings['inline_keyboard'] == 'on' ? '🔴 غیرفعال کردن کیبورد شیشه ای' : '🟢 فعال کردن کیبورد شیشه ای';
                $sales_status_text = $settings['sales_status'] == 'on' ? '🔴 خاموش کردن فروش' : '🟢 روشن کردن فروش';
                $join_status_text = $settings['join_channel_status'] == 'on' ? '🔴 غیرفعال کردن جوین' : '🟢 فعال کردن جوین';
                $message = "<b>⚙️ تنظیمات کلی ربات:</b>";
                $keyboard = [
                    'keyboard' => [
                        [['text' => $bot_status_text]],
                        [['text' => $inline_keyboard_text]],
                        [['text' => $sales_status_text]],
                        [['text' => $join_status_text], ['text' => '📢 تنظیم کانال جوین']],
                        [['text' => '🎁 تنظیم هدیه عضویت']],
                        [['text' => '🏷️ مدیریت نام کانفیگ']],
                        [['text' => '📋 مدیریت لاگ‌ها']],
                        [['text' => '🛡️ ضد اسپم']],
                        [['text' => '🔗 تنظیم مجدد Webhook']],
                        [['text' => '◀️ بازگشت به منوی اصلی']],
                    ],
                    'resize_keyboard' => true,
                ];
                sendMessage($chat_id, $message, $keyboard);
            }
            break;
        

        case '🏷️ مدیریت نام کانفیگ':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                if (class_exists('ConfigNaming')) {
                    $configNaming = ConfigNaming::getInstance();
                    $namingSettings = $configNaming->getConfigNamingSettings();
                    
                    $prefix = $namingSettings['prefix'] ?: '<i>تنظیم نشده</i>';
                    $startNumber = $namingSettings['start_number'];
                    $lastNumber = $namingSettings['last_number'];
                    
                    $message = "<b>🏷️ مدیریت نام کانفیگ</b>\n\n";
                    $message .= "▫️ پیشوند (Prefix): <code>{$prefix}</code>\n";
                    $message .= "▫️ شماره شروع: <b>{$startNumber}</b>\n";
                    $message .= "▫️ آخرین شماره استفاده شده: <b>{$lastNumber}</b>\n\n";
                    $message .= "برای تنظیم نام کانفیگ، گزینه مورد نظر را انتخاب کنید:";
                    
                    $keyboard = [
                        'inline_keyboard' => [
                            [['text' => '✏️ تنظیم پیشوند و شماره شروع', 'callback_data' => 'set_config_naming']],
                            [['text' => '🔄 ریست شمارنده', 'callback_data' => 'reset_config_counter']],
                            [['text' => '◀️ بازگشت به تنظیمات', 'callback_data' => 'back_to_admin_panel']]
                        ]
                    ];
                    
                    sendMessage($chat_id, $message, $keyboard);
                } else {
                    sendMessage($chat_id, "❌ سیستم نام‌گذاری کانفیگ در دسترس نیست.");
                }
            }
            break;

        case '🎁 تنظیم هدیه عضویت':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                updateUserData($chat_id, 'admin_awaiting_welcome_gift_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا مبلغ هدیه برای کاربران جدید را به تومان وارد کنید (برای غیرفعال کردن عدد 0 را وارد کنید):", $cancelKeyboard);
            }
            break;

        case '🔴 غیرفعال کردن کیبورد شیشه ای':
        case '🟢 فعال کردن کیبورد شیشه ای':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                $settings['inline_keyboard'] = $settings['inline_keyboard'] == 'on' ? 'off' : 'on';
                saveSettings($settings);
                sendMessage($chat_id, "✅ وضعیت کیبورد ربات با موفقیت تغییر کرد.\nمجدد /start کنید.");
            }
            break;

        case '🔴 خاموش کردن ربات':
        case '🟢 روشن کردن ربات':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                $settings['bot_status'] = $settings['bot_status'] == 'on' ? 'off' : 'on';
                saveSettings($settings);
                sendMessage($chat_id, "✅ وضعیت کلی ربات با موفقیت تغییر کرد.");
                handleMainMenu($chat_id, $first_name);
            }
            break;

        case '🔴 خاموش کردن فروش':
        case '🟢 روشن کردن فروش':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                $settings['sales_status'] = $settings['sales_status'] == 'on' ? 'off' : 'on';
                saveSettings($settings);
                sendMessage($chat_id, "✅ وضعیت فروش با موفقیت تغییر کرد.");
                handleMainMenu($chat_id, $first_name);
            }
            break;

        case '🔴 غیرفعال کردن جوین':
        case '🟢 فعال کردن جوین':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                $settings['join_channel_status'] = $settings['join_channel_status'] == 'on' ? 'off' : 'on';
                saveSettings($settings);
                sendMessage($chat_id, "✅ وضعیت عضویت اجباری با موفقیت تغییر کرد.");
                handleMainMenu($chat_id, $first_name);
            }
            break;

        case '📢 تنظیم کانال جوین':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                updateUserData($chat_id, 'admin_awaiting_join_channel_id', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا شناسه کانال مورد نظر را به همراه @ وارد کنید (مثال: @YourChannel)\n\n<b>توجه:</b> ربات باید در کانال ادمین باشد.", $cancelKeyboard);
            }
            break;

        case '🌐 مدیریت سرورها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_marzban')) {
                $servers = pdo()
                    ->query("SELECT id, name FROM servers")
                    ->fetchAll(PDO::FETCH_ASSOC);
                $keyboard_buttons = [[['text' => '➕ افزودن سرور جدید', 'callback_data' => 'add_server_select_type']]];
                foreach ($servers as $server) {
                    $keyboard_buttons[] = [['text' => "🖥 {$server['name']}", 'callback_data' => "view_server_{$server['id']}"]];
                }
                sendMessage($chat_id, "<b>🌐 مدیریت سرورها</b>\n\nسرور مورد نظر را برای مشاهده یا حذف انتخاب کنید، یا یک سرور جدید اضافه کنید:", ['inline_keyboard' => $keyboard_buttons]);
            }
            break;

        case '💳 مدیریت پرداخت':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_payment')) {
                updateUserData($chat_id, 'admin_awaiting_card_number', ['admin_view' => 'admin']);
                sendMessage($chat_id, "مرحله ۱/۳: شماره کارت ۱۶ رقمی را وارد کنید:", $cancelKeyboard);
            }
            break;
        
        case '💳 مدیریت درگاه پرداخت':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_payment')) {
                $settings = getSettings();
                
                $message = "<b>💳 مدیریت درگاه‌های پرداخت</b>\n\n";
                $message .= "درگاه‌های پرداخت موجود:\n\n";
                
                // زرین‌پال
                $zarinpal_enabled = ($settings['payment_gateway_status'] ?? 'off') == 'on' && !empty($settings['zarinpal_merchant_id']);
                $zarinpal_icon = $zarinpal_enabled ? '✅' : '❌';
                $zarinpal_merchant = $settings['zarinpal_merchant_id'] ?? 'تنظیم نشده';
                $message .= "{$zarinpal_icon} <b>زرین‌پال</b>\n";
                $message .= "   مرچنت کد: <code>{$zarinpal_merchant}</code>\n\n";
                
                // IDPay
                $idpay_enabled = ($settings['idpay_enabled'] ?? 'off') == 'on' && !empty($settings['idpay_api_key']);
                $idpay_icon = $idpay_enabled ? '✅' : '❌';
                $idpay_api = !empty($settings['idpay_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
                $message .= "{$idpay_icon} <b>IDPay</b>\n";
                $message .= "   API Key: <code>{$idpay_api}</code>\n\n";
                
                // NextPay
                $nextpay_enabled = ($settings['nextpay_enabled'] ?? 'off') == 'on' && !empty($settings['nextpay_api_key']);
                $nextpay_icon = $nextpay_enabled ? '✅' : '❌';
                $nextpay_api = !empty($settings['nextpay_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
                $message .= "{$nextpay_icon} <b>NextPay</b>\n";
                $message .= "   API Key: <code>{$nextpay_api}</code>\n\n";
                
                // زیبال
                $zibal_enabled = ($settings['zibal_enabled'] ?? 'off') == 'on' && !empty($settings['zibal_merchant_id']);
                $zibal_icon = $zibal_enabled ? '✅' : '❌';
                $zibal_merchant = !empty($settings['zibal_merchant_id']) ? 'تنظیم شده' : 'تنظیم نشده';
                $message .= "{$zibal_icon} <b>زیبال</b>\n";
                $message .= "   مرچنت کد: <code>{$zibal_merchant}</code>\n\n";
                
                // newPayment
                $newpayment_enabled = ($settings['newpayment_enabled'] ?? 'off') == 'on' && !empty($settings['newpayment_api_key']);
                $newpayment_icon = $newpayment_enabled ? '✅' : '❌';
                $newpayment_api = !empty($settings['newpayment_api_key']) ? 'تنظیم شده' : 'تنظیم نشده';
                $message .= "{$newpayment_icon} <b>newPayment</b>\n";
                $message .= "   API Key: <code>{$newpayment_api}</code>\n\n";
                
                $message .= "برای تنظیم هر درگاه، گزینه مورد نظر را انتخاب کنید:";

                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '💎 تنظیم زرین‌پال', 'callback_data' => 'setup_gateway_zarinpal']],
                        [['text' => '🔷 تنظیم IDPay', 'callback_data' => 'setup_gateway_idpay']],
                        [['text' => '🔶 تنظیم NextPay', 'callback_data' => 'setup_gateway_nextpay']],
                        [['text' => '💛 تنظیم زیبال', 'callback_data' => 'setup_gateway_zibal']],
                        [['text' => '🆕 تنظیم newPayment', 'callback_data' => 'setup_gateway_newpayment']],
                        [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']],
                    ]
                ];
                sendMessage($chat_id, $message, $keyboard);
            }
            break;

        case '🔗 تنظیم مجدد Webhook':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                if (!defined('BOT_TOKEN') || BOT_TOKEN === 'TOKEN') {
                    sendMessage($chat_id, "❌ خطا: BOT_TOKEN در config.php تنظیم نشده است.");
                    break;
                }
                if (!defined('SECRET_TOKEN') || SECRET_TOKEN === 'SECRET') {
                    sendMessage($chat_id, "❌ خطا: SECRET_TOKEN در config.php تنظیم نشده است.");
                    break;
                }
                
                $webhook_url = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/bot.php';
                
                // استفاده از تابع setTelegramWebhook از install.php (یا ایجاد یک تابع مشابه)
                $set_webhook_url = "https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook";
                $webhook_data = [
                    'url' => $webhook_url,
                    'secret_token' => SECRET_TOKEN,
                    'drop_pending_updates' => true
                ];
                
                $ch = curl_init($set_webhook_url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhook_data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);
                
                if ($curl_error) {
                    sendMessage($chat_id, "❌ خطا در اتصال به Telegram API: " . $curl_error);
                } else {
                    $response_data = json_decode($response, true);
                    
                    if ($http_code === 200 && isset($response_data['ok']) && $response_data['ok']) {
                        // بررسی نهایی
                        $get_webhook_url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getWebhookInfo";
                        $ch = curl_init($get_webhook_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                        $webhook_info = curl_exec($ch);
                        curl_close($ch);
                        
                        $webhook_check = json_decode($webhook_info, true);
                        $verified = false;
                        if ($webhook_check['ok'] && isset($webhook_check['result']['url'])) {
                            $webhook_url_set = $webhook_check['result']['url'];
                            $verified = ($webhook_url_set === $webhook_url);
                        }
                        
                        $message = "✅ Webhook با secret_token با موفقیت تنظیم شد!\n\n";
                        $message .= "🔗 URL: <code>{$webhook_url}</code>\n";
                        $message .= "🔐 Secret Token: <code>" . substr(SECRET_TOKEN, 0, 10) . "...</code>\n";
                        if ($verified) {
                            $message .= "\n✅ تأیید: Webhook به درستی تنظیم شده است.";
                        }
                        sendMessage($chat_id, $message);
                    } else {
                        $error_desc = $response_data['description'] ?? 'پاسخ نامعتبر از تلگرام';
                        sendMessage($chat_id, "❌ خطا در تنظیم Webhook: {$error_desc}\n\nHTTP Code: {$http_code}");
                    }
                }
            }
            break;

        case '🛡️ ضد اسپم':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                if (file_exists(__DIR__ . '/includes/AntiSpam.php') && class_exists('AntiSpam')) {
                    require_once __DIR__ . '/includes/AntiSpam.php';
                    $antiSpam = AntiSpam::getInstance();
                    $antiSpamSettings = $antiSpam->getSettings();
                    
                    $status_icon = ($antiSpamSettings['enabled'] ?? 'off') == 'on' ? '✅' : '❌';
                    $message = "<b>🛡️ مدیریت ضد اسپم</b>\n\n";
                    $message .= "▫️ وضعیت: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
                    $message .= "▫️ حداکثر اعمال: <b>" . ($antiSpamSettings['max_actions'] ?? 10) . "</b>\n";
                    $message .= "▫️ بازه زمانی: <b>" . ($antiSpamSettings['time_window'] ?? 5) . " ثانیه</b>\n";
                    $message .= "▫️ مدت زمان میوت: <b>" . ($antiSpamSettings['mute_duration'] ?? 60) . " دقیقه</b>\n";
                    $message .= "▫️ پیام مسدودیت: <code>" . htmlspecialchars(substr($antiSpamSettings['message'] ?? '', 0, 50)) . "...</code>\n\n";
                    $message .= "برای تنظیم ضد اسپم، گزینه مورد نظر را انتخاب کنید:";
                    
                    $keyboard = [
                        'inline_keyboard' => [
                            [['text' => $status_icon . ' فعال/غیرفعال کردن', 'callback_data' => 'toggle_antispam_status']],
                            [['text' => '⚙️ تنظیم حداکثر اعمال', 'callback_data' => 'set_antispam_max_actions']],
                            [['text' => '⏱️ تنظیم بازه زمانی', 'callback_data' => 'set_antispam_time_window']],
                            [['text' => '🔇 تنظیم مدت زمان میوت', 'callback_data' => 'set_antispam_mute_duration']],
                            [['text' => '💬 تنظیم پیام مسدودیت', 'callback_data' => 'set_antispam_message']],
                            [['text' => '◀️ بازگشت به تنظیمات', 'callback_data' => 'back_to_admin_panel']]
                        ]
                    ];
                    
                    sendMessage($chat_id, $message, $keyboard);
                } else {
                    sendMessage($chat_id, "❌ سیستم ضد اسپم در دسترس نیست.");
                }
            }
            break;

        case '📋 مدیریت لاگ‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                if (class_exists('LogManager')) {
                    $logManager = LogManager::getInstance();
                    $logSettings = $logManager->getLogSettings();
                    $groupId = $logSettings['group_id'] ?? null;
                    $logTypes = $logSettings['types'] ?? [];
                    
                    $message = "<b>📋 مدیریت لاگ‌ها</b>\n\n";
                    
                    if ($groupId) {
                        $message .= "👥 گروه لاگ‌ها: <code>{$groupId}</code>\n\n";
                    } else {
                        $message .= "⚠️ گروه لاگ‌ها تنظیم نشده است.\n\n";
                    }
                    
                    $message .= "برای تنظیم گروه لاگ‌ها و فعال/غیرفعال کردن انواع لاگ‌ها، گزینه مورد نظر را انتخاب کنید:";
                    
                    $keyboard = [
                        'inline_keyboard' => [
                            [['text' => '👥 تنظیم گروه لاگ‌ها', 'callback_data' => 'set_log_group']],
                            [['text' => ($logTypes['server'] ?? false ? '✅' : '❌') . ' لاگ سرور', 'callback_data' => 'toggle_log_server']],
                            [['text' => ($logTypes['error'] ?? false ? '✅' : '❌') . ' لاگ خطاها', 'callback_data' => 'toggle_log_error']],
                            [['text' => ($logTypes['purchase'] ?? false ? '✅' : '❌') . ' لاگ خریدها', 'callback_data' => 'toggle_log_purchase']],
                            [['text' => ($logTypes['transaction'] ?? false ? '✅' : '❌') . ' لاگ تراکنش‌ها', 'callback_data' => 'toggle_log_transaction']],
                            [['text' => ($logTypes['user_new'] ?? false ? '✅' : '❌') . ' لاگ کاربران جدید', 'callback_data' => 'toggle_log_user_new']],
                            [['text' => ($logTypes['user_ban'] ?? false ? '✅' : '❌') . ' لاگ مسدود کردن کاربر', 'callback_data' => 'toggle_log_user_ban']],
                            [['text' => ($logTypes['admin_action'] ?? false ? '✅' : '❌') . ' لاگ اقدامات ادمین', 'callback_data' => 'toggle_log_admin_action']],
                            [['text' => ($logTypes['payment'] ?? false ? '✅' : '❌') . ' لاگ پرداخت‌ها', 'callback_data' => 'toggle_log_payment']],
                            [['text' => ($logTypes['config_create'] ?? false ? '✅' : '❌') . ' لاگ ایجاد کانفیگ', 'callback_data' => 'toggle_log_config_create']],
                            [['text' => ($logTypes['config_delete'] ?? false ? '✅' : '❌') . ' لاگ حذف کانفیگ', 'callback_data' => 'toggle_log_config_delete']],
                            [['text' => '◀️ بازگشت به تنظیمات', 'callback_data' => 'back_to_admin_panel']]
                        ]
                    ];
                    
                    sendMessage($chat_id, $message, $keyboard);
                } else {
                    sendMessage($chat_id, "❌ سیستم مدیریت لاگ‌ها در دسترس نیست.");
                }
            }
            break;

        case '📊 آمار کلی':
            if ($isAnAdmin && hasPermission($chat_id, 'view_stats')) {
                $total_users = pdo()
                    ->query("SELECT COUNT(*) FROM users")
                    ->fetchColumn();
                $banned_users = pdo()
                    ->query("SELECT COUNT(*) FROM users WHERE status = 'banned'")
                    ->fetchColumn();
                $active_users = $total_users - $banned_users;
                $total_services = pdo()
                    ->query("SELECT COUNT(*) FROM services")
                    ->fetchColumn();
                $total_tickets = pdo()
                    ->query("SELECT COUNT(*) FROM tickets")
                    ->fetchColumn();
                $stats_message =
                    "<b>📊 آمار کلی ربات</b>\n\n" .
                    "👥 <b>آمار کاربران:</b>\n" .
                    "▫️ کل کاربران: <b>{$total_users}</b> نفر\n" .
                    "▫️ کاربران فعال: <b>{$active_users}</b> نفر\n" .
                    "▫️ کاربران مسدود: <b>{$banned_users}</b> نفر\n\n" .
                    "🛍 <b>آمار فروش و پشتیبانی:</b>\n" .
                    "▫️ کل سرویس‌های فروخته شده: <b>{$total_services}</b> عدد\n" .
                    "▫️ کل تیکت‌های پشتیبانی: <b>{$total_tickets}</b> عدد";
                sendMessage($chat_id, $stats_message);
            }
            break;

        case '💰 آمار درآمد':
            if ($isAnAdmin && hasPermission($chat_id, 'view_stats')) {
                $income_stats = calculateIncomeStats();
                $income_message =
                    "<b>💰 آمار درآمد ربات</b>\n\n" .
                    "▫️ درآمد امروز: <b>" .
                    number_format($income_stats['today']) .
                    "</b> تومان\n" .
                    "▫️ درآمد این هفته: <b>" .
                    number_format($income_stats['week']) .
                    "</b> تومان\n" .
                    "▫️ درآمد این ماه: <b>" .
                    number_format($income_stats['month']) .
                    "</b> تومان\n" .
                    "▫️ درآمد امسال: <b>" .
                    number_format($income_stats['year']) .
                    "</b> تومان";
                sendMessage($chat_id, $income_message);
            }
            break;

        case '👨‍💼 مدیریت ادمین‌ها':
            if ($chat_id == ADMIN_CHAT_ID) {
                showAdminManagementMenu($chat_id);
            }
            break;

        case '🎁 مدیریت کد تخفیف':
            if ($isAnAdmin) {
                $keyboard = ['keyboard' => [[['text' => '➕ افزودن کد تخفیف']], [['text' => '📋 لیست کدهای تخفیف']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "🎁 بخش مدیریت کدهای تخفیف:", $keyboard);
            }
            break;
        
        case '🔄 مدیریت تمدید':
            if ($isAnAdmin) { 
                showRenewalManagementMenu($chat_id);
            }
            break;    

        case '➕ افزودن کد تخفیف':
            if ($isAnAdmin) {
                updateUserData($chat_id, 'admin_awaiting_discount_code', ['admin_view' => 'admin']);
                sendMessage($chat_id, "1/4 - لطفاً کد تخفیف را وارد کنید (مثال: EID1404):", $cancelKeyboard);
            }
            break;

        case '📚 مدیریت راهنما':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_guides')) {
                $keyboard = ['keyboard' => [[['text' => '➕ افزودن راهنمای جدید']], [['text' => '📋 لیست راهنماها']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "بخش مدیریت راهنما:", $keyboard);
            }
            break;

        case '➕ افزودن راهنمای جدید':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_guides')) {
                updateUserData($chat_id, 'admin_awaiting_guide_button_name', ['admin_view' => 'admin']);
                sendMessage($chat_id, "1/3 - لطفاً نام راهنما را وارد کنید (این نام روی دکمه شیشه‌ای به کاربر نمایش داده می‌شود):", $cancelKeyboard);
            }
            break;

        case '📋 لیست راهنماها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_guides')) {
                generateGuideList($chat_id);
            }
            break;

        case '👤 حساب کاربری':
            $balance = $user_data['balance'] ?? 0;
            $services = getUserServices($chat_id);
            $total_services = count($services);
            $active_services_count = 0;
            $expired_services_count = 0;
            $now = time();
            foreach ($services as $service) {
                if ($service['expire_timestamp'] < $now) {
                    $expired_services_count++;
                }
                else {
                    $active_services_count++;
                }
            }
            $account_info = "<b>اطلاعات حساب کاربری شما </b> 👤\n\n";
            $account_info .= "▫️ نام: " . htmlspecialchars($first_name) . "\n";
            $account_info .= "▫️ شناسه کاربری: <code>" . $chat_id . "</code>\n";
            $account_info .= "💰 موجودی حساب: <b>" . number_format($balance) . " تومان</b>\n\n";
            $account_info .= "<b>آمار سرویس‌های شما:</b>\n";
            $account_info .= "▫️ کل سرویس‌های خریداری شده: <b>" . $total_services . "</b> عدد\n";
            $account_info .= "▫️ سرویس‌های فعال: <b>" . $active_services_count . "</b> عدد\n";
            $account_info .= "▫️ سرویس‌های منقضی شده: <b>" . $expired_services_count . "</b> عدد";
            sendMessage($chat_id, $account_info);
            break;

        case '💳 شارژ حساب':
            updateUserData($chat_id, 'awaiting_charge_amount');
            sendMessage($chat_id, "لطفا مبلغی که قصد دارید حساب خود را شارژ کنید به تومان وارد نمایید:", $cancelKeyboard);
            break;

        case '🔧 سرویس‌های من':
            $services = getUserServices($chat_id);
            if (empty($services)) {
                sendMessage($chat_id, "شما هیچ سرویس فعالی ندارید.");
            }
            else {
                $keyboard_buttons = [];
                $now = time();
                foreach ($services as $service) {
                    // پشتیبانی از زمان نامحدود (اگر expire_timestamp صفر باشد)
                    $expire_date = 'نامحدود';
                    if (!empty($service['expire_timestamp']) && $service['expire_timestamp'] > 0) {
                        $expire_date = date('Y-m-d', $service['expire_timestamp']);
                    }
                    
                    $status_icon = '✅';
                    if (!empty($service['expire_timestamp']) && $service['expire_timestamp'] > 0) {
                        $status_icon = $service['expire_timestamp'] < $now ? '❌' : '✅';
                    }
                    
                    $button_text = "{$status_icon} {$service['plan_name']} (انقضا: {$expire_date})";
                    $keyboard_buttons[] = [['text' => $button_text, 'callback_data' => 'service_details_' . $service['marzban_username']]];
                }
                sendMessage($chat_id, "سرویس مورد نظر خود را برای مشاهده جزئیات انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
            }
            break;

        case '📨 پشتیبانی':
            if (class_exists('TicketSystem')) {
                $ticketSystem = TicketSystem::getInstance();
                $categories = $ticketSystem->getTicketCategories();
                
                $message = "<b>📨 پشتیبانی</b>\n\n";
                $message .= "لطفا دسته‌بندی تیکت خود را انتخاب کنید:";
                
                $keyboard_buttons = [];
                foreach ($categories as $key => $name) {
                    $keyboard_buttons[] = [['text' => $name, 'callback_data' => "create_ticket_category_{$key}"]];
                }
                $keyboard_buttons[] = [['text' => '◀️ بازگشت', 'callback_data' => 'back_to_main_menu']];
                
                sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
            } else {
                updateUserData($chat_id, 'awaiting_ticket_subject');
                sendMessage($chat_id, "لطفا موضوع تیکت پشتیبانی خود را به صورت خلاصه وارد کنید:", $cancelKeyboard);
            }
            break;


        case '📜 تاریخچه خریدها':
            // دریافت تاریخچه خریدهای کاربر
            $stmt = pdo()->prepare("
                SELECT s.*, p.name as plan_name, p.price as plan_price, serv.name as server_name, cat.name as category_name
                FROM services s
                LEFT JOIN plans p ON s.plan_id = p.id
                LEFT JOIN servers serv ON s.server_id = serv.id
                LEFT JOIN categories cat ON p.category_id = cat.id
                WHERE s.owner_chat_id = ?
                ORDER BY s.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$chat_id]);
            $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($purchases)) {
                sendMessage($chat_id, "📜 <b>تاریخچه خریدها</b>\n\nشما هنوز هیچ خریدی انجام نداده‌اید.");
                break;
            }
            
            // محاسبه آمار کلی
            $totalPurchases = count($purchases);
            $totalSpent = 0;
            $activeServices = 0;
            $expiredServices = 0;
            
            foreach ($purchases as $purchase) {
                $totalSpent += (float)($purchase['plan_price'] ?? 0);
                $expireTime = $purchase['expire_timestamp'] ?? 0;
                if ($expireTime > 0 && $expireTime > time()) {
                    $activeServices++;
                } elseif ($expireTime > 0 && $expireTime <= time()) {
                    $expiredServices++;
                }
            }
            
            $message = "<b>📜 تاریخچه خریدها</b>\n\n";
            $message .= "📊 <b>آمار کلی:</b>\n";
            $message .= "▫️ تعداد کل خریدها: <b>" . number_format($totalPurchases) . "</b> عدد\n";
            $message .= "▫️ مجموع هزینه: <b>" . number_format($totalSpent) . "</b> تومان\n";
            $message .= "▫️ سرویس‌های فعال: <b>{$activeServices}</b> عدد\n";
            $message .= "▫️ سرویس‌های منقضی شده: <b>{$expiredServices}</b> عدد\n\n";
            $message .= "<b>📋 آخرین خریدها:</b>\n\n";
            
            // نمایش 10 خرید آخر
            $displayCount = min(10, count($purchases));
            for ($i = 0; $i < $displayCount; $i++) {
                $purchase = $purchases[$i];
                $planName = htmlspecialchars($purchase['plan_name'] ?? 'نامشخص');
                $serverName = htmlspecialchars($purchase['server_name'] ?? 'نامشخص');
                $categoryName = htmlspecialchars($purchase['category_name'] ?? 'نامشخص');
                $price = number_format($purchase['plan_price'] ?? 0);
                $createdAt = date('Y/m/d H:i', strtotime($purchase['created_at'] ?? 'now'));
                
                $expireTime = $purchase['expire_timestamp'] ?? 0;
                $status = 'نامشخص';
                if ($expireTime == 0) {
                    $status = '🟢 نامحدود';
                } elseif ($expireTime > time()) {
                    $status = '🟢 فعال';
                } else {
                    $status = '🔴 منقضی شده';
                }
                
                $message .= ($i + 1) . ". <b>{$planName}</b>\n";
                $message .= "   📂 {$categoryName} | 🌐 {$serverName}\n";
                $message .= "   💰 {$price} تومان | {$status}\n";
                $message .= "   📅 {$createdAt}\n\n";
            }
            
            if (count($purchases) > $displayCount) {
                $message .= "... و " . (count($purchases) - $displayCount) . " خرید دیگر\n";
            }
            
            sendMessage($chat_id, $message);
            break;

        case '📚 راهنما':
            showGuideSelectionMenu($chat_id);
            break;

        case '🧪 دریافت کانفیگ تست':
            $test_plan = getTestPlan();
            if (!$test_plan) {
                sendMessage($chat_id, "❌ دریافت کانفیگ تست در حال حاضر توسط مدیر غیرفعال شده است.");
                break;
            }

            $settings = getSettings();
            $usage_limit = (int)($settings['test_config_usage_limit'] ?? 1);

            if ($user_data['test_config_count'] >= $usage_limit) {
                sendMessage($chat_id, "❌ شما قبلا از حداکثر تعداد کانفیگ تست خود استفاده کرده‌اید.");
                break;
            }

            $message =
                "<b>🧪 مشخصات کانفیگ تست رایگان</b>\n\n" .
                "▫️ نام پلن: <b>{$test_plan['name']}</b>\n" .
                "▫️ حجم: <b>" . (($test_plan['volume_gb'] > 0) ? number_format($test_plan['volume_gb']) . " GB" : "نامحدود") . "</b>\n" .
                "▫️ مدت اعتبار: <b>" . (($test_plan['duration_days'] > 0) ? number_format($test_plan['duration_days']) . " روز" : "نامحدود") . "</b>\n\n" .
                "برای دریافت این کانفیگ رایگان، روی دکمه زیر کلیک کنید.";
            $keyboard = ['inline_keyboard' => [[['text' => '✅ دریافت تست رایگان', 'callback_data' => 'buy_plan_' . $test_plan['id']]]]];
            sendMessage($chat_id, $message, $keyboard);
            break;

        case '🧪 مدیریت کانفیگ تست':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_test_config')) {
                $settings = getSettings();
                $usage_limit = $settings['test_config_usage_limit'] ?? 1;
                $message =
                    "<b>🧪 مدیریت کانفیگ تست</b>\n\n" .
                    "در این بخش می‌توانید تعداد دفعاتی که هر کاربر می‌تواند پلن تست را دریافت کند، مدیریت نمایید.\n\n" .
                    "▫️ تعداد مجاز فعلی: <b>{$usage_limit}</b> بار\n\n" .
                    "<b>نکته:</b> برای تعریف پلن تست، حجم و زمان آن، از بخش «مدیریت پلن‌ها» اقدام کنید.";
                $keyboard = ['keyboard' => [[['text' => '🔢 تنظیم تعداد مجاز'], ['text' => '🔄 ریست کردن دریافت‌ها']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, $message, $keyboard);
            }
            break;

        case '🔢 تنظیم تعداد مجاز':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_test_config')) {
                updateUserData($chat_id, 'admin_awaiting_test_limit', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا حداکثر تعداد دفعاتی که هر کاربر می‌تواند کانفیگ تست بگیرد را وارد کنید (فقط عدد):", $cancelKeyboard);
            }
            break;

        case '🔄 ریست کردن دریافت‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_test_config')) {
                $count = resetAllUsersTestCount();
                sendMessage($chat_id, "✅ شمارنده دریافت تست برای <b>{$count}</b> کاربر با موفقیت ریست شد. اکنون همه می‌توانند دوباره تست دریافت کنند.");
            }
            break;

        case '📢 مدیریت اعلان‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_notifications')) {
                $keyboard = ['inline_keyboard' => [[['text' => '🔔 اعلان‌های کاربران', 'callback_data' => 'user_notifications_menu']], [['text' => '👨‍💼 ارسال پیام به ادمین‌ها', 'callback_data' => 'admin_notifications_menu']]]];
                sendMessage($chat_id, "کدام دسته از اعلان‌ها را می‌خواهید مدیریت کنید؟", $keyboard);
            }
            break;

        case '🔐 مدیریت احراز هویت':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_verification')) {
                showVerificationManagementMenu($chat_id);
            }
            break;

        default:
            if ($user_state === 'main_menu' && !$apiRequest) {
                sendMessage($chat_id, "دستور شما را متوجه نشدم. لطفا از دکمه‌های موجود استفاده کنید.");
            }
            break;
    }
}