<!DOCTYPE html>

<?php
ob_start(); // 啟動輸出緩衝
// 其他程式碼……
require_once('head.php'); //session_start();
//$date = date("Y-m-d H:i:s");

$upload_token = $_SESSION['upload_token'] ?? 0; // 取得 Token

if ($_SESSION["username"] == 'way.lee') {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  /* print '<pre>';
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */
}
if (isset($_SESSION['upload_success'])) {
  /* echo "<script>alert('" . $_SESSION['upload_success'] . "');</script>"; */
  unset($_SESSION['upload_success']); // 顯示後清除
}




function progress_color($rate)
{
  if ($rate < 25) {
    $color = 'red';
  } elseif ($rate < 50) {
    $color = 'yellow';
  } elseif ($rate < 75) {
    $color = 'green';
  } else {
    $color = 'aqua';
  }
  return $color;
}
function all_equal($x, $y, $z)
{
  if ($x === $y || $y === $z || $x === $z) {
    return 1;
  }
}

function cost_punish(int $id1_cost, int $id2_cost, int $id3_cost)
{
  $punish = 0;
  $dif_a = abs($id1_cost - $id2_cost);
  $dif_b = abs($id1_cost - $id3_cost);
  $dif_c = abs($id2_cost - $id3_cost);
  if ($dif_a > 6) {
    $punish += 5;
    if ($dif_a > 13) {
      $punish += 5;
    }
  }
  if ($dif_b > 6) {
    $punish += 5;
    if ($dif_b > 13) {
      $punish += 5;
    }
  }
  if ($dif_c > 6) {
    $punish += 5;
    if ($dif_c > 13) {
      $punish += 5;
    }
  }

  return $punish;
}


function regionIcon($region)
{
  switch ($region) {
    case 'TW':
      return '<i class="fab fa-steam"></i>';

    case 'JP':
      return '<span class="server-icon dmm">D</span>';

    case 'CR':
      return '<span class="region-globe">☯</span>';//🌐

    default:
      return '';
  }
}

//找到下一筆去判斷此筆的勝負
function update_all_next_matches(PDO $db, array $dataList)
{
  $logs = [];
  $updated = 0;
  $processed_ids = [];
  $sql = '';
  foreach ($dataList as $data) {
    $id = $data['id'];
    if (in_array($id, $processed_ids)) continue;
    $processed_ids[] = $id;
    $update_time = $data['update_time'];
    // === 玩家1 ===
    $ack1 = $data['ack1'];
    if ($ack1 == 0) {
      $name_p1 = $data['name_p1'];
      $win_p1 = $data['win_p1'];
      $draw_p1 = $data['draw_p1'];
      $lose_p1 = $data['lose_p1'];

      $stmt1 = $db->prepare("
      SELECT * FROM arena_unlight
      WHERE (name_p1 = :name OR name_p2 = :name)
        AND update_time > :update_time
      ORDER BY update_time ASC LIMIT 1
      ");
      $stmt1->execute([':name' => $name_p1, ':update_time' => $update_time]);
      $next1 = $stmt1->fetch(PDO::FETCH_ASSOC);
      if ($next1) {
        $is_p1 = ($next1['name_p1'] === $name_p1);
        $win_after = $is_p1 ? $next1['win_p1'] : $next1['win_p2'];
        $draw_after = $is_p1 ? $next1['draw_p1'] : $next1['draw_p2'];
        $lose_after = $is_p1 ? $next1['lose_p1'] : $next1['lose_p2'];
        $dw = $win_after - $win_p1;
        $dl = $lose_after - $lose_p1;
        $dd = $draw_after - $draw_p1;
        $ack_field = 'ack1';
        if ($dw >= 1 && $dl === 0 && $dd === 0) {
          //$sql = "✅ 勝：P1 $name_p1 (ID $id)<br>UPDATE arena_unlight SET win=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 勝：P1 $name_p1 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET win=1, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "✅ 勝：P1 $name_p1 (ID $id)";
          $updated++;
        } elseif ($dl >= 1 && $dw === 0 && $dd === 0) {
          //$sql = "✅ 負：P1 $name_p1 (ID $id)<br>UPDATE arena_unlight SET win=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 負：P1 $name_p1 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET win=1, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "✅ 負：P1 $name_p1 (ID $id)";
          $updated++;
        } elseif ($dd >= 1 && $dw === 0 && $dl === 0) {
          //$sql = "✅ 平：P1 $name_p1 (ID $id)<br>UPDATE arena_unlight SET tie=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 平：P1 $name_p1 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET tie=1, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "✅ 平：P1 $name_p1 (ID $id)";
          $updated++;
        } elseif ($dd === 0 && $dw === 0 && $dl === 0) {
          //$sql = "✅ 平：P1 $name_p1 (ID $id)<br>UPDATE arena_unlight SET tie=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 前場無效：P1 $name_p1 (ID $id)";
          $db->prepare("DELETE FROM arena_unlight WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "✅ 平：P1 $name_p1 (ID $id)";
          $updated++;
        } else {
          $sql = "⚠️ 無法判斷 P1 $name_p1 勝負（已標 $ack_field(ID $id)<br>UPDATE arena_unlight SET $ack_field=1 WHERE id = $id";
          //$sql = "⚠️ 無法判斷 P1 $name_p1 勝負（已標 $ack_field(ID $id)";
          $db->prepare("UPDATE arena_unlight SET $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "⚠️ 無法判斷 P1 $name_p1 勝負（已標 $ack_field(ID $id)";
        }
        print $sql . '<br>';
      } else {
        //$sql = "🕵 無法比對 P1 $name_p1 ，找不到下一筆 (ID $id)";
        //$logs[] = "🕵 無法比對 P1 $name_p1 ，找不到下一筆 (ID $id)";
      }
    }




    // === 玩家2 ===
    $ack2 = $data['ack2'];
    if ($ack2 == 0) {
      $name_p2 = $data['name_p2'];
      $win_p2 = $data['win_p2'];
      $draw_p2 = $data['draw_p2'];
      $lose_p2 = $data['lose_p2'];

      $stmt2 = $db->prepare("
        SELECT * FROM arena_unlight
        WHERE (name_p1 = :name OR name_p2 = :name)
          AND update_time > :update_time
        ORDER BY update_time ASC LIMIT 1
        ");
      $stmt2->execute([':name' => $name_p2, ':update_time' => $update_time]);
      $next2 = $stmt2->fetch(PDO::FETCH_ASSOC);

      if ($next2) {
        $is_p2 = ($next2['name_p1'] === $name_p2);
        $win_after = $is_p2 ? $next2['win_p1'] : $next2['win_p2'];
        $draw_after = $is_p2 ? $next2['draw_p1'] : $next2['draw_p2'];
        $lose_after = $is_p2 ? $next2['lose_p1'] : $next2['lose_p2'];

        $dw = $win_after - $win_p2;
        $dl = $lose_after - $lose_p2;
        $dd = $draw_after - $draw_p2;

        $ack_field = 'ack2';

        if ($dw >= 1 && $dl === 0 && $dd === 0) {
          //$sql = "✅ 勝：P2 $name_p2 (ID $id)<br>UPDATE arena_unlight SET win=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 勝：P2 $name_p2 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET win=1, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "✅ 勝：P2 $name_p2 (ID $id)";
          $updated++;
        } elseif ($dl >= 1 && $dw === 0 && $dd === 0) {
          //$sql = "✅ 負：P2 $name_p2 (ID $id)<br>UPDATE arena_unlight SET lose=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 負：P2 $name_p2 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET lose=1, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "✅ 負：P2 $name_p2 (ID $id)";
          $updated++;
        } elseif ($dd >= 1 && $dw === 0 && $dl === 0) {
          //$sql = "✅ 平：P2 $name_p2 (ID $id)<br>UPDATE arena_unlight SET tie=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 平：P2 $name_p2 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET tie=1, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "✅ 平：P2 $name_p2 (ID $id)";
          $updated++;
        } elseif ($dd === 0 && $dw === 0 && $dl === 0) {
          //$sql = "✅ 平：P1 $name_p1 (ID $id)<br>UPDATE arena_unlight SET tie=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 前場無效：P2 $name_p2 (ID $id)";
          $db->prepare("DELETE FROM arena_unlight WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "✅ 平：P1 $name_p1 (ID $id)";
          $updated++;
        } else {
          //$sql = "⚠️ 無法判斷 P2 $name_p2 勝負（已標 $ack_field(ID $id)<br>UPDATE arena_unlight SET $ack_field=1 WHERE id = $id";
          $sql = "⚠️ 無法判斷 P2 $name_p2 勝負（已標 $ack_field(ID $id)";
          $db->prepare("UPDATE arena_unlight SET $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "⚠️ 無法判斷 P2 $name_p2 勝負（已標 $ack_field(ID $id)";
        }
        print $sql . '<br>';
      } else {
        //$sql = "🕵 無法比對 P2 $name_p2 ，找不到下一筆 (ID $id)";
        //$logs[] = "🕵 無法比對 P2 $name_p2 ，找不到下一筆 (ID $id)";
      }
    }
  }
  return ['updated' => $updated, 'logs' => $logs];
}

$check_ack_sql = '';
$order = 0;
$order_sql = '';


if (isset($_POST['check_ack'])) {
  $_SESSION['updated_history'] = 0;
}


//手動更新
if (isset($_POST['manual_update'])) {
  $dataList = [];

  $sql = "SELECT id, room_id, update_time, name_p1, win_p1, draw_p1, lose_p1, name_p2, win_p2, draw_p2, lose_p2,ack1,ack2
        FROM arena_unlight
        WHERE (win+lose+tie<1)
        ORDER BY update_time ASC";

  $stmt = $db->query($sql);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $dataList[] = [
      'id' => $row['id'],
      'update_time' => $row['update_time'],
      'name_p1' => $row['name_p1'],
      'win_p1' => $row['win_p1'],
      'draw_p1' => $row['draw_p1'],
      'lose_p1' => $row['lose_p1'],
      'enemy' => $row['name_p2'],
      'name_p2' => $row['name_p2'],
      'win_p2' => $row['win_p2'],
      'draw_p2' => $row['draw_p2'],
      'lose_p2' => $row['lose_p2'],
      'ack1' => $row['ack1'],
      'ack2' => $row['ack2']
    ];
  }

  $result = update_all_next_matches($db, $dataList);
  $cannot_deter = count($dataList) - $result['updated'];
  // 把提示推到 flash
  $_SESSION['flash'][] = [
    'type'    => 'success',
    'message' => "📦 共處理 " . count($dataList)
      . " 筆，{$cannot_deter} 筆無法比對，更新 {$result['updated']} 筆"
  ];
  // PRG：處理完立刻重導
  header('Location: /queue.php?debug=1');
exit;

}

function updatePreviousMatchResult($db, $name, $winNow, $drawNow, $loseNow, $room_id, $update_time)
{
  $stmt_prev = $db->prepare("SELECT * FROM arena_unlight WHERE (name_p1 = :name OR name_p2 = :name) AND update_time<:update_time ORDER BY update_time DESC LIMIT 1");
  $stmt_prev->execute([':name' => $name, ':room_id' => $room_id, ':update_time' => $update_time]);
  $prev = $stmt_prev->fetch(PDO::FETCH_ASSOC);

  if (!$prev) return null;

  $log = '';
  $dw = $winNow - ($prev['name_p1'] === $name ? $prev['win_p1'] : $prev['win_p2']);
  $dl = $loseNow - ($prev['name_p1'] === $name ? $prev['lose_p1'] : $prev['lose_p2']);
  $dd = $drawNow - ($prev['name_p1'] === $name ? $prev['draw_p1'] : $prev['draw_p2']);

  if ($dw >= 1 && $dl === 0 && $dd === 0) {
    if ($prev['name_p2'] === $name) {
      $db->prepare("UPDATE arena_unlight SET win=1,ack1 = 1,ack2 = 1 WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "✅ 更新前筆($name(勝) VS {$prev['name_p1']} ID {$prev['id']})";
    } else {
      $db->prepare("UPDATE arena_unlight SET lose=1,ack1 = 1,ack2 = 1 WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "✅ 更新前筆($name(勝) VS {$prev['name_p2']} ID {$prev['id']})";
    }
  } elseif ($dw === 0 && $dl >= 1 && $dd === 0) {
    if ($prev['name_p2'] === $name) {
      $db->prepare("UPDATE arena_unlight SET lose = 1,ack1 = 1,ack2 = 1 WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "✅ 更新前筆($name(負) VS {$prev['name_p1']} ID {$prev['id']})";
    } else {
      $db->prepare("UPDATE arena_unlight SET win = 1,ack1 = 1,ack2 = 1 WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "✅ 更新前筆($name(負) VS {$prev['name_p2']} ID {$prev['id']})";
    }
  } elseif ($dd >= 1 && $dw === 0 && $dl === 0) {
    if ($prev['name_p2'] === $name) {
      $db->prepare("UPDATE arena_unlight SET tie=1,ack1 = 1,ack2 = 1 WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "✅ 更新前筆($name(平) VS {$prev['name_p1']} ID {$prev['id']})";
    } else {
      $db->prepare("UPDATE arena_unlight SET tie=1,ack1 = 1,ack2 = 1 WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "✅ 更新前筆($name(平) VS {$prev['name_p2']} ID {$prev['id']})";
    }
  } elseif ($dd === 0 && $dw === 0 && $dl === 0) {
    if ($prev['name_p2'] === $name) {
      $sql = "DELETE FROM arena_unlight WHERE id = " . $prev['id'] . ";";
      //print $sql;
      $db->prepare("DELETE FROM arena_unlight WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "✅ 前筆記錄失效，已刪除($name(平) VS {$prev['name_p1']} ID {$prev['id']})";
    } else {
      $sql = "DELETE FROM arena_unlight WHERE id = " . $prev['id'] . ";";
      //print $sql;
      $db->prepare("DELETE FROM arena_unlight WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "✅ 前筆記錄失效，已刪除($name(平) VS {$prev['name_p2']} ID {$prev['id']})";
    }
  } else {
    if ($prev['name_p1'] === $name) {
      $db->prepare("UPDATE arena_unlight SET ack1 = 1 WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "⚠️ 無法判斷勝負($name VS {$prev['name_p2']} ID {$prev['id']})，已標記無效";
    } else {
      $db->prepare("UPDATE arena_unlight SET ack2 = 1 WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "⚠️ 無法判斷勝負($name VS {$prev['name_p1']} ID {$prev['id']})，已標記無效";
    }
  }

  return $log;
}

//匯入json (+更新前筆)
if (isset($_POST['upload_json'])) {


  $json_str = $_POST['ws_json'];
  $dataList = json_decode($json_str, true);

  // 先檢查 json
  if (json_last_error() !== JSON_ERROR_NONE || !is_array($dataList)) {
    $_SESSION['flash'][] = [
      'type'    => 'danger',
      'message' => '❌ JSON 格式錯誤或不是陣列：' . json_last_error_msg()
    ];
    header('Location: /queue.php?debug=1');
exit;

  }

  // ** 新增此段：依 date 由小到大排序 **
  usort($dataList, function ($a, $b) {
    $da = $a['date'] ?? 0;
    $db = $b['date'] ?? 0;
    return $da <=> $db;
  });

  $logs = [];
  $updated = 0;
  $total = count($dataList);
  $short_room_id = '';
  $count_short_room = 0;
  foreach ($dataList as $data) {
    $room_id = $data['room_id'] ?? '';
    $cost = isset($data['cost']) && $data['cost'] !== null
      ? (int)$data['cost']
      : 0;
    $stage = $data['stage'] ?? '';
    /* $readyA = $data['readyA'] ?? '';
    $readyB = $data['readyB'] ?? ''; */
    //print "room_id=$room_id";
    $date = $data['date'] ?? 0;
    $datetime = date("Y-m-d H:i:s", $date / 1000);

    // 防止重複上傳
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM arena_unlight WHERE room_id = :room_id");
    $stmt_check->execute([':room_id' => $room_id]);
    $exists = $stmt_check->fetchColumn();

    if ($exists > 0) {
      $short_room_id .= substr($room_id, 0, 3) . '..';
      $count_short_room++;
      continue;
    }

    // Player A
    $nameA = $data['playerA']['name'] ?? '';
    $bpA = $data['playerA']['bp'] ?? 0;
    $winA = $data['playerA']['win'] ?? 0;
    $drawA = $data['playerA']['draw'] ?? 0;
    $loseA = $data['playerA']['lose'] ?? 0;
    $e1 = ($data['deckA']['charaIndex'][0] ?? -1) + 1;
    $e2 = ($data['deckA']['charaIndex'][1] ?? -1) + 1;
    $e3 = ($data['deckA']['charaIndex'][2] ?? -1) + 1;
    $w1 = $data['deckA']['weapon'][0] ?? NULL;
    $w2 = $data['deckA']['weapon'][1] ?? NULL;
    $w3 = $data['deckA']['weapon'][2] ?? NULL;
    $eventindex1 = json_encode($data['deckA']['eventIndex']) ?? [null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null];
    // Player B
    $nameB = $data['playerB']['name'] ?? '';
    $bpB = $data['playerB']['bp'] ?? 0;
    $winB = $data['playerB']['win'] ?? 0;
    $drawB = $data['playerB']['draw'] ?? 0;
    $loseB = $data['playerB']['lose'] ?? 0;
    $u1 = ($data['deckB']['charaIndex'][0] ?? -1) + 1;
    $u2 = ($data['deckB']['charaIndex'][1] ?? -1) + 1;
    $u3 = ($data['deckB']['charaIndex'][2] ?? -1) + 1;
    $v1 = $data['deckB']['weapon'][0] ?? NULL;
    $v2 = $data['deckB']['weapon'][1] ?? NULL;
    $v3 = $data['deckB']['weapon'][2] ?? NULL;
    $eventindex2 = json_encode($data['deckB']['eventIndex']) ?? [null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null];

    // 資料庫插入
    $sql = "INSERT INTO arena_unlight 
              (room_id,cost,stage, name_p1, bp_p1, win_p1, draw_p1, lose_p1, e1, e2, e3, 
               name_p2, bp_p2, win_p2, draw_p2, lose_p2, u1, u2, u3, update_time, username, w1,w2,w3,eventindex1,v1,v2,v3,eventindex2)
              VALUES 
              (:room_id,:cost,:stage, :name_p1, :bp_p1, :win_p1, :draw_p1, :lose_p1, :e1, :e2, :e3,
               :name_p2, :bp_p2, :win_p2, :draw_p2, :lose_p2, :u1, :u2, :u3, :update_time, :username,  :w1, :w2, :w3, :eventindex1, :v1, :v2, :v3, :eventindex2)";

    $stmt_insert = $db->prepare($sql);

    try {
      $success = $stmt_insert->execute([
        ':room_id' => $room_id,
        ':cost' => $cost,
        ':stage' => $stage,
        ':name_p1' => $nameA,
        ':bp_p1' => $bpA,
        ':win_p1' => $winA,
        ':draw_p1' => $drawA,
        ':lose_p1' => $loseA,
        ':e1' => $e1,
        ':e2' => $e2,
        ':e3' => $e3,
        ':name_p2' => $nameB,
        ':bp_p2' => $bpB,
        ':win_p2' => $winB,
        ':draw_p2' => $drawB,
        ':lose_p2' => $loseB,
        ':u1' => $u1,
        ':u2' => $u2,
        ':u3' => $u3,
        ':update_time' => $datetime,
        ':username' => $username ?? '',
        ':w1' => $w1,
        ':w2' => $w2,
        ':w3' => $w3,
        ':eventindex1' => $eventindex1,
        ':v1' => $v1,
        ':v2' => $v2,
        ':v3' => $v3,
        ':eventindex2' => $eventindex2
      ]);

      if ($success) {
        $_SESSION['flash'][] = [
          'type'    => 'success',
          'message' => "✅ 上傳成功！{$nameA} VS {$nameB} Room ID: {$room_id}"
        ];
        $updated++;
      } else {
        $_SESSION['flash'][] = [
          'type'    => 'danger',
          'message' => "❌ 插入失敗 ({$nameA} VS {$nameB} Room ID: {$room_id})"
        ];
      }
    } catch (Exception $e) {
      $_SESSION['flash'][] = [
        'type'    => 'danger',
        'message' => '❌ 發生例外錯誤：' . $e->getMessage()
      ];
    }

    $logA = updatePreviousMatchResult($db, $nameA, $winA, $drawA, $loseA, $room_id, $datetime);
    $logB = updatePreviousMatchResult($db, $nameB, $winB, $drawB, $loseB, $room_id, $datetime);
    // 處理 updatePreviousMatchResult 回傳的 $log 也一併推 flash
    if ($logA) {
      $_SESSION['flash'][] = ['type' => 'info', 'message' => $logA];
      $updated++;
    }
    if ($logB) {
      $_SESSION['flash'][] = ['type' => 'info', 'message' => $logB];
      $updated++;
    }
    $_SESSION['flash'][] = [
      'type'    => 'info',
      'message' => "📌 新增資料：$datetime $nameA vs $nameB"
    ];
    //$logs[] = "📌 新增資料：$datetime $nameA vs $nameB";
  }

  // 結束後再推一次整體 summary
  $_SESSION['flash'][] = [
    'type'    => 'warning',
    'message' => "⚠️ 已存在 {$count_short_room} 間 (Room ID: {$short_room_id})"
  ];
  $_SESSION['flash'][] = [
    'type'    => 'success',
    'message' => "📦 本次上傳共處理 {$total} 筆資料(更新 {$updated} 筆)"
  ];

  // PRG：完成後重導
  header('Location: /queue.php?debug=1');
exit;

}


/* ----------  處理「動作」按鈕  ---------- */
if (isset($_POST['set_win1'])) {
  $id          = intval($_POST['set_win1']);
  $player_name = trim($_POST['player_name1']);
  /* 1) 先抓資料 */
  $stmt = $db->prepare("
      SELECT name_p1, name_p2
      FROM   arena_unlight
      WHERE  id = :id
      LIMIT  1
  ");
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $_SESSION['upload_success'] = "找不到這筆對戰 (ID=' . $id . ')";
    die('❌ 找不到這筆對戰 (ID=' . $id . ')');
  }
  /* 2) 判斷玩家是 P1 還是 P2 */
  if ($row['name_p1'] === $player_name) {
    // 玩家是 P1 → P1 勝、P2 敗
    $sql = "
          UPDATE arena_unlight
          SET win = 0,  lose = 1, tie = 0,
              ack1 = 1, ack2 = 1
          WHERE id = :id
      ";
  } elseif ($row['name_p2'] === $player_name) {
    // 玩家其實是 P2 → P1 敗、P2 勝
    $sql = "
          UPDATE arena_unlight
          SET win = 1,  lose = 0, tie = 0,
              ack1 = 1, ack2 = 1
          WHERE id = :id
      ";
  } else {
    $_SESSION['upload_success'] = '名稱不符，請重新整理後再試！';
    die('❌ 名稱不符，請重新整理後再試！');
  }
  /* 3) 執行更新 */
  $db->prepare($sql)->execute([':id' => $id]);
  /* 4) 更新成功 → 導回 */
  $_SESSION['upload_success'] = "$player_name 勝！";
  header('Location: queue.php?debug=1');      // ← 導回 queue.php?debug=1
  //header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['set_win2'])) {
  $id          = intval($_POST['set_win2']);
  $player_name = trim($_POST['player_name2']);
  /* 1) 先抓資料 */
  $stmt = $db->prepare("
      SELECT name_p1, name_p2
      FROM   arena_unlight
      WHERE  id = :id
      LIMIT  1
  ");
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $_SESSION['upload_success'] = '找不到這筆對戰 (ID=' . $id . ')';
    die('❌ 找不到這筆對戰 (ID=' . $id . ')');
  }
  /* 2) 判斷玩家是 P1 還是 P2 */
  if ($row['name_p1'] === $player_name) {
    // 玩家是 P1 → P1 勝、P2 敗
    $sql = "
          UPDATE arena_unlight
          SET win = 0,  lose = 1, tie = 0,
              ack1 = 1, ack2 = 1
          WHERE id = :id
      ";
  } elseif ($row['name_p2'] === $player_name) {
    // 玩家其實是 P2 → P1 敗、P2 勝
    $sql = "
          UPDATE arena_unlight
          SET win = 1,  lose = 0, tie = 0,
              ack1 = 1, ack2 = 1
          WHERE id = :id
      ";
  } else {
    $_SESSION['upload_success'] = '名稱不符，請重新整理後再試';
    die('❌ 名稱不符，請重新整理後再試！');
  }
  /* 3) 執行更新 */
  $db->prepare($sql)->execute([':id' => $id]);
  /* 4) 回饋訊息 */
  $_SESSION['upload_success'] = '感謝上傳！勝場已更新！';
  header('Location: queue.php?debug=1');      // ← 導回 queue.php?debug=1
  //header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['tie'])) {
  $id = $_POST['tie'];
  $sql_insert = "UPDATE `leway_db`.`arena_unlight` 
  SET `ack1` = :ack1 ,`ack2` = :ack2,`tie` = :tie
  WHERE `id` = :id";
  $stmt_insert = $db->prepare($sql_insert);
  $stmt_insert->execute([
    ':ack1' => 1,
    ':ack2' => 1,
    ':tie' => 1,
    ':id' => $id,
  ]);
  $_SESSION['upload_success'] = '平！';
  header('Location: queue.php?debug=1');      // ← 導回 queue.php?debug=1
  //header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['is_read'])) {
  $id = $_POST['is_read'];
  $sql_insert = "UPDATE `leway_db`.`arena_unlight`
    SET `is_read` = :is_read
    WHERE `id` = :id";
  $stmt_insert = $db->prepare($sql_insert);
  $stmt_insert->execute([
    ':is_read' => 1,
    ':id'      => $id,
  ]);
  $_SESSION['upload_success'] = '標示已讀';
  header('Location: queue.php?debug=1');      // ← 導回 queue.php?debug=1
  //header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['del'])) {
  $id = $_POST['del'];
  $sql_insert = "DELETE FROM `leway_db`.`arena_unlight` WHERE `id` = :id";
  $stmt_insert = $db->prepare($sql_insert);
  $stmt_insert->execute([':id' => $id]);
  $_SESSION['upload_success'] = '大刀一砍！';
  header('Location: queue.php?debug=1');      // ← 導回 queue.php?debug=1
  //header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
}


// ✅ PRG：如果是 POST，處理完直接重導回自己，避免 F5 重新送出表單
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ✅ 一般欄位統一存進 SESSION
  foreach ($_POST as $key => $value) {
    $_SESSION[$key] = $value;
  }
  // ✅ 最後導回自己
  header('Location: /queue.php?debug=1');
exit;

}

// 從 session 取得 combined，如果不存在則預設為 false
$combined = $_SESSION["combined"] ?? false;
$username = $_SESSION["username"];
$permission = $_SESSION["ack"] ?? 0;
$check_ack = $_SESSION['check_ack'] ?? 3;
$updated_history = $_SESSION['updated_history'] ?? 0;
$order = $_SESSION['order'] ?? 0;




if ($order == 1) { //查詢已略
  $order_sql = "ASC";
} else { //查詢已略
  $order_sql = "DESC";
}
//$check_ack =0;
if ($check_ack == 1) { //查詢已略
  $check_ack_sql = "AND ((ack1=1) OR (ack2=1) )";
} elseif ($check_ack == 2) { // 雙略
  $check_ack_sql = "AND (ack1=1 AND ack2=1)";
} elseif ($check_ack == 3) { // 未讀
  $check_ack_sql = "AND ( (ack1=1 OR ack2=1) AND is_read=0)";
} elseif ($check_ack == 4) { //已讀
  $check_ack_sql = "AND ((ack1=1) OR (ack2=1)) AND (is_read=1)";
} else {
  $check_ack_sql = '';
}




?>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Unlight</title>

  <!-- <script>
        var divs= $("input[name='skill[]']:checked").map(function() { return $(this).val(); }).get();
    </script> -->
  <script>
    function clearAll(ch) {
      var myCheckBox = document.getElementsByTagName('input');
      for (var i = 0; i < myCheckBox.length; i++) {
        if (myCheckBox[i].type == "checkbox") {
          myCheckBox[i].checked = false;
        }
      }
    }
  </script>

  <style>
    /* 回到頂部按鈕 */
    #backToTop,
    #goToBottom {
      position: fixed;
      right: 30px;
      width: 50px;
      height: 50px;
      background-color: #007bff;
      color: white;
      border: none;
      border-radius: 50%;
      cursor: pointer;
      display: none;
      /* 預設隱藏 */
      justify-content: center;
      align-items: center;
      font-size: 18px;
      box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.2);
      transition: opacity 0.3s ease;
      z-index: 999;
      /* 確保按鈕在最上層 */
    }

    /* 回到頂部按鈕 (▲) */
    #backToTop {
      bottom: 90px;
    }

    /* 滾到底部按鈕 (▼) */
    #goToBottom {
      bottom: 30px;
    }

    #backToTop:hover,
    #goToBottom:hover {
      background-color: #0056b3;
    }


    /* ✅ 浮動按鈕 */
    #historyBtn {
      position: fixed;
      right: 32px;
      bottom: 110px;
      transform: translateY(50%);
      background-color: #28a745;
      color: white;
      border: none;
      border-radius: 10px;
      padding: 12px 16px;
      font-size: 16px;
      cursor: pointer;
      box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.2);
      transition: background-color 0.3s ease, transform 0.3s ease;
      z-index: 999;
      /* 確保在最上層 */
    }

    /* ✅ 滑鼠移上去時改變顏色 */
    #historyBtn:hover {
      background-color: #218838;
    }

    /* ✅ 讓按鈕固定在「頂部按鈕」與「底部按鈕」之間 */
    @media (max-width: 768px) {
      #historyBtn {
        right: 15px;
        padding: 10px 14px;
        font-size: 14px;
      }
    }




    .fight-form {
      display: flex;
      flex-direction: row;
      align-items: center;
      justify-content: center;
    }


    /* ✅ 偶數行灰色 */
    .gray-bg {
      background-color: rgba(181, 181, 181, 0.51);
    }

    /* ✅ 奇數行白色 */
    .white-bg {
      background-color: #ffffff;
    }

    /* 組合1 & 組合2組合 */
    /* .team {
      display: flex;
      align-items: center;
      gap: 10px;
    } */



    /* VS 置中 */
    .vs {
      font-size: 24px;
      font-weight: bold;
      /* padding: 10px; */
    }

    .timestamp {
      font-size: 12px;
      background-color: #f8f9fa;
      padding: 0px 6px;
      /* margin: 3px 0px; */
      border-radius: 5px;
      display: inline-block;
    }

    .vs-container {
      text-align: center;
      width: 100px;
      /* margin-right: 40px; */
    }

    .btn {
      padding: 3px 6px;
    }

    .skiped-btn {
      background: rgb(124, 105, 105);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      /* padding: 3px 6px; */
      margin: 2px 2px;
      /* transform: scale(1.1); */
      width: 44px;
    }

    .skip-btn {
      background: rgb(181, 181, 191);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 3px 14px;
      margin: 0px 2px;
    }

    .set-tie-btn {
      background: rgb(0, 193, 61);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      /* padding: 3px 8px; */
      margin: 2px 5px;
      /* transform: scale(1.1); */
      /* width: 33px; */
    }

    .set-del-btn {
      background: rgb(199, 39, 18);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      /* padding: 3px 8px; */
      margin: 2px 5px;
      /* transform: scale(1.1); */
      width: 33px;
    }




    /* COST 按鈕 */
    .cost-btn {
      width: auto;
      padding: 5px 5px;
      font-weight: bold;
      background: white;
      border-radius: 8px;
      border: 2px solid #ddd;
      box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
    }

    /* win rate 按鈕 */
    .wr-btn {
      width: 70px;
      /* padding: 5px 0px; */
      font-weight: bold;
      background: white;
      border-radius: 8px;
      border: 2px solid #ddd;
      box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
    }

    /* 勝利按鈕 */
    .enemy-win-btn {
      background: #ff4e4e;
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 3px 15px;
      width: 60px;
      /* transform: scale(1.1); */
    }

    .player-win-btn {
      background: rgb(60, 153, 253);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 3px 15px;
      width: 60px;
      /* transform: scale(1.1); */
    }

    .tie-btn {
      background: rgb(0, 193, 61);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 3px 15px;
      width: 60px;
      /* transform: scale(1.1); */
    }



    /* 勝率按鈕 */
    .winrate-container {
      margin-left: 40px;
    }

    .winrate-btn {
      background: green;
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 5px 15px;
    }



    .abgne-menu input[type="checkbox"] {
      display: none;
      /* 隱藏 checkbox */
    }

    .abgne-menu input[type="checkbox"]+label {
      display: inline-block;
      background-color: #ccc;
      cursor: pointer;
      padding: 5px;
      margin: 0 2px;
      border-radius: 5px;
      position: relative;
      width: 75px;
      height: 75px;
      overflow: hidden;
      transition: background-color 0.3s ease, border 0.3s ease;
      border: 5px solid transparent;
      /* 預設無外框 */
      box-sizing: border-box;
      /* 確保邊框內縮，不影響內部內容 */
    }

    .abgne-menu input[type="checkbox"]:checked+label {
      background-color: pink;
      border: 5px solid red;
      /* 勾選時加上粉紅色外框 */
    }

    /* 讓圖片放大 150% 但不影響 input checkbox */
    .cropped-img {
      width: 130%;
      height: 100%;
      object-fit: cover;
      object-position: top left;
      /* 讓圖片從左上角開始顯示 */
      transform: scale(1.2);
      /* 放大 150% */
      transition: transform 0.3s ease;
      /* 平滑過渡效果 */
    }

    .sticky-top {
      position: sticky;
      top: 0;
      z-index: 1000;
      background-color: dimgray;
      border-bottom: 2px solid #ddd;
      padding: 10px;
      border-radius: 5px;
      width: 380px;
      margin: auto;
      /* 自動左右外邊距，水平置中 */
    }

    .character-list {
      text-align: center;
    }

    .player-input {
      /* margin-top: 10px; */
      display: flex;
      flex-direction: column;
      gap: 2px;
      width: 90px;
      align-items: center;
    }

    .player-input input {
      /* width: 70%; */
      padding: 5px;
      border-radius: 5px;
    }

    .characters {
      display: flex !important;
      flex-direction: row;
      align-items: flex-start;
      height: 84px;
      overflow: hidden;
      display: inline-block;
      border-radius: 5px;
    }


    .player-name {
      padding: 5px 6px;
    }
  </style>

</head>
<div class="navbar">
  <?php
  print $head_bar;


  ?>
</div>


<body>

  <!-- Go To Top Button -->
  <button id="backToTop" onclick="scrollToTop()">▲</button>
  <!-- Go To Bottom Button -->
  <button id="goToBottom" onclick="scrollToBottom()">▼</button>

  <script>
    // 監聽滾動事件，決定是否顯示按鈕
    window.onscroll = function() {
      let topButton = document.getElementById("backToTop");
      let bottomButton = document.getElementById("goToBottom");
      let scrollTop = document.documentElement.scrollTop;
      let scrollHeight = document.documentElement.scrollHeight;
      let clientHeight = document.documentElement.clientHeight;

      if (scrollTop > 200) {
        topButton.style.display = "flex"; // 顯示回到頂部按鈕
      } else {
        topButton.style.display = "none"; // 隱藏按鈕
      }

      if (scrollTop + clientHeight < scrollHeight - 200) {
        bottomButton.style.display = "flex"; // 顯示滾到底部按鈕
      } else {
        bottomButton.style.display = "none"; // 隱藏按鈕
      }
    };

    // 點擊按鈕回到頂部
    function scrollToTop() {
      window.scrollTo({
        top: 0,
        behavior: "smooth" // 平滑滾動效果
      });
    }

    // 點擊按鈕滾到底部
    function scrollToBottom() {
      window.scrollTo({
        top: document.documentElement.scrollHeight,
        behavior: "smooth" // 平滑滾動效果
      });
    }
  </script>

  <!-- <h3 style="margin:5px"> 對手(多選)</h3> -->
  <div class="abgne-menu" id="about" style="margin:10px">
    <?php
    if (!empty($_SESSION['flash'])) {
      foreach ($_SESSION['flash'] as $f) {
        // Bootstrap alert
        /* echo "<div class='alert alert-{$f['type']}'>{$f['message']}</div>"; */
      }
      unset($_SESSION['flash']);
    }
    /* 1已略
    2雙已略
    3未讀
    4已讀
    20250822 2.3合併 -> 待處理 ;移除1*/
    if ($ack) {
      print '
        <form method="POST">

        <div style="text-align:center; margin:10px 0;">

          <!-- JSON 折疊按鈕 -->
          <button class="btn btn-primary"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#jsonInput"
                  style="margin-bottom:15px;">
              📥 貼上 JSON（點擊展開）
          </button>

          <!-- 折疊內容 -->
          <div id="jsonInput" class="collapse mt-2 mb-3">
              <div style="text-align:center;">
                  <textarea name="ws_json"
                            class="form-control"
                            style="max-width:900px; min-height:200px; margin:0 auto;"
                            placeholder="請貼上 WebSocket 資料"></textarea>

                  <button type="submit" name="upload_json"
                          class="btn btn-success mt-3">
                      解析並上傳佇列
                  </button>
              </div>
          </div>

          <!-- 查詢按鈕列（現在確定在 form 內） -->
          <div style="margin-top:15px;">
              <button type="submit" name="manual_update" value="1" class="btn btn-secondary">
                  手動更新
              </button>
              查詢：
              <button type="submit" class="btn btn-info"    name="check_ack" value="2">雙略</button>
              <button type="submit" class="btn btn-info"    name="check_ack" value="3">未讀</button>
              <button type="submit" class="btn btn-primary" name="check_ack" value="4">已讀</button>
              <button type="submit" class="btn btn-success" name="updated_history" value="1">近期對戰</button>
              <button type="submit" class="btn btn-default" name="order" value="0">新▼</button>
              <button type="submit" class="btn btn-warning" name="order" value="1">舊▼</button>
          </div>

        </div>

        </form>
      ';
    }

    print '<div class="main"></div>';
    if (isset($_POST['ch']) && !isset($_POST['up']) && !isset($_POST['show_all'])) {
      $_SESSION["combined"] = 0;
      $combined = 0;
      $status = "status1";
      for ($i = 0; $i < 3; $i++) {
        $j = $i + 1;
        ${"e" . $j} = $_POST['ch'][$i] ?? 0;
      }
    } elseif (isset($_POST["e1"])) {
      $status =  "state2";
      $e1 = $_POST['e1'];
      $e2 = $_POST['e2'];
      $e3 = $_POST['e3'];
    } elseif (isset($_SESSION["e1"])) {
      $status =  "state3";
      $e1 = $_SESSION['e1'];
      $e2 = $_SESSION['e2'];
      $e3 = $_SESSION['e3'];
    }
    $sql = "SELECT COUNT(*) FROM arena_unlight WHERE (ack1+ack2> 0) AND(win+lose+tie<1) AND update_time >= DATE_SUB(NOW(), INTERVAL 30 DAY);";/* (ack1+ack2> 0) AND(win+lose+tie<1) ack1=0 OR ack2=0*/
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $count = $stmt->fetchColumn();
    print '<div style="text-align:center;"><h4>佇列【Queue】- 已略共 ' . $count . ' 筆。</h4></div>';

    $flag = 1;
    $use_flag = 1;
    $index = 0; // 追蹤行數
    $result = 0;
    //$player_name = '';

    //print "updated_history=$updated_history";
    //近期對戰
    if ($updated_history) {
      $sql = "SELECT *
          FROM arena_unlight 
          ORDER BY update_time DESC
          LIMIT 100";
    } else {
      $sql = "SELECT * FROM arena_unlight  WHERE win+lose+tie<1  $check_ack_sql AND update_time >= DATE_SUB(NOW(), INTERVAL 30 DAY) order by update_time $order_sql;";
    }
    $win_sum = 0;
    $lose_sum = 0;
    // … 先前程式不變 …

    // 執行你的 SQL
    $stmt2 = $db->query($sql);
    $stmt2->setFetchMode(PDO::FETCH_ASSOC);

    // 逐筆輸出
    while ($row = $stmt2->fetch()) {
      // 交替背景
      $bg_class = ($index % 2 === 0) ? "gray-bg" : "white-bg";
      $index++;

      // 基本欄位
      $id           = $row['id'];
      $rawDate      = $row['update_time'];
      $showDate     = date('m-d H:i', strtotime($rawDate));
      $admin        = $row['username'];
      $region       = $row['region'];

      // Player 1 資料
      $player_name1 = $row['name_p1'];
      $bp1          = $row['bp_p1'];
      $win1         = $row['win_p1'];
      $draw1        = $row['draw_p1'];
      $lose1        = $row['lose_p1'];

      // Player 2 資料
      $player_name2 = $row['name_p2'];
      $bp2          = $row['bp_p2'];
      $win2         = $row['win_p2'];
      $draw2        = $row['draw_p2'];
      $lose2        = $row['lose_p2'];

      $ack1         = $row['ack1'];
      $ack2         = $row['ack2'];
      $is_read      = $row['is_read'];

      $result_p2_win = $row['win'];
      $result_p2_lose = $row['lose'];
      $result_p2_tie = $row['tie'];

      if ($result_p2_win + $result_p2_lose + $result_p2_tie == 1) {
        $result = 1;
      }

      print '<form action="queue.php?debug=1" method="POST" class="fight-form">';
      print '<div class="fight-container ' . $bg_class . '">';

      // ==== 組合1 (e1,e2,e3) ====
      $cost_sum = 0;
      //$cost_arr = [];
      $img_arr  = '';

      print '<div class="team">';
      foreach (['e1', 'e2', 'e3'] as $col) {
        $charId = $row[$col];
        $stmt3 = $db->prepare("SELECT ico, cost FROM unlight WHERE id = :id");
        $stmt3->execute([':id' => $charId]);
        $stmt3->setFetchMode(PDO::FETCH_ASSOC);
        if ($r = $stmt3->fetch()) {
          $ico   = $r['ico'];
          $cost  = $r['cost'];
          $cost_sum += $cost;
          $cost_arr[] = $cost;
          $img_arr .= '<img class="character-img" src="uploads/' . $ico . '" loading="lazy">';
        }
      }
      // 收集 cost
      $cost_arr = array_filter($cost_arr, function ($c) {
        return is_int($c) || ctype_digit((string)$c);
      });
      // 只有當剛好有三張卡的 cost 時，才計算懲罰
      if (count($cost_arr) === 3) {
        // 強制轉型保險
        $c1 = (int)$cost_arr[0];
        $c2 = (int)$cost_arr[1];
        $c3 = (int)$cost_arr[2];
        $punishment = cost_punish(
          (int)($cost_arr[0] ?? 0),
          (int)($cost_arr[1] ?? 0),
          (int)($cost_arr[2] ?? 0)
        );
      } else {
        $punishment = 0;
      }
      $cost_sum += $punishment;

      $cost_sum += $punishment;
      $cost_color = $punishment > 0 ? 'text-danger' : '';

      // 輸出 COST 與圖
      print '<button type="button" class="btn btn-light cost-btn ' . $cost_color . '">'
        . 'COST<br>' . $cost_sum
        . '</button>';
      print '<div class="characters">' . $img_arr . '</div>';
      print '</div>'; // .team

      // 如果有權限，顯示 player1 操作區
      if ($permission) {
        if ($result == 1) {
          print '<div class="player-input">';
          if ($result_p2_lose == 1) {
            print '<button type="button" class="btn btn-secondary player-win-btn">勝</button>';
          } elseif ($result_p2_win == 1) {
            print '<button type="button" class="btn btn-secondary enemy-win-btn">負</button>';
          } elseif ($result_p2_tie == 1) {
            print '<button type="button" class="btn btn-secondary tie-btn">平</button>';
          }
          $url1 = 'fight.php?debug=1&' . http_build_query([
            'player_name' => $player_name1,
            'player2'     => $player_name2
          ]);
          print '<a href="' . htmlspecialchars($url1) . '" class="player-name">' . htmlspecialchars($player_name1) . '</a>';
          print '<button type="button" class="btn btn-light wr-btn">BP ' . $bp1 . '</button>';
          print '</div>';
        } else {
          print '<div class="player-input">';
          if ($ack1) {
            print '<button type="button" class="btn btn-secondary skiped-btn">已略</button>';
          } else {
            print '<button type="button" class="btn btn-default skip-btn">未判</button>';
          }
          $url1 = 'fight.php?debug=1&' . http_build_query([
            'player_name' => $player_name1,
            'player2'     => $player_name2
          ]);
          print '<a href="' . htmlspecialchars($url1) . '" class="player-name">' . htmlspecialchars($player_name1) . '</a>';
          print '<button type="button" class="btn btn-light wr-btn">BP ' . $bp1 . '</button>';
          print '</div>';
        }
      }

      print '</div>'; // .fight-container



      // ========== VS & 操作按鈕 ==========
      print '<div class="vs-container">';
      print '<span class="timestamp">' . regionIcon($region) . '</span><br>';
      print '<span class="timestamp">' . $showDate . '</span><br>';
      print htmlspecialchars($admin);
      print '</div>';


      // ==== 組合2 (u1,u2,u3) ====
      $cost_sum = 0;
      $cost_arr = [];
      $img_arr  = '';
      print '<div class="team">';
      foreach (['u1', 'u2', 'u3'] as $col) {
        $charId = $row[$col];
        $stmt4 = $db->prepare("SELECT ico, cost FROM unlight WHERE id = :id");
        $stmt4->execute([':id' => $charId]);
        $stmt4->setFetchMode(PDO::FETCH_ASSOC);
        if ($r = $stmt4->fetch()) {
          $ico  = $r['ico'];
          $cost = $r['cost'];
          $cost_sum += $cost;
          $cost_arr[] = $cost;
          $img_arr .= '<img class="character-img" src="uploads/' . $ico . '" loading="lazy">';
        }
      }
      $punishment = (count($cost_arr) === 3)
        ? cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2])
        : 0;
      $cost_sum += $punishment;
      $cost_color = $punishment > 0 ? 'text-danger' : '';

      print '<button type="button" class="btn btn-light cost-btn ' . $cost_color . '">'
        . 'COST<br>' . $cost_sum
        . '</button>';
      print '<div class="characters">' . $img_arr . '</div>';

      if ($permission) {

        if ($result == 1) {

          print '<div class="player-input">';
          if ($result_p2_win == 1) {
            print '<button type="button" class="btn btn-secondary player-win-btn">勝</button>';
          } elseif ($result_p2_lose == 1) {
            print '<button type="button" class="btn btn-secondary enemy-win-btn">負</button>';
          } elseif ($result_p2_tie == 1) {
            print '<button type="button" class="btn btn-secondary tie-btn">平</button>';
          }
          $url2 = 'fight.php?debug=1&player_name=' . urlencode($player_name2);
          print '<a href="' . htmlspecialchars($url2) . '" class="player-name">' . htmlspecialchars($player_name2) . '</a>';
          print '<button type="button" class="btn btn-light wr-btn">BP ' . $bp2 . '</button>';
          print '</div>';
        } else {
          print '<div class="player-input">';
          if ($ack2) {
            print '<button type="button" class="btn btn-secondary skiped-btn">已略</button>';
          } else {
            print '<button type="submit" name="skip2" value="' . $id . '" class="btn btn-default skip-btn">未判</button>';
          }
          $url2 = 'fight.php?debug=1&player_name=' . urlencode($player_name2);
          print '<a href="' . htmlspecialchars($url2) . '" class="player-name">' . htmlspecialchars($player_name2) . '</a>';
          print '<button type="button" class="btn btn-light wr-btn">BP ' . $bp2 . '</button>';
          print '</div>';
        }
      }

      print '</div>'; // .team
      print '</form>';
      $result = 0;
    } // end while


    ?>



  </div>

  <!-- jQuery 3 -->
  <script src="bower_components/jquery/dist/jquery.min.js"></script>
  <!-- jQuery UI 1.11.4 -->
  <script src="bower_components/jquery-ui/jquery-ui.min.js"></script>
  <!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
  <script>
    $.widget.bridge('uibutton', $.ui.button);
  </script>
  <!-- Bootstrap 3.3.7 -->
  <script src="bower_components/bootstrap/dist/js/bootstrap.min.js"></script>

  <!-- Morris.js charts -->
  <!--   <script src="bower_components/raphael/raphael.min.js"></script>
  <script src="bower_components/morris.js/morris.min.js"></script> -->

  <!-- Sparkline -->
  <script src="bower_components/jquery-sparkline/dist/jquery.sparkline.min.js"></script>

  <!-- jvectormap -->
  <!--   <script src="plugins/jvectormap/jquery-jvectormap-1.2.2.min.js"></script>
  <script src="plugins/jvectormap/jquery-jvectormap-world-mill-en.js"></script> -->

  <!-- jQuery Knob Chart -->
  <script src="bower_components/jquery-knob/dist/jquery.knob.min.js"></script>
  <!-- daterangepicker -->
  <script src="bower_components/moment/min/moment.min.js"></script>
  <script src="bower_components/bootstrap-daterangepicker/daterangepicker.js"></script>
  <!-- datepicker -->
  <script src="bower_components/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js"></script>
  <!-- Bootstrap WYSIHTML5 -->
  <script src="plugins/bootstrap-wysihtml5/bootstrap3-wysihtml5.all.min.js"></script>
  <!-- Slimscroll -->
  <script src="bower_components/jquery-slimscroll/jquery.slimscroll.min.js"></script>
  <!-- FastClick -->
  <script src="bower_components/fastclick/lib/fastclick.js"></script>
  <!-- AdminLTE App -->
  <script src="dist/js/adminlte.min.js"></script>
  <!-- AdminLTE dashboard demo (This is only for demo purposes) -->
  <script src="dist/js/pages/dashboard.js"></script>
  <!-- AdminLTE for demo purposes -->
  <script src="dist/js/demo.js"></script>

  <!-- Bootstrap CSS (若已引入可略過) -->
  <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> -->

  <!-- Bootstrap JavaScript (必要，讓 collapse 運作) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>