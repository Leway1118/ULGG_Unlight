"use strict";
/* M2 synthetic-only UI. Never injects raw HTML, Steam IDs, or URL player params. */
(() => {
  const cards = [...document.querySelectorAll(".slide")];
  const app = document.getElementById("app");
  const prev = document.getElementById("prev");
  const next = document.getElementById("next");
  const skip = document.getElementById("skip");
  const count = document.getElementById("page-count");
  const state = document.getElementById("live-state");
  let current = 0;
  let showingAll = false;
  const set = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value == null ? "UNKNOWN" : String(value);
  };
  function show(index) {
    current = Math.max(0, Math.min(cards.length - 1, index));
    if (showingAll) return;
    cards.forEach((node, i) => { node.hidden = i !== current; });
    prev.disabled = current === 0;
    next.disabled = current === cards.length - 1;
    count.textContent = String(current + 1) + " / " + String(cards.length);
  }
  prev.addEventListener("click", () => show(current - 1));
  next.addEventListener("click", () => show(current + 1));
  skip.addEventListener("click", () => {
    showingAll = !showingAll;
    app.classList.toggle("all-visible", showingAll);
    cards.forEach((node, i) => { node.hidden = !showingAll && i !== current; });
    prev.disabled = showingAll || current === 0;
    next.disabled = showingAll || current === cards.length - 1;
    skip.textContent = showingAll ? "返回分頁模式" : "略過動畫／顯示摘要";
    count.textContent = showingAll ? "全部摘要" : String(current + 1) + " / " + String(cards.length);
  });
  document.addEventListener("keydown", (event) => {
    if (showingAll || event.altKey || event.ctrlKey || event.metaKey) return;
    if (event.key === "ArrowRight") show(current + 1);
    if (event.key === "ArrowLeft") show(current - 1);
  });
  function renderCup(data) {
    if (!data) return;
    const cup = data.payload && data.payload.cup;
    if (!cup) return;
    set("cup-games", cup.verified_games);
    set("personal-cup", "UNKNOWN");
    const ul = document.getElementById("cup-stories");
    ul.replaceChildren();
    for (const report of (cup.reports || []).slice(0, 6)) {
      const li = document.createElement("li");
      li.textContent = report.title || "已發布賽事報告";
      ul.appendChild(li);
    }
    if (!ul.children.length) {
      const li = document.createElement("li");
      li.textContent = "尚無已驗證的公開報告";
      ul.appendChild(li);
    }
  }
  function renderPersonal(data) {
    const pvp = data.payload && data.payload.pvp;
    if (!pvp) return;
    set("matches", pvp.verified_matches);
    set("wins", pvp.wins);
    set("losses", pvp.losses);
    set("draws", pvp.draws);
    set("pvp-quality", pvp.status);
    set("character-count", Array.isArray(pvp.top_characters) ? pvp.top_characters.length : null);
    const ol = document.getElementById("characters");
    ol.replaceChildren();
    for (const character of (pvp.top_characters || []).slice(0, 3)) {
      const li = document.createElement("li");
      li.textContent = "角色 #" + character.char_id + " · " + character.usage + " 場";
      ol.appendChild(li);
    }
    if (!ol.children.length) {
      const li = document.createElement("li");
      li.textContent = "角色來源尚未驗證";
      ol.appendChild(li);
    }
    const primary = (pvp.top_teams || [])[0];
    set("team", primary ? primary.team_key : null);
    set("team-matches", primary ? "共同出場 " + primary.matches + " 場" : "隊伍來源尚未驗證");
    set("active-months", pvp.active_months == null ? null : "活躍月份：" + pvp.active_months);
    const months = document.getElementById("months");
    months.replaceChildren();
    for (const [month, summary] of Object.entries(pvp.monthly_trend || {})) {
      const span = document.createElement("span");
      span.textContent = month.slice(5) + "月 " + (summary.matches == null ? "—" : summary.matches);
      span.title = month + "｜" + summary.status;
      months.appendChild(span);
    }
    state.textContent = "私人 PvP 已通過合成資料驗證；個人 CUP／Raid 仍為 UNKNOWN。";
  }
  async function getJson(url) {
    const response = await fetch(url, {
      method: "GET", credentials: "same-origin", cache: "no-store",
      headers: { Accept: "application/json" }
    });
    if (!response.ok) return { status: response.status };
    return { status: response.status, data: await response.json() };
  }
  Promise.all([
    getJson("/api/checkpoint2026/public.php"),
    getJson("/api/checkpoint2026/me.php")
  ]).then(([publicData, privateData]) => {
    if (publicData.status === 200) renderCup(publicData.data);
    if (privateData.status === 200) {
      renderPersonal(privateData.data);
    } else {
      state.textContent = "登入後才能查看個人 PvP；目前僅顯示合成公開 CUP。";
      set("pvp-quality", "UNKNOWN");
    }
  }).catch(() => {
    state.textContent = "資料取得失敗；請確認離線 Staging 是否已啟動。";
  });
  show(0);
})();
