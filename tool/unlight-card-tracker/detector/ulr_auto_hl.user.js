// ==UserScript==
// @name         ULR auto HL
// @namespace    http://tampermonkey.net/
// @version      0.0.1
// @description  Auto HL for ULR with configurable settings. Press Space to toggle on/off
// @author       Me
// @match        https://www.playunlight.online/*
// @match        https://www.trexmtgrzrm-jhrguyhqcuquyflzzbv6.site/?*
// @run-at       document-start
// @grant        none
// @license      MIT
// ==/UserScript==
 
//////// Settings Start ////////
const HOTKEY = "Space";
const SKIP_ITEMS = [
  "exp",
  "gem",
  "cc", // 角色
  "event",
  "item_avatar",
  "item_quest", // 通行證
  "item_battle",
  "item_ccoin",
  "item_cmem", // 碎片
  "item_weapon",
  "item_raid_5", //β型渦探知機 I
  // "item_raid_6", //β型渦探知機 II
];
const BATTLE_ITEMS = [
  0, // 0: 花1
  1, // 1: 花3
  2, // 2: 花5
  3, // 3: 四葉草
  4, // 4: 跳越星
];
const BATTLE_ITEM_DELAY_SCALE = 1.03; // battle item use delay = base delay * this scale
const MAX_STEP = null; // null or number, stop auto running when reaching this step
const CHECK_INTERVAL = 300; // ms, check button interval, lower value may cause higher CPU usage
//////// Settings End ////////
 
(function () {
  "use strict";
 
  let bonus = null;
  let timeoutID = null;
  let readyStates = {
    next: 1,
    highlow: 1,
    item: 1,
  };
 
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
 
  function waitBonusScene() {
    const intervalID = setInterval(() => {
      if (window?.game?.scene?.keys?.Bonus) {
        console.log("Bonus scene detected");
        clearInterval(intervalID);
        bonus = window.game.scene.keys.Bonus;
      }
    }, 500);
  }
  waitBonusScene();
 
  function addHotkey(callback) {
    document.addEventListener("keydown", (event) => {
      if (event.code === HOTKEY) {
        event.preventDefault();
        if (timeoutID === null) {
          console.log("start auto running");
          timeoutID = setTimeout(callback, CHECK_INTERVAL);
        } else {
          console.log("stop auto running");
          timeoutID = null;
        }
      }
    });
  }
 
  function checkNext() {
    console.log("checkNext");
    if (MAX_STEP !== null && bonus.step >= MAX_STEP) {
      console.log("max step reached");
      timeoutID = null;
      return;
    }
    let reward = bonus.resultArray[4];
    let reward_str = `${reward.type}_${reward.name}_${reward.index}_${reward.value}`;
    console.debug("reward", reward_str);
    if (!SKIP_ITEMS.some((p) => reward_str.startsWith(p))) {
      console.log("item not in skip list", reward_str);
      timeoutID = null;
      return;
    }
    bonus.btn_next.emit("pointerup");
  }
 
  function checkHL() {
    console.log("checkHL");
    bonus.dice_tmp = bonus.rand;
    if (bonus.dice_tmp > 7) {
      bonus.btn_low.emit("pointerup");
    } else if (bonus.dice_tmp < 7) {
      bonus.btn_high.emit("pointerup");
    } else {
      if (Math.floor(Math.random() * 2) === 0) {
        bonus.btn_low.emit("pointerup");
      } else {
        bonus.btn_high.emit("pointerup");
      }
    }
  }
 
  async function checkBattleItem() {
    console.log("checkBattleItem");
    await sleep(2100 * BATTLE_ITEM_DELAY_SCALE);
    bonus.btn_item.emit("pointerup");
    bonus.item_list.item_select = {
      index: null,
      type: "battle",
    };
    await sleep(500 * BATTLE_ITEM_DELAY_SCALE);
    const diff = Math.abs(bonus.rand - bonus.dice_tmp);
 
    function useBattleItem(i) {
      bonus.item_list.item_select.index = i;
      bonus.item_list.item_use.setVisible();
      bonus.item_list.item_use.emit("pointerdown");
      console.log(`use item ${i}`);
    }
    if (diff <= 1 && BATTLE_ITEMS.includes(0)) {
      useBattleItem(0);
    } else if (1 < diff && diff <= 3 && BATTLE_ITEMS.includes(1)) {
      useBattleItem(1);
    } else if (3 < diff && BATTLE_ITEMS.includes(2)) {
      useBattleItem(2);
    } else if (3 < diff && BATTLE_ITEMS.includes(3)) {
      useBattleItem(3);
    } else if (3 < diff && BATTLE_ITEMS.includes(4)) {
      useBattleItem(4);
    } else {
      console.log("no battle item to use");
    }
  }
 
  async function checkBtn() {
    if (timeoutID === null) {
      console.log("stop auto running");
      readyStates.next = readyStates.highlow = readyStates.item = 1;
      return;
    }
    if (
      bonus.btn_next.input.enabled &&
      bonus.btn_next.visible &&
      readyStates.next === 1
    ) {
      readyStates.next = readyStates.highlow = readyStates.item = 0;
      checkNext();
      readyStates.highlow = 1;
    } else if (
      bonus.btn_high.input.enabled &&
      bonus.btn_low.input.enabled &&
      bonus.btn_high.visible &&
      bonus.btn_low.visible &&
      readyStates.highlow === 1
    ) {
      readyStates.next = readyStates.highlow = readyStates.item = 0;
      checkHL();
      readyStates.next = readyStates.item = 1;
    } else if (
      bonus.btn_item.input?.enabled &&
      bonus.btn_item.visible &&
      readyStates.item === 1
    ) {
      readyStates.next = readyStates.highlow = readyStates.item = 0;
      await checkBattleItem();
      readyStates.next = 1;
    }
    if (timeoutID === null) {
      console.log("stop auto running");
      readyStates.next = readyStates.highlow = readyStates.item = 1;
      return;
    }
    timeoutID = setTimeout(checkBtn, CHECK_INTERVAL);
  }
  addHotkey(checkBtn);
})();