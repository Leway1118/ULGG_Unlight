<?php
// ULGG_SHELL_SINGLE_SOURCE_20261027: presentation only.
// Quest branch must not read Session/DB.
?>
  <header class="un-navbar">

    <button class="nav-toggle" type="button" data-toggle="sidebar">
      ☰
    </button>
    <div class="nav-brand">
      <span class="nav-logo">UL.GG</span>
      <span class="nav-subtitle">Unlight Analytics</span>
    </div>
    <div class="nav-right">
      <?php if (($ulggShellMode ?? 'main') === 'quest'): ?>
        <div class="nav-login-area"><a class="nav-login-btn" href="/pages/index.php" rel="nofollow">UL.GG 首頁</a></div>
      <?php else: ?>

      <?php if (!empty($_SESSION['steam_id'])): ?>

        <?php
        // 決定顯示名稱：UL 名優先，其次 Steam 名，再其次是 Steam ID
        $displayName = $_SESSION['username']
          ?? $_SESSION['steam_name']
          ?? $_SESSION['steam_id'];

        // 頭像 fallback
        $avatar = $_SESSION['steam_avatar_full']
          ?? "/assets/favicon/android-chrome-192x192.png";
        if (empty($avatar)) {
          $avatar = "/assets/favicon/android-chrome-192x192.png";
        }
        ?>

        <div class="nav-user-info">
          <div class="user-box" onclick="toggleUserMenu()">

            <img src="<?= htmlspecialchars($avatar) ?>" class="avatar">

            <span class="username"><?= htmlspecialchars($displayName) ?></span>

            <i class="fa fa-caret-down caret"></i>

            <div class="user-dropdown" id="userDropdown">

              <!-- UL.GG 個人頁面（有 UL ID 才顯示） -->
              <?php if ($isVerifiedUL): ?>
                <!-- ack = 1，已綁定 -->
                <a href="/pages/profile.php">
                  <i class="fa fa-user"></i> 我的 UL.GG
                </a>

              <?php elseif ($isApplyingUL): ?>
                <!-- apply = 1，審核中 -->
                <a href="/pages/bind_ulid.php" style="opacity:.7;">
                  <i class="fa fa-clock-o"></i> UL ID 審核中
                </a>

              <?php elseif ($isGuestSteam): ?>
                <!-- ack = 0 & apply = 0 -->
                <a href="/pages/bind_ulid.php">
                  <i class="fa fa-link"></i> 綁定 UL ID
                </a>
              <?php endif; ?>



              <!-- Steam Profile -->
              <a href="https://steamcommunity.com/profiles/<?= $_SESSION['steam_id'] ?>" target="_blank">
                <i class="fab fa-steam"></i> 我的 Steam
              </a>

              <!-- 登出 -->
              <a href="/pages/logout.php" class="logout">
                <i class="fa fa-sign-out"></i> 登出
              </a>
              <!-- 登出所有裝置 -->
              <a href="/pages/logout_all.php"
                class="logout-all"
                onclick="return confirm('這會讓所有裝置都登出，確定嗎？');">
                <i class="fa fa-power-off"></i> 登出所有裝置
              </a>

            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="nav-login-area">
          <a href="/api/auth/steam_start.php"
            class="nav-login-btn">
            登入
          </a>
        </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </header>
