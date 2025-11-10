<?php

/**
 * سیستم Cache پیشرفته با پشتیبانی از File Cache و Memory Cache
 */
class Cache
{
    private static ?Cache $instance = null;
    private string $cacheDir;
    private array $memoryCache = [];
    private int $defaultTtl;

    private function __construct()
    {
        $this->cacheDir = defined('DATA_DIR') ? DATA_DIR . '/cache' : __DIR__ . '/../data/cache';
        $this->defaultTtl = 3600; // 1 hour default

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public static function getInstance(): Cache
    {
        if (self::$instance === null) {
            self::$instance = new Cache();
        }
        return self::$instance;
    }

    /**
     * ذخیره مقدار در Cache
     */
    public function set(string $key, $value, int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $expireAt = time() + $ttl;

        // ذخیره در Memory Cache
        $this->memoryCache[$key] = [
            'value' => $value,
            'expire_at' => $expireAt
        ];

        // ذخیره در File Cache
        $cacheFile = $this->getCacheFile($key);
        $data = [
            'value' => $value,
            'expire_at' => $expireAt,
            'created_at' => time()
        ];

        return file_put_contents($cacheFile, serialize($data), LOCK_EX) !== false;
    }

    /**
     * دریافت مقدار از Cache
     */
    public function get(string $key, $default = null)
    {
        // بررسی Memory Cache اول
        if (isset($this->memoryCache[$key])) {
            $cached = $this->memoryCache[$key];
            if ($cached['expire_at'] > time()) {
                return $cached['value'];
            }
            unset($this->memoryCache[$key]);
        }

        // بررسی File Cache
        $cacheFile = $this->getCacheFile($key);
        if (!file_exists($cacheFile)) {
            return $default;
        }

        $data = unserialize(file_get_contents($cacheFile));
        if (!$data || !isset($data['expire_at'])) {
            @unlink($cacheFile);
            return $default;
        }

        // بررسی انقضا
        if ($data['expire_at'] < time()) {
            @unlink($cacheFile);
            return $default;
        }

        // ذخیره در Memory Cache برای دسترسی سریع‌تر
        $this->memoryCache[$key] = [
            'value' => $data['value'],
            'expire_at' => $data['expire_at']
        ];

        return $data['value'];
    }

    /**
     * حذف مقدار از Cache
     */
    public function delete(string $key): bool
    {
        unset($this->memoryCache[$key]);
        
        $cacheFile = $this->getCacheFile($key);
        if (file_exists($cacheFile)) {
            return @unlink($cacheFile);
        }
        return true;
    }

    /**
     * پاکسازی Cache منقضی شده
     */
    public function clearExpired(): int
    {
        $cleared = 0;
        $files = glob($this->cacheDir . '/*.cache');
        
        foreach ($files as $file) {
            $data = @unserialize(file_get_contents($file));
            if (!$data || !isset($data['expire_at']) || $data['expire_at'] < time()) {
                if (@unlink($file)) {
                    $cleared++;
                }
            }
        }

        // پاکسازی Memory Cache
        $now = time();
        foreach ($this->memoryCache as $key => $cached) {
            if ($cached['expire_at'] < $now) {
                unset($this->memoryCache[$key]);
            }
        }

        return $cleared;
    }

    /**
     * پاکسازی تمام Cache
     */
    public function clear(): bool
    {
        $this->memoryCache = [];
        $files = glob($this->cacheDir . '/*.cache');
        $success = true;
        
        foreach ($files as $file) {
            if (!@unlink($file)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * دریافت مسیر فایل Cache
     */
    private function getCacheFile(string $key): string
    {
        $hashedKey = md5($key);
        return $this->cacheDir . '/' . $hashedKey . '.cache';
    }

    /**
     * بررسی وجود کلید در Cache
     */
    public function has(string $key): bool
    {
        return $this->get($key, '__CACHE_MISS__') !== '__CACHE_MISS__';
    }

    /**
     * افزایش مقدار (برای شمارنده‌ها)
     */
    public function increment(string $key, int $step = 1): int
    {
        $value = $this->get($key, 0);
        $newValue = $value + $step;
        $this->set($key, $newValue);
        return $newValue;
    }

    /**
     * کاهش مقدار
     */
    public function decrement(string $key, int $step = 1): int
    {
        $value = $this->get($key, 0);
        $newValue = max(0, $value - $step);
        $this->set($key, $newValue);
        return $newValue;
    }

    /**
     * دریافت چند کلید به صورت گروهی
     */
    public function getMultiple(array $keys): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        return $results;
    }

    /**
     * ذخیره چند کلید به صورت گروهی
     */
    public function setMultiple(array $values, int $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }
}

/**
 * تابع کمکی برای دسترسی سریع به Cache
 */
function cache(): Cache
{
    return Cache::getInstance();
}
