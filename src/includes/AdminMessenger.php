<?php

/**
 * سیستم ارسال پیام به ادمین‌ها
 * امکان ارسال پیام به همه ادمین‌ها یا ادمین خاص
 */
class AdminMessenger
{
    private static ?AdminMessenger $instance = null;
    private Logger $logger;

    private function __construct()
    {
        $this->logger = Logger::getInstance();
    }

    public static function getInstance(): AdminMessenger
    {
        if (self::$instance === null) {
            self::$instance = new AdminMessenger();
        }
        return self::$instance;
    }

    /**
     * دریافت لیست تمام ادمین‌ها
     */
    public function getAllAdmins(): array
    {
        $admins = [];
        
        // ادمین اصلی
        if (defined('ADMIN_CHAT_ID')) {
            $admins[] = ADMIN_CHAT_ID;
        }
        
        // ادمین‌های دیگر
        $stmt = pdo()->prepare("SELECT chat_id FROM admins WHERE is_super_admin = 0");
        $stmt->execute();
        $adminList = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $admins = array_merge($admins, $adminList);
        
        return array_unique($admins);
    }

    /**
     * ارسال پیام به همه ادمین‌ها
     */
    public function sendToAllAdmins(string $message, array $keyboard = null): array
    {
        $admins = $this->getAllAdmins();
        $successCount = 0;
        $failedCount = 0;
        
        foreach ($admins as $adminId) {
            try {
                $result = sendMessage($adminId, $message, $keyboard);
                $decoded = json_decode($result, true);
                
                if ($decoded && $decoded['ok']) {
                    $successCount++;
                } else {
                    $failedCount++;
                    $this->logger->warning("Failed to send message to admin", [
                        'admin_id' => $adminId,
                        'error' => $decoded['description'] ?? 'Unknown error'
                    ]);
                }
                
                // تأخیر برای جلوگیری از Rate Limit
                usleep(50000); // 50ms
            } catch (Exception $e) {
                $failedCount++;
                $this->logger->error("Error sending message to admin", [
                    'admin_id' => $adminId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return [
            'success' => true,
            'total' => count($admins),
            'success_count' => $successCount,
            'failed_count' => $failedCount
        ];
    }

    /**
     * ارسال پیام به ادمین خاص
     */
    public function sendToAdmin(int $adminId, string $message, array $keyboard = null): bool
    {
        // بررسی اینکه آیا این کاربر ادمین است
        if (!isUserAdmin($adminId)) {
            $this->logger->warning("Attempted to send message to non-admin user", [
                'user_id' => $adminId
            ]);
            return false;
        }
        
        try {
            $result = sendMessage($adminId, $message, $keyboard);
            $decoded = json_decode($result, true);
            
            if ($decoded && $decoded['ok']) {
                $this->logger->info("Message sent to admin", [
                    'admin_id' => $adminId
                ]);
                return true;
            } else {
                $this->logger->warning("Failed to send message to admin", [
                    'admin_id' => $adminId,
                    'error' => $decoded['description'] ?? 'Unknown error'
                ]);
                return false;
            }
        } catch (Exception $e) {
            $this->logger->error("Error sending message to admin", [
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت اطلاعات ادمین‌ها برای نمایش
     */
    public function getAdminsList(): array
    {
        $admins = [];
        
        // ادمین اصلی
        if (defined('ADMIN_CHAT_ID')) {
            $adminInfo = getUserData(ADMIN_CHAT_ID);
            $admins[] = [
                'chat_id' => ADMIN_CHAT_ID,
                'first_name' => $adminInfo['first_name'] ?? 'ادمین اصلی',
                'is_super_admin' => true
            ];
        }
        
        // ادمین‌های دیگر
        $stmt = pdo()->prepare("SELECT chat_id, first_name FROM admins WHERE is_super_admin = 0");
        $stmt->execute();
        $adminList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($adminList as $admin) {
            $admins[] = [
                'chat_id' => $admin['chat_id'],
                'first_name' => $admin['first_name'] ?? 'ادمین',
                'is_super_admin' => false
            ];
        }
        
        return $admins;
    }
}
