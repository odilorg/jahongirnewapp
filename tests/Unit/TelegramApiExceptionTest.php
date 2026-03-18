<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\Telegram\TelegramApiException;
use PHPUnit\Framework\TestCase;

class TelegramApiExceptionTest extends TestCase
{
    private const FAKE_TOKEN = '123456:ABC-DEF-FAKE-TOKEN';

    /** @test */
    public function message_contains_slug_not_token(): void
    {
        $e = new TelegramApiException(
            slug: 'owner-alert',
            method: 'sendMessage',
            httpStatus: 400,
            apiError: 'Bad Request: chat not found',
        );

        $this->assertStringContainsString('owner-alert', $e->getMessage());
        $this->assertStringContainsString('sendMessage', $e->getMessage());
        $this->assertStringNotContainsString(self::FAKE_TOKEN, $e->getMessage());
    }

    /** @test */
    public function safe_context_does_not_contain_token(): void
    {
        $e = new TelegramApiException(
            slug: 'cashier',
            method: 'getMe',
            httpStatus: 401,
            apiError: 'Unauthorized',
            context: ['extra' => 'safe-value'],
        );

        $ctx = $e->safeContext();
        $serialized = json_encode($ctx);

        $this->assertSame('cashier', $ctx['bot_slug']);
        $this->assertSame('getMe', $ctx['method']);
        $this->assertSame(401, $ctx['http_status']);
        $this->assertSame('safe-value', $ctx['extra']);
        $this->assertStringNotContainsString('token', strtolower($serialized));
    }

    /** @test */
    public function http_status_is_exception_code(): void
    {
        $e = new TelegramApiException(
            slug: 'pos',
            method: 'sendMessage',
            httpStatus: 429,
            apiError: 'Too Many Requests',
        );

        $this->assertSame(429, $e->getCode());
    }

    /** @test */
    public function preserves_previous_exception(): void
    {
        $prev = new \RuntimeException('connection timed out');
        $e = new TelegramApiException(
            slug: 'kitchen',
            method: 'sendMessage',
            httpStatus: 0,
            apiError: 'Network error',
            previous: $prev,
        );

        $this->assertSame($prev, $e->getPrevious());
    }
}
