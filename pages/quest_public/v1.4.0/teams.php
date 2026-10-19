<?php
/**
 * Tab 4 - 隊伍比較 (Teams).
 *
 * All-period only. `window` and `duration` are NOT inherited: any such query
 * parameter is explicitly stripped and reported, so a link cannot make this tab
 * look like a filtered view.
 *
 * Phase 2B: teams_all.json now carries published region/Quest associations
 * (identities + sample counts only, each >= min_association_samples). A Quest
 * drill link therefore filters teams precisely - a team is shown only when it
 * really ran that region/Quest. Associations below the threshold are suppressed
 * and never counted.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

if (!qpub_data_available()) {
    qpub_abort_no_data('teams', '隊伍比較');
}

/* Strip time/duration parameters: they are not applicable on this tab. */
$stripped = [];
foreach (['window', 'duration'] as $key) {
    if (array_key_exists($key, $_GET)) {
        $value = qpub_raw($key, 8);
        $stripped[$key] = $value === '' ? '(空)' : $value;
    }
}

$teamsDoc = qpub_read_json('teams_all.json') ?? [];
$minEligible = qpub_is_number($teamsDoc['min_eligible'] ?? null)
    ? (int)$teamsDoc['min_eligible'] : QPUB_TEAM_MIN_ELIGIBLE;
$minQph = qpub_is_number($teamsDoc['min_qph_samples'] ?? null)
    ? (int)$teamsDoc['min_qph_samples'] : QPUB_TEAM_MIN_QPH;
$minValh = qpub_is_number($teamsDoc['min_valh_samples'] ?? null)
    ? (int)$teamsDoc['min_valh_samples'] : QPUB_TEAM_MIN_VALH;
$minAssoc = qpub_team_assoc_min();

/* Context handed over from the Quest tab. */
$context = qpub_param_context(QPUB_DEFAULT_WINDOW);

$sortMap = [
    'qph' => 'qph',
    'valh' => 'valh',
    'composite' => 'composite_score',
    'samples' => 'eligible_samples',
    'name' => 'team',
];
$sort = qpub_param_sort(array_keys($sortMap), 'qph');
$dir = qpub_param_dir('desc');
$teamFilter = qpub_param_team();

/* Precise region/Quest filters (validated against the published associations). */
$scope = qpub_team_scope();
$regionFilter = qpub_param_team_region();
$questFilter = qpub_param_team_quest();

/* A Quest-tab drill link may only carry the legacy context parameters. */
if ($regionFilter === '' && isset($context['from_region'])
    && isset($scope['regions'][$context['from_region']])) {
    $regionFilter = (string)$context['from_region'];
}
if ($questFilter === '' && isset($context['from_quest'])) {
    $name = (string)$context['from_quest'];
    if (isset($scope['quest_names'][$name]) && count($scope['quest_names'][$name]) === 1) {
        $questFilter = (string)$scope['quest_names'][$name][0];
    }
}

$rows = qpub_teams_rows();
if ($teamFilter !== '') {
    $rows = array_values(array_filter(
        $rows,
        static fn(array $row): bool => (string)($row['team'] ?? '') === $teamFilter
    ));
}
if ($regionFilter !== '') {
    $rows = array_values(array_filter($rows, static function (array $row) use ($regionFilter): bool {
        foreach (qpub_team_safe_associations($row)['regions'] as $assoc) {
            if ((string)($assoc['zone_key'] ?? '') === $regionFilter) {
                return true;
            }
        }
        return false;
    }));
}
if ($questFilter !== '') {
    $rows = array_values(array_filter($rows, static function (array $row) use ($questFilter): bool {
        foreach (qpub_team_safe_associations($row)['quests'] as $assoc) {
            if ((string)($assoc['quest_key'] ?? '') === $questFilter) {
                return true;
            }
        }
        return false;
    }));
}
$rows = qpub_rows_sort($rows, $sort, $dir, $sortMap);

$carry = ['region' => $regionFilter, 'quest' => $questFilter, 'team' => $teamFilter];
$teamOptions = ['' => '全部隊伍'];
foreach (qpub_teams_rows() as $row) {
    $name = (string)($row['team'] ?? '');
    if ($name !== '') {
        $teamOptions[$name] = $name;
    }
}
$regionOptions = ['' => '全部地域'];
$catalogByKey = qpub_catalog_by_key();
foreach ($scope['regions'] as $zoneKey => $unused) {
    $label = trim((string)($catalogByKey[$zoneKey]['region_name'] ?? ''));
    $regionOptions[$zoneKey] = ($label === '' ? $zoneKey : $label . ' (' . $zoneKey . ')');
}
$questOptions = ['' => '全部 Quest'];
foreach ($scope['quests'] as $questKey => $assoc) {
    $label = trim((string)($assoc['quest_name'] ?? ''));
    $questOptions[$questKey] = ($label === '' ? $questKey : $label) . '（' . $questKey . '）';
}

/* A real public identity with no published team association is still a real
 * filter; keep it visible in the control and say why the list is empty. */
$regionUnassociated = $regionFilter !== '' && !isset($scope['regions'][$regionFilter]);
if ($regionUnassociated) {
    $label = trim((string)($catalogByKey[$regionFilter]['region_name'] ?? ''));
    $regionOptions[$regionFilter] = ($label === '' ? $regionFilter : $label . ' (' . $regionFilter . ')')
        . '（無已發布關聯）';
}
$questUnassociated = $questFilter !== '' && !isset($scope['quests'][$questFilter]);
if ($questUnassociated) {
    $label = trim((string)($catalogByKey[$questFilter]['quest_name'] ?? ''));
    $questOptions[$questFilter] = ($label === '' ? $questFilter : $label)
        . '（' . $questFilter . '・無已發布關聯）';
}

qpub_render_head('teams', '隊伍比較', ['canonical' => qpub_current_url('teams.php', $carry)]);
qpub_render_tabs('teams', []);
qpub_render_notices();

if ($stripped !== []) {
    $parts = [];
    foreach ($stripped as $key => $value) {
        $parts[] = $key . '=' . $value;
    }
    echo '<div class="qpub-alert qpub-alert--note" role="status">'
        . '隊伍比較僅提供全期間彙總，不繼承時間與 duration 篩選；已忽略：'
        . qpub_h(implode('、', $parts)) . '。</div>';
}

if ($context !== []) {
    $backParams = [];
    if ($regionFilter !== '') {
        $backParams['region'] = $regionFilter;
    }
    if ($questFilter !== '') {
        $backParams['quest'] = $questFilter;
    }
    echo '<div class="qpub-context" role="note">來源脈絡：';
    if (isset($context['from_region'])) {
        echo '地域 <code>' . qpub_h($context['from_region']) . '</code>　';
    }
    if (isset($context['from_quest'])) {
        echo 'Quest <code>' . qpub_h($context['from_quest']) . '</code>　';
    }
    echo '<a class="qpub-btn qpub-btn--ghost" href="'
        . qpub_hu(qpub_url('quest.php', $backParams)) . '">回到 Quest 效率</a>';
    echo '</div>';
}
?>
<div class="qpub-toolbar">
  <?php
    qpub_render_select('teams.php', 'team', '隊伍', $teamOptions, $teamFilter, [
        'region' => $regionFilter, 'quest' => $questFilter,
    ]);
    qpub_render_select('teams.php', 'region', '地域（隊伍關聯）', $regionOptions, $regionFilter, [
        'team' => $teamFilter, 'quest' => $questFilter,
    ]);
    qpub_render_select('teams.php', 'quest', 'Quest（隊伍關聯）', $questOptions, $questFilter, [
        'team' => $teamFilter, 'region' => $regionFilter,
    ]);
    echo '<div class="qpub-field qpub-field--disabled"><span class="qpub-field__label">時間範圍</span>';
    echo '<select class="qpub-select" name="window" disabled aria-describedby="qpub-teams-period">';
    echo '<option value="all">全期間（固定）</option></select></div>';
    qpub_render_reset('teams.php');
    qpub_render_copy_button();
    ?>
</div>

<p class="qpub-hint" id="qpub-teams-period">
  隊伍排行固定為全期間彙總，且門檻為：有效樣本 ≥ <?= qpub_int($minEligible) ?>、
  QP/h 有效樣本 ≥ <?= qpub_int($minQph) ?>、VAL/h 有效樣本 ≥ <?= qpub_int($minValh) ?>。
  未達門檻的隊伍不會出現在公開資料中，本站也不顯示其數量。
  QP/h 為「實際有效 QP ÷ 實際耗時」彙總，非隊伍 QP/h 平均；VAL/h 為估值屬性。
  地域 / Quest 關聯僅發布身分與樣本數（各自 ≥ <?= qpub_int($minAssoc) ?>），
  未達門檻的關聯一律不列出、也不公布其數量。
</p>

<?php if ($regionFilter !== '' || $questFilter !== ''): ?>
<div class="qpub-alert qpub-alert--scope" role="status">
  <strong>關聯篩選</strong>
  僅顯示確實在此公開關聯中出現的隊伍（地域
  <code><?= qpub_h($regionFilter === '' ? '（不限）' : $regionFilter) ?></code>、
  Quest <code><?= qpub_h($questFilter === '' ? '（不限）' : $questFilter) ?></code>）。
  關聯以樣本數 ≥ <?= qpub_int($minAssoc) ?> 為發布門檻；樣本不足的關聯不列入，因此符合條件的隊伍可能少於實際。
  <?php if ($regionUnassociated || $questUnassociated): ?>
  此身分沒有任何已發布的隊伍關聯（可能未觀測，或關聯樣本數未達門檻），因此列表為空。
  <?php endif; ?>
</div>
<?php endif; ?>

<?php qpub_render_meta('all'); ?>

<div class="qpub-panel">
  <div class="qpub-panel__head">
    <h2 class="qpub-panel__title">隊伍比較（全期間，n ≥ <?= qpub_int($minEligible) ?>）</h2>
    <p class="qpub-panel__note">
      共 <?= qpub_int(count($rows)) ?> 支隊伍符合公開門檻與篩選。已匿名化：僅公開隊伍組合名稱與彙總統計，
      不含玩家識別資訊。展開「地域 / Quest」可檢視該隊已發布的關聯與樣本數。
    </p>
  </div>

  <?php if ($rows === []): ?>
  <?= qpub_empty_state(
      ($teamFilter !== '' || $regionFilter !== '' || $questFilter !== '')
          ? '沒有符合篩選的公開隊伍'
          : '目前沒有符合 n≥' . $minEligible . ' 門檻的隊伍',
      '未達門檻的隊伍與未達門檻的關聯都不會公開（也不公布數量）。'
  ) ?>
  <?php else: ?>
  <div class="qpub-table-wrap">
    <table class="qpub-table qpub-table--teams">
      <thead>
        <tr>
          <th scope="col" class="qpub-col-rank">#</th>
          <?php
            qpub_th('teams.php', $carry, 'name', '隊伍', $sort, $dir);
            qpub_th_plain('模式');
            qpub_th('teams.php', $carry, 'samples', '有效樣本', $sort, $dir);
            qpub_th('teams.php', $carry, 'qph', 'QP/h', $sort, $dir);
            qpub_th_plain('QP n');
            qpub_th('teams.php', $carry, 'valh', 'VAL/h', $sort, $dir);
            qpub_th_plain('VAL n');
            qpub_th('teams.php', $carry, 'composite', '綜合分', $sort, $dir, 'qpub-col-optional');
            qpub_th_plain('地域數', 'qpub-col-optional');
            qpub_th_plain('Quest 數');
            qpub_th_plain('地域 / Quest');
            ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $index => $row): ?>
        <?php
            $safe = qpub_team_safe_associations($row);
            $isSelected = (string)($row['team'] ?? '') === $teamFilter;
            ?>
        <tr class="<?= $isSelected ? 'is-selected' : '' ?>">
          <td class="qpub-col-rank"><?= qpub_int($index + 1) ?></td>
          <td class="qpub-col-name qpub-col-strong"><?= qpub_text($row['team'] ?? null) ?></td>
          <td><span class="qpub-tag"><?= qpub_text($row['team_mode'] ?? null) ?></span></td>
          <td class="qpub-col-num"><?= qpub_int($row['eligible_samples'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-strong"><?= qpub_num($row['qph'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-dim"><?= qpub_int($row['qph_samples'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_num($row['valh'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-dim"><?= qpub_int($row['valh_samples'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_num($row['composite_score'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_int(count($safe['regions'])) ?></td>
          <td class="qpub-col-num"><?= qpub_int(count($safe['quests'])) ?></td>
          <td class="qpub-col-name qpub-col-assoc">
            <details class="qpub-details">
              <summary>關聯（<?= qpub_int(count($safe['regions'])) ?> 地域 /
                <?= qpub_int(count($safe['quests'])) ?> Quest）</summary>
              <?php if ($safe['regions'] !== []): ?>
              <p class="qpub-details__label">地域</p>
              <ul class="qpub-details__list">
                <?php foreach ($safe['regions'] as $assoc): ?>
                <li><code><?= qpub_h((string)$assoc['zone_key']) ?></code>
                  <?= qpub_text($assoc['zone_name'] ?? null) ?>
                  <span class="qpub-sub">n=<?= qpub_int($assoc['samples'] ?? null) ?></span></li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
              <?php if ($safe['quests'] !== []): ?>
              <p class="qpub-details__label">Quest</p>
              <ul class="qpub-details__list">
                <?php foreach ($safe['quests'] as $assoc): ?>
                <li><code><?= qpub_h((string)$assoc['quest_key']) ?></code>
                  <?= qpub_text($assoc['quest_name'] ?? null) ?>
                  <span class="qpub-sub">n=<?= qpub_int($assoc['samples'] ?? null) ?></span></li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
              <?php if ($safe['regions'] === [] && $safe['quests'] === []): ?>
              <p class="qpub-details__empty">此隊伍沒有達門檻的地域 / Quest 關聯（未達門檻的關聯不公開）。</p>
              <?php endif; ?>
              <?php if (empty($row['association_complete'])): ?>
              <p class="qpub-details__note">部分關聯因樣本數未達
                <?= qpub_int($minAssoc) ?> 而未發布（數量不公開）。</p>
              <?php endif; ?>
            </details>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php qpub_render_foot(); ?>
