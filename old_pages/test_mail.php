<?php
require_once __DIR__ . '/lib/mail_helper.php';

$result = sendSystemMail(
    'gbgbdavidlee@gmail.com',
    '測試使用者',
    '【UL.GG】SMTP 測試信',
    "這是一封 SMTP 測試信。\n\n如果你收到這封信，代表 Gmail SMTP 設定成功。"
);

var_dump($result);
