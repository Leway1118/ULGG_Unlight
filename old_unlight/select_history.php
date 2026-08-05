<?php
require "head.php"; // 確保連接到資料庫

// ✅ 查詢歷史戰績
$sql = "SELECT e1, e2, e3, u1, u2, u3, update_time FROM leway_db.arena_unlight ORDER BY update_time DESC LIMIT 20";
$stmt = $db->query($sql);
?>

<!DOCTYPE html>
<html lang="zh-TW">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>選擇歷史戰績角色</title>
    <link rel="stylesheet" href="styles.css"> <!-- 假設有 CSS 樣式 -->
</head>

<body>
    <h2>選擇歷史戰績角色</h2>
    <table border="1">
        <tr>
            <th>敵方角色</th>
            <th>我方角色</th>
            <th>時間</th>
            <th>操作</th>
        </tr>
        <?php while ($row = $stmt->fetch()) { ?>
            <tr>
                <td><?= $row['e1'] ?>, <?= $row['e2'] ?>, <?= $row['e3'] ?></td>
                <td><?= $row['u1'] ?>, <?= $row['u2'] ?>, <?= $row['u3'] ?></td>
                <td><?= $row['update_time'] ?></td>
                <td>
                    <!-- ✅ 點選按鈕回傳角色數據 -->
                    <button onclick="selectCharacters(
                    '<?= $row['e1'] ?>', '<?= $row['e2'] ?>', '<?= $row['e3'] ?>',
                    '<?= $row['u1'] ?>', '<?= $row['u2'] ?>', '<?= $row['u3'] ?>'
                )">選擇</button>
                </td>
            </tr>
        <?php } ?>
    </table>

    <script>
        function selectCharacters(e1, e2, e3, u1, u2, u3) {
            // ✅ 回傳選擇的角色到主頁面
            if (window.opener) {
                window.opener.setSelectedCharacters(e1, e2, e3, u1, u2, u3);
                window.close(); // ✅ 選擇完成後關閉 POP 視窗
            }
        }
    </script>
</body>

</html>