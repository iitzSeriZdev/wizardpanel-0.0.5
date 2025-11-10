<?php

/**
 * سیستم نام‌گذاری کانفیگ‌ها
 * امکان تنظیم prefix و شماره شروع برای نام کانفیگ‌ها
 */
class ConfigNaming
{
    private static ?ConfigNaming $instance = null;
    private Logger $logger;

    private function __construct()
    {
        $this->logger = Logger::getInstance();
    }

    public static function getInstance(): ConfigNaming
    {
        if (self::$instance === null) {
            self::$instance = new ConfigNaming();
        }
        return self::$instance;
    }

    /**
     * تولید نام کانفیگ جدید
     */
    public function generateConfigName(): string
    {
        $settings = getSettings();
        $prefix = $settings['config_prefix'] ?? '';
        $startNumber = (int)($settings['config_start_number'] ?? 0);
        
        // اگر prefix تنظیم نشده، از روش قدیمی استفاده می‌کنیم
        if (empty($prefix)) {
            return $this->generateDefaultName();
        }
        
        // پاکسازی prefix از کاراکترهای غیرمجاز
        $prefix = preg_replace('/[^a-zA-Z0-9_.-]/', '', $prefix);
        
        // دریافت آخرین شماره استفاده شده
        $lastNumber = $this->getLastConfigNumber();
        
        // اگر شماره شروع بیشتر از آخرین شماره است، از شماره شروع استفاده می‌کنیم
        if ($startNumber > $lastNumber) {
            $nextNumber = $startNumber;
        } else {
            $nextNumber = $lastNumber + 1;
        }
        
        // ذخیره شماره جدید
        $this->saveLastConfigNumber($nextNumber);
        
        $configName = $prefix . $nextNumber;
        
        $this->logger->info("Generated config name", [
            'prefix' => $prefix,
            'number' => $nextNumber,
            'config_name' => $configName
        ]);
        
        return $configName;
    }

    /**
     * دریافت آخرین شماره استفاده شده
     */
    private function getLastConfigNumber(): int
    {
        $settings = getSettings();
        $prefix = $settings['config_prefix'] ?? '';
        
        if (empty($prefix)) {
            return 0;
        }
        
        // پاکسازی prefix
        $prefix = preg_replace('/[^a-zA-Z0-9_.-]/', '', $prefix);
        
        // جستجوی آخرین شماره در نام‌های کاربری موجود
        $stmt = pdo()->prepare("
            SELECT marzban_username 
            FROM services 
            WHERE marzban_username LIKE ? 
            ORDER BY id DESC 
            LIMIT 100
        ");
        $likePattern = $prefix . '%';
        $stmt->execute([$likePattern]);
        $usernames = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $maxNumber = 0;
        $pattern = '/^' . preg_quote($prefix, '/') . '(\d+)$/';
        
        foreach ($usernames as $username) {
            if (preg_match($pattern, $username, $matches)) {
                $number = (int)$matches[1];
                if ($number > $maxNumber) {
                    $maxNumber = $number;
                }
            }
        }
        
        return $maxNumber;
    }

    /**
     * ذخیره آخرین شماره استفاده شده
     */
    private function saveLastConfigNumber(int $number): void
    {
        // شماره را در تنظیمات ذخیره می‌کنیم
        $stmt = pdo()->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES ('config_last_number', ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        $stmt->execute([$number, $number]);
    }

    /**
     * تولید نام پیش‌فرض (روش قدیمی)
     */
    private function generateDefaultName(): string
    {
        // استفاده از timestamp برای ایجاد نام یکتا
        return 'user_' . time() . '_' . rand(1000, 9999);
    }

    /**
     * تنظیم prefix و شماره شروع
     */
    public function setConfigNaming(string $prefix, int $startNumber): bool
    {
        try {
            // پاکسازی prefix
            $prefix = preg_replace('/[^a-zA-Z0-9_.-]/', '', $prefix);
            
            if (empty($prefix)) {
                return false;
            }
            
            // ذخیره تنظیمات
            saveSettings([
                'config_prefix' => $prefix,
                'config_start_number' => (string)$startNumber
            ]);
            
            $this->logger->info("Config naming settings updated", [
                'prefix' => $prefix,
                'start_number' => $startNumber
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Error updating config naming settings", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت تنظیمات نام‌گذاری
     */
    public function getConfigNamingSettings(): array
    {
        $settings = getSettings();
        return [
            'prefix' => $settings['config_prefix'] ?? '',
            'start_number' => (int)($settings['config_start_number'] ?? 0),
            'last_number' => (int)($settings['config_last_number'] ?? 0)
        ];
    }

    /**
     * ریست کردن شمارنده
     */
    public function resetCounter(int $newStartNumber = 0): bool
    {
        try {
            saveSettings([
                'config_start_number' => (string)$newStartNumber,
                'config_last_number' => (string)$newStartNumber
            ]);
            
            $this->logger->info("Config naming counter reset", [
                'new_start_number' => $newStartNumber
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Error resetting config naming counter", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
