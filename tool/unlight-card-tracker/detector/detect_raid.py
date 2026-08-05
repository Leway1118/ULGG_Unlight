# -*- coding: utf-8 -*-
from __future__ import annotations

import json
from pathlib import Path

from ul_sniffer import ULSniffer


CDP_PORT = 59222
OUTPUT_PATH = Path("raid_probe.json")


RAID_PROBE_JS = r"""
(() => {
  const game = globalThis.game;

  if (!game?.scene) {
    return JSON.stringify({
      ok: false,
      error: "Phaser game.scene missing"
    });
  }

  const keywords = [
    "raid", "boss", "vortex", "reward",
    "fragment", "join", "participant",
    "battle", "room", "code", "hp",
    "remain", "expire"
  ];

  const maxDepth = 3;
  const maxArrayItems = 30;
  const maxObjectKeys = 80;

  function keywordHit(text) {
    const lower = String(text || "").toLowerCase();
    return keywords.some(word => lower.includes(word));
  }

  function summarize(value, depth = 0, seen = new WeakSet()) {
    if (
      value === null ||
      typeof value === "string" ||
      typeof value === "number" ||
      typeof value === "boolean"
    ) {
      return value;
    }

    if (typeof value === "function") {
      return `[Function ${value.name || "anonymous"}]`;
    }

    if (typeof value !== "object") {
      return String(value);
    }

    if (seen.has(value)) {
      return "[Circular]";
    }

    if (depth >= maxDepth) {
      return Array.isArray(value)
        ? `[Array(${value.length})]`
        : `[${value.constructor?.name || "Object"}]`;
    }

    seen.add(value);

    if (Array.isArray(value)) {
      return value
        .slice(0, maxArrayItems)
        .map(item => summarize(item, depth + 1, seen));
    }

    const out = {};

    for (const key of Object.keys(value).slice(0, maxObjectKeys)) {
      try {
        out[key] = summarize(value[key], depth + 1, seen);
      } catch (error) {
        out[key] = `[Error: ${error?.message || error}]`;
      }
    }

    return out;
  }

  function candidateScore(key, value) {
    let score = keywordHit(key) ? 5 : 0;

    if (Array.isArray(value)) {
      score += Math.min(value.length, 10);
    }

    if (value && typeof value === "object") {
      for (const childKey of Object.keys(value)) {
        if (keywordHit(childKey)) {
          score += 2;
        }
      }
    }

    return score;
  }

  function scan(root, rootName, depth = 0, seen = new WeakSet(), results = []) {
    if (
      !root ||
      typeof root !== "object" ||
      seen.has(root) ||
      depth > maxDepth
    ) {
      return results;
    }

    seen.add(root);

    for (const key of Object.keys(root).slice(0, maxObjectKeys)) {
      let value;

      try {
        value = root[key];
      } catch {
        continue;
      }

      const path = `${rootName}.${key}`;
      const score = candidateScore(key, value);

      if (score > 0) {
        results.push({
          path,
          key,
          score,
          type: Array.isArray(value) ? "array" : typeof value,
          length: Array.isArray(value) ? value.length : null,
          preview: summarize(value)
        });
      }

      if (value && typeof value === "object" && depth < maxDepth) {
        scan(value, path, depth + 1, seen, results);
      }
    }

    return results;
  }

  const sceneKeys =
    game.scene.keys ||
    game.scene.manager?.keys ||
    {};

  const scenes = [];
  const candidates = [];
  const visibleTexts = [];

  for (const [sceneKey, scene] of Object.entries(sceneKeys)) {
    let active = false;
    let sleeping = false;

    try {
      active = Boolean(scene?.scene?.isActive?.());
      sleeping = Boolean(scene?.scene?.isSleeping?.());
    } catch {}

    scenes.push({
      key: sceneKey,
      active,
      sleeping,
      constructor: scene?.constructor?.name || null
    });

    for (const item of scan(scene, `scene.${sceneKey}`)) {
      candidates.push({
        scene: sceneKey,
        ...item
      });
    }

    const displayList =
      scene?.children?.list ||
      scene?.sys?.displayList?.list ||
      [];

    for (const object of displayList.slice(0, 500)) {
      const text =
        typeof object?.text === "string"
          ? object.text.trim()
          : "";

      if (!text) {
        continue;
      }

      visibleTexts.push({
        scene: sceneKey,
        text: text.slice(0, 500),
        name: object?.name || null,
        type:
          object?.type ||
          object?.constructor?.name ||
          null,
        x: Number(object?.x || 0),
        y: Number(object?.y || 0),
        visible: object?.visible !== false
      });
    }
  }

  candidates.sort((a, b) => b.score - a.score);

  return JSON.stringify({
    ok: true,
    url: globalThis.location?.href || null,
    title: globalThis.document?.title || null,
    generatedAt: new Date().toISOString(),
    sceneCount: scenes.length,
    scenes,
    candidateCount: candidates.length,
    candidates: candidates.slice(0, 250),
    visibleTextCount: visibleTexts.length,
    visibleTexts
  });
})()
"""


async def after_connect(sniffer: ULSniffer) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請停在遊戲內 Raid 清單主畫面")
    input("[+] 畫面準備好後按 Enter 開始探測……")

    value, error = await sniffer.evaluate(
        RAID_PROBE_JS,
        await_promise=False,
    )

    if error:
        print(f"\n[!] 探測失敗：{error}")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    data = json.loads(value)

    OUTPUT_PATH.write_text(
        json.dumps(
            data,
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    print("\n[+] Raid 探測完成")
    print(f"[+] Scene 數量：{data.get('sceneCount', 0)}")
    print(f"[+] 疑似資料位置：{data.get('candidateCount', 0)}")
    print(f"[+] 可見文字數量：{data.get('visibleTextCount', 0)}")

    print("\n目前啟用的 Scene：")
    for scene in data.get("scenes", []):
        if scene.get("active"):
            print(f"  {scene.get('key')} ({scene.get('constructor')})")

    print("\n最高分疑似 Raid 資料位置：")
    for candidate in data.get("candidates", [])[:20]:
        print(
            f"  score={candidate.get('score'):>2} "
            f"{candidate.get('path')} "
            f"[{candidate.get('type')}]"
        )

    print(f"\n[+] 完整結果已寫入：{OUTPUT_PATH.resolve()}")
    print("[+] 請把 raid_probe.json 上傳給我分析")

    input("\n按 Enter 關閉……")
    await sniffer.ws.close()


def main() -> None:
    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=False,
    )

    sniffer.start(
        after_connect=after_connect
    )


if __name__ == "__main__":
    main()
