<?php
session_start();
require_once __DIR__ . '/../../config.php';   // ⭐ 必須包含資料庫設定
require_once __DIR__ . '/_admin_gate.php';

$seoTitle = '黑單 / 觀察玩家管理 | UL.GG';
$pageTitleFull = '黑單 / 觀察玩家管理';
$pageTitleText = '黑單 / 觀察';
$activeMenu = 'admin_watch';

ob_start();

/* ===== 參數 ===== */
$q      = trim($_GET['q'] ?? '');
$region = $_GET['region'] ?? '';
$status = $_GET['status'] ?? '';
$source = $_GET['source'] ?? '';

$where  = [];
$params = [];

/* 關鍵字 */
if ($q !== '') {
  $where[] = 'player_name LIKE :q';
  $params[':q'] = '%' . $q . '%';
}

/* Region */
if (in_array($region, ['TW', 'JP'], true)) {
  $where[] = 'region = :region';
  $params[':region'] = $region;
}

/* Status */
if (in_array($status, ['watch', 'black'], true)) {
  $where[] = 'status = :status';
  $params[':status'] = $status;
}

/* Source */
if ($source !== '') {
  $where[] = 'source = :source';
  $params[':source'] = $source;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ===== 分頁設定 =====
$perPage = 20; // 每頁筆數
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$countSql = "
SELECT COUNT(*)
FROM admin_player_watch
$whereSql
";

$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));


$sql = "SELECT
  id,
  player_name,
  region,
  status,
  note,
  source,
  created_at,
  updated_at
FROM admin_player_watch
$whereSql
ORDER BY updated_at DESC
LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($sql);

foreach ($params as $k => $v) {
  $stmt->bindValue($k, $v);
}

$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
  .watch-card {
    background: #1e222b;
    border: 1px solid #2c313c;
    border-radius: 8px;
    padding: 16px;
    color: #e6e6e6;
  }

  .watch-table {
    width: 100%;
    border-collapse: collapse;
  }

  .watch-table th,
  .watch-table td {
    padding: 8px 6px;
    border-bottom: 1px solid #2c313c;
    font-size: 13px;
    text-align: center;
  }

  .watch-table th {
    background: #262b36;
  }

  .badge-watch {
    background: #f1c40f;
    color: #000;
  }

  .badge-black {
    background: #e74c3c;
  }

  .action-btn {
    padding: 4px 8px;
    font-size: 12px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    color: #fff;
  }

  .action-btn.edit {
    background: #3b82f6;
  }

  .action-btn.unlock {
    background: #2ecc71;
    color: #000;
  }

  /* =========================
   黑單 / 觀察頁 篩選區塊
========================= */

  .watch-card form input,
  .watch-card form select {
    background: #11141a !important;
    color: #e6e6e6 !important;
    border: 1px solid #2c313c !important;
    border-radius: 6px;
    padding: 6px 10px;
    font-size: 13px;
    min-width: 140px;
  }

  /* placeholder 顏色 */
  .watch-card form input::placeholder {
    color: #9aa4b2;
  }

  /* select option（下拉展開） */
  .watch-card form select option {
    background: #1e222b;
    color: #e6e6e6;
  }

  /* hover / focus 狀態 */
  .watch-card form input:focus,
  .watch-card form select:focus {
    outline: none;
    border-color: #3b82f6;
    background: #0f1319;
    box-shadow: none;
  }

  /* 搜尋按鈕 */
  .watch-card .action-btn.edit {
    background: linear-gradient(180deg, #3b82f6, #2563eb);
    border: 1px solid rgba(255, 255, 255, 0.15);
  }

  .watch-card .action-btn.edit:hover {
    background: linear-gradient(180deg, #4f8df8, #3b6df2);
  }

  .action-btn.danger {
    background: #e74c3c;
  }

  .action-btn.danger:hover {
    background: #ff6b5c;
  }

  /* =========================
   分頁 UI（UL.GG Dark）
========================= */

  .pagination {
    display: inline-flex;
    gap: 6px;
    padding: 0;
  }

  .pagination li {
    list-style: none;
  }

  .pagination li a {
    display: inline-block;
    min-width: 32px;
    padding: 6px 10px;
    text-align: center;
    font-size: 13px;
    color: #cfd6e4;
    background: #1e222b;
    border: 1px solid #2c313c;
    border-radius: 6px;
    text-decoration: none;
    transition: all .15s ease;
  }

  /* hover */
  .pagination li a:hover {
    background: #2a2f3a;
    color: #ffffff;
  }

  /* active page */
  .pagination li.active a {
    background: linear-gradient(180deg, #3b82f6, #2563eb);
    color: #fff;
    border-color: rgba(255, 255, 255, 0.25);
    font-weight: 600;
  }

  /* disabled */
  .pagination li.disabled a {
    color: #666;
    background: #14171d;
    border-color: #1f2430;
    pointer-events: none;
  }

  /* 上下頁箭頭微調 */
  .pagination li a {
    line-height: 1.2;
  }

  @media (max-width: 768px) {
    .pagination {
      gap: 4px;
    }

    .pagination li a {
      padding: 6px 8px;
      min-width: 28px;
      font-size: 12px;
    }
  }

  .watch-card .pagination {
    position: sticky;
    bottom: 0;
    padding: 8px 0;
    background: linear-gradient(180deg, rgba(30, 34, 43, 0), #1e222b 40%);
  }

 
</style>


<?php
/* 放入你的 PHP程式    */
?>
<div style="margin-top:6px;font-size:12px;color:#9aa4b2;">
  顯示第 <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> 筆
</div>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">

      <h1 class="page-title">🚫 黑單 / 🔍 觀察玩家管理</h1>
      <?php if (!empty($_SESSION['permission']) && $_SESSION['permission'] >= 2): ?>
        <div style="margin-bottom:15px;text-align:right;">
          <button class="action-btn danger" id="btn-add-watch">
            🚫 新增黑單 / 觀察
          </button>
        </div>
      <?php endif; ?>
      <!-- 篩選 -->
      <div class="watch-card mb-3">
        <form method="get" class="d-flex gap-2 flex-wrap">
          <input type="text" name="q" placeholder="玩家名稱"
            value="<?= htmlspecialchars($q) ?>">

          <select name="region">
            <option value="">全部伺服器</option>
            <option value="TW" <?= $region === 'TW' ? 'selected' : '' ?>>TW</option>
            <option value="JP" <?= $region === 'JP' ? 'selected' : '' ?>>JP</option>
          </select>

          <select name="status">
            <option value="">全部狀態</option>
            <option value="watch" <?= $status === 'watch' ? 'selected' : '' ?>>觀察</option>
            <option value="black" <?= $status === 'black' ? 'selected' : '' ?>>黑單</option>
          </select>

          <select name="source">
            <option value="">全部來源</option>
            <option value="legacy" <?= $source === 'legacy' ? 'selected' : '' ?>>legacy</option>
            <option value="manual" <?= $source === 'manual' ? 'selected' : '' ?>>manual</option>
          </select>

          <button class="action-btn edit" type="submit">搜尋</button>
        </form>
      </div>

      <!-- 表格 -->
      <div class="watch-card">
        <table class="watch-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>玩家名稱</th>
              <th>伺服器</th>
              <th>狀態</th>
              <th>來源</th>
              <th>備註</th>
              <th>更新時間</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="8">目前沒有資料</td>
              </tr>
              <?php else: foreach ($rows as $r): ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= htmlspecialchars($r['player_name']) ?></td>
                  <td><?= $r['region'] ?></td>
                  <td>
                    <?php if ($r['status'] === 'black'): ?>
                      <span class="badge badge-black">🚫 黑單</span>
                    <?php else: ?>
                      <span class="badge badge-watch">🔍 觀察</span>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($r['source']) ?></td>
                  <td style="text-align:left;max-width:300px;">
                    <?= nl2br(htmlspecialchars($r['note'])) ?>
                  </td>
                  <td><?= $r['updated_at'] ?></td>
                  <td>
                    <!-- 編輯（兩種狀態都能用） -->
                    <button class="action-btn edit btn-edit"
                      data-id="<?= $r['id'] ?>"
                      data-status="<?= $r['status'] ?>"
                      data-note="<?= htmlspecialchars($r['note'], ENT_QUOTES) ?>">
                      編輯
                    </button>

                    <?php if ($r['status'] === 'black'): ?>
                      <!-- 黑單 → 解封 -->
                      <button class="action-btn unlock btn-unlock"
                        data-id="<?= $r['id'] ?>">
                        解封
                      </button>

                    <?php elseif ($r['status'] === 'watch'): ?>
                      <!-- 觀察 → 黑單 -->
                      <button class="action-btn danger btn-black"
                        data-id="<?= $r['id'] ?>">
                        黑單
                      </button>
                    <?php endif; ?>
                  </td>

                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
        <?php if ($totalPages > 1): ?>
          <div style="margin-top:15px;text-align:center;">
            <ul class="pagination" style="margin:0;">

              <!-- 上一頁 -->
              <li class="<?= $page <= 1 ? 'disabled' : '' ?>">
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>">
                  &laquo;
                </a>
              </li>

              <?php
              $start = max(1, $page - 2);
              $end   = min($totalPages, $page + 2);
              ?>

              <?php for ($i = $start; $i <= $end; $i++): ?>
                <li class="<?= $i === $page ? 'active' : '' ?>">
                  <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                    <?= $i ?>
                  </a>
                </li>
              <?php endfor; ?>

              <!-- 下一頁 -->
              <li class="<?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])) ?>">
                  &raquo;
                </a>
              </li>

            </ul>

            <div style="margin-top:5px;font-size:12px;color:#aaa;">
              共 <?= number_format($totalRows) ?> 筆，第 <?= $page ?> / <?= $totalPages ?> 頁
            </div>
          </div>
        <?php endif; ?>

      </div>

    </div>
  </section>
</div>
<!-- /.content-wrapper -->
<!-- 新增 / 編輯 黑單 or 觀察玩家 -->
<div class="modal fade" id="watchModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="background:#1e222b;color:#fff;">
      <div class="modal-header">
        <h4 class="modal-title">🚫 新增黑單 / 觀察玩家</h4>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>

      <div class="modal-body">
        <div class="form-group">
          <label>遊戲名稱</label>
          <input type="text" id="wm-player" class="form-control" placeholder="請輸入遊戲內名稱">
        </div>

        <div class="form-group">
          <label>伺服器</label>
          <select id="wm-region" class="form-control">
            <option value="TW">TW</option>
            <option value="JP">JP</option>
          </select>
        </div>

        <div class="form-group">
          <label>狀態</label>
          <select id="wm-status" class="form-control">
            <option value="watch">🔍 觀察</option>
            <option value="black">🚫 黑名單</option>
          </select>
        </div>

        <div class="form-group">
          <label>備註（必填）</label>
          <textarea
            id="wm-note"
            class="form-control"
            rows="4"
            placeholder="請填寫原因 / 可疑行為"></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-danger" id="wm-submit">送出</button>
      </div>
    </div>
  </div>
</div>
<!-- 新增 / 編輯 黑單 or 觀察玩家 -->
<script>
  $(function() {

    // 打開 Modal
    $('#btn-add-watch').on('click', function() {
      $('#wm-player').val('');
      $('#wm-note').val('');
      $('#wm-status').val('black');
      $('#wm-region').val('TW');
      $('#watchModal').modal('show');
    });

    // 送出
    $('#wm-submit').on('click', function() {
      const data = {
        player_name: $('#wm-player').val().trim(),
        region: $('#wm-region').val(),
        status: $('#wm-status').val(),
        note: $('#wm-note').val().trim()
      };

      if (!data.player_name || !data.note) {
        alert('請填寫玩家名稱與備註');
        return;
      }

      $.post(
        '/pages/admin/ajax/player_watch_save.php',
        data,
        function(res) {
          if (!res || res.error) {
            alert(res.error || '操作失敗');
            return;
          }
          alert('已儲存');
          $('#watchModal').modal('hide');
        },
        'json'
      );
    });

  });
</script>
<script>
  $(function() {

    $('.btn-unlock').on('click', function() {
      if (!confirm('確定要解封為「觀察」？')) return;

      $.post('/pages/admin/ajax/player_watch_update.php', {
        id: $(this).data('id'),
        status: 'watch',
        note: '[UNBLOCK] 解除黑單'
      }, () => location.reload(), 'json');
    });
    $('.btn-black').on('click', function() {
      if (!confirm('確定要將此玩家設為【黑單】？')) return;

      $.post('/pages/admin/ajax/player_watch_update.php', {
        id: $(this).data('id'),
        status: 'black',
        note: '[BLOCK] 設為黑單'
      }, function() {
        location.reload();
      }, 'json');
    });


    $('.btn-edit').on('click', function() {
      const id = $(this).data('id');
      const note = prompt('請輸入新的備註', $(this).data('note'));
      if (note === null) return;

      $.post('/pages/admin/ajax/player_watch_update.php', {
        id,
        status: $(this).data('status'),
        note
      }, () => location.reload(), 'json');
    });

  });
</script>
<script>
  $(function() {
    $('form[method="get"]').on('submit', function() {
      let $page = $(this).find('input[name="page"]');
      if ($page.length === 0) {
        $('<input>', {
          type: 'hidden',
          name: 'page',
          value: 1
        }).appendTo(this);
      } else {
        $page.val(1);
      }
    });
  });
</script>


<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
?>