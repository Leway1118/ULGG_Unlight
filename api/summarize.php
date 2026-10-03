<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// 1. 載入 dotenv
$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();

// 2. 定義摘要函式
function summarizeAndTranslate($text)
{
    $apiKey = $_ENV['OPENAI_API_KEY'];

    if (!$apiKey) {
        return "（缺少 API KEY）";
    }

    if (empty($text)) {
        return "（公告內容為空）";
    }

    $prompt = "
你是一位 UNLIGHT 官方公告整理助手。

請將以下公告內容：

1. 萃取 3—6 個重點（條列）
2. 自動翻譯成繁體中文
3. 內容需簡短，適合 UL.GG 首頁布告欄
4. 不要額外補充

《公告內容》
$text
";

    $payload = [
        "model" => "gpt-4o-mini",
        "messages" => [
            ["role" => "user", "content" => $prompt]
        ]
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);

    if (isset($data["error"])) {
        return "（API錯誤：" . $data["error"]["message"] . "）";
    }

    return $data["choices"][0]["message"]["content"] ?? "（摘要失敗）";
}
