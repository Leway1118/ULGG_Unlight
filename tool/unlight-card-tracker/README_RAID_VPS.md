# ULGG Raid Watcher VPS 部署指南

本服務直接連線已實作的 Raid WebSocket source，把
`raid_support_list` 轉成現有 Raid domain model，並透過現有
formatter、`RaidNotifier` 與 `DiscordWebhook` 發送通知。

這條 VPS 流程不需要 Steam、遊戲桌面版、CDP 或瀏覽器 Extension。

## 實際行為

- WebSocket endpoint 預設為 `wss://www.playunlight.online:15003/`。
- 連線後送出 `register [owner_id]`。
- 立即查詢一次，之後每 `RAID_INTERVAL` 秒送出
  `raid_get_support [owner_id]`。
- 接收 `raid_support_list` 後正規化 HP、人數、到期時間與 Raid ID。
- 第一份有效清單只建立 baseline，不通知。
- 之後只通知從未出現過的 `prf_code`。
- HP 或人數更新、Raid 消失、已見 Raid 再出現都不通知。
- 同一輪多個新 Raid 合併成一則 Discord 訊息。
- 已見 Raid ID 寫入 `RAID_STATE_FILE`，使用同目錄暫存檔與
  `os.replace` 進行 atomic replace。
- 斷線後以 2、5、10、30、60 秒上限的退避序列重連。

## 需求

- CentOS 9 主機與 Python 3.11。
- 專案內容放在 `/opt/ulgg-raid-bot`。
- 有效的 `RAID_OWNER_ID`。
- 有效的 Discord webhook URL。
- VPS 可建立對 Raid WebSocket 與 Discord 的對外 HTTPS/WSS 連線。

Health API 固定只允許綁定 localhost。程式會拒絕
`0.0.0.0`、`::` 或其他非 localhost 的 `API_HOST`。

## 安裝

以下假設專案已放在 `/opt/ulgg-raid-bot`：

```bash
sudo dnf install -y python3.11 python3.11-pip
sudo useradd --system --home-dir /opt/ulgg-raid-bot --shell /sbin/nologin ulgg
sudo chown -R ulgg:ulgg /opt/ulgg-raid-bot

sudo -u ulgg python3.11 -m venv /opt/ulgg-raid-bot/.venv
sudo -u ulgg /opt/ulgg-raid-bot/.venv/bin/python -m pip install --upgrade pip
sudo -u ulgg /opt/ulgg-raid-bot/.venv/bin/python -m pip install -r /opt/ulgg-raid-bot/detector/requirements-raid-bot.txt

sudo -u ulgg cp /opt/ulgg-raid-bot/.env.example /opt/ulgg-raid-bot/.env
sudo chmod 600 /opt/ulgg-raid-bot/.env
sudo mkdir -p /opt/ulgg-raid-bot/data
sudo chown -R ulgg:ulgg /opt/ulgg-raid-bot/data /opt/ulgg-raid-bot/.env
sudo -u ulgg vi /opt/ulgg-raid-bot/.env
```

`.env` 至少需要：

```dotenv
RAID_SOURCE=websocket
RAID_WS_ENDPOINT=wss://www.playunlight.online:15003/
RAID_OWNER_ID=
RAID_INTERVAL=10
DISCORD_WEBHOOK_URL=
RAID_STATE_FILE=./data/raid_state.json
API_HOST=127.0.0.1
API_PORT=8766
```

不要把真實 `.env` 加入版本控制，也不要把 owner ID 或 webhook
貼到 issue、聊天記錄或 systemd unit 內。

## 先以前景模式驗證

```bash
cd /opt/ulgg-raid-bot
sudo -u ulgg /opt/ulgg-raid-bot/.venv/bin/python detector/run_raid_bot.py
```

另開一個 SSH session 查詢：

```bash
curl --fail --silent http://127.0.0.1:8766/api/health
```

`ok: true` 代表 HTTP API 正常回應；是否連上 Raid source 要另看
`connected`。`last_error` 只保留非敏感的錯誤類型，不會回傳
owner ID 或 webhook。

第一次收到清單沒有 Discord 通知是預期行為。

## 安裝 systemd

```bash
sudo cp /opt/ulgg-raid-bot/deploy/systemd/ulgg-raid-watcher.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ulgg-raid-watcher.service
sudo systemctl status ulgg-raid-watcher.service
```

systemd 會以非 root 帳號 `ulgg` 執行，工作目錄為
`/opt/ulgg-raid-bot`，並從 `/opt/ulgg-raid-bot/.env` 載入設定。
程式結束後 systemd 會等待 10 秒再重啟，日誌交給 journald。

```bash
sudo journalctl -u ulgg-raid-watcher.service -f
sudo systemctl restart ulgg-raid-watcher.service
sudo systemctl stop ulgg-raid-watcher.service
```

## 狀態檔與通知保證

`raid_state.json` 只保存已見 Raid ID，不保存 owner ID、webhook
或原始 WebSocket payload。

新 Raid ID 在通知前就會先寫入狀態檔。這可避免服務重啟後
重複通知；但若 Discord 該次傳送失敗，現行程式不會把同一
Raid 當成新 Raid 再次傳送。

刪除狀態檔會使下一份有效清單重建 baseline。除非明確要
重建狀態，否則不應刪除。

## CDP 模式

`RAID_SOURCE=cdp` 仍會建立 `CDPClient` 與 `RaidReader`，並使用
`CDP_PORT`。這是桌面版相容路徑，不是 VPS WebSocket 部署的
預設流程。

完整安裝摘要請見 [docs/USER_INSTALLATION.md](docs/USER_INSTALLATION.md)，
問題排查請見 [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)。
