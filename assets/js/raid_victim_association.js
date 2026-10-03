(() => {
  'use strict';

  // RAID_VICTIM_ASSOCIATION_UI_V1

  const API =
    '/api/raid_victim_report.php';

  let report = null;
  let dialog = null;


  function n(value) {
    const x = Number(value);

    return Number.isFinite(x)
      ? x
      : 0;
  }


  function fmt(value) {
    return n(value).toLocaleString(
      'zh-TW'
    );
  }


  function el(
    tag,
    className,
    text
  ) {
    const node =
      document.createElement(tag);

    if (className) {
      node.className = className;
    }

    if (text !== undefined) {
      node.textContent = text;
    }

    return node;
  }


  function formatTime(value) {
    const s = String(value || '');

    return s
      ? s.replace('T', ' ').slice(0, 19)
      : '—';
  }


  function ensureStyle() {
    if (
      document.getElementById(
        'raid-victim-style-v1'
      )
    ) {
      return;
    }

    const style =
      document.createElement('style');

    style.id =
      'raid-victim-style-v1';

    style.textContent = `
      .raid-victim-entry {
        margin-top:8px;
        display:flex;
        gap:8px;
        align-items:center;
        flex-wrap:wrap;
      }

      .raid-victim-button {
        border:1px solid rgba(248,113,113,.48);
        background:rgba(127,29,29,.16);
        color:inherit;
        border-radius:999px;
        padding:7px 12px;
        cursor:pointer;
        font-weight:700;
      }

      .raid-victim-button:hover {
        background:rgba(127,29,29,.28);
      }

      .raid-victim-member {
        font-size:12px;
        opacity:.65;
      }

      .raid-victim-dialog {
        width:min(1100px,calc(100vw - 24px));
        max-height:88vh;
        padding:0;
        border:1px solid rgba(148,163,184,.28);
        border-radius:16px;
        background:#10141d;
        color:#e5e7eb;
        box-shadow:0 24px 80px rgba(0,0,0,.5);
      }

      .raid-victim-dialog::backdrop {
        background:rgba(0,0,0,.72);
      }

      .raid-victim-shell {
        padding:20px;
      }

      .raid-victim-head {
        display:flex;
        justify-content:space-between;
        gap:16px;
        align-items:flex-start;
      }

      .raid-victim-title {
        margin:0;
        font-size:21px;
      }

      .raid-victim-subtitle {
        margin-top:5px;
        opacity:.7;
        font-size:13px;
      }

      .raid-victim-close {
        border:0;
        background:transparent;
        color:inherit;
        font-size:24px;
        cursor:pointer;
      }

      .raid-victim-cards {
        display:grid;
        grid-template-columns:
          repeat(5,minmax(0,1fr));
        gap:8px;
        margin:18px 0;
      }

      .raid-victim-card {
        padding:12px;
        border-radius:12px;
        background:rgba(255,255,255,.05);
      }

      .raid-victim-card span {
        display:block;
        font-size:12px;
        opacity:.65;
      }

      .raid-victim-card strong {
        display:block;
        margin-top:4px;
        font-size:20px;
      }

      @media(max-width:767px) {
        .raid-victim-cards {
          grid-template-columns:
            repeat(2,minmax(0,1fr));
        }
      }
    `;

    document.head.appendChild(style);
  }


  function addTableStyles() {
    const style =
      document.getElementById(
        'raid-victim-style-v1'
      );

    style.textContent += `
      .raid-victim-table-wrap {
        overflow:auto;
        max-height:390px;
        border:1px solid rgba(148,163,184,.16);
        border-radius:12px;
      }

      .raid-victim-table {
        width:100%;
        min-width:820px;
        border-collapse:collapse;
        font-size:13px;
      }

      .raid-victim-table th,
      .raid-victim-table td {
        padding:9px 10px;
        border-bottom:
          1px solid rgba(148,163,184,.12);
        text-align:left;
        white-space:nowrap;
      }

      .raid-victim-table th {
        position:sticky;
        top:0;
        z-index:1;
        background:#171d28;
      }

      .raid-victim-code {
        font-family:ui-monospace,monospace;
      }

      .raid-victim-rewards {
        margin-top:18px;
        padding:14px;
        border-radius:12px;
        background:rgba(255,255,255,.04);
      }

      .raid-victim-reward-grid {
        display:grid;
        grid-template-columns:
          repeat(3,minmax(0,1fr));
        gap:10px;
        margin-top:10px;
      }

      .raid-victim-reward-box {
        padding:10px;
        border-radius:10px;
        background:rgba(0,0,0,.18);
      }

      .raid-victim-reward-box b {
        display:block;
        margin-bottom:6px;
      }

      .raid-victim-note {
        margin-top:12px;
        font-size:12px;
        opacity:.68;
        line-height:1.6;
      }

      .raid-victim-insufficient {
        margin-top:14px;
      }

      .raid-victim-insufficient summary {
        cursor:pointer;
      }

      @media(max-width:767px) {
        .raid-victim-reward-grid {
          grid-template-columns:1fr;
        }
      }
    `;
  }


  function rewardBox(
    title,
    values
  ) {
    const box =
      el(
        'div',
        'raid-victim-reward-box'
      );

    box.appendChild(
      el('b', '', title)
    );

    const entries =
      Object.entries(
        values || {}
      );

    if (!entries.length) {
      box.appendChild(
        el('div', '', '—')
      );

      return box;
    }

    entries.forEach(
      ([name, quantity]) => {
        box.appendChild(
          el(
            'div',
            '',
            `${name} ×${fmt(quantity)}`
          )
        );
      }
    );

    return box;
  }


  // RAID_VICTIM_ASSOCIATION_LAYOUT_V4
  function buildRows(
    tbody,
    rows
  ) {
    tbody.replaceChildren();

    (rows || []).forEach(
      (row) => {

        const tr =
          document.createElement('tr');

        const code =
          String(
            row.raid_id || '—'
          );


        /*
         * Raid ID is the primary official lookup key.
         * Keep it first and copyable.
         */
        const codeCell =
          document.createElement('td');

        codeCell.className =
          'raid-victim-code';


        const codeButton =
          el(
            'button',
            'raid-victim-code-copy',
            code
          );

        codeButton.type =
          'button';

        codeButton.title =
          '點擊複製渦碼 / Raid ID';


        codeButton.addEventListener(
          'click',
          async () => {

            let copied = false;

            try {
              if (
                navigator.clipboard
                && window.isSecureContext
              ) {
                await navigator.clipboard
                  .writeText(code);

                copied = true;

              } else {

                const textarea =
                  document.createElement(
                    'textarea'
                  );

                textarea.value = code;

                textarea.style.position =
                  'fixed';

                textarea.style.opacity =
                  '0';

                document.body.appendChild(
                  textarea
                );

                textarea.select();

                copied =
                  document.execCommand(
                    'copy'
                  );

                textarea.remove();
              }

            } catch (_error) {
              copied = false;
            }


            if (!copied) {
              return;
            }


            codeButton.classList.add(
              'is-copied'
            );

            codeButton.textContent =
              `✓ ${code}`;


            window.setTimeout(
              () => {
                codeButton.classList.remove(
                  'is-copied'
                );

                codeButton.textContent =
                  code;
              },
              900
            );
          }
        );


        codeCell.appendChild(
          codeButton
        );

        tr.appendChild(
          codeCell
        );


        const values = [
          formatTime(row.time),

          String(
            row.boss || '—'
          ),

          String(
            row.whirlpool_tier || '—'
          ),

          row.rarity
            ? `${row.rarity}★`
            : '—',

          fmt(
            row.participant_count
          ),

          String(
            row.evidence || '—'
          ),
        ];


        values.forEach(
          (value) => {

            const td =
              el(
                'td',
                '',
                value
              );

            tr.appendChild(td);
          }
        );


        tbody.appendChild(tr);
      }
    );
  }

  function openDialog() {
    if (!report) {
      return;
    }

    if (dialog) {
      dialog.showModal();
      return;
    }

    dialog =
      document.createElement('dialog');

    dialog.className =
      'raid-victim-dialog';

    const shell =
      el('div', 'raid-victim-shell');

    const head =
      el('div', 'raid-victim-head');

    const titleWrap =
      document.createElement('div');

    titleWrap.appendChild(
      el(
        'h2',
        'raid-victim-title',
        '🧾 受害者協會'
      )
    );

    titleWrap.appendChild(
      el(
        'div',
        'raid-victim-subtitle',
        `${report.founder}｜會員限定・確認異常紀錄`
      )
    );

    const close =
      el(
        'button',
        'raid-victim-close',
        '×'
      );

    close.type = 'button';

    close.addEventListener(
      'click',
      () => dialog.close()
    );

    // RAID_VICTIM_EXPORT_BUTTON_V1
    const headActions =
      el(
        'div',
        'raid-victim-head-actions'
      );

    const exportButton =
      el(
        'button',
        'raid-victim-export',
        '⬇ 匯出 Excel'
      );

    exportButton.type =
      'button';

    exportButton.addEventListener(
      'click',
      () => {
        window.location.href =
          `${API}?format=xlsx`;
      }
    );

    headActions.append(
      exportButton,
      close
    );

    head.append(
      titleWrap,
      headActions
    );

    shell.appendChild(head);


    const s =
      report.summary || {};

    const cards =
      el('div', 'raid-victim-cards');

    [
      ['開渦總數', s.opened],
      [
        '有本人紀錄',
        s.founder_recorded
      ],
      [
        '渦主未列名',
        s.founder_missing
      ],
      [
        '確認異常',
        s.confirmed_anomaly
      ],
      [
        '資料不足',
        s.insufficient_evidence
      ],
    ].forEach(
      ([label, value]) => {
        const card =
          el(
            'div',
            'raid-victim-card'
          );

        card.append(
          el('span', '', label),
          el(
            'strong',
            '',
            fmt(value)
          )
        );

        cards.appendChild(card);
      }
    );

    shell.appendChild(cards);

    const lookupHint =
      el(
        'div',
        'raid-victim-lookup-hint',
        '官方回查請以「渦碼 / Raid ID」為主；點擊渦碼即可複製。'
      );

    shell.appendChild(
      lookupHint
    );


    const tableWrap =
      el(
        'div',
        'raid-victim-table-wrap'
      );

    const table =
      el(
        'table',
        'raid-victim-table'
      );

    table.innerHTML = `
      <thead>
        <tr>
          <th>渦碼 / Raid ID</th>
          <th>擊破時間</th>
          <th>BOSS</th>
          <th>渦階</th>
          <th>星等</th>
          <th>人數</th>
          <th>判定</th>
        </tr>
      </thead>
      <tbody></tbody>
    `;

    buildRows(
      table.querySelector('tbody'),
      report.confirmed
    );

    tableWrap.appendChild(table);
    shell.appendChild(tableWrap);


    const rewards =
      el(
        'div',
        'raid-victim-rewards'
      );

    rewards.appendChild(
      el(
        'b',
        '',
        '可能受影響獎勵配置'
      )
    );

    const grid =
      el(
        'div',
        'raid-victim-reward-grid'
      );

    const rs =
      report.reward_summary || {};

    grid.append(
      rewardBox(
        '開渦配置',
        rs.discovery
      ),
      rewardBox(
        '參加配置',
        rs.participation
      ),
      rewardBox(
        '擊破配置',
        rs.defeat
      )
    );

    rewards.appendChild(grid);

    rewards.appendChild(
      el(
        'div',
        'raid-victim-note',
        '排名獎勵無法精確還原，不納入合計。'
        + ' UL.GG 可驗證擊破、渦主身分及玩家名單；'
        + ' 無法驗證官方帳號實際入帳結果。'
      )
    );

    shell.appendChild(rewards);


    if (
      Array.isArray(report.insufficient)
      && report.insufficient.length
    ) {
      const details =
        el(
          'details',
          'raid-victim-insufficient'
        );

      details.appendChild(
        el(
          'summary',
          '',
          `資料不足 ${
            report.insufficient.length
          } 場`
        )
      );

      const text =
        report.insufficient
          .map(
            (row) =>
              `${formatTime(row.time)}｜`
              + `${row.boss}｜`
              + `${row.raid_id}`
          )
          .join('\n');

      const pre =
        el('pre', '', text);

      pre.style.whiteSpace =
        'pre-wrap';

      details.appendChild(pre);
      shell.appendChild(details);
    }


    dialog.appendChild(shell);
    document.body.appendChild(dialog);

    dialog.addEventListener(
      'click',
      (event) => {
        if (event.target === dialog) {
          dialog.close();
        }
      }
    );

    dialog.showModal();
  }


  function renderEntry() {
    const section =
      document.querySelector(
        '[data-role="raid-stats-section"]'
      );

    if (!section || !report) {
      return;
    }

    if (
      section.querySelector(
        '[data-role="raid-victim-entry"]'
      )
    ) {
      return;
    }

    const summary =
      section.querySelector(
        '[data-role="raid-stats-summary"]'
      );

    if (!summary) {
      return;
    }

    const wrap =
      el(
        'div',
        'raid-victim-entry'
      );

    wrap.dataset.role =
      'raid-victim-entry';

    const count =
      n(
        report.summary
        && report.summary.confirmed_anomaly
      );

    const button =
      el(
        'button',
        'raid-victim-button',
        `🧾 受害者協會 ${fmt(count)}`
      );

    button.type = 'button';

    button.addEventListener(
      'click',
      openDialog
    );

    wrap.append(
      button,
      el(
        'span',
        'raid-victim-member',
        '🔒 會員限定'
      )
    );

    summary.insertAdjacentElement(
      'afterend',
      wrap
    );
  }


  async function loadReport() {
    try {
      const response =
        await fetch(
          API,
          {
            cache: 'no-store',
            headers: {
              Accept:
                'application/json',
            },
          }
        );

      if (response.status === 401) {
        return;
      }

      if (!response.ok) {
        throw new Error(
          `HTTP ${response.status}`
        );
      }

      const payload =
        await response.json();

      if (
        !payload
        || payload.ok !== true
      ) {
        return;
      }

      report = payload;

      renderEntry();

    } catch (error) {
      console.warn(
        '[RAID VICTIM]',
        error
      );
    }
  }


  ensureStyle();
  addTableStyles();

  loadReport();

})();


/* =========================================================
 * RAID_VICTIM_ASSOCIATION_UI_V2
 * Visual integration with Raid Observer.
 * CSS-only override. No data / privacy / API behavior changed.
 * ========================================================= */
(() => {
  const style = document.createElement('style');

  style.id = 'raid-victim-style-v2';

  style.textContent = `
    .raid-victim-dialog {
      width:min(820px,calc(100vw - 32px));
      max-height:82vh;
      border:
        1px solid rgba(184,190,255,.20);
      border-radius:12px;
      background:#11151d;
      box-shadow:
        0 18px 60px rgba(0,0,0,.52);
    }

    .raid-victim-dialog::backdrop {
      background:rgba(3,6,12,.64);
      backdrop-filter:blur(2px);
    }

    .raid-victim-shell {
      padding:18px 20px 20px;
    }

    .raid-victim-head {
      align-items:center;
      padding-bottom:12px;
      border-bottom:
        1px solid rgba(255,255,255,.07);
    }

    .raid-victim-title {
      font-size:18px;
      line-height:1.35;
      letter-spacing:.02em;
    }

    .raid-victim-subtitle {
      margin-top:3px;
      font-size:12px;
      color:#8e96a6;
      opacity:1;
    }

    .raid-victim-close {
      width:32px;
      height:32px;
      padding:0;
      border-radius:7px;
      font-size:21px;
      line-height:1;
      color:#9ca3af;
    }

    /*
     * KPI: stop looking like dashboard cards.
     * Make them one quiet information strip.
     */
    .raid-victim-cards {
      display:flex;
      flex-wrap:wrap;
      gap:0;
      margin:13px 0 15px;
      padding:9px 12px;
      border:
        1px solid rgba(184,190,255,.10);
      border-radius:9px;
      background:
        rgba(184,190,255,.035);
    }

    .raid-victim-card {
      position:relative;
      display:flex;
      align-items:baseline;
      gap:6px;
      min-width:0;
      padding:3px 14px 3px 0;
      margin-right:14px;
      border-radius:0;
      background:transparent;
    }

    .raid-victim-card:not(:last-child)::after {
      content:'';
      position:absolute;
      right:0;
      top:4px;
      bottom:4px;
      width:1px;
      background:rgba(255,255,255,.09);
    }

    .raid-victim-card span {
      display:inline;
      font-size:12px;
      color:#8e96a6;
      opacity:1;
      white-space:nowrap;
    }

    .raid-victim-card strong {
      display:inline;
      margin:0;
      font-size:14px;
      font-weight:700;
      color:#d7dae2;
    }

    /*
     * Confirmed anomaly is the only number
     * that deserves visual emphasis.
     */
    .raid-victim-card:nth-child(4) strong {
      color:#f0a0a0;
    }

    /*
     * Only the dialog scrolls vertically.
     * Remove the "scroll inside scroll" feeling.
     */
    .raid-victim-table-wrap {
      overflow-x:auto;
      overflow-y:visible;
      max-height:none;
      border:
        1px solid rgba(255,255,255,.08);
      border-radius:9px;
      background:rgba(0,0,0,.10);
    }

    .raid-victim-table {
      width:100%;
      min-width:720px;
      table-layout:fixed;
      font-size:12.5px;
    }

    .raid-victim-table th,
    .raid-victim-table td {
      padding:8px 9px;
      vertical-align:top;
      border-bottom:
        1px solid rgba(255,255,255,.055);
    }

    .raid-victim-table th {
      position:static;
      background:
        rgba(184,190,255,.055);
      color:#cbd0ff;
      font-size:12px;
      font-weight:700;
    }

    .raid-victim-table tbody tr:last-child td {
      border-bottom:0;
    }

    .raid-victim-table tbody tr:hover {
      background:rgba(184,190,255,.035);
    }

    .raid-victim-table th:nth-child(1),
    .raid-victim-table td:nth-child(1) {
      width:145px;
    }

    .raid-victim-table th:nth-child(2),
    .raid-victim-table td:nth-child(2) {
      width:74px;
    }

    .raid-victim-table th:nth-child(3),
    .raid-victim-table td:nth-child(3) {
      width:67px;
    }

    .raid-victim-table th:nth-child(4),
    .raid-victim-table td:nth-child(4) {
      width:45px;
      text-align:center;
    }

    .raid-victim-table th:nth-child(5),
    .raid-victim-table td:nth-child(5) {
      width:110px;
    }

    .raid-victim-table th:nth-child(6),
    .raid-victim-table td:nth-child(6) {
      width:62px;
      text-align:center;
    }

    .raid-victim-table th:nth-child(7),
    .raid-victim-table td:nth-child(7) {
      width:auto;
      white-space:normal;
      line-height:1.45;
      color:#adb4c0;
    }

    .raid-victim-code {
      font-size:11.5px;
      color:#aeb5c5;
    }

    /*
     * Reward area: one quiet footer block,
     * not another dashboard.
     */
    .raid-victim-rewards {
      margin-top:14px;
      padding:12px 14px;
      border:
        1px solid rgba(184,190,255,.10);
      border-radius:9px;
      background:
        rgba(184,190,255,.028);
    }

    .raid-victim-rewards > b {
      font-size:13px;
      color:#d6d9e5;
    }

    .raid-victim-reward-grid {
      gap:7px;
      margin-top:8px;
    }

    .raid-victim-reward-box {
      padding:8px 10px;
      border:
        1px solid rgba(255,255,255,.055);
      border-radius:7px;
      background:rgba(0,0,0,.10);
      font-size:12px;
      line-height:1.65;
    }

    .raid-victim-reward-box b {
      margin-bottom:2px;
      color:#9fa7ba;
      font-size:11px;
      font-weight:600;
    }

    .raid-victim-note {
      margin-top:9px;
      padding-top:8px;
      border-top:
        1px solid rgba(255,255,255,.055);
      color:#7f8797;
      font-size:11px;
      line-height:1.55;
      opacity:1;
    }

    .raid-victim-insufficient {
      margin-top:10px;
      color:#9299a8;
      font-size:12px;
    }

    .raid-victim-insufficient summary {
      padding:5px 0;
    }

    @media(max-width:767px) {
      .raid-victim-dialog {
        width:calc(100vw - 16px);
        max-height:90vh;
      }

      .raid-victim-shell {
        padding:14px;
      }

      .raid-victim-cards {
        gap:5px 12px;
      }

      .raid-victim-card {
        width:auto;
        margin:0;
        padding:2px 0;
      }

      .raid-victim-card::after {
        display:none;
      }

      .raid-victim-table {
        min-width:680px;
      }

      .raid-victim-reward-grid {
        grid-template-columns:1fr;
      }
    }
  `;

  document.head.appendChild(style);
})();


/* =========================================================
 * RAID_VICTIM_ASSOCIATION_SCROLLBAR_V3
 * ULGG-style X / Y scrollbar.
 * Scoped to victim association only.
 * ========================================================= */
(() => {
  const style =
    document.createElement('style');

  style.id =
    'raid-victim-scrollbar-v3';

  style.textContent = `

    /*
     * Firefox
     */
    .raid-victim-dialog,
    .raid-victim-table-wrap {
      scrollbar-width: thin;

      scrollbar-color:
        rgba(184,190,255,.30)
        rgba(7,10,16,.72);
    }


    /*
     * Chromium / Edge / Safari
     */
    .raid-victim-dialog::-webkit-scrollbar,
    .raid-victim-table-wrap::-webkit-scrollbar {
      width: 8px;
      height: 8px;
    }


    .raid-victim-dialog::-webkit-scrollbar-track,
    .raid-victim-table-wrap::-webkit-scrollbar-track {
      background:
        rgba(7,10,16,.72);

      border-radius:999px;
    }


    .raid-victim-dialog::-webkit-scrollbar-thumb,
    .raid-victim-table-wrap::-webkit-scrollbar-thumb {
      background:
        rgba(184,190,255,.26);

      border:
        2px solid
        rgba(7,10,16,.72);

      border-radius:999px;

      min-height:36px;
    }


    .raid-victim-dialog::-webkit-scrollbar-thumb:hover,
    .raid-victim-table-wrap::-webkit-scrollbar-thumb:hover {
      background:
        rgba(184,190,255,.48);
    }


    .raid-victim-dialog::-webkit-scrollbar-thumb:active,
    .raid-victim-table-wrap::-webkit-scrollbar-thumb:active {
      background:
        rgba(203,208,255,.62);
    }


    /*
     * X/Y intersection.
     * Prevent native white scrollbar corner.
     */
    .raid-victim-dialog::-webkit-scrollbar-corner,
    .raid-victim-table-wrap::-webkit-scrollbar-corner {
      background:#0b0f16;
    }


    /*
     * Slight breathing room at horizontal edges.
     */
    .raid-victim-table-wrap::-webkit-scrollbar-track:horizontal {
      margin-inline:6px;
    }


    /*
     * Vertical dialog scrollbar.
     */
    .raid-victim-dialog::-webkit-scrollbar-track:vertical {
      margin-block:8px;
    }

  `;

  document.head.appendChild(style);
})();


/* =========================================================
 * RAID_VICTIM_EXPORT_BUTTON_STYLE_V1
 * ========================================================= */
(() => {
  const style =
    document.createElement('style');

  style.id =
    'raid-victim-export-button-style-v1';

  style.textContent = `
    .raid-victim-head-actions {
      display:flex;
      align-items:center;
      gap:7px;
      flex-shrink:0;
    }

    .raid-victim-export {
      appearance:none;
      border:
        1px solid rgba(184,190,255,.24);
      border-radius:7px;
      background:
        rgba(184,190,255,.075);
      color:#cbd0ff;
      padding:6px 10px;
      font:inherit;
      font-size:12px;
      font-weight:700;
      line-height:1.2;
      cursor:pointer;
      white-space:nowrap;

      transition:
        background .16s ease,
        border-color .16s ease,
        color .16s ease;
    }

    .raid-victim-export:hover {
      background:
        rgba(184,190,255,.15);
      border-color:
        rgba(184,190,255,.42);
      color:#fff;
    }

    .raid-victim-export:focus-visible {
      outline:
        2px solid rgba(184,190,255,.55);
      outline-offset:2px;
    }

    @media(max-width:520px) {
      .raid-victim-export {
        padding:6px 8px;
      }
    }
  `;

  document.head.appendChild(style);
})();


/* =========================================================
 * RAID_VICTIM_ASSOCIATION_LAYOUT_V4_STYLE
 * Raid ID first / sticky / copyable.
 * ========================================================= */
(() => {
  const style =
    document.createElement('style');

  style.id =
    'raid-victim-layout-v4-style';

  style.textContent = `

    .raid-victim-lookup-hint {
      margin:-4px 0 10px;
      padding:7px 9px;

      border:
        1px solid rgba(184,190,255,.10);

      border-radius:7px;

      background:
        rgba(184,190,255,.035);

      color:#9299aa;

      font-size:11px;
      line-height:1.45;
    }


    /*
     * Fits inside desktop modal without
     * requiring horizontal scrolling.
     */
    .raid-victim-table {
      min-width:700px;
    }


    .raid-victim-table th:nth-child(1),
    .raid-victim-table td:nth-child(1) {
      width:126px;
    }

    .raid-victim-table th:nth-child(2),
    .raid-victim-table td:nth-child(2) {
      width:140px;
    }

    .raid-victim-table th:nth-child(3),
    .raid-victim-table td:nth-child(3) {
      width:76px;
    }

    .raid-victim-table th:nth-child(4),
    .raid-victim-table td:nth-child(4) {
      width:70px;
    }

    .raid-victim-table th:nth-child(5),
    .raid-victim-table td:nth-child(5) {
      width:45px;
      text-align:center;
    }

    .raid-victim-table th:nth-child(6),
    .raid-victim-table td:nth-child(6) {
      width:50px;
      text-align:center;
    }

    .raid-victim-table th:nth-child(7),
    .raid-victim-table td:nth-child(7) {
      width:auto;
      white-space:normal;
      line-height:1.45;
    }


    /*
     * Raid ID remains visible even when a
     * narrow screen needs horizontal scroll.
     */
    .raid-victim-table th:first-child {
      position:sticky;
      left:0;
      z-index:4;

      background:#1a202c;
    }

    .raid-victim-table td:first-child {
      position:sticky;
      left:0;
      z-index:2;

      background:#11161f;
    }

    .raid-victim-table tbody tr:hover
    td:first-child {
      background:#171d28;
    }


    .raid-victim-code-copy {
      appearance:none;

      width:100%;

      padding:5px 6px;

      border:
        1px solid rgba(184,190,255,.14);

      border-radius:6px;

      background:
        rgba(184,190,255,.055);

      color:#cbd0ff;

      font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Consolas,
        monospace;

      font-size:11px;
      font-weight:700;

      cursor:copy;

      white-space:nowrap;

      transition:
        background .15s ease,
        border-color .15s ease,
        color .15s ease;
    }


    .raid-victim-code-copy:hover {
      background:
        rgba(184,190,255,.13);

      border-color:
        rgba(184,190,255,.36);

      color:#fff;
    }


    .raid-victim-code-copy.is-copied {
      background:
        rgba(184,190,255,.20);

      border-color:
        rgba(184,190,255,.50);

      color:#fff;
    }


    @media(max-width:767px) {

      .raid-victim-table {
        min-width:650px;
      }

      .raid-victim-dialog {
        width:
          calc(100vw - 12px);
      }

      .raid-victim-shell {
        padding:12px;
      }

    }

  `;

  document.head.appendChild(
    style
  );
})();
