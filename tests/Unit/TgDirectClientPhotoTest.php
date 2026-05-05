<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TgDirectClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TgDirectClient::sendPhoto contract — pinned by HTTP fakes so we
 * never actually hit tg-direct in CI. The real /send_photo endpoint
 * is smoke-tested manually after deploy.
 */
final class TgDirectClientPhotoTest extends TestCase
{
    /** @test */
    public function send_photo_posts_correct_payload_to_tg_direct(): void
    {
        Http::fake([
            '*tg-direct*' => Http::response(['ok' => true, 'msg_id' => 42, 'method' => 'username', 'kind' => 'photo'], 200),
            '*8766*'      => Http::response(['ok' => true, 'msg_id' => 42, 'method' => 'username', 'kind' => 'photo'], 200),
        ]);

        $result = app(TgDirectClient::class)->sendPhoto(
            '@odilorg',
            'https://jahongir-app.uz/images/review/tripadvisor-review-card-jahongir-travel.png',
            'Caption text',
            'Test Driver',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(42, $result['msg_id']);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_ends_with($request->url(), '/send_photo')
                && ($body['to']         ?? '') === '@odilorg'
                && ($body['image_url']  ?? '') === 'https://jahongir-app.uz/images/review/tripadvisor-review-card-jahongir-travel.png'
                && ($body['caption']    ?? '') === 'Caption text'
                && ($body['first_name'] ?? '') === 'Test Driver';
        });
    }

    /** @test */
    public function send_photo_propagates_failure_without_throwing(): void
    {
        Http::fake([
            '*8766*' => Http::response(['ok' => false, 'error' => 'image_url not in allowlist'], 400),
        ]);

        $result = app(TgDirectClient::class)->sendPhoto(
            '@odilorg',
            'https://example.com/bad.png',
            'caption',
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('allowlist', (string) $result['error']);
    }

    /** @test */
    public function empty_destination_does_not_call_tg_direct(): void
    {
        Http::fake();
        $result = app(TgDirectClient::class)->sendPhoto('   ', 'https://jahongir-app.uz/x.png');

        $this->assertFalse($result['ok']);
        $this->assertSame('empty_destination', $result['error']);
        Http::assertNothingSent();
    }
}
