<?php
// steam_start.php
ini_set('session.cookie_path', '/');
session_start();
error_log("START sid=" . session_id() . " redirect=" . ($_SESSION['login_redirect'] ?? 'NULL'));
if (empty($_SESSION['login_redirect'])) {
    $_SESSION['login_redirect'] =
        $_SERVER['HTTP_REFERER']
        ?? '/pages/index.php';
}

error_log(
    'STEAM_START sid=' . session_id() .
        ' redirect=' . ($_SESSION['login_redirect'] ?? 'NULL')
);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'];
$base   = $scheme . "://" . $host;

$returnTo = $base . "/api/auth/steam_callback.php";

$params = http_build_query([
    'openid.ns'         => 'http://specs.openid.net/auth/2.0',
    'openid.mode'       => 'checkid_setup',
    'openid.return_to'  => $returnTo,
    'openid.realm'      => $base,
    'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
    'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select'
]);

header("Location: https://steamcommunity.com/openid/login?{$params}");
exit;
