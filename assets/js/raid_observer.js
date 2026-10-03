(() => {
  'use strict';

  // RAID_OBSERVER_POINT_FIRST_ANCHOR_V1

  const root = document.querySelector('[data-raid-observer]');
  if (!root) {
    return;
  }

  const endpoint = root.dataset.endpoint;
  const refreshMilliseconds = 30000;
  const staleAfterSeconds = 30;
  const elements = {
    connection: root.querySelector('[data-role="connection"]'),
    staleNotice: root.querySelector('[data-role="stale-notice"]'),
    activeCount: root.querySelector('[data-role="active-count"]'),
    endedCount: root.querySelector('[data-role="ended-count"]'),
    updatedAge: root.querySelector('[data-role="updated-age"]'),
    activeGrid: root.querySelector('[data-role="active-grid"]'),
    endedGrid: root.querySelector('[data-role="ended-grid"]'),

    // RAID_OBSERVER_ENDED_COLLAPSE_JS_V1
    endedSection: root.querySelector('[data-role="ended-section"]'),
    endedToggle: root.querySelector('[data-role="ended-toggle"]'),
    endedToggleIcon: root.querySelector('[data-role="ended-toggle-icon"]'),
    endedToggleCount: root.querySelector('[data-role="ended-toggle-count"]'),
    endedBody: root.querySelector('[data-role="ended-body"]'),
  };

  let lastPayload = null;
  let requestInFlight = false;

  // RAID_OBSERVER_SERVER_CLOCK_V2
  //
  // Wall clock comes from UL.GG server.
  // performance.now() only measures elapsed time and is not
  // affected when the user's Windows clock is changed.
  let raidServerBaseMs = 0;
  let raidServerPerfMs = 0;

  function syncRaidServerClock(payload) {
    const serverNow =
      numberOrNull(
        payload && payload.server_now_ms
      );

    if (
      serverNow === null
      || serverNow <= 0
    ) {
      return false;
    }

    raidServerBaseMs = serverNow;
    raidServerPerfMs = window.performance.now();

    return true;
  }

  function raidNowMs() {
    return (
      raidServerBaseMs
      + (
        window.performance.now()
        - raidServerPerfMs
      )
    );
  }


  // RAID_OBSERVER_ENDED_COLLAPSE_JS_V1
  // Recently-ended Raid cards are secondary information.
  // Keep them collapsed by default.
  let endedExpanded = false;


  function syncEndedExpanded() {

    if (
      !elements.endedToggle
      || !elements.endedBody
    ) {
      return;
    }


    elements.endedBody.hidden =
      !endedExpanded;


    elements.endedToggle.setAttribute(
      'aria-expanded',
      endedExpanded
        ? 'true'
        : 'false'
    );


    if (
      elements.endedToggleIcon
    ) {
      elements.endedToggleIcon.textContent =
        endedExpanded
          ? '▼'
          : '▶';
    }


    if (
      elements.endedSection
    ) {
      elements.endedSection.classList.toggle(
        'is-collapsed',
        !endedExpanded
      );
    }
  }


  if (
    elements.endedToggle
  ) {

    elements.endedToggle.addEventListener(
      'click',
      () => {

        endedExpanded =
          !endedExpanded;

        syncEndedExpanded();
      }
    );
  }


  syncEndedExpanded();

  // Keep Raid cards in the order they were first seen by this page.
  // API/source ordering may change between refreshes.
  const raidStableOrder = new Map();
  let raidStableOrderSequence = 0;
  const expandedPlayerRaids = new Set();

  // RAID_OBSERVER_PARTICIPANT_PAGER_V1
  const RAID_PARTICIPANT_PAGE_SIZE = 20;
  const raidParticipantPages = new Map();


  // RAID_OBSERVER_COLLAPSED_DETAILS_V1
  // Reward panels are collapsed by default and keep
  // their state across the 30-second refresh.
  const expandedRewardRaids = new Set();

  function numberOrNull(value) {
    if (value === null || value === undefined || value === '') {
      return null;
    }
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  }

  function formatInteger(value, fallback = '—') {
    const parsed = numberOrNull(value);
    return parsed === null ? fallback : Math.max(0, Math.round(parsed)).toLocaleString('zh-TW');
  }

  function formatPercent(value) {
    const ratio = numberOrNull(value);
    return ratio === null ? '—' : `${Math.round(Math.max(0, Math.min(1, ratio)) * 100)}%`;
  }

  function formatAge(timestamp, emptyText = '尚未更新') {
    const seconds = numberOrNull(timestamp);
    if (seconds === null) {
      return emptyText;
    }
    const age = Math.max(0, Math.floor(raidNowMs() / 1000 - seconds));
    if (age < 3) return '剛剛';
    if (age < 60) return `${age} 秒前`;
    if (age < 3600) return `${Math.floor(age / 60)} 分鐘前`;
    return `${Math.floor(age / 3600)} 小時前`;
  }

  function formatRemaining(expiresAt) {
    const milliseconds = numberOrNull(expiresAt);
    if (milliseconds === null || milliseconds <= 0) {
      return '—';
    }
    const remaining = Math.floor((milliseconds - raidNowMs()) / 1000);
    if (remaining <= 0) return '已到期';
    const hours = Math.floor(remaining / 3600);
    const minutes = Math.floor((remaining % 3600) / 60);
    const seconds = remaining % 60;
    if (hours > 0) return `${hours} 小時 ${minutes} 分`;
    if (minutes > 0) return `${minutes} 分 ${seconds} 秒`;
    return `${seconds} 秒`;
  }

  function formatStateLabels(raid) {
    const labels = Array.isArray(raid.state_labels)
      ? raid.state_labels.filter((value) => typeof value === 'string' && value.trim() !== '')
      : [];

    return labels.length > 0 ? labels.join('｜') : '無';
  }

  function statusPresentation(raid) {
    if (raid.status === 'defeated') return { label: '已擊破', className: 'is-defeated' };
    if (raid.status === 'expired') return { label: '已過期', className: 'is-expired' };
    if (raid.status === 'ended') return { label: '已結束', className: 'is-ended' };
    const ratio = numberOrNull(raid.hp_ratio);
    if (raid.status === 'active' && ratio !== null && ratio <= 0.2) {
      return { label: '殘血', className: 'is-critical' };
    }
    return { label: '進行中', className: 'is-active' };
  }

  function appendText(parent, className, text) {
    const node = document.createElement('span');
    node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    return node;
  }

  function createMeta(label, value) {
    const item = document.createElement('div');
    item.className = 'raid-card__meta-item';
    appendText(item, 'raid-card__meta-label', label);
    appendText(item, 'raid-card__meta-value', value);
    return item;
  }

  function resolveStatusIcon(label) {
    const icons = window.RAID_STATUS_ICONS || {};
    const clean = String(label || '').replace(/\.\.\.$/, '');

    const exact = {
      // RAID_STATUS_MAHI_JIKAI_ICON_V2
      '麻': ['mahi', null],
      '壞': ['jikai', null],

      '封': ['huin', null],
      '狂': ['bers', null],
      '毒': ['poison', null],
      '猛': ['poison2', null],
      '低': ['dbuff', null],
      '暈': ['stun', null],
      '恐': ['scare', null],
      '混': ['chaos', null],
      '咒': ['bind', null],
      '臨': ['critical', null],
      '斷': ['dark', null],
      '不': ['immo', null],
      '再': ['rege', null],
      '標': ['target', null],
    };

    if (exact[clean]) {
      const [type] = exact[clean];
      return icons[type] || null;
    }

    if (/^詛\d*$/.test(clean)) {
      return icons.curse || null;
    }

    const patterns = [
      [/^攻\+(\d+)$/, 'atkB'],
      [/^攻-(\d+)$/, 'atkD'],
      [/^防\+(\d+)$/, 'defB'],
      [/^防-(\d+)$/, 'defD'],
      [/^移\+(\d+)$/, 'movB'],
      [/^移-(\d+)$/, 'movD'],
    ];

    for (const [pattern, type] of patterns) {
      const match = clean.match(pattern);
      if (!match) continue;

      const level = Number(match[1]);
      const group = icons[type];

      if (group && typeof group === 'object') {
        return group[level] || null;
      }
    }

    return null;
  }

  function createStateMeta(raid) {
    const item = document.createElement('div');
    item.className = 'raid-card__meta-item raid-card__state';

    appendText(item, 'raid-card__meta-label', '異常狀態');

    const list = document.createElement('div');
    list.className = 'raid-status-list';

    const labels = Array.isArray(raid.state_labels)
      ? raid.state_labels.filter(
          (value) => typeof value === 'string' && value.trim() !== ''
        )
      : [];

    if (labels.length === 0) {
      appendText(list, 'raid-card__meta-value', '無');
      item.appendChild(list);
      return item;
    }

    labels.forEach((label) => {
      const chip = document.createElement('span');
      chip.className = 'raid-status-chip';

      const iconUrl = resolveStatusIcon(label);

      if (iconUrl) {
        const img = document.createElement('img');
        img.className = 'raid-status-icon';
        img.src = iconUrl;
        img.alt = '';
        img.width = 24;
        img.height = 24;
        img.loading = 'lazy';
        chip.appendChild(img);
      } else {
        chip.classList.add('has-no-icon');
      }

      appendText(chip, 'raid-status-text', label);
      list.appendChild(chip);
    });

    item.appendChild(list);
    return item;
  }


  // RAID_OBSERVER_PLAYER_REAL_TABLE_V2
  // RAID_OBSERVER_REMOVE_DPM_COLUMN_V4
  // DPM is intentionally hidden from the Observer player table; backend analytics remain retained.
  // RAID_OBSERVER_REMOVE_CAST_COLUMN_V3
  // Observer player table intentionally omits support/caster attribution because current evidence is not precise enough.

  function supportTopStatusLabel(
    base,
    level
  ) {
    const labels = {
      poison: '毒',
      poison2: '猛毒',
      mahi: '麻痺',

      atkB: '攻+',
      atkD: '攻-',

      defB: '防+',
      defD: '防-',

      movB: '移+',
      movD: '移-',

      bers: '狂',
      stun: '暈',
      huin: '封',
      jikai: '自壞',
      immo: '不死',
      scare: '恐',
      rege: '再生',
      bind: '咒',
      chaos: '混',
      stigma: '聖痕',
      dbuff: '低下',

      sticka: '棍攻',
      stickd: '棍防',

      critical: '臨界',
      control: '操',
      target: '標',
      dark: '斷',
    };

    const cleanBase =
      String(base || '').trim();

    if (
      cleanBase ===
      '__maintenance__'
    ) {
      return '延長';
    }

    let label =
      labels[cleanBase]
      || cleanBase
      || '—';

    const numericLevel =
      numberOrNull(level);

    if (
      numericLevel !== null
      && numericLevel > 0
      && [
        'atkB',
        'atkD',
        'defB',
        'defD',
        'movB',
        'movD',
      ].includes(cleanBase)
    ) {
      label += Math.round(
        numericLevel
      );
    }

    return label;
  }


  function resolveSupportTopStatusIcon(
    base,
    level
  ) {
    const cleanBase =
      String(base || '').trim();

    if (
      !cleanBase
      || cleanBase ===
        '__maintenance__'
    ) {
      return null;
    }

    const icons =
      window.RAID_STATUS_ICONS
      || {};

    const entry =
      icons[cleanBase];

    if (!entry) {
      return null;
    }

    if (
      typeof entry ===
      'string'
    ) {
      return entry;
    }

    if (
      typeof entry ===
      'object'
    ) {
      const numericLevel =
        numberOrNull(level);

      if (
        numericLevel !== null
      ) {
        const key =
          Math.round(
            numericLevel
          );

        if (entry[key]) {
          return entry[key];
        }
      }
    }

    return null;
  }


  function createSupportTopCell(
    player
  ) {
    const td =
      document.createElement('td');

    td.className =
      'raid-player-cast';


    const base =
      String(
        player
        && player.support_top_status_base
        || ''
      ).trim();

    const level =
      numberOrNull(
        player
        && player.support_top_status_level
      );


    if (!base) {
      td.textContent = '—';

      td.title =
        '尚無高可信施術傾向';

      return td;
    }


    const label =
      supportTopStatusLabel(
        base,
        level
      );


    if (
      base ===
      '__maintenance__'
    ) {
      const maintenance =
        document.createElement('span');

      maintenance.className =
        'raid-player-cast-maintenance';

      maintenance.textContent =
        '⏳';

      maintenance.setAttribute(
        'aria-label',
        '延長'
      );

      td.appendChild(
        maintenance
      );

      td.title =
        '施術傾向：延長';

      return td;
    }


    const iconUrl =
      resolveSupportTopStatusIcon(
        base,
        level
      );


    if (iconUrl) {
      const img =
        document.createElement('img');

      img.className =
        'raid-player-cast-icon';

      img.src = iconUrl;

      img.alt = label;

      img.width = 22;
      img.height = 22;

      img.loading =
        'lazy';

      td.appendChild(
        img
      );

    } else {
      /*
       * ICON registry miss.
       * Keep the status identifiable
       * instead of showing an empty cell.
       */
      td.textContent =
        label;
    }


    td.title =
      `施術傾向：${label}`;

    return td;
  }


  function appendTableCell(
    row,
    className,
    text
  ) {
    const td =
      document.createElement('td');

    td.className =
      className;

    td.textContent =
      text;

    row.appendChild(td);

    return td;
  }



  function createRaidPlayerPageButton(
    label,
    page,
    currentPage,
    totalPages,
    onPage
  ) {
    const button =
      document.createElement('button');

    button.type = 'button';
    button.className =
      'raid-player-page-button';

    button.textContent =
      label;

    const targetPage =
      Math.max(
        1,
        Math.min(
          totalPages,
          Number(page) || 1
        )
      );

    if (
      typeof page === 'number'
      && targetPage === currentPage
    ) {
      button.classList.add(
        'is-active'
      );

      button.setAttribute(
        'aria-current',
        'page'
      );
    }

    if (
      label === '‹'
      && currentPage <= 1
    ) {
      button.disabled = true;
    }

    if (
      label === '›'
      && currentPage >= totalPages
    ) {
      button.disabled = true;
    }

    button.addEventListener(
      'click',
      () => {
        if (!button.disabled) {
          onPage(targetPage);
        }
      }
    );

    return button;
  }


  function renderRaidPlayerPager(
    buttons,
    currentPage,
    totalPages,
    onPage
  ) {
    buttons.replaceChildren();

    if (totalPages <= 1) {
      return;
    }

    buttons.appendChild(
      createRaidPlayerPageButton(
        '‹',
        currentPage - 1,
        currentPage,
        totalPages,
        onPage
      )
    );

    const windowSize = 5;
    let firstPage =
      Math.max(
        1,
        currentPage - 2
      );

    let lastPage =
      Math.min(
        totalPages,
        firstPage + windowSize - 1
      );

    firstPage =
      Math.max(
        1,
        lastPage - windowSize + 1
      );

    for (
      let page = firstPage;
      page <= lastPage;
      page += 1
    ) {
      buttons.appendChild(
        createRaidPlayerPageButton(
          String(page),
          page,
          currentPage,
          totalPages,
          onPage
        )
      );
    }

    buttons.appendChild(
      createRaidPlayerPageButton(
        '›',
        currentPage + 1,
        currentPage,
        totalPages,
        onPage
      )
    );
  }


  /*
   * RAID_OBSERVER_PROTOCOL14_PARTICIPANTS_V2
   *
   * Protocol 1.4 public ranking fields:
   *   rank / level / player_name / point
   *
   * participant_count is independent from ranking cache.
   * When ranking has not arrived yet, keep the participant
   * control visible and explicitly show that the list is loading.
   */
  function createParticipantsMeta(raid) {
    const item =
      document.createElement('div');

    item.className =
      'raid-card__meta-item raid-card__participants';


    appendText(
      item,
      'raid-card__meta-label',
      '參與人數'
    );


    const players =
      Array.isArray(raid.players)
        ? raid.players
        : [];


    const totalPlayerPoint =
      players.reduce(
        (sum, player) => {
          const point =
            numberOrNull(
              player
              && player.point
            );

          return (
            sum
            + (
              point === null
                ? 0
                : point
            )
          );
        },
        0
      );


    const raidId =
      String(
        raid.raid_id
        || ''
      );


    const countText =
      `${formatInteger(
        raid.participant_count,
        '0'
      )} / ${formatInteger(
        raid.member_limit
      )}`;


    /*
     * Ranking collector has not populated this Raid yet.
     *
     * Do NOT silently downgrade to plain participant text.
     * Keep a button-shaped control so the UI does not
     * appear to lose functionality between refreshes.
     */
    if (players.length === 0) {
      const pending =
        document.createElement(
          'button'
        );

      pending.type =
        'button';

      pending.className =
        'raid-participants-toggle';

      pending.disabled =
        true;

      pending.textContent =
        `${countText}　名單取得中…`;

      pending.setAttribute(
        'aria-expanded',
        'false'
      );

      pending.title =
        '參與人數已取得；玩家排名名單正在更新';

      item.appendChild(
        pending
      );

      return item;
    }


    const button =
      document.createElement(
        'button'
      );

    button.type =
      'button';

    button.className =
      'raid-participants-toggle';


    const list =
      document.createElement(
        'div'
      );

    list.className =
      'raid-player-list';


    const table =
      document.createElement(
        'table'
      );

    table.className =
      'raid-player-table';


    const thead =
      document.createElement(
        'thead'
      );

    const headRow =
      document.createElement(
        'tr'
      );


    const headers = [
      ['#', 'rank'],
      ['Lv', 'level'],
      ['玩家', 'name'],
      ['POINT', 'point'],
      ['POINT占比', 'percent'],
    ];


    headers.forEach(
      ([label, key]) => {
        const th =
          document.createElement(
            'th'
          );

        th.scope = 'col';

        th.className =
          `raid-player-th raid-player-th-${key}`;

        th.textContent =
          label;

        headRow.appendChild(
          th
        );
      }
    );


    thead.appendChild(
      headRow
    );


    const tbody =
      document.createElement(
        'tbody'
      );


    table.appendChild(
      thead
    );

    table.appendChild(
      tbody
    );

    list.appendChild(
      table
    );


    const pagination =
      document.createElement(
        'div'
      );

    pagination.className =
      'raid-player-pagination';


    const pageSummary =
      document.createElement(
        'span'
      );

    pageSummary.className =
      'raid-player-page-summary';


    const pageButtons =
      document.createElement(
        'div'
      );

    pageButtons.className =
      'raid-player-page-buttons';


    pagination.appendChild(
      pageSummary
    );

    pagination.appendChild(
      pageButtons
    );


    function renderPlayerPage() {
      const totalPlayers =
        players.length;

      const totalPages =
        Math.max(
          1,
          Math.ceil(
            totalPlayers
            / RAID_PARTICIPANT_PAGE_SIZE
          )
        );


      let currentPage =
        Number(
          raidParticipantPages.get(
            raidId
          )
          || 1
        );


      currentPage =
        Math.max(
          1,
          Math.min(
            totalPages,
            currentPage
          )
        );


      raidParticipantPages.set(
        raidId,
        currentPage
      );


      const start =
        (
          currentPage - 1
        )
        * RAID_PARTICIPANT_PAGE_SIZE;


      const end =
        Math.min(
          totalPlayers,
          start
          + RAID_PARTICIPANT_PAGE_SIZE
        );


      tbody.replaceChildren();


      players
        .slice(
          start,
          end
        )
        .forEach(
          (player, pageIndex) => {
            const absoluteIndex =
              start + pageIndex;


            const row =
              document.createElement(
                'tr'
              );

            row.className =
              'raid-player-row';


            const rank =
              numberOrNull(
                player
                && player.rank
              );


            appendTableCell(
              row,
              'raid-player-rank',
              `#${rank === null
                ? absoluteIndex + 1
                : Math.round(rank)
              }`
            );


            const level =
              numberOrNull(
                player
                && player.level
              );


            appendTableCell(
              row,
              'raid-player-level',
              level === null
                ? '—'
                : `Lv.${Math.round(level)}`
            );


            appendTableCell(
              row,
              'raid-player-name',
              String(
                player
                && player.name
                || '未知玩家'
              )
            );


            const playerPoint =
              numberOrNull(
                player
                && player.point
              )
              ?? 0;


            appendTableCell(
              row,
              'raid-player-point',
              formatInteger(
                playerPoint,
                '0'
              )
            );


            const pointPercent =
              totalPlayerPoint > 0
                ? (
                    playerPoint
                    / totalPlayerPoint
                  )
                  * 100
                : 0;


            appendTableCell(
              row,
              'raid-player-percent',
              `${pointPercent.toFixed(1)}%`
            );


            tbody.appendChild(
              row
            );
          }
        );


      pageSummary.textContent =
        `共${totalPlayers}人`
        + `｜第${currentPage}/${totalPages}頁`
        + `｜顯示${start + 1}–${end}`;


      renderRaidPlayerPager(
        pageButtons,
        currentPage,
        totalPages,
        (page) => {
          raidParticipantPages.set(
            raidId,
            page
          );

          renderPlayerPage();
        }
      );
    }


    function syncExpanded() {
      const expanded =
        expandedPlayerRaids.has(
          raidId
        );


      button.textContent =
        `${countText} ${
          expanded
            ? '▲'
            : '▼'
        }`;


      button.setAttribute(
        'aria-expanded',
        expanded
          ? 'true'
          : 'false'
      );


      list.hidden =
        !expanded;

      pagination.hidden =
        !expanded;


      item.classList.toggle(
        'is-expanded',
        expanded
      );


      if (expanded) {
        renderPlayerPage();
      }
    }


    button.addEventListener(
      'click',
      () => {
        if (
          expandedPlayerRaids.has(
            raidId
          )
        ) {
          expandedPlayerRaids.delete(
            raidId
          );
        } else {
          expandedPlayerRaids.add(
            raidId
          );
        }

        syncExpanded();
      }
    );


    item.appendChild(
      button
    );

    item.appendChild(
      list
    );

    item.appendChild(
      pagination
    );


    syncExpanded();

    return item;
  }

  // RAID_HISTORY_PLAYER_PAGER_V1
  function initHistoryPlayerPager() {
    const report =
      root.querySelector(
        '[data-history-raid-report]'
      );

    if (!report) {
      return;
    }

    const rows =
      Array.from(
        report.querySelectorAll(
          '[data-history-player-row]'
        )
      );

    if (rows.length === 0) {
      return;
    }

    const summary =
      report.querySelector(
        '[data-history-player-page-summary]'
      );

    const buttons =
      report.querySelector(
        '[data-history-player-page-buttons]'
      );

    if (!summary || !buttons) {
      return;
    }

    const RAID_HISTORY_PLAYER_PAGE_SIZE =
      20;

    let currentPage = 1;


    function renderPage() {
      const totalPlayers =
        rows.length;

      const totalPages =
        Math.max(
          1,
          Math.ceil(
            totalPlayers
            / RAID_HISTORY_PLAYER_PAGE_SIZE
          )
        );

      currentPage =
        Math.max(
          1,
          Math.min(
            totalPages,
            currentPage
          )
        );

      const start =
        (
          currentPage - 1
        )
        * RAID_HISTORY_PLAYER_PAGE_SIZE;

      const end =
        Math.min(
          totalPlayers,
          start
          + RAID_HISTORY_PLAYER_PAGE_SIZE
        );


      rows.forEach(
        (row, index) => {
          row.hidden =
            index < start
            || index >= end;
        }
      );


      summary.textContent =
        `共${totalPlayers}人｜第${currentPage}/${totalPages}頁｜顯示${start + 1}–${end}`;


      renderRaidPlayerPager(
        buttons,
        currentPage,
        totalPages,
        (page) => {
          currentPage = page;
          renderPage();
        }
      );
    }


    renderPage();
  }


  function rewardRankText(item) {
    const min = numberOrNull(item && item.rankMin);
    const max = numberOrNull(item && item.rankMax);

    if (min === null && max === null) {
      return '';
    }

    if (min !== null && max !== null) {
      if (min === max) {
        return `${Math.round(min)}名 `;
      }

      return `${Math.round(min)}–${Math.round(max)}名 `;
    }

    if (min !== null) {
      return `${Math.round(min)}名以上 `;
    }

    return `${Math.round(max)}名以內 `;
  }

  function createRewardChip(item, isRanking = false) {
    const chip = document.createElement('span');
    chip.className = 'raid-reward-chip';

    const color =
      item && typeof item.textColor === 'string' && item.textColor.trim()
        ? item.textColor.trim()
        : '#aeb5c9';

    chip.style.setProperty('--reward-color', color);

    if (item && item.textBold === true) {
      chip.classList.add('is-bold');
    }

    const itemName =
      item && typeof item.itemName === 'string' && item.itemName.trim()
        ? item.itemName.trim()
        : '未知獎勵';

    const quantity = numberOrNull(item && item.quantity);

    const rankText = isRanking
      ? rewardRankText(item)
      : '';

    const quantityText = quantity === null
      ? ''
      : ` ×${formatInteger(quantity, '0')}`;

    chip.textContent =
      `${rankText}${itemName}${quantityText}`;

    return chip;
  }

  // RAID_OBSERVER_BOSS_FRAGMENT_ICON_V1
  // Same authoritative source as Discord:
  // Reward API ranking[].itemName -> fragment colour.
  function resolveRaidFragmentIcon(raid) {
    // RAID_OBSERVER_STAGE_FRAGMENT_FALLBACK_V1
    // VPS stage-sync is authoritative when full reward metadata
    // has not been observed yet.
    const stageFragment =
      raid
      && typeof raid.fragment === 'string'
        ? raid.fragment.trim()
        : '';

    if (
      ['🟡', '🟢', '🔵', '🔴', '🟣']
        .includes(stageFragment)
    ) {
      return stageFragment;
    }

    const reward =
      raid
      && raid.reward
      && typeof raid.reward === 'object'
        ? raid.reward
        : null;

    if (
      !reward
      || !Array.isArray(reward.ranking)
    ) {
      return '';
    }

    const fragmentIcons = [
      ['記憶碎片', '🟡'],
      ['记忆碎片', '🟡'],

      ['時間碎片', '🟢'],
      ['时间碎片', '🟢'],

      ['靈魂碎片', '🔵'],
      ['灵魂碎片', '🔵'],

      ['生命碎片', '🔴'],
      ['死亡碎片', '🟣'],
    ];

    const matches = new Set();

    reward.ranking.forEach((item) => {
      if (
        !item
        || typeof item !== 'object'
        || typeof item.itemName !== 'string'
      ) {
        return;
      }

      const normalized =
        item.itemName
          .replace(/\s+/g, '')
          .replaceAll('的', '');

      fragmentIcons.forEach(
        ([fragmentName, icon]) => {
          if (
            normalized.includes(
              fragmentName
            )
          ) {
            matches.add(icon);
          }
        }
      );
    });

    /*
     * Exactly one fragment only.
     * Ambiguous/missing reward => do not guess.
     */
    if (matches.size !== 1) {
      return '';
    }

    return Array.from(matches)[0];
  }


  function createRewardSection(raid) {
    const section =
      document.createElement('div');

    section.className =
      'raid-rewards';


    const raidId =
      String(
        raid
        && raid.raid_id
        || ''
      );


    const button =
      document.createElement(
        'button'
      );

    button.type =
      'button';

    button.className =
      'raid-card__detail-toggle raid-reward-toggle';


    const body =
      document.createElement(
        'div'
      );

    body.className =
      'raid-rewards__body';


    const reward =
      raid
      && raid.reward
      && typeof raid.reward === 'object'
        ? raid.reward
        : null;


    if (reward) {

      const header =
        document.createElement(
          'div'
        );

      header.className =
        'raid-rewards__header';


      appendText(
        header,
        'raid-rewards__title',
        '獎勵明細'
      );


      const tierRaw =
        typeof reward.whirlpoolTier === 'string'
          ? reward.whirlpoolTier.trim()
          : '';


      if (tierRaw) {

        appendText(
          header,
          'raid-rewards__tier',
          tierRaw.replaceAll(
            '涡',
            '渦'
          )
        );
      }


      body.appendChild(
        header
      );


      const groups = [
        ['發現', 'discovery', false],
        ['參加', 'participation', false],
        ['排名', 'ranking', true],
        ['擊破', 'defeat', false],
      ];


      let renderedGroups = 0;


      groups.forEach(
        ([label, key, isRanking]) => {

          const items =
            Array.isArray(
              reward[key]
            )
              ? reward[key].filter(
                  (item) =>
                    item
                    && typeof item ===
                      'object'
                )
              : [];


          if (
            items.length === 0
          ) {
            return;
          }


          renderedGroups += 1;


          const group =
            document.createElement(
              'div'
            );

          group.className =
            'raid-reward-group';


          appendText(
            group,
            'raid-reward-group__label',
            label
          );


          const chips =
            document.createElement(
              'div'
            );

          chips.className =
            'raid-reward-chips';


          items.forEach(
            (item) => {

              chips.appendChild(
                createRewardChip(
                  item,
                  isRanking
                )
              );
            }
          );


          group.appendChild(
            chips
          );

          body.appendChild(
            group
          );
        }
      );


      if (
        renderedGroups === 0
      ) {

        appendText(
          body,
          'raid-rewards__empty',
          '此渦沒有可顯示的獎勵資料'
        );
      }

    } else {

      // RAID_OBSERVER_STAGE_FRAGMENT_REWARD_FALLBACK_V1
      // Knowing the fragment does NOT mean the complete reward
      // table is known.  Show only the authoritative fragment.
      const fragment =
        raid
        && typeof raid.fragment === 'string'
          ? raid.fragment.trim()
          : '';

      const fragmentNames = {
        '🟡': '記憶碎片',
        '🟢': '時間碎片',
        '🔵': '靈魂碎片',
        '🔴': '生命碎片',
        '🟣': '死亡碎片',
      };

      const fragmentName =
        fragmentNames[fragment]
        || '';

      if (fragmentName) {
        appendText(
          body,
          'raid-rewards__empty',
          `${fragment} ${fragmentName}　已確認`
        );

        appendText(
          body,
          'raid-rewards__empty',
          '完整獎勵資料尚未取得'
        );
      } else {
        appendText(
          body,
          'raid-rewards__empty',
          '獎勵資料尚未取得'
        );
      }
    }


    function syncRewardExpanded() {

      const expanded =
        expandedRewardRaids.has(
          raidId
        );


      button.textContent =
        `🎁 獎勵 ${
          expanded
            ? '▲'
            : '▼'
        }`;


      button.setAttribute(
        'aria-expanded',
        expanded
          ? 'true'
          : 'false'
      );


      body.hidden =
        !expanded;
    }


    button.addEventListener(
      'click',
      () => {

        if (
          expandedRewardRaids.has(
            raidId
          )
        ) {

          expandedRewardRaids.delete(
            raidId
          );

        } else {

          expandedRewardRaids.add(
            raidId
          );
        }


        syncRewardExpanded();
      }
    );


    section.appendChild(
      button
    );

    section.appendChild(
      body
    );


    syncRewardExpanded();

    return section;
  }


  function createRaidCard(raid) {
    const status = statusPresentation(raid);
    const ratio = numberOrNull(raid.hp_ratio);
    const percent = ratio === null ? 0 : Math.max(0, Math.min(100, ratio * 100));
    const card = document.createElement('article');
    card.className = `raid-card ${status.className}`;
    card.dataset.raidId = String(raid.raid_id || '');

    const header = document.createElement('div');
    header.className = 'raid-card__header';
    const titleGroup = document.createElement('div');

    const fragmentIcon =
      resolveRaidFragmentIcon(raid);

    const bossName =
      `${String(
        raid.boss || '未知 Boss'
      )}${fragmentIcon}`;
    const rarity = numberOrNull(
      raid && raid.reward && raid.reward.rarity
    );

    const bossTitle = rarity === null
      ? bossName
      : `${bossName}　⭐${Math.round(rarity)}`;

    appendText(
      titleGroup,
      'raid-card__boss',
      bossTitle
    );

    header.appendChild(titleGroup);
    appendText(header, 'raid-card__badge', status.label);
    card.appendChild(header);

    const hpRow = document.createElement('div');
    hpRow.className = 'raid-card__hp-row';
    appendText(hpRow, 'raid-card__hp', `${formatInteger(raid.hp)} / ${formatInteger(raid.hp_max)}`);
    appendText(hpRow, 'raid-card__hp-percent', formatPercent(ratio));
    card.appendChild(hpRow);

    const bar = document.createElement('div');
    bar.className = 'raid-card__bar';
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-label', `${String(raid.boss || 'Raid')} HP`);
    bar.setAttribute('aria-valuemin', '0');
    bar.setAttribute('aria-valuemax', '100');
    bar.setAttribute('aria-valuenow', String(Math.round(percent)));
    const fill = document.createElement('span');
    fill.style.width = `${percent}%`;
    bar.appendChild(fill);
    card.appendChild(bar);

    const meta = document.createElement('div');
    meta.className = 'raid-card__meta';
    meta.appendChild(
      createStateMeta(raid)
    );

    meta.appendChild(
      createParticipantsMeta(raid)
    );

    meta.appendChild(
      createMeta(
        '剩餘',
        formatRemaining(
          raid.expires_at
        )
      )
    );

    meta.appendChild(
      createMeta(
        '渦主',
        String(
          raid.founder
          || '未知'
        )
      )
    );
    card.appendChild(meta);
    card.appendChild(createRewardSection(raid));
    return card;
  }


  // RAID_OBSERVER_TWO_ROW_COMPACT_UI_V2
  const expandedCompactRaidCards = new Set();

  function compactRaidTier(raid) {
    const reward =
      raid && typeof raid.reward === 'object'
        ? raid.reward
        : (
            raid && typeof raid.rewards === 'object'
              ? raid.rewards
              : null
          );

    const raw =
      raid.whirlpool_tier
      || raid.whirlpoolTier
      || (
        reward
          ? (
              reward.whirlpool_tier
              || reward.whirlpoolTier
            )
          : null
      )
      || '—';

    return String(raw).replaceAll('涡', '渦');
  }

  function compactRaidRarity(raid) {
    const reward =
      raid && typeof raid.reward === 'object'
        ? raid.reward
        : (
            raid && typeof raid.rewards === 'object'
              ? raid.rewards
              : null
          );

    const value =
      numberOrNull(
        raid.rarity
        ?? (
          reward
            ? reward.rarity
            : null
        )
      );

    return value === null
      ? '—'
      : String(Math.max(0, Math.round(value)));
  }

  function compactRemainingClock(expiresAt) {
    const milliseconds = numberOrNull(expiresAt);

    if (milliseconds === null || milliseconds <= 0) {
      return '—';
    }

    const remaining =
      Math.floor((milliseconds - raidNowMs()) / 1000);

    if (remaining <= 0) {
      return '00:00:00';
    }

    const hours = Math.floor(remaining / 3600);
    const minutes = Math.floor((remaining % 3600) / 60);
    const seconds = remaining % 60;

    return [
      String(hours).padStart(2, '0'),
      String(minutes).padStart(2, '0'),
      String(seconds).padStart(2, '0'),
    ].join(':');
  }

  function createCompactCell(className, text, label = '') {
    const cell = document.createElement('div');
    cell.className = `raid-card__compact-cell ${className}`;

    if (label) {
      appendText(
        cell,
        'raid-card__compact-label',
        label
      );
    }

    appendText(
      cell,
      'raid-card__compact-value',
      text
    );

    return cell;
  }


  // RAID_OBSERVER_STATUS_ALWAYS_ICON_ONLY_V2_JS_ONLY
  // JS-only style injection: no dependency on raid_observer.php CSS blocks.
  const raidStatusAlwaysIconOnlyStyle =
    document.createElement('style');

  raidStatusAlwaysIconOnlyStyle.textContent = `
    .raid-card__compact-state.is-icon-only {
      gap: 2px;
      overflow-x: hidden;
    }

    .raid-card__compact-state.is-icon-only .raid-status-chip {
      gap: 0;
      min-height: 23px;
      padding: 1px;
    }

    .raid-card__compact-state.is-icon-only .raid-status-icon {
      flex-basis: 40px;
      height: 20px;
      width: 20px;
    }

    .raid-card__compact-state.is-icon-only .raid-status-text {
      display: none !important;
    }
  `;

  document.head.appendChild(
    raidStatusAlwaysIconOnlyStyle
  );


  // RAID_OBSERVER_COMPACT_COLUMN_GRID_V1
  const raidCompactColumnGridStyle =
    document.createElement('style');

  raidCompactColumnGridStyle.textContent = `
    /*
     * Row 1
     * Boss | Raid code | Timer | HP | Rarity | Tier
     */
    .raid-card__compact-primary {
      display: grid !important;
      grid-template-columns:
        minmax(150px, 1fr)
        145px
        135px
        170px
        64px
        82px;
      align-items: stretch;
    }

    .raid-card__compact-primary
    > .raid-card__compact-cell {
      box-sizing: border-box;
      min-width: 0;
      width: auto;
    }

    .raid-card__compact-boss {
      flex: none !important;
      min-width: 0 !important;
    }

    .raid-card__compact-code {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .raid-card__compact-timer,
    .raid-card__compact-hp,
    .raid-card__compact-rarity,
    .raid-card__compact-tier {
      justify-content: center;
    }

    /*
     * Row 2
     * Status | participants | founder | more
     */
    .raid-card__compact-secondary {
      display: grid !important;
      grid-template-columns:
        minmax(0, 1fr)
        100px
        150px
        78px;
      align-items: center;
    }

    .raid-card__compact-state {
      min-width: 0;
    }

    .raid-card__compact-secondary-meta {
      min-width: 0;
    }

    .raid-card__compact-founder {
      max-width: none !important;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .raid-card__compact-more {
      box-sizing: border-box;
      width: 100%;
    }

    @media (max-width: 767px) {
      .raid-card__compact-primary {
        grid-template-columns:
          minmax(125px, 1fr)
          125px
          112px
          145px
          58px
          70px;
      }

      .raid-card__compact-secondary {
        grid-template-columns:
          minmax(0, 1fr)
          88px
          115px
          70px;
      }
    }
  `;

  document.head.appendChild(
    raidCompactColumnGridStyle
  );


  // RAID_OBSERVER_COMPACT_GRID_NO_SCROLL_V2
  const raidCompactGridNoScrollStyle =
    document.createElement('style');

  raidCompactGridNoScrollStyle.textContent = `
    /*
     * Compact aligned row without horizontal scrollbar.
     *
     * Boss | Raid code | Timer | HP | Star | Tier
     */
    .raid-card__compact-primary {
      display: grid !important;

      grid-template-columns:
        minmax(110px, 1fr)
        125px
        115px
        155px
        58px
        72px;

      width: 100%;
      max-width: 100%;
      overflow-x: hidden !important;
      scrollbar-width: none !important;
    }

    .raid-card__compact-primary::-webkit-scrollbar {
      display: none !important;
    }

    .raid-card__compact-primary
    > .raid-card__compact-cell {
      box-sizing: border-box;
      min-width: 0 !important;
      overflow: hidden;
      padding-left: 7px;
      padding-right: 7px;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .raid-card__compact-boss,
    .raid-card__compact-code {
      min-width: 0 !important;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .raid-card__compact-timer {
      min-width: 0 !important;
      justify-content: center;
    }

    .raid-card__compact-hp {
      min-width: 0 !important;
      justify-content: center;
    }

    .raid-card__compact-rarity {
      min-width: 0 !important;
      justify-content: center;
    }

    .raid-card__compact-tier {
      min-width: 0 !important;
      justify-content: center;
    }

    /*
     * Second row stays aligned but also cannot create a scrollbar.
     */
    .raid-card__compact-secondary {
      width: 100%;
      max-width: 100%;
      overflow: hidden;
    }

    .raid-card__compact-state {
      min-width: 0;
      overflow-x: hidden !important;
      scrollbar-width: none !important;
    }

    .raid-card__compact-state::-webkit-scrollbar {
      display: none !important;
    }

    @media (max-width: 767px) {
      .raid-card__compact-primary {
        grid-template-columns:
          minmax(95px, 1fr)
          105px
          100px
          135px
          50px
          62px;
      }

      .raid-card__compact-primary
      > .raid-card__compact-cell {
        font-size: 11px;
        padding-left: 5px;
        padding-right: 5px;
      }

      .raid-card__compact-code {
        font-size: 9px !important;
      }
    }
  `;

  document.head.appendChild(
    raidCompactGridNoScrollStyle
  );


  // RAID_OBSERVER_MOBILE_HP_PRIORITY_V1
  const raidMobileHpPriorityStyle =
    document.createElement('style');

  raidMobileHpPriorityStyle.textContent = `
    @media (max-width: 767px) {

      /*
       * Mobile information priority
       *
       * Row 1:
       * Boss | HP | Timer
       *
       * Row 2:
       * Rarity | Tier | Raid code
       */
      .raid-card__compact-primary {
        grid-template-columns:
          minmax(92px, 1fr)
          minmax(125px, 1.25fr)
          minmax(88px, .9fr)
          !important;

        grid-template-areas:
          "boss hp timer"
          "rarity tier code";

        row-gap: 2px;
      }


      .raid-card__compact-boss {
        grid-area: boss;
      }


      .raid-card__compact-hp {
        grid-area: hp;

        justify-content: center;

        font-size: 13px !important;
        font-weight: 800 !important;
      }


      .raid-card__compact-timer {
        grid-area: timer;

        justify-content: center;
      }


      .raid-card__compact-rarity {
        grid-area: rarity;

        justify-content: center;
      }


      .raid-card__compact-tier {
        grid-area: tier;

        justify-content: center;
      }


      .raid-card__compact-code {
        grid-area: code;

        min-width: 0 !important;

        font-size: 9px !important;
        font-weight: 500 !important;

        opacity: .72;

        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;

        justify-content: center;
      }


      /*
       * HP value itself gets visual priority.
       */
      .raid-card__compact-hp
      .raid-card__compact-value {
        font-size: 13px;
        font-weight: 800;
      }


      /*
       * Code is still available, but clearly secondary.
       */
      .raid-card__compact-code
      .raid-card__compact-value {
        font-size: 9px;
        font-weight: 500;
      }
    }
  `;

  document.head.appendChild(
    raidMobileHpPriorityStyle
  );



  // RAID_OBSERVER_COMPACT_PREMIUM_RARITY_V1
  const raidPremiumRarityStyle =
    document.createElement('style');

  raidPremiumRarityStyle.textContent = `
    /*
     * 1★ = original normal banner
     * 2★+ = premium metallic gold banner
     */
    .raid-card__compact-rarity.is-premium-rarity {
      color: #fff8dc !important;

      background:
        linear-gradient(
          180deg,
          #d7b85d 0%,
          #b18a38 52%,
          #8b6727 100%
        ) !important;

      border-left-color:
        rgba(255, 232, 157, .55) !important;

      border-right-color:
        rgba(94, 65, 16, .55) !important;

      box-shadow:
        inset 0 1px 0 rgba(255,255,255,.32),
        inset 0 -1px 0 rgba(69,45,8,.30),
        0 0 8px rgba(214,178,79,.16);

      text-shadow:
        0 1px 1px rgba(70,42,0,.65);

      font-weight: 900;
    }

    .raid-card__compact-rarity.is-premium-rarity
    .raid-card__compact-value {
      color: #fff8dc !important;
    }
  `;

  document.head.appendChild(
    raidPremiumRarityStyle
  );

  function createCompactStateStrip(raid, status) {
    const strip = document.createElement('div');
    strip.className = 'raid-card__compact-state';

    appendText(
      strip,
      'raid-card__compact-status-badge',
      status.label
    );

    const labels =
      Array.isArray(raid.state_labels)
        ? raid.state_labels.filter(
            (value) =>
              typeof value === 'string'
              && value.trim() !== ''
          )
        : [];

    if (labels.length === 0) {
      appendText(
        strip,
        'raid-card__compact-state-label',
        '無'
      );
      return strip;
    }

    // RAID_OBSERVER_STATUS_ICON_ONLY_OVER_6_V1
    const iconOnly = true;

    strip.classList.toggle(
      'is-icon-only',
      iconOnly
    );

    strip.title =
      iconOnly
        ? `${labels.length} 個狀態`
        : '';

    labels.forEach((label) => {
      const chip = document.createElement('span');
      chip.className = 'raid-status-chip';
      chip.title = label;

      const iconUrl = resolveStatusIcon(label);

      if (iconUrl) {
        const image = document.createElement('img');
        image.className = 'raid-status-icon';
        image.src = iconUrl;
        image.alt = '';
        image.width = 18;
        image.height = 18;
        image.loading = 'lazy';
        chip.appendChild(image);
      } else {
        chip.classList.add('has-no-icon');
      }

      if (!iconOnly) {
        appendText(
          chip,
          'raid-status-text',
          label
        );
      } else if (!iconUrl) {
        appendText(
          chip,
          'raid-status-text raid-status-icon-fallback',
          '•'
        );
      }

      strip.appendChild(chip);
    });

    return strip;
  }

  function decorateRaidCardTwoRows(card, raid) {
    if (!card || card.dataset.twoRowCompact === '1') {
      return card;
    }

    const status = statusPresentation(raid);

    const raidId =
      String(
        raid.raid_id
        || raid.public_raid_code
        || raid.code
        || ''
      );

    // RAID_OBSERVER_COMPACT_HP_BAR_V2
    const existingHpBar =
      Array.from(card.children).find(
        (element) =>
          element.classList
          && element.classList.contains(
            'raid-card__bar'
          )
      )
      || null;

    const originalChildren =
      Array.from(card.childNodes)
        .filter(
          (node) =>
            node !== existingHpBar
        );

    const shell = document.createElement('div');
    shell.className = 'raid-card__compact-shell';

    const primary = document.createElement('div');
    primary.className = 'raid-card__compact-primary';

    // RAID_OBSERVER_COMPACT_FRAGMENT_ICON_V3
    primary.appendChild(
      createCompactCell(
        'raid-card__compact-boss',
        `${String(
          raid.boss
          || raid.boss_name
          || '未知 Boss'
        )}${resolveRaidFragmentIcon(raid)}`
      )
    );

    // RAID_OBSERVER_COMPACT_RAID_CODE_V1
    const compactRaidCode =
      String(
        raid.raid_id
        || ''
      ).trim();

    if (compactRaidCode) {
      const codeCell =
        createCompactCell(
          'raid-card__compact-code',
          compactRaidCode
        );

      codeCell.title =
        `渦碼：${compactRaidCode}`;

      codeCell.style.color =
        'rgba(220, 225, 238, .55)';

      codeCell.style.fontFamily =
        'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

      codeCell.style.fontSize =
        '10px';

      codeCell.style.fontWeight =
        '500';

      codeCell.style.letterSpacing =
        '.02em';

      primary.appendChild(
        codeCell
      );
    }

    const timer =
      createCompactCell(
        'raid-card__compact-timer',
        compactRemainingClock(
          raid.expires_at
          ?? raid.expires_at_ms
        ),
        '⌛'
      );

    timer.dataset.expiresAt =
      String(
        raid.expires_at
        ?? raid.expires_at_ms
        ?? ''
      );

    primary.appendChild(timer);

    primary.appendChild(
      createCompactCell(
        'raid-card__compact-hp',
        `${formatInteger(raid.hp, '0')} / ${formatInteger(raid.hp_max, '—')}`,
        'HP'
      )
    );

    const compactRarityNumber =
      Number(
        compactRaidRarity(raid)
      );

    const compactRarityCell =
      createCompactCell(
        'raid-card__compact-rarity',
        `⭐ ${compactRaidRarity(raid)}`
      );

    if (
      Number.isFinite(compactRarityNumber)
      && compactRarityNumber > 1
    ) {
      compactRarityCell.classList.add(
        'is-premium-rarity'
      );
    }

    primary.appendChild(
      compactRarityCell
    );

    primary.appendChild(
      createCompactCell(
        'raid-card__compact-tier',
        compactRaidTier(raid)
      )
    );

    const secondary = document.createElement('div');
    secondary.className = 'raid-card__compact-secondary';

    secondary.appendChild(
      createCompactStateStrip(
        raid,
        status
      )
    );

    appendText(
      secondary,
      'raid-card__compact-secondary-meta',
      `👥 ${formatInteger(raid.participant_count, '0')}/${formatInteger(raid.member_limit, '—')}`
    );

    const founder =
      appendText(
        secondary,
        'raid-card__compact-secondary-meta raid-card__compact-founder',
        `渦主 ${String(raid.founder || '未知')}`
      );

    founder.title = String(raid.founder || '未知');

    const more = document.createElement('button');
    more.type = 'button';
    more.className = 'raid-card__compact-more';

    const details = document.createElement('div');
    details.className = 'raid-card__compact-details';

    originalChildren.forEach((node) => {
      details.appendChild(node);
    });

    function syncExpanded() {
      const expanded =
        expandedCompactRaidCards.has(raidId);

      details.hidden = !expanded;

      more.textContent =
        expanded
          ? '收合 ▲'
          : '更多 ▼';

      more.setAttribute(
        'aria-expanded',
        expanded ? 'true' : 'false'
      );

      card.classList.toggle(
        'is-compact-expanded',
        expanded
      );
    }

    more.addEventListener('click', () => {
      if (expandedCompactRaidCards.has(raidId)) {
        expandedCompactRaidCards.delete(raidId);
      } else {
        expandedCompactRaidCards.add(raidId);
      }

      syncExpanded();
    });

    secondary.appendChild(more);
    shell.appendChild(primary);

    if (existingHpBar) {
      shell.appendChild(existingHpBar);
    }

    shell.appendChild(secondary);

    card.replaceChildren(
      shell,
      details
    );

    card.classList.add('has-two-row-compact');
    card.dataset.twoRowCompact = '1';

    syncExpanded();
    return card;
  }

  function refreshCompactRaidTimers() {
    root.querySelectorAll(
      '.raid-card__compact-timer[data-expires-at]'
    ).forEach((cell) => {
      const text =
        cell.querySelector(
          '.raid-card__compact-value'
        );

      if (text) {
        text.textContent =
          compactRemainingClock(
            cell.dataset.expiresAt
          );
      }
    });
  }

  /*
   * Wrap instead of replacing the existing builder.
   * Existing participant pager / rewards / detail UI survives unchanged.
   */
  const createRaidCardBeforeTwoRowCompact =
    createRaidCard;

  createRaidCard =
    function createRaidCardTwoRowCompact(raid) {
      return decorateRaidCardTwoRows(
        createRaidCardBeforeTwoRowCompact(raid),
        raid
      );
    };

  window.setInterval(
    refreshCompactRaidTimers,
    1000
  );

  function renderGrid(grid, raids, emptyText) {
    if (raids.length === 0) {
      const existingEmpty = grid.querySelector('.raid-empty');

      if (
        existingEmpty
        && grid.children.length === 1
      ) {
        existingEmpty.textContent = emptyText;
        return;
      }

      const empty = document.createElement('div');
      empty.className = 'raid-empty';
      empty.textContent = emptyText;

      grid.replaceChildren(empty);
      return;
    }

    // 移除空狀態
    grid
      .querySelectorAll('.raid-empty')
      .forEach((node) => node.remove());

    const existingCards = new Map();

    grid
      .querySelectorAll('.raid-card[data-raid-id]')
      .forEach((card) => {
        const raidId = String(
          card.dataset.raidId || ''
        );

        if (raidId) {
          existingCards.set(raidId, card);
        }
      });

    const desiredIds = new Set();

    raids.forEach((raid, index) => {
      const raidId = String(
        raid && raid.raid_id || ''
      );

      if (!raidId) {
        return;
      }

      desiredIds.add(raidId);

      const freshCard = createRaidCard(raid);
      let card = existingCards.get(raidId);

      if (card) {
        // 保留外層 DOM，因此 Grid cell 不會每30秒被重建
        card.className = freshCard.className;
        card.dataset.raidId = raidId;

        card.replaceChildren(
          ...Array.from(freshCard.childNodes)
        );
      } else {
        card = freshCard;
      }

      const currentAtPosition =
        grid.children[index] || null;

      if (currentAtPosition !== card) {
        grid.insertBefore(
          card,
          currentAtPosition
        );
      }
    });

    // 只有真正不存在的渦才移除
    grid
      .querySelectorAll('.raid-card[data-raid-id]')
      .forEach((card) => {
        const raidId = String(
          card.dataset.raidId || ''
        );

        if (!desiredIds.has(raidId)) {
          card.remove();
        }
      });
  }

  function isStale(payload) {
    const updatedAt = numberOrNull(payload && payload.updated_at);
    return !payload || payload.connected !== true || updatedAt === null
      || (raidNowMs() / 1000 - updatedAt) > staleAfterSeconds;
  }

  function updateConnection(stale) {
    elements.connection.textContent = stale ? '資料可能已過期' : 'LIVE';
    elements.connection.classList.toggle('is-live', !stale);
    elements.connection.classList.toggle('is-stale', stale);
    elements.staleNotice.hidden = !stale;
  }

  function stabilizeRaidOrder(raids) {
    raids.forEach((raid) => {
      const raidId = String(raid && raid.raid_id || '');

      if (!raidId) {
        return;
      }

      if (!raidStableOrder.has(raidId)) {
        raidStableOrder.set(
          raidId,
          raidStableOrderSequence++
        );
      }
    });

    return [...raids].sort((a, b) => {
      const aId = String(a && a.raid_id || '');
      const bId = String(b && b.raid_id || '');

      const aOrder = raidStableOrder.has(aId)
        ? raidStableOrder.get(aId)
        : Number.MAX_SAFE_INTEGER;

      const bOrder = raidStableOrder.has(bId)
        ? raidStableOrder.get(bId)
        : Number.MAX_SAFE_INTEGER;

      return aOrder - bOrder;
    });
  }

  function render(payload) {
    const raids = Array.isArray(payload.raids) ? payload.raids : [];
    const active = stabilizeRaidOrder(
      raids.filter((raid) => raid && raid.ended_at == null)
    );

    const ended = stabilizeRaidOrder(
      raids.filter((raid) => raid && raid.ended_at != null)
    );
    elements.activeCount.textContent =
      formatInteger(
        payload.active_count,
        String(active.length)
      );

    const endedCountText =
      formatInteger(
        payload.recent_ended_count,
        String(ended.length)
      );

    elements.endedCount.textContent =
      endedCountText;

    if (
      elements.endedToggleCount
    ) {
      elements.endedToggleCount.textContent =
        endedCountText;
    }

    renderGrid(elements.activeGrid, active, '目前沒有觀測中的公開 Raid。');
    renderGrid(elements.endedGrid, ended, '最近沒有結束的 Raid。');
    updateConnection(isStale(payload));
  }

  function refreshClock() {
    if (!lastPayload) return;
    elements.updatedAge.textContent = formatAge(lastPayload.updated_at);
    updateConnection(isStale(lastPayload));
  }

  async function refresh() {
    if (requestInFlight) return;
    requestInFlight = true;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 1800);
    try {
      const response = await fetch(endpoint, {
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });
      const payload = await response.json();
      if (!response.ok || payload.ok !== true || !Array.isArray(payload.raids)) {
        throw new Error('Raid proxy returned an invalid response.');
      }
      if (!syncRaidServerClock(payload)) {
        throw new Error(
          'Raid proxy missing server_now_ms'
        );
      }

      lastPayload = payload;
      render(payload);
    } catch (_error) {
      updateConnection(true);
    } finally {
      window.clearTimeout(timeout);
      requestInFlight = false;
      refreshClock();
    }
  }

  initHistoryPlayerPager();

  refresh();
  window.setInterval(refresh, refreshMilliseconds);
  window.setInterval(refreshClock, 1000);


  // =========================================================
  // My Raid / Player performance statistics
  // =========================================================

  const RAID_STATS_REFRESH_MS = 30000;

  let raidStatsRange = 'all';
  let raidStatsRows = [];
  let raidStatsFounder = '';
  let raidStatsRaidCount = 0;
  let raidStatsBossRows = [];

  let raidStatsSortKey = 'total_point';
  let raidStatsSortDirection = 'desc';

  // RAID_PLAYER_STATS_PAGINATION_V1
  const RAID_STATS_PAGE_SIZE = 20;
  let raidStatsPage = 1;

  let raidStatsRequestInFlight = false;
  let raidStatsAuthenticationRequired = false;


  function statsNumber(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
  }


  function statsFormatNumber(value, decimals = 0) {
    return statsNumber(value).toLocaleString(
      'zh-TW',
      {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      }
    );
  }


  function ensureRaidStatsSection() {
    let section = document.querySelector(
      '[data-role="raid-stats-section"]'
    );

    if (section) {
      return section;
    }

    const endedGrid = document.querySelector(
      '[data-role="ended-grid"]'
    );

    if (!endedGrid) {
      return null;
    }

    section = document.createElement('section');
    section.className = 'raid-stats-section';
    section.dataset.role = 'raid-stats-section';

    section.innerHTML = `
      <div class="raid-stats-head">
        <div>
          <h2
            class="raid-stats-title"
            data-role="raid-stats-title"
          >
            我的渦・玩家 POINT 統計
          </h2>

          <div
            class="raid-stats-summary"
            data-role="raid-stats-summary"
          >
            讀取中...
          </div>
        </div>

        <div class="raid-stats-controls">
          <div class="raid-stats-ranges">
            <button
              type="button"
              data-stats-range="all"
              class="is-active"
            >全部</button>

            <button
              type="button"
              data-stats-range="30"
            >30天</button>

            <button
              type="button"
              data-stats-range="7"
            >7天</button>
          </div>

          <input
            type="search"
            class="raid-stats-search"
            data-role="raid-stats-search"
            placeholder="搜尋玩家"
            autocomplete="off"
          >
        </div>
      </div>

      <div class="raid-stats-table-wrap">
        <table class="raid-stats-table">
          <thead>
            <tr>
              <th data-sort="player_name">
                玩家名稱
              </th>

              <th data-sort="raids_joined">
                參加渦
              </th>

              <th data-sort="participation_percent">
                參加率
              </th>

              <th data-sort="total_point">
                總分數
              </th>

              <th data-sort="avg_point">
                平均分數
              </th>
            </tr>
          </thead>

          <tbody
            data-role="raid-stats-body"
          ></tbody>
        </table>
      </div>

      <div
        class="raid-stats-pagination"
        data-role="raid-stats-pagination"
      >
        <div
          class="raid-stats-page-summary"
          data-role="raid-stats-page-summary"
        ></div>

        <div
          class="raid-stats-page-buttons"
          data-role="raid-stats-page-buttons"
        ></div>
      </div>
    `;

    const endedSection =
      endedGrid.closest('section')
      || endedGrid.parentElement;

    if (endedSection) {
      endedSection.insertAdjacentElement(
        'afterend',
        section
      );
    }

    section
      .querySelectorAll('[data-stats-range]')
      .forEach((button) => {
        button.addEventListener('click', () => {
          raidStatsRange =
            button.dataset.statsRange || 'all';

          raidStatsPage = 1;

          section
            .querySelectorAll('[data-stats-range]')
            .forEach((candidate) => {
              candidate.classList.toggle(
                'is-active',
                candidate === button
              );
            });

          refreshRaidStats();
        });
      });

    const search = section.querySelector(
      '[data-role="raid-stats-search"]'
    );

    if (search) {
      search.addEventListener(
        'input',
        () => {
          raidStatsPage = 1;
          renderRaidStats();
        }
      );
    }

    section
      .querySelectorAll('th[data-sort]')
      .forEach((header) => {
        header.addEventListener('click', () => {
          const key = header.dataset.sort;

          if (!key) {
            return;
          }

          if (raidStatsSortKey === key) {
            raidStatsSortDirection =
              raidStatsSortDirection === 'desc'
                ? 'asc'
                : 'desc';
          } else {
            raidStatsSortKey = key;
            raidStatsSortDirection =
              key === 'player_name'
                ? 'asc'
                : 'desc';
          }

          raidStatsPage = 1;
          renderRaidStats();
        });
      });

    return section;
  }



  // RAID_PLAYER_STATS_PAGINATION_V1
  function renderRaidStatsPager(
    section,
    totalRows,
    totalPages
  ) {
    const summary = section.querySelector(
      '[data-role="raid-stats-page-summary"]'
    );

    const buttons = section.querySelector(
      '[data-role="raid-stats-page-buttons"]'
    );

    if (!summary || !buttons) {
      return;
    }

    buttons.replaceChildren();

    if (totalRows <= 0) {
      summary.textContent = '沒有符合的玩家';
      return;
    }

    const start =
      (raidStatsPage - 1) * RAID_STATS_PAGE_SIZE + 1;

    const end = Math.min(
      raidStatsPage * RAID_STATS_PAGE_SIZE,
      totalRows
    );

    summary.textContent =
      `共 ${statsFormatNumber(totalRows)} 名`
      + `｜第 ${raidStatsPage} / ${totalPages} 頁`
      + `｜顯示 ${start}–${end}`;

    function makeButton(
      label,
      page,
      active = false,
      disabled = false
    ) {
      const button = document.createElement('button');

      button.type = 'button';
      button.textContent = label;

      button.className =
        'raid-stats-page-button';

      if (active) {
        button.classList.add('is-active');
      }

      button.disabled = disabled;

      if (!disabled && !active) {
        button.addEventListener(
          'click',
          () => {
            raidStatsPage = page;
            renderRaidStats();

            section.scrollIntoView({
              behavior: 'smooth',
              block: 'start',
            });
          }
        );
      }

      return button;
    }

    buttons.appendChild(
      makeButton(
        '‹',
        raidStatsPage - 1,
        false,
        raidStatsPage <= 1
      )
    );

    let first = Math.max(
      1,
      raidStatsPage - 2
    );

    let last = Math.min(
      totalPages,
      raidStatsPage + 2
    );

    /*
     * 盡量維持5個數字按鈕
     */
    if (raidStatsPage <= 3) {
      last = Math.min(
        totalPages,
        5
      );
    }

    if (raidStatsPage >= totalPages - 2) {
      first = Math.max(
        1,
        totalPages - 4
      );
    }

    if (first > 1) {
      buttons.appendChild(
        makeButton(
          '1',
          1,
          raidStatsPage === 1
        )
      );

      if (first > 2) {
        const dots =
          document.createElement('span');

        dots.className =
          'raid-stats-page-dots';

        dots.textContent = '…';

        buttons.appendChild(dots);
      }
    }

    for (
      let page = first;
      page <= last;
      page += 1
    ) {
      buttons.appendChild(
        makeButton(
          String(page),
          page,
          page === raidStatsPage
        )
      );
    }

    if (last < totalPages) {
      if (last < totalPages - 1) {
        const dots =
          document.createElement('span');

        dots.className =
          'raid-stats-page-dots';

        dots.textContent = '…';

        buttons.appendChild(dots);
      }

      buttons.appendChild(
        makeButton(
          String(totalPages),
          totalPages,
          raidStatsPage === totalPages
        )
      );
    }

    buttons.appendChild(
      makeButton(
        '›',
        raidStatsPage + 1,
        false,
        raidStatsPage >= totalPages
      )
    );
  }


  function renderRaidStats() {
    const section = ensureRaidStatsSection();

    if (!section) {
      return;
    }

    const tbody = section.querySelector(
      '[data-role="raid-stats-body"]'
    );

    const summary = section.querySelector(
      '[data-role="raid-stats-summary"]'
    );

    const title = section.querySelector(
      '[data-role="raid-stats-title"]'
    );

    const search = section.querySelector(
      '[data-role="raid-stats-search"]'
    );

    if (!tbody) {
      return;
    }

    if (title) {
      title.textContent = raidStatsFounder
        ? `${raidStatsFounder}開渦・玩家 POINT 統計`
        : '我的渦・玩家 POINT 統計';
    }

    const keyword = String(
      search ? search.value : ''
    )
      .trim()
      .toLocaleLowerCase();

    let rows = raidStatsRows.filter((row) => {
      if (!keyword) {
        return true;
      }

      return String(row.player_name || '')
        .toLocaleLowerCase()
        .includes(keyword);
    });

    rows = [...rows].sort((a, b) => {
      const key = raidStatsSortKey;

      let result = 0;

      if (key === 'player_name') {
        result = String(a.player_name || '')
          .localeCompare(
            String(b.player_name || ''),
            'zh-TW'
          );
      } else {
        result =
          statsNumber(a[key])
          - statsNumber(b[key]);
      }

      return raidStatsSortDirection === 'asc'
        ? result
        : -result;
    });

    // RAID_PLAYER_STATS_PAGINATION_V1
    const totalRows = rows.length;

    const totalPages = Math.max(
      1,
      Math.ceil(
        totalRows / RAID_STATS_PAGE_SIZE
      )
    );

    if (raidStatsPage > totalPages) {
      raidStatsPage = totalPages;
    }

    if (raidStatsPage < 1) {
      raidStatsPage = 1;
    }

    const pageStart =
      (raidStatsPage - 1) * RAID_STATS_PAGE_SIZE;

    const pageRows = rows.slice(
      pageStart,
      pageStart + RAID_STATS_PAGE_SIZE
    );

    tbody.replaceChildren();

    const fragment =
      document.createDocumentFragment();

    pageRows.forEach((row) => {
      const tr = document.createElement('tr');

      if (
        String(row.player_name || '')
        === raidStatsFounder
      ) {
        tr.classList.add('is-owner');
      }

      const values = [
        String(row.player_name || ''),
        statsFormatNumber(row.raids_joined),
        `${statsFormatNumber(
          row.participation_percent,
          0
        )}%`,
        statsFormatNumber(row.total_point),
        statsFormatNumber(row.avg_point, 0),
      ];

      values.forEach((value, index) => {
        const td = document.createElement('td');

        td.textContent = value;

        if (index !== 0) {
          td.classList.add('is-number');
        }

        tr.appendChild(td);
      });

      fragment.appendChild(tr);
    });

    tbody.appendChild(fragment);

    renderRaidStatsPager(
      section,
      totalRows,
      totalPages
    );

    if (summary) {
      const visibleText =
        keyword
          ? `｜搜尋結果 ${rows.length} 名`
          : '';

      summary.textContent =
        `已記錄 ${statsFormatNumber(
          raidStatsRaidCount
        )} 個渦`
        + `｜${statsFormatNumber(
          raidStatsRows.length
        )} 名玩家`
        + visibleText;
    }

    section
      .querySelectorAll('th[data-sort]')
      .forEach((header) => {
        const active =
          header.dataset.sort === raidStatsSortKey;

        header.classList.toggle(
          'is-sorted',
          active
        );

        header.dataset.direction =
          active
            ? raidStatsSortDirection
            : '';
      });
  }


  function renderRaidStatsLoginPrompt() {
    const section = ensureRaidStatsSection();

    if (!section) {
      return;
    }

    section.innerHTML = `
      <div class="raid-stats-head">
        <div>
          <h2 class="raid-stats-title">
            🔒 登入解鎖你的 Raid 統計
          </h2>

          <div class="raid-stats-summary">
            登入後可查看專屬於你的 Raid 紀錄與統計。
          </div>
        </div>
      </div>

      <div
        style="
          text-align:center;
          padding:22px 12px 12px;
        "
      >
        <div
          style="
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:10px;
            max-width:560px;
            margin:0 auto 18px;
            text-align:left;
          "
        >
          <div>📊 自己開過哪些渦</div>
          <div>🏅 玩家 POINT 排行</div>
          <div>🏆 個人 Raid 戰績</div>
          <div>🤝 最常一起打的戰友</div>
        </div>

        <a
          href="/api/auth/steam_start.php"
          class="raid-lookup__button"
          style="
            display:inline-block;
            text-decoration:none;
          "
        >
          Steam 登入查看更多
        </a>
      </div>
    `;
  }



  // =========================================================
  // RAID_PLAYER_STATS_BOSS_TABLE_COMPACT_V1
// RAID_PLAYER_STATS_BOSS_TABLE_V1
  // Founder Boss + rarity statistics
  // =========================================================

  // RAID_BOSS_MERGE_RARITY_V1
  function aggregateRaidBossRows(
    sourceRows,
    totalRaidCount
  ) {

    const map = new Map();

    (
      Array.isArray(sourceRows)
        ? sourceRows
        : []
    ).forEach((row) => {

      const boss =
        String(
          row.boss
          || '未知 Boss'
        );

      if (!map.has(boss)) {
        map.set(
          boss,
          {
            boss,
            total:0,
            normal:0,
            six:0,
            known:0,
          }
        );
      }

      const item =
        map.get(boss);

      const count =
        Math.max(
          0,
          statsNumber(
            row.raids_opened
          )
        );

      const rarity =
        Number(
          row.rarity
        );

      item.total += count;


      /*
       * Unlight Raid:
       * 1★ = normal
       * 6★ = rare reward version
       */
      if (
        Number.isFinite(rarity)
        &&
        rarity > 0
      ) {
        item.known += count;
      }


      if (rarity === 1) {
        item.normal += count;
      }


      if (rarity === 6) {
        item.six += count;
      }
    });


    const all =
      Math.max(
        0,
        statsNumber(
          totalRaidCount
        )
      );


    const rows =
      Array.from(
        map.values()
      );


    rows.forEach((row) => {

      row.spawn_percent =
        all > 0
          ? row.total / all * 100
          : 0;

      row.six_boss_percent =
        row.total > 0
          ? row.six / row.total * 100
          : 0;

      row.six_all_percent =
        all > 0
          ? row.six / all * 100
          : 0;
    });


    rows.sort((a,b) => {

      const countCmp =
        b.total
        -
        a.total;

      if (countCmp !== 0) {
        return countCmp;
      }

      return String(
        a.boss
      ).localeCompare(
        String(
          b.boss
        ),
        'zh-TW'
      );
    });


    return rows;
  }


  function ensureRaidBossStatsSection() {

    let section = document.querySelector(
      '[data-role="raid-boss-stats-section"]'
    );

    if (section) {
      return section;
    }

    const ownSection =
      ensureRaidStatsSection();

    if (!ownSection) {
      return null;
    }

    section =
      document.createElement('section');

    section.className =
      'raid-stats-section';

    section.dataset.role =
      'raid-boss-stats-section';

    section.innerHTML = `
      <div class="raid-stats-head">
        <div>
          <h2
            class="raid-stats-title"
            data-role="raid-boss-stats-title"
          >
            🐉 開渦・BOSS統計
          </h2>

          <div
            class="raid-stats-summary"
            data-role="raid-boss-stats-summary"
          >
            讀取中...
          </div>
        </div>
      </div>

      <div class="raid-stats-table-wrap">
        <table class="raid-stats-table">
          <thead>
            <tr>
              <th>BOSS</th>
              <th>總數</th>
              <th>1★</th>
              <th>6★</th>
              <th>已知星級</th>
              <th>出現率</th>
              <th>6★率</th>
              <th>6★占全部</th>
              
              
            </tr>
          </thead>

          <tbody
            data-role="raid-boss-stats-body"
          ></tbody>
        </table>
      </div>

      <div
        class="raid-stats-summary"
        style="margin-top:10px"
      >
        NORMAL = 1★；6★ = 六星；
        KNOWN = 已知星級樣本；
        SPAWN% = 此 BOSS / 全部開渦；
        6★/BOSS = 此 BOSS 內六星比例；
        6★/ALL = 六星占全部開渦比例。
      </div>
    `;

    ownSection.insertAdjacentElement(
      'afterend',
      section
    );

    return section;
  }


  // RAID_PLAYER_STATS_BOSS_TD6_KILL_TIME_V1
  function renderRaidBossStats() {

    const section =
      ensureRaidBossStatsSection();

    if (!section) {
      return;
    }

    const title =
      section.querySelector(
        '[data-role="raid-boss-stats-title"]'
      );

    const summary =
      section.querySelector(
        '[data-role="raid-boss-stats-summary"]'
      );

    const tbody =
      section.querySelector(
        '[data-role="raid-boss-stats-body"]'
      );

    if (!tbody) {
      return;
    }


    if (title) {

      title.textContent =
        raidStatsFounder
          ? `🐉 ${raidStatsFounder}開渦・BOSS統計`
          : '🐉 開渦・BOSS統計';
    }


    if (summary) {

      summary.textContent =
        `已記錄 ${statsFormatNumber(
          raidStatsRaidCount
        )} 個渦`
        +
        `｜${statsFormatNumber(
          aggregateRaidBossRows(
            raidStatsBossRows,
            raidStatsRaidCount
          ).length
        )} 種 BOSS`;
    }


    tbody.replaceChildren();


    if (
      !Array.isArray(
        raidStatsBossRows
      )
      ||
      raidStatsBossRows.length === 0
    ) {

      const tr =
        document.createElement('tr');

      const td =
        document.createElement('td');

      td.colSpan = 8;

      td.textContent =
        '目前沒有 BOSS 統計資料。';

      td.style.textAlign =
        'center';

      tr.appendChild(td);
      tbody.appendChild(tr);

      return;
    }


    const fragment =
      document.createDocumentFragment();


    const mergedRows =
      aggregateRaidBossRows(
        raidStatsBossRows,
        raidStatsRaidCount
      );


    mergedRows.forEach(
      (row) => {

        const tr =
          document.createElement('tr');


        const cells = [

          {
            value:
              String(
                row.boss
                || '未知 Boss'
              ),
            numeric:false,
          },

          {
            value:
              statsFormatNumber(
                row.total
              ),
            numeric:true,
          },

          {
            value:
              statsFormatNumber(
                row.normal
              ),
            numeric:true,
          },

          {
            value:
              statsFormatNumber(
                row.six
              ),
            numeric:true,
          },

          {
            value:
              statsFormatNumber(
                row.known
              ),
            numeric:true,
          },

          {
            value:
              `${
                statsFormatNumber(
                  row.spawn_percent,
                  2
                )
              }%`,
            numeric:true,
          },

          {
            value:
              `${
                statsFormatNumber(
                  row.six_boss_percent,
                  2
                )
              }%`,
            numeric:true,
          },

          {
            value:
              `${
                statsFormatNumber(
                  row.six_all_percent,
                  2
                )
              }%`,
            numeric:true,
          },

        ];


        cells.forEach(
          (cell) => {

            const td =
              document.createElement(
                'td'
              );

            td.textContent =
              cell.value;

            if (cell.numeric) {
              td.classList.add(
                'is-number'
              );
            }

            tr.appendChild(td);
          }
        );


        fragment.appendChild(
          tr
        );
      }
    );


    tbody.appendChild(
      fragment
    );
  }


  async function refreshRaidStats() {
    if (raidStatsAuthenticationRequired) {
      return;
    }

    if (raidStatsRequestInFlight) {
      return;
    }

    raidStatsRequestInFlight = true;

    try {
      const response = await fetch(
        `/api/raid_player_stats.php?days=${
          encodeURIComponent(raidStatsRange)
        }`,
        {
          cache: 'no-store',
        }
      );

      if (response.status === 401) {
        raidStatsAuthenticationRequired = true;
        renderRaidStatsLoginPrompt();
        return;
      }

      if (!response.ok) {
        throw new Error(
          `HTTP ${response.status}`
        );
      }

      const payload = await response.json();

      if (!payload || payload.ok !== true) {
        throw new Error(
          payload && payload.error
            ? payload.error
            : 'stats request failed'
        );
      }

      raidStatsRows = Array.isArray(payload.players)
        ? payload.players
        : [];

      raidStatsFounder =
        String(payload.founder || '');

      raidStatsRaidCount =
        statsNumber(payload.raid_count);

      raidStatsBossRows =
        Array.isArray(payload.bosses)
          ? payload.bosses
          : [];

      renderRaidStats();
      renderRaidBossStats();

    } catch (error) {
      const section = ensureRaidStatsSection();

      const summary = section
        ? section.querySelector(
            '[data-role="raid-stats-summary"]'
          )
        : null;

      if (summary) {
        summary.textContent =
          `統計讀取失敗：${error.message}`;
      }

    } finally {
      raidStatsRequestInFlight = false;
    }
  }


  ensureRaidStatsSection();
  refreshRaidStats();

  window.setInterval(
    refreshRaidStats,
    RAID_STATS_REFRESH_MS
  );




  // =========================================================
  // ADMIN_RAID_FOUNDER_STATS_V1
  // 管理員：指定任意玩家作為渦主查統計
  // =========================================================

  const raidRoot =
    document.querySelector(
      '[data-raid-observer]'
    );

  const adminRaidStatsEnabled =
    raidRoot
    && raidRoot.dataset.raidStatsAdmin === '1';

  let adminRaidFounder = '';
  let adminRaidRange = 'all';
  let adminRaidRows = [];
  let adminRaidBossRows = [];
  let adminRaidCount = 0;

  let adminRaidSortKey = 'total_point';
  let adminRaidSortDirection = 'desc';

  let adminRaidPage = 1;

  const ADMIN_RAID_PAGE_SIZE = 20;


  function ensureAdminRaidStatsSection() {

    if (!adminRaidStatsEnabled) {
      return null;
    }

    let section =
      document.querySelector(
        '[data-role="admin-raid-stats-section"]'
      );

    if (section) {
      return section;
    }

    /*
     * 確保「自己的統計」先存在，
     * 然後管理員表放在它正下方。
     */
    const ownSection =
      ensureRaidStatsSection();

    if (!ownSection) {
      return null;
    }

    section =
      document.createElement('section');

    section.className =
      'raid-stats-section';

    section.dataset.role =
      'admin-raid-stats-section';

    section.innerHTML = `
      <div class="raid-stats-head">

        <div>
          <h2 class="raid-stats-title">
            🔒 管理員｜指定渦主統計
          </h2>

          <div
            class="raid-stats-summary"
            data-role="admin-raid-summary"
          >
            輸入玩家名稱後查詢
          </div>
        </div>

        <div class="raid-stats-controls">

          <div
            style="
              display:flex;
              gap:6px;
              align-items:center;
              flex-wrap:wrap;
            "
          >
            <input
              type="search"
              class="raid-stats-search"
              data-role="admin-founder-input"
              placeholder="輸入渦主玩家名稱"
              autocomplete="off"
              style="min-width:250px"
            >

            <button
              type="button"
              data-role="admin-founder-submit"
            >
              🔍 查詢
            </button>
          </div>

          <div class="raid-stats-ranges">

            <button
              type="button"
              data-admin-range="all"
              class="is-active"
            >
              全部
            </button>

            <button
              type="button"
              data-admin-range="30"
            >
              30天
            </button>

            <button
              type="button"
              data-admin-range="7"
            >
              7天
            </button>

          </div>
        </div>
      </div>

      <div
        data-role="admin-result"
        hidden
      >

        <!-- ADMIN_RAID_FOUNDER_BOSS_STATS_V2 -->

        <div
          data-role="admin-boss-result"
          style="margin-bottom:22px"
        >
          <h3
            style="margin:0 0 6px"
            data-role="admin-boss-title"
          ></h3>

          <div
            class="raid-stats-summary"
            data-role="admin-boss-summary"
            style="margin-bottom:10px"
          ></div>

          <div class="raid-stats-table-wrap">

            <table class="raid-stats-table">

              <thead>
                <tr>
                  <th>BOSS</th>
                  <th>總數</th>
                  <th>1★</th>
                  <th>6★</th>
                  <th>已知星級</th>
                  <th>出現率</th>
                  <th>6★率</th>
                  <th>6★占全部</th>
                </tr>
              </thead>

              <tbody
                data-role="admin-boss-body"
              ></tbody>

            </table>

          </div>

          <div
            class="raid-stats-summary"
            style="margin-top:10px"
          >
            時間樣本 = 有可用 ended_at 的渦 / 此類總渦數；
            平均觀測至結束只使用這些樣本計算，
            不代表只有這些渦實際打完。
          </div>

        </div>


        <h3
          style="margin:0 0 10px"
          data-role="admin-result-title"
        ></h3>

        <div class="raid-stats-table-wrap">

          <table class="raid-stats-table">

            <thead>
              <tr>
                <th data-admin-sort="player_name">
                  玩家名稱
                </th>

                <th data-admin-sort="raids_joined">
                  參加渦
                </th>

                <th data-admin-sort="participation_percent">
                  參加率
                </th>

                <th data-admin-sort="total_point">
                  總分數
                </th>

                <th data-admin-sort="avg_point">
                  平均分數
                </th>
              </tr>
            </thead>

            <tbody
              data-role="admin-raid-body"
            ></tbody>

          </table>
        </div>

        <div
          data-role="admin-page-summary"
          class="raid-stats-summary"
          style="margin-top:10px;text-align:center"
        ></div>

        <div
          data-role="admin-page-buttons"
          style="
            display:flex;
            justify-content:center;
            gap:5px;
            flex-wrap:wrap;
            margin-top:8px;
          "
        ></div>

      </div>
    `;

    ownSection.insertAdjacentElement(
      'afterend',
      section
    );


    const input =
      section.querySelector(
        '[data-role="admin-founder-input"]'
      );

    const submit =
      section.querySelector(
        '[data-role="admin-founder-submit"]'
      );


    function submitFounder() {

      const founder =
        String(
          input ? input.value : ''
        ).trim();

      if (!founder) {
        return;
      }

      adminRaidFounder = founder;
      adminRaidPage = 1;

      refreshAdminRaidStats();
    }


    if (submit) {
      submit.addEventListener(
        'click',
        submitFounder
      );
    }


    if (input) {
      input.addEventListener(
        'keydown',
        (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            submitFounder();
          }
        }
      );
    }


    section
      .querySelectorAll(
        '[data-admin-range]'
      )
      .forEach((button) => {

        button.addEventListener(
          'click',
          () => {

            adminRaidRange =
              button.dataset.adminRange
              || 'all';

            adminRaidPage = 1;

            section
              .querySelectorAll(
                '[data-admin-range]'
              )
              .forEach((candidate) => {
                candidate.classList.toggle(
                  'is-active',
                  candidate === button
                );
              });

            if (adminRaidFounder) {
              refreshAdminRaidStats();
            }
          }
        );
      });


    section
      .querySelectorAll(
        '[data-admin-sort]'
      )
      .forEach((header) => {

        header.style.cursor = 'pointer';

        header.addEventListener(
          'click',
          () => {

            const key =
              header.dataset.adminSort;

            if (!key) {
              return;
            }

            if (
              adminRaidSortKey === key
            ) {
              adminRaidSortDirection =
                adminRaidSortDirection === 'desc'
                ? 'asc'
                : 'desc';
            } else {
              adminRaidSortKey = key;

              adminRaidSortDirection =
                key === 'player_name'
                  ? 'asc'
                  : 'desc';
            }

            adminRaidPage = 1;
            renderAdminRaidStats();
          }
        );
      });


    return section;
  }


  // ADMIN_RAID_FOUNDER_BOSS_STATS_V2
  // RAID_BOSS_MERGE_RARITY_V1
  function renderAdminRaidBossStats(section) {

    if (!section) {
      return;
    }

    const title =
      section.querySelector(
        '[data-role="admin-boss-title"]'
      );

    const summary =
      section.querySelector(
        '[data-role="admin-boss-summary"]'
      );

    const body =
      section.querySelector(
        '[data-role="admin-boss-body"]'
      );

    if (!body) {
      return;
    }


    const mergedRows =
      aggregateRaidBossRows(
        adminRaidBossRows,
        adminRaidCount
      );


    if (title) {
      title.textContent =
        `🐉 ${adminRaidFounder}開渦・BOSS統計`;
    }


    if (summary) {
      summary.textContent =
        `已記錄 ${statsFormatNumber(
          adminRaidCount
        )} 個渦`
        +
        `｜${statsFormatNumber(
          mergedRows.length
        )} 種 BOSS`;
    }


    body.replaceChildren();


    if (mergedRows.length === 0) {

      const tr =
        document.createElement('tr');

      const td =
        document.createElement('td');

      td.colSpan = 8;

      td.textContent =
        '目前沒有 BOSS 統計資料。';

      td.style.textAlign =
        'center';

      tr.appendChild(td);
      body.appendChild(tr);

      return;
    }


    mergedRows.forEach((row) => {

      const tr =
        document.createElement('tr');


      const values = [

        {
          value:
            String(
              row.boss
              || '未知 Boss'
            ),
          numeric:false,
        },

        {
          value:
            statsFormatNumber(
              row.total
            ),
          numeric:true,
        },

        {
          value:
            statsFormatNumber(
              row.normal
            ),
          numeric:true,
        },

        {
          value:
            statsFormatNumber(
              row.six
            ),
          numeric:true,
        },

        {
          value:
            statsFormatNumber(
              row.known
            ),
          numeric:true,
        },

        {
          value:
            `${
              statsFormatNumber(
                row.spawn_percent,
                2
              )
            }%`,
          numeric:true,
        },

        {
          value:
            `${
              statsFormatNumber(
                row.six_boss_percent,
                2
              )
            }%`,
          numeric:true,
        },

        {
          value:
            `${
              statsFormatNumber(
                row.six_all_percent,
                2
              )
            }%`,
          numeric:true,
        },

      ];


      values.forEach((cell) => {

        const td =
          document.createElement('td');

        td.textContent =
          cell.value;

        if (cell.numeric) {
          td.classList.add(
            'is-number'
          );
        }

        tr.appendChild(td);
      });


      body.appendChild(tr);
    });
  }


  function renderAdminRaidStats() {

    const section =
      ensureAdminRaidStatsSection();

    if (!section) {
      return;
    }

    const result =
      section.querySelector(
        '[data-role="admin-result"]'
      );

    const title =
      section.querySelector(
        '[data-role="admin-result-title"]'
      );

    const body =
      section.querySelector(
        '[data-role="admin-raid-body"]'
      );

    const summary =
      section.querySelector(
        '[data-role="admin-page-summary"]'
      );

    const buttons =
      section.querySelector(
        '[data-role="admin-page-buttons"]'
      );

    if (
      !result
      || !body
      || !summary
      || !buttons
    ) {
      return;
    }

    result.hidden = false;

    if (title) {
      title.textContent =
        `⚔️ ${adminRaidFounder}開渦・玩家 POINT 統計`;
    }


    renderAdminRaidBossStats(
      section
    );


    let rows =
      [...adminRaidRows];

    rows.sort((a,b) => {

      let cmp = 0;

      if (
        adminRaidSortKey ===
        'player_name'
      ) {
        cmp =
          String(a.player_name || '')
          .localeCompare(
            String(b.player_name || ''),
            'zh-TW'
          );
      } else {
        cmp =
          statsNumber(
            a[adminRaidSortKey]
          )
          -
          statsNumber(
            b[adminRaidSortKey]
          );
      }

      return (
        adminRaidSortDirection === 'asc'
        ? cmp
        : -cmp
      );
    });


    const total = rows.length;

    const pages = Math.max(
      1,
      Math.ceil(
        total / ADMIN_RAID_PAGE_SIZE
      )
    );

    if (adminRaidPage > pages) {
      adminRaidPage = pages;
    }

    const start =
      (adminRaidPage - 1)
      * ADMIN_RAID_PAGE_SIZE;

    const pageRows =
      rows.slice(
        start,
        start + ADMIN_RAID_PAGE_SIZE
      );


    body.replaceChildren();

    pageRows.forEach((row) => {

      const tr =
        document.createElement('tr');

      if (
        String(row.player_name || '')
        === adminRaidFounder
      ) {
        tr.classList.add(
          'is-owner'
        );
      }

      const values = [
        String(row.player_name || ''),
        statsFormatNumber(
          row.raids_joined
        ),
        `${statsFormatNumber(
          row.participation_percent,
          0
        )}%`,
        statsFormatNumber(
          row.total_point
        ),
        statsFormatNumber(
          row.avg_point,
          0
        ),
      ];

      values.forEach((value) => {

        const td =
          document.createElement('td');

        td.textContent = value;

        tr.appendChild(td);
      });

      body.appendChild(tr);
    });


    const end =
      Math.min(
        start
        + ADMIN_RAID_PAGE_SIZE,
        total
      );

    summary.textContent =
      `共 ${total} 名玩家`
      + `｜渦 ${adminRaidCount} 場`
      + `｜第 ${adminRaidPage} / ${pages} 頁`
      + (
        total
        ? `｜顯示 ${start + 1}–${end}`
        : ''
      );


    buttons.replaceChildren();

    if (pages <= 1) {
      return;
    }


    function pageButton(
      label,
      page,
      disabled = false
    ) {

      const b =
        document.createElement('button');

      b.type = 'button';
      b.textContent = label;
      b.disabled = disabled;

      b.addEventListener(
        'click',
        () => {
          adminRaidPage = page;
          renderAdminRaidStats();
        }
      );

      return b;
    }


    buttons.appendChild(
      pageButton(
        '‹',
        adminRaidPage - 1,
        adminRaidPage <= 1
      )
    );


    const first =
      Math.max(
        1,
        adminRaidPage - 2
      );

    const last =
      Math.min(
        pages,
        adminRaidPage + 2
      );

    for (
      let page = first;
      page <= last;
      page += 1
    ) {

      const button =
        pageButton(
          String(page),
          page,
          page === adminRaidPage
        );

      if (
        page === adminRaidPage
      ) {
        button.classList.add(
          'is-active'
        );
      }

      buttons.appendChild(button);
    }


    buttons.appendChild(
      pageButton(
        '›',
        adminRaidPage + 1,
        adminRaidPage >= pages
      )
    );
  }


  async function refreshAdminRaidStats() {

    if (
      !adminRaidStatsEnabled
      || !adminRaidFounder
    ) {
      return;
    }

    const section =
      ensureAdminRaidStatsSection();

    if (!section) {
      return;
    }

    const info =
      section.querySelector(
        '[data-role="admin-raid-summary"]'
      );

    if (info) {
      info.textContent =
        `正在查詢 ${adminRaidFounder}…`;
    }

    try {

      const url =
        '/api/raid_player_stats_admin.php'
        + '?founder='
        + encodeURIComponent(
          adminRaidFounder
        )
        + '&days='
        + encodeURIComponent(
          adminRaidRange
        );

      const response =
        await fetch(
          url,
          {
            cache:'no-store',
            headers:{
              Accept:'application/json'
            }
          }
        );

      const payload =
        await response.json();

      if (
        !response.ok
        || payload.ok !== true
        || !Array.isArray(
          payload.players
        )
      ) {
        throw new Error(
          payload.error
          || '查詢失敗'
        );
      }

      adminRaidRows =
        payload.players;

      adminRaidBossRows =
        Array.isArray(
          payload.bosses
        )
          ? payload.bosses
          : [];

      adminRaidCount =
        statsNumber(
          payload.raid_count
        );

      adminRaidFounder =
        String(
          payload.founder
          || adminRaidFounder
        );

      if (info) {
        info.textContent =
          `${adminRaidFounder}`
          + `｜${adminRaidCount} 場渦`
          + `｜${adminRaidRows.length} 名玩家`;
      }

      renderAdminRaidStats();

    } catch (error) {

      adminRaidRows = [];
      adminRaidBossRows = [];
      adminRaidCount = 0;

      if (info) {
        info.textContent =
          `查詢失敗：${
            error instanceof Error
            ? error.message
            : 'unknown'
          }`;
      }
    }
  }


  /*
   * 只有 admin 才會建立 section。
   * 不會自動查自己的名字。
   */
  if (adminRaidStatsEnabled) {
    ensureAdminRaidStatsSection();
  }

})();
