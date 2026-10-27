<?php
/**
 * Shared public footer for UL.GG main site and Quest public pages.
 *
 * Required variables:
 *   $supportUrl (string) - href for the support button.
 *                          Main site: '#' (JS opens the inline modal).
 *                          Quest:     '/pages/index.php#supportModal' (link to main site modal).
 *
 * Optional variables:
 *   $supportLabel    (string)
 *   $supportSubLabel (string)
 */
declare(strict_types=1);

if (!function_exists('footer_h')) {
    function footer_h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

$supportUrl      = $supportUrl ?? '#';
$supportLabel    = $supportLabel ?? '💜 支持 UL.GG';
$supportSubLabel = $supportSubLabel ?? '街口支付';
?>
<footer class="un-footer">
  <div class="footer-inner container">

    <div class="footer-support">
      <a class="open-support-modal report-btn"<?php if ($supportUrl !== '#'): ?> href="<?= footer_h($supportUrl) ?>"<?php endif; ?>>
        <?= $supportLabel ?>
        <span><?= footer_h($supportSubLabel) ?></span>
      </a>

      <a class="report-btn"
         href="https://www.buymeacoffee.com/ulgg"
         target="_blank"
         rel="noopener">
        ☕ Buy Me a Coffee
        <span>信用卡</span>
      </a>
    </div>

    <div class="footer-report">
      <a href="mailto:ulgg.online@gmail.com?subject=UL.GG 問題回報">回報問題</a>
      -
      <a href="/pages/about.php" class="footer-link">關於本站</a>
      -
      <a href="/pages/privacy.php" class="footer-link">隱私權政策</a>
    </div>

    <div class="footer-copy">
      © <?= date('Y') ?> UL.GG • Unlight Fan-made Statistics
    </div>

  </div>
</footer>
