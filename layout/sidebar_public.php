<?php
// ULGG_SHELL_SINGLE_SOURCE_20261027: public menu identical on both pages.
// Main-only panels run only in main mode.
?>
    <aside class="un-sidebar" id="unSidebar">
      <div class="sidebar-section sidebar-title">
        <span class="sidebar-game">UNLIGHT</span>




      </div>


      <nav class="sidebar-nav">
        <!-- 系統 -->
        <a href="/pages/index.php"
          class="sidebar-link <?= $activeMenu === 'index' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">📢</span>
          <span class="sidebar-text">首頁情報 <small>Bulletin</small></span>
        </a>

        <!-- ⭐ 新增：ULGG 杯 -->
        <a href="/pages/tournament/ulgg_cup.php"
          class="sidebar-link <?= $activeMenu === 'ulgg_cup' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">🏟️</span>
          <span class="sidebar-text">ULGG 杯 <small>Cup</small></span>
        </a>

        <!-- 排行 -->
        <div class="sidebar-group">

          <button class="sidebar-group-toggle" data-target="group-team">
            <span class="sidebar-icon">🏆</span>
            <span class="sidebar-text">對戰組合 <small>Team</small></span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-team">

            <a href="/pages/ranking_team.php"
              class="sidebar-link <?= $activeMenu === 'ranking_team' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🏆</span>
              <span class="sidebar-text">組合排行 <small>Ranking</small></span>
            </a>

            <a href="/pages/strength_rank.php"
              class="sidebar-link <?= $activeMenu === 'strength_rank' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🔥</span>
              <span class="sidebar-text">強度排行 <small>Strength</small></span>
            </a>

          </div>
        </div>



        <div class="sidebar-group">
          <button class="sidebar-group-toggle" data-target="group-character">
            <span class="sidebar-icon">🧙</span>
            <span class="sidebar-text">角色 <small>Character</small></span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-character">
            <a href="/pages/ranking_char.php"
              class="sidebar-link <?= $activeMenu === 'ranking_char' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🧙</span>
              <span class="sidebar-text">角色排行 <small>Ranking</small></span>
            </a>

            <a href="/pages/character_analysis.php"
              class="sidebar-link <?= $activeMenu === 'character_analysis' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🧠</span>
              <span class="sidebar-text">角色分析 <small>Analysis</small></span>
            </a>

            <a href="/pages/character_intro.php"
              class="sidebar-link <?= $activeMenu === 'character_intro' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🃏</span>
              <span class="sidebar-text">集卡冊 <small>Intro</small></span>
            </a>




            <!-- ⭐ 新增：自製卡面 Fan ART -->
            <a href="/pages/upload_card_art.php"
              class="sidebar-link <?= $activeMenu === 'upload_card_art' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🎨</span>
              <span class="sidebar-text">自製卡面 <small>Fan Art</small></span>
            </a>
          </div>
        </div>


        <div class="sidebar-group">
          <button class="sidebar-group-toggle" data-target="group-rank">
            <span class="sidebar-icon">📊</span>
            <span class="sidebar-text">排行榜 <small>Rank</small></span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-rank">

            <a href="/pages/bp_rank.php"
              class="sidebar-link <?= $activeMenu === 'bp_rank' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">📈</span>
              <span class="sidebar-text">BP 排行</span>
            </a>

            <a href="/pages/qp_rank.php"
              class="sidebar-link <?= $activeMenu === 'qp_rank' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">📊</span>
              <span class="sidebar-text">QP 排行</span>
            </a>

            <!-- 🏆 歷史名人堂 -->
            <a href="/pages/hall_of_fame.php"
              class="sidebar-link <?= $activeMenu === 'hall_of_fame' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🏆</span>
              <span class="sidebar-text">歷史名人堂</span>
            </a>

          </div>
        </div>

        <!-- ⭐ 新增：ULGG 杯 -->
        

        <!-- 工具 -->
        <div class="sidebar-group">

          <button
            class="sidebar-group-toggle"
            type="button"
            data-target="group-tools">
            <span class="sidebar-icon">🧰</span>
            <span class="sidebar-text">
              實用工具 <small>Tools</small>
            </span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-tools">

            <a href="/pages/quest_map.php"
              class="sidebar-link <?= $activeMenu === 'quest_map' ? 'is-active' : '' ?>">
              <span class="sidebar-text">
                地圖任務查詢 <small>Map</small>
              </span>
            </a>

            <a href="/pages/skills.php"
              class="sidebar-link <?= $activeMenu === 'skills' ? 'is-active' : '' ?>">
              <span class="sidebar-text">
                技能戰術搜尋 <small>Skills</small>
              </span>
            </a>

            <a href="/pages/calculator.php"
              class="sidebar-link <?= $activeMenu === 'calculator' ? 'is-active' : '' ?>">
              <span class="sidebar-text">
                計算 Cost <small>Calculator</small>
              </span>
            </a>

            <!-- QUEST_PUBLIC_MAIN_MENU_20261026 -->
            <a href="/pages/quest_native_preview.php?view=regions"
              class="sidebar-link <?= $activeMenu === 'quest_public' ? 'is-active' : '' ?>">
              <span class="sidebar-text">任務公開分析 <small>Quest Analytics</small></span>
            </a>





          </div>
        </div>
        <a href="/pages/queue.php"
          class="sidebar-link <?= $activeMenu === 'queue' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">👀</span>
          <span class="sidebar-text">觀察大廳 <small>Lobby</small></span>
        </a>

        <!-- RAID_PUBLIC_NAV_GROUP_V1 -->
        <div class="sidebar-group <?= in_array(
          $activeMenu,
          ['raid_observer', 'raid_stats'],
          true
        ) ? 'is-open' : '' ?>">

          <button
            class="sidebar-group-toggle"
            type="button"
            data-target="group-raid">
            <span class="sidebar-icon">🌀</span>
            <span class="sidebar-text">
              公開渦 <small>Raid</small>
            </span>
            <span class="chevron">▾</span>
          </button>

          <div
            class="sidebar-group-items"
            id="group-raid">

            <a href="/pages/raid_observer.php"
              class="sidebar-link sidebar-sub-link <?= $activeMenu === 'raid_observer' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">👁</span>
              <span class="sidebar-text">
                觀察渦 <small>Live</small>
              </span>
            </a>

            <a href="/pages/raid_stats.php"
              class="sidebar-link sidebar-sub-link <?= $activeMenu === 'raid_stats' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">📊</span>
              <span class="sidebar-text">
                Raid 統計 <small>Stats</small>
              </span>
            </a>

          </div>
        </div>

        <a href="/pages/raising/index.php"
          class="sidebar-link <?= $activeMenu === 'raising' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">⚔️</span>
          <span class="sidebar-text">暗房育成 <small>Prototype</small></span>
        </a>

        <?php if (($ulggShellMode ?? 'main') === 'main'): ?>

        <?php if (!empty($_SESSION['permission']) && $_SESSION['permission'] >= 2): ?>

          <!-- =========================
       管理員
  ========================== -->
          <div class="sidebar-divider"></div>

          <div class="sidebar-section-title">🛠 管理員</div>

          <!-- 📊 系統監控 -->
          <a href="/pages/admin_dashboard.php"
            class="sidebar-link <?= $activeMenu === 'admin_dashboard' ? 'is-active' : '' ?>">
            <span class="sidebar-icon">📊</span>
            <span class="sidebar-text">
              系統監控 <small>Dashboard</small>
            </span>
          </a>

          <!-- 👤 會員管理 -->
          <a href="/pages/admin/user_manage.php"
            class="sidebar-link <?= $activeMenu === 'user_manage' ? 'is-active' : '' ?>">
            <span class="sidebar-icon">👤</span>
            <span class="sidebar-text">
              會員管理 <small>Members</small>
            </span>
          </a>

          <?php if ($_SESSION['permission'] >= 3): ?>

            <!-- ☕ 上傳紀錄 -->
            <a href="/pages/admin/payment_qr.php"
              class="sidebar-link <?= $activeMenu === 'payment_qr' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">☕</span>
              <span class="sidebar-text">
                上傳紀錄 <small>Upload</small>
              </span>
            </a>

          <?php endif; ?>


          <!-- =========================
       開發中
  ========================== -->
          <div class="sidebar-divider"></div>

          <div class="sidebar-section-title">🧪 開發中</div>

          <div class="sidebar-group <?= in_array($activeMenu, ['card_tracker', 'raid_stats'], true) ? 'is-open' : '' ?>">

            <!-- 🃏 公牌追蹤 -->
            <a href="/tool/unlight-card-tracker/index.php"
              class="sidebar-link sidebar-sub-link <?= $activeMenu === 'card_tracker' ? 'is-active' : '' ?>">

              <span class="sidebar-icon">🃏</span>

              <span class="sidebar-text">
                公牌追蹤 <small>Tracker</small>
              </span>
            </a>

            <!-- 📊 Raid 統計 -->

          </div>


        <?php endif; ?>


        <!-- =========================
              Data Status (Compact)
          ========================= -->
        <div class="sidebar-divider"></div>

        <div class="sidebar-status">

          <div class="sidebar-status-title">📡 資料狀態</div>

          <!-- <div class="status-source">
            來源：遊戲中可公開觀察之排行事件
          </div> -->

          <div class="status-row">
            <span class="status-server">
              <?= htmlspecialchars($serverLabel) ?>
            </span>
            <span class="status-update">
              <span class="status-dot <?= $statusDot ?>"></span>
              <?= htmlspecialchars($statusText) ?>
            </span>
          </div>

          <div class="status-row">
            <span class="status-server">
              <?= htmlspecialchars($serverLabelJP) ?>
            </span>
            <span class="status-update">
              <span class="status-dot <?= $statusDotJP ?>"></span>
              <?= htmlspecialchars($statusTextJP) ?>
            </span>
          </div>

          <!-- ⭐ 新增：分析樣本數 -->
          <div class="status-row status-sample">
            <span class="status-label">📊 分析樣本數</span>
            <span class="status-value">
              <?= number_format($totalSamples) ?> 場
            </span>
          </div>

        </div>

        <?php endif; ?>


      </nav>
    </aside>
