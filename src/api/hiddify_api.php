<?php

/**
 * API Integration برای پنل Hiddify
 * پشتیبانی از ایجاد، حذف و مدیریت کاربران
 * مستندات: https://www.hiddify.com/
 */

function hiddifyApiRequest($endpoint, $server_id, $method = 'GET', $data = []) {
    $stmt = pdo()->prepare("SELECT * FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();

    if (!$server_info) {
        error_log("Hiddify server with ID {$server_id} not found.");
        return ['error' => 'Hiddify server is not configured.'];
    }

    $url = rtrim($server_info['url'], '/') . $endpoint;
    $api_key = $server_info['password'] ?? ''; // API Key (secret_code) در فیلد password ذخیره می‌شود
    
    if (empty($api_key)) {
        return ['error' => 'Hiddify API key is not configured.'];
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Hiddify-API-Key: ' . $api_key
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    switch ($method) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            break;
        case 'PATCH':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log("Hiddify API cURL error for server {$server_id}: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $decoded = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return $decoded;
    } else {
        error_log("Hiddify API error: HTTP {$httpCode} - " . $response);
        return false;
    }
}

function getHiddifyUser($username, $server_id) {
    // دریافت لیست همه کاربران و جستجوی کاربر مورد نظر
    $response = hiddifyApiRequest('/api/v2/admin/user/', $server_id, 'GET');
    
    if (isset($response['error']) || !$response || !is_array($response)) {
        error_log("Hiddify: Failed to get users list for server {$server_id}");
        return false;
    }
    
    // جستجوی کاربر با نام
    foreach ($response as $user) {
        if (!isset($user['name'])) {
            continue;
        }
        
        if ($user['name'] === $username) {
            // تبدیل داده‌ها به فرمت استاندارد
            $data_limit = 0;
            if (isset($user['package_days']) && $user['package_days'] > 0) {
                // اگر package_days وجود دارد، ممکن است حجم محدود باشد
                // در هیدیفای، حجم ممکن است به bytes یا GB باشد
                $data_limit = isset($user['data_limit']) ? $user['data_limit'] : 0;
            } elseif (isset($user['data_limit']) && $user['data_limit'] > 0) {
                $data_limit = $user['data_limit'];
            }
            
            $expire = 0;
            if (isset($user['expire']) && $user['expire'] > 0) {
                $expire = is_numeric($user['expire']) ? $user['expire'] : strtotime($user['expire']);
            }
            
            $used_traffic = 0;
            if (isset($user['used_traffic'])) {
                $used_traffic = $user['used_traffic'];
            } elseif (isset($user['usage'])) {
                $used_traffic = $user['usage'];
            }
            
            return [
                'username' => $user['name'],
                'data_limit' => $data_limit,
                'used_traffic' => $used_traffic,
                'expire' => $expire,
                'status' => (isset($user['enable']) && $user['enable']) ? 'active' : 'inactive',
                'subscription_url' => $user['subscription_url'] ?? $user['link'] ?? '',
                'uuid' => $user['uuid'] ?? null,
            ];
        }
    }
    
    return false;
}

function createHiddifyUser($plan, $chat_id, $plan_id) {
    $username = $plan['full_username'] ?? 'user_' . $chat_id . '_' . time();
    
    // پشتیبانی از حجم نامحدود (اگر volume_gb صفر یا null باشد)
    $data_limit = 0; // 0 به معنای نامحدود در هیدیفای
    if (!empty($plan['volume_gb']) && $plan['volume_gb'] > 0) {
        // در هیدیفای، حجم به bytes است
        $data_limit = $plan['volume_gb'] * 1024 * 1024 * 1024;
    }
    
    // پشتیبانی از زمان نامحدود (اگر duration_days صفر یا null باشد)
    $expire_timestamp = 0; // 0 یا null به معنای نامحدود در هیدیفای
    if (!empty($plan['duration_days']) && $plan['duration_days'] > 0) {
        $expire_timestamp = time() + ($plan['duration_days'] * 24 * 60 * 60);
    }
    
    $user_data = [
        'name' => $username,
        'enable' => true,
    ];
    
    // فقط در صورت محدود بودن، فیلدها را اضافه می‌کنیم
    if ($data_limit > 0) {
        $user_data['data_limit'] = $data_limit;
    }
    
    if ($expire_timestamp > 0) {
        $user_data['expire'] = $expire_timestamp;
    }
    
    $response = hiddifyApiRequest('/api/v2/admin/user/', $plan['server_id'], 'POST', $user_data);
    
    if (isset($response['error']) || !$response) {
        error_log("Hiddify: Failed to create user. Response: " . json_encode($response));
        return ['error' => 'Failed to create user', 'details' => isset($response['error']) ? $response['error'] : 'Unknown error'];
    }
    
    // اگر پاسخ موفقیت‌آمیز باشد، اطلاعات کاربر را برگردان
    $created_user = is_array($response) && isset($response['name']) ? $response : null;
    
    // اگر کاربر ایجاد شد، اطلاعات subscription را دریافت کن
    $subscription_url = '';
    if ($created_user && isset($created_user['subscription_url'])) {
        $subscription_url = $created_user['subscription_url'];
    } elseif ($created_user && isset($created_user['link'])) {
        $subscription_url = $created_user['link'];
    }
    
    return [
        'username' => $created_user['name'] ?? $username,
        'subscription_url' => $subscription_url,
        'expire' => $expire_timestamp,
        'expire_date' => $expire_timestamp > 0 ? date('Y-m-d H:i:s', $expire_timestamp) : 'نامحدود'
    ];
}

function deleteHiddifyUser($username, $server_id) {
    // دریافت اطلاعات کاربر برای دریافت UUID
    $user_data = getHiddifyUser($username, $server_id);
    
    if (!$user_data || !isset($user_data['uuid'])) {
        error_log("Hiddify: User not found for delete: {$username}");
        return false;
    }
    
    $uuid = $user_data['uuid'];
    $response = hiddifyApiRequest("/api/v2/admin/user/{$uuid}/", $server_id, 'DELETE');
    
    // در هیدیفای، DELETE ممکن است پاسخ خالی برگرداند
    return !isset($response['error']);
}

function updateHiddifyUser($username, $server_id, $data) {
    // دریافت اطلاعات کاربر برای دریافت UUID
    $user_data = getHiddifyUser($username, $server_id);
    
    if (!$user_data || !isset($user_data['uuid'])) {
        error_log("Hiddify: User not found for update: {$username}");
        return false;
    }
    
    $uuid = $user_data['uuid'];
    
    // تبدیل data_limit از bytes به bytes (اگر لازم باشد)
    $update_data = [];
    if (isset($data['data_limit'])) {
        $update_data['data_limit'] = $data['data_limit'];
    }
    if (isset($data['expire'])) {
        $update_data['expire'] = $data['expire'];
    }
    if (isset($data['enable'])) {
        $update_data['enable'] = $data['enable'];
    }
    
    $response = hiddifyApiRequest("/api/v2/admin/user/{$uuid}/", $server_id, 'PATCH', $update_data);
    
    return !isset($response['error']);
}

function getHiddifyUsers($server_id) {
    $response = hiddifyApiRequest('/api/v2/admin/user/', $server_id, 'GET');
    
    if (isset($response['error']) || !$response || !is_array($response)) {
        return [];
    }
    
    return $response;
}

