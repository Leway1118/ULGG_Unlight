(function () {
  "use strict";
  var CFG = JSON.parse("{\"canvasId\":\"myCustomCanvas\",\"canvasWidth\":760,\"canvasHeight\":680,\"stylesheet\":\"stylesheets/style-steam.css\",\"title\":\"UNLIGHT:Revive\",\"portMin\":14012,\"portMax\":14021,\"allowPortRedirect\":true}");
  var BUNDLES = JSON.parse(document.documentElement.dataset.ulrBundles || "[]");
  var FLAG = "__ulrBootShell";

  // 注入會在這個 target 的每個 document 跑，導向之後也會再跑一次。
  // 沒有這道閘就會重複建 canvas、重複注入 bundle。
  if (window[FLAG]) return;

  var params = new URLSearchParams(location.search);
  // 網址沒帶身分時退回擴充功能記下來的那個，這樣書籤不必寫死 steamid。
  var authString =
    params.get("steamid") ||
    params.get("stove_id") ||
    document.documentElement.dataset.ulrAuth ||
    "";

  // 兩邊都沒有就別碰這個頁面。
  if (!authString) return;

  window[FLAG] = { rebuilt: false };

  // 遊戲實際跑在 :14012~14021 的其中一個 port，沒帶 port 的網址會拿到 403。
  if (location.port === "" && CFG.allowPortRedirect) {
    var span = CFG.portMax - CFG.portMin + 1;
    var port = CFG.portMin + Math.trunc(Math.random() * span);
    location.href =
      location.protocol + "//" + location.hostname + ":" + port +
      location.pathname + location.search + location.hash;
    return;
  }

  window.SERVER = "steam";
  window.platform_type = location.pathname.indexOf("stove") !== -1 ? "stove" : "steam";
  window.auth_string = authString;

  function alreadyServed() {
    // 伺服器有正常吐頁面的時候（正常 Steam 流程），文件裡本來就有 client/ 的
    // script。這時候動手會把已經載好的遊戲砍掉重來。
    return document.querySelector('script[src*="' + BUNDLES[0] + '"]') !== null ||
           document.querySelector('script[src*="/client/"]') !== null;
  }

  function loadNext(i) {
    if (i >= BUNDLES.length) return;
    var s = document.createElement("script");
    s.src = BUNDLES[i];
    s.onload = function () { loadNext(i + 1); };
    s.onerror = function () {
      // ⚠ 這裡**不要**宣稱「遊戲改版了」。onerror 只在檔案真的不存在時觸發，
      // 而伺服器會保留舊的 bundle（社群實測），所以改版後最可能的結果是
      // 靜默跑在舊版，根本不會走到這裡。詳見檔案開頭的說明。
      var msg = document.createElement("div");
      msg.style.cssText = "color:#f66;font:14px/1.6 monospace;padding:16px";
      msg.style.whiteSpace = "pre-wrap";
      msg.textContent = "載入失敗：" + BUNDLES[i] + "\n請重新同步 bundle 檔名後再試。";
      document.body.appendChild(msg);
    };
    document.head.appendChild(s);
  }

  function rebuild() {
    if (alreadyServed()) return;
    // dataset 模式下檔名是執行期給的。給不出來就什麼都別做 —— 清空 body 卻又
    // 沒有東西可載，只會留下一片白畫面，比原本的 403 更難懂。
    if (BUNDLES.length === 0) return;

    document.body.textContent = "";
    document.title = CFG.title;

    var css = document.createElement("link");
    css.rel = "stylesheet";
    css.href = CFG.stylesheet;
    document.head.appendChild(css);

    var canvas = document.createElement("canvas");
    canvas.id = CFG.canvasId;
    canvas.width = CFG.canvasWidth;
    canvas.height = CFG.canvasHeight;
    document.body.appendChild(canvas);

    window[FLAG].rebuilt = true;
    loadNext(0);
  }

  // document-start 時 body 還不存在。403 的錯誤頁也要等解析完才知道有沒有
  // client/ 的 script，所以一律等 DOMContentLoaded。
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", rebuild);
  } else {
    rebuild();
  }
})();