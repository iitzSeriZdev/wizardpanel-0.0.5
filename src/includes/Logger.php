<?php

/**
 * سیستم لاگ‌گیری پیشرفته برای ربات تلگرام
 * 
 * این کلاس امکان لاگ‌گیری با سطوح مختلف، چرخش فایل‌ها و فرمت‌های مختلف را فراهم می‌کند.
 */
class Logger
{
    private const LOG_LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];

    private static ?Logger $instance = null;
    private string $logDir;
    private string $logLevel;
    private int $maxFileSize;
    private int $maxFiles;

    private function __construct()
    {
        $this->logDir = defined('DATA_DIR') ? DATA_DIR . '/logs' : __DIR__ . '/../data/logs';
        $this->logLevel = $_ENV['LOG_LEVEL'] ?? 'INFO';
        $this->maxFileSize = 10 * 1024 * 1024; // 10MB
        $this->maxFiles = 10;

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public static function getInstance(): Logger
    {
        if (self::$instance === null) {
            self::$instance = new Logger();
        }
        return self::$instance;
    }

    /**
     * لاگ کردن پیام با سطح مشخص
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!isset(self::LOG_LEVELS[$level]) || 
            self::LOG_LEVELS[$level] < self::LOG_LEVELS[$this->logLevel]) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logFile = $this->logDir . '/bot_' . date('Y-m-d') . '.log';
        
        $contextStr = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

        // چرخش فایل در صورت بزرگ شدن
        $this->rotateIfNeeded($logFile);

        // برای خطاهای بحرانی، ارسال به error_log هم
        if ($level === 'CRITICAL' || $level === 'ERROR') {
            error_log($logMessage);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    /**
     * چرخش فایل لاگ در صورت بزرگ شدن
     */
    private function rotateIfNeeded(string $logFile): void
    {
        if (!file_exists($logFile) || filesize($logFile) < $this->maxFileSize) {
            return;
        }

        // پیدا کردن آخرین شماره فایل
        $baseName = basename($logFile, '.log');
        $dir = dirname($logFile);
        $maxNum = 0;

        $files = glob($dir . '/' . $baseName . '_*.log');
        foreach ($files as $file) {
            if (preg_match('/_(\d+)\.log$/', $file, $matches)) {
                $maxNum = max($maxNum, (int)$matches[1]);
            }
        }

        // تغییر نام فایل فعلی
        $newNum = $maxNum + 1;
        rename($logFile, $dir . '/' . $baseName . '_' . $newNum . '.log');

        // حذف فایل‌های قدیمی
        $this->cleanOldLogs($baseName, $dir);
    }

    /**
     * حذف فایل‌های قدیمی لاگ
     */
    private function cleanOldLogs(string $baseName, string $dir): void
    {
        $files = glob($dir . '/' . $baseName . '_*.log');
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        if (count($files) > $this->maxFiles) {
            foreach (array_slice($files, $this->maxFiles) as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * لاگ کردن تراکنش‌های مالی
     */
    public function logTransaction(int $userId, float $amount, string $type, array $details = []): void
    {
        $this->info("Transaction: User {$userId} | Amount: {$amount} | Type: {$type}", $details);
    }

    /**
     * لاگ کردن فعالیت‌های ادمین
     */
    public function logAdminAction(int $adminId, string $action, array $details = []): void
    {
        $this->info("Admin Action: Admin {$adminId} | Action: {$action}", $details);
    }
}

/**
 * تابع کمکی برای دسترسی سریع به Logger
 */
function logger(): Logger
{
    return Logger::getInstance();
}
