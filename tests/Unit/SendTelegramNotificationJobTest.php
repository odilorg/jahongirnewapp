<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\SendTelegramNotificationJob;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that SendTelegramNotificationJob queue payloads
 * never contain plaintext bot tokens.
 */
class SendTelegramNotificationJobTest extends TestCase
{
    /** @test */
    public function serialized_payload_contains_slug_not_token(): void
    {
        $job = new SendTelegramNotificationJob(
            botSlug: 'owner-alert',
            method: 'sendMessage',
            params: ['chat_id' => 12345, 'text' => 'Hello'],
        );

        $serialized = serialize($job);

        // Slug MUST be present (it's the identifier)
        $this->assertStringContainsString('owner-alert', $serialized);

        // No token-like strings should appear
        // Telegram tokens look like: 123456:ABC-DEF... (digits:alphanum)
        $this->assertStringNotContainsString('botToken', $serialized);
        $this->assertStringNotContainsString('bot_token', $serialized);
    }

    /** @test */
    public function serialized_payload_does_not_contain_known_token_patterns(): void
    {
        // Simulate with a realistic-looking token embedded nowhere in the params
        $job = new SendTelegramNotificationJob(
            botSlug: 'housekeeping',
            method: 'sendMessage',
            params: [
                'chat_id' => 99999,
                'text' => 'Room 101 is clean',
                'parse_mode' => 'HTML',
            ],
        );

        $serialized = serialize($job);

        // Only the slug, method, and params should be serialized
        $this->assertStringContainsString('housekeeping', $serialized);
        $this->assertStringContainsString('sendMessage', $serialized);
        $this->assertStringContainsString('Room 101 is clean', $serialized);

        // Confirm no properties named 'token' or 'secret' exist
        $this->assertStringNotContainsString('"token"', $serialized);
        $this->assertStringNotContainsString('"secret"', $serialized);
        $this->assertStringNotContainsString('api.telegram.org', $serialized);
    }

    /** @test */
    public function job_properties_are_only_slug_method_params(): void
    {
        $job = new SendTelegramNotificationJob(
            botSlug: 'pos',
            method: 'sendPhoto',
            params: ['chat_id' => 1, 'photo' => 'file_id'],
        );

        // Verify public properties via reflection
        $reflection = new \ReflectionClass($job);
        $publicProps = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            $reflection->getProperties(\ReflectionProperty::IS_PUBLIC)
        );

        // Should contain our safe properties but NOT botToken
        $this->assertContains('botSlug', $publicProps);
        $this->assertContains('method', $publicProps);
        $this->assertContains('params', $publicProps);
        $this->assertNotContains('botToken', $publicProps);
        $this->assertNotContains('token', $publicProps);
        $this->assertNotContains('secret', $publicProps);
    }

    /** @test */
    public function failed_callback_does_not_reference_token(): void
    {
        $job = new SendTelegramNotificationJob(
            botSlug: 'cashier',
            method: 'sendMessage',
            params: ['chat_id' => 1, 'text' => 'test'],
        );

        // Inspect the failed() method source to confirm no token references
        $reflection = new \ReflectionMethod($job, 'failed');
        $source = file_get_contents($reflection->getFileName());
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $lines = array_slice(
            explode("\n", $source),
            $startLine - 1,
            $endLine - $startLine + 1,
        );
        $failedSource = implode("\n", $lines);

        $this->assertStringNotContainsString('token', strtolower($failedSource));
        $this->assertStringNotContainsString('secret', strtolower($failedSource));
        $this->assertStringContainsString('bot_slug', $failedSource);
    }
}
