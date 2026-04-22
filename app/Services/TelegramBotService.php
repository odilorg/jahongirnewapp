<?php

namespace App\Services;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use Illuminate\Support\Facades\Log;

/**
 * Legacy convenience wrapper around TelegramTransport for the 'booking' bot.
 *
 * Used exclusively by the hotel booking bot flow (ProcessBookingMessage,
 * TelegramWebhookController). New code should inject BotResolverInterface +
 * TelegramTransportInterface directly instead of using this wrapper.
 */
class TelegramBotService
{
    private BotResolverInterface $resolver;
    private TelegramTransportInterface $transport;

    public function __construct(BotResolverInterface $resolver, TelegramTransportInterface $transport)
    {
        $this->resolver = $resolver;
        $this->transport = $transport;
    }

    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        $bot = $this->resolver->resolve('booking');
        $result = $this->transport->sendMessage($bot, $chatId, $text, $options);

        if (!$result->succeeded()) {
            Log::error('TelegramBotService sendMessage failed', ['chat_id' => $chatId, 'status' => $result->httpStatus]);
        }

        return ['ok' => $result->ok, 'result' => $result->result];
    }

    public function setWebhook(string $url): array
    {
        $bot = $this->resolver->resolve('booking');

        // Forward the configured secret_token so Telegram starts sending
        // X-Telegram-Bot-Api-Secret-Token on every update. Without this
        // the webhook middleware (verify.telegram.webhook:booking) falls
        // back to migration-mode and accepts every request unsigned.
        // See docs/audits/2026-04-22-booking-bot-auth.md (Finding B5).
        $secretToken = (string) config('services.telegram_booking_bot.secret_token', '');

        if ($secretToken === '') {
            Log::warning(
                'TelegramBotService::setWebhook called with no secret_token configured — webhook will remain UNENFORCED until TELEGRAM_BOOKING_SECRET_TOKEN is set.',
                ['url' => $url],
            );
            $result = $this->transport->setWebhook($bot, $url);
        } else {
            $result = $this->transport->setWebhook($bot, $url, $secretToken);
        }

        return ['ok' => $result->ok, 'result' => $result->result];
    }

    public function getWebhookInfo(): array
    {
        $bot = $this->resolver->resolve('booking');
        $result = $this->transport->getWebhookInfo($bot);

        return ['ok' => $result->ok, 'result' => $result->result];
    }

    public function deleteWebhook(): array
    {
        $bot = $this->resolver->resolve('booking');
        $result = $this->transport->deleteWebhook($bot);

        return ['ok' => $result->ok, 'result' => $result->result];
    }

    public function answerCallbackQuery(string $callbackQueryId, array $options = []): array
    {
        $bot = $this->resolver->resolve('booking');
        $result = $this->transport->call($bot, 'answerCallbackQuery', array_merge(
            ['callback_query_id' => $callbackQueryId],
            $options,
        ));

        return ['ok' => $result->ok, 'result' => $result->result];
    }

    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): array
    {
        $bot = $this->resolver->resolve('booking');
        $result = $this->transport->call($bot, 'editMessageText', array_merge(
            ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text],
            $options,
        ));

        return ['ok' => $result->ok, 'result' => $result->result];
    }
}
