# Client Authentication Contract Review

日期：2026-07-29

審查對象：`docs/client_authentication_contract_v0.1.md`

結論：契約可進入團隊決策審查；在localhost handoff、Identity Integrity Migration與Official Evidence門檻定案前，不應開始Auth API實作。

## 1. 本次探索過的檔案

### ULGG Authentication與Session

- `config.php`：PHP Session Cookie、24小時GC、資料庫連線、Remember自動登入。
- `layout/base.php`：Header登入入口、Remember Me、登入狀態及 `ack`／`permission` 顯示邏輯。
- `pages/login.php`：Steam登入頁與登入後帳號查找。
- `api/auth/steam_start.php`：OpenID起始與redirect Session。
- `api/auth/steam_callback.php`：Steam簽章、nonce、User查找／建立、Session與Remember Token。
- `pages/logout.php`：本裝置登出。
- `pages/logout_all.php`：Remember Token全撤銷與本裝置Session銷毀。
- `pages/admin/_admin_gate.php`：`permission >= 2`管理權限。
- `pages/admin/ajax/user_block.php`：`black_list` 0／1／2操作語意。
- `pages/admin/user_manage.php`：帳號狀態顯示。
- `pages/profile.php`：Steam登入Gate、Profile權限及black list呈現。
- `pages/fight_detail.php`：分散式登入／permission檢查範例。

### Schema與資料

- 現有 `game_user` schema、column與index metadata。
- `user_remember_tokens`與 `steam_openid_nonce`既有用途。
- 只讀Steam ID duplicate、NULL、blank、format、ack與black list統計。

### ULR Companion基準

- `README.md`
- `docs/canonical-json.md`
- `docs/open-questions.md`
- `packages/api-contract/src/index.ts`
- `packages/rule-schema/`
- `packages/cost-engine/src/index.ts`
- `packages/cdp-adapter/src/index.ts`

ULR Companion基準仍是先前Integration Review使用的commit `992a8f55161f29d2f81b3a94dbece072c47b7153`。其WP-01可用，Room Report、Match Import、Cost Engine與CDP Adapter仍未完整實作。

## 2. 已確認的 Existing Findings

1. Steam Login入口是Header或 `pages/login.php`，最終進入Steam OpenID callback。
2. Callback驗證Steam簽章、17碼Steam ID、nonce時間與nonce replay。
3. Session同時保存 `game_user.id` 與 `game_user.steamID`。
4. Match Participant應以 `game_user.id` 作FK。
5. `permission`目前由 `ack`複製。
6. `ack >= 1`在現有UI代表已驗證UL User。
7. PHP Web Session約24小時。
8. Remember Token約30天，且以原值保存。
9. Remember Token、PHP Session ID、Steam Cookie都不能作Client Token。
10. 現有421個User；143個有效Steam ID；278個NULL。
11. 143個Steam ID皆唯一、17碼數字；沒有空白或格式錯誤。
12. `steamID`沒有Index或Unique Constraint。
13. 143名Steam User中83名 `ack >= 1`，60名 `ack = 0`。
14. `black_list`為NULL或0，沒有1／2；NULL需概念上視為0。
15. 現有權限檢查分散，沒有統一Middleware。
16. 「登出所有裝置」不會立即撤銷其他現存PHP Session。
17. `remember_flag.php`缺失、Remember登入缺 `apply`、black list顯示語意不一致都是既有風險，本次未修正。

## 3. 與既有規格的衝突

### 3.1 Phase 1 Migration Scope與Auth Entity

Migration Scope v0.1把Client Evidence、Verification、Arena Mapping排到Phase 2。`client_auth_tokens`、`client_sessions`與audit log同樣依賴Match Participant與Client contract，因此：

- Phase 1只建立Contract。
- 不把Auth表偷偷加入Ruleset／Lobby migration。
- Auth實體應在Phase 2第一個子階段建立。

### 3.2 game_user保持不變與Steam Unique

既有Freeze要求核心表保持不變，但身份安全需要Steam ID唯一保證。兩者衝突。

推薦以獨立Identity Integrity Migration處理，單獨審查：

- 資料前置稽核。
- `steamID` Index／Unique。
- Callback併發INSERT conflict處理。

不能混入Ruleset或Match migration。

### 3.3 ULR API draft與新Authentication

現有ULR `RoomRuleReportRequest`使用 `reporterHash`，Match Import直接帶 `matchId`、`roomId`與結果，尚未描述Client Bearer Session。

新契約要求：

- 身份由Client Session反查。
- Client不自報可信User／Steam身份。
- Evidence endpoint不以 `reporterHash` 作唯一授權。

因此ULR `api-contract`後續需要新版本，但本次不修改外部Repository。

### 3.4 API與Database casing

Database Naming Freeze採snake_case；ULR TypeScript contract採camelCase。主契約推薦：

```text
Database：snake_case
API JSON：camelCase
```

必須建立明確mapper，不能把DB row直接json_encode成公開contract。

### 3.5 Session與Match lifecycle

Match `FINISHED`代表Server Verification完成；Client finalize只代表「該Client聲稱Evidence已送完」。因此：

- Client Session可CLOSED。
- Match仍可維持IN_GAME／等待Verification。
- Finalize endpoint不能直接修改Match為FINISHED。

### 3.6 Local Tracker API邊界

現有Tracker API固定在 `127.0.0.1:8765`，屬於本機元件。Public ULGG Client Auth／Evidence API必須走ULGG HTTPS Server，不能把8765公開到LAN或Internet，也不能讓公開API反向控制本機Tracker。

## 4. 安全風險

| 風險 | 等級 | 目前契約控制 | 尚需確認 |
|---|---|---|---|
| Browser到localhost Token被惡意程序攔截 | High | 單次Token、challenge建議、不渲染DOM | port／challenge handshake |
| `steamID`無Unique造成身份歧義 | High | 已完成資料稽核 | Identity Integrity Migration批准 |
| Client Evidence偽造 | High | Evidence不等於Verification | Official quorum |
| Bearer Token進Log／Crash dump | High | secret-once、hash-only、遮罩 | Client logging實作 |
| 同Participant雙Session | Medium | 單一ACTIVE預設 | 雙開／裁判例外 |
| Remember Token原值保存 | Medium | 明確禁止重用 | 既有網站未來修正 |
| Logout All無法即時撤銷Web Session | Medium | Client Session獨立revocation | Session管理UI |
| black_list語意不一致 | Medium | v0.1 fail closed | 產品政策 |
| Client本機access token保存 | Medium | 建議OS credential store | Windows實作 |
| Hidden hand／restricted packet外洩 | High | safe parser boundary | Evidence schema與測試 |
| CORS被誤當安全機制 | High | 契約明確否定 | localhost implementation review |
| Development scope誤進Official | High | scope與eligibility分離 | Server authorization tests |

## 5. Blocker

### Blocker 1：Match／Participant實體尚未落地

Client Token必須綁定 `match_id`與 `participant_id`。在Ruleset Registry與Custom Match Lobby核心migration完成前，Auth migration不能正確建立FK。

### Blocker 2：localhost handoff未定

Token Issue由Browser Web Session授權，但Python Client需要取得Secret。固定port、challenge、custom URI或手動交接尚未共同決定。

### Blocker 3：Steam Identity唯一保證未批准

目前資料乾淨，但DB無Unique。Official Client Auth若直接上線，身份一對一只靠Application假設。

### Blocker 4：Eligibility policy尚未由產品Owner確認

`ack=0` Community Token、`black_list=1`及Development scope仍需決策。

### Blocker 5：Official Evidence quorum未定

認證只證明誰提交，不能證明結果。Official／BP候選必須另定雙方Client、watcher或Game Server evidence門檻。

### Blocker 6：ULR API contract尚未納入Bearer Session

ULR Companion的TypeScript API contract需在實作前版本化，避免Python Client與PHP自行發明不同payload。

## 6. 建議實作順序

1. 團隊審查並凍結本Authentication Contract。
2. 決定localhost handoff、Eligibility、單一Session及API JSON casing。
3. 完成Phase 1 Ruleset Registry與Custom Match Lobby migration。
4. 以獨立變更完成Identity Integrity Migration與Callback併發處理。
5. 在ULR Companion新增版本化Auth／Evidence TypeScript contract與test vectors。
6. 建立 `client_auth_tokens`、`client_sessions`、audit migration。
7. 實作Token Issue與Exchange；先不接Evidence。
8. 實作Python Client安全secret storage與localhost handoff。
9. 實作Session status、heartbeat、revoke與Match終止撤銷。
10. 實作Evidence intake、idempotency receipt與local retry queue。
11. 以fake／development scope進行端到端測試。
12. 完成Verification policy後才允許Official／BP候選。

## 7. 需要30塊決定的事項

1. 是否批准獨立Identity Integrity Migration修改 `game_user.steamID`。
2. `black_list=1` 是否全拒絕Client Token。
3. PHP端API JSON是否正式採camelCase mapper。
4. Token hash採SHA-256或具版本的HMAC-SHA-256；推薦HMAC。
5. Audit retention與隱私政策。
6. 是否建立Client Session管理／撤銷網站頁面。

## 8. 需要5i決定的事項

1. localhost port discovery與challenge handshake。
2. Python access token是否使用Windows Credential Manager。
3. 一個Python Session如何協調雙開Game／CDP context。
4. 真實事件量、batch interval與payload上限。
5. Client crash recovery、queue格式與retention。
6. ULR `api-contract` Auth／Evidence版本化方式。

## 9. 需要燈皇決定的事項

1. `ack=0`是否允許Community／Development受限Token。
2. `black_list=1`觀察中帳號是否允許Community測試。
3. Replay／Evidence賽後可見範圍。
4. 裁判工具的業務角色與權限。
5. Development Match是否顯示在公開Lobby。

## 10. 需要共同決定的事項

1. Official／BP Evidence quorum。
2. localhost handoff threat model。
3. 單Participant／Match單一Active Session是否有正式例外。
4. API JSON casing及ULR contract版本。
5. Ruleset Hash mismatch時是否立即中止Match或只標INVALID。
6. Session失效後是否允許受控補傳本機Evidence。

## 11. Git Working Tree狀態

記錄時間：2026-07-29，兩份Authentication文件建立後。

```text
branch: master
HEAD: 2979ece9e3192e37695a8c2eab6b53a058bb3602
status entries: 8961
staged entries: 10
unstaged tracked entries: 38
untracked entries: 8913
```

工作樹原本已包含大量與本Authentication Contract無關的既有修改、staged與untracked檔案。本次只新增：

```text
docs/client_authentication_contract_v0.1.md
docs/client_authentication_contract_review.md
```

本次未修改PHP、Python、JavaScript或SQL，未執行資料庫寫入，也未stage、commit或push。
