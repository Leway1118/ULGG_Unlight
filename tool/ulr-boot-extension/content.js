(function () {
  "use strict";
  var CFG = {"storageKey":"ulrBundles","datasetKey":"ulrBundles","authDatasetKey":"ulrAuth","bundleDir":"client/","deployWeekday":2,"deployUtcHour":2,"steamUrl":"steam://rungameid/3247080","waitAfterLaunchMs":90000};

  function currentBundles() {
    var out = [];
    var scripts = document.scripts;
    for (var i = 0; i < scripts.length; i++) {
      var src = scripts[i].src;
      if (!src) continue;
      var u;
      try { u = new URL(src); } catch (e) { continue; }
      if (u.origin !== location.origin) continue;
      var p = u.pathname.replace(/^\//, "");
      if (p.indexOf(CFG.bundleDir) !== 0) continue;
      out.push(p);
    }
    return out;
  }

  /** 最近一次「應該已經部署」的時間點。 */
  function lastDeploy(now) {
    var d = new Date(now.getTime());
    d.setUTCHours(CFG.deployUtcHour, 5, 0, 0);
    while (d.getUTCDay() !== CFG.deployWeekday || d.getTime() > now.getTime()) {
      d.setUTCDate(d.getUTCDate() - 1);
      d.setUTCHours(CFG.deployUtcHour, 5, 0, 0);
    }
    return d;
  }

  /**
   * 黃條。可以帶一顆「開啟 Steam」按鈕。
   *
   * ⚠ 為什麼一定要按鈕、不能自動導向 steam://：瀏覽器對外部協定強制要求
   * user gesture，這是防止網頁隨意啟動本機程式的安全機制，擴充功能沒有豁免。
   * 沒有點擊的話 Chrome 會直接吞掉，而且不會有任何錯誤 —— 那比要求點一下更糟。
   */
  function notice(text, withButton) {
    var el = document.createElement("div");
    el.style.cssText =
      "position:fixed;left:0;right:0;top:0;z-index:2147483647;padding:8px 12px;" +
      "background:#3a2a00;color:#ffd479;font:13px/1.5 sans-serif;white-space:pre-wrap";
    var span = document.createElement("span");
    span.textContent = text;
    el.appendChild(span);

    if (withButton) {
      var btn = document.createElement("button");
      btn.textContent = "開啟 Steam 更新";
      btn.style.cssText =
        "margin-left:12px;padding:3px 10px;border:1px solid #ffd479;border-radius:4px;" +
        "background:transparent;color:#ffd479;font:inherit;cursor:pointer";
      btn.addEventListener("click", function () {
        btn.disabled = true;
        btn.textContent = "已送出，等遊戲載入…";
        location.href = CFG.steamUrl;
        setTimeout(function () {
          span.textContent =
            "等不到更新。若你目前啟用的是桌面版客戶端（遊戲開在自己的視窗、" +
            "不經過瀏覽器），這條路就收不到檔案清單 —— 要先換成網頁版客戶端。";
          btn.disabled = false;
          btn.textContent = "再試一次";
        }, CFG.waitAfterLaunchMs);
      });
      el.appendChild(btn);
    }
    document.body.appendChild(el);
  }

  function ready(fn) {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", fn);
    else fn();
  }

  ready(function () {
    var params = new URLSearchParams(location.search);
    var urlAuth = params.get("steamid") || params.get("stove_id") || "";

    var served = currentBundles();
    if (served.length > 0) {
      // 伺服器給了真的頁面 —— 這是唯一可靠的真相來源，記下來就好，不要動畫面。
      // 身分也一起記：這樣書籤不必寫死 steamid，換一台機器、換個人都能用。
      var rec = {};
      rec[CFG.storageKey] = {
        bundles: served,
        auth: urlAuth,
        discoveredAt: new Date().toISOString()
      };
      chrome.storage.local.set(rec);
      return;
    }

    chrome.storage.local.get(CFG.storageKey, function (data) {
      var rec = data && data[CFG.storageKey];
      var known = rec && rec.bundles ? rec.bundles.join(",") : "";

      // 按了按鈕之後，遊戲會在另一個分頁載入、由那邊的 content script 記下
      // 新檔名。這裡等 storage 一變就自己重載 —— 使用者不必回來手動 F5。
      chrome.storage.onChanged.addListener(function (changes, area) {
        if (area !== "local" || !changes[CFG.storageKey]) return;
        var next = changes[CFG.storageKey].newValue;
        if (!next || !next.bundles) return;
        if (next.bundles.join(",") === known) return;
        location.reload();
      });

      var auth = urlAuth || (rec && rec.auth) || "";

      if (!rec || !rec.bundles || rec.bundles.length === 0 || !auth) {
        notice(
          "ULR Boot：還沒有這個版本的檔案清單。需要從 Steam 開一次遊戲來取得。",
          true
        );
        return;
      }

      if (rec.discoveredAt && new Date(rec.discoveredAt) < lastDeploy(new Date())) {
        // 措辭要保守：我們無法驗證是否過期，只知道記錄的時間早於上一次
        // 例行部署時間。舊 bundle 伺服器不會刪，所以真的過期也不會報錯。
        notice(
          "ULR Boot：這份檔案清單記錄於上一次例行更新之前，可能是舊版。" +
          "（無法確認 —— 舊版檔案伺服器仍會供應。）",
          true
        );
      }

      document.documentElement.dataset[CFG.datasetKey] = JSON.stringify(rec.bundles);
      document.documentElement.dataset[CFG.authDatasetKey] = auth;
      var s = document.createElement("script");
      s.src = chrome.runtime.getURL("shell.js");
      s.onload = function () { s.remove(); };
      (document.head || document.documentElement).appendChild(s);
    });
  });
})();