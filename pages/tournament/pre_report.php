<?php
// ⚠️ 不要 session_start()，交給 config.php
require_once __DIR__ . '/../../config.php';
$pdo = $db;

$pageTitleText = 'ULGG CUP 戰前情報';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "ulgg_cup";

ob_start();
?>

<style>
  .pre-report {

    max-width: 1400px;
    margin: 25px auto;
    padding: 0 15px;

  }

  /* ===========================
Hero
=========================== */

  .pre-hero {

    position: relative;

    overflow: hidden;

    border-radius: 18px;

    padding: 70px 40px;

    text-align: center;

    background:
      linear-gradient(rgba(8, 8, 8, .86), rgba(8, 8, 8, .86)),
      radial-gradient(circle at top, #5a471f 0%, #111 70%);

    border: 1px solid #3d3d3d;

    box-shadow: 0 10px 35px rgba(0, 0, 0, .45);

  }

  .pre-hero:before {

    content: "";

    position: absolute;

    left: -80px;
    top: -80px;

    width: 220px;
    height: 220px;

    border-radius: 50%;

    background: rgba(255, 194, 74, .06);

  }

  .pre-hero:after {

    content: "";

    position: absolute;

    right: -100px;
    bottom: -100px;

    width: 260px;
    height: 260px;

    border-radius: 50%;

    background: rgba(255, 194, 74, .05);

  }

  .hero-sub {

    color: #d6ab57;

    font-size: 18px;

    letter-spacing: 3px;

    text-transform: uppercase;

  }

  .hero-title {

    margin-top: 15px;

    font-size: 54px;

    color: #fff;

    font-weight: 800;

    letter-spacing: 2px;

  }

  .hero-desc {

    margin-top: 18px;

    color: #aaa;

    font-size: 18px;

  }

  .hero-version {

    margin-top: 25px;

    color: #777;

    font-size: 13px;

  }

  /* ===========================
Summary
=========================== */

  .summary-grid {

    display: grid;

    grid-template-columns: repeat(4, 1fr);

    gap: 18px;

    margin-top: 28px;

  }

  .summary-card {

    background: #181818;

    border-radius: 15px;

    padding: 28px 20px;

    text-align: center;

    border: 1px solid #333;

    transition: .25s;

  }

  .summary-card:hover {

    transform: translateY(-6px);

    border-color: #d5ad5f;

    box-shadow: 0 10px 30px rgba(0, 0, 0, .35);

  }

  .summary-value {

    font-size: 42px;

    color: #fff;

    font-weight: bold;

  }

  .summary-title {

    margin-top: 8px;

    color: #999;

    letter-spacing: 1px;

    text-transform: uppercase;

  }

  /* ===========================
Section
=========================== */

  .report-section {

    margin-top: 45px;

  }

  .section-title {

    color: #d6ab57;

    font-size: 30px;

    font-weight: bold;

    border-left: 5px solid #d6ab57;

    padding-left: 15px;

    margin-bottom: 25px;

  }

  /* ===========================
Meta Grid
=========================== */

  .meta-grid {

    display: grid;

    grid-template-columns: 1fr 1fr;

    gap: 22px;

  }

  .meta-box {

    background: #181818;

    border-radius: 16px;

    border: 1px solid #333;

    padding: 10px;

    min-height: 380px;

  }

  .meta-box-title {

    color: #fff;

    font-size: 22px;

    font-weight: bold;

    margin-bottom: 20px;

  }

  /* ===========================
Responsive
=========================== */

  @media(max-width:992px) {

    .summary-grid {

      grid-template-columns: repeat(2, 1fr);

    }

    .meta-grid {

      grid-template-columns: 1fr;

    }

    .hero-title {

      font-size: 40px;

    }

  }

  @media(max-width:576px) {

    .summary-grid {

      grid-template-columns: 1fr;

    }

    .hero-title {

      font-size: 32px;

    }

    .pre-hero {

      padding: 50px 25px;

    }

  }

  .character-grid {

    display: grid;

    grid-template-columns: repeat(5, 1fr);

    gap: 10px;

  }

  .character-card {

    position: relative;

    background: #121212;

    border: 1px solid #303030;

    border-radius: 14px;

    padding: 14px;

    text-align: center;

    transition: .25s;

  }

  .character-card:hover {

    transform: translateY(-6px);

    border-color: #d8b062;

    box-shadow: 0 10px 25px rgba(0, 0, 0, .35);

  }

  .rank-badge {

    position: absolute;

    right: 0px;

    top: 0px;

    background: #d8b062;

    color: #111;

    font-size: 12px;

    font-weight: bold;

    border-radius: 30px;

    padding: 0px 5px;

  }

  .character-img {

    width: 100%;

    height: auto;

    display: block;

    border-radius: 8px;

  }

  .character-card {

    padding: 10px;

  }

  .character-name {

    margin-top: 10px;

    color: #fff;

    font-weight: bold;

  }

  .character-use {

    color: #d8b062;

    font-size: 13px;

    margin-top: 6px;

  }

  .character-win {

    color: #72d672;

    font-size: 13px;

    margin-top: 2px;

  }

  .report-card {
    background: #181818;
    border: 1px solid #333;
    border-radius: 16px;
    padding: 28px;
    color: #ddd;
    line-height: 1.9;
    margin: 20px 0px;
  }

  .report-card h3 {
    margin: 0 0 18px;
    color: #ffd479;
    font-size: 24px;
  }

  .report-card p {
    margin: 0 0 16px;
  }

  .report-card p:last-child {
    margin-bottom: 0;
  }

  .report-card b {
    color: #fff;
  }

  .team-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-top: 16px;
  }

  .team-card {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    background: #121212;
    border: 1px solid #303030;
    border-radius: 14px;
    padding: 10px 12px 10px 14px;
    transition: .25s;
    min-height: 96px;
  }

  .team-card:hover {
    transform: translateY(-3px);
    border-color: #d8b062;
    box-shadow: 0 10px 25px rgba(0, 0, 0, .35);
  }

  .team-rank {
    position: absolute;
    left: 6px;
    top: 6px;
    z-index: 3;
    background: rgba(216, 176, 98, .92);
    color: #111;
    font-size: 11px;
    font-weight: bold;
    border-radius: 30px;
    padding: 2px 7px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, .35);
  }

  .team-chars {
    display: flex;
    align-items: center;
    flex: 0 0 118px;
    padding-left: 8px;
  }

  .team-char-img {
    width: 54px;
    height: 72px;
    object-fit: cover;
    object-position: top center;
    border-radius: 8px;
    background: #000;
    border: 1px solid #2c2c2c;
    box-shadow: 0 4px 12px rgba(0, 0, 0, .45);
  }

  .team-char-img+.team-char-img {
    margin-left: -10px;
  }

  .team-info {
    min-width: 0;
    flex: 1;
  }

  .team-title {
    color: #fff;
    font-weight: bold;
    font-size: 14px;
    line-height: 1.4;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .team-meta {
    display: flex;
    gap: 12px;
    margin-top: 8px;
  }

  .team-meta-item {
    display: flex;
    align-items: baseline;
    gap: 4px;
  }

  .team-meta-label {
    color: #888;
    font-size: 12px;
  }

  .team-meta-value {
    color: #d8b062;
    font-size: 15px;
    font-weight: bold;
  }

  .team-meta-value.win {
    color: #72d672;
  }

  @media(max-width: 992px) {
    .team-grid {
      grid-template-columns: 1fr;
    }
  }

  @media(max-width: 576px) {
    .team-card {
      padding: 10px;
    }

    .team-rank {
      left: 5px;
      top: 5px;
      font-size: 10px;
      padding: 2px 6px;
    }

    .team-chars {
      flex-basis: 98px;
    }

    .team-char-img {
      width: 46px;
      height: 62px;
    }

    .team-char-img+.team-char-img {
      margin-left: -22px;
    }

    .team-title {
      font-size: 13px;
    }
  }

  .team-board {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-top: 18px;
  }

  .team-board-col {
    background: #101010;
    border: 1px solid #2d2d2d;
    border-radius: 14px;
    padding: 14px;
  }

  .team-board-title {
    color: #d8b062;
    font-size: 17px;
    font-weight: bold;
    margin-bottom: 12px;
  }

  .team-grid-single {
    grid-template-columns: 1fr;
    margin-top: 0;
  }

  @media(max-width: 992px) {
    .team-board {
      grid-template-columns: 1fr;
    }
  }

  .weapon-board {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-top: 18px;
  }

  .weapon-board-col {
    background: #101010;
    border: 1px solid #2d2d2d;
    border-radius: 14px;
    padding: 14px;
  }

  .weapon-board-title {
    color: #d8b062;
    font-size: 17px;
    font-weight: bold;
    margin-bottom: 12px;
  }

  .weapon-list {
    display: grid;
    gap: 8px;
  }

  .weapon-card {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #121212;
    border: 1px solid #303030;
    border-radius: 12px;
    padding: 10px 12px;
    transition: .25s;
  }

  .weapon-card:hover {
    transform: translateY(-2px);
    border-color: #d8b062;
    box-shadow: 0 8px 20px rgba(0, 0, 0, .3);
  }

  .weapon-rank {
    flex: 0 0 36px;
    background: #d8b062;
    color: #111;
    font-size: 12px;
    font-weight: bold;
    border-radius: 30px;
    padding: 3px 8px;
    width: 36px;
    text-align: center;
  }

  .weapon-name {
    flex: 1;
    min-width: 0;
    color: #fff;
    font-weight: bold;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .weapon-meta {
    display: flex;
    gap: 10px;
    flex: 0 0 auto;
  }

  .weapon-meta span {
    color: #888;
    font-size: 12px;
  }

  .weapon-meta b {
    color: #d8b062;
    font-size: 14px;
  }

  .weapon-meta b.win {
    color: #72d672;
  }

  .weapon-pair-char {
    width: 42px;
    height: 56px;
    object-fit: cover;
    object-position: top center;
    border-radius: 7px;
    border: 1px solid #2c2c2c;
    background: #000;
    flex: 0 0 auto;
  }

  @media(max-width: 992px) {
    .weapon-board {
      grid-template-columns: 1fr;
    }
  }

  .hero-title {
    margin-top: 15px;
    font-size: 48px;
    color: #fff;
    font-weight: 800;
    line-height: 1.2;
  }

  .hero-title span {
    display: block;
    margin-top: 10px;
    font-size: 30px;
    color: #d8b062;
    letter-spacing: 4px;
    text-transform: uppercase;
  }

  .weapon-stats {
    display: flex;
    gap: 4px;
    margin-top: 4px;
  }

  .weapon-stats span {
    font-size: 11px;
    line-height: 1;
    padding: 3px 6px;
    border-radius: 4px;
    font-weight: bold;
  }

  .stat-melee {
    color: #ff7777;
    background: rgba(255, 80, 80, .12);
    border: 1px solid rgba(255, 80, 80, .28);
  }

  .stat-ranged {
    color: #7dff9b;
    background: rgba(80, 255, 130, .10);
    border: 1px solid rgba(80, 255, 130, .25);
  }

  .stat-all {
    color: #eee;
    background: rgba(255, 255, 255, .10);
    border: 1px solid rgba(255, 255, 255, .25);
  }

  .player-board {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-top: 18px;
  }

  .player-card {
    background: #121212;
    border: 1px solid #303030;
    border-radius: 14px;
    padding: 14px;
    transition: .25s;
  }

  .player-card:hover {
    transform: translateY(-3px);
    border-color: #d8b062;
    box-shadow: 0 10px 25px rgba(0, 0, 0, .35);
  }

  .player-head {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .player-seed {
    background: #d8b062;
    color: #111;
    font-size: 12px;
    font-weight: bold;
    border-radius: 30px;
    padding: 3px 8px;
  }

  .player-name {
    color: #fff;
    font-weight: bold;
    font-size: 15px;
  }

  .player-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid #2d2d2d;
  }

  .player-stats span {
    display: block;
    color: #888;
    font-size: 12px;
  }

  .player-stats b {
    color: #d8b062;
    font-size: 16px;
  }

  .player-stats b.win {
    color: #72d672;
  }

  .player-team-chars {
    margin-top: 12px;
  }

  @media(max-width: 992px) {
    .player-board {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  @media(max-width: 576px) {
    .player-board {
      grid-template-columns: 1fr;
    }
  }

  .weapon-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
  }

  .weapon-icon {
    width: 42px;
    height: 42px;
    object-fit: contain;
    flex-shrink: 0;
  }



  /* ===========================
Mobile Fix
=========================== */

  * {
    box-sizing: border-box;
  }

  .pre-report,
  .report-card,
  .meta-box,
  .team-board-col,
  .weapon-board-col {
    max-width: 100%;
    overflow: hidden;
  }

  @media(max-width: 576px) {
    .pre-report {
      padding: 0 8px;
    }

    .report-card {
      padding: 16px;
    }

    .meta-box {
      padding: 10px;
    }

    .character-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
    }

    .character-card {
      min-width: 0;
      padding: 8px;
    }

    .character-name {
      font-size: 13px;
      word-break: break-word;
    }

    .team-board,
    .weapon-board,
    .player-board {
      grid-template-columns: 1fr;
    }

    .team-card {
      gap: 8px;
      padding: 10px;
      min-width: 0;
    }

    .team-rank {
      flex: 0 0 auto;
    }

    .team-chars {
      flex: 0 0 118px;
      padding-left: 0;
    }

    .team-char-img {
      width: 44px;
      height: 58px;
    }

    .team-char-img+.team-char-img {
      margin-left: -8px;
    }

    .team-info {
      min-width: 0;
    }

    .team-title {
      white-space: normal;
      font-size: 12px;
      line-height: 1.4;
    }

    .team-meta {
      flex-wrap: wrap;
      gap: 6px;
    }

    .weapon-card {
      display: flex;
      flex-direction: row;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      padding: 10px;
    }

    .weapon-rank {
      width: 36px;
      flex: 0 0 36px;
      padding: 3px 0;
      text-align: center;
    }

    .weapon-icon,
    .weapon-pair-char {
      width: 42px;
      height: 42px;
      flex: 0 0 42px;
    }

    .weapon-pair-char {
      height: 56px;
    }

    .pair-icons {
      flex: 0 0 76px;
    }

    .weapon-main {
      flex: 1 1 0;
      min-width: 120px;
    }

    .weapon-name {
      white-space: normal;
      overflow: visible;
      text-overflow: unset;
      font-size: 13px;
      line-height: 1.35;
    }

    .weapon-stats {
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
      margin-top: 5px;
    }

    .weapon-meta {
      width: 100%;
      flex: 0 0 100%;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 2px;
      margin-top: 6px;
      padding-top: 8px;
      border-top: 1px solid #2d2d2d;
    }

    .weapon-meta span {
      display: block;
      white-space: nowrap;
    }

    .pair-weapon-name {
      display: block;
      margin-top: 2px;
    }




    .player-board {
      grid-template-columns: 1fr;
    }

    .player-stats {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }


  }

  .pair-icons {
    position: relative;
    width: 76px;
    height: 58px;
    flex: 0 0 76px;
  }

  .pair-char-img {
    width: 42px;
    height: 56px;
    object-fit: cover;
    object-position: top center;
    border-radius: 7px;
    border: 1px solid #2c2c2c;
    background: #000;
    position: absolute;
    left: 0;
    top: 0;
    z-index: 2;
  }

  .pair-weapon-img {
    width: 38px;
    height: 38px;
    object-fit: contain;
    border-radius: 7px;
    border: 1px solid #2c2c2c;
    background: #050505;
    position: absolute;
    right: 0;
    bottom: 0;
    z-index: 3;
  }

  .pair-weapon-name {
    color: #d8b062;
    font-size: 12px;
    font-weight: bold;
  }

  @media(max-width: 576px) {
    .pair-icons {
      grid-column: 2;
      grid-row: 1;
      width: 72px;
      height: 56px;
      flex: 0 0 72px;
    }

    .pair-char-img {
      width: 40px;
      height: 54px;
    }

    .pair-weapon-img {
      width: 34px;
      height: 34px;
    }
  }

  .team-card-link {
    text-decoration: none;
    color: inherit;
    cursor: pointer;
  }

  .team-card-link:hover {
    text-decoration: none;
  }

  .team-link-hint {
    color: #d8b062;
    font-size: 12px;
    margin-top: 6px;
  }

  .character-card-link {
    text-decoration: none;
    color: inherit;
    cursor: pointer;
  }

  .character-card-link:hover {
    text-decoration: none;
  }

  .character-card-link:hover .character-img {
    transform: translateY(-2px);
    transition: .2s;
  }

  .character-card-link:hover .character-name {
    color: #ffd36d;
  }

  .character-link-hint {
    font-size: 11px;
    color: #b5b5b5;
    margin-top: 4px;
  }

  .character-card:hover .character-link-hint {
    color: #ffd36d;
  }
</style>

<?php

/* ===========================
暫時使用假資料
之後改SQL
=========================== */
$stmt = $pdo->query("
    SELECT COUNT(*) AS players
    FROM tournament_players
    WHERE tournament_id = 1
      AND entry_status IN ('registered','confirmed')
      AND player_status <> 'withdrawn'
");

$playerCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['players'];
$summary = [
  'players' => $playerCount,
  'history' => 1982,
  'wins' => 1023,
  'losses' => 907
];
$charIconMap = [
  19 => 'official/19_R4.png',
  59 => 'phpYAkFjg.png',
  77 => 'phpujSgGp.png',
  78 => 'phpASukIq.png',
  119 => 'phpKw0ZIJ.png',
  120 => 'php0WSPek.png',
  129 => 'php5ImLW2.png',
  139 => 'phpSBU9rP.png',
  148 => 'phpfmhcNP.png',
  158 => 'phpYHw5cG.png',
  169 => 'phpQ6PhGF.png',
  178 => 'php9OPtYI.png',
  180 => 'phpS66QmJ.png',
  185 => 'official/185_L5.png',
  186 => 'official/186_R1.png',
  200 => 'php6JStVC.png',
  208 => 'php3rMyAk.png',
  230 => 'phpe4VSGB.png',
  237 => 'phpUcbvOO.png',
  239 => 'phpV0e73Q.png',
  245 => 'phpKBmMso.png',
  248 => 'phpIKiatZ.png',
  249 => 'phpSu6hoM.png',
  250 => 'phpfdBpk7.png',
  258 => 'phpUJsqj6.png',
  259 => 'phpcNPFPA.png',
  260 => 'php5OE7EJ.png',
  269 => 'phpoE2Crp.png',
  288 => 'phpC7HeDl.png',
  289 => 'phpqNC5c0.png',
  290 => 'phpN9xT8J.png',
  307 => 'phpFEBhcZ.png',
  309 => 'phpOQnbCz.png',
  318 => 'phpKsW5xZ.png',
  320 => 'phpPZFosM.png',
  328 => 'phpK5DoaT.png',
  330 => 'phpNZTTLU.png',
  336 => 'php0hehRD.png',
  347 => 'phpJf1Ko8.png',
  374 => 'phpzDNYBX.png',
  375 => 'phpMqHzfH.png',
  377 => 'phpFz5DRi.png',
  385 => 'phpRVse3m.png',
  387 => 'phpksRZW1.png',
  388 => 'phpZMLUoR.png',
  389 => 'phpjcE7If.png',
  397 => 'phpiaLkN1.png',
  399 => 'phpcyeXM2.png',
  406 => 'php1jkj7d.png',
  428 => 'official/428_R3.png',
  435 => 'phpKmmzXL.png',
  437 => 'official/437_R2.png',
  439 => 'phpPWdxnz.png',
  446 => 'phpNKvKmJ.png',
  466 => 'phpWr9Wt9.png',
  478 => 'phpOpo0QX.png',
  479 => 'phpifoxDC.png',
  480 => 'phpWj29y8.png',
  486 => 'official/486_R1.png',
  509 => '509_R4.png',
  516 => 'phpcpPJ3s.png',
  526 => 'phpKY4eGj.png',
  545 => 'php3QxYXO.png',
  775 => '775_L5.png',
  776 => '776_R1.png'
];

$charNameMap = [
  19 => '艾依查庫',
  59 => '庫勒尼西',
  77 => '阿奇波爾多',
  78 => '阿奇波爾多',
  119 => '艾茵',
  120 => '艾茵',
  129 => '伯恩哈德',
  139 => '弗雷特里西',
  148 => '瑪格莉特',
  158 => '多妮妲',
  169 => '史普拉多',
  178 => '貝琳達',
  180 => '貝琳達',
  185 => '羅索',
  186 => '羅索',
  200 => '艾妲',
  208 => '梅倫',
  230 => '蕾格烈芙',
  237 => '里斯',
  239 => '里斯',
  245 => '米利安',
  248 => '米利安',
  249 => '米利安',
  250 => '米利安',
  258 => '沃肯',
  259 => '沃肯',
  260 => '沃肯',
  269 => '佛羅倫斯',
  288 => '阿修羅',
  289 => '阿修羅',
  290 => '阿修羅',
  307 => '瑪爾瑟斯',
  309 => '瑪爾瑟斯',
  318 => '路德',
  320 => '路德',
  328 => '魯卡',
  330 => '魯卡',
  336 => '史塔夏',
  347 => '沃蘭德',
  374 => '伊芙琳',
  375 => '伊芙琳',
  377 => '伊芙琳',
  385 => '布勞',
  387 => '布勞',
  388 => '布勞',
  389 => '布勞',
  397 => '凱倫貝克',
  399 => '凱倫貝克',
  406 => '音音夢',
  428 => '碧姬媞',
  435 => '庫恩',
  437 => '庫恩',
  439 => '庫恩',
  446 => '夏洛特',
  466 => '露緹亞',
  478 => '威廉',
  479 => '威廉',
  480 => '威廉',
  486 => '梅莉',
  509 => '尤莉卡',
  516 => '林奈烏斯',
  526 => '娜汀',
  545 => '奧蘭(茶)',
  775 => '史特靈',
  776 => '史特靈'
];

$charLevelMap = [
  19 => 'R4',
  59 => 'R4',
  77 => 'R2',
  78 => 'R3',
  119 => 'R4',
  120 => 'R5',
  129 => 'R4',
  139 => 'R4',
  148 => 'R3',
  158 => 'R3',
  169 => 'R4',
  178 => 'R3',
  180 => 'R5',
  185 => 'L5',
  186 => 'R1',
  200 => 'R5',
  208 => 'R3',
  230 => 'R5',
  237 => 'R2',
  239 => 'R4',
  245 => 'L5',
  248 => 'R3',
  249 => 'R4',
  250 => 'R5',
  258 => 'R3',
  259 => 'R4',
  260 => 'R5',
  269 => 'R4',
  288 => 'R3',
  289 => 'R4',
  290 => 'R5',
  307 => 'R2',
  309 => 'R4',
  318 => 'R3',
  320 => 'R5',
  328 => 'R3',
  330 => 'R5',
  336 => 'R1',
  347 => 'R2',
  374 => 'L4',
  375 => 'L5',
  377 => 'R2',
  385 => 'L5',
  387 => 'R2',
  388 => 'R3',
  389 => 'R4',
  397 => 'R2',
  399 => 'R4',
  406 => 'R1',
  428 => 'R3',
  435 => 'L5',
  437 => 'R2',
  439 => 'R4',
  446 => 'R1',
  466 => 'R1',
  478 => 'R3',
  479 => 'R4',
  480 => 'R5',
  486 => 'R1',
  509 => 'R4',
  516 => 'R1',
  526 => 'R1',
  545 => 'L5',
  775 => 'L5',
  776 => 'R1'
];


$popularChars = [
  ['rank' => 1, 'char_id' => 480, 'ico' => 'phpWj29y8.png', 'name' => '威廉', 'used_count' => 184, 'win_rate' => 67.4],
  ['rank' => 2, 'char_id' => 486, 'ico' => 'official/486_R1.png', 'name' => '梅莉', 'used_count' => 109, 'win_rate' => 49.5],
  ['rank' => 3, 'char_id' => 239, 'ico' => 'phpV0e73Q.png', 'name' => '里斯', 'used_count' => 108, 'win_rate' => 61.9],
  ['rank' => 4, 'char_id' => 388, 'ico' => 'phpZMLUoR.png', 'name' => '布勞', 'used_count' => 90, 'win_rate' => 64.0],
  ['rank' => 5, 'char_id' => 259, 'ico' => 'phpcNPFPA.png', 'name' => '沃肯', 'used_count' => 88, 'win_rate' => 62.1],
  ['rank' => 6, 'char_id' => 309, 'ico' => 'phpOQnbCz.png', 'name' => '瑪爾瑟斯', 'used_count' => 86, 'win_rate' => 68.7],
  ['rank' => 7, 'char_id' => 120, 'ico' => 'php0WSPek.png', 'name' => '艾茵', 'used_count' => 84, 'win_rate' => 56.6],
  ['rank' => 8, 'char_id' => 250, 'ico' => 'phpfdBpk7.png', 'name' => '米利安', 'used_count' => 75, 'win_rate' => 68.0],
  ['rank' => 9, 'char_id' => 230, 'ico' => 'phpe4VSGB.png', 'name' => '蕾格烈芙', 'used_count' => 75, 'win_rate' => 62.7],
  ['rank' => 10, 'char_id' => 397, 'ico' => 'phpiaLkN1.png', 'name' => '凱倫貝克', 'used_count' => 72, 'win_rate' => 56.5]
];

$winRateChars = [
  ['rank' => 1, 'char_id' => 439, 'ico' => 'phpPWdxnz.png', 'name' => '庫恩', 'used_count' => 55, 'win_rate' => 74.5],
  ['rank' => 2, 'char_id' => 59, 'ico' => 'phpYAkFjg.png', 'name' => '庫勒尼西', 'used_count' => 42, 'win_rate' => 69.0],
  ['rank' => 3, 'char_id' => 289, 'ico' => 'phpqNC5c0.png', 'name' => '阿修羅', 'used_count' => 49, 'win_rate' => 68.8],
  ['rank' => 4, 'char_id' => 309, 'ico' => 'phpOQnbCz.png', 'name' => '瑪爾瑟斯', 'used_count' => 86, 'win_rate' => 68.7],
  ['rank' => 5, 'char_id' => 250, 'ico' => 'phpfdBpk7.png', 'name' => '米利安', 'used_count' => 75, 'win_rate' => 68.0],
  ['rank' => 6, 'char_id' => 480, 'ico' => 'phpWj29y8.png', 'name' => '威廉', 'used_count' => 184, 'win_rate' => 67.4],
  ['rank' => 7, 'char_id' => 509, 'ico' => '509_R4.png', 'name' => '尤莉卡', 'used_count' => 71, 'win_rate' => 65.7],
  ['rank' => 8, 'char_id' => 208, 'ico' => 'php3rMyAk.png', 'name' => '梅倫', 'used_count' => 54, 'win_rate' => 65.4],
  ['rank' => 9, 'char_id' => 387, 'ico' => 'phpksRZW1.png', 'name' => '布勞', 'used_count' => 54, 'win_rate' => 64.2],
  ['rank' => 10, 'char_id' => 388, 'ico' => 'phpZMLUoR.png', 'name' => '布勞', 'used_count' => 90, 'win_rate' => 64.0]
];

$popularTeams = [
  ['rank' => 1, 'chars' => [486, 180, 397], 'team' => '梅莉 / 貝琳達 / 凱倫貝克', 'used' => 29, 'win_rate' => 62.1],
  ['rank' => 2, 'chars' => [178, 239, 309], 'team' => '貝琳達 / 里斯 / 瑪爾瑟斯', 'used' => 18, 'win_rate' => 77.8],
  ['rank' => 3, 'chars' => [509, 545, 480], 'team' => '尤莉卡 / 奧蘭(茶) / 威廉', 'used' => 17, 'win_rate' => 58.8],
  ['rank' => 4, 'chars' => [446, 387, 439], 'team' => '夏洛特 / 布勞 / 庫恩', 'used' => 17, 'win_rate' => 76.5],
  ['rank' => 5, 'chars' => [385, 237, 230], 'team' => '布勞 / 里斯 / 蕾格烈芙', 'used' => 17, 'win_rate' => 52.9],
  ['rank' => 6, 'chars' => [186, 336, 307], 'team' => '羅索 / 史塔夏 / 瑪爾瑟斯', 'used' => 15, 'win_rate' => 21.4],
  ['rank' => 7, 'chars' => [374, 200, 260], 'team' => '伊芙琳 / 艾妲 / 沃肯', 'used' => 15, 'win_rate' => 28.6],
  ['rank' => 8, 'chars' => [330, 185, 245], 'team' => '魯卡 / 羅索 / 米利安', 'used' => 15, 'win_rate' => 42.9],
  ['rank' => 9, 'chars' => [486, 435, 478], 'team' => '梅莉 / 庫恩 / 威廉', 'used' => 14, 'win_rate' => 57.1],
  ['rank' => 10, 'chars' => [446, 250, 388], 'team' => '夏洛特 / 米利安 / 布勞', 'used' => 14, 'win_rate' => 57.1],
];
$bestTeams = [
  ['rank' => 1, 'chars' => [374, 250, 480], 'team' => '伊芙琳 / 米利安 / 威廉', 'used' => 10, 'win_rate' => 100.0],
  ['rank' => 2, 'chars' => [388, 776, 480], 'team' => '布勞 / 史特靈 / 威廉', 'used' => 13, 'win_rate' => 84.6],
  ['rank' => 3, 'chars' => [178, 239, 309], 'team' => '貝琳達 / 里斯 / 瑪爾瑟斯', 'used' => 18, 'win_rate' => 77.8],
  ['rank' => 4, 'chars' => [446, 387, 439], 'team' => '夏洛特 / 布勞 / 庫恩', 'used' => 17, 'win_rate' => 76.5],
  ['rank' => 5, 'chars' => [259, 269, 200], 'team' => '沃肯 / 佛羅倫斯 / 艾妲', 'used' => 11, 'win_rate' => 72.7],
  ['rank' => 6, 'chars' => [486, 180, 397], 'team' => '梅莉 / 貝琳達 / 凱倫貝克', 'used' => 29, 'win_rate' => 62.1],
  ['rank' => 7, 'chars' => [526, 250, 290], 'team' => '娜汀 / 米利安 / 阿修羅', 'used' => 10, 'win_rate' => 60.0],
  ['rank' => 8, 'chars' => [509, 545, 480], 'team' => '尤莉卡 / 奧蘭(茶) / 威廉', 'used' => 17, 'win_rate' => 58.8],
  ['rank' => 9, 'chars' => [486, 435, 478], 'team' => '梅莉 / 庫恩 / 威廉', 'used' => 14, 'win_rate' => 57.1],
  ['rank' => 10, 'chars' => [446, 250, 388], 'team' => '夏洛特 / 米利安 / 布勞', 'used' => 14, 'win_rate' => 57.1],
];
$popularWeapons = [
  ['rank' => 1, 'weapon_id' => 13, 'name' => '白的彈藥', 'used' => 769, 'wins' => 478, 'win_rate' => 62.9],
  ['rank' => 2, 'weapon_id' => 6, 'name' => '妖魔戒指', 'used' => 351, 'wins' => 195, 'win_rate' => 56.4],
  ['rank' => 3, 'weapon_id' => 188, 'name' => '白色桿麵棍', 'used' => 326, 'wins' => 196, 'win_rate' => 61.1],
  ['rank' => 4, 'weapon_id' => 9, 'name' => '白的槍劍', 'used' => 312, 'wins' => 184, 'win_rate' => 59.5],
  ['rank' => 5, 'weapon_id' => 11, 'name' => '白的短劍', 'used' => 268, 'wins' => 161, 'win_rate' => 60.8],
  ['rank' => 6, 'weapon_id' => 14, 'name' => '黑的彈藥', 'used' => 138, 'wins' => 70, 'win_rate' => 53.8],
  ['rank' => 7, 'weapon_id' => 204, 'name' => '除魔的面具藍', 'used' => 133, 'wins' => 73, 'win_rate' => 55.7],
  ['rank' => 8, 'weapon_id' => 10, 'name' => '黑的槍劍', 'used' => 101, 'wins' => 46, 'win_rate' => 47.4],
  ['rank' => 9, 'weapon_id' => 136, 'name' => '水擊槍', 'used' => 97, 'wins' => 52, 'win_rate' => 54.7],
  ['rank' => 10, 'weapon_id' => 206, 'name' => '除魔的面具白', 'used' => 80, 'wins' => 31, 'win_rate' => 39.2]
];

$popularWeapons = [
  ['rank' => 1, 'weapon_id' => 13, 'name' => '白的彈藥', 'melee' => 0, 'ranged' => 1, 'def_melee' => 0, 'def_ranged' => 1, 'used' => 769, 'wins' => 478, 'win_rate' => 62.9],
  ['rank' => 2, 'weapon_id' => 6, 'name' => '妖魔戒指', 'melee' => -1, 'ranged' => -1, 'def_melee' => 1, 'def_ranged' => 1, 'used' => 351, 'wins' => 195, 'win_rate' => 56.4],
  ['rank' => 3, 'weapon_id' => 188, 'name' => '白色桿麵棍', 'melee' => 3, 'ranged' => 0, 'def_melee' => -1, 'def_ranged' => 0, 'used' => 326, 'wins' => 196, 'win_rate' => 61.1],
  ['rank' => 4, 'weapon_id' => 9, 'name' => '白的槍劍', 'melee' => 1, 'ranged' => 1, 'def_melee' => 0, 'def_ranged' => 0, 'used' => 312, 'wins' => 184, 'win_rate' => 59.5],
  ['rank' => 5, 'weapon_id' => 11, 'name' => '白的短劍', 'melee' => 1, 'ranged' => 0, 'def_melee' => 1, 'def_ranged' => 0, 'used' => 268, 'wins' => 161, 'win_rate' => 60.8],
  ['rank' => 6, 'weapon_id' => 14, 'name' => '黑的彈藥', 'melee' => 0, 'ranged' => 2, 'def_melee' => 0, 'def_ranged' => 2, 'used' => 138, 'wins' => 70, 'win_rate' => 53.8],
  ['rank' => 7, 'weapon_id' => 204, 'name' => '除魔的面具藍', 'melee' => 0, 'ranged' => 1, 'def_melee' => 0, 'def_ranged' => 0, 'used' => 133, 'wins' => 73, 'win_rate' => 55.7],
  ['rank' => 8, 'weapon_id' => 10, 'name' => '黑的槍劍', 'melee' => 2, 'ranged' => 2, 'def_melee' => 0, 'def_ranged' => 0, 'used' => 101, 'wins' => 46, 'win_rate' => 47.4],
  ['rank' => 9, 'weapon_id' => 136, 'name' => '水擊槍', 'melee' => 0, 'ranged' => 4, 'def_melee' => -3, 'def_ranged' => 0, 'used' => 97, 'wins' => 52, 'win_rate' => 54.7],
  ['rank' => 10, 'weapon_id' => 206, 'name' => '除魔的面具白', 'melee' => 1, 'ranged' => 1, 'def_melee' => 1, 'def_ranged' => 1, 'used' => 80, 'wins' => 31, 'win_rate' => 39.2]
];
$charWeaponPairs = [
  ['rank' => 1, 'char_id' => 480, 'weapon_id' => 6, 'weapon_name' => '妖魔戒指', 'used' => 118, 'wins' => 83, 'win_rate' => 71.6],
  ['rank' => 2, 'char_id' => 486, 'weapon_id' => 13, 'weapon_name' => '白的彈藥', 'used' => 53, 'wins' => 31, 'win_rate' => 58.5],
  ['rank' => 3, 'char_id' => 439, 'weapon_id' => 13, 'weapon_name' => '白的彈藥', 'used' => 47, 'wins' => 36, 'win_rate' => 76.6],
  ['rank' => 4, 'char_id' => 388, 'weapon_id' => 13, 'weapon_name' => '白的彈藥', 'used' => 42, 'wins' => 28, 'win_rate' => 68.3],
  ['rank' => 5, 'char_id' => 397, 'weapon_id' => 13, 'weapon_name' => '白的彈藥', 'used' => 40, 'wins' => 23, 'win_rate' => 57.5],
  ['rank' => 6, 'char_id' => 239, 'weapon_id' => 84, 'weapon_name' => '焰型細劍', 'used' => 37, 'wins' => 20, 'win_rate' => 55.6],
  ['rank' => 7, 'char_id' => 169, 'weapon_id' => 65, 'weapon_name' => '獸人的焰爪', 'used' => 34, 'wins' => 16, 'win_rate' => 47.1],
  ['rank' => 8, 'char_id' => 250, 'weapon_id' => 11, 'weapon_name' => '白的短劍', 'used' => 34, 'wins' => 20, 'win_rate' => 58.8],
  ['rank' => 9, 'char_id' => 776, 'weapon_id' => 13, 'weapon_name' => '白的彈藥', 'used' => 33, 'wins' => 22, 'win_rate' => 66.7],
  ['rank' => 10, 'char_id' => 259, 'weapon_id' => 13, 'weapon_name' => '白的彈藥', 'used' => 31, 'wins' => 17, 'win_rate' => 54.8]
];
$playerReports = [
  ['seed' => 1, 'name' => 'ASAPbaby', 'tag' => '穩健型選手', 'team_count' => 10, 'wins' => 6, 'losses' => 4, 'ties' => 0, 'win_rate' => 60.0, 'avg_bp' => 1676, 'max_bp' => 1741, 'top_chars' => [480, 776, 388]],
  ['seed' => 3, 'name' => '秋神玥亞', 'tag' => '中堅實戰派', 'team_count' => 11, 'wins' => 6, 'losses' => 5, 'ties' => 0, 'win_rate' => 54.5, 'avg_bp' => 1584, 'max_bp' => 1618, 'top_chars' => [249, 129, 318]],
  ['seed' => 4, 'name' => '打牌靠賽輕鬆', 'tag' => '少場高勝率黑馬', 'team_count' => 3, 'wins' => 3, 'losses' => 0, 'ties' => 0, 'win_rate' => 100.0, 'avg_bp' => 1570, 'max_bp' => 1584, 'top_chars' => [389, 399, 259]],
  ['seed' => 6, 'name' => 'kevin61395', 'tag' => '低調參戰者', 'team_count' => 4, 'wins' => 2, 'losses' => 2, 'ties' => 0, 'win_rate' => 50.0, 'avg_bp' => 1485, 'max_bp' => 1500, 'top_chars' => [78, 180, 119]],
  ['seed' => 7, 'name' => '羅馬', 'tag' => '資料稀少型選手', 'team_count' => 1, 'wins' => 1, 'losses' => 0, 'ties' => 0, 'win_rate' => 100.0, 'avg_bp' => 1484, 'max_bp' => 1484, 'top_chars' => [19, 437, 328]],
  ['seed' => 8, 'name' => '嗷嗷龜', 'tag' => '高 BP 潛力股', 'team_count' => 2, 'wins' => 2, 'losses' => 0, 'ties' => 0, 'win_rate' => 100.0, 'avg_bp' => 1705, 'max_bp' => 1771, 'top_chars' => [309, 239, 249]],
  ['seed' => 9, 'name' => '芭樂的傳奇', 'tag' => '本屆最大熱門之一', 'team_count' => 57, 'wins' => 35, 'losses' => 22, 'ties' => 0, 'win_rate' => 61.4, 'avg_bp' => 1663, 'max_bp' => 1815, 'top_chars' => [239, 309, 178]],
  ['seed' => 14, 'name' => '咕嚕咕嚕', 'tag' => '高勝率觀察名單', 'team_count' => 2, 'wins' => 2, 'losses' => 0, 'ties' => 0, 'win_rate' => 100.0, 'avg_bp' => 1573, 'max_bp' => 1630, 'top_chars' => [776, 437, 309]],
  ['seed' => 17, 'name' => '奇怪的叔叔', 'tag' => '反制型潛力選手', 'team_count' => 4, 'wins' => 2, 'losses' => 2, 'ties' => 0, 'win_rate' => 50.0, 'avg_bp' => 1475, 'max_bp' => 1489, 'top_chars' => [377, 347, 248]]
];
$playerReports = array_values(array_filter($playerReports, function ($player) {
  return $player['team_count'] > 0;
}));
$weaponIconMap = [
  13 => 'phpp90mdZ.png',
  6 => 'phpN3ZEsK.png',
  188 => '188.jpg',
  9 => 'phpWzikOi.png',
  11 => 'phpFtIzyb.png',
  14 => 'phpHq1Mug.png',
  204 => 'weapon_204.png',
  10 => 'phpNPnE8C.png',
  136 => '136.jpg',
  206 => 'weapon_206.png',
  65 => '65.jpg',
  84 => '84.jpg'
];
?>

<div class="content-wrapper">

  <section class="content ul-container-nopad">

    <div class="pre-report">

      <div class="pre-hero">

        <div class="hero-sub">

          ULGG CUP #1

        </div>

        <div class="hero-title">
          第一屆 ULGG CUP 戰前分析
          <span>Tournament Preview</span>
        </div>

        <div class="hero-desc">

          COST 74 Meta Analysis

        </div>

        <div class="hero-version">

          Data Source：ULGG Arena ｜ Version 1.0

        </div>

      </div>

      <div class="summary-grid">

        <div class="summary-card">

          <div class="summary-value">

            <?= number_format($summary['players']) ?>

          </div>

          <div class="summary-title">

            Players

          </div>

        </div>

        <div class="summary-card">

          <div class="summary-value">

            <?= number_format($summary['history']) ?>

          </div>

          <div class="summary-title">

            Historical Teams

          </div>

        </div>

        <div class="summary-card">

          <div class="summary-value">

            <?= number_format($summary['wins']) ?>

          </div>

          <div class="summary-title">

            Wins

          </div>

        </div>

        <div class="summary-card">

          <div class="summary-value">

            <?= number_format($summary['losses']) ?>

          </div>

          <div class="summary-title">

            Losses

          </div>

        </div>

      </div>

      <div class="report-section">
        <div class="section-title">📰 Tournament Report</div>
        <div class="report-card">
          <h3>ULGG CUP 第一屆</h3>


          <p>
            本次分析以符合本屆賽制（角色 + 武器 COST = 74、事件卡不計入 COST）的歷史資料為基礎，
            共統計 1,982 組隊伍、1,930 場有效勝負資料，
            希望讓選手與觀眾在開賽前，快速掌握目前的 Meta 環境。
          </p>

        </div>
        <div class="report-card">
          <h3>使用率最高的角色</h3>

          <p>
            從目前 Meta 來看，環境已逐漸形成固定核心。
            <strong>R5 威廉</strong>仍是目前使用率最高的角色，而
            <strong>R4 瑪爾瑟斯</strong>、<strong>R5 米利安</strong>等角色則在高使用率下仍維持接近七成勝率，
            顯示目前環境不只是「熱門」，更開始出現穩定且成熟的核心體系。
          </p>
          <div class="meta-grid">

            <div class="meta-box">

              <div class="meta-box-title">

                🔥 熱門角色 TOP10

              </div>
              <div class="character-link-hint">
                點擊查看角色分析 →
              </div>

              <div class="character-grid">
                <?php foreach ($popularChars as $row) { ?>
                  <a
                    class="character-card character-card-link"
                    href="/pages/analysis_character_card.php?char_id=<?= $row['char_id'] ?>&char=<?= urlencode(($row['level'] ?? '') . ' ' . $row['name']) ?>"
                    target="_blank">
                    <div class="rank-badge">#<?= $row['rank'] ?></div>

                    <img src="<?= IMG_BASE . $row['ico'] ?>"
                      class="character-img"
                      alt="<?= $row['name'] ?>">

                    <div class="character-name">
                      <?= $charLevelMap[$row['char_id']] . ' ' . htmlspecialchars($row['name']) ?>
                    </div>

                    <div class="character-use"><?= $row['used_count'] ?> 場</div>

                    <div class="character-win"><?= $row['win_rate'] ?>%</div>
                  </a>
                <?php } ?>
              </div>

            </div>

            <div class="meta-box">

              <div class="meta-box-title">

                ⭐ 高勝率角色 TOP10

              </div>
              <div class="character-link-hint">
                點擊查看角色分析 →
              </div>

              <div class="character-grid">
                <?php foreach ($winRateChars as $row) { ?>
                  <a
                    class="character-card character-card-link"
                    href="/pages/analysis_character_card.php?char_id=<?= $row['char_id'] ?>&char=<?= urlencode(($row['level'] ?? '') . ' ' . $row['name']) ?>"
                    target="_blank">
                    <div class="rank-badge">#<?= $row['rank'] ?></div>

                    <img src="<?= IMG_BASE . $row['ico'] ?>"
                      class="character-img"
                      loading="lazy"
                      alt="<?= $row['name'] ?>">

                    <div class="character-name">
                      <?= $charLevelMap[$row['char_id']] . ' ' . htmlspecialchars($row['name']) ?>
                    </div>

                    <div class="character-use"><?= $row['used_count'] ?> 場</div>

                    <div class="character-win"><?= $row['win_rate'] ?>%</div>
                  </a>
                <?php } ?>
              </div>

            </div>

          </div>
        </div>
        <div class="report-card">
          <h3>隊伍配置</h3>
          <p>
            在隊伍配置方面，三人組合也開始趨於固定，
            <strong>R1 梅莉／R5 貝琳達／R2 凱倫貝克</strong>是目前出場次數最高的隊伍；
            而
            <strong>L4 伊芙琳 / R5 米利安 / R5 威廉</strong>、
            <strong>R3 布勞／R1 史特靈／R5 威廉</strong>
            則繳出更亮眼的歷史勝率。
          </p>

          <div class="team-board">
            <div class="team-board-col">
              <div class="team-board-title">🔥 熱門三人組 TOP10</div>
              <div class="team-link-hint">點擊查看三人組分析 →</div>
              <div class="team-grid team-grid-single">
                <?php foreach ($popularTeams as $team) { ?>
                  <a href="/pages/team_analysis.php?id1=<?= $team['chars'][0] ?>&id2=<?= $team['chars'][1] ?>&id3=<?= $team['chars'][2] ?>"
                    class="team-card team-card-link">
                    <div class="team-rank">#<?= $team['rank'] ?></div>
                    <div class="team-chars">
                      <?php foreach ($team['chars'] as $charId) { ?>
                        <img src="<?= IMG_BASE . $charIconMap[$charId] ?>" class="team-char-img" loading="lazy">
                      <?php } ?>
                    </div>
                    <div class="team-info">
                      <div class="team-title">
                        <?php
                        $teamNames = [];
                        foreach ($team['chars'] as $charId) {
                          $teamNames[] = $charLevelMap[$charId] . ' ' . $charNameMap[$charId];
                        }
                        echo implode(' / ', $teamNames);
                        ?>
                      </div>

                      <div class="team-meta">
                        <div class="team-meta-item">
                          <span class="team-meta-label">使用</span>
                          <span class="team-meta-value"><?= $team['used'] ?>場</span>
                        </div>
                        <div class="team-meta-item">
                          <span class="team-meta-label">勝率</span>
                          <span class="team-meta-value win"><?= $team['win_rate'] ?>%</span>
                        </div>
                      </div>

                    </div>
                  </a>
                <?php } ?>
              </div>
            </div>

            <div class="team-board-col">
              <div class="team-board-title">⭐ 高勝率三人組 TOP10</div>
              <div class="team-link-hint">點擊查看三人組分析 →</div>
              <div class="team-grid team-grid-single">
                <?php foreach ($bestTeams as $team) { ?>
                  <a href="/pages/team_analysis.php?id1=<?= $team['chars'][0] ?>&id2=<?= $team['chars'][1] ?>&id3=<?= $team['chars'][2] ?>"
                    class="team-card team-card-link">
                    <div class="team-rank">#<?= $team['rank'] ?></div>
                    <div class="team-chars">
                      <?php foreach ($team['chars'] as $charId) { ?>
                        <img src="<?= IMG_BASE . $charIconMap[$charId] ?>" class="team-char-img" loading="lazy">
                      <?php } ?>
                    </div>
                    <div class="team-info">
                      <div class="team-title">
                        <?php
                        $title = [];
                        foreach ($team['chars'] as $charId) {
                          $title[] = $charLevelMap[$charId] . ' ' . $charNameMap[$charId];
                        }
                        echo implode(' / ', $title);
                        ?>
                      </div>
                      <div class="team-meta">
                        <div class="team-meta-item">
                          <span class="team-meta-label">使用</span>
                          <span class="team-meta-value"><?= $team['used'] ?>場</span>
                        </div>
                        <div class="team-meta-item">
                          <span class="team-meta-label">勝率</span>
                          <span class="team-meta-value win"><?= $team['win_rate'] ?>%</span>
                        </div>
                      </div>

                    </div>
                  </a>
                <?php } ?>
              </div>
            </div>
          </div>
        </div>

        <div class="report-card">
          <h3>武器方面</h3>
          <p>
            武器方面，<strong>白的彈藥</strong>仍是目前最主流的選擇，使用次數超過七百場，幾乎成為現今 Meta 的標準配置。除此之外，<strong>妖魔戒指</strong>、<strong>白色桿麵棍</strong>與<strong>白的槍劍</strong>同樣維持極高出場率。
            從角色搭配來看，不少角色已逐漸形成固定武器組合，例如 <strong>R5 威廉 × 妖魔戒指</strong>、<strong>R4 庫恩 × 白的彈藥</strong>、<strong>R3 布勞 × 白的彈藥</strong> 等，都展現出相當穩定的使用率與勝率，也代表武器選擇開始成為隊伍構築的重要一環。
          </p>

          <div class="weapon-board">
            <div class="weapon-board-col">
              <div class="weapon-board-title">🗡 熱門武器 TOP10</div>
              <div class="weapon-list">
                <?php foreach ($popularWeapons as $weapon) { ?>
                  <div class="weapon-card">
                    <div class="weapon-rank">#<?= $weapon['rank'] ?></div>

                    <?php if (!empty($weaponIconMap[$weapon['weapon_id']])) { ?>
                      <img src="<?= IMG_BASE . $weaponIconMap[$weapon['weapon_id']] ?>" class="weapon-icon" loading="lazy">
                    <?php } else { ?>
                      <div class="weapon-icon weapon-icon-empty">?</div>
                    <?php } ?>

                    <div class="weapon-main">
                      <div class="weapon-name"><?= $weapon['name'] ?></div>

                      <div class="weapon-stats">
                        <?php if ($weapon['melee'] == $weapon['ranged'] && $weapon['def_melee'] == $weapon['def_ranged']) { ?>
                          <?php if ($weapon['melee'] != 0) { ?><span class="stat-all">ATK <?= $weapon['melee'] > 0 ? '+' : '' ?><?= $weapon['melee'] ?></span><?php } ?>
                          <?php if ($weapon['def_melee'] != 0) { ?><span class="stat-all">DEF <?= $weapon['def_melee'] > 0 ? '+' : '' ?><?= $weapon['def_melee'] ?></span><?php } ?>
                        <?php } else { ?>
                          <?php if ($weapon['melee'] != 0) { ?><span class="stat-melee">ATK <?= $weapon['melee'] > 0 ? '+' : '' ?><?= $weapon['melee'] ?></span><?php } ?>
                          <?php if ($weapon['def_melee'] != 0) { ?><span class="stat-melee">DEF <?= $weapon['def_melee'] > 0 ? '+' : '' ?><?= $weapon['def_melee'] ?></span><?php } ?>
                          <?php if ($weapon['ranged'] != 0) { ?><span class="stat-ranged">ATK <?= $weapon['ranged'] > 0 ? '+' : '' ?><?= $weapon['ranged'] ?></span><?php } ?>
                          <?php if ($weapon['def_ranged'] != 0) { ?><span class="stat-ranged">DEF <?= $weapon['def_ranged'] > 0 ? '+' : '' ?><?= $weapon['def_ranged'] ?></span><?php } ?>
                        <?php } ?>
                      </div>
                    </div>

                    <div class="weapon-meta">
                      <span>使用 <b><?= $weapon['used'] ?>場</b></span>
                      <span>勝率 <b class="win"><?= $weapon['win_rate'] ?>%</b></span>
                    </div>
                  </div>
                <?php } ?>
              </div>
            </div>

            <div class="weapon-board-col">
              <div class="weapon-board-title">⚔ 角色 × 武器配置 TOP10</div>
              <div class="weapon-list">
                <?php foreach ($charWeaponPairs   as $pair) { ?>
                  <div class="weapon-card">
                    <div class="weapon-rank">#<?= $pair['rank'] ?></div>
                    <div class="pair-icons">
                      <img src="<?= IMG_BASE . $charIconMap[$pair['char_id']] ?>" class="pair-char-img" loading="lazy">

                      <?php if (!empty($weaponIconMap[$pair['weapon_id']])) { ?>
                        <img src="<?= IMG_BASE . $weaponIconMap[$pair['weapon_id']] ?>" class="pair-weapon-img" loading="lazy">
                      <?php } else { ?>
                        <div class="pair-weapon-img weapon-icon-empty">?</div>
                      <?php } ?>
                    </div>
                    <div class="weapon-name">
                      <?= $charLevelMap[$pair['char_id']] . ' ' . $charNameMap[$pair['char_id']] ?> × <?= $pair['weapon_name'] ?> </div>
                    <div class="weapon-meta">
                      <span>使用 <b><?= $pair['used'] ?>場</b></span>
                      <span>勝率 <b class="win"><?= $pair['win_rate'] ?>%</b></span>
                    </div>
                  </div>
                <?php } ?>
              </div>
            </div>
          </div>
        </div>
        <div class="report-card">
          <h3>玩家專欄</h3>
          <p>
            從歷史 COST 74 對戰紀錄來看，各位選手的比賽經驗差距相當明顯。
            有人早已累積數十場以上實戰，不斷在天梯磨練自己的牌組；也有不少選手屬於首次踏入這個環境，
            真正的實力仍充滿未知數。

            本專欄將帶大家快速認識每位參賽者的歷史數據，包括出場場數、勝率與最高 BP，
            一起看看誰是本屆最值得關注的熱門選手，又有哪些黑馬可能在正式比賽一鳴驚人。
          </p>

          <div class="player-board">
            <?php foreach ($playerReports as $player) { ?>
              <div class="player-card">
                <div class="player-head">
                  <div class="player-seed">#<?= $player['seed'] ?></div>
                  <div>
                    <div class="player-name"><?= $player['name'] ?></div>
                    <div class="player-tag"><?= $player['tag'] ?></div>
                  </div>
                </div>

                <div class="player-caster-line">
                  <?php if ($player['team_count'] >= 30) { ?>
                    74COST歷史資料相當充足，是本屆賽前最容易被研究的選手之一。
                  <?php } elseif ($player['win_rate'] >= 80) { ?>
                    74COST樣本數雖然不多，但目前勝率表現相當亮眼，具備黑馬潛力。
                  <?php } elseif ($player['max_bp'] >= 1700) { ?>
                    最高 BP 表現突出，若能穩定發揮，有機會成為賽事焦點。
                  <?php } else { ?>
                    74COST歷史資料不算多，正式比賽中的牌組選擇仍保有相當神秘感。
                  <?php } ?>
                </div>

                <div class="player-stats">
                  <div><span>出場</span><b><?= $player['team_count'] ?></b></div>
                  <div><span>勝率</span><b class="win"><?= $player['win_rate'] ?>%</b></div>
                  <div><span>MAX BP</span><b><?= $player['max_bp'] ?></b></div>
                </div>

                <!-- <div class="team-chars player-team-chars">
                  <?php foreach ($player['top_chars'] as $charId) { ?>
                    <img src="<?= IMG_BASE . $charIconMap[$charId] ?>" class="team-char-img" loading="lazy"
                      title="<?= $charLevelMap[$charId] . ' ' . $charNameMap[$charId] ?>">
                  <?php } ?>
                </div> -->
              </div>
            <?php } ?>
          </div>
        </div>
        <div class="report-card">
          <h3>ULGG Arena 即時統計</h3>


          <p>
            本頁所有排行榜皆由 ULGG Arena 即時統計，
            包括熱門角色、高勝率角色、熱門隊伍、熱門武器與 Ban 建議等內容，
            後續將隨資料庫持續更新，希望能提供選手、主播與觀眾最即時的賽前情報。
          </p>

        </div>

      </div>



    </div>

  </section>

</div>

<script>

</script>

<?php

$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
