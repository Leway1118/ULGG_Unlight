<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "DEBUG: file loaded<br>";
require 'api/summarize.php';

echo summarizeAndTranslate("UNLIGHT新增角色卡『尤莉卡』，一週年活動開始……");
