<?php
//I am get_steam_unlight_news.php
require_once __DIR__ . '/summarize_local.php'; // ← 新增（本地摘要器）

function getUnlightSteamNews($count = 5)
{

    $url = "https://api.steampowered.com/ISteamNews/GetNewsForApp/v2/?appid=3247080&count=$count&maxlength=5000";

    $json = @file_get_contents($url);
    if (!$json) return [];
    // ⭐ 直接存原始 JSON
    file_put_contents(
        __DIR__ . '/_debug_steam_news_raw.json',
        $json
    );


    $data = json_decode($json, true);
    /* if (
        isset($_SESSION['username']) &&
        in_array($_SESSION['username'], ['way.lee', '咕嚕．挖2朵'], true)
    ) {

        echo '<pre>';
        print_r($data['appnews']['newsitems']);
        echo '</pre>';
        exit;
    } */
    $items = $data['appnews']['newsitems'] ?? [];

    $allowedFeeds = ["steam_community", "steam_community_announcements", "steam_updates"];

    $filtered = array_filter($items, function ($n) use ($allowedFeeds) {
        return in_array($n['feedname'], $allowedFeeds);
    });

    $filtered = array_slice(array_values($filtered), 0, $count);

    // ⭐ 對每篇公告加入本地摘要 summary
    foreach ($filtered as &$n) {
        $n['summary'] = localSummarize($n['contents'] ?? '');
    }

    return $filtered;
}
