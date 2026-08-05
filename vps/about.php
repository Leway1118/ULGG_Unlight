<?php include 'head.php'; ?>
<!DOCTYPE html>
<html lang="zh-TW">
<body>

<div class="container" style="margin-top: 30px; margin-bottom: 50px;">

    <!-- Title -->
    <div class="text-center">
        <h1><i class="fas fa-info-circle"></i> 關於 UL.GG</h1>
        <p class="text-muted">Unlight Game Guide — 玩家開發的非營利統計平台</p>
        <hr>
    </div>

    <!-- Language Tabs -->
    <ul class="nav nav-tabs" role="tablist">
        <li class="active">
            <a href="#tab-tw" role="tab" data-toggle="tab">
                <i class="fa fa-flag"></i> 繁體中文
            </a>
        </li>
        <li>
            <a href="#tab-jp" role="tab" data-toggle="tab">
                <i class="fa fa-flag-o"></i> 日本語
            </a>
        </li>
        <li>
            <a href="#tab-en" role="tab" data-toggle="tab">
                <i class="fa fa-globe"></i> English
            </a>
        </li>
    </ul>

    <div class="tab-content" style="padding: 20px; border: 1px solid #ddd; border-top: none; background: #fff;">

        <!-- ◆◆◆ 中文 ◆◆◆ -->
        <div class="tab-pane fade in active" id="tab-tw">

            <h3><i class="fa fa-book"></i> 關於 UL.GG（Unlight Game Guide）</h3>
            <p>
                UL.GG 是由玩家自主開發、非營利的 Unlight 對戰統計平台，致力於提供：
            </p>

            <ul>
                <li>對戰紀錄統計與分析</li>
                <li>BP/QP 排名</li>
                <li>角色使用率與組合勝率</li>
                <li>角色資料、事件卡、武器資訊</li>
                <li>歷史資料查詢與數據可視化</li>
            </ul>

            <h4><i class="fa fa-exclamation-circle text-danger"></i> 免責聲明</h4>
            <div class="alert alert-warning">
                本網站為非官方粉絲製作網站。<br>
                與 Unlight 官方營運 / 開發團隊沒有任何合作、隸屬或授權關係。<br>
                所有資料均以非侵入方式取得，不對官方伺服器造成負荷或干擾。
            </div>

            <h4><i class="fa fa-shield"></i> 版權聲明</h4>
            <p>
                Unlight 之角色、美術、故事背景等內容，其著作權皆屬原版權方所有。<br>
                UL.GG 僅提供統計整理與資料索引，不主張任何著作權或修改原作品。
            </p>

            <h4><i class="fa fa-compass"></i> UL.GG 的定位</h4>
            <blockquote>
                UL.GG 是由玩家為玩家而生的資料站，目標是保存 Unlight 的對戰歷史，
                並提供最便利的數據工具，協助玩家探索更多策略與編成。
            </blockquote>
        </div>

        <!-- ◆◆◆ 日文 ◆◆◆ -->
        <div class="tab-pane fade" id="tab-jp">

            <h3><i class="fa fa-book"></i> UL.GG（Unlight Game Guide）について</h3>
            <p>
                UL.GG はプレイヤー自主開発の非営利 Unlight データ解析サイトです。
                以下のサービスを提供しています：
            </p>

            <ul>
                <li>対戦履歴の統計と分析</li>
                <li>BP/QP ランキング</li>
                <li>使用率と組み合わせ勝率</li>
                <li>キャラクター情報・イベントカード・武器データ</li>
                <li>履歴データの検索</li>
            </ul>

            <h4><i class="fa fa-exclamation-circle text-danger"></i> 免責事項</h4>
            <div class="alert alert-warning">
                本サイトは非公式ファンサイトです。<br>
                Unlight 運営・開発チームとは一切関係ありません。<br>
                データは非侵入的な方法で取得しており、ゲームサーバーに負荷を与えません。
            </div>

            <h4><i class="fa fa-shield"></i> 著作権について</h4>
            <p>
                Unlight に関する全ての著作権は原著作権者に帰属します。<br>
                UL.GG は統計と整理のみを行い、著作権を主張しません。
            </p>

            <h4><i class="fa fa-compass"></i> プロジェクトの目的</h4>
            <blockquote>
                UL.GG はプレイヤーのためのデータ保存と分析ツールであり、
                より深い戦略分析と編成研究を可能にします。
            </blockquote>
        </div>

        <!-- ◆◆◆ 英文 ◆◆◆ -->
        <div class="tab-pane fade" id="tab-en">

            <h3><i class="fa fa-book"></i> About UL.GG (Unlight Game Guide)</h3>
            <p>
                UL.GG is a non-profit, community-driven analytics platform for Unlight.
                The platform provides:
            </p>

            <ul>
                <li>Battle history statistics & analysis</li>
                <li>BP/QP leaderboards</li>
                <li>Usage rate & team win rate analytics</li>
                <li>Character, event card, and weapon data</li>
                <li>Historical data search and visualization</li>
            </ul>

            <h4><i class="fa fa-exclamation-circle text-danger"></i> Disclaimer</h4>
            <div class="alert alert-warning">
                UL.GG is an unofficial fan-made website.<br>
                It is not affiliated with the official Unlight developer or operator.<br>
                All data is collected via non-intrusive methods without server interference.
            </div>

            <h4><i class="fa fa-shield"></i> Copyright Notice</h4>
            <p>
                All Unlight characters, artwork, and related assets belong to their respective copyright owners.<br>
                UL.GG claims no ownership and provides data analysis only.
            </p>

            <h4><i class="fa fa-compass"></i> Project Mission</h4>
            <blockquote>
                UL.GG aims to preserve Unlight's matches and provide a powerful data toolset
                for strategic exploration and team-building analysis.
            </blockquote>
        </div>

    </div>
</div>
<!-- 必須：Bootstrap Tab 功能 JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
</body>
</html>
