<?php
/**
 * Discord Webhook Helper
 * ======================
 * - 只提供 function
 * - 不輸出任何文字
 * - 不自動執行
 * - 可安全被 require_once
 */

declare(strict_types=1);

/**
 * Test seam: replace the real cURL POST with an injectable callable.
 * Production never sets this.
 *
 * The callable receives ($url, $jsonBody, $timeout, $connectTimeout) and
 * returns:
 *   ['exec' => mixed, 'errno' => int, 'http_code' => int, 'error' => string]
 */
function ulggDiscordSetHttpTransport(?callable $transport): void
{
    $GLOBALS['ULGG_DISCORD_HTTP_TRANSPORT'] = $transport;
}

function ulggDiscordHttpTransport(): ?callable
{
    $transport = $GLOBALS['ULGG_DISCORD_HTTP_TRANSPORT'] ?? null;

    return is_callable($transport) ? $transport : null;
}

/**
 * @param array{exec?:mixed,errno?:int,http_code?:int,error?:string} $result
 */
function ulggDiscordTransportSucceeded(array $result): bool
{
    $httpCode = (int)($result['http_code'] ?? 0);

    return ($result['exec'] ?? false) !== false
        && (int)($result['errno'] ?? -1) === 0
        && $httpCode >= 200
        && $httpCode < 300;
}

/**
 * 發送 Discord Webhook
 *
 * @param string $webhookUrl Discord Webhook URL
 * @param array  $payload    Discord payload（會自動轉 JSON）
 * @param int    $timeout    逾時秒數（預設 5 秒）
 *
 * @return bool true = HTTP 2xx 且無 cURL error；其餘一律 false
 */
function sendDiscordWebhook(string $webhookUrl, array $payload, int $timeout = 5): bool
{
    if ($webhookUrl === '') {
        return false;
    }

    try {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        error_log('[DiscordWebhook] payload encode failed');

        return false;
    }

    $transport = ulggDiscordHttpTransport();

    if ($transport !== null) {
        try {
            $result = $transport($webhookUrl, $body, $timeout, 3);
        } catch (Throwable) {
            error_log('[DiscordWebhook] transport exception');

            return false;
        }

        return is_array($result) && ulggDiscordTransportSucceeded($result);
    }

    $ch = curl_init($webhookUrl);

    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    try {
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    } catch (Throwable) {
        // 不記錄 URL、response body 或 exception message。
        error_log('[DiscordWebhook] transport exception');
        curl_close($ch);

        return false;
    }

    curl_close($ch);

    return ulggDiscordTransportSucceeded([
        'exec' => $response,
        'errno' => $errno,
        'http_code' => $httpCode,
    ]);
}
