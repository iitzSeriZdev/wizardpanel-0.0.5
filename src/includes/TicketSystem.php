<?php

/**
 * سیستم تیکتینگ حرفه‌ای
 * شامل اولویت، دسته‌بندی، انتساب به ادمین، و مدیریت پیشرفته
 */
class TicketSystem
{
    private static ?TicketSystem $instance = null;
    private Logger $logger;

    private function __construct()
    {
        $this->logger = Logger::getInstance();
    }

    public static function getInstance(): TicketSystem
    {
        if (self::$instance === null) {
            self::$instance = new TicketSystem();
        }
        return self::$instance;
    }

    /**
     * ایجاد تیکت جدید
     */
    public function createTicket(int $userId, string $userName, string $subject, string $category = 'general', string $priority = 'normal'): array
    {
        $ticketId = 'TICKET-' . time() . '-' . rand(1000, 9999);
        
        try {
            $stmt = pdo()->prepare("INSERT INTO tickets (id, user_id, user_name, subject, status, priority, category, created_at) VALUES (?, ?, ?, ?, 'open', ?, ?, NOW())");
            $stmt->execute([$ticketId, $userId, $userName, $subject, $priority, $category]);
            
            // ارسال لاگ
            if (class_exists('LogManager')) {
                $logManager = LogManager::getInstance();
                $logManager->logAdminAction($userId, "تیکت جدید ایجاد شد: {$ticketId}", [
                    'ticket_id' => $ticketId,
                    'subject' => $subject,
                    'category' => $category,
                    'priority' => $priority
                ]);
            }
            
            return ['success' => true, 'ticket_id' => $ticketId];
        } catch (Exception $e) {
            $this->logger->error("Error creating ticket", ['error' => $e->getMessage(), 'user_id' => $userId]);
            return ['success' => false, 'error' => 'خطا در ایجاد تیکت.'];
        }
    }

    /**
     * افزودن پیام به تیکت
     */
    public function addMessage(string $ticketId, string $sender, int $senderId, string $message, array $attachments = []): bool
    {
        try {
            $stmt = pdo()->prepare("INSERT INTO ticket_conversations (ticket_id, sender, sender_id, message_text, sent_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$ticketId, $sender, $senderId, $message]);
            $conversationId = pdo()->lastInsertId();
            
            // افزودن فایل‌های ضمیمه
            if (!empty($attachments)) {
                foreach ($attachments as $attachment) {
                    $stmt_attach = pdo()->prepare("INSERT INTO ticket_attachments (ticket_id, conversation_id, file_id, file_type, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmt_attach->execute([$ticketId, $conversationId, $attachment['file_id'], $attachment['file_type']]);
                }
            }
            
            // به‌روزرسانی updated_at تیکت
            $stmt_update = pdo()->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?");
            $stmt_update->execute([$ticketId]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Error adding message to ticket", ['error' => $e->getMessage(), 'ticket_id' => $ticketId]);
            return false;
        }
    }

    /**
     * دریافت اطلاعات تیکت
     */
    public function getTicket(string $ticketId): ?array
    {
        $stmt = pdo()->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ticket) {
            $ticket['conversations'] = $this->getTicketConversations($ticketId);
        }
        
        return $ticket ?: null;
    }

    /**
     * دریافت مکالمات تیکت
     */
    public function getTicketConversations(string $ticketId): array
    {
        $stmt = pdo()->prepare("SELECT tc.*, ta.file_id, ta.file_type FROM ticket_conversations tc LEFT JOIN ticket_attachments ta ON tc.id = ta.conversation_id WHERE tc.ticket_id = ? ORDER BY tc.sent_at ASC");
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * تغییر وضعیت تیکت
     */
    public function updateTicketStatus(string $ticketId, string $status): bool
    {
        try {
            $stmt = pdo()->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $ticketId]);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Error updating ticket status", ['error' => $e->getMessage(), 'ticket_id' => $ticketId]);
            return false;
        }
    }

    /**
     * تغییر اولویت تیکت
     */
    public function updateTicketPriority(string $ticketId, string $priority): bool
    {
        try {
            $stmt = pdo()->prepare("UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$priority, $ticketId]);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Error updating ticket priority", ['error' => $e->getMessage(), 'ticket_id' => $ticketId]);
            return false;
        }
    }

    /**
     * انتساب تیکت به ادمین
     */
    public function assignTicket(string $ticketId, int $adminId): bool
    {
        try {
            $stmt = pdo()->prepare("UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$adminId, $ticketId]);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Error assigning ticket", ['error' => $e->getMessage(), 'ticket_id' => $ticketId]);
            return false;
        }
    }

    /**
     * دریافت لیست تیکت‌ها
     */
    public function getTickets(array $filters = []): array
    {
        $sql = "SELECT * FROM tickets WHERE 1=1";
        $params = [];
        
        if (isset($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }
        
        if (isset($filters['priority'])) {
            $sql .= " AND priority = ?";
            $params[] = $filters['priority'];
        }
        
        if (isset($filters['category'])) {
            $sql .= " AND category = ?";
            $params[] = $filters['category'];
        }
        
        if (isset($filters['assigned_to'])) {
            $sql .= " AND assigned_to = ?";
            $params[] = $filters['assigned_to'];
        }
        
        if (isset($filters['user_id'])) {
            $sql .= " AND user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        $sql .= " ORDER BY 
            CASE priority
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'low' THEN 4
            END,
            updated_at DESC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * دریافت آمار تیکت‌ها
     */
    public function getTicketStats(): array
    {
        $stats = [];
        
        // تیکت‌های باز
        $stmt = pdo()->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'");
        $stats['open'] = $stmt->fetchColumn();
        
        // تیکت‌های بسته
        $stmt = pdo()->query("SELECT COUNT(*) FROM tickets WHERE status = 'closed'");
        $stats['closed'] = $stmt->fetchColumn();
        
        // تیکت‌های در حال بررسی
        $stmt = pdo()->query("SELECT COUNT(*) FROM tickets WHERE status = 'pending'");
        $stats['pending'] = $stmt->fetchColumn();
        
        // تیکت‌های با اولویت بالا
        $stmt = pdo()->query("SELECT COUNT(*) FROM tickets WHERE priority IN ('critical', 'high') AND status = 'open'");
        $stats['high_priority'] = $stmt->fetchColumn();
        
        // تیکت‌های امروز
        $stmt = pdo()->query("SELECT COUNT(*) FROM tickets WHERE DATE(created_at) = CURDATE()");
        $stats['today'] = $stmt->fetchColumn();
        
        return $stats;
    }

    /**
     * دریافت دسته‌بندی‌های تیکت
     */
    public function getTicketCategories(): array
    {
        return [
            'general' => 'عمومی',
            'technical' => 'فنی',
            'billing' => 'مالی',
            'account' => 'حساب کاربری',
            'service' => 'سرویس',
            'other' => 'سایر'
        ];
    }

    /**
     * دریافت اولویت‌های تیکت
     */
    public function getTicketPriorities(): array
    {
        return [
            'low' => 'کم',
            'normal' => 'عادی',
            'high' => 'بالا',
            'critical' => 'بحرانی'
        ];
    }

    /**
     * ارسال اعلان به ادمین‌ها برای تیکت جدید
     */
    public function notifyAdmins(string $ticketId, string $subject, string $priority = 'normal'): void
    {
        $priorityIcons = [
            'low' => '🟢',
            'normal' => '🟡',
            'high' => '🟠',
            'critical' => '🔴'
        ];
        
        $icon = $priorityIcons[$priority] ?? '🟡';
        
        $message = "{$icon} <b>تیکت جدید</b>\n\n";
        $message .= "🆔 شناسه: <code>{$ticketId}</code>\n";
        $message .= "📝 موضوع: {$subject}\n";
        $message .= "⚡ اولویت: {$priority}\n\n";
        $message .= "برای مشاهده تیکت، از منوی پشتیبانی استفاده کنید.";
        
        $admins = getAdmins();
        foreach ($admins as $adminId => $adminData) {
            if (hasPermission($adminId, 'view_tickets')) {
                sendMessage($adminId, $message);
            }
        }
    }
}

