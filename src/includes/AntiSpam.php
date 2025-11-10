<?php

/**
 * سیستم ضد اسپم برای ربات تلگرام
 * شامل قابلیت میوت، پیام قابل تنظیم و مدیریت هوشمند
 */
class AntiSpam
{
    private static ?AntiSpam $instance = null;
    private Logger $logger;
    private array $settings;

    private function __construct()
    {
        if (file_exists(__DIR__ . '/Logger.php') && class_exists('Logger')) {
            $this->logger = Logger::getInstance();
        }
        $this->loadSettings();
    }

    public static function getInstance(): AntiSpam
    {
        if (self::$instance === null) {
            self::$instance = new AntiSpam();
        }
        return self::$instance;
    }

    /**
     * بارگذاری تنظیمات ضد اسپم
     */
    private function loadSettings(): void
    {
        $settings = getSettings();
        $this->settings = [
            'enabled' => $settings['antispam_enabled'] ?? 'off',
            'max_actions' => (int)($settings['antispam_max_actions'] ?? 10),
            'time_window' => (int)($settings['antispam_time_window'] ?? 5), // ثانیه
            'mute_duration' => (int)($settings['antispam_mute_duration'] ?? 60), // دقیقه
            'message' => $settings['antispam_message'] ?? '⚠️ شما به دلیل ارسال پیام‌های زیاد به صورت موقت مسدود شده‌اید. لطفاً صبر کنید.',
        ];
    }

    /**
     * بررسی و اعمال ضد اسپم
     * @param int $userId شناسه کاربر
     * @param string $actionType نوع عمل (message, callback, etc.)
     * @return array ['allowed' => bool, 'muted' => bool, 'message' => string|null]
     */
    public function checkAndHandle(int $userId, string $actionType = 'message'): array
    {
        // اگر ضد اسپم غیرفعال است
        if ($this->settings['enabled'] !== 'on') {
            return ['allowed' => true, 'muted' => false, 'message' => null];
        }

        // بررسی اینکه آیا کاربر در حال حاضر میوت است
        $isMuted = $this->isUserMuted($userId);
        if ($isMuted) {
            $muteInfo = $this->getMuteInfo($userId);
            if ($muteInfo && $muteInfo['expires_at'] > time()) {
                $remaining = $muteInfo['expires_at'] - time();
                $remainingMinutes = ceil($remaining / 60);
                return [
                    'allowed' => false,
                    'muted' => true,
                    'message' => $this->settings['message'] . "\n\n⏰ زمان باقی‌مانده: {$remainingMinutes} دقیقه"
                ];
            } else {
                // زمان میوت تمام شده، حذف میوت
                $this->removeMute($userId);
            }
        }

        // ثبت عمل کاربر
        $this->recordAction($userId, $actionType);

        // بررسی تعداد اعمال در بازه زمانی
        $actionCount = $this->getActionCount($userId, $this->settings['time_window']);
        
        if ($actionCount >= $this->settings['max_actions']) {
            // کاربر اسپم کرده است - میوت کردن
            $this->muteUser($userId, $this->settings['mute_duration']);
            
            return [
                'allowed' => false,
                'muted' => true,
                'message' => $this->settings['message'] . "\n\n⏰ مدت زمان مسدودیت: {$this->settings['mute_duration']} دقیقه"
            ];
        }

        return ['allowed' => true, 'muted' => false, 'message' => null];
    }

    /**
     * ثبت عمل کاربر
     */
    private function recordAction(int $userId, string $actionType): void
    {
        $cacheKey = "antispam_actions_{$userId}";
        $cacheFile = sys_get_temp_dir() . '/' . md5($cacheKey) . '.cache';
        
        $actions = [];
        if (file_exists($cacheFile)) {
            $data = file_get_contents($cacheFile);
            $actions = json_decode($data, true) ?? [];
        }

        // حذف اعمال قدیمی
        $now = time();
        $actions = array_filter($actions, function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->settings['time_window'];
        });

        // اضافه کردن عمل جدید
        $actions[] = $now;
        
        file_put_contents($cacheFile, json_encode($actions));
    }

    /**
     * دریافت تعداد اعمال در بازه زمانی
     */
    private function getActionCount(int $userId, int $timeWindow): int
    {
        $cacheKey = "antispam_actions_{$userId}";
        $cacheFile = sys_get_temp_dir() . '/' . md5($cacheKey) . '.cache';
        
        if (!file_exists($cacheFile)) {
            return 0;
        }

        $data = file_get_contents($cacheFile);
        $actions = json_decode($data, true) ?? [];
        
        $now = time();
        $recentActions = array_filter($actions, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });

        return count($recentActions);
    }

    /**
     * میوت کردن کاربر
     */
    private function muteUser(int $userId, int $durationMinutes): void
    {
        $cacheKey = "antispam_mute_{$userId}";
        $cacheFile = sys_get_temp_dir() . '/' . md5($cacheKey) . '.cache';
        
        $muteInfo = [
            'user_id' => $userId,
            'muted_at' => time(),
            'expires_at' => time() + ($durationMinutes * 60),
            'duration_minutes' => $durationMinutes
        ];
        
        file_put_contents($cacheFile, json_encode($muteInfo));
        
        // لاگ کردن
        if (isset($this->logger)) {
            $this->logger->warning("User muted for spam", [
                'user_id' => $userId,
                'duration_minutes' => $durationMinutes
            ]);
        }
    }

    /**
     * بررسی اینکه آیا کاربر میوت است
     */
    public function isUserMuted(int $userId): bool
    {
        $muteInfo = $this->getMuteInfo($userId);
        if (!$muteInfo) {
            return false;
        }
        
        return $muteInfo['expires_at'] > time();
    }

    /**
     * دریافت اطلاعات میوت کاربر
     */
    private function getMuteInfo(int $userId): ?array
    {
        $cacheKey = "antispam_mute_{$userId}";
        $cacheFile = sys_get_temp_dir() . '/' . md5($cacheKey) . '.cache';
        
        if (!file_exists($cacheFile)) {
            return null;
        }

        $data = file_get_contents($cacheFile);
        $muteInfo = json_decode($data, true);
        
        if (!$muteInfo || !isset($muteInfo['expires_at'])) {
            return null;
        }

        return $muteInfo;
    }

    /**
     * حذف میوت کاربر
     */
    private function removeMute(int $userId): void
    {
        $cacheKey = "antispam_mute_{$userId}";
        $cacheFile = sys_get_temp_dir() . '/' . md5($cacheKey) . '.cache';
        
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * دریافت تنظیمات ضد اسپم
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * به‌روزرسانی تنظیمات
     */
    public function updateSettings(array $newSettings): void
    {
        $settings = getSettings();
        foreach ($newSettings as $key => $value) {
            $settings["antispam_{$key}"] = $value;
        }
        saveSettings($settings);
        $this->loadSettings();
    }

    /**
     * دریافت لیست کاربران میوت شده
     */
    public function getMutedUsers(): array
    {
        $mutedUsers = [];
        $cacheDir = sys_get_temp_dir();
        $files = glob($cacheDir . '/' . md5('antispam_mute_*') . '.cache');
        
        // این روش کامل نیست، بهتر است از دیتابیس استفاده کنیم
        // اما برای سادگی از cache استفاده می‌کنیم
        return $mutedUsers;
    }

    /**
     * حذف میوت کاربر (دستور ادمین)
     */
    public function unmuteUser(int $userId): bool
    {
        $this->removeMute($userId);
        if (isset($this->logger)) {
            $this->logger->info("User unmuted by admin", ['user_id' => $userId]);
        }
        return true;
    }
}

