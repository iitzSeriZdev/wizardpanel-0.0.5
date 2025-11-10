<?php

/**
 * API Integration برای پنل AliReza
 * پشتیبانی از ایجاد، حذف و مدیریت کاربران
 * این پنل از x-ui استفاده می‌کند و از cookie-based authentication استفاده می‌کند
 */

/**
 * دریافت Cookie از پنل علی رضا (x-ui)
 * از cookie file استفاده می‌کند مثل نمونه کد کاربر
 */
function getAlirezaCookie($server_id) {
    // ایجاد مسیر فایل cookie برای این سرور
    $cookie_file = __DIR__ . '/../data/cookies/alireza_' . $server_id . '.txt';
    $cookie_dir = dirname($cookie_file);
    
    // ایجاد پوشه cookies اگر وجود ندارد
    if (!is_dir($cookie_dir)) {
        @mkdir($cookie_dir, 0755, true);
    }
    
    // اگر فایل cookie وجود دارد و جدیدتر از 1 ساعت است، از آن استفاده کن
    if (file_exists($cookie_file) && (time() - filemtime($cookie_file)) < 3600) {
        return $cookie_file;
    }
    
    $stmt = pdo()->prepare("SELECT url, username, password FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();
    
    if (!$server_info) {
        error_log("Alireza: Server with ID {$server_id} not found.");
        return false;
    }
    
    $url = rtrim($server_info['url'], '/') . '/login';
    $postData = [
        'username' => $server_info['username'],
        'password' => $server_info['password']
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_COOKIEFILE => $cookie_file,
        CURLOPT_COOKIEJAR => $cookie_file,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("Alireza: cURL error during login for server {$server_id}: {$curlError}");
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log("Alireza: Login failed for server {$server_id}. HTTP Code: {$httpCode}");
        @unlink($cookie_file); // حذف فایل cookie نامعتبر
        return false;
    }
    
    // بررسی اینکه آیا cookie file ایجاد شده است
    if (!file_exists($cookie_file) || filesize($cookie_file) === 0) {
        error_log("Alireza: Cookie file was not created for server {$server_id}");
        return false;
    }
    
    return $cookie_file;
}

/**
 * درخواست به API علی رضا
 * از cookie file استفاده می‌کند
 */
function alirezaApiRequest($endpoint, $server_id, $method = 'GET', $data = []) {
    $stmt = pdo()->prepare("SELECT url FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_url = $stmt->fetchColumn();
    
    if (!$server_url) {
        error_log("Alireza: Server URL not found for server ID {$server_id}");
        return ['error' => 'Alireza server is not configured.'];
    }
    
    $cookie_file = getAlirezaCookie($server_id);
    if (!$cookie_file) {
        error_log("Alireza: Login failed for server ID {$server_id}");
        return ['error' => 'Login failed'];
    }
    
    $url = rtrim($server_url, '/') . $endpoint;
    $headers = [
        'Accept: application/json'
    ];
    
    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $headers[] = 'Content-Type: application/json';
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $cookie_file,
        CURLOPT_COOKIEJAR => $cookie_file,
        CURLOPT_TIMEOUT => 15,
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
        case 'PATCH':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
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
    curl_close($ch);
    
    if ($curlError) {
        error_log("Alireza API cURL error for server {$server_id}: {$curlError}");
        return ['error' => 'Network error', 'details' => $curlError];
    }
    
    $decoded = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return $decoded ?? $response; // اگر JSON decode نشد، response خام را برگردان
    } else {
        error_log("Alireza API error: HTTP {$httpCode} - " . substr($response, 0, 500));
        return ['error' => 'API error', 'http_code' => $httpCode, 'details' => $decoded ?? $response];
    }
}

/**
 * دریافت اطلاعات کاربر از پنل علی رضا
 */
function getAlirezaUser($username, $server_id) {
    // دریافت لیست inbounds
    $response = alirezaApiRequest('/xui/API/inbounds', $server_id, 'GET');
    
    if (isset($response['error']) || !isset($response['obj'])) {
        error_log("Alireza: Failed to get inbounds for server {$server_id}");
        return false;
    }
    
    $inbounds = $response['obj'];
    if (!is_array($inbounds)) {
        return false;
    }
    
    // جستجوی کاربر در همه inbounds
    foreach ($inbounds as $inbound) {
        if (!isset($inbound['settings'])) {
            continue;
        }
        
        $settings = json_decode($inbound['settings'], true);
        if (!isset($settings['clients']) || !is_array($settings['clients'])) {
            continue;
        }
        
        // جستجو در clients
        foreach ($settings['clients'] as $client) {
            if (isset($client['email']) && $client['email'] === $username) {
                // دریافت آمار کاربر از clientStats
                $clientStats = $inbound['clientStats'] ?? [];
                $userStats = null;
                
                foreach ($clientStats as $stat) {
                    if (isset($stat['email']) && $stat['email'] === $username) {
                        $userStats = $stat;
                        break;
                    }
                }
                
                // محاسبه حجم استفاده شده
                $used_traffic = 0;
                if ($userStats) {
                    $used_traffic = ($userStats['up'] ?? 0) + ($userStats['down'] ?? 0);
                }
                
                // محاسبه حجم کل
                $data_limit = 0;
                if (isset($client['totalGB']) && $client['totalGB'] > 0) {
                    $data_limit = $client['totalGB'] * 1024 * 1024 * 1024; // تبدیل GB به bytes
                }
                
                // محاسبه زمان انقضا
                $expire = 0;
                if (isset($client['expiryTime']) && $client['expiryTime'] > 0) {
                    $expire = floor($client['expiryTime'] / 1000); // تبدیل میلی‌ثانیه به ثانیه
                }
                
                // ساخت subscription URL
                $stmt = pdo()->prepare("SELECT url, sub_host FROM servers WHERE id = ?");
                $stmt->execute([$server_id]);
                $server_info = $stmt->fetch();
                $base_sub_url = !empty($server_info['sub_host']) ? rtrim($server_info['sub_host'], '/') : rtrim($server_info['url'], '/');
                $sub_id = $client['subId'] ?? '';
                $subscription_url = !empty($sub_id) ? $base_sub_url . '/sub/' . $sub_id : '';
                
                return [
                    'username' => $username,
                    'status' => ($client['enable'] ?? true) ? 'active' : 'inactive',
                    'used_traffic' => $used_traffic,
                    'data_limit' => $data_limit,
                    'expire' => $expire,
                    'subscription_url' => $subscription_url,
                    'inbound_id' => $inbound['id'] ?? null,
                    'client_id' => $client['id'] ?? null,
                ];
            }
        }
    }
    
    // کاربر پیدا نشد
    return false;
}

/**
 * ایجاد کاربر جدید در پنل علی رضا
 */
function createAlirezaUser($plan, $chat_id, $plan_id) {
    $server_id = $plan['server_id'];
    $inbound_id = $plan['inbound_id'] ?? null;
    
    // اگر inbound_id در plan نباشد، باید از سرور دریافت کنیم
    if (!$inbound_id) {
        $stmt = pdo()->prepare("SELECT inbound_id FROM servers WHERE id = ?");
        $stmt->execute([$server_id]);
        $inbound_id = $stmt->fetchColumn();
        
        if (!$inbound_id) {
            error_log("Alireza: inbound_id not found for server {$server_id}");
            return ['error' => 'inbound_id is required', 'details' => 'inbound_id is not configured for this server'];
        }
    }
    
    $username = $plan['full_username'];
    $uuid = generateUUID();
    $sub_id = generateUUID(16);
    
    // محاسبه حجم (GB به bytes)
    $totalGB = 0; // 0 به معنای نامحدود
    if (!empty($plan['volume_gb']) && $plan['volume_gb'] > 0) {
        $totalGB = $plan['volume_gb'];
    }
    
    // محاسبه زمان انقضا (ثانیه به میلی‌ثانیه)
    $expiryTime = 0; // 0 به معنای نامحدود
    if (!empty($plan['duration_days']) && $plan['duration_days'] > 0) {
        $expiryTime = (time() + ($plan['duration_days'] * 86400)) * 1000;
    }
    
    // ساخت payload برای ایجاد کاربر
    $config = [
        'id' => (int)$inbound_id,
        'settings' => json_encode([
            'clients' => [
                [
                    'id' => $uuid,
                    'flow' => '',
                    'email' => $username,
                    'totalGB' => $totalGB,
                    'expiryTime' => $expiryTime,
                    'enable' => true,
                    'tgId' => (string)$chat_id,
                    'subId' => $sub_id,
                    'reset' => 0
                ]
            ],
            'decryption' => 'none',
            'fallbacks' => []
        ])
    ];
    
    $response = alirezaApiRequest('/xui/API/inbounds/addClient', $server_id, 'POST', $config);
    
    // بررسی پاسخ API - ممکن است success flag داشته باشد یا نداشته باشد
    if (isset($response['error'])) {
        $error_details = isset($response['details']) ? json_encode($response['details']) : ($response['error'] ?? 'Unknown error');
        error_log("Alireza: Failed to create user. Error: " . json_encode($response));
        return ['error' => 'Failed to create user', 'details' => $error_details];
    }
    
    // اگر success flag وجود دارد و false است، خطا است
    if (isset($response['success']) && $response['success'] === false) {
        $error_details = isset($response['msg']) ? $response['msg'] : (isset($response['details']) ? json_encode($response['details']) : 'Unknown error');
        error_log("Alireza: Failed to create user. Response: " . json_encode($response));
        return ['error' => 'Failed to create user', 'details' => $error_details];
    }
    
    // ساخت subscription URL
    $stmt = pdo()->prepare("SELECT url, sub_host FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();
    $base_sub_url = !empty($server_info['sub_host']) ? rtrim($server_info['sub_host'], '/') : rtrim($server_info['url'], '/');
    $subscription_url = $base_sub_url . '/sub/' . $sub_id;
    
    // دریافت links از subscription URL
    $links = fetchAndParseSubscriptionUrl($subscription_url, $server_id);
    
    return [
        'username' => $username,
        'subscription_url' => $subscription_url,
        'links' => $links,
        'expire' => $expiryTime > 0 ? floor($expiryTime / 1000) : 0,
    ];
}

/**
 * به‌روزرسانی کاربر در پنل علی رضا
 * بر اساس نمونه کد کاربر که از updateClient استفاده می‌کند
 */
function updateAlirezaUser($username, $server_id, $data) {
    $user_data = getAlirezaUser($username, $server_id);
    
    if (!$user_data || !isset($user_data['client_id'])) {
        error_log("Alireza: User not found for update: {$username}");
        return false;
    }
    
    $client_id = $user_data['client_id'];
    $inbound_id = $user_data['inbound_id'];
    
    // دریافت اطلاعات فعلی inbound
    $response = alirezaApiRequest("/xui/API/inbounds/get/{$inbound_id}", $server_id, 'GET');
    
    if (isset($response['error']) || !isset($response['obj'])) {
        error_log("Alireza: Failed to get inbound for update. Response: " . json_encode($response));
        return false;
    }
    
    $inbound = $response['obj'];
    $settings = json_decode($inbound['settings'], true);
    if (!is_array($settings)) {
        error_log("Alireza: Failed to parse inbound settings");
        return false;
    }
    
    $clients = $settings['clients'] ?? [];
    
    // پیدا کردن client و به‌روزرسانی آن
    $client_found = false;
    foreach ($clients as &$client) {
        if (isset($client['id']) && $client['id'] === $client_id) {
            $client_found = true;
            
            // به‌روزرسانی حجم
            if (isset($data['data_limit'])) {
                $client['totalGB'] = $data['data_limit'] > 0 ? ($data['data_limit'] / (1024 * 1024 * 1024)) : 0;
            }
            
            // به‌روزرسانی زمان انقضا
            if (isset($data['expire'])) {
                $client['expiryTime'] = $data['expire'] > 0 ? ($data['expire'] * 1000) : 0;
            }
            
            // به‌روزرسانی وضعیت
            if (isset($data['enable'])) {
                $client['enable'] = (bool)$data['enable'];
            }
            
            break;
        }
    }
    
    if (!$client_found) {
        error_log("Alireza: Client not found in inbound settings for update: {$username}");
        return false;
    }
    
    // به‌روزرسانی settings
    $settings['clients'] = $clients;
    
    // ساخت config برای به‌روزرسانی (مثل نمونه کد کاربر)
    $update_config = [
        'id' => (int)$inbound_id,
        'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)
    ];
    
    // استفاده از endpoint updateClient مطابق نمونه کد کاربر
    $update_response = alirezaApiRequest("/xui/API/inbounds/updateClient/{$client_id}", $server_id, 'POST', $update_config);
    
    if (isset($update_response['error'])) {
        error_log("Alireza: Failed to update user. Error: " . json_encode($update_response['error']));
        return false;
    }
    
    // بررسی success flag در response
    if (isset($update_response['success']) && $update_response['success'] === false) {
        error_log("Alireza: Update user failed. Response: " . json_encode($update_response));
        return false;
    }
    
    // اگر success flag وجود ندارد، اما error هم نیست، احتمالاً موفقیت‌آمیز است
    return true;
}

/**
 * حذف کاربر از پنل علی رضا
 */
function deleteAlirezaUser($username, $server_id) {
    $user_data = getAlirezaUser($username, $server_id);
    
    if (!$user_data || !isset($user_data['client_id']) || !isset($user_data['inbound_id'])) {
        error_log("Alireza: User not found for delete: {$username}");
        return false;
    }
    
    $client_id = $user_data['client_id'];
    $inbound_id = $user_data['inbound_id'];
    
    // حذف کاربر از inbound
    $response = alirezaApiRequest("/xui/API/inbounds/{$inbound_id}/delClient/{$client_id}", $server_id, 'POST', []);
    
    // بررسی پاسخ API
    if (isset($response['error'])) {
        error_log("Alireza: Failed to delete user. Error: " . json_encode($response['error']));
        return false;
    }
    
    // اگر success flag وجود دارد و false است، خطا است
    if (isset($response['success']) && $response['success'] === false) {
        error_log("Alireza: Failed to delete user. Response: " . json_encode($response));
        return false;
    }
    
    // اگر error وجود ندارد و success flag هم false نیست، احتمالاً موفقیت‌آمیز است
    return true;
}

// تابع generateUUID در sanaei_api.php تعریف شده است

