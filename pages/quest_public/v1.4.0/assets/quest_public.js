/* ============================================================
   UL.GG Quest Public Analytics V1.4 - Phase 2A staging script
   Progressive enhancement only.

   Rules kept by this file:
     * no statistic is computed here (numbers come from the JSON);
     * no innerHTML / insertAdjacentHTML / document.write: every
       dynamic value is written with textContent;
     * nothing is read from the URL except location.href, which the
       user explicitly copies.
   ============================================================ */
(function () {
  "use strict";

  /** Submit a select as soon as it changes (the form still works without JS). */
  function initAutoSubmit() {
    var selects = document.querySelectorAll("[data-qpub-autosubmit]");
    Array.prototype.forEach.call(selects, function (select) {
      select.addEventListener("change", function () {
        var form = select.form;
        if (!form) {
          return;
        }
        if (typeof form.requestSubmit === "function") {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
    });
  }

  /** Copy the current shareable URL. */
  function initCopy() {
    var button = document.querySelector("[data-qpub-copy]");
    var status = document.getElementById("qpub-copy-status");
    if (!button || !status) {
      return;
    }
    button.addEventListener("click", function () {
      var url = window.location.href;
      var done = function (ok) {
        status.textContent = ok ? "已複製連結" : "無法自動複製，請手動複製網址";
        window.setTimeout(function () {
          status.textContent = "";
        }, 4000);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(
          function () {
            done(true);
          },
          function () {
            done(false);
          }
        );
        return;
      }
      var probe = document.createElement("input");
      probe.type = "text";
      probe.value = url;
      probe.setAttribute("readonly", "readonly");
      probe.style.position = "fixed";
      probe.style.opacity = "0";
      document.body.appendChild(probe);
      probe.select();
      var ok = false;
      try {
        ok = document.execCommand("copy");
      } catch (err) {
        ok = false;
      }
      document.body.removeChild(probe);
      done(ok);
    });
  }

  /** Star matrix: tap/click/focus a cell to read its detail line (mobile has no hover). */
  function initHeatmap() {
    var cells = document.querySelectorAll(".qpub-heat");
    if (cells.length === 0) {
      return;
    }
    var panel = null;
    var show = function (cell) {
      if (!panel) {
        panel = document.createElement("div");
        panel.className = "qpub-heat-detail";
        panel.id = "qpub-heat-detail";
        panel.setAttribute("role", "status");
        panel.setAttribute("aria-live", "polite");
        var table = cell.closest("table");
        if (table && table.parentNode) {
          table.parentNode.insertBefore(panel, table.nextSibling);
        } else {
          document.body.appendChild(panel);
        }
      }
      var star = cell.getAttribute("data-qpub-star") || "";
      var estimated = cell.getAttribute("data-qpub-estimated") || "";
      var low = cell.getAttribute("data-qpub-ci-low") || "";
      var high = cell.getAttribute("data-qpub-ci-high") || "";
      var observed = cell.getAttribute("data-qpub-observed") || "";
      var count = cell.getAttribute("data-qpub-count") || "";
      var rowHead = cell.parentNode ? cell.parentNode.querySelector("th") : null;
      var duration = rowHead ? rowHead.textContent.replace(/\s+/g, " ").trim() : "";
      panel.textContent =
        "duration " + duration +
        " ／ ★" + star +
        "：估計 " + estimated + "%" +
        "｜CI " + low + "–" + high + "%" +
        "｜觀測 " + observed + "%" +
        "｜樣本 n " + count;
    };
    Array.prototype.forEach.call(cells, function (cell) {
      cell.addEventListener("click", function () {
        show(cell);
      });
      cell.addEventListener("focus", function () {
        show(cell);
      });
    });
  }

  /** Keep aria-expanded on the sidebar toggle in sync (main.js handles the class). */
  function initSidebarAria() {
    var toggle = document.querySelector(".nav-toggle");
    if (!toggle) {
      return;
    }
    var sync = function () {
      toggle.setAttribute(
        "aria-expanded",
        document.body.classList.contains("sidebar-open") ? "true" : "false"
      );
    };
    toggle.addEventListener("click", sync);
    var overlay = document.querySelector(".un-overlay");
    if (overlay) {
      overlay.addEventListener("click", sync);
    }
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        sync();
      }
    });
    sync();
  }

  document.addEventListener("DOMContentLoaded", function () {
    initAutoSubmit();
    initCopy();
    initHeatmap();
    initSidebarAria();
  });
})();
