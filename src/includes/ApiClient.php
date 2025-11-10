<?php

class ApiClient
{
    private static ?ApiClient $instance = null;
    private string $baseUrl;
    private int $maxRetries;
    private int $retryDelay;
    private array $rateLimits = [];
    private Logger $logger;

    private function __construct()
    {
        $this->baseUrl = 'https://api.telegram.org/bot' . BOT_TOKEN . '/';
        $this->maxRetries = 3;
        $this->retryDelay = 1;
        $this->logger = Logger::getInstance();
    }

    public static function getInstance(): ApiClient
    {
        if (self::$instance === null) {
            self::$instance = new ApiClient();
        }
        return self::$instance;
    }

    public function request(string $method, array $params = [], int $retryCount = 0): array
    {
        $url = $this->baseUrl . $method;
        
        if (!$this->checkRateLimit($method)) {
            $this->logger->warning("Rate limit exceeded for method: {$method}");
            sleep($this->retryDelay);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("cURL error in API request", [
                'method' => $method,
                'error' => $error
            ]);
            
            if ($retryCount < $this->maxRetries) {
                sleep($this->retryDelay * ($retryCount + 1));
                return $this->request($method, $params, $retryCount + 1);
            }
            
            return ['ok' => false, 'error' => $error];
        }

        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $this->logger->error("HTTP error in API request", [
                'method' => $method,
                'http_code' => $httpCode,
                'response' => $data
            ]);
            return ['ok' => false, 'http_code' => $httpCode, 'response' => $data];
        }

        if (!$data) {
            $this->logger->error("Invalid JSON response", [
                'method' => $method,
                'response' => $response
            ]);
            return ['ok' => false, 'error' => 'Invalid JSON response'];
        }

        if (!$data['ok']) {
            $errorCode = $data['error_code'] ?? 0;
            $errorDescription = $data['description'] ?? 'Unknown error';
            
            if (in_array($errorCode, [429, 500, 502, 503, 504]) && $retryCount < $this->maxRetries) {
                $retryAfter = $data['parameters']['retry_after'] ?? $this->retryDelay * ($retryCount + 1);
                $this->logger->warning("Retrying API request", [
                    'method' => $method,
                    'error_code' => $errorCode,
                    'retry_after' => $retryAfter,
                    'retry_count' => $retryCount + 1
                ]);
                sleep($retryAfter);
                return $this->request($method, $params, $retryCount + 1);
            }
            
            $this->logger->error("Telegram API error", [
                'method' => $method,
                'error_code' => $errorCode,
                'error_description' => $errorDescription
            ]);
        }

        return $data;
    }

    private function checkRateLimit(string $method): bool
    {
        $key = 'api_rate_limit_' . $method;
        $now = time();
        
        if (!isset($this->rateLimits[$key])) {
            $this->rateLimits[$key] = ['count' => 0, 'reset_time' => $now + 60];
        }
        
        if ($this->rateLimits[$key]['reset_time'] < $now) {
            $this->rateLimits[$key] = ['count' => 0, 'reset_time' => $now + 60];
        }
        
        if ($this->rateLimits[$key]['count'] >= 30) {
            return false;
        }
        
        $this->rateLimits[$key]['count']++;
        return true;
    }

    public function sendMessage(int $chatId, string $text, array $keyboard = null, string $parseMode = 'HTML'): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = is_string($keyboard) ? $keyboard : json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        
        return $this->request('sendMessage', $params);
    }

    public function editMessageText(int $chatId, int $messageId, string $text, array $keyboard = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = is_string($keyboard) ? $keyboard : json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        
        return $this->request('editMessageText', $params);
    }

    public function deleteMessage(int $chatId, int $messageId): array
    {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = null, bool $showAlert = false): array
    {
        $params = ['callback_query_id' => $callbackQueryId];
        
        if ($text) {
            $params['text'] = $text;
        }
        
        if ($showAlert) {
            $params['show_alert'] = true;
        }
        
        return $this->request('answerCallbackQuery', $params);
    }

    public function sendPhoto(int $chatId, string $photo, string $caption = null, array $keyboard = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photo,
            'parse_mode' => 'HTML'
        ];
        
        if ($caption) {
            $params['caption'] = $caption;
        }
        
        if ($keyboard) {
            $params['reply_markup'] = is_string($keyboard) ? $keyboard : json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        
        return $this->request('sendPhoto', $params);
    }

    public function getChat(int $chatId): array
    {
        return $this->request('getChat', ['chat_id' => $chatId]);
    }

    public function getChatMember(string $chatId, int $userId): array
    {
        return $this->request('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }
}

