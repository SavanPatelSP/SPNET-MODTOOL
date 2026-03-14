<?php

namespace App;

class Telegram
{
    private string $token;
    private string $apiUrl;

    public function __construct(string $token)
    {
        $this->token = $token;
        $this->apiUrl = 'https://api.telegram.org/bot' . $token . '/';
    }

    public function call(string $method, array $params = [], bool $isMultipart = false): array
    {
        $url = $this->apiUrl . $method;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        $caPath = '/etc/ssl/cert.pem';
        if (file_exists($caPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caPath);
        }

        if ($isMultipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $result = curl_exec($ch);
        if ($result === false) {
            Logger::error('Telegram API call failed: ' . curl_error($ch));
            curl_close($ch);
            return ['ok' => false, 'description' => 'curl_error'];
        }

        curl_close($ch);
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            Logger::error('Telegram API invalid JSON response');
            return ['ok' => false, 'description' => 'invalid_json'];
        }

        return $decoded;
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ], $options);

        $resp = $this->call('sendMessage', $params);
        if (!($resp['ok'] ?? false)) {
            Logger::error('sendMessage failed: ' . ($resp['description'] ?? 'unknown'));
        }
        return $resp;
    }

    public function sendDocument(int|string $chatId, string $filePath, string $caption = '', array $options = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'caption' => $caption,
            'document' => new \CURLFile($filePath),
            'parse_mode' => 'Markdown',
        ], $options);

        $resp = $this->call('sendDocument', $params, true);
        if (!($resp['ok'] ?? false)) {
            Logger::error('sendDocument failed: ' . ($resp['description'] ?? 'unknown'));
        }
        return $resp;
    }

    public function getChatMember(int|string $chatId, int|string $userId): array
    {
        return $this->call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function banChatMember(int|string $chatId, int|string $userId, ?int $untilDate = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ];
        if ($untilDate !== null) {
            $params['until_date'] = $untilDate;
        }
        return $this->call('banChatMember', $params);
    }

    public function unbanChatMember(int|string $chatId, int|string $userId): array
    {
        return $this->call('unbanChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function restrictChatMember(int|string $chatId, int|string $userId, int $untilDate): array
    {
        return $this->call('restrictChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'permissions' => json_encode([
                'can_send_messages' => false,
                'can_send_media_messages' => false,
                'can_send_polls' => false,
                'can_send_other_messages' => false,
                'can_add_web_page_previews' => false,
                'can_change_info' => false,
                'can_invite_users' => false,
                'can_pin_messages' => false,
            ]),
            'until_date' => $untilDate,
        ]);
    }

    public function unrestrictChatMember(int|string $chatId, int|string $userId): array
    {
        return $this->call('restrictChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'permissions' => json_encode([
                'can_send_messages' => true,
                'can_send_media_messages' => true,
                'can_send_polls' => true,
                'can_send_other_messages' => true,
                'can_add_web_page_previews' => true,
                'can_change_info' => false,
                'can_invite_users' => true,
                'can_pin_messages' => false,
            ]),
        ]);
    }
}
