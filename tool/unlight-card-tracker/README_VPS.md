# ULGG Raid Watcher — CentOS 9

這個服務直接連線已驗證的 Raid WebSocket，將 `raid_support_list`
正規化為既有 Raid domain model，再透過既有 Discord notifier 發送新 Raid。

## 安全與通知規則

- owner ID、Discord webhook 只從 `/opt/ulgg-raid-bot/.env` 讀取。
- 本機 health API 只綁定 `127.0.0.1`。
- 第一次有效清單只建立 baseline，不推播。
- 狀態只保存已見過的 Raid ID，不保存 owner ID、webhook 或原始封包。
- HP、人數更新、Raid 消失及已見 Raid 再出現都不推播。
- 同一輪多個新 Raid 合併成一則 Discord 訊息。

## 安裝

以下假設專案內容已放在 `/opt/ulgg-raid-bot`：

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
```

編輯 `.env`，填入 `RAID_OWNER_ID` 與 `DISCORD_WEBHOOK_URL`：

```bash
sudo -u ulgg vi /opt/ulgg-raid-bot/.env
```

## 安裝 systemd service

```bash
sudo cp /opt/ulgg-raid-bot/deploy/systemd/ulgg-raid-watcher.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ulgg-raid-watcher.service
sudo systemctl status ulgg-raid-watcher.service
```

## 驗證與日誌

```bash
curl --fail --silent http://127.0.0.1:8766/api/health
sudo journalctl -u ulgg-raid-watcher.service -f
```

Health response 不包含 owner ID 或 Discord webhook：

```json
{
  "ok": true,
  "source": "websocket",
  "connected": true,
  "raid_count": 2,
  "last_update": "2026-08-04T12:00:00+00:00",
  "last_error": null
}
```

## 更新設定

修改 `.env` 後重啟服務：

```bash
sudo systemctl restart ulgg-raid-watcher.service
```

## 切回桌面 CDP source

原本 CDP source 仍保留。將 `RAID_SOURCE` 改成 `cdp`，並確保程式所在主機
能存取本機遊戲 CDP。VPS 通常應使用 `RAID_SOURCE=websocket`。
