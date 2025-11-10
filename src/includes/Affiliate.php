<?php

/**
 * سیستم Affiliate/Referral
 * کاربران می‌توانند با دعوت دیگران، کمیسیون دریافت کنند
 */
class Affiliate
{
    private static ?Affiliate $instance = null;
    private Logger $logger;

    private function __construct()
    {
        $this->logger = Logger::getInstance();
    }

    public static function getInstance(): Affiliate
    {
        if (self::$instance === null) {
            self::$instance = new Affiliate();
        }
        return self::$instance;
    }

    /**
     * ثبت کاربر جدید به عنوان معرف
     */
    public function setReferrer(int $userId, int $referrerId): bool
    {
        try {
            // بررسی اینکه کاربر قبلاً معرفی نشده باشد
            $stmt = pdo()->prepare("SELECT referrer_id FROM users WHERE chat_id = ?");
            $stmt->execute([$userId]);
            $existing = $stmt->fetch();
            
            if ($existing && $existing['referrer_id']) {
                return false; // کاربر قبلاً معرفی شده
            }
            
            // بررسی اینکه کاربر خودش را معرفی نکرده باشد
            if ($userId === $referrerId) {
                return false;
            }
            
            // ثبت معرف
            $stmt = pdo()->prepare("UPDATE users SET referrer_id = ? WHERE chat_id = ?");
            $stmt->execute([$referrerId, $userId]);
            
            // افزایش تعداد معرفی‌های معرف
            $stmt = pdo()->prepare("UPDATE users SET referrals_count = COALESCE(referrals_count, 0) + 1 WHERE chat_id = ?");
            $stmt->execute([$referrerId]);
            
            $this->logger->info("User registered with referrer", [
                'user_id' => $userId,
                'referrer_id' => $referrerId
            ]);
            
            return true;
        } catch (PDOException $e) {
            $this->logger->error("Error setting referrer", [
                'user_id' => $userId,
                'referrer_id' => $referrerId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * پرداخت کمیسیون به معرف
     */
    public function payCommission(int $userId, float $purchaseAmount): bool
    {
        try {
            // دریافت اطلاعات معرف
            $stmt = pdo()->prepare("SELECT referrer_id FROM users WHERE chat_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || !$user['referrer_id']) {
                return false; // کاربر معرفی نشده
            }
            
            $referrerId = $user['referrer_id'];
            
            // دریافت تنظیمات کمیسیون
            $settings = getSettings();
            $commissionType = $settings['affiliate_commission_type'] ?? 'percentage'; // percentage or fixed
            $commissionValue = (float)($settings['affiliate_commission_value'] ?? 0);
            
            if ($commissionValue <= 0) {
                return false; // کمیسیون فعال نیست
            }
            
            // محاسبه کمیسیون
            if ($commissionType === 'percentage') {
                $commission = ($purchaseAmount * $commissionValue) / 100;
            } else {
                $commission = $commissionValue;
            }
            
            // پرداخت کمیسیون به معرف
            $stmt = pdo()->prepare("UPDATE users SET balance = balance + ?, affiliate_earnings = COALESCE(affiliate_earnings, 0) + ? WHERE chat_id = ?");
            $stmt->execute([$commission, $commission, $referrerId]);
            
            // ثبت تراکنش کمیسیون
            $stmt = pdo()->prepare("INSERT INTO affiliate_transactions (referrer_id, referred_id, purchase_amount, commission_amount, status) VALUES (?, ?, ?, ?, 'paid')");
            $stmt->execute([$referrerId, $userId, $purchaseAmount, $commission]);
            
            // ارسال پیام به معرف
            $referrerData = getUserData($referrerId);
            $message = "🎉 <b>کمیسیون جدید!</b>\n\n" .
                      "کاربری که شما معرفی کرده‌اید، یک خرید انجام داد.\n" .
                      "▫️ مبلغ خرید: " . number_format($purchaseAmount) . " تومان\n" .
                      "▫️ کمیسیون شما: <b>" . number_format($commission) . " تومان</b>\n\n" .
                      "مبلغ کمیسیون به موجودی شما اضافه شد.";
            
            sendMessage($referrerId, $message);
            
            $this->logger->info("Commission paid", [
                'referrer_id' => $referrerId,
                'referred_id' => $userId,
                'purchase_amount' => $purchaseAmount,
                'commission' => $commission
            ]);
            
            return true;
        } catch (PDOException $e) {
            $this->logger->error("Error paying commission", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت اطلاعات Affiliate کاربر
     */
    public function getAffiliateInfo(int $userId): array
    {
        $stmt = pdo()->prepare("
            SELECT 
                referrals_count,
                affiliate_earnings,
                (SELECT COUNT(*) FROM affiliate_transactions WHERE referrer_id = ?) as total_commissions,
                (SELECT SUM(commission_amount) FROM affiliate_transactions WHERE referrer_id = ? AND status = 'paid') as total_earned
            FROM users 
            WHERE chat_id = ?
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $info = $stmt->fetch();
        
        if (!$info) {
            return [
                'referrals_count' => 0,
                'affiliate_earnings' => 0,
                'total_commissions' => 0,
                'total_earned' => 0
            ];
        }
        
        return [
            'referrals_count' => (int)($info['referrals_count'] ?? 0),
            'affiliate_earnings' => (float)($info['affiliate_earnings'] ?? 0),
            'total_commissions' => (int)($info['total_commissions'] ?? 0),
            'total_earned' => (float)($info['total_earned'] ?? 0)
        ];
    }

    /**
     * دریافت لینک معرفی
     */
    public function getReferralLink(int $userId): string
    {
        $settings = getSettings();
        $botUsername = $settings['bot_username'] ?? '';
        
        if (empty($botUsername)) {
            // اگر نام کاربری ربات ثبت نشده، از توکن استفاده کنیم
            $botInfo = json_decode(apiRequest('getMe'), true);
            if ($botInfo && $botInfo['ok']) {
                $botUsername = $botInfo['result']['username'];
                // ذخیره در تنظیمات
                saveSettings(['bot_username' => $botUsername]);
            }
        }
        
        $refCode = base64_encode($userId);
        return "https://t.me/{$botUsername}?start=ref_{$refCode}";
    }

    /**
     * پردازش لینک معرفی
     */
    public function processReferralLink(int $userId, string $startParam): bool
    {
        if (strpos($startParam, 'ref_') !== 0) {
            return false;
        }
        
        $refCode = substr($startParam, 4);
        $referrerId = (int)base64_decode($refCode);
        
        if ($referrerId <= 0 || $referrerId === $userId) {
            return false;
        }
        
        // بررسی وجود معرف
        $stmt = pdo()->prepare("SELECT chat_id FROM users WHERE chat_id = ?");
        $stmt->execute([$referrerId]);
        if (!$stmt->fetch()) {
            return false; // معرف وجود ندارد
        }
        
        return $this->setReferrer($userId, $referrerId);
    }

    /**
     * دریافت لیست کاربران معرفی شده
     */
    public function getReferredUsers(int $referrerId, int $limit = 50): array
    {
        $stmt = pdo()->prepare("
            SELECT chat_id, first_name, created_at, 
                   (SELECT SUM(p.price) FROM services s JOIN plans p ON s.plan_id = p.id WHERE s.owner_chat_id = users.chat_id) as total_purchases
            FROM users 
            WHERE referrer_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$referrerId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * دریافت آمار کلی Affiliate
     */
    public function getAffiliateStats(int $userId): array
    {
        $info = $this->getAffiliateInfo($userId);
        $referredUsers = $this->getReferredUsers($userId, 100);
        
        $totalPurchases = 0;
        foreach ($referredUsers as $user) {
            $totalPurchases += (float)($user['total_purchases'] ?? 0);
        }
        
        return [
            'referrals_count' => $info['referrals_count'],
            'total_earned' => $info['total_earned'],
            'total_purchases' => $totalPurchases,
            'referred_users' => count($referredUsers),
            'referral_link' => $this->getReferralLink($userId)
        ];
    }
}
