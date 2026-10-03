<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/_skill_render.php';

$stmt = $db->prepare("
  SELECT
    skill_code,
    name_tcn,
    info_tcn,
    note_player_tcn,
    phase,
    range_mask,
    require_json
  FROM unlight_skill
  WHERE skill_code = ?
  LIMIT 1
");
$stmt->execute(['cc001_sk01_2']);
$s = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$s) {
  echo '找不到技能';
  exit;
}

$s['chara_name'] = '艾伯李斯特';
$s['min_level_label'] = 'L2';
$s['available_levels'] = 'L2, L3, L4, L5, R1';
$s['tags'] = ['測試', '增攻'];

?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <link rel="stylesheet" href="/assets/css/theme.css">
</head>
<body class="un-body">
  <main class="un-main" style="margin-left:0;">
    <div class="un-main-inner">
      <div class="ul-card">
        <h4 class="card-title">技能 Render 測試</h4>
        <?= ul_render_skill_card($s, [
          'is_admin' => true,
          'show_character' => true,
          'show_available' => true,
        ]) ?>
      </div>
    </div>
  </main>
</body>
</html>