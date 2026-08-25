<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramAlertClient
{
    public function enabled(): bool
    {
        return (bool) config('telegram.enabled')
            && filled(config('telegram.bot_token'))
            && filled(config('telegram.chat_id'));
    }

    public function send(string $message): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Telegram alerts are not configured or enabled.');
        }

        $response = Http::asJson()
            ->timeout((int) config('telegram.timeout_seconds', 10))
            ->post('https://api.telegram.org/bot'.config('telegram.bot_token').'/sendMessage', [
                'chat_id' => (string) config('telegram.chat_id'),
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram API rejected the alert: '.$response->body());
        }
    }
}
