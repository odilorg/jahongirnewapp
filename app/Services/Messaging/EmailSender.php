<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

class EmailSender
{
    private const FROM_EMAIL = 'odilorg@gmail.com';

    /**
     * Send an email via himalaya.
     */
    public function send(string $to, string $subject, string $body): SendResult
    {
        $mml = "From: " . self::FROM_EMAIL . "\nTo: {$to}\nSubject: {$subject}\n\n{$body}";

        $tmpFile = tempnam(sys_get_temp_dir(), 'gyg_mail_') . '.eml';
        file_put_contents($tmpFile, $mml);

        $output = [];
        $code = 1;
        exec("himalaya template send < " . escapeshellarg($tmpFile) . " 2>&1", $output, $code);
        unlink($tmpFile);

        if ($code === 0) {
            return SendResult::ok('email');
        }

        $error = implode(' ', $output);
        Log::error('EmailSender: himalaya failed', ['to' => $to, 'code' => $code, 'output' => $error]);

        return SendResult::fail('email', $error, retryable: true);
    }
}
