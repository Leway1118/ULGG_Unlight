# -*- coding: utf-8 -*-
import asyncio
import json

from ul_sniffer import ULSniffer


JS = r"""
(() => {
  const game = globalThis.game;
  const scenes = game?.scene?.keys || {};
  const bonus = scenes.Bonus;

  const summarize = (obj) => {
    if (!obj) return null;
    const out = {};
    for (const key of Reflect.ownKeys(obj)) {
      let value;
      try { value = obj[key]; } catch (e) { value = "[getter error]"; }
      const type = typeof value;
      out[String(key)] = {
        type,
        ctor: value?.constructor?.name || null,
        hasFetch: Boolean(value && typeof value.fetch === "function"),
        hasSend: Boolean(value && typeof value.send === "function"),
        hasEmit: Boolean(value && typeof value.emit === "function"),
      };
    }
    return out;
  };

  const sceneCandidates = {};
  for (const [sceneName, scene] of Object.entries(scenes)) {
    const hits = {};
    for (const key of Reflect.ownKeys(scene || {})) {
      let value;
      try { value = scene[key]; } catch (e) { continue; }
      if (
        value &&
        (
          typeof value.fetch === "function" ||
          typeof value.send === "function" ||
          typeof value.emit === "function" ||
          /sock|server|client|conn|network/i.test(String(key))
        )
      ) {
        hits[String(key)] = {
          type: typeof value,
          ctor: value?.constructor?.name || null,
          hasFetch: typeof value.fetch === "function",
          hasSend: typeof value.send === "function",
          hasEmit: typeof value.emit === "function",
        };
      }
    }
    if (Object.keys(hits).length) sceneCandidates[sceneName] = hits;
  }

  return JSON.stringify({
    gameKeys: Reflect.ownKeys(game || {}).map(String),
    sceneNames: Object.keys(scenes),
    bonusExists: Boolean(bonus),
    bonusKeys: bonus ? Reflect.ownKeys(bonus).map(String) : [],
    bonusSummary: summarize(bonus),
    sceneCandidates,
  });
})()
"""


async def inspect(sniffer):
    try:
        value, error = await sniffer.evaluate(JS, await_promise=False)
        if error:
            print("[!] inspect failed:", error)
            return
        data = json.loads(value)
        print(json.dumps(data, ensure_ascii=False, indent=2))
    finally:
        await sniffer.ws.close()


def main():
    sniffer = ULSniffer(port=59222, verbose=True)
    sniffer.start(after_connect=inspect)


if __name__ == "__main__":
    main()
