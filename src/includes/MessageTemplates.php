<?php

/**
 * کلاس مدیریت قالب‌های پیام
 * این کلاس برای یکپارچه‌سازی و مدیریت پیام‌های ربات استفاده می‌شود
 */
class MessageTemplates
{
    /**
     * قالب پیام خوش‌آمدگویی
     */
    public static function welcome(string $firstName, bool $isAdmin = false): string
    {
        if ($isAdmin) {
            return "👑 <b>ادمین عزیز، به پنل مدیریت خوش آمدید.</b>\n\n" .
                   "سلام {$firstName} عزیز! 🌹\n" .
                   "از طریق این پنل می‌توانید تمام بخش‌های ربات را مدیریت کنید.";
        }
        
        return "سلام {$firstName} عزیز!\n" .
               "به ربات فروش کانفیگ خوش آمدید. 🌹\n\n" .
               "از طریق این ربات می‌توانید سرویس‌های VPN خود را خریداری و مدیریت کنید.";
    }

    /**
     * قالب پیام خرید موفق
     */
    public static function purchaseSuccess(array $data): string
    {
        $message = "✅ <b>خرید شما با موفقیت انجام شد.</b>\n\n";
        
        if (isset($data['original_price']) && isset($data['final_price']) && $data['original_price'] != $data['final_price']) {
            $message .= "🏷 قیمت اصلی: " . number_format($data['original_price']) . " تومان\n";
            $message .= "💰 قیمت با تخفیف: <b>" . number_format($data['final_price']) . " تومان</b>\n\n";
        }
        
        $message .= "▫️ نام سرویس: <b>" . htmlspecialchars($data['service_name'] ?? 'نامشخص') . "</b>\n";
        
        if (isset($data['subscription_url'])) {
            $message .= "\n🔗 <b>لینک اشتراک (Subscription):</b>\n" .
                       "<code>" . htmlspecialchars($data['subscription_url']) . "</code>\n";
        }
        
        if (isset($data['balance'])) {
            $message .= "\n💰 موجودی جدید شما: " . number_format($data['balance']) . " تومان";
        }
        
        return $message;
    }

    /**
     * قالب پیام موجودی ناکافی
     */
    public static function insufficientBalance(float $required, float $current): string
    {
        $needed = $required - $current;
        return "❌ <b>موجودی شما کافی نیست!</b>\n\n" .
               "▫️ مبلغ مورد نیاز: " . number_format($required) . " تومان\n" .
               "▫️ موجودی فعلی شما: " . number_format($current) . " تومان\n" .
               "▫️ مبلغ کمبود: <b>" . number_format($needed) . " تومان</b>\n\n" .
               "لطفاً ابتدا حساب خود را شارژ کنید.";
    }

    /**
     * قالب پیام اطلاعات حساب کاربری
     */
    public static function userAccount(array $userData): string
    {
        $servicesCount = $userData['services_count'] ?? 0;
        $balance = $userData['balance'] ?? 0;
        $joinDate = isset($userData['created_at']) ? 
            date('Y/m/d', strtotime($userData['created_at'])) : 'نامشخص';
        
        return "👤 <b>اطلاعات حساب کاربری</b>\n\n" .
               "▫️ نام: " . htmlspecialchars($userData['first_name'] ?? 'کاربر') . "\n" .
               "▫️ شناسه کاربری: <code>" . $userData['chat_id'] . "</code>\n" .
               "▫️ موجودی: <b>" . number_format($balance) . " تومان</b>\n" .
               "▫️ تعداد سرویس‌های فعال: <b>{$servicesCount}</b>\n" .
               "▫️ تاریخ عضویت: {$joinDate}";
    }

    /**
     * قالب پیام لیست سرویس‌ها
     */
    public static function servicesList(array $services): string
    {
        if (empty($services)) {
            return "📭 <b>شما هیچ سرویس فعالی ندارید.</b>\n\n" .
                   "برای خرید سرویس جدید، از منوی اصلی گزینه «🛒 خرید سرویس» را انتخاب کنید.";
        }
        
        $message = "🔧 <b>سرویس‌های شما</b>\n\n";
        
        foreach ($services as $index => $service) {
            $expireDate = isset($service['expire_timestamp']) && $service['expire_timestamp'] > 0 ?
                date('Y/m/d H:i', $service['expire_timestamp']) : 'نامحدود';
            
            $remainingDays = isset($service['expire_timestamp']) && $service['expire_timestamp'] > time() ?
                ceil(($service['expire_timestamp'] - time()) / 86400) : 0;
            
            $volumeUsed = $service['volume_used_gb'] ?? 0;
            $volumeTotal = $service['volume_gb'] ?? 0;
            $volumePercent = $volumeTotal > 0 ? round(($volumeUsed / $volumeTotal) * 100) : 0;
            
            $message .= ($index + 1) . ". <b>" . htmlspecialchars($service['custom_name'] ?? $service['plan_name']) . "</b>\n";
            $message .= "   ▫️ حجم مصرفی: {$volumeUsed} / {$volumeTotal} GB ({$volumePercent}%)\n";
            $message .= "   ▫️ انقضا: {$expireDate}";
            if ($remainingDays > 0) {
                $message .= " ({$remainingDays} روز باقیمانده)";
            }
            $message .= "\n\n";
        }
        
        return $message;
    }

    /**
     * قالب پیام هشدار انقضا
     */
    public static function expirationWarning(array $serviceData): string
    {
        $remainingDays = isset($serviceData['remaining_days']) ? $serviceData['remaining_days'] : 0;
        $remainingGb = isset($serviceData['remaining_gb']) ? round($serviceData['remaining_gb'], 2) : 0;
        
        $message = "⚠️ <b>هشدار انقضا</b>\n\n";
        $message .= "▫️ سرویس: <b>" . htmlspecialchars($serviceData['service_name'] ?? 'نامشخص') . "</b>\n";
        
        if ($remainingDays > 0) {
            $message .= "▫️ زمان باقیمانده: <b>{$remainingDays} روز</b>\n";
        }
        
        if ($remainingGb > 0) {
            $message .= "▫️ حجم باقیمانده: <b>{$remainingGb} GB</b>\n";
        }
        
        $message .= "\nلطفاً جهت تمدید سرویس خود اقدام کنید.";
        
        return $message;
    }

    /**
     * قالب پیام اطلاعات پلن
     */
    public static function planInfo(array $plan): string
    {
        $message = "📦 <b>" . htmlspecialchars($plan['name']) . "</b>\n\n";
        $message .= "▫️ قیمت: <b>" . number_format($plan['price']) . " تومان</b>\n";
        $message .= "▫️ حجم: <b>{$plan['volume_gb']} گیگابایت</b>\n";
        $message .= "▫️ مدت زمان: <b>{$plan['duration_days']} روز</b>\n";
        
        if (!empty($plan['description'])) {
            $message .= "\n📝 <b>توضیحات:</b>\n" . htmlspecialchars($plan['description']);
        }
        
        return $message;
    }

    /**
     * قالب پیام تایید پرداخت
     */
    public static function paymentConfirmation(float $amount, string $method = 'کارت به کارت'): string
    {
        return "✅ <b>پرداخت شما تایید شد!</b>\n\n" .
               "▫️ مبلغ: <b>" . number_format($amount) . " تومان</b>\n" .
               "▫️ روش پرداخت: {$method}\n\n" .
               "مبلغ به حساب شما اضافه شد.";
    }

    /**
     * قالب پیام خطا
     */
    public static function error(string $message, string $code = null): string
    {
        $errorMsg = "❌ <b>خطا!</b>\n\n" . $message;
        if ($code) {
            $errorMsg .= "\n\nکد خطا: <code>{$code}</code>";
        }
        return $errorMsg;
    }

    /**
     * قالب پیام موفقیت
     */
    public static function success(string $message): string
    {
        return "✅ " . $message;
    }

    /**
     * قالب پیام اطلاعات
     */
    public static function info(string $message): string
    {
        return "ℹ️ " . $message;
    }

    /**
     * قالب پیام هشدار
     */
    public static function warning(string $message): string
    {
        return "⚠️ " . $message;
    }

    /**
     * قالب پیام آمار ادمین
     */
    public static function adminStats(array $stats): string
    {
        $message = "📊 <b>آمار کلی ربات</b>\n\n";
        $message .= "▫️ تعداد کاربران: <b>" . number_format($stats['total_users'] ?? 0) . "</b>\n";
        $message .= "▫️ کاربران فعال: <b>" . number_format($stats['active_users'] ?? 0) . "</b>\n";
        $message .= "▫️ تعداد سرویس‌ها: <b>" . number_format($stats['total_services'] ?? 0) . "</b>\n";
        $message .= "▫️ درآمد امروز: <b>" . number_format($stats['income_today'] ?? 0) . " تومان</b>\n";
        $message .= "▫️ درآمد این ماه: <b>" . number_format($stats['income_month'] ?? 0) . " تومان</b>\n";
        
        return $message;
    }
}
