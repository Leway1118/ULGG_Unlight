<?php
/* ULGG-VAL-INFO-MODAL-V1. 2026-10-27 quest_value.py policy snapshot. */
declare(strict_types=1);
function ulggValInfoModal(string $content): string {
    $button='<button type="button" class="ulgg-val-help" data-ulgg-val-info aria-haspopup="dialog" aria-label="開啟 VAL 與 GEM 估值說明" title="VAL 估值說明">ⓘ</button>';
    $content=preg_replace_callback('~(<th\b[^>]*>)(.*?)(</th>)~si',static function($m)use($button){
        $label=trim((string)preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8')));
        if(!preg_match('~(?:^|\s)(?:平均\s+)?VAL(?:/h|/AP)?(?:\s*[↕▲▼▴▾↑↓])*\s*$~u',$label))return $m[0];
        return $m[1].$m[2].' '.$button.$m[3];
    },$content);
    if(!is_string($content))throw new RuntimeException('VAL HEAD ERROR');
    $content=preg_replace('~(>\s*最高 VAL 效率\s*)(</span>)~u','$1 '.$button.'$2',$content,1);
    if(!is_string($content))throw new RuntimeException('VAL CARD ERROR');
    $prices=[
      'GEM／碎片'=>[
        ['GEM','1 GEM＝1 VAL','直接按 GEM 數量'],
        ['記憶／時間／靈魂／生命／死亡碎片','750／個','500 × 稀有加權 1.5'],
      ],
      '道具'=>[
        ['白色石楠1／3／5','200／540／900','道具估值'],
        ['幸運四葉草','900','道具估值'],['精靈之藥','500','道具估值'],
        ['古代妙藥／魔女秘藥','1,350／2,500','道具估值'],
        ['抽獎券／複製券','500／300','道具估值'],
        ['跳越星','675','135 JPY × 5'],
      ],
      'Quest 消耗品'=>[
        ['時間沙漏／超時空沙漏','300／1,500','道具估值'],
        ['急救品1／3／5／10','100／250／500／750','道具估值'],
        ['不死靈藥／墨菲斯之門','1,500／1,500','道具估值'],
        ['夢幻曲之門','1,500','300 JPY × 5'],
        ['討魔的證明','7,500','1,500 JPY × 5'],
        ['妖魔的指南針','3,000','道具估值'],
      ],
      'EX 通行證（每張）'=>[
        ['影之大陸1／2／3','300／500／800','60／100／160 JPY × 5'],
        ['月之大陸1／2／3','400／600／900','80／120／180 JPY × 5'],
        ['安妮莫娜1／2／3','500／700／1,000','100／140／200 JPY × 5'],
        ['天使大陸1／2／3','600／800／1,100','120／160／220 JPY × 5'],
      ],
      '材料／渦'=>[
        ['鐵／銅／銀幣、M1／M2／M3 怪物卡','170','材料層級估值'],
        ['金幣／白金幣','250','材料估值'],
        ['β型渦探知機Ⅲ','1,500','300 JPY × 5'],
      ],
    ];
    ob_start(); ?>
<dialog id="ulggValDialog" class="ulgg-val-dialog" aria-labelledby="ulggValTitle" aria-describedby="ulggValDesc">
 <div class="ulgg-val-dialog__header"><h2 id="ulggValTitle">💎 VAL／GEM 估值說明</h2><form method="dialog"><button aria-label="關閉 VAL 視窗" type="submit">✕</button></form></div>
 <div class="ulgg-val-dialog__body">
  <p id="ulggValDesc"><strong>VAL = Quest 路線獎勵的 GEM 等值估算分數。</strong>VAL/h 是每小時估計獎勵價值；VAL/AP 是每點 AP 的估計價值。<strong>不是實際獲得的 GEM。</strong></p>
  <div class="ulgg-val-dialog__scroll"><table><thead><tr><th>獎勵</th><th>VAL</th><th>計算依據</th></tr></thead><tbody>
  <?php foreach($prices as $group=>$rows): ?><tr class="group"><th colspan="3"><?=htmlspecialchars($group,ENT_QUOTES,'UTF-8')?></th></tr>
  <?php foreach($rows as [$item,$value,$reason]): ?><tr><th scope="row"><?=htmlspecialchars($item,ENT_QUOTES,'UTF-8')?></th><td><?=htmlspecialchars($value,ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars($reason,ENT_QUOTES,'UTF-8')?></td></tr><?php endforeach; endforeach; ?>
  </tbody></table></div>
  <p class="note">政策基準：只有 JPY 標價的單項採 1 JPY = 5 VAL（內部假設），稀有碎片加權 1.5；混合包不拆價，未計價獎勵可能為 0。VAL 採最佳完整路線的估值，並非實際背包掉落或官方 GEM 兌換價格。</p>
  <p class="note">估值表依 2026-10-27 的 quest_value.py 規則整理；政策變動時須同步更新。</p>
 </div>
</dialog>
<style>
.ulgg-val-help{border:0;background:transparent;color:#4ca0ef;font:inherit;padding:0 .2em;cursor:pointer;font-weight:700}.ulgg-val-help:focus-visible{outline:2px solid currentColor;border-radius:3px}
dialog.ulgg-val-dialog{box-sizing:border-box;width:min(690px,calc(100vw - 22px));max-height:85vh;border:1px solid #cbd5e1;border-radius:12px;padding:0;color:#172033;background:#fff;box-shadow:0 18px 60px #0008}dialog.ulgg-val-dialog::backdrop{background:#000a}
.ulgg-val-dialog__header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #cbd5e1;position:sticky;top:0}.ulgg-val-dialog__header h2{color:#172033;margin:0;font-size:1.1rem}.ulgg-val-dialog__header button{cursor:pointer;border:1px solid #ccc;border-radius:6px;background:#fff;padding:4px 10px}
.ulgg-val-dialog__body{padding:14px;font-size:.92rem;line-height:1.6}.ulgg-val-dialog__body p{margin:0 0 10px;color:#334155}.ulgg-val-dialog__scroll{overflow:auto;max-height:48vh;border:1px solid #e2e8f0}
.ulgg-val-dialog table{width:100%;border-collapse:collapse;font-size:.88rem}.ulgg-val-dialog th,.ulgg-val-dialog td{padding:7px;text-align:left;border-bottom:1px solid #e2e8f0;color:#172033}.ulgg-val-dialog thead th{position:sticky;top:0;background:#e9eef5}.ulgg-val-dialog tr.group th{background:#f1f5f9}.ulgg-val-dialog td:nth-child(2){white-space:nowrap;font-weight:600}.ulgg-val-dialog__body .note{color:#64748b;font-size:.81rem;margin-top:9px}
</style>
<script>(()=>{const d=document.getElementById('ulggValDialog');if(!d)return;document.addEventListener('click',e=>{const b=e.target.closest('[data-ulgg-val-info]');if(!b)return;e.preventDefault();if(!d.open)d.showModal()});d.addEventListener('click',e=>{const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)d.close()})})();</script>
<?php
    return $content.(string)ob_get_clean();
}
