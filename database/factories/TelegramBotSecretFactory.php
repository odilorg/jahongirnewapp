<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SecretStatus;
use App\Models\TelegramBot;
use App\Models\TelegramBotSecret;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<TelegramBotSecret>
 */
class TelegramBotSecretFactory extends Factory
{
    protected $model = TelegramBotSecret::class;

    public function definition(): array
    {
        return [
            'telegram_bot_id' => TelegramBot::factory(),
            'version' => 1,
            'token_encrypted' => Crypt::encryptString($this->faker->sha256()),
            'webhook_secret_encrypted' => null,
            'status' => SecretStatus::Pending,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => SecretStatus::Active,
            'activated_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => SecretStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }

    public function withToken(string $plaintext): static
    {
        return $this->state(fn () => [
            'token_encrypted' => Crypt::encryptString($plaintext),
        ]);
    }

    public function withWebhookSecret(string $plaintext): static
    {
        return $this->state(fn () => [
            'webhook_secret_encrypted' => Crypt::encryptString($plaintext),
        ]);
    }

    public function version(int $version): static
    {
        return $this->state(fn () => ['version' => $version]);
    }
}
