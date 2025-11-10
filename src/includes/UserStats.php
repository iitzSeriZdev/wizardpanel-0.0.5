<?php

/**
 * سیستم آمار و گزارش‌گیری کاربران
 * شامل آمار استفاده، تست سرعت، و گزارش‌های پیشرفته
 */
class UserStats
{
    private static ?UserStats $instance = null;
    private Logger $logger;

    private function __construct()
    {
        $this->logger = Logger::getInstance();
    }

    public static function getInstance(): UserStats
    {
        if (self::$instance === null) {
            self::$instance = new UserStats();
        }
        return self::$instance;
    }

    /**
     * دریافت آمار استفاده کاربر
     */
    public function getUserUsageStats(int $userId): array
    {
        $services = getUserServices($userId);
        $totalDataUsed = 0;
        $totalDataLimit = 0;
        $activeServices = 0;
        $expiredServices = 0;
        
        foreach ($services as $service) {
            $serverId = $service['server_id'];
            $username = $service['marzban_username'];
            
            $panelUser = getPanelUser($username, $serverId);
            if ($panelUser) {
                $totalDataUsed += $panelUser['used_traffic'] ?? 0;
                $dataLimit = ($service['volume_gb'] ?? 0) * 1024 * 1024 * 1024;
                $totalDataLimit += $dataLimit;
                
                if (($panelUser['expire'] ?? 0) > time() && ($panelUser['status'] ?? '') === 'active') {
                    $activeServices++;
                } else {
                    $expiredServices++;
                }
            }
        }
        
        return [
            'total_data_used' => $totalDataUsed,
            'total_data_limit' => $totalDataLimit,
            'active_services' => $activeServices,
            'expired_services' => $expiredServices,
            'usage_percentage' => $totalDataLimit > 0 ? ($totalDataUsed / $totalDataLimit) * 100 : 0
        ];
    }

    /**
     * دریافت آمار خرید کاربر
     */
    public function getUserPurchaseStats(int $userId): array
    {
        $stmt = pdo()->prepare("SELECT COUNT(*) as total, SUM(amount) as total_amount FROM transactions WHERE user_id = ? AND status = 'completed'");
        $stmt->execute([$userId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_purchases' => (int)($stats['total'] ?? 0),
            'total_spent' => (float)($stats['total_amount'] ?? 0)
        ];
    }

    /**
     * دریافت آمار کلی کاربر
     */
    public function getUserFullStats(int $userId): array
    {
        $usageStats = $this->getUserUsageStats($userId);
        $purchaseStats = $this->getUserPurchaseStats($userId);
        $userData = getUserData($userId);
        
        return [
            'user_id' => $userId,
            'user_name' => $userData['first_name'] ?? 'نامشخص',
            'balance' => $userData['balance'] ?? 0,
            'usage' => $usageStats,
            'purchases' => $purchaseStats,
            'join_date' => $userData['created_at'] ?? null,
            'last_seen' => $userData['last_seen_at'] ?? null
        ];
    }
}

