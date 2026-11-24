# UL.GG｜2026 年度回顧 M3-A Linux CI Gate

日期：2026-11-24（Asia/Taipei）
依賴：M2 本機 Commit 81c1259cdba2cc99524921fbf3fb57a1d0d43972，M1 28/28、M2 22/22、合成 localhost HTTP + Chromium 11/11。
目的：只核准獨立審查分支與 Draft PR 觸發 GitHub-hosted Ubuntu Linux CI，**不合併、不部署、不連正式 DB、不操作真實 Unlight 遊戲**。

## 分支與安全範圍

- Base: server-sync (2026-11-24 HEAD 3b1c018d2b8c9fd465a367ec3002f50076c2c786).
- Head: review/checkpoint2026-m3a-linux-ci（僅允許此一審查分支推送）。
- 工作樹：D:\ULGG\Checkpoint2026\checkpoint2026-m3a，獨立本機 branch feature/checkpoint2026-m3a-linux-ci。
- 所有變更限於 .github/workflows/checkpoint2026-m3a-linux-synthetic.yml、tests/checkpoint2026 與 docs/checkpoint2026 或 M2 模組必要的合成環境相容修正。
- 工作流程只在 server-sync 的 Pull Request、同 repository 且符合指定 head branch 時啟動；Token 權限 contents: read，persist-credentials=false，無 push/deploy/job secrets。
- Actions 完整 Commit 固定：actions/checkout v5.0.0、shivammathur/setup-php v2.40.0（已修補 2026 安全公告）、actions/setup-python v5.6.0、actions/upload-artifact v4.6.2。
- Playwright 1.56.0，網路安裝只供 CI 測試工具；應用測試只連結 127.0.0.1，無 OAuth、遊戲、正式 DB 連線。

## M3-A acceptance gates

| Gate | 內容 | 放行條件 |
|---|---|---|
| A0 | 本機 M2 基準、獨立 worktree、Git Diff 範圍、無真實 Steam/Token/DB 密碼 | PASS |
| A1 | 工作流程 YAML 表達式及安全權限 | PASS |
| A2 | GitHub Linux PHP 8.0 Runtime + PDO SQLite、PHP lint、M1 28 + M2 22 | 50/50 PASS |
| A3 | GitHub Linux PHP 8.3 Runtime + PDO SQLite、PHP lint、M1 28 + M2 22 | 50/50 PASS |
| A4 | GitHub Linux PHP 8.3、本機 PHP server、HTTP IDOR/Steam A-B、CUP published/verified、Snapshot、Chromium 360/1280px、無水平 overflow、reduced-motion、stop cleanup | 11/11 PASS |
| A5 | Actions 合成資料證據 artifacts、完全沒有正式站異動、PR 保留 Draft | PASS |
| A6 | 提供可回滾 Git binary diff Patch 及 SHA-256、正反向 apply --check | PASS |

## 例外與未放行項目

即使 M3-A 全綠：
- 真正 Steam OAuth、來源水位的 authority、年度完整度、個人 CUP/ Raid 映射、來源不可變快照、PHP 正式效能均尚未驗證。
- 個人 CUP／Raid 必須 UNKNOWN；不得以缺值 0 代替。
- 發布前必須 M3-B 獨立 DB 唯讀身份及合成 staging 實證、性能測試、安全審查與另案上線核准。
- 不得合併任何 PR，不得修改正式 DB、Watcher、Collector、排程或 master，不得登入／操作遊戲。


## GitHub Linux 首輪失敗處理（不代表已通過）

- Draft PR #17 首輪 GitHub Actions：Run 45324859691。
- PHP 8.3：50/50 PASS。
- PHP 8.0：M1 28/28 PASS；M2 19/22 PASS。3 項 Reader Case 在 Linux PHP 8.0 的 PDO SQLite 把數值型 match_id 取回成字串，例如 "1"，而 PHP 8.3 回傳整數 1。此差異觸發了嚴格型別 TDD，非勝敗計算或 Steam 身份保護失敗。
- 修正範圍只在 PvPFactReader.php：將正確且可驗證的正整數 ID 標準化為 int，異常/不可解析值不強制轉型、留給下游拒絕。M2 測試仍維持嚴格型別預期。
- 這項跨版本修正需在下一次 GitHub PHP 8.0/8.3 和 Chromium Linux 實際 CI 重新驗證；絕不以本機 PHP 8.3 成功宣稱 Linux PHP 8.0 Gate 通過。
