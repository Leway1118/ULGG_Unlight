const output = document.getElementById("output");
const transfer = document.getElementById("transfer");

async function readStorage() {
  const data = await chrome.storage.local.get(null);
  const record = data.ulrBundles;

  const result = {
    extensionId: chrome.runtime.id,
    allKeys: Object.keys(data),
    hasUlrBundles: record != null,
    bundleCount: Array.isArray(record?.bundles)
      ? record.bundles.length
      : 0,
    auth: record?.auth ?? null,
    discoveredAt: record?.discoveredAt ?? null,
    ulrBundlesSize: record
      ? JSON.stringify(record).length
      : 0
  };

  output.textContent = JSON.stringify(result, null, 2);
  return record;
}

document
  .getElementById("refreshBtn")
  .addEventListener("click", async () => {
    await readStorage();
  });

document
  .getElementById("exportBtn")
  .addEventListener("click", async () => {
    const record = await readStorage();

    if (!record) {
      alert("目前沒有 ulrBundles 可匯出。");
      return;
    }

    transfer.value = JSON.stringify(record, null, 2);
    transfer.focus();
    transfer.select();

    try {
      await navigator.clipboard.writeText(transfer.value);
      alert("已匯出並複製到剪貼簿。");
    } catch {
      alert("已匯出到文字框，請手動複製。");
    }
  });

document
  .getElementById("importBtn")
  .addEventListener("click", async () => {
    const raw = transfer.value.trim();

    if (!raw) {
      alert("請先貼上匯出的 JSON。");
      return;
    }

    let record;

    try {
      record = JSON.parse(raw);
    } catch (error) {
      alert(`JSON 格式錯誤：${error.message}`);
      return;
    }

    if (
      !record ||
      !Array.isArray(record.bundles) ||
      record.bundles.length === 0
    ) {
      alert("資料格式不正確：找不到 bundles。");
      return;
    }

    await chrome.storage.local.set({
      ulrBundles: record
    });

    await readStorage();
    alert("ulrBundles 匯入完成。");
  });

document
  .getElementById("clearBtn")
  .addEventListener("click", async () => {
    const confirmed = confirm(
      "確定要清除 ulrBundles 嗎？"
    );

    if (!confirmed) {
      return;
    }

    await chrome.storage.local.remove("ulrBundles");
    transfer.value = "";
    await readStorage();
  });

readStorage();