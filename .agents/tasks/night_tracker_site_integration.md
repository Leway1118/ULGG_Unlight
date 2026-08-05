# Unlight Tracker Night Sprint

## Sprint Name

Site Detection Integration Investigation


## Objective

將目前 SiteDetector 的辨識結果整合評估到 Tracker runtime flow。

目標不是完整發布，而是完成一個可審查的開發切片。


## Allowed Scope

只允許修改：

tool/unlight-card-tracker/


優先範圍：

- detector/
- tracker-api.js
- tracker-db.js
- observation-poller.js
- tests/


禁止修改：

- pages/
- config.php
- steam/data/
- old_pages/
- old_unlight/
- vps/
- unrelated dirty files


## Required Workflow

### Phase 1 - Explore

確認：

1. SiteDetector result 目前流向
2. live_screen_monitor 如何產生 state
3. Tracker API 如何接收 observation
4. battle state 是否已有 site 欄位
5. UI 是否已有顯示位置


### Phase 2 - Design

提出最小整合方案：

必須回答：

- site result 應該存在哪個 state
- 是否需要新增 API field
- 是否需要 DB schema
- 是否需要 IndexedDB projection


若涉及產品決策：
停止並寫入報告。


### Phase 3 - Implementation

若不需要重大架構決策：

完成最小修改：

- 加入必要欄位
- 補測試
- 不破壞既有 flow


### Phase 4 - Verification

執行：

- targeted tests
- related regression tests
- py_compile


## Git Rules

禁止：

- git add
- git commit
- git push
- git reset
- git clean
- git restore


## Data Safety

禁止：

- 刪除 debug frames
- 修改 SQLite
- 修改 JSONL runtime data
- 清理 dirty files


## Final Report

建立：

docs/night_report_site_integration.md


內容：

# Summary

# Modified Files

# Architecture Impact

# Tests

# Remaining Risks

# Questions For Human Review


完成後停止等待人工審查。