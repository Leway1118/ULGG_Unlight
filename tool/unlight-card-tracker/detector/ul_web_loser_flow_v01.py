# -*- coding: utf-8 -*-
"""
UNLIGHT Web Loser Flow v0.1
===========================
單輪敗方流程：

初始 Bonus（可在任意位置）
→ 最近白色石楠3
→ 領獎離開
→ 檢查 AP
→ 建立好友限定房
→ 等主帳加入並由遊戲進入對戰
→ 自動投降
→ Result OK
→ Bonus 最近白色石楠3
→ 領獎離開
→ 完成後停止

v0.1 限制：
- 只跑一輪，不循環。
- AP < 3 時安全停止，不自動使用 AP 道具。
- 尚未驗證指定加入者身分；本版等待遊戲進入 MainA。
- 建房參數沿用本次 Flow Probe 抓到的好友限定設定。
"""

from __future__ import annotations

import asyncio
from typing import Any

from ul_sniffer import ULSniffer


CDP_PORT = 59223
API_NAME = "__UL_WEB_LOSER_FLOW_V01__"


CONTROLLER_JS = r"""
(() => {
  const API_NAME = "__UL_WEB_LOSER_FLOW_V01__";

  const old = globalThis[API_NAME];
  if (old?.stop) old.stop("replaced");

  const state = {
    running: false,
    busy: false,
    timer: null,

    phase: "idle",
    roundStage: "initial_bonus",

    playerId: null,
    roomId: null,
    ap: null,
    apMax: null,

    targetCode: "item_battle_1_1",
    targetName: "白色石楠3",
    targetRank: null,
    tableSize: 0,

    roomRequested: false,
    surrenderClicked: false,
    surrenderConfirmed: false,
    resultOkClicked: false,
    initialBonusExited: false,
    finalBonusExited: false,

    lastAction: null,
    lastStopReason: null,
    lastError: null,
    actions: []
  };

  const sleep = ms =>
    new Promise(resolve => setTimeout(resolve, ms));

  function now() {
    return new Date().toISOString();
  }

  function action(text) {
    state.lastAction = String(text);
    state.actions.push({
      at: now(),
      phase: state.phase,
      text: String(text)
    });

    if (state.actions.length > 200) {
      state.actions.splice(0, state.actions.length - 200);
    }

    console.log("[UL Loser Flow v0.1]", text);
  }

  function getScenes() {
    return globalThis.game?.scene?.keys || {};
  }

  function getScene(name) {
    return getScenes()[name] || null;
  }

  function isSceneActive(name) {
    const scene = getScene(name);
    if (!scene) return false;

    try {
      return Boolean(
        scene.scene?.isActive?.() ||
        scene.scene?.isVisible?.()
      );
    } catch {
      return false;
    }
  }

  function findSocket() {
    const scenes = getScenes();

    const candidates = [
      scenes.Bonus?.socket,
      scenes.Result?.socket,
      scenes.Lobby?.socket,
      scenes.MainA?.socket,
      scenes.MatchBoot?.socket,
      scenes.Match?.socket,
      scenes.Initialize?.socket,
      scenes.Unlight_Init?.socket,
      scenes.ConnectionCheck?.socket,
      scenes.Raid?.socket
    ];

    for (const socket of candidates) {
      if (
        socket &&
        (
          typeof socket.fetch === "function" ||
          typeof socket.emit === "function"
        )
      ) {
        return socket;
      }
    }

    return null;
  }

  function findPlayerId() {
    if (
      typeof state.playerId === "string" &&
      state.playerId.length >= 20
    ) {
      return state.playerId;
    }

    const scenes = getScenes();
    const preferredFields = [
      "id",
      "player_id",
      "playerId",
      "uid",
      "user_id",
      "userId"
    ];

    for (const scene of Object.values(scenes)) {
      if (!scene) continue;

      for (const key of preferredFields) {
        const value = scene[key];

        if (
          typeof value === "string" &&
          value.length >= 20 &&
          !value.includes(" ")
        ) {
          state.playerId = value;
          return value;
        }
      }
    }

    return null;
  }

  function currentBonusPosition() {
    const b = getScene("Bonus");
    if (!b) return null;

    const value = Number(b.step ?? b.start);
    return Number.isFinite(value) ? value : null;
  }

  function itemQuantity(index) {
    const b = getScene("Bonus");
    const items = b?.item_list?.item_list || b?.items || [];

    const item = items.find(entry =>
      entry?.type === "battle" &&
      Number(entry.index) === Number(index)
    );

    return Number(item?.quantity || 0);
  }

  function visibleEnabled(object) {
    return Boolean(
      object &&
      object.visible !== false &&
      object.active !== false &&
      (
        object.input?.enabled ||
        object.input ||
        typeof object.emit === "function"
      )
    );
  }

  function objectTexture(object) {
    return String(
      object?.texture?.key ||
      object?.frame?.texture?.key ||
      ""
    );
  }

  function findObjectsByTexture(
    sceneName,
    texture,
    {
      requireVisible = true,
      requireInput = true
    } = {}
  ) {
    const scene = getScene(sceneName);
    const children = scene?.children?.list || [];

    return children.filter(object => {
      if (objectTexture(object) !== texture) {
        return false;
      }

      if (
        requireVisible &&
        (
          object?.visible === false ||
          object?.active === false
        )
      ) {
        return false;
      }

      if (
        requireInput &&
        !(
          object?.input?.enabled ||
          object?.input ||
          typeof object?.emit === "function"
        )
      ) {
        return false;
      }

      return true;
    });
  }

  function collectDisplayObjects(root, output = [], seen = new Set()) {
    if (!root || typeof root !== "object" || seen.has(root)) {
      return output;
    }

    seen.add(root);
    output.push(root);

    const children =
      root?.list ||
      root?.children?.list ||
      root?.getAll?.() ||
      [];

    if (Array.isArray(children)) {
      for (const child of children) {
        collectDisplayObjects(child, output, seen);
      }
    }

    return output;
  }

  function findSurrenderConfirmButton() {
    const scene = getScene("MainA");
    const children = scene?.children?.list || [];

    /*
     * Console 實測確認：
     * - OK：texture=btn_gene, x=330, y=376
     * - Cancel：texture=btn_gene, x=430, y=376
     * - index 會隨局面變動，不能使用固定 index
     *
     * 僅接受目前可見、啟用且 input.enabled=true 的左側按鈕。
     */
    return children.find(object =>
      objectTexture(object) === "btn_gene" &&
      object?.visible === true &&
      object?.active !== false &&
      object?.input?.enabled === true &&
      Math.abs(Number(object?.x) - 330) <= 5 &&
      Math.abs(Number(object?.y) - 376) <= 5
    ) || null;
  }

  async function clickSurrenderConfirm(object) {
    if (!object) {
      throw new Error("object_missing:surrender_confirm");
    }

    action("點擊：投降確認 OK");

    const pointer = {
      stopPropagation() {}
    };

    object.emit("pointerdown", null, 0, 0, pointer);
    await sleep(120);
    object.emit("pointerup", null, 0, 0, pointer);
    await sleep(500);
  }

  async function clickObject(object, label) {
    if (!object) {
      throw new Error(`object_missing:${label}`);
    }

    action(`點擊：${label}`);

    try {
      object.emit("pointerdown");
    } catch {}

    await sleep(120);

    try {
      object.emit("pointerup");
    } catch {}

    await sleep(120);

    try {
      object.emit("click");
    } catch {}
  }

  function stop(reason = "manual") {
    state.running = false;
    state.busy = false;
    state.lastStopReason = String(reason);

    if (state.timer !== null) {
      clearTimeout(state.timer);
      state.timer = null;
    }

    action(`停止：${reason}`);
    return state.lastStopReason;
  }

  function complete() {
    state.phase = "completed";
    state.running = false;
    state.busy = false;
    state.lastStopReason = "completed_one_round";

    if (state.timer !== null) {
      clearTimeout(state.timer);
      state.timer = null;
    }

    action("單輪敗方流程完成");
    return status();
  }

  function schedule(delay = 350) {
    if (!state.running) return;

    if (state.timer !== null) {
      clearTimeout(state.timer);
    }

    state.timer = setTimeout(tick, delay);
  }

  function chooseHighLow(b) {
    b.dice_tmp = b.rand;
    const dice = Number(b.dice_tmp);

    if (dice > 7) {
      b.btn_low.emit("pointerup");
      action(`骰子 ${dice}，選 LOW`);
    } else if (dice < 7) {
      b.btn_high.emit("pointerup");
      action(`骰子 ${dice}，選 HIGH`);
    } else if (Math.random() < 0.5) {
      b.btn_low.emit("pointerup");
      action("骰子 7，隨機選 LOW");
    } else {
      b.btn_high.emit("pointerup");
      action("骰子 7，隨機選 HIGH");
    }
  }

  async function fetchBonusTable() {
    const b = getScene("Bonus");

    if (!b) {
      throw new Error("bonus_scene_missing");
    }

    if (b.id == null) {
      throw new Error("bonus_not_initialized");
    }

    const source = findSocket();

    if (!source || typeof source.fetch !== "function") {
      throw new Error("socket_fetch_not_found");
    }

    const SocketClass = source.constructor;
    const socket = new SocketClass(
      "https://www.playunlight.online:11002"
    );

    const data = await socket.fetch(
      "db_bonusgame",
      b.id
    );

    if (!data?.items) {
      throw new Error("bonus_table_missing");
    }

    return String(data.items)
      .split(",")
      .map((code, index) => ({
        rank: index + 1,
        code: String(code)
      }));
  }

  async function prepareBonusTarget() {
    const current = currentBonusPosition();

    if (current === null) {
      throw new Error("bonus_position_missing");
    }

    const rows = await fetchBonusTable();

    /*
     * 重要：
     * 使用 >=，目前位置本身就是花3時必須立即保留，
     * 不可跳去找下一個花3。
     */
    const target = rows.find(row =>
      row.rank >= current &&
      row.code === state.targetCode
    );

    if (!target) {
      throw new Error("white3_not_found_from_current");
    }

    state.targetRank = target.rank;
    state.tableSize = rows.length;

    action(
      `Bonus目標：目前 ${current}，花3 ${target.rank}，距離 ${target.rank - current}`
    );
  }

  async function useFlower1Only(b) {
    await sleep(2163);

    if (!state.running) return;

    const diff = Math.abs(
      Number(b.rand) - Number(b.dice_tmp)
    );

    if (!Number.isFinite(diff)) {
      stop("dice_difference_invalid");
      return;
    }

    if (diff > 1) {
      stop(`dice_diff_${diff}_flower1_unusable`);
      return;
    }

    const quantity = itemQuantity(0);

    if (quantity <= 0) {
      stop("flower1_insufficient");
      return;
    }

    b.btn_item.emit("pointerup");
    action(`骰差 ${diff}，開啟道具選單`);

    await sleep(515);

    if (!state.running) return;

    if (!b.item_list?.item_use) {
      stop("item_panel_missing");
      return;
    }

    b.item_list.item_select = {
      index: 0,
      type: "battle"
    };

    b.item_list.item_use.setVisible();
    b.item_list.item_use.emit("pointerdown");

    action(`使用白色石楠1，使用前庫存 ${quantity}`);
  }

  async function runBonusUntilTarget() {
    const b = getScene("Bonus");

    if (!b) {
      return false;
    }

    if (state.targetRank === null) {
      await prepareBonusTarget();
    }

    const current = currentBonusPosition();

    if (
      current !== null &&
      state.targetRank !== null &&
      current >= state.targetRank
    ) {
      state.phase = "bonus_target_reached";
      action(
        current === state.targetRank
          ? `已位於花3：${current}`
          : `已到達花3目標：${current}`
      );
      return true;
    }

    if (
      b.btn_next?.input?.enabled &&
      b.btn_next.visible
    ) {
      b.btn_next.emit("pointerup");
      action(`Bonus下一步，目前 ${current}`);
      return false;
    }

    if (
      b.btn_high?.input?.enabled &&
      b.btn_low?.input?.enabled &&
      b.btn_high.visible &&
      b.btn_low.visible
    ) {
      chooseHighLow(b);
      return false;
    }

    if (
      b.btn_item?.input?.enabled &&
      b.btn_item.visible
    ) {
      await useFlower1Only(b);
      return false;
    }

    return false;
  }

  async function claimAndExitBonus() {
    const objects = findObjectsByTexture(
      "Bonus",
      "result_get"
    );

    if (objects.length === 0) {
      state.phase = "waiting_bonus_result_get";
      return false;
    }

    await clickObject(
      objects[0],
      "Bonus result_get"
    );

    state.targetRank = null;

    if (!state.initialBonusExited) {
      state.initialBonusExited = true;
      state.roundStage = "room";
      state.phase = "waiting_lobby_after_initial_bonus";
      action("初始 Bonus 已領獎，等待回大廳");
    } else {
      state.finalBonusExited = true;
      action("最終 Bonus 已領獎");
    }

    return true;
  }

  async function readPlayerAp() {
    const socket = findSocket();
    const playerId = findPlayerId();

    if (!socket || typeof socket.fetch !== "function") {
      throw new Error("socket_fetch_not_found");
    }

    if (!playerId) {
      throw new Error("player_id_not_found");
    }

    const player = await socket.fetch(
      "db_player",
      playerId
    );

    const ap = Number(player?.ap);
    const apMax = Number(
      player?.ap_max ?? player?.apMax
    );

    state.ap = Number.isFinite(ap) ? ap : null;
    state.apMax = Number.isFinite(apMax)
      ? apMax
      : null;

    action(`AP：${state.ap}/${state.apMax}`);

    return state.ap;
  }

  async function createFriendRoom() {
    const socket = findSocket();
    const playerId = findPlayerId();

    if (!socket || typeof socket.emit !== "function") {
      throw new Error("socket_emit_not_found");
    }

    if (!playerId) {
      throw new Error("player_id_not_found");
    }

    if (state.ap === null) {
      await readPlayerAp();
    }

    if (state.ap < 3) {
      stop("ap_insufficient");
      return false;
    }

    /*
     * 本組參數來自 2026-08-03 Flow Probe：
     * match_room_make(
     *   playerId, 2, "請多關照", "000",
     *   true, true, null, null, 1
     * )
     *
     * v0.1 先沿用成功建立好友限定房的原始設定。
     */
    socket.emit(
      "match_room_make",
      playerId,
      2,
      "請多關照",
      "000",
      true,
      true,
      null,
      null,
      1
    );

    state.roomRequested = true;
    state.phase = "waiting_room_and_opponent";
    action("已送出好友限定房建立請求，等待主帳加入");
    return true;
  }

  async function handleMatch() {
    if (!isSceneActive("MainA")) {
      return false;
    }

    state.phase = "match";

    if (!state.surrenderClicked) {
      const surrenderButtons = findObjectsByTexture(
        "MainA",
        "btn_surrender"
      );

      if (surrenderButtons.length === 0) {
        state.phase = "waiting_surrender_button";
        return false;
      }

      await clickObject(
        surrenderButtons[0],
        "MainA btn_surrender"
      );

      state.surrenderClicked = true;
      state.phase = "waiting_surrender_confirm";
      return true;
    }

    if (!state.surrenderConfirmed) {
      /*
       * 投降彈窗有動畫，等待其 OK 按鈕真正變成 visible/input enabled。
       */
      await sleep(450);

      const confirmButton =
        findSurrenderConfirmButton();

      if (!confirmButton) {
        state.phase = "waiting_surrender_confirm";

        const scene = getScene("MainA");
        const candidates = (scene?.children?.list || [])
          .filter(object => objectTexture(object) === "btn_gene")
          .map((object, index) => ({
            index,
            x: object?.x ?? null,
            y: object?.y ?? null,
            visible: object?.visible ?? null,
            active: object?.active ?? null,
            inputEnabled: object?.input?.enabled ?? null
          }));

        action(
          `等待投降確認 OK｜btn_gene候選=${JSON.stringify(candidates)}`
        );
        return false;
      }

      await clickSurrenderConfirm(confirmButton);

      state.surrenderConfirmed = true;
      state.phase = "waiting_result";
      action("已確認投降，等待 Result");
      return true;
    }

    return false;
  }

  async function handleResult() {
    if (!isSceneActive("Result")) {
      return false;
    }

    state.phase = "result";

    if (state.resultOkClicked) {
      state.phase = "waiting_final_bonus";
      return false;
    }

    const okButtons = findObjectsByTexture(
      "Result",
      "panel_ok"
    );

    if (okButtons.length === 0) {
      state.phase = "waiting_result_ok";
      return false;
    }

    await clickObject(
      okButtons[0],
      "Result panel_ok"
    );

    state.resultOkClicked = true;
    state.roundStage = "final_bonus";
    state.targetRank = null;
    state.phase = "waiting_final_bonus";
    action("Result OK 已點擊，等待 Bonus");
    return true;
  }

  async function tick() {
    state.timer = null;

    if (!state.running) return;

    if (state.busy) {
      schedule();
      return;
    }

    state.busy = true;

    try {
      if (isSceneActive("Bonus")) {
        state.phase =
          state.roundStage === "final_bonus"
            ? "final_bonus"
            : "initial_bonus";

        const reached = await runBonusUntilTarget();

        if (reached) {
          const claimed = await claimAndExitBonus();

          if (
            claimed &&
            state.finalBonusExited
          ) {
            complete();
            return;
          }
        }

        return;
      }

      if (isSceneActive("Result")) {
        await handleResult();
        return;
      }

      if (isSceneActive("MainA")) {
        await handleMatch();
        return;
      }

      if (
        state.roundStage === "room" &&
        !state.roomRequested
      ) {
        state.phase = "preparing_room";

        const ap = await readPlayerAp();

        if (ap === null) {
          stop("ap_unreadable");
          return;
        }

        if (ap < 3) {
          stop("ap_insufficient");
          return;
        }

        await createFriendRoom();
        return;
      }

      if (
        state.roomRequested &&
        !state.surrenderClicked
      ) {
        state.phase = "waiting_room_and_opponent";
        return;
      }

      if (
        state.resultOkClicked &&
        state.roundStage === "final_bonus"
      ) {
        state.phase = "waiting_final_bonus";
        return;
      }

      state.phase = "waiting_supported_scene";
    } catch (error) {
      state.lastError = String(
        error?.stack || error
      );

      console.error(
        "[UL Loser Flow v0.1]",
        error
      );

      stop(
        String(error?.message || "runtime_error")
      );
      return;
    } finally {
      state.busy = false;

      /*
       * tick() 內多數 Scene 分支都會直接 return。
       * 排程若放在 finally 外面，完成一次動作後就不會再進下一輪，
       * 造成流程停在 waiting_surrender_confirm。
       */
      if (state.running && state.timer === null) {
        schedule();
      }
    }
  }

  async function start() {
    if (state.running) {
      return status();
    }

    state.running = true;
    state.lastStopReason = null;
    state.lastError = null;
    state.phase = "starting";

    action("開始單輪敗方流程");
    schedule(100);

    return status();
  }

  function status() {
    return {
      running: state.running,
      phase: state.phase,
      roundStage: state.roundStage,

      playerId: state.playerId,
      roomId: state.roomId,
      ap: state.ap,
      apMax: state.apMax,

      currentBonusPosition: currentBonusPosition(),
      targetRank: state.targetRank,
      targetName: state.targetName,
      tableSize: state.tableSize,

      roomRequested: state.roomRequested,
      surrenderClicked: state.surrenderClicked,
      surrenderConfirmed: state.surrenderConfirmed,
      resultOkClicked: state.resultOkClicked,
      initialBonusExited: state.initialBonusExited,
      finalBonusExited: state.finalBonusExited,

      activeScenes: Object.keys(getScenes()).filter(
        isSceneActive
      ),

      lastAction: state.lastAction,
      lastStopReason: state.lastStopReason,
      lastError: state.lastError,
      actions: state.actions.slice(-20)
    };
  }

  globalThis[API_NAME] = {
    state,
    start,
    stop,
    status,
    tick
  };

  return status();
})()
"""


async def evaluate_or_raise(
    sniffer: ULSniffer,
    expression: str,
    *,
    await_promise: bool = False,
) -> Any:
    value, error = await sniffer.evaluate(
        expression,
        await_promise=await_promise,
    )

    if error:
        raise RuntimeError(error)

    return value


async def get_status(
    sniffer: ULSniffer,
) -> dict[str, Any]:
    value = await evaluate_or_raise(
        sniffer,
        f"globalThis.{API_NAME}?.status?.()",
    )

    return value if isinstance(value, dict) else {}


async def cdp_click_game_point(
    sniffer: ULSniffer,
    game_x: float,
    game_y: float,
) -> None:
    geometry = await evaluate_or_raise(
        sniffer,
        f"""
(() => {{
  const canvas = globalThis.game?.canvas;
  if (!canvas) return null;

  const rect = canvas.getBoundingClientRect();

  return {{
    clientX: rect.left + ({game_x}) * rect.width / canvas.width,
    clientY: rect.top + ({game_y}) * rect.height / canvas.height
  }};
}})()
""",
    )

    if not isinstance(geometry, dict):
        raise RuntimeError("找不到遊戲 Canvas 座標")

    location = getattr(sniffer, "_game_location", None)
    if not location:
        raise RuntimeError("找不到遊戲 CDP session")

    session_id, _context_id = location
    x = float(geometry["clientX"])
    y = float(geometry["clientY"])

    await sniffer._send(
        "Input.dispatchMouseEvent",
        {
            "type": "mousePressed",
            "x": x,
            "y": y,
            "button": "left",
            "buttons": 1,
            "clickCount": 1,
        },
        session_id=session_id,
    )

    await asyncio.sleep(0.12)

    await sniffer._send(
        "Input.dispatchMouseEvent",
        {
            "type": "mouseReleased",
            "x": x,
            "y": y,
            "button": "left",
            "buttons": 0,
            "clickCount": 1,
        },
        session_id=session_id,
    )


def format_status(data: dict[str, Any]) -> str:
    return (
        f"執行={data.get('running')}｜"
        f"階段={data.get('phase')}｜"
        f"輪段={data.get('roundStage')}｜"
        f"Scene={data.get('activeScenes')}｜"
        f"AP={data.get('ap')}/{data.get('apMax')}｜"
        f"位置={data.get('currentBonusPosition')}｜"
        f"花3={data.get('targetRank')}｜"
        f"建房={data.get('roomRequested')}｜"
        f"投降={data.get('surrenderConfirmed')}｜"
        f"ResultOK={data.get('resultOkClicked')}｜"
        f"停止={data.get('lastStopReason')}｜"
        f"動作={data.get('lastAction')}"
    )


async def monitor(
    sniffer: ULSniffer,
    stop_event: asyncio.Event,
) -> None:
    previous_signature: tuple[Any, ...] | None = None
    surrender_notice_clicked = False
    completion_announced = False

    while not stop_event.is_set():
        try:
            data = await get_status(sniffer)

            signature = (
                data.get("running"),
                data.get("phase"),
                data.get("roundStage"),
                tuple(data.get("activeScenes") or []),
                data.get("ap"),
                data.get("currentBonusPosition"),
                data.get("targetRank"),
                data.get("lastAction"),
                data.get("lastStopReason"),
                data.get("lastError"),
            )

            if signature != previous_signature:
                print("[狀態] " + format_status(data))
                previous_signature = signature

            if (
                data.get("running")
                and data.get("phase") == "waiting_result"
                and data.get("surrenderConfirmed")
                and "MainA" in (data.get("activeScenes") or [])
                and not surrender_notice_clicked
            ):
                await asyncio.sleep(0.5)
                await cdp_click_game_point(sniffer, 350, 350)
                surrender_notice_clicked = True
                print("[+] 已點擊投降完成通知 OK")

            if not data.get("running"):
                surrender_notice_clicked = False

            if (
                data.get("lastStopReason") == "completed_one_round"
                and not completion_announced
            ):
                print("[+] 單輪流程完成，可以輸入 Q 離開。")
                completion_announced = True

            if data.get("lastStopReason") != "completed_one_round":
                completion_announced = False

        except Exception as exc:
            print(f"[!] 狀態讀取失敗：{exc}")
            stop_event.set()
            return

        try:
            await asyncio.wait_for(
                stop_event.wait(),
                timeout=0.7,
            )
        except asyncio.TimeoutError:
            pass


async def command_loop(
    sniffer: ULSniffer,
) -> None:
    print()
    print("=== UNLIGHT Web Loser Flow v0.1 ===")
    print("單輪：Bonus花3 → 建好友房 → 投降 → Result → Bonus花3")
    print("AP < 3：本版安全停止，不使用 AP 道具")
    print("指令：S開始／P狀態／X停止／Q離開")
    print()

    stop_event = asyncio.Event()
    monitor_task = asyncio.create_task(
        monitor(sniffer, stop_event)
    )

    try:
        while True:
            command = (
                await asyncio.to_thread(input, "> ")
            ).strip().lower()

            if command in {"s", "start", "開始"}:
                data = await evaluate_or_raise(
                    sniffer,
                    f"globalThis.{API_NAME}.start()",
                    await_promise=True,
                )
                print("[+] " + format_status(data))

            elif command in {"p", "status", "狀態", ""}:
                data = await get_status(sniffer)
                print("[+] " + format_status(data))

            elif command in {"x", "stop", "停止"}:
                reason = await evaluate_or_raise(
                    sniffer,
                    f"globalThis.{API_NAME}.stop('manual')",
                )
                print(f"[+] 已停止：{reason}")

            elif command in {"q", "quit", "exit", "離開"}:
                try:
                    await evaluate_or_raise(
                        sniffer,
                        f"globalThis.{API_NAME}.stop('python_exit')",
                    )
                except Exception:
                    pass

                print("[+] 離開程式")
                break

            else:
                print("未知指令。使用 S／P／X／Q。")
    finally:
        stop_event.set()
        monitor_task.cancel()

        try:
            await monitor_task
        except asyncio.CancelledError:
            pass

        if sniffer.ws is not None:
            await sniffer.ws.close()


async def after_connect(
    sniffer: ULSniffer,
) -> None:
    print("[+] CDP 已連線，注入單輪敗方控制器……")

    injected = await evaluate_or_raise(
        sniffer,
        CONTROLLER_JS,
    )

    print("[+] 控制器已注入")
    print("[+] " + format_status(injected))
    print()
    print("[!] 第一次測試請全程看著畫面，異常立即輸入 X。")

    await command_loop(sniffer)


def main() -> int:
    print(
        f"[+] 連接 Chrome CDP "
        f"127.0.0.1:{CDP_PORT}"
    )

    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=False,
    )

    sniffer.start(
        after_connect=after_connect,
    )

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
