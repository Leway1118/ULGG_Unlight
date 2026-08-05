(function () {
  "use strict";
  var CFG = JSON.parse("{\"bundles\":[\"client/runtime.0e89562b7d68d7d0099f.js\",\"client/unlight-common.bbd5306bbd9422708666.js\",\"client/main.42c288379d1657883b4c.js\"],\"canvasId\":\"myCustomCanvas\",\"canvasWidth\":760,\"canvasHeight\":680,\"stylesheet\":\"stylesheets/style-steam.css\",\"title\":\"UNLIGHT:Revive\",\"portMin\":14012,\"portMax\":14021,\"allowPortRedirect\":true}");
  var FLAG = "__ulrBootShell";

  // 注入會在這個 target 的每個 document 跑，導向之後也會再跑一次。
  // 沒有這道閘就會重複建 canvas、重複注入 bundle。
  if (window[FLAG]) return;

  var params = new URLSearchParams(location.search);
  var authString = params.get("steamid") || params.get("stove_id");

  // 不是遊戲入口就別碰。注入掛在整個 target 上，玩家在同一個瀏覽器開的
  // 其他分頁也會跑到這段。
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
    return document.querySelector('script[src*="' + CFG.bundles[0] + '"]') !== null ||
           document.querySelector('script[src*="/client/"]') !== null;
  }

  function loadNext(i) {
    if (i >= CFG.bundles.length) return;
    var s = document.createElement("script");
    s.src = CFG.bundles[i];
    s.onload = function () { loadNext(i + 1); };
    s.onerror = function () {
      // 幾乎都是同一個原因：遊戲改版了，webpack 的 content hash 變了。
      // 講清楚，不然使用者只會看到一片黑畫面。
      var msg = document.createElement("div");
      msg.style.cssText = "color:#f66;font:14px/1.6 monospace;padding:16px";
      msg.textContent =
        "載入失敗：" + CFG.bundles[i] + "\n" +
        "遊戲很可能改版了，bundle 檔名已經不同。請從 Steam 正常開一次遊戲，" +
        "讓 companion 重新讀取檔名（companion web --refresh）。";
      msg.style.whiteSpace = "pre-wrap";
      document.body.appendChild(msg);
    };
    document.head.appendChild(s);
  }

  function rebuild() {
    if (alreadyServed()) return;

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