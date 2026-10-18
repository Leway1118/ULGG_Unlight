<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/_admin_gate.php';

$seoTitle = '會員管理 Managment | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "user_manage"; //.php
$pageTitleFull = '會員管理 Managment | UL.GG 戰績網'; //桌機
$pageTitleText = '會員管理 Managment'; //手機

ob_start();

function renderUserStatus($row)
{
  $bl = (int)($row['black_list'] ?? 0);

  if ($bl === 2) {
    return '<span class="badge badge-ban">黑名單</span>';
  }

  if ($bl === 1) {
    return '<span class="badge badge-pending">觀察中</span>';
  }

  if ((int)$row['ack'] >= 1) {
    return '<span class="badge badge-ok">已驗證</span>';
  }

  if (!empty($row['apply'])) {
    return '<span class="badge badge-pending">待審</span>';
  }

  return '<span class="badge">未申請</span>';
}

function sortLink($label, $field)
{
  $currentSort  = $_GET['sort']  ?? '';
  $currentOrder = $_GET['order'] ?? 'desc';

  // 下一次排序方向 + icon
  $icon = '';
  if ($currentSort === $field) {
    $icon = ($currentOrder === 'asc') ? '▲' : '▼';
    $nextOrder = ($currentOrder === 'asc') ? 'desc' : 'asc';
  } else {
    $nextOrder = 'asc';
  }

  // 保留原本 GET，切換 sort/order，並回到第 1 頁
  $query = array_merge($_GET, [
    'sort'  => $field,
    'order' => $nextOrder,
    'page'  => 1
  ]);

  $url = '?' . http_build_query($query);

  return '<a class="sort-link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">' .
    htmlspecialchars($label) . ' ' . $icon .
    '</a>';
}



/* 參數設定 */

$q      = trim($_GET['q'] ?? '');
$region = $_GET['region'] ?? '';
$status = $_GET['status'] ?? '';


$where  = [];
$params = [];

// ===== 分頁設定 =====
$perPage = 20; // 每頁筆數（後台建議 20~50）
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// ===== 排序設定 =====
$allowedSortFields = [
  'id'       => 'id',
  'username' => 'username',
  'region'   => 'region',
  'ack'      => 'ack',
  'apply'    => 'apply',
  'updated'  => 'update_time'
];

$sort  = $_GET['sort'] ?? '';
$order = $_GET['order'] ?? 'desc';

$sortField = $allowedSortFields[$sort] ?? 'id';
$orderSql  = ($order === 'asc') ? 'ASC' : 'DESC';

// ===== 組 WHERE 條件（你原本的）=====
$where  = [];
$params = [];

// 關鍵字
if ($q !== '') {
  $where[] = '(username LIKE :q OR steamID LIKE :q)';
  $params[':q'] = '%' . $q . '%';
}

// Region
if (in_array($region, ['TW', 'JP'], true)) {
  $where[] = 'region = :region';
  $params[':region'] = $region;
}

// 狀態
switch ($status) {
  case 'verified':
    $where[] = 'ack >= 1 AND (black_list IS NULL OR black_list = 0)';
    break;
  case 'pending':
    $where[] = 'ack = 0 AND apply = 1 AND (black_list IS NULL OR black_list = 0)';
    break;
  case 'black':
    $where[] = 'black_list = 2';
    break;
  case 'observe':
    $where[] = 'black_list = 1';
    break;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countSql = "SELECT COUNT(*) FROM game_user $whereSql";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$dataSql = "
  SELECT
    id,
    username,
    nickname,
    region,
    steamID,
    ack,
    apply,
    black_list
  FROM game_user
  $whereSql
  ORDER BY $sortField $orderSql
  LIMIT :limit OFFSET :offset
";


$stmt = $db->prepare($dataSql);

// bind 原本條件
foreach ($params as $key => $val) {
  $stmt->bindValue($key, $val);
}

// LIMIT / OFFSET 一定要用 PARAM_INT
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);

$stmt->execute();
$users = $stmt->fetchAll();


?>

<style>
  /* ==============================
   UL.GG 會員管理介面（純前端刻版）
   哥德黑色系 / AdminLTE 風格相容
============================== */
  .member-card {
    background: #1e222b;
    border: 1px solid #2c313c;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
    color: #e6e6e6;
  }

  .member-card h3 {
    margin-top: 0;
    font-size: 18px;
    font-weight: bold;
  }

  .member-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
  }

  .member-filter input,
  .member-filter select {
    background: #11141a;
    border: 1px solid #2c313c;
    color: #fff;
    border-radius: 4px;
    padding: 6px 8px;
  }

  .member-table {
    width: 100%;
    border-collapse: collapse;
  }

  .member-table th,
  .member-table td {
    border-bottom: 1px solid #2c313c;
    padding: 8px 6px;
    font-size: 13px;
    text-align: center;
  }

  .member-table th {
    background: #262b36;
    font-weight: bold;
  }

  .badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
  }

  .badge-ok {
    background: #2ecc71;
    color: #000;
  }

  .badge-pending {
    background: #f1c40f;
    color: #000;
  }

  .badge-ban {
    background: #e74c3c;
  }

  .action-btn {
    background: #3b82f6;
    border: none;
    color: #fff;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    min-width: auto;
    /* 取消過小最小寬 */
    white-space: nowrap;
    /* 強制不換行 */
    padding: 4px 10px;
    /* 稍微加寬 */
  }

  .action-btn.danger {
    background: #e74c3c;
  }

  .action-btn.gray {
    background: #555;
  }

  /* 修正 AdminLTE header 蓋住 modal 的問題 */
  .modal {
    z-index: 30000 !important;
  }

  .modal-backdrop {
    z-index: 29000 !important;
  }

  .sort-link {
    color: #e6e6e6;
    text-decoration: none;
  }

  .sort-link:hover {
    color: #9ab6ff;
    text-decoration: underline;
  }

  .sort-link .active {
    font-weight: bold;
  }

  .action-group {
    display: inline-flex;
    gap: 6px;
    white-space: nowrap;
  }

  .action-group .action-btn {
    margin: 0;
    padding: 4px 8px;
    line-height: 1.2;

    min-width: unset;
    text-align: center;
  }

  .admin-quick-check {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .admin-quick-check input {
    width: 180px;
    padding: 6px 10px;
    font-size: 13px;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    background: rgba(255, 255, 255, 0.06);
    color: #fff;
  }

  .admin-quick-check input::placeholder {
    color: #aaa;
  }

  .admin-quick-check button {
    padding: 6px 12px;
    font-size: 13px;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    background: rgba(120, 120, 255, 0.25);
    color: #fff;
    cursor: pointer;
  }

  .admin-quick-check button:hover {
    background: rgba(120, 120, 255, 0.45);
  }

  @media (max-width: 768px) {
    .page-header {
      flex-direction: column;
      align-items: stretch;
      gap: 8px;
    }

    .page-header>.d-flex {
      justify-content: flex-end;
    }
  }

  /* ⭐ 修正 admin quick check 強制橫排 */
  .page-header .admin-quick-check {
    display: inline-flex !important;
    align-items: center;
    gap: 8px;
    width: auto !important;
    margin: 0;
    flex-wrap: nowrap;
  }

  /* 防止 input 撐爆 */
  .page-header .admin-quick-check input {
    width: 180px;
    max-width: 180px;
    flex: 0 0 auto;
  }

  /* 防止 button 換行 */
  .page-header .admin-quick-check button {
    white-space: nowrap;
    flex: 0 0 auto;
  }

  .page-header-actions {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
  }

  .action-btn.observe {
    background: #f1c40f;
    color: #000;
  }

  .admin-select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;

    background:
      rgba(255, 255, 255, .06) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='white'%3E%3Cpath d='M2 4l4 4 4-4'/%3E%3C/svg%3E") no-repeat right 10px center;

    border: 1px solid rgba(255, 255, 255, .25);
    color: #fff;
    padding: 6px 28px 6px 10px;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
  }

  .admin-select option {
    background: #ffffff;
    /* 明確指定，避免系統灰 */
    color: #111;
    /* 保證文字可讀 */
  }

  .page-header-actions>form {
    margin-left: 8px;
  }
</style>

<!-- Content Wrapper -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="page-header d-flex align-items-center justify-content-between">

        <!-- 左側 -->
        <h1 class="page-title">
          👤 會員管理
        </h1>

        <!-- ⭐ 右側：所有操作都包在一起 -->
        <div class="page-header-actions d-flex align-items-center gap-2">

          <?php if (!empty($_SESSION['permission']) && $_SESSION['permission'] >= 2): ?>

            <!-- 🔍 快速檢查 -->
            <form
              action="/pages/profile.php"
              method="get"
              class="admin-quick-check m-0">

              <input type="hidden" name="admin_view" value="1">

              <input
                type="text"
                name="player"
                placeholder="🔍 搜尋玩家"
                autocomplete="username"
                required>

              <button type="submit">
                檢查
              </button>
            </form>

          <?php endif; ?>
          <!-- ➕ 新增黑單 -->
          <form
            method="post"
            action="/pages/admin/ajax/add_black_user.php"
            class="admin-quick-check m-0">

            <input
              type="text"
              name="username"
              placeholder="新增黑單"
              autocomplete="off"
              required>

            <select name="region" class="admin-select" required>
              <option value="" disabled selected>伺服器</option>
              <option value="TW">Steam</option>
              <option value="JP">DMM</option>
            </select>


            <button type="submit">➕ 新增黑單</button>
          </form>



        </div>
      </div>



      <!-- 搜尋 / 篩選區 -->
      <div class="member-card">
        <h3>🔍 會員篩選</h3>
        <form method="get" class="member-filter">
          <input
            type="text"
            name="q"
            placeholder="Username / SteamID"
            value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">

          <select name="region">
            <option value="">全部伺服器</option>
            <option value="TW" <?= ($_GET['region'] ?? '') === 'TW' ? 'selected' : '' ?>>TW</option>
            <option value="JP" <?= ($_GET['region'] ?? '') === 'JP' ? 'selected' : '' ?>>JP</option>
          </select>

          <select name="status">
            <option value="">全部狀態</option>
            <option value="verified" <?= ($_GET['status'] ?? '') === 'verified' ? 'selected' : '' ?>>已驗證</option>
            <option value="pending" <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>待審核</option>
            <option value="black" <?= ($_GET['status'] ?? '') === 'black' ? 'selected' : '' ?>>黑名單</option>
            <option value="observe" <?= ($_GET['status'] ?? '') === 'observe' ? 'selected' : '' ?>>觀察中</option>
          </select>

          <button class="action-btn" type="submit">搜尋</button>
        </form>


      </div>

      <!-- 會員列表 -->
      <div class="member-card">
        <h3>👤 會員列表</h3>
        <div style="overflow-x:auto;">
          <table class="member-table">
            <thead>
              <tr>
                <th><?= sortLink('ID', 'id') ?></th>
                <th><?= sortLink('Username', 'username') ?></th>
                <th>暱稱</th>
                <th><?= sortLink('Region', 'region') ?></th>
                <th>SteamID</th>
                <th><?= sortLink('權等', 'ack') ?></th>
                <th><?= sortLink('申請', 'apply') ?></th>
                <th>狀態</th>
                <th>操作</th>
              </tr>

            </thead>
            <tbody>
              <?php if (empty($users)): ?>
                <tr>
                  <td colspan="9">目前沒有會員資料</td>
                </tr>
              <?php else: ?>
                <?php foreach ($users as $row): ?>
                  <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td>
                      <a href="/pages/fight.php?player_name=<?= rawurlencode($row['username']) ?>&srch_player=1&combined=2"
                        class="player-link">
                        <?= htmlspecialchars($row['username']) ?>
                      </a>
                    </td>

                    <td><?= htmlspecialchars($row['nickname'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['region']) ?></td>
                    <td><?= htmlspecialchars($row['steamID'] ?? '-') ?></td>
                    <td><?= (int)$row['ack'] ?></td>
                    <td><?= !empty($row['apply']) ? '✔' : '-' ?></td>
                    <td><?= renderUserStatus($row) ?></td>
                    <td>
                      <div class="action-group">

                        <!-- 查看 -->
                        <button
                          class="action-btn btn-view"
                          data-user-id="<?= (int)$row['id'] ?>">
                          查看
                        </button>

                        <!-- 審核 -->
                        <?php if (
                          (int)$row['ack'] === 0 &&
                          !empty($row['apply']) &&
                          (int)($row['black_list'] ?? 0) !== 2
                        ): ?>
                          <button
                            class="action-btn gray btn-review"
                            data-user-id="<?= (int)$row['id'] ?>">
                            審核
                          </button>
                        <?php endif; ?>

                        <!-- ⭐ 觀察 / 解除觀察（所有狀態都能顯示） -->
                        <?php if ((int)($row['black_list'] ?? 0) === 1): ?>
                          <!-- 觀察中 -->
                          <button
                            class="action-btn gray btn-unobserve"
                            data-user-id="<?= (int)$row['id'] ?>">
                            解除觀察
                          </button>
                        <?php else: ?>
                          <!-- 正常 or 黑名單 -->
                          <button
                            class="action-btn observe btn-observe"
                            data-user-id="<?= (int)$row['id'] ?>">
                            觀察
                          </button>
                        <?php endif; ?>

                        <!-- 封鎖 / 解封 -->
                        <?php if ((int)($row['black_list'] ?? 0) === 2): ?>
                          <button
                            class="action-btn btn-unblock"
                            data-user-id="<?= (int)$row['id'] ?>">
                            解封
                          </button>
                        <?php else: ?>
                          <button
                            class="action-btn danger btn-block"
                            data-user-id="<?= (int)$row['id'] ?>">
                            封鎖
                          </button>
                        <?php endif; ?>

                      </div>
                    </td>




                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
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
                // 頁碼顯示範圍（避免太長）
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

    </div>
  </section>
</div>

<!-- 查看驗證 Modal -->
<div class="modal fade" id="verifyModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content" style="background:#1e222b;color:#fff;">
      <div class="modal-header">
        <h4 class="modal-title">會員驗證資料</h4>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>

      <div class="modal-body">
        <p><strong>帳號：</strong><span id="vm-username"></span></p>

        <!-- ⭐ 新增：Friend Code -->
        <p>
          <strong>好友代碼（Friend Code）：</strong>
          <span id="vm-friend-code">（未填）</span>
        </p>

        <div id="vm-image-area" style="margin-bottom:15px;">
          <img id="vm-image" src="" style="max-width:100%;border:1px solid #333;">
        </div>

        <div>
          <strong>備註：</strong>
          <div id="vm-note" style="white-space:pre-wrap;margin-top:5px;"></div>
        </div>
      </div>


      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
      </div>
    </div>
  </div>
</div>
<!-- 審核 Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content" style="background:#1e222b;color:#fff;">
      <div class="modal-header">
        <h4 class="modal-title">會員審核</h4>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>

      <div class="modal-body">
        <input type="hidden" id="rm-user-id">

        <div style="margin-bottom:10px;">
          <label>審核結果</label><br>
          <label>
            <input type="radio" name="rm-result" value="approve" checked>
            通過（ACK）
          </label>
          &nbsp;&nbsp;
          <label>
            <input type="radio" name="rm-result" value="reject">
            拒絕
          </label>
        </div>

        <div>
          <label>備註 / 拒絕原因</label>
          <textarea
            id="rm-note"
            class="form-control"
            rows="4"
            placeholder="若拒絕，請填寫原因"></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="rm-submit">送出</button>
      </div>
    </div>
  </div>
</div>
<!-- 封鎖 / 解封 Modal -->
<div class="modal fade" id="blockModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content" style="background:#1e222b;color:#fff;">
      <div class="modal-header">
        <h4 class="modal-title" id="bm-title">封鎖會員</h4>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>

      <div class="modal-body">
        <input type="hidden" id="bm-user-id">
        <input type="hidden" id="bm-action">

        <div>
          <label id="bm-note-label">封鎖原因</label>
          <textarea
            id="bm-note"
            class="form-control"
            rows="4"
            placeholder=""></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" id="bm-submit">確定</button>
      </div>
    </div>
  </div>
</div>




<!-- 查看 -->
<script>
  $(function() {
    $(document).on('click', '.btn-view', function() {
      const userId = $(this).data('user-id');

      $.get(
        '/pages/admin/ajax/user_verify.php', {
          id: userId
        },
        function(res) {
          if (!res || res.error) {
            alert(res.error || '讀取失敗');
            return;
          }

          $('#vm-username').text(res.username);
          $('#vm-friend-code').text(res.friend_code || '（未填）');
          $('#vm-note').text(res.verify_note || '（無）');

          if (res.verify_image) {
            $('#vm-image')
              .attr(
                'src',
                '/pages/admin/ajax/verify_image.php?id='
                  + encodeURIComponent(userId)
              )
              .show();
          } else {
            $('#vm-image').hide();
          }

          $('#verifyModal').modal('show');
        },
        'json'
      );
    });
  });
</script>

<script>
  $(function() {

    // 點擊「審核」
    $(document).on('click', '.btn-review', function() {
      const userId = $(this).data('user-id');
      $('#rm-user-id').val(userId);
      $('#rm-note').val('');
      $('input[name="rm-result"][value="approve"]').prop('checked', true);
      $('#reviewModal').modal('show');
    });

    // 送出審核
    $('#rm-submit').on('click', function() {
      const userId = $('#rm-user-id').val();
      const result = $('input[name="rm-result"]:checked').val();
      const note = $('#rm-note').val().trim();

      if (result === 'reject' && note === '') {
        alert('請填寫拒絕原因');
        return;
      }

      $.post(
        '/pages/admin/ajax/user_review.php', {
          id: userId,
          result: result,
          note: note
        },
        function(res) {
          if (!res || res.error) {
            alert(res.error || '操作失敗');
            return;
          }

          alert('審核完成');
          location.reload();
        },
        'json'
      );
    });

  });
</script>




<script>
  $(function() {

    // 搜尋時，強制 page 回到 1
    $('form.member-filter').on('submit', function() {
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

<script>
  $(function() {

    // 加入觀察
    $(document).on('click', '.btn-observe', function() {
      const userId = $(this).data('user-id');

      $.post(
        '/pages/admin/ajax/user_block.php', {
          id: userId,
          action: 'observe',
          note: ''
        },
        function(res) {
          if (!res || res.error) {
            alert(res.error || '操作失敗');
            return;
          }
          location.reload();
        },
        'json'
      );
    });


    // 解除觀察（回到正常）
    $(document).on('click', '.btn-unobserve', function() {
      const userId = $(this).data('user-id');

      $.post(
        '/pages/admin/ajax/user_block.php', {
          id: userId,
          action: 'unblock',
          note: ''
        },
        function(res) {
          if (!res || res.error) {
            alert(res.error || '操作失敗');
            return;
          }
          location.reload();
        },
        'json'
      );
    });


  });

  $(document).on('click', '.btn-unblock', function() {
    const userId = $(this).data('user-id');

    $.post(
      '/pages/admin/ajax/user_block.php', {
        id: userId,
        action: 'unblock',
        note: ''
      },
      function(res) {
        if (!res || res.error) {
          alert(res.error || '操作失敗');
          return;
        }
        location.reload();
      },
      'json'
    );
  });


  $(document).on('click', '.btn-block', function() {
    const userId = $(this).data('user-id');

    $.post(
      '/pages/admin/ajax/user_block.php', {
        id: userId,
        action: 'block',
        note: ''
      },
      function(res) {
        if (!res || res.error) {
          alert(res.error || '操作失敗');
          return;
        }
        location.reload();
      },
      'json'
    );
  });
</script>
<script>
  $(function() {

    // ➕ 新增黑名單（AJAX）
    $('.page-header-actions form[action*="add_black_user.php"]').on('submit', function(e) {
      e.preventDefault(); // ⛔ 阻止 form 跳頁

      const $form = $(this);

      $.post(
        $form.attr('action'),
        $form.serialize(),
        function(res) {
          if (!res || !res.success) {
            alert(res.error || '新增黑名單失敗');
            return;
          }

          // ✅ 成功後跳回會員管理頁
          window.location.href = res.redirect || '/pages/admin/user_manage.php';
        },
        'json'
      );
    });

  });
</script>


<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
?>