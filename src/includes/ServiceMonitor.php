<?php

/**
 * سیستم مانیتورینگ و هشدار پیشرفته سرویس‌ها
 * شامل هشدار حجم، زمان، و مانیتورینگ وضعیت
 */
class ServiceMonitor
{
    private static ?ServiceMonitor $instance = null;
    private Logger $logger;

    private function __construct()
    {
        $this->logger = Logger::getInstance();
    }

    public static function getInstance(): ServiceMonitor
    {
        if (self::$instance === null) {
            self::$instance = new ServiceMonitor();
        }
        return self::$instance;
    }

    /**
     * بررسی و ارسال هشدار برای سرویس‌ها
     */
    public function checkAndSendWarnings(): void
    {
        $settings = getSettings();
        
        // بررسی هشدار حجم
        if (($settings['notification_expire_status'] ?? 'off') === 'on') {
            $this->checkDataUsageWarnings();
        }
        
        // بررسی هشدار زمان
        if (($settings['notification_expire_status'] ?? 'off') === 'on') {
            $this->checkExpirationWarnings();
        }
        
        // بررسی وضعیت سرویس‌ها
        $this->checkServiceStatus();
    }

    /**
     * بررسی هشدار حجم
     */
    private function checkDataUsageWarnings(): void
    {
        $settings = getSettings();
        $warningThreshold = (float)($settings['notification_expire_gb'] ?? 1); // گیگابایت
        $warningThresholdBytes = $warningThreshold * 1024 * 1024 * 1024;
        
        $stmt = pdo()->query("SELECT s.*, u.chat_id, u.first_name FROM services s JOIN users u ON s.owner_chat_id = u.chat_id WHERE s.warning_sent = 0");
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($services as $service) {
            $panelUser = getPanelUser($service['marzban_username'], $service['server_id']);
            if ($panelUser) {
                $usedBytes = $panelUser['used_traffic'] ?? 0;
                $totalBytes = ($service['volume_gb'] ?? 0) * 1024 * 1024 * 1024;
                $remainingBytes = $totalBytes - $usedBytes;
                
                if ($totalBytes > 0 && $remainingBytes <= $warningThresholdBytes) {
                    $remainingGB = $remainingBytes / (1024 * 1024 * 1024);
                    $message = "⚠️ <b>هشدار حجم سرویس</b>\n\n";
                    $message .= "سرویس: <code>{$service['marzban_username']}</code>\n";
                    $message .= "حجم باقی‌مانده: <b>" . number_format($remainingGB, 2) . " GB</b>\n\n";
                    $message .= "لطفاً برای تمدید یا افزایش حجم اقدام کنید.";
                    
                    sendMessage($service['chat_id'], $message);
                    
                    // علامت‌گذاری هشدار ارسال شده
                    pdo()->prepare("UPDATE services SET warning_sent = 1 WHERE id = ?")->execute([$service['id']]);
                }
            }
        }
    }

    /**
     * بررسی هشدار انقضا
     */
    private function checkExpirationWarnings(): void
    {
        $settings = getSettings();
        $warningDays = (int)($settings['notification_expire_days'] ?? 3);
        $warningMessage = $settings['notification_expire_message'] ?? 'سرویس شما به زودی منقضی می‌شود.';
        
        $warningTimestamp = time() + ($warningDays * 24 * 60 * 60);
        
        $stmt = pdo()->prepare("SELECT s.*, u.chat_id, u.first_name FROM services s JOIN users u ON s.owner_chat_id = u.chat_id WHERE s.expire_timestamp > ? AND s.expire_timestamp <= ? AND s.warning_sent = 0");
        $stmt->execute([time(), $warningTimestamp]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($services as $service) {
            $daysLeft = ceil(($service['expire_timestamp'] - time()) / (24 * 60 * 60));
            $expireDate = date('Y-m-d H:i:s', $service['expire_timestamp']);
            
            $message = "⚠️ <b>هشدار انقضای سرویس</b>\n\n";
            $message .= "{$warningMessage}\n\n";
            $message .= "سرویس: <code>{$service['marzban_username']}</code>\n";
            $message .= "روزهای باقی‌مانده: <b>{$daysLeft} روز</b>\n";
            $message .= "تاریخ انقضا: <b>{$expireDate}</b>\n\n";
            $message .= "لطفاً برای تمدید سرویس اقدام کنید.";
            
            sendMessage($service['chat_id'], $message);
            
            // علامت‌گذاری هشدار ارسال شده
            pdo()->prepare("UPDATE services SET warning_sent = 1 WHERE id = ?")->execute([$service['id']]);
        }
    }

    /**
     * بررسی وضعیت سرویس‌ها
     */
    private function checkServiceStatus(): void
    {
        // بررسی سرویس‌های غیرفعال و اطلاع به کاربران
        $stmt = pdo()->query("SELECT s.*, u.chat_id FROM services s JOIN users u ON s.owner_chat_id = u.chat_id");
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($services as $service) {
            $panelUser = getPanelUser($service['marzban_username'], $service['server_id']);
            if ($panelUser && ($panelUser['status'] ?? '') !== 'active') {
                // سرویس غیرفعال است
                $message = "⚠️ <b>هشدار وضعیت سرویس</b>\n\n";
                $message .= "سرویس شما غیرفعال شده است.\n";
                $message .= "سرویس: <code>{$service['marzban_username']}</code>\n\n";
                $message .= "لطفاً با پشتیبانی تماس بگیرید.";
                
                sendMessage($service['chat_id'], $message);
            }
        }
    }

    /**
     * ریست کردن هشدارهای سرویس
     */
    public function resetServiceWarnings(int $serviceId): void
    {
        pdo()->prepare("UPDATE services SET warning_sent = 0 WHERE id = ?")->execute([$serviceId]);
    }
}

