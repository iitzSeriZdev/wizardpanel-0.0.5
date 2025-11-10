<?php

/**
 * API Integration برای پنل PasarGuard (PasarGuard VPN Panel)
 * پشتیبانی از ایجاد، حذف و مدیریت کاربران
 * مستندات: https://docs.pasarguard.org/fa/node/api/
 */

/**
 * درخواست به API پاسارگاد
 */
function pasargadApiRequest($endpoint, $server_id, $method = 'GET', $data = []) {
    $stmt = pdo()->prepare("SELECT * FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();

    if (!$server_info) {
        error_log("Pasargad: Server with ID {$server_id} not found.");
        return ['error' => 'Pasargad server is not configured.', 'details' => "Server ID {$server_id} not found"];
    }

    // ساخت URL کامل
    $base_url = rtrim($server_info['url'], '/');
    
    // بررسی اینکه URL سرور معتبر است
    if (empty($base_url)) {
        error_log("Pasargad: Empty server URL for server ID {$server_id}");
        return ['error' => 'Server URL is empty', 'details' => "Server URL is not configured for server ID {$server_id}"];
    }
    
    // اگر URL با http:// یا https:// شروع نمی‌شود، اضافه کن
    if (!preg_match('/^https?:\/\//', $base_url)) {
        $base_url = 'https://' . $base_url;
        error_log("Pasargad: Added https:// prefix to server URL: {$base_url}");
    }
    
    $url = $base_url . $endpoint;
    
    // لاگ URL برای دیباگ
    error_log("Pasargad: Request URL: {$url} (Method: {$method})");
    
    $username = $server_info['username'] ?? '';
    $password = $server_info['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        error_log("Pasargad: Credentials not configured for server ID {$server_id}.");
        return ['error' => 'Pasargad credentials are not configured.', 'details' => 'Username or password is empty'];
    }

    // دریافت Token
    $token = getPasargadToken($server_id, $username, $password);
    if (!$token) {
        error_log("Pasargad: Failed to authenticate for server ID {$server_id}.");
        return ['error' => 'Failed to authenticate with Pasargad panel.', 'details' => 'Token retrieval failed'];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    switch ($method) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    if ($curlErrno) {
        error_log("Pasargad API cURL error for server {$server_id}: [{$curlErrno}] {$curlError}");
        return ['error' => 'Network error', 'details' => "cURL error: {$curlError}"];
    }

    // بررسی پاسخ خالی - برخی API ها در DELETE یا PUT ممکن است پاسخ خالی برگردانند
    if (empty($response)) {
        // اگر HTTP code موفق است (200-299) و method DELETE یا PUT است، ممکن است موفقیت‌آمیز باشد
        if ($httpCode >= 200 && $httpCode < 300 && in_array($method, ['DELETE', 'PUT'])) {
            error_log("Pasargad API: Empty response but HTTP {$httpCode} for {$method} - Endpoint: {$endpoint} - This might be successful");
            return ['success' => true, 'http_code' => $httpCode];
        }
        // اگر GET یا POST است و پاسخ خالی است، احتمالاً خطا است
        error_log("Pasargad API: Empty response from server {$server_id} for endpoint {$endpoint} - HTTP {$httpCode} - Method: {$method}");
        return ['error' => 'Empty response from server', 'details' => "HTTP {$httpCode}", 'http_code' => $httpCode];
    }

    $decoded = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Pasargad API: JSON decode error for server {$server_id}: " . json_last_error_msg());
        error_log("Pasargad API: Raw response (first 500 chars): " . substr($response, 0, 500));
        
        // اگر HTTP code موفق است اما JSON decode failed، ممکن است پاسخ خالی یا متن ساده باشد
        if ($httpCode >= 200 && $httpCode < 300) {
            // برای DELETE و PUT، پاسخ خالی ممکن است موفقیت‌آمیز باشد
            if (in_array($method, ['DELETE', 'PUT'])) {
                error_log("Pasargad API: HTTP code is successful but JSON decode failed for {$method} - Assuming success");
                return ['success' => true, 'http_code' => $httpCode, 'raw_response' => substr($response, 0, 200)];
            }
        }
        
        return ['error' => 'Invalid JSON response', 'details' => json_last_error_msg(), 'http_code' => $httpCode];
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        // اگر پاسخ خالی است اما HTTP code موفق است، ممکن است عملیات موفق باشد
        // این معمولاً برای DELETE و PUT رخ می‌دهد
        if (empty($decoded)) {
            error_log("Pasargad API: Empty decoded response but HTTP {$httpCode} - Endpoint: {$endpoint} - Method: {$method} - Assuming success");
            return ['success' => true, 'http_code' => $httpCode];
        }
        return $decoded;
    } else {
        $errorMsg = isset($decoded['message']) ? $decoded['message'] : (isset($decoded['error']) ? $decoded['error'] : 'Unknown error');
        $errorDetails = isset($decoded['errors']) ? json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE) : (isset($decoded['detail']) ? $decoded['detail'] : '');
        error_log("Pasargad API error: HTTP {$httpCode} - Endpoint: {$endpoint} - Method: {$method} - Error: {$errorMsg}");
        if ($errorDetails) {
            error_log("Pasargad API: Error details: {$errorDetails}");
        }
        error_log("Pasargad API: Full response (first 1000 chars): " . substr($response, 0, 1000));
        return ['error' => $errorMsg, 'http_code' => $httpCode, 'details' => $decoded, 'error_details' => $errorDetails];
    }
}

/**
 * دریافت Token احراز هویت از پنل پاسارگاد
 */
function getPasargadToken($server_id, $username, $password) {
    $cache_key = 'pasargad_token_' . $server_id;
    $current_time = time();

    // بررسی cache
    $stmt_cache = pdo()->prepare("SELECT cache_value FROM cache WHERE cache_key = ? AND expire_at > ?");
    $stmt_cache->execute([$cache_key, $current_time]);
    $cached_token = $stmt_cache->fetchColumn();
    if ($cached_token) {
        return $cached_token;
    }

    // دریافت URL سرور
    $stmt = pdo()->prepare("SELECT url FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_url = $stmt->fetchColumn();
    
    if (!$server_url) {
        error_log("Pasargad: Server URL not found for server ID {$server_id}");
        return false;
    }
    
    // پاک‌سازی و اعتبارسنجی URL
    $server_url = rtrim($server_url, '/');
    
    // اگر URL با http:// یا https:// شروع نمی‌شود، اضافه کن
    if (!preg_match('/^https?:\/\//', $server_url)) {
        $server_url = 'https://' . $server_url;
        error_log("Pasargad: Added https:// prefix to server URL for token: {$server_url}");
    }
    
    // endpoint احراز هویت - ممکن است `/api/auth/login` یا `/api/admin/token` باشد
    // بر اساس مستندات پاسارگاد: https://docs.pasarguard.org/fa/node/api/
    $login_endpoints = [
        '/api/auth/login',
        '/api/admin/token',
        '/api/v1/auth/login',
        '/auth/login',
        '/admin/token',
        '/v1/auth/login',
        '/api/login',
        '/login'
    ];
    
    $token = false;
    $last_error = '';
    
    foreach ($login_endpoints as $endpoint) {
        $url = rtrim($server_url, '/') . $endpoint;
        
        // برخی پنل‌ها از form-data استفاده می‌کنند
        $login_data_json = json_encode([
            'username' => $username,
            'password' => $password
        ]);
        
        $login_data_form = http_build_query([
            'username' => $username,
            'password' => $password
        ]);

        // تلاش با JSON
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $login_data_json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && !empty($response)) {
            $data = json_decode($response, true);
            
            // بررسی انواع مختلف پاسخ token
            if (isset($data['token'])) {
                $token = $data['token'];
            } elseif (isset($data['access_token'])) {
                $token = $data['access_token'];
            } elseif (isset($data['data']['token'])) {
                $token = $data['data']['token'];
            } elseif (isset($data['data']['access_token'])) {
                $token = $data['data']['access_token'];
            }
            
            if ($token) {
                // ذخیره در cache - token معمولاً 1 ساعت اعتبار دارد
                $expire_time = time() + 3500; // 58 دقیقه برای اطمینان
                if (isset($data['expires_in'])) {
                    $expire_time = time() + $data['expires_in'] - 60; // 1 دقیقه قبل از انقضا
                }
                
                try {
                    $stmt_insert_cache = pdo()->prepare("INSERT INTO cache (cache_key, cache_value, expire_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expire_at = VALUES(expire_at)");
                    $stmt_insert_cache->execute([$cache_key, $token, $expire_time]);
                } catch (Exception $e) {
                    error_log("Pasargad: Failed to cache token: " . $e->getMessage());
                }
                
                return $token;
            }
        }
        
        // اگر JSON کار نکرد، با form-data امتحان کن
        if (!$token && $endpoint === '/api/admin/token') {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $login_data_form,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && !empty($response)) {
                $data = json_decode($response, true);
                
                if (isset($data['token'])) {
                    $token = $data['token'];
                } elseif (isset($data['access_token'])) {
                    $token = $data['access_token'];
                }
                
                if ($token) {
                    $expire_time = time() + 3500;
                    try {
                        $stmt_insert_cache = pdo()->prepare("INSERT INTO cache (cache_key, cache_value, expire_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expire_at = VALUES(expire_at)");
                        $stmt_insert_cache->execute([$cache_key, $token, $expire_time]);
                    } catch (Exception $e) {
                        error_log("Pasargad: Failed to cache token: " . $e->getMessage());
                    }
                    
                    return $token;
                }
            }
        }
        
        if (!$token) {
            $last_error = "HTTP {$httpCode}: " . substr($response, 0, 200);
        }
    }
    
    error_log("Pasargad: Failed to get token for server ID {$server_id}. Last error: {$last_error}");
    return false;
}

/**
 * دریافت اطلاعات کاربر از پنل پاسارگاد
 */
function getPasargadUser($username, $server_id) {
    // تلاش با endpoint های مختلف
    $endpoints = [
        "/api/users/{$username}",
        "/api/v1/users/{$username}",
        "/api/admin/users/{$username}",
        "/api/user/{$username}"
    ];
    
    foreach ($endpoints as $endpoint) {
        $response = pasargadApiRequest($endpoint, $server_id, 'GET');
        
        if ($response && !isset($response['error'])) {
            // تبدیل expire به timestamp اگر string باشد
            $expire = null;
            $user_data = $response;
            
            // بررسی ساختارهای مختلف پاسخ
            if (isset($response['data'])) {
                $user_data = $response['data'];
            } elseif (isset($response['user'])) {
                $user_data = $response['user'];
            }
            
            if (isset($user_data['expire'])) {
                if (is_numeric($user_data['expire'])) {
                    $expire = (int)$user_data['expire'];
                } elseif (is_string($user_data['expire'])) {
                    $expire = strtotime($user_data['expire']);
                }
            }
            
            return [
                'username' => $user_data['username'] ?? $username,
                'data_limit' => isset($user_data['data_limit']) ? (int)$user_data['data_limit'] : 0,
                'data_used' => isset($user_data['data_used']) ? (int)$user_data['data_used'] : 0,
                'expire' => $expire,
                'status' => $user_data['status'] ?? 'active',
                'subscription_url' => $user_data['subscription_url'] ?? $user_data['sub_url'] ?? $response['subscription_url'] ?? $response['sub_url'] ?? ''
            ];
        }
        
        // اگر خطای 404 است، endpoint بعدی را امتحان کن
        if ($response && isset($response['http_code']) && $response['http_code'] == 404) {
            continue;
        }
        
        // اگر خطای دیگری است، endpoint بعدی را امتحان کن
        if ($response && isset($response['error'])) {
            error_log("Pasargad: Failed to get user from endpoint {$endpoint} - Error: {$response['error']}");
            continue;
        }
    }
    
    return false;
}

/**
 * ایجاد کاربر جدید در پنل پاسارگاد
 * بر اساس نمونه کد مرزبان - استفاده از ساختار ساده و مستقیم
 */
function createPasargadUser($plan, $chat_id, $plan_id) {
    // بررسی اینکه plan معتبر است
    if (empty($plan) || !is_array($plan)) {
        error_log("Pasargad: Invalid plan data provided");
        return ['error' => 'Invalid plan data', 'details' => 'Plan data is empty or invalid'];
    }
    
    // بررسی server_id
    if (empty($plan['server_id'])) {
        error_log("Pasargad: Server ID is missing in plan data");
        return ['error' => 'Server ID is missing', 'details' => 'Server ID is not set in plan data'];
    }
    
    // دریافت اطلاعات سرور
    $stmt = pdo()->prepare("SELECT * FROM servers WHERE id = ?");
    $stmt->execute([$plan['server_id']]);
    $server_info = $stmt->fetch();
    
    if (!$server_info) {
        error_log("Pasargad: Server not found for server ID {$plan['server_id']}");
        return ['error' => 'Server not found', 'details' => "Server ID {$plan['server_id']} not found"];
    }
    
    $server_url = rtrim($server_info['url'], '/');
    if (!preg_match('/^https?:\/\//', $server_url)) {
        $server_url = 'https://' . $server_url;
    }
    
    // دریافت Token
    $username = $server_info['username'] ?? '';
    $password = $server_info['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        error_log("Pasargad: Credentials not configured for server ID {$plan['server_id']}");
        return ['error' => 'Credentials not configured', 'details' => 'Username or password is empty'];
    }
    
    $token = getPasargadToken($plan['server_id'], $username, $password);
    if (!$token) {
        error_log("Pasargad: Failed to authenticate for server ID {$plan['server_id']}");
        return ['error' => 'Failed to authenticate', 'details' => 'Token retrieval failed'];
    }
    
    // دریافت username از plan
    $user_username = $plan['full_username'] ?? 'user_' . $chat_id . '_' . time();
    
    // پاک‌سازی username
    $user_username = preg_replace('/[^a-zA-Z0-9_-]/', '', $user_username);
    if (empty($user_username)) {
        $user_username = 'user_' . $chat_id . '_' . time();
    }
    if (strlen($user_username) > 50) {
        $user_username = substr($user_username, 0, 50);
    }
    
    // محاسبه data_limit (bytes) - بر اساس نمونه کد پاسارگاد
    $volume_gb = isset($plan['volume_gb']) ? (float)$plan['volume_gb'] : 0;
    $data_limit = 0; // 0 به معنای نامحدود
    if ($volume_gb > 0) {
        $data_limit = (int)($volume_gb * 1024 * 1024 * 1024); // تبدیل GB به bytes
    }
    
    // محاسبه expire (timestamp یا null) - بر اساس نمونه کد پاسارگاد
    $duration_days = isset($plan['duration_days']) ? (int)$plan['duration_days'] : 0;
    $expire = null;
    if ($duration_days > 0) {
        $expire = time() + ($duration_days * 24 * 60 * 60); // timestamp
    }
    
    // تولید UUID برای proxy_settings
    require_once __DIR__ . '/sanaei_api.php'; // برای استفاده از generateUUID
    if (!function_exists('generateUUID')) {
        error_log("Pasargad: generateUUID function not found");
        return ['error' => 'UUID generation function not available', 'details' => 'generateUUID function is required'];
    }
    
    $vmess_uuid = generateUUID();
    $vless_uuid = generateUUID();
    
    // تولید password برای trojan و shadowsocks
    if (!function_exists('generateRandomString')) {
        function generateRandomString($length = 16) {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[rand(0, strlen($characters) - 1)];
            }
            return $randomString;
        }
    }
    
    $trojan_password = generateRandomString(16);
    $shadowsocks_password = generateRandomString(16);
    
    // ساختار داده کاربر - بر اساس نمونه کد پاسارگاد
    $userData = [
        'username' => $user_username,
        'status' => $plan['status'] ?? 'active',
        'expire' => $expire,
        'data_limit' => $data_limit > 0 ? $data_limit : null, // null برای نامحدود
        'data_limit_reset_strategy' => $plan['data_limit_reset_strategy'] ?? 'no_reset',
        'note' => $plan['note'] ?? 'Created by Telegram Bot',
        'proxy_settings' => [
            'vmess' => [
                'id' => $vmess_uuid
            ],
            'vless' => [
                'id' => $vless_uuid,
                'flow' => ''
            ],
            'trojan' => [
                'password' => $trojan_password
            ],
            'shadowsocks' => [
                'password' => $shadowsocks_password,
                'method' => 'chacha20-ietf-poly1305'
            ]
        ]
    ];
    
    // اگر expire null است، از payload حذف کن (بر اساس نمونه که expire می‌تواند null باشد)
    if ($expire === null) {
        unset($userData['expire']);
    }
    
    // اگر data_limit 0 است (نامحدود)، از payload حذف کن
    if ($data_limit == 0) {
        unset($userData['data_limit']);
    }
    
    // اضافه کردن فیلدهای دیگر اگر در plan وجود داشته باشند
    if (!empty($plan['email'])) {
        $userData['email'] = $plan['email'];
    }
    
    if (!empty($plan['password'])) {
        $userData['password'] = $plan['password'];
    }
    
    // Groups
    if (!empty($plan['groups']) && is_array($plan['groups'])) {
        $userData['groups'] = $plan['groups'];
    }
    
    // Template ID
    if (!empty($plan['template_id'])) {
        $userData['template_id'] = $plan['template_id'];
    }
    
    // Inbound ID
    if (!empty($plan['inbound_id'])) {
        $userData['inbound_id'] = $plan['inbound_id'];
    }
    
    // Protocol
    if (!empty($plan['protocol'])) {
        $userData['protocol'] = $plan['protocol'];
    }
    
    // Subscription URL - اگر در plan تعریف شده باشد
    if (!empty($plan['subscription_url'])) {
        $userData['subscription_url'] = $plan['subscription_url'];
    }
    
    // لاگ داده‌های ارسالی
    error_log("Pasargad: Creating user '{$user_username}' with data: " . json_encode($userData, JSON_UNESCAPED_UNICODE));
    
    // endpoint های ممکن برای ایجاد کاربر
    $endpoints = [
        '/api/users/create',
        '/api/users',
        '/api/v1/users',
        '/api/admin/users'
    ];
    
    $response = null;
    $last_error = null;
    $successful_endpoint = null;
    $httpCode = 0;
    
    foreach ($endpoints as $endpoint) {
        $url = $server_url . $endpoint;
        error_log("Pasargad: Trying endpoint: {$url}");
        
        // استفاده از cURL برای ارسال درخواست POST به API - مثل نمونه کد
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($userData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response_body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        if ($curlErrno) {
            error_log("Pasargad: cURL error for endpoint {$endpoint}: [{$curlErrno}] {$curlError}");
            $last_error = "cURL error: {$curlError}";
            continue;
        }
        
        // بررسی نتیجه - مثل نمونه کد
        if ($httpCode === 200 || $httpCode === 201) {
            error_log("Pasargad: User created successfully using endpoint: {$endpoint} - HTTP Code: {$httpCode}");
            $successful_endpoint = $endpoint;
            
            // parse response
            $response = json_decode($response_body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // اگر JSON decode نشد، پاسخ را raw نگه دار
                $response = ['success' => true, 'raw_response' => $response_body];
            }
            break;
        } else {
            error_log("Pasargad: Error creating user - HTTP {$httpCode} — {$response_body}");
            $last_error = "HTTP {$httpCode}: " . substr($response_body, 0, 200);
            
            // اگر خطای 404 است، endpoint بعدی را امتحان کن
            if ($httpCode == 404) {
                continue;
            }
            
            // اگر خطای validation است (400 یا 422)، احتمالاً ساختار داده اشتباه است
            if ($httpCode == 400 || $httpCode == 422) {
                error_log("Pasargad: Validation error for endpoint {$endpoint} - Response: {$response_body}");
                // endpoint را تغییر نده، اما خطا را برگردان
                break;
            }
            
            // اگر خطای authentication است (401 یا 403)، احتمالاً مشکل token است
            if ($httpCode == 401 || $httpCode == 403) {
                error_log("Pasargad: Authentication error for endpoint {$endpoint} - Token might be invalid");
                // endpoint را تغییر نده، اما خطا را برگردان
                break;
            }
        }
    }
    
    if (!$successful_endpoint) {
        error_log("Pasargad: Failed to create user - Last error: {$last_error}");
        return ['error' => 'Failed to create user in pasargad panel', 'details' => $last_error ?: 'No successful response from any endpoint', 'http_code' => $httpCode];
    }
    
    // بررسی اینکه پاسخ موفقیت‌آمیز است
    $created_username = null;
    $user_id = null;
    $subscription_url = '';
    
    // بررسی فیلدهای مختلف برای username
    if (isset($response['username'])) {
        $created_username = $response['username'];
    } elseif (isset($response['data']['username'])) {
        $created_username = $response['data']['username'];
    } elseif (isset($response['user']['username'])) {
        $created_username = $response['user']['username'];
    } else {
        $created_username = $user_username;
    }
    
    // بررسی فیلدهای مختلف برای user ID
    if (isset($response['id'])) {
        $user_id = $response['id'];
    } elseif (isset($response['data']['id'])) {
        $user_id = $response['data']['id'];
    } elseif (isset($response['user']['id'])) {
        $user_id = $response['user']['id'];
    }
    
    // دریافت subscription_url
    $subscription_url = $response['subscription_url'] ?? $response['sub_url'] ?? $response['data']['subscription_url'] ?? $response['data']['sub_url'] ?? $response['user']['subscription_url'] ?? $response['user']['sub_url'] ?? '';
    
    // اگر subscription_url خالی است، تلاش برای دریافت از API
    if (empty($subscription_url)) {
        $user_info = getPasargadUser($created_username, $plan['server_id']);
        if ($user_info && !empty($user_info['subscription_url'])) {
            $subscription_url = $user_info['subscription_url'];
        }
    }
    
    // محاسبه expire_timestamp
    $expire_timestamp = null;
    // اولویت: 1) expire که در userData ارسال کردیم, 2) response از API
    if ($expire !== null) {
        $expire_timestamp = $expire;
    } elseif (isset($response['expire'])) {
        if (is_numeric($response['expire'])) {
            $expire_timestamp = (int)$response['expire'];
        } elseif (is_string($response['expire'])) {
            $expire_timestamp = strtotime($response['expire']);
        }
    } elseif (isset($response['data']['expire'])) {
        if (is_numeric($response['data']['expire'])) {
            $expire_timestamp = (int)$response['data']['expire'];
        } elseif (is_string($response['data']['expire'])) {
            $expire_timestamp = strtotime($response['data']['expire']);
        }
    } elseif (isset($response['user']['expire'])) {
        if (is_numeric($response['user']['expire'])) {
            $expire_timestamp = (int)$response['user']['expire'];
        } elseif (is_string($response['user']['expire'])) {
            $expire_timestamp = strtotime($response['user']['expire']);
        }
    }
    
    error_log("Pasargad: User created successfully - Username: {$created_username}, User ID: " . ($user_id ?? 'N/A'));
    
    return [
        'username' => $created_username,
        'subscription_url' => $subscription_url,
        'expire' => $expire_timestamp,
        'expire_date' => $expire_timestamp ? date('Y-m-d H:i:s', $expire_timestamp) : 'نامحدود'
    ];
}

/**
 * حذف کاربر از پنل پاسارگاد
 */
function deletePasargadUser($username, $server_id) {
    // بررسی اینکه username معتبر است
    if (empty($username)) {
        error_log("Pasargad: Username is empty for delete operation");
        return false;
    }
    
    // تلاش با endpoint های مختلف برای delete
    $endpoints = [
        "/api/users/{$username}",
        "/api/v1/users/{$username}",
        "/api/admin/users/{$username}"
    ];
    
    foreach ($endpoints as $endpoint) {
        $response = pasargadApiRequest($endpoint, $server_id, 'DELETE');
        
        if ($response && !isset($response['error'])) {
            error_log("Pasargad: User {$username} deleted successfully using endpoint: {$endpoint}");
            return true;
        }
        
        // اگر پاسخ خالی است اما HTTP code موفق است، موفقیت‌آمیز است
        if ($response && isset($response['success'])) {
            error_log("Pasargad: User {$username} deleted successfully (empty response)");
            return true;
        }
        
        if ($response && isset($response['error'])) {
            $http_code = isset($response['http_code']) ? $response['http_code'] : 'N/A';
            error_log("Pasargad: Failed to delete user {$username} from endpoint {$endpoint} - Error: {$response['error']} - HTTP Code: {$http_code}");
            
            // اگر خطای 404 است، endpoint بعدی را امتحان کن
            if ($http_code == 404) {
                continue;
            }
            
            // اگر خطای دیگری است، خطا را برگردان
            return false;
        }
    }
    
    error_log("Pasargad: Failed to delete user {$username} - No successful response from any endpoint");
    return false;
}

/**
 * به‌روزرسانی اطلاعات کاربر در پنل پاسارگاد
 */
function updatePasargadUser($username, $server_id, $data) {
    // بررسی اینکه داده معتبر است
    if (empty($data) || !is_array($data)) {
        error_log("Pasargad: Invalid data provided for update user {$username}");
        return false;
    }
    
    // بررسی اینکه username معتبر است
    if (empty($username)) {
        error_log("Pasargad: Username is empty for update operation");
        return false;
    }
    
    // تبدیل volume_gb به data_limit اگر نیاز باشد
    if (isset($data['volume_gb'])) {
        $volume_gb = (float)$data['volume_gb'];
        if ($volume_gb > 0) {
            $data['data_limit'] = (int)($volume_gb * 1024 * 1024 * 1024);
            error_log("Pasargad: Updating data_limit to " . number_format($data['data_limit']) . " bytes ({$volume_gb} GB)");
        } else {
            // برای نامحدود، data_limit را حذف می‌کنیم (نه 0)
            // برخی پنل‌ها 0 را به عنوان reset تفسیر می‌کنند
            unset($data['data_limit']);
            error_log("Pasargad: Removing data_limit (unlimited volume)");
        }
        unset($data['volume_gb']);
    }
    
    // تبدیل duration_days به expire اگر نیاز باشد
    if (isset($data['duration_days'])) {
        $duration_days = (int)$data['duration_days'];
        if ($duration_days > 0) {
            $data['expire'] = time() + ($duration_days * 24 * 60 * 60);
            error_log("Pasargad: Updating expire to timestamp: {$data['expire']} (" . date('Y-m-d H:i:s', $data['expire']) . ")");
        } else {
            // برای نامحدود، expire را حذف می‌کنیم (نه 0)
            // برخی پنل‌ها 0 را به عنوان reset تفسیر می‌کنند
            unset($data['expire']);
            error_log("Pasargad: Removing expire (unlimited duration)");
        }
        unset($data['duration_days']);
    }
    
    // اگر داده‌ای برای آپدیت وجود ندارد، true برگردان
    if (empty($data)) {
        error_log("Pasargad: No data to update for user {$username}");
        return true;
    }
    
    // تلاش با endpoint های مختلف برای update
    $endpoints = [
        "/api/users/{$username}",
        "/api/v1/users/{$username}",
        "/api/admin/users/{$username}"
    ];
    
    foreach ($endpoints as $endpoint) {
        error_log("Pasargad: Trying to update user {$username} using endpoint: {$endpoint}");
        $response = pasargadApiRequest($endpoint, $server_id, 'PUT', $data);
        
        if ($response && !isset($response['error'])) {
            error_log("Pasargad: User {$username} updated successfully using endpoint: {$endpoint}");
            return true;
        }
        
        // اگر پاسخ خالی است اما HTTP code موفق است، موفقیت‌آمیز است
        if ($response && isset($response['success'])) {
            error_log("Pasargad: User {$username} updated successfully (empty response)");
            return true;
        }
        
        if ($response && isset($response['error'])) {
            $http_code = isset($response['http_code']) ? $response['http_code'] : 'N/A';
            error_log("Pasargad: Failed to update user {$username} from endpoint {$endpoint} - Error: {$response['error']} - HTTP Code: {$http_code}");
            
            // اگر خطای 404 است، endpoint بعدی را امتحان کن
            if ($http_code == 404) {
                continue;
            }
            
            // اگر خطای دیگری است، خطا را برگردان
            return false;
        }
    }
    
    error_log("Pasargad: Failed to update user {$username} - No successful response from any endpoint");
    return false;
}

/**
 * دریافت لیست کاربران از پنل پاسارگاد
 */
function getPasargadUsers($server_id) {
    $response = pasargadApiRequest('/api/users', $server_id, 'GET');
    
    if (!$response || isset($response['error'])) {
        return [];
    }
    
    // اگر پاسخ یک آرایه است، آن را برمی‌گردانیم
    if (is_array($response)) {
        // اگر پاسخ دارای فیلد 'users' است
        if (isset($response['users']) && is_array($response['users'])) {
            return $response['users'];
        }
        // اگر پاسخ دارای فیلد 'data' است
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }
        // اگر خود پاسخ یک آرایه است
        return $response;
    }
    
    return [];
}
