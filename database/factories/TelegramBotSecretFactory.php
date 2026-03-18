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
 *
 * Encrypted columns (token_encrypted, webhook_secret_encrypted) are not
 * mass-assignable. This factory sets them via afterMaking/afterCreating
 * hooks that write directly to the model attributes.
 */
class TelegramBotSecretFactory extends Factory
{
    protected $model = TelegramBotSecret::class;

    /** @var string|null Override plaintext token for this factory run */
    private ?string $plaintextToken = null;

    /** @var string|null Override plaintext webhook secret for this factory run */
    private ?string $plaintextWebhookSecret = null;

    public function definition(): array
    {
        return [
            'telegram_bot_id' => TelegramBot::factory(),
            'version' => 1,
            'status' => SecretStatus::Pending,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (TelegramBotSecret $secret) {
            // Set encrypted columns directly on the model (bypasses $fillable)
            if (! $secret->token_encrypted) {
                $secret->token_encrypted = Crypt::encryptString(
                    $this->plaintextToken ?? $this->faker->sha256()
                );
            }
        })->afterCreating(function (TelegramBotSecret $secret) {
            // After creating, if encrypted fields were set via afterMaking
            // they're already persisted. But if withWebhookSecret was used
            // and the column is set on the model, we need to save again.
            if ($this->plaintextWebhookSecret !== null && ! $secret->webhook_secret_encrypted) {
                $secret->webhook_secret_encrypted = Crypt::encryptString($this->plaintextWebhookSecret);
                $secret->saveQuietly();
            }
        });
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
        return $this->afterMaking(function (TelegramBotSecret $secret) use ($plaintext) {
            $secret->token_encrypted = Crypt::encryptString($plaintext);
        });
    }

    public function withWebhookSecret(string $plaintext): static
    {
        return $this->afterMaking(function (TelegramBotSecret $secret) use ($plaintext) {
            $secret->webhook_secret_encrypted = Crypt::encryptString($plaintext);
        });
    }

    public function version(int $version): static
    {
        return $this->state(fn () => ['version' => $version]);
    }
}
