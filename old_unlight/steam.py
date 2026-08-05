from pathlib import Path
import json
import asyncio


import socketio

data_dir = Path("steam/data")  # 改成要放資料的資料夾

data_dir.mkdir(parents=True, exist_ok=True)  # ✅ 確保資料夾存在
class ChannelWatcher:
    def __init__(self, channel_id: int):
        self.channel_id = channel_id
        self.sio = socketio.AsyncClient()
        self.sio.on(f"channel{channel_id}_room", self.channel_rooms)
        self.data = data_dir / f"channel{channel_id}_room.json"
        if not self.data.exists():
            with self.data.open("w", encoding="utf-8") as f:
                json.dump({}, f)
        with self.data.open("r", encoding="utf-8") as f:
            self.channel_data = json.load(f)

    async def channel_rooms(self, rooms):
        self.channel_data.update({room["room_id"]: room for room in rooms})
        with self.data.open("w", encoding="utf-8") as f:
            json.dump(self.channel_data, f, indent=4, ensure_ascii=False)

    async def start(self):
        endpoint = "https://www.playunlight.online:11012"
        await self.sio.connect(endpoint)
        await self.sio.emit("channel_in", self.channel_id)
        await self.sio.wait()


async def main():
    channel_ids = [1]  # Replace with your actual channel IDs
    watchers = [ChannelWatcher(channel_id) for channel_id in channel_ids]
    await asyncio.gather(*(watcher.start() for watcher in watchers))


if __name__ == "__main__":
    asyncio.run(main())