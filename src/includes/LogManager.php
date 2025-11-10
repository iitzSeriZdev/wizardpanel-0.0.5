<?php

/**
 * سیستم مدیریت لاگ‌ها با ارسال به گروه خصوصی
 * امکان فعال/غیرفعال کردن انواع مختلف لاگ‌ها
 */
class LogManager
{
    private static ?LogManager $instance = null;
    private ?Logger $logger = null;
    private ?int $logGroupId = null;
    private array $logTypes = [];

    private function __construct()
    {
        if (class_exists('Logger')) {
            try {
                $this->logger = Logger::getInstance();
            } catch (Exception $e) {
                error_log("LogManager: Failed to initialize Logger: " . $e->getMessage());
            }
        }
        $this->loadSettings();
    }

    public static function getInstance(): LogManager
    {
        if (self::$instance === null) {
            self::$instance = new LogManager();
        }
        return self::$instance;
    }

    /**
     * بارگذاری تنظیمات لاگ‌ها
     */
    private function loadSettings(): void
    {
        $settings = getSettings();
        
        // آیدی گروه لاگ‌ها
        $this->logGroupId = !empty($settings['log_group_id']) ? (int)$settings['log_group_id'] : null;
        
        // انواع لاگ‌ها و وضعیت فعال/غیرفعال بودن آنها
        $this->logTypes = [
            'server' => ($settings['log_server_enabled'] ?? 'off') === 'on',
            'error' => ($settings['log_error_enabled'] ?? 'on') === 'on',
            'purchase' => ($settings['log_purchase_enabled'] ?? 'on') === 'on',
            'transaction' => ($settings['log_transaction_enabled'] ?? 'on') === 'on',
            'user_new' => ($settings['log_user_new_enabled'] ?? 'off') === 'on',
            'user_ban' => ($settings['log_user_ban_enabled'] ?? 'off') === 'on',
            'admin_action' => ($settings['log_admin_action_enabled'] ?? 'off') === 'on',
            'payment' => ($settings['log_payment_enabled'] ?? 'on') === 'on',
            'config_create' => ($settings['log_config_create_enabled'] ?? 'on') === 'on',
            'config_delete' => ($settings['log_config_delete_enabled'] ?? 'off') === 'on',
        ];
    }

    /**
     * ارسال لاگ به گروه خصوصی
     */
    private function sendToLogGroup(string $message, string $parseMode = 'HTML'): bool
    {
        // به‌روزرسانی تنظیمات قبل از ارسال (برای اطمینان از اینکه logGroupId به‌روز است)
        $this->loadSettings();
        
        if (!$this->logGroupId) {
            // اگر logGroupId تنظیم نشده، لاگ را در فایل ذخیره می‌کنیم
            if (isset($this->logger) && $this->logger) {
                $this->logger->warning("Log group ID not configured, skipping log send to Telegram group");
            }
            return false;
        }

        try {
            // بررسی اینکه آیا تابع apiRequest موجود است
            if (!function_exists('apiRequest')) {
                if (isset($this->logger) && $this->logger) {
                    $this->logger->error("apiRequest function not found");
                }
                return false;
            }
            
            // برای ارسال به گروه، باید مستقیماً از apiRequest استفاده کنیم
            // تا از مشکل editMessageText جلوگیری کنیم
            $params = [
                'chat_id' => $this->logGroupId,
                'text' => $message,
                'parse_mode' => $parseMode
            ];
            
            $result = apiRequest('sendMessage', $params);
            
            if (empty($result)) {
                if (isset($this->logger) && $this->logger) {
                    $this->logger->error("Empty response from apiRequest");
                }
                return false;
            }
            
            $decoded = json_decode($result, true);
            
            if ($decoded && isset($decoded['ok']) && $decoded['ok']) {
                return true;
            } else {
                $error_msg = $decoded['description'] ?? ($decoded['error_code'] ?? 'Unknown error');
                if (isset($this->logger) && $this->logger) {
                    $this->logger->warning("Failed to send log to group", [
                        'group_id' => $this->logGroupId,
                        'error' => $error_msg,
                        'response' => $decoded
                    ]);
                }
                // همچنین لاگ را در error_log ذخیره می‌کنیم
                error_log("LogManager: Failed to send log to group {$this->logGroupId}: {$error_msg}");
                return false;
            }
        } catch (Exception $e) {
            if (isset($this->logger) && $this->logger) {
                $this->logger->error("Error sending log to group", [
                    'group_id' => $this->logGroupId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            // همچنین لاگ را در error_log ذخیره می‌کنیم
            error_log("LogManager: Exception while sending log to group {$this->logGroupId}: " . $e->getMessage());
            return false;
        } catch (Throwable $e) {
            if (isset($this->logger) && $this->logger) {
                $this->logger->error("Throwable error sending log to group", [
                    'group_id' => $this->logGroupId,
                    'error' => $e->getMessage()
                ]);
            }
            error_log("LogManager: Throwable error while sending log to group {$this->logGroupId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * لاگ سرور (خطاهای سرور، مشکلات پنل و...)
     */
    public function logServer(string $message, array $context = []): void
    {
        if (!$this->logTypes['server']) {
            return;
        }

        if ($this->logger) {
            $this->logger->error("Server: {$message}", $context);
        }
        
        $logMessage = "🔴 <b>لاگ سرور</b>\n\n";
        $logMessage .= "📝 پیام: {$message}\n";
        if (!empty($context)) {
            $logMessage .= "📋 جزئیات:\n<code>" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "</code>";
        }
        
        $this->sendToLogGroup($logMessage);
    }

    /**
     * لاگ خطاها
     */
    public function logError(string $message, array $context = []): void
    {
        if (!$this->logTypes['error']) {
            return;
        }

        if ($this->logger) {
            $this->logger->error("Error: {$message}", $context);
        }
        
        $logMessage = "❌ <b>لاگ خطا</b>\n\n";
        $logMessage .= "📝 پیام: {$message}\n";
        if (!empty($context)) {
            $logMessage .= "📋 جزئیات:\n<code>" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "</code>";
        }
        $logMessage .= "\n\n⏰ زمان: " . date('Y-m-d H:i:s');
        
        $this->sendToLogGroup($logMessage);
    }

    /**
     * لاگ خریدها
     */
    public function logPurchase(int $userId, int $planId, float $amount, string $planName): void
    {
        if (!$this->logTypes['purchase']) {
            return;
        }

        if ($this->logger) {
            $this->logger->info("Purchase: User {$userId} | Plan: {$planName} | Amount: {$amount}");
        }
        
        try {
            $userData = getUserData($userId, 'کاربر');
            $userName = $userData['first_name'] ?? 'نامشخص';
        } catch (Exception $e) {
            $userName = "User {$userId}";
        }
        
        $logMessage = "🛒 <b>خرید جدید</b>\n\n";
        $logMessage .= "👤 کاربر: {$userName} (<code>{$userId}</code>)\n";
        $logMessage .= "📦 پلن: {$planName}\n";
        $logMessage .= "💰 مبلغ: " . number_format($amount) . " تومان\n";
        $logMessage .= "🆔 پلن ID: <code>{$planId}</code>\n";
        $logMessage .= "\n⏰ زمان: " . date('Y-m-d H:i:s');
        
        $this->sendToLogGroup($logMessage);
    }

    /**
     * لاگ تراکنش‌ها
     */
    public function logTransaction(int $userId, float $amount, string $type, array $details = []): void
    {
        if (!$this->logTypes['transaction']) {
            return;
        }

        if ($this->logger) {
            $this->logger->info("Transaction: User {$userId} | Amount: {$amount} | Type: {$type}", $details);
        }
        
        try {
            $userData = getUserData($userId, 'کاربر');
            $userName = $userData['first_name'] ?? 'نامشخص';
        } catch (Exception $e) {
            $userName = "User {$userId}";
        }
        
        $typeNames = [
            'charge' => 'شارژ حساب',
            'purchase' => 'خرید سرویس',
            'refund' => 'بازگشت وجه',
            'commission' => 'کمیسیون',
        ];
        
        $typeName = $typeNames[$type] ?? $type;
        
        $logMessage = "💳 <b>تراکنش مالی</b>\n\n";
        $logMessage .= "👤 کاربر: {$userName} (<code>{$userId}</code>)\n";
        $logMessage .= "💰 مبلغ: " . number_format($amount) . " تومان\n";
        $logMessage .= "📝 نوع: {$typeName}\n";
        if (!empty($details)) {
            $logMessage .= "📋 جزئیات:\n<code>" . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "</code>\n";
        }
        $logMessage .= "\n⏰ زمان: " . date('Y-m-d H:i:s');
        
        $this->sendToLogGroup($logMessage);
    }

    /**
     * لاگ کاربران جدید
     */
    public function logNewUser(int $userId, string $userName): void
    {
        if (!$this->logTypes['user_new']) {
            return;
        }

        if ($this->logger) {
            $this->logger->info("New User: {$userId} | Name: {$userName}");
        }
        
        $logMessage = "👤 <b>کاربر جدید</b>\n\n";
        $logMessage .= "👤 نام: {$userName}\n";
        $logMessage .= "🆔 آیدی: <code>{$userId}</code>\n";
        $logMessage .= "\n⏰ زمان: " . date('Y-m-d H:i:s');
        
        $this->sendToLogGroup($logMessage);
    }

    /**
     * لاگ مسدود کردن کاربر
     */
    public function logUserBan(int $userId, string $userName, string $reason = ''): void
    {
        if (!$this->logTypes['user_ban']) {
            return;
        }

        if ($this->logger) {
            $this->logger->warning("User Banned: {$userId} | Reason: {$reason}");
        }
        
        $logMessage = "🚫 <b>کاربر مسدود شد</b>\n\n";
        $logMessage .= "👤 نام: {$userName}\n";
        $logMessage .= "🆔 آیدی: <code>{$userId}</code>\n";
        if (!empty($reason)) {
            $logMessage .= "📝 دلیل: {$reason}\n";
        }
        $logMessage .= "\n⏰ زمان: " . date('Y-m-d H:i:s');
        
        $this->sendToLogGroup($logMessage);
    }

    /**
     * لاگ اقدامات ادمین
     */
    public function logAdminAction(int $adminId, string $action, array $details = []): void
    {
        if (!$this->logTypes['admin_action']) {
            return;
        }

        if ($this->logger) {
            $this->logger->info("Admin Action: Admin {$adminId} | Action: {$action}", $details);
        }
        
        try {
            $adminData = getUserData($adminId, 'ادمین');
            $adminName = $adminData['first_name'] ?? 'نامشخص';
        } catch (Exception $e) {
            $adminName = "Admin {$adminId}";
        }
        
        $logMessage = "👨‍💼 <b>اقدام ادمین</b>\n\n";
        $logMessage .= "👤 ادمین: {$adminName} (<code>{$adminId}</code>)\n";
        $logMessage .= "⚡ اقدام: {$action}\n";
        if (!empty($details)) {
            $logMessage .= "📋 جزئیات:\n<code>" . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "</code>\n";
        }
        $logMessage .= "\n⏰ زمان: " . date('Y-m-d H:i:s');
        
        $this->sendToLogGroup($logMessage);
    }

    /**
     * لاگ پرداخت‌ها
     */
    public function logPayment(int $userId, float $amount, string $gateway, string $status, array $details = []): void
    {
        if (!$this->logTypes['payment']) {
            return;
        }

        if ($this->logger) {
            $this->logger->info("Payment: User {$userId} | Amount: {$amount} | Gateway: {$gateway} | Status: {$status}", $details);
        }
        
        try {
            $userData = getUserData($userId, 'کاربر');
            $userName = $userData['first_name'] ?? 'نامشخص';
        } catch (Exception $e) {
            $userName = "User {$userId}";
        }
        
        $statusIcons = [
            'success' => '✅',
            'failed' => '❌',
            'pending' => '⏳',
        ];
        
        $statusIcon = $statusIcons[$status] ?? '❓';
        
        $logMessage = "💳 <b>لاگ پرداخت</b>\n\n";
        $logMessage .= "👤 کاربر: {$userName} (<code>{$userId}</code>)\n";
        $logMessage .= "💰 مبلغ: " . number_format($amount) . " تومان\n";
        $logMessage .= "🌐 درگاه: {$gateway}\n";
        $logMessage .= "{$statusIcon} وضعیت: {$status}\n";
        if (!empty($details)) {
            $logMessage .= "📋 جزئیات:\n<code>" . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "</code>\n";
        }
        $logMessage .= "\n⏰ زمان: " . date('Y-m-d H:i:s');
        
        $this->sendToLogGroup($logMessage);
    }

    /**
     * لاگ ایجاد کانفیگ
     */
    public function logConfigCreate(int $userId, string $configName, int $planId): void
    {
        if (!$this->logTypes['config_create']) {
            return;
        }

        if ($this->logger) {
            $this->logger->info("Config Created: User {$userId} | Config: {$configName} | Plan: {$planId}");
        }
        
        try {
            $userData = getUserData($userId, 'کاربر');
            $userName = $userData['first_name'] ?? 'نامشخص';
        } catch (Exception $e) {
            $userName = "User {$userId}";
        }
        
        $logMessage = "⚙️ <b>کانفیگ ایجاد شد</b>\n\n";
        $logMessage .= "👤 کاربر: {$userName} (<code>{$userId}</code>)\n";
        $logMessage .= "📦 نام کانفیگ: <code>{$configName}</code>\n";
        $logMessage .= "🆔 پلن ID: <code>{$planId}</code>\n";
        $logMessage .= "\n⏰ زمان: " . date('Y-m-d H:i:s');
        
        $this->sendToLogGroup($logMessage);
    }

    /**
     * لاگ حذف کانفیگ
     */
    public function logConfigDelete(int $userId, string $configName, string $reason = ''): void
    {
        if (!$this->logTypes['config_delete']) {
            return;
        }

        if ($this->logger) {
            $this->logger->warning("Config Deleted: User {$userId} | Config: {$configName} | Reason: {$reason}");
        }
        
        try {
            $userData = getUserData($userId, 'کاربر');
            $userName = $userData['first_name'] ?? 'نامشخص';
        } catch (Exception $e) {
            $userName = "User {$userId}";
        }
        
        $logMessage = "🗑️ <b>کانفیگ حذف شد</b>\n\n";
        $logMessage .= "👤 کاربر: {$userName} (<code>{$userId}</code>)\n";
        $logMessage .= "📦 نام کانفیگ: <code>{$configName}</code>\n";
        if (!empty($reason)) {
            $logMessage .= "📝 دلیل: {$reason}\n";
        }
        $logMessage .= "\n⏰ زمان: " . date('Y-m-d H:i:s');
        
        $this->sendToLogGroup($logMessage);
    }

    /**
     * تنظیم آیدی گروه لاگ‌ها
     */
    public function setLogGroupId(int $groupId): bool
    {
        try {
            saveSettings(['log_group_id' => (string)$groupId]);
            $this->logGroupId = $groupId;
            return true;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Error setting log group ID", ['error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * فعال/غیرفعال کردن نوع لاگ
     */
    public function toggleLogType(string $logType, bool $enabled): bool
    {
        if (!isset($this->logTypes[$logType])) {
            return false;
        }

        try {
            $settingKey = "log_{$logType}_enabled";
            saveSettings([$settingKey => $enabled ? 'on' : 'off']);
            $this->logTypes[$logType] = $enabled;
            return true;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Error toggling log type", ['log_type' => $logType, 'error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * دریافت تنظیمات لاگ‌ها
     */
    public function getLogSettings(): array
    {
        return [
            'group_id' => $this->logGroupId,
            'types' => $this->logTypes
        ];
    }

    /**
     * دریافت آیدی گروه لاگ‌ها
     */
    public function getLogGroupId(): ?int
    {
        return $this->logGroupId;
    }

    /**
     * بررسی فعال بودن نوع لاگ
     */
    public function isLogTypeEnabled(string $logType): bool
    {
        return $this->logTypes[$logType] ?? false;
    }
}
