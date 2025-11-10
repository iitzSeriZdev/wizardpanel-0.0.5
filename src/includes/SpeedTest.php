<?php

/**
 * سیستم تست سرعت برای کاربران
 * امکان تست سرعت اینترنت و مقایسه با سرورهای مختلف
 * شامل دریافت تمامی کانفیگ‌ها از پنل و نمایش پینگ
 */
class SpeedTest
{
    private static ?SpeedTest $instance = null;
    private $logger = null;

    private function __construct()
    {
        if (file_exists(__DIR__ . '/Logger.php') && class_exists('Logger')) {
            try {
                $this->logger = Logger::getInstance();
            } catch (Exception $e) {
                // Logger در دسترس نیست
                $this->logger = null;
            }
        }
    }

    public static function getInstance(): SpeedTest
    {
        if (self::$instance === null) {
            self::$instance = new SpeedTest();
        }
        return self::$instance;
    }

    /**
     * دریافت تمامی کانفیگ‌ها از لینک سابسکریپشن
     * @param string $subscriptionUrl لینک سابسکریپشن
     * @param string $inboundType نوع اینباند (vless, vmess, etc.) - اگر خالی باشد تمامی کانفیگ‌ها را برمی‌گرداند
     * @return array لیست کانفیگ‌ها با اطلاعات
     */
    public function getAllConfigsFromSubscription(string $subscriptionUrl, string $inboundType = ''): array
    {
        $settings = getSettings();
        // اگر لینک سابسکریپشن در تنظیمات است، از آن استفاده کن
        $speedtestSubUrl = $settings['speedtest_subscription_url'] ?? $subscriptionUrl;
        
        // دریافت محتوای سابسکریپشن
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $speedtestSubUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return [];
        }
        
        // دیکود base64
        $decoded = base64_decode($response, true);
        if ($decoded === false) {
            $decoded = $response;
        }
        
        // تبدیل به آرایه
        $configs = [];
        $lines = preg_split("/\r\n|\n|\r/", trim($decoded));
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // پارس کردن کانفیگ
            $config = $this->parseConfig($line, $inboundType);
            if ($config) {
                $configs[] = $config;
            } elseif (empty($inboundType)) {
                // اگر نوع اینباند مشخص نشده، تمامی کانفیگ‌ها را اضافه کن
                $config = $this->parseConfig($line, '');
                if ($config) {
                    $configs[] = $config;
                }
            }
        }
        
        return $configs;
    }

    /**
     * پارس کردن یک کانفیگ
     */
    private function parseConfig(string $configLine, string $inboundType): ?array
    {
        // فرمت: protocol://uuid@host:port?params#name
        if (strpos($configLine, '://') === false) {
            return null;
        }
        
        $parts = explode('://', $configLine, 2);
        if (count($parts) < 2) {
            return null;
        }
        $protocol = strtolower($parts[0]);
        
        // اگر نوع اینباند مشخص شده و کانفیگ با آن مطابقت ندارد، رد کن
        if (!empty($inboundType) && $protocol !== strtolower($inboundType)) {
            return null;
        }
        
        $remaining = $parts[1] ?? '';
        
        // استخراج نام از fragment
        $name = '';
        if (strpos($remaining, '#') !== false) {
            list($remaining, $name) = explode('#', $remaining, 2);
            $name = urldecode($name);
        }
        
        // استخراج UUID و host:port
        if (strpos($remaining, '@') !== false) {
            list($uuid, $hostPort) = explode('@', $remaining, 2);
        } else {
            return null;
        }
        
        // استخراج host و port
        if (strpos($hostPort, '?') !== false) {
            list($hostPort, $params) = explode('?', $hostPort, 2);
        }
        
        if (strpos($hostPort, ':') !== false) {
            list($host, $port) = explode(':', $hostPort, 2);
        } else {
            return null;
        }
        
        return [
            'protocol' => $protocol,
            'name' => $name ?: 'Config',
            'host' => $host,
            'port' => (int)$port,
            'uuid' => $uuid,
            'full_config' => $configLine
        ];
    }

    /**
     * تست پینگ یک هاست
     */
    public function pingHost(string $host, int $port = 443, int $timeout = 3): ?int
    {
        $startTime = microtime(true);
        
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
        
        if ($connection) {
            $endTime = microtime(true);
            $ping = round(($endTime - $startTime) * 1000); // به میلی‌ثانیه
            fclose($connection);
            return $ping;
        }
        
        return null;
    }

    /**
     * دریافت تمامی کانفیگ‌ها و تست پینگ
     * @param int $serverId شناسه سرور
     * @param string $inboundType نوع اینباند
     * @return array لیست کانفیگ‌ها با پینگ
     */
    public function getConfigsWithPing(int $serverId, string $inboundType = 'vless'): array
    {
        // دریافت لینک سابسکریپشن از سرور
        $stmt = pdo()->prepare("SELECT url, sub_host FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();
        
        if (!$server) {
            return [];
        }
        
        // ساخت لینک سابسکریپشن
        $baseUrl = !empty($server['sub_host']) ? rtrim($server['sub_host'], '/') : rtrim($server['url'], '/');
        
        // دریافت لینک سابسکریپشن از تنظیمات یا استفاده از یک لینک نمونه
        $settings = getSettings();
        $subscriptionUrl = $settings['speedtest_subscription_url'] ?? null;
        
        if (!$subscriptionUrl) {
            // اگر لینک در تنظیمات نیست، از سرویس‌های کاربر استفاده کن
            $services = getUserServices($_SESSION['user_id'] ?? 0);
            foreach ($services as $service) {
                if ($service['server_id'] == $serverId && !empty($service['sub_url'])) {
                    $subscriptionUrl = $service['sub_url'];
                    break;
                }
            }
        }
        
        if (!$subscriptionUrl) {
            return [];
        }
        
        // دریافت تمامی کانفیگ‌ها
        $configs = $this->getAllConfigsFromSubscription($subscriptionUrl, $inboundType);
        
        // تست پینگ برای هر کانفیگ
        $configsWithPing = [];
        foreach ($configs as $config) {
            $ping = $this->pingHost($config['host'], $config['port']);
            $config['ping'] = $ping;
            $configsWithPing[] = $config;
        }
        
        // مرتب‌سازی نتایج
        usort($configsWithPing, function($a, $b) {
            $pingA = $a['ping'] ?? 9999;
            $pingB = $b['ping'] ?? 9999;
            return $pingA <=> $pingB;
        });
        
        return $configsWithPing;
    }

    /**
     * ایجاد لینک تست سرعت برای سرور
     */
    public function generateSpeedTestLink(int $serverId, string $username): ?string
    {
        $stmt = pdo()->prepare("SELECT url, type FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();
        
        if (!$server) {
            return null;
        }
        
        // برای تست سرعت، از سرویس‌های رایگان مثل speedtest.net استفاده می‌کنیم
        return "https://www.speedtest.net/";
    }

    /**
     * دریافت نتایج تست سرعت از سرور
     */
    public function getSpeedTestResults(int $serverId): array
    {
        return [
            'server_id' => $serverId,
            'download_speed' => 0,
            'upload_speed' => 0,
            'ping' => 0,
            'tested_at' => date('Y-m-d H:i:s')
        ];
    }
}
