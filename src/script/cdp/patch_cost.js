(function () {
  "use strict";

  function waitPhaser(callback) {
    const intervalID = setInterval(() => {
      const JSONFile = window?.Phaser?.Loader?.FileTypes?.JSONFile?.prototype;
      if (JSONFile?.onProcess) {
        clearInterval(intervalID);
        callback(JSONFile);
      }
    }, 100);
  }

  function patchOnProcess(JSONFile) {
    const originalOnProcess = JSONFile.onProcess;
    JSONFile.onProcess = function (...args) {
      originalOnProcess.call(this, ...args);
      if (this.key !== "cc_asset") {
        return;
      }
      patchCostData(this.data);
    };
  }

  function patchCostData(data) {
    const costMap = __JSON_DATA__;
    for (const cc of data.frames) {
      const cost = costMap[cc.filename];

      if (cost !== undefined) {
        cc.cost = cost;
      }
    }
  }

  waitPhaser(patchOnProcess);
})();
