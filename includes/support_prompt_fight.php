<?php
/**
 * Service page support CTA (staging candidate).
 * Included ONLY from pages/fight.php after battle search results.
 * Does not collect personal identifiers or change any payment endpoint.
 */
?>
<style>
  .ulgg-support-cta {
    margin: 20px auto 16px;
    padding: 15px 17px;
    border: 1px solid rgba(153, 137, 222, .38);
    border-radius: 12px;
    background: #232433;
    color: #eef0fa;
    max-width: 860px;
    box-sizing: border-box;
    text-align: center;
  }
  .ulgg-support-cta__heading { margin: 0 0 5px; color: #eee7ff; font-size: 16px; font-weight: 700; }
  .ulgg-support-cta__description { margin: 0 0 12px; font-size: 13px; line-height: 1.7; color: #ccd0e0; }
  .ulgg-support-cta__actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
  .ulgg-support-cta__button {
    box-sizing: border-box;
    min-width: 0;
    max-width: 100%;
    white-space: normal;
    overflow-wrap: anywhere;
    text-align: center;
    display: inline-flex; align-items: center; justify-content: center;
    min-height: 38px; padding: 8px 13px; border-radius: 8px;
    border: 1px solid #77709e; background: #353052;
    color: #f8f6ff; font-size: 13px; font-weight: 600;
    line-height: 1.4; text-decoration: none; cursor: pointer;
  }
  .ulgg-support-cta__button--secondary { background: #2a3042; border-color: #596b8b; }
  .ulgg-support-cta__button:focus-visible { outline: 3px solid #cfc2ff; outline-offset: 2px; }
  .ulgg-support-cta__button:hover { color: white; background: #48406b; text-decoration: none; }
  @media (max-width: 560px) {
    .ulgg-support-cta { padding: 12px 10px; margin: 16px 5px; }
    .ulgg-support-cta__actions { flex-direction: column; }
    .ulgg-support-cta__button { width: 100%; }
  }
</style>
<section id="ulgg-fight-support-cta" class="ulgg-support-cta" aria-label="支持 UL.GG">
  <h2 class="ulgg-support-cta__heading">💜 喜歡 UL.GG 的戰績分析嗎？</h2>
  <p class="ulgg-support-cta__description">
    UL.GG 是由玩家自發維護的免費非官方工具。如果本站對你有幫助，歡迎自願支持主機與功能維護。
  </p>
  <div class="ulgg-support-cta__actions">
    <button type="button"
            class="open-support-modal ulgg-support-cta__button"
            data-support-channel="tw">💜 街口支付</button>
    <a class="ulgg-support-cta__button ulgg-support-cta__button--secondary"
       data-support-channel="bmc"
       href="https://www.buymeacoffee.com/ulgg"
       target="_blank" rel="noopener noreferrer">☕ Buy Me a Coffee</a>
  </div>
</section>
<script>
(() => {
  'use strict';
  const card = document.getElementById('ulgg-fight-support-cta');
  if (!card) return;
  const endpoint = '/api/support_event.php';
  const events = new Set(['impression', 'click_tw', 'click_bmc']);
  const emit = (event) => {
    if (!events.has(event)) return;
    const url = `${endpoint}?e=${event}&src=fight`;
    // Same-origin, anonymous, no cookies and no full fight-page referer.
    // POST gets reflected ONLY in the existing web-server access log.
    fetch(url, {
      method: 'POST', mode: 'same-origin', credentials: 'omit',
      referrerPolicy: 'no-referrer', cache: 'no-store', keepalive: true
    }).catch(() => {});
  };
  let impressed = false;
  if ('IntersectionObserver' in window) {
    const obs = new IntersectionObserver(entries => {
      if (!impressed && entries.some(e => e.isIntersecting && e.intersectionRatio >= 0.5)) {
        impressed = true;
        emit('impression');
        obs.disconnect();
      }
    }, {threshold: [0.5]});
    obs.observe(card);
  }
  card.querySelectorAll('[data-support-channel]').forEach(el => {
    el.addEventListener('click', () => {
      emit(el.dataset.supportChannel === 'tw' ? 'click_tw' : 'click_bmc');
    });
  });
})();
</script>
