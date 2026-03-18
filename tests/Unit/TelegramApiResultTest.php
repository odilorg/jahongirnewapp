<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\TelegramApiResult;
use PHPUnit\Framework\TestCase;

class TelegramApiResultTest extends TestCase
{
    /** @test */
    public function succeeded_is_true_for_ok_200(): void
    {
        $result = new TelegramApiResult(ok: true, result: ['id' => 1], httpStatus: 200);

        $this->assertTrue($result->succeeded());
    }

    /** @test */
    public function succeeded_is_false_for_ok_false(): void
    {
        $result = new TelegramApiResult(ok: false, result: null, httpStatus: 200);

        $this->assertFalse($result->succeeded());
    }

    /** @test */
    public function succeeded_is_false_for_500(): void
    {
        $result = new TelegramApiResult(ok: false, result: null, httpStatus: 500);

        $this->assertFalse($result->succeeded());
    }

    /** @test */
    public function is_rate_limited_for_429(): void
    {
        $result = new TelegramApiResult(
            ok: false,
            result: ['parameters' => ['retry_after' => 30]],
            httpStatus: 429,
            description: 'Too Many Requests',
        );

        $this->assertTrue($result->isRateLimited());
        $this->assertSame(30, $result->retryAfterSeconds());
    }

    /** @test */
    public function retry_after_returns_null_for_non_429(): void
    {
        $result = new TelegramApiResult(ok: true, result: [], httpStatus: 200);

        $this->assertNull($result->retryAfterSeconds());
    }

    /** @test */
    public function is_permanent_error_for_400_403_404(): void
    {
        foreach ([400, 403, 404] as $status) {
            $result = new TelegramApiResult(ok: false, result: null, httpStatus: $status);
            $this->assertTrue($result->isPermanentError(), "HTTP {$status} should be permanent");
        }
    }

    /** @test */
    public function is_not_permanent_error_for_429_500(): void
    {
        foreach ([429, 500, 502] as $status) {
            $result = new TelegramApiResult(ok: false, result: null, httpStatus: $status);
            $this->assertFalse($result->isPermanentError(), "HTTP {$status} should not be permanent");
        }
    }

    /** @test */
    public function description_and_error_code(): void
    {
        $result = new TelegramApiResult(
            ok: false,
            result: null,
            httpStatus: 400,
            description: 'Bad Request: chat not found',
            errorCode: 400,
        );

        $this->assertSame('Bad Request: chat not found', $result->description);
        $this->assertSame(400, $result->errorCode);
    }
}
