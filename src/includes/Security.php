<?php

/**
 * کلاس امنیتی برای اعتبارسنجی و پاکسازی ورودی‌ها
 */
class Security
{
    /**
     * پاکسازی رشته برای جلوگیری از XSS
     */
    public static function sanitizeString(string $input, bool $allowHtml = false): string
    {
        if ($allowHtml) {
            // فقط تگ‌های HTML مجاز را نگه دار
            $allowedTags = '<b><strong><i><em><u><s><code><pre><a>';
            $input = strip_tags($input, $allowedTags);
        } else {
            $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        }
        return trim($input);
    }

    /**
     * اعتبارسنجی Chat ID
     */
    public static function validateChatId($chatId): bool
    {
        return is_numeric($chatId) && $chatId > 0 && $chatId < PHP_INT_MAX;
    }

    /**
     * اعتبارسنجی مبلغ
     */
    public static function validateAmount($amount): bool
    {
        return is_numeric($amount) && $amount >= 0 && $amount <= 999999999;
    }

    /**
     * اعتبارسنجی نام کاربری
     */
    public static function validateUsername(string $username): bool
    {
        // فقط حروف، اعداد، خط تیره و زیرخط
        return preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $username) === 1;
    }

    /**
     * اعتبارسنجی کد تخفیف
     */
    public static function validateDiscountCode(string $code): bool
    {
        return preg_match('/^[A-Z0-9_-]{3,20}$/i', $code) === 1;
    }

    /**
     * اعتبارسنجی شماره کارت بانکی
     */
    public static function validateCardNumber(string $cardNumber): bool
    {
        $cardNumber = preg_replace('/\s+/', '', $cardNumber);
        return preg_match('/^[0-9]{16}$/', $cardNumber) === 1;
    }

    /**
     * اعتبارسنجی شماره تلفن
     */
    public static function validatePhoneNumber(string $phone): bool
    {
        // فرمت: +989123456789 یا 09123456789
        return preg_match('/^(\+98|0)?9\d{9}$/', $phone) === 1;
    }

    /**
     * اعتبارسنجی URL
     */
    public static function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * جلوگیری از SQL Injection با استفاده از Prepared Statements
     * (این تابع فقط برای موارد خاص استفاده می‌شود، بهتر است از PDO استفاده کنیم)
     */
    public static function escapeSql(string $string): string
    {
        // بهتر است از PDO و Prepared Statements استفاده کنیم
        // این فقط برای موارد ضروری است
        return addslashes($string);
    }

    /**
     * بررسی Rate Limit برای جلوگیری از سوء استفاده
     */
    public static function checkRateLimit(string $key, int $maxRequests = 10, int $timeWindow = 60): bool
    {
        $cacheKey = 'rate_limit_' . md5($key);
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.cache';
        
        $requests = [];
        if (file_exists($cacheFile)) {
            $data = file_get_contents($cacheFile);
            $requests = json_decode($data, true) ?? [];
        }

        $now = time();
        // حذف درخواست‌های قدیمی
        $requests = array_filter($requests, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });

        if (count($requests) >= $maxRequests) {
            return false; // Rate limit exceeded
        }

        $requests[] = $now;
        file_put_contents($cacheFile, json_encode($requests));
        
        return true; // Within rate limit
    }

    /**
     * تولید Token امن
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Hash کردن پسورد
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * بررسی پسورد
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * بررسی صحت داده‌های JSON
     */
    public static function validateJson(string $json): bool
    {
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * پاکسازی آرایه از مقادیر خطرناک
     */
    public static function sanitizeArray(array $data, bool $allowHtml = false): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $cleanKey = self::sanitizeString($key, false);
            if (is_array($value)) {
                $sanitized[$cleanKey] = self::sanitizeArray($value, $allowHtml);
            } elseif (is_string($value)) {
                $sanitized[$cleanKey] = self::sanitizeString($value, $allowHtml);
            } else {
                $sanitized[$cleanKey] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * بررسی IP برای جلوگیری از حملات
     */
    public static function getClientIp(): string
    {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
