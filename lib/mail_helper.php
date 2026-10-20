<?php
// lib/mail_helper.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Resolve mail transport configuration from the environment.
 *
 * No credential fallback is provided. Missing or invalid configuration must
 * stop the send before a transport is created.
 *
 * @param array<string,string>|null $env Test seam. Null reads process env.
 * @return array{host:string,username:string,password:string,port:int,from:string}
 * @throws RuntimeException When required configuration is missing or invalid.
 */
function ulggMailConfigFromEnv(?array $env = null): array
{
    $read = static function (string $key) use ($env): string {
        if ($env !== null) {
            return trim((string)($env[$key] ?? ''));
        }

        $values = [
            $_ENV[$key] ?? null,
            $_SERVER[$key] ?? null,
            getenv($key),
        ];

        foreach ($values as $value) {
            if ($value === false || $value === null) {
                continue;
            }

            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    };

    $required = ['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_PORT', 'MAIL_FROM'];
    $values = [];
    $missing = [];

    foreach ($required as $key) {
        $values[$key] = $read($key);
        if ($values[$key] === '') {
            $missing[] = $key;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException(
            'Missing mail configuration: ' . implode(', ', $missing)
        );
    }

    if (!ctype_digit($values['MAIL_PORT'])) {
        throw new RuntimeException('Invalid mail configuration: MAIL_PORT');
    }

    $port = (int)$values['MAIL_PORT'];
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid mail configuration: MAIL_PORT');
    }

    if (filter_var($values['MAIL_FROM'], FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Invalid mail configuration: MAIL_FROM');
    }

    return [
        'host' => $values['MAIL_HOST'],
        'username' => $values['MAIL_USERNAME'],
        'password' => $values['MAIL_PASSWORD'],
        'port' => $port,
        'from' => $values['MAIL_FROM'],
    ];
}

/**
 * 發送系統信件（Gmail SMTP）
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $subject
 * @param string $bodyText  純文字內容
 * @return bool
 */
function sendSystemMail(string $toEmail, string $toName, string $subject, string $bodyText, ?array $env = null): bool
{
    try {
        $config = ulggMailConfigFromEnv($env);
    } catch (RuntimeException $e) {
        error_log('sendSystemMail: ' . $e->getMessage());
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // ===== SMTP 設定 =====
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $config['port'];
        $mail->CharSet    = 'UTF-8';

        // ===== 寄件者 =====
        $mail->setFrom($config['from'], 'UL.GG');

        // ===== 收件者 =====
        $mail->addAddress($toEmail, $toName);

        // ===== 信件內容 =====
        $mail->isHTML(false); // 純文字（先用這個，最穩）
        $mail->Subject = $subject;
        $mail->Body    = $bodyText;

        $mail->send();
        return true;

    } catch (Exception $e) {
        // 可選：寫 log
        error_log('sendSystemMail: mail transport error');
        return false;
    }
}
