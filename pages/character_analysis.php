<?php
declare(strict_types=1);
// Public Character Meta Analysis — aggregate statistics, read-only.
require_once __DIR__ . '/../config.php';
header('Cache-Control: private, no-store, max-age=0');
$pdo = $db;
$activeMenu = 'character_analysis';
$seoTitle = '角色分析總覽 Character Analysis | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = '角色分析總覽 Character Analysis | UL.GG 戰績網';
$pageTitleText = '角色分析總覽';
function cbLoadWeeklyRule(PDO $pdo, ?DateTimeImmutable $now = null): ?array
{
  $tz = new DateTimeZone('Asia/Taipei');
  $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
  // Rules take effect after the announced 10:00 maintenance.
  $asOf = $now->format('H:i') < '10:00' ? $now->modify('-1 day') : $now;
  try {
    $stmt = $pdo->prepare("SELECT id, effective_date, cost1, cost2, cost3, cost4, cost4_operator
      FROM quickmatch_weekly_cost
      WHERE ladder_zone_code = 'ALEXANDRE' AND import_status = 'APPROVED'
        AND matchmaking_enabled = 1
        AND effective_date <= :as_of
      ORDER BY effective_date DESC, id DESC LIMIT 1");
    $stmt->execute([':as_of' => $asOf->format('Y-m-d')]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rule || $rule['effective_date'] < $asOf->modify('-6 days')->format('Y-m-d')) return null;
    $costs = [];
    for ($i = 1; $i <= 4; $i++) {
      $cost = filter_var($rule['cost'.$i], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>999]]);
      if ($cost === false) return null;
      $costs[] = $cost;
      $rule['cost'.$i] = $cost;
    }
    $operator = strtoupper((string)$rule['cost4_operator']);
    if (!in_array($operator, ['EXACT','MIN'], true) || count(array_unique($costs)) !== 4) return null;
    if ($operator === 'MIN' && $costs[3] <= max(array_slice($costs, 0, 3))) return null;
    $rule['cost4_operator'] = $operator;
    return $rule;
  } catch (Throwable $e) {
    error_log('character_analysis: weekly COST unavailable');
    return null;
  }
}

function cbH($x): string { return htmlspecialchars((string)$x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cbInt(string $key, array $allowed, int $default): int {
    $v = $_GET[$key] ?? null;
    return is_scalar($v) && ctype_digit((string)$v) && in_array((int)$v,$allowed,true) ? (int)$v : $default;
}
function cbRate($v): string { return $v === null ? '—' : number_format((float)$v,1).'%'; }
function cbDelta($v): string { return $v === null ? '—' : sprintf('%+.1f pp',(float)$v); }
function cbQuery(PDO $pdo, string $sql): array { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
function cbUrl(array $changes=[]): string {
    global $filters; return '?'.http_build_query(array_replace($filters,$changes));
}
$days=cbInt('days',[7,14,30,90],14);
$min=cbInt('min',[10,30,60,100,200],60);
$bp=cbInt('bp',[0,1499,1500,1600,1700],0);
$cost=cbInt('cost',[0,50,60,70,90,201,202,203,204],0);
$selected=filter_var($_GET['char']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000]]) ?: 0;
$filters=compact('days','min','bp','cost','selected'); unset($filters['selected']); $filters['char']=$selected;
$rows=$levels=$mates=$combos=$opponents=[]; $trends=[]; $distribution=[]; $teams=0; $failure=false; $weekly=null;
try {
    // Reuse the approved weekly rule loader extracted from the existing ranking candidate.
    $weekly=cbLoadWeeklyRule($pdo);
    $costWhere=match($cost){50=>'AND cost BETWEEN 50 AND 59',60=>'AND cost BETWEEN 60 AND 69',70=>'AND cost BETWEEN 70 AND 80',90=>'AND cost >= 90',default=>''};
    // BP ranges are disjoint; only positive known ratings are included when filtering.
    $bpClause=static fn(string $col):string=>match($bp){
      1499=>"AND $col > 0 AND $col < 1500",
      1500=>"AND $col >= 1500 AND $col < 1600",
      1600=>"AND $col >= 1600 AND $col < 1700",
      1700=>"AND $col >= 1700",default=>''};
    $bp1=$bpClause('bp_p1'); $bp2=$bpClause('bp_p2');
    $weeklyWhere='';
    if ($cost>=201) {
        if (!$weekly) $weeklyWhere='AND 1=0';
        else {
            $i=$cost-200; $n=(int)$weekly['cost'.$i];
            $op=$i===4 && $weekly['cost4_operator']==='MIN' ? '>=' : '=';
            $weeklyWhere="AND t.cost $op $n AND (k1.cost+k2.cost+k3.cost) $op $n";
        }
    }
    $twice=$days*2;
    // Same valid match/result orientation as public character_analysis.php.
    // Use separate adjacent equal windows (not overlapping periods).
    $cte="WITH raw_matches AS (
      SELECT id, e1,e2,e3,u1,u2,u3,win,lose,tie,cost,bp_p1,bp_p2,
        FLOOR(TIMESTAMPDIFF(SECOND,update_time,NOW())/86400) day_bucket,
        CASE WHEN update_time >= DATE_SUB(NOW(),INTERVAL {$days} DAY) THEN 1 ELSE 0 END AS recent
      FROM arena_unlight WHERE ack1=1 AND ack2=1 AND (win+lose+tie)=1
        AND update_time <= NOW() AND update_time >= DATE_SUB(NOW(),INTERVAL {$twice} DAY) $costWhere
    ), raw_teams AS (
      SELECT id,1 side,e1 c1,e2 c2,e3 c3,u1 o1,u2 o2,u3 o3,cost,recent,day_bucket,
        CASE WHEN lose=1 THEN 1 WHEN win=1 THEN 0 ELSE NULL END is_win FROM raw_matches WHERE 1=1 $bp1
      UNION ALL
      SELECT id,2,u1,u2,u3,e1,e2,e3,cost,recent,day_bucket,
        CASE WHEN win=1 THEN 1 WHEN lose=1 THEN 0 ELSE NULL END FROM raw_matches WHERE 1=1 $bp2
    ), teams AS (
      SELECT t.* FROM raw_teams t
      LEFT JOIN unlight k1 ON k1.id=t.c1 LEFT JOIN unlight k2 ON k2.id=t.c2 LEFT JOIN unlight k3 ON k3.id=t.c3
      WHERE 1=1 $weeklyWhere
    ), slots AS (
      SELECT id,side,recent,day_bucket,is_win,c1 cid FROM teams UNION ALL
      SELECT id,side,recent,day_bucket,is_win,c2 FROM teams UNION ALL
      SELECT id,side,recent,day_bucket,is_win,c3 FROM teams
    ), characters AS (
      SELECT DISTINCT id,side,recent,day_bucket,is_win,FLOOR((cid-1)/10)*10+1 base_id FROM slots WHERE cid>0
    ), totals AS (SELECT recent,COUNT(*) n FROM teams GROUP BY recent),
    stats AS (
      SELECT base_id,recent,COUNT(*) n,COUNT(is_win) dec_n,AVG(is_win)*100 wr
      FROM characters GROUP BY base_id,recent
    )";
    $rows=cbQuery($pdo,$cte." SELECT u.id,u.name,u.ico,u.stand_ico,s.n,s.dec_n,s.wr,
      s.n*100/NULLIF(t.n,0) pick,t.n total,
      p.dec_n prev_n,p.wr prev_wr,p.n*100/NULLIF(pt.n,0) prev_pick
      FROM stats s JOIN unlight u ON u.id=s.base_id
      JOIN totals t ON t.recent=1 LEFT JOIN stats p ON p.base_id=s.base_id AND p.recent=0
      LEFT JOIN totals pt ON pt.recent=0
      WHERE s.recent=1 ORDER BY s.n DESC,u.id");
    foreach($rows as &$r) {
        $r['delta']=$r['prev_wr']!==null && (int)$r['prev_n'] >= $min && (int)$r['dec_n'] >= $min && $r['wr']!==null ? (float)$r['wr']-(float)$r['prev_wr'] : null;
        $r['pick_delta']=$r['prev_pick']!==null ? (float)$r['pick']-(float)$r['prev_pick'] : null;
    } unset($r);
    foreach(cbQuery($pdo,$cte." SELECT base_id,day_bucket,COUNT(is_win) n,AVG(is_win)*100 wr
      FROM characters WHERE recent=1 AND day_bucket BETWEEN 0 AND 6
      GROUP BY base_id,day_bucket") as $point) {
        if ((int)$point['n']>0 && $point['wr']!==null) $trends[(int)$point['base_id']][(int)$point['day_bucket']]=$point;
    }
    // Public analysis counts all mapped card slots, not distinct teams.
    // Retain original recommendation-score ordering and Top 8 + others.
    $distribution=cbQuery($pdo,$cte." SELECT u.id,u.name,u.ico,COUNT(*) n,
      ROUND(SUM(s.is_win=1)*LOG10(COUNT(*)+1),4) score
      FROM slots s JOIN unlight u ON u.id=FLOOR((s.cid-1)/10)*10+1
      WHERE s.recent=1 GROUP BY u.id,u.name,u.ico ORDER BY score DESC,u.id");
    $teams=(int)($rows[0]['total']??0);
    $byId=array_column($rows,null,'id');
    if (!isset($byId[$selected])) $selected=(int)($rows[0]['id']??0);
    $filters['char']=$selected;
    if ($selected) {
        $lo=$selected; $hi=$lo+9;
        $sel=" AND recent=1 AND (c1 BETWEEN $lo AND $hi OR c2 BETWEEN $lo AND $hi OR c3 BETWEEN $lo AND $hi)";
        $levels=cbQuery($pdo,$cte." SELECT u.level,u.id,COUNT(*) n,AVG(s.is_win)*100 wr
          FROM (SELECT DISTINCT id,side,is_win,cid FROM slots WHERE recent=1 AND cid BETWEEN $lo AND $hi) s
          JOIN unlight u ON u.id=s.cid GROUP BY u.id,u.level ORDER BY u.id");
        $detailCte=$cte.", chosen AS (SELECT * FROM teams WHERE 1=1 $sel),
          friends AS (SELECT id,side,is_win,c1 cid FROM chosen UNION ALL SELECT id,side,is_win,c2 FROM chosen UNION ALL SELECT id,side,is_win,c3 FROM chosen),
          enemies AS (SELECT id,side,is_win,o1 cid FROM chosen UNION ALL SELECT id,side,is_win,o2 FROM chosen UNION ALL SELECT id,side,is_win,o3 FROM chosen)";
        $mates=cbQuery($pdo,$detailCte." SELECT u.id,u.name,u.ico,COUNT(*) n,AVG(q.is_win)*100 wr
          FROM (SELECT DISTINCT id,side,is_win,FLOOR((cid-1)/10)*10+1 base_id FROM friends WHERE cid>0 AND cid NOT BETWEEN $lo AND $hi) q
          JOIN unlight u ON u.id=q.base_id GROUP BY u.id,u.name,u.ico HAVING COUNT(is_win)>=$min ORDER BY wr DESC,n DESC LIMIT 5");
        $combos=cbQuery($pdo,$detailCte." SELECT a.name n1,a.ico i1,b.name n2,b.ico i2,c.name n3,c.ico i3,
          COUNT(*) n,AVG(ch.is_win)*100 wr FROM chosen ch
          JOIN unlight a ON a.id=FLOOR((ch.c1-1)/10)*10+1
          JOIN unlight b ON b.id=FLOOR((ch.c2-1)/10)*10+1
          JOIN unlight c ON c.id=FLOOR((ch.c3-1)/10)*10+1
          GROUP BY a.id,a.name,a.ico,b.id,b.name,b.ico,c.id,c.name,c.ico
          HAVING COUNT(*)>=$min ORDER BY n DESC LIMIT 3");
        $opponents=cbQuery($pdo,$detailCte." SELECT u.id,u.name,u.ico,COUNT(*) n,AVG(q.is_win)*100 wr
          FROM (SELECT DISTINCT id,side,is_win,FLOOR((cid-1)/10)*10+1 base_id FROM enemies WHERE cid>0 AND cid NOT BETWEEN $lo AND $hi) q
          JOIN unlight u ON u.id=q.base_id GROUP BY u.id,u.name,u.ico HAVING COUNT(is_win)>=$min ORDER BY wr DESC,n DESC");
    }
} catch (Throwable $e) {
    error_log('character_analysis: query failed'); http_response_code(503); $failure=true;
    $rows=$levels=$mates=$combos=$opponents=[]; $trends=[]; $distribution=[]; $teams=0; $selected=0;
}
$valid=array_values(array_filter($rows,fn($r)=>(int)$r['dec_n'] >= $min && $r['wr']!==null));
$strong=$valid; usort($strong,fn($a,$b)=>(float)$b['wr']<=>(float)$a['wr']);
$rising=array_values(array_filter($valid,fn($r)=>$r['delta']!==null && $r['delta']>0)); usort($rising,fn($a,$b)=>$b['delta']<=>$a['delta']);
$slotTotal=array_sum(array_column($distribution,'n'));
$pie=array_slice($distribution,0,8);
$other=$slotTotal-array_sum(array_column($pie,'n'));
if($other>0) $pie[]=['id'=>0,'name'=>'其他','ico'=>null,'n'=>$other];
$pieColors=['rgba(110, 120, 255, 0.78)','rgba(255, 118, 118, 0.76)','rgba(246, 196,  83, 0.78)','rgba(102, 187, 106, 0.72)','rgba(171, 136, 255, 0.74)','rgba(38, 198, 218, 0.72)','rgba(255, 167, 38, 0.72)','rgba(189, 189, 189, 0.65)','rgba(120, 130, 150, 0.45)'];
$current=array_column($rows,null,'id')[$selected]??null;
$maxX=max(5,ceil(max(array_merge([0],array_map(fn($r)=>(float)$r['pick'],$valid)))/5)*5);
$median=0; if ($valid) { $xs=array_map(fn($r)=>(float)$r['pick'],$valid); sort($xs); $median=$xs[(int)floor(count($xs)/2)]; }
$metaPoints=array_map(fn($v)=>['id'=>(int)$v['id'],'name'=>$v['name'],'x'=>(float)$v['pick'],'y'=>(float)$v['wr'],'n'=>(int)$v['n'],'ico'=>empty($v['ico'])?null:IMG_BASE.$v['ico'],'href'=>cbUrl(['char'=>(int)$v['id']])],$valid);
$metaData=['points'=>$metaPoints,'maxX'=>$maxX,'median'=>$median,'selected'=>$selected];
function cbSpark(array $points): string {
    if (!$points) return '<span class="cb-muted">無有效樣本</span>';
    $svg='<svg class="cb-spark" viewBox="0 0 84 30" role="img" aria-label="近七個24小時區間勝率，缺資料不連線">';
    $svg.='<path d="M4 15H80" stroke="currentColor" stroke-opacity=".18"/>';
    $previous=null;
    for($i=6;$i>=0;$i--){
        if(!isset($points[$i])) {$previous=null; continue;}
        $p=$points[$i]; $x=6+(6-$i)*12; $y=27-max(0,min(100,(float)$p['wr']))*.24;
        if($previous!==null) $svg.='<line x1="'.$previous[0].'" y1="'.$previous[1].'" x2="'.$x.'" y2="'.$y.'" stroke="#c1a1dc" stroke-width="1.6"/>';
        $svg.='<circle cx="'.$x.'" cy="'.$y.'" r="2.3" fill="#c1a1dc"><title>'.cbH('距今 '.$i.'–'.($i+1).' 日｜'.cbRate($p['wr']).'｜有效勝負 '.$p['n']).'</title></circle>';
        $previous=[$x,$y];
    }
    return $svg.'</svg>';
}
function cbImg($ico,string $class=''): string {
    if (!$ico) return '';
    return '<img loading="lazy" class="'.cbH($class).'" src="'.cbH(IMG_BASE.$ico).'" alt="">';
}
ob_start();
?>
<style>
.cb{--cb-gold:#d8bb85;--cb-line:rgba(208,174,116,.30);--cb-panel:var(--card-bg,#211d29);--cb-muted:#bdb4c7;--cb-green:#89d5a2;--cb-red:#f2a0a0;color:var(--text-color,#eee9f0);font-size:14px;line-height:1.5}
.cb *{box-sizing:border-box}.cb h2,.cb h3,.cb h4{margin:0;color:var(--cb-gold);font-weight:700}.cb h3{font-size:16px}.cb h4{font-size:14px}
.cb .cb-panel{min-width:0;border:1px solid var(--cb-line);border-radius:10px;background:var(--cb-panel);padding:14px;overflow:hidden}
.cb .cb-title{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}.cb .cb-title h2{font-size:26px}.cb .cb-badge{border:1px solid var(--cb-line);border-radius:5px;padding:2px 8px;color:var(--cb-gold);font-size:12px}
.cb .cb-muted{color:var(--cb-muted);font-size:12px}.cb .cb-filters{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin-bottom:12px}.cb label{display:grid;gap:4px;font-size:12px;flex:1 1 100px;min-width:0}.cb select,.cb input{max-width:100%;min-width:0}
.cb .cb-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:12px}.cb .cb-kpi{display:flex;align-items:center;gap:9px;text-decoration:none;color:inherit;min-height:100px}.cb .cb-kpi img{width:60px;height:68px;object-fit:contain}.cb .cb-kpi strong{display:block;font-size:20px}.cb .cb-kpi .cb-muted{display:block}
.cb .cb-grid{display:grid;grid-template-columns:minmax(0,1.48fr) minmax(0,1fr);gap:12px;align-items:start}.cb .cb-stack{display:grid;gap:12px;min-width:0}.cb .cb-plot{display:block;width:100%;height:auto;margin-top:8px}.cb .cb-plot a:focus circle,.cb .cb-plot a:hover circle{stroke:white;stroke-width:3}
.cb .cb-table-wrap{overflow:auto;max-width:100%}.cb table{width:100%;border-collapse:collapse;white-space:nowrap;font-variant-numeric:tabular-nums}.cb th,.cb td{padding:8px 6px;border-bottom:1px solid rgba(255,255,255,.08);text-align:right}.cb th:first-child,.cb td:first-child{text-align:left}.cb th button{color:inherit;border:none;background:none;padding:0;cursor:pointer}.cb .cb-row-active{background:rgba(175,130,205,.19)}.cb td a{color:inherit;display:flex;align-items:center;gap:7px;text-decoration:none}.cb td img,.cb .cb-person img{width:34px;height:34px;object-fit:contain}.cb .cb-person{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.08)}.cb .cb-person strong{margin-left:auto;white-space:nowrap}.cb .cb-plus{color:var(--cb-green)}.cb .cb-minus{color:var(--cb-red)}.cb .cb-detail-head{display:flex;align-items:center;gap:12px}.cb .cb-portrait{width:31%;max-height:145px;object-fit:contain}.cb .cb-detail-head h2{font-size:24px}.cb .cb-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0}.cb .cb-metrics strong{display:block;font-size:21px;font-variant-numeric:tabular-nums}
.cb .cb-levels{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px;margin-top:9px}.cb .cb-level{text-align:center;font-size:11px;min-width:0}.cb .cb-track{height:62px;background:rgba(255,255,255,.04);display:flex;align-items:end;justify-content:center;margin:3px 0}.cb .cb-track i{display:block;width:65%;background:linear-gradient(#c3a1d9,#665079);min-height:0}.cb .cb-pair{display:grid;grid-template-columns:1fr 1fr;gap:10px}.cb .cb-combo{display:flex;gap:4px;align-items:center;padding:6px 0;flex-wrap:wrap}.cb .cb-combo img{width:29px;height:29px;object-fit:contain}.cb .cb-combo strong{margin-left:auto}.cb .cb-sub{border:1px solid rgba(255,255,255,.08);padding:10px;border-radius:7px;min-width:0}.cb .cb-search{margin:10px 0;width:100%}.cb .cb-warning{padding:12px;border:1px solid var(--cb-line);margin-bottom:12px}
@media(max-width:1100px){.cb .cb-grid{grid-template-columns:1fr}.cb .cb-kpi img{width:45px;height:52px}}
@media(max-width:600px){.cb .cb-kpis{grid-template-columns:1fr}.cb .cb-kpi{min-height:68px}.cb .cb-panel{padding:10px}.cb .cb-title h2{font-size:21px}.cb .cb-pair{grid-template-columns:1fr}.cb .cb-metrics strong{font-size:17px}.cb .cb-filters label{flex-basis:43%}}
.cb .cb-spark{width:84px;height:30px;vertical-align:middle;display:inline-block}.cb .cb-tabbar{display:flex;gap:4px;flex-wrap:wrap}.cb .cb-tabbar .btn{flex:1 1 auto;min-width:0;font-size:13px}.cb .cb-tabbar [aria-pressed="true"]{box-shadow:inset 0 -2px 0 var(--cb-gold);border-color:var(--cb-gold)}.cb [data-cb-section][hidden]{display:none!important}.cb .cb-detail-head{scroll-margin-top:80px}

@media(max-width:600px){.cb .cb-spark{width:70px}}
.cb .cb-distribution-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:12px;align-items:center}.cb .cb-donut{width:100%;max-width:290px;display:block;margin:auto}.cb .cb-pie-legend{display:grid;gap:3px;min-width:0}.cb .cb-pie-item{display:grid;grid-template-columns:9px 30px minmax(0,1fr) auto;align-items:center;gap:6px;padding:3px 5px;border-radius:5px;color:var(--text-main,#e0e0e0);text-decoration:none}.cb a.cb-pie-item:hover,.cb a.cb-pie-item:focus-visible{background:rgba(159,168,218,.15);color:var(--text-main,#e0e0e0)}.cb .cb-pie-item img{width:30px;height:33px;object-fit:contain}.cb .cb-pie-item strong{font-variant-numeric:tabular-nums;white-space:nowrap}.cb .cb-pie-dot{width:9px;height:9px;border-radius:2px}.cb .cb-pie-item .cb-pie-name{overflow-wrap:anywhere}.cb .cb-donut a:hover circle,.cb .cb-donut a:focus-visible circle{opacity:.75}
@media(max-width:600px){.cb .cb-distribution-grid{grid-template-columns:1fr}.cb .cb-donut{max-width:240px}}

.cb .cb-plot-scroll{overflow-x:auto;max-width:100%}.cb .cb-plot{min-width:620px}.cb .cb-plot-label:hover .cb-plot-label-bg,.cb .cb-plot-label:focus .cb-plot-label-bg{stroke:var(--accent,#9fa8da);stroke-width:1.5}.cb .cb-plot-label text{pointer-events:none}
.cb .cb-distribution-grid{display:flex;flex-wrap:wrap;align-items:center;gap:12px}.cb .cb-pie-stage{position:relative;flex:1 1 290px;min-width:0;max-width:360px;aspect-ratio:1;margin:auto}.cb .cb-full-pie{display:block;width:100%;height:100%}.cb .cb-full-pie a:hover circle{opacity:.8}.cb .cb-pie-legend{flex:1 1 175px}.cb .cb-pie-cards{position:absolute;inset:0;pointer-events:none}
.cb .ca-pie-card {
    position: absolute;
    width: 56px;
    height: 78px;
    border-radius: 8px;
    overflow: hidden;

    border: 1px solid rgba(255, 255, 255, .25);
    background: #111;
    box-shadow: 0 8px 18px rgba(0, 0, 0, .6);

    cursor: pointer;
    pointer-events: auto;
    /* 卡面可點 */
    transition: transform .15s ease;
  }.cb .ca-pie-card:hover {
    transform: translateY(-4px) scale(1.04);
  }.cb .ca-pie-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
.cb .cb-original-pie-card{width:17.5%;height:24.375%;pointer-events:auto;display:block}.cb .cb-original-pie-card:focus-visible{outline:2px solid var(--accent,#9fa8da);z-index:2}
.cb .cb-pagination{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;margin-top:10px}.cb .cb-page-buttons{display:flex;flex-wrap:wrap;gap:4px}.cb .cb-page-buttons .btn{min-width:30px}.cb #cb-table tr[hidden]{display:none!important}
@media(max-width:600px){.cb .cb-plot-image{display:none}.cb .cb-pie-stage{flex-basis:100%;max-width:320px}}

.cb #cb-meta-card .cb-meta-controls{display:flex;flex-wrap:wrap;gap:7px;align-items:center;margin-top:10px}
.cb .cb-meta-modes,.cb .cb-meta-actions{display:flex;gap:4px;flex-wrap:wrap}
.cb #cb-meta-search{flex:1 1 130px;width:auto;min-width:100px}
.cb #cb-meta-card [aria-pressed="true"]{box-shadow:inset 0 -2px 0 var(--cb-gold);border-color:var(--accent,#9fa8da)}
.cb .cb-meta-stage{position:relative;min-width:0;width:100%}
.cb #cb-meta-svg{min-width:0;width:100%;max-width:100%;touch-action:pan-y;cursor:crosshair}
.cb #cb-meta-svg a{cursor:pointer}
.cb .cb-meta-tip{position:absolute;z-index:3;display:flex;gap:8px;align-items:flex-start;max-width:calc(100% - 8px);padding:9px;border:1px solid var(--accent,#9fa8da);background:#14141c;color:var(--text-main,#e0e0e0);border-radius:8px;box-shadow:0 6px 16px #0008}
.cb .cb-meta-tip img{width:44px;height:62px;object-fit:contain;flex-shrink:0}
.cb .cb-meta-tip strong{font-size:14px;display:block}
.cb .cb-meta-tip a{margin-top:5px}
.cb .cb-meta-tip button{margin-left:auto}
.cb .cb-meta-tip[hidden],.cb .cb-meta-tip img[hidden]{display:none!important}
.cb #cb-meta-card.cb-meta-expanded{position:fixed;inset:12px;z-index:30000;background:var(--bg-main,#0b0c10);overflow:auto;box-shadow:0 0 0 100vmax #000a;padding:16px}
.cb #cb-meta-card.cb-meta-expanded #cb-meta-svg{touch-action:none}
@media(max-width:600px){.cb #cb-meta-card.cb-meta-expanded{inset:4px;padding:10px}.cb .cb-meta-modes,.cb .cb-meta-actions{width:100%}}

.cb .cb-levels{grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}
.cb .cb-level{border:1px solid var(--border-glow,#4a4055);border-radius:7px;background:var(--bg-card-mid,#14141c);padding:5px 3px;min-width:0}
.cb .cb-level-rate{display:block;border:1px solid var(--border-glow,#4a4055);border-radius:4px;padding:4px 1px;color:var(--text-main,#e0e0e0);background:var(--bg-card-top,#2a2a3a);font-size:clamp(10px,1vw,13px);font-weight:700;line-height:1.25;font-variant-numeric:tabular-nums;white-space:nowrap}
.cb .cb-level .cb-muted{white-space:nowrap;font-size:11px}
.cb .cb-level strong{display:block;margin-top:3px}
.cb .cb-level .cb-track{height:48px;margin:4px 0}
@media(max-width:400px){.cb .cb-levels{gap:4px}.cb .cb-level{padding:4px 2px}.cb .cb-level-rate{font-size:10px}.cb .cb-level .cb-muted{font-size:10px}}
</style>
<div class="content-wrapper"><section class="content ul-container-nopad"><div class="container-fluid cb">
<div class="cb-title"><h2>角色分析中心</h2><span class="cb-muted">Character Meta Analysis · L1–R5 合併</span></div>
<form method="get" class="cb-panel cb-filters">
<input type="hidden" name="char" value="<?= $selected ?>">
<?php foreach(['days'=>['統計區間',[7=>'近 7 日',14=>'近 14 日',30=>'近 30 日',90=>'近 90 日']], 'cost'=>['COST',[0=>'不限',50=>'50–59',60=>'60–69',70=>'70–80',90=>'90+']+($weekly?array_combine([201,202,203,204],array_map(fn($i)=>'當週 '.$weekly['cost'.$i].($i===4&&$weekly['cost4_operator']==='MIN'?'+':''),[1,2,3,4])):[])], 'bp'=>['BP 區間',[0=>'全部',1499=>'< 1500',1500=>'1500–1599',1600=>'1600–1699',1700=>'1700+']], 'min'=>['最低有效勝負樣本',[10=>'≥ 10',30=>'≥ 30',60=>'≥ 60',100=>'≥ 100',200=>'≥ 200']]] as $key=>[$label,$opts]): ?>
<label><?= cbH($label) ?><select class="form-control" name="<?= cbH($key) ?>"><?php foreach($opts as $v=>$txt): ?><option value="<?= $v ?>" <?= $filters[$key]===$v?'selected':'' ?>><?= cbH($txt) ?></option><?php endforeach; ?></select></label>
<?php endforeach; ?><button class="btn btn-primary" type="submit">套用篩選</button>
</form>
<?php if($failure): ?><div class="cb-warning" role="alert">目前無法取得分析資料，未顯示推測數值。</div><?php elseif(!$rows): ?><div class="cb-warning">此條件沒有資料。<?php if($cost>=201&&!$weekly): ?> 尚無有效核准的當週 COST 規則。<?php endif; ?></div><?php endif; ?>
<div class="cb-kpis">
<?php foreach([['熱門角色','隊伍出場率',$rows[0]??null,'pick'],['強勢角色','高樣本勝率',$strong[0]??null,'wr'],['環境新星','較前一等長期間勝率變化',$rising[0]??null,'delta']] as [$title,$subtitle,$r,$key]): ?>
<a class="cb-panel cb-kpi" href="<?= cbH(cbUrl(['char'=>(int)($r['id']??0)])) ?>"><?= cbImg($r['ico']??null) ?><div><h3><?= cbH($title) ?></h3><strong><?= cbH($r['name']??'資料不足') ?></strong><span><?= $key==='delta'?cbDelta($r[$key]??null):cbRate($r[$key]??null) ?></span><span class="cb-muted"><?= cbH($subtitle) ?></span></div></a>
<?php endforeach; ?></div>
<div class="cb-grid"><div class="cb-stack">
<section class="cb-panel" id="cb-distribution" data-cb-version="original-pie-r5">
<h3>角色出場分布（L1–R5 合併） <small class="cb-muted">Top 8 + 其他</small></h3>
<p class="cb-muted">沿用原站卡位出場佔比與推薦分數排序；同步目前日期、COST、BP 篩選（來源已合併）。低樣本角色仍納入分母。</p>
<?php if($slotTotal>0): ?>
<div class="cb-distribution-grid">
<div class="cb-pie-stage">
<svg class="cb-full-pie" viewBox="0 0 320 320" role="group" aria-label="原站圓餅圖及環繞角色卡面">
<?php $offset=0.0; foreach($pie as $i=>$item): $rate=100*(int)$item['n']/$slotTotal; $col=$pieColors[$i]; ?>
<?php if($item['id']): ?><a href="<?= cbH(cbUrl(['char'=>(int)$item['id']])) ?>" aria-label="<?= cbH($item['name'].' '.cbRate($rate)) ?>"><?php else: ?><g><?php endif; ?>
<circle cx="160" cy="160" r="65" pathLength="100" fill="none" stroke="<?= $col ?>" stroke-width="130" stroke-dasharray="<?= $rate ?> <?= 100-$rate ?>" stroke-dashoffset="<?= -$offset ?>" transform="rotate(-90 160 160)"><title><?= cbH($item['name'].'｜'.cbRate($rate).'｜'.number_format((int)$item['n']).' 卡位') ?></title></circle>
<?php if($item['id']): ?></a><?php else: ?></g><?php endif; $offset+=$rate; endforeach; ?>
</svg>
<div class="cb-pie-cards">
<?php $cards=array_values(array_filter($pie,fn($item)=>$item['id']&&!empty($item['ico']))); foreach($cards as $i=>$item): $angle=2*M_PI*$i/count($cards)-M_PI/2; ?>
<a class="ca-pie-card cb-original-pie-card" href="<?= cbH(cbUrl(['char'=>(int)$item['id']])) ?>" title="<?= cbH($item['name'].'｜'.cbRate(100*$item['n']/$slotTotal)) ?>" aria-label="<?= cbH($item['name']) ?>" style="left:<?= 50+35*cos($angle)-8.75 ?>%;top:<?= 50+35*sin($angle)-12.1875 ?>%"><?= cbImg($item['ico']) ?></a>
<?php endforeach; ?>
</div></div>
<div class="cb-pie-legend">
<?php foreach($pie as $i=>$item): $tag=$item['id']?'a':'div'; ?>
<<?= $tag ?> class="cb-pie-item" <?= $item['id']?'href="'.cbH(cbUrl(['char'=>(int)$item['id']])).'"':'' ?> data-slots="<?= (int)$item['n'] ?>"><span class="cb-pie-dot" style="background:<?= $pieColors[$i] ?>"></span><span><?= cbImg($item['ico']) ?></span><span class="cb-pie-name"><?= cbH($item['name']) ?></span><strong><?= cbRate(100*(int)$item['n']/$slotTotal) ?></strong></<?= $tag ?>>
<?php endforeach; ?>
</div></div>
<p class="cb-muted">有效角色卡位：<?= number_format($slotTotal) ?>。出場佔比＝角色卡位數／所有可識別角色卡位數；與上圖的隊伍出場率不同。比例四捨五入至小數一位。</p>
<?php else: ?><p class="cb-muted">目前篩選無有效出場資料。</p><?php endif; ?>
</section>
<section class="cb-panel" id="cb-meta-card" data-meta-version="r8-public">
<h3>角色環境分布圖</h3><div class="cb-muted">隊伍出場率 × 勝率｜圓點大小：隊伍樣本｜垂直線：合格角色出場率中位數｜水平線：50%</div>
<?php if($valid): ?>
<div class="cb-meta-controls">
<div class="cb-meta-modes" role="group" aria-label="圖表顯示模式">
<button type="button" class="btn btn-default btn-sm" data-meta-mode="smart" aria-pressed="true">智慧顯示</button>
<button type="button" class="btn btn-default btn-sm" data-meta-mode="all" aria-pressed="false">全部名稱</button>
<button type="button" class="btn btn-default btn-sm" data-meta-mode="dot" aria-pressed="false">純散點</button>
</div>
<input type="search" class="form-control" id="cb-meta-search" aria-label="搜尋圖表角色" placeholder="搜尋角色並高亮">
<div class="cb-meta-actions" role="group" aria-label="圖表縮放">
<button type="button" class="btn btn-default btn-sm" data-meta-action="dense">低出場率放大</button>
<button type="button" class="btn btn-default btn-sm" data-meta-action="in" aria-label="放大">＋</button>
<button type="button" class="btn btn-default btn-sm" data-meta-action="out" aria-label="縮小">－</button>
<button type="button" class="btn btn-default btn-sm" data-meta-action="reset">重設</button>
<button type="button" class="btn btn-default btn-sm" data-meta-action="expand" aria-expanded="false">全螢幕</button>
</div></div>
<div class="cb-meta-stage" id="cb-meta-stage">
<svg class="cb-plot" id="cb-meta-svg" role="group" aria-label="角色環境分布，點選角色檢視"></svg>
<div class="cb-meta-tip" id="cb-meta-tip" hidden><img alt="" loading="lazy"><div><strong></strong><div class="cb-muted" data-meta-stats></div><a class="btn btn-default btn-sm">檢視角色</a></div><button type="button" class="btn btn-default btn-sm" aria-label="關閉提示">×</button></div>
</div>
<p id="cb-meta-status" class="cb-muted" aria-live="polite"></p>
<div class="cb-muted">左上：冷門高勝率　右上：主流高勝率　左下：低使用低勝率　右下：熱門低勝率</div>
<div class="cb-muted">智慧顯示優先熱門、強勢及搜尋角色；移入散點查看卡面。縮放後拖曳空白處移動；Ctrl＋滾輪亦可縮放。所有名稱模式保留引線。</div>
<script type="application/json" id="cb-meta-data"><?= json_encode($metaData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<noscript><p>請啟用 JavaScript 使用互動圖表。完整角色資料仍可於下方列表查看。</p></noscript>
<?php else: ?><p class="cb-muted">沒有符合樣本門檻的角色。</p><?php endif; ?></section>
<section class="cb-panel"><h3>角色列表 <small class="cb-muted"><?= count($rows) ?> 名 · <?= number_format($teams) ?> 隊</small></h3><input id="cb-search" class="form-control cb-search" type="search" placeholder="搜尋角色名稱" aria-label="搜尋角色">
<div class="cb-table-wrap"><table id="cb-table"><thead><tr><th><button type="button" data-col="0">角色 ↕</button></th><th><button type="button" data-col="1">勝率 ↕</button></th><th><button type="button" data-col="2">出場率 ↕</button></th><th><button type="button" data-col="3">樣本 ↕</button></th><th><button type="button" data-col="4">勝率變化 ↕</button></th><th>近7日走勢</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr class="<?= (int)$r['id']===$selected?'cb-row-active':'' ?>"><td data-value="<?= cbH($r['name']) ?>"><a href="<?= cbH(cbUrl(['char'=>(int)$r['id']])) ?>"><?= cbImg($r['ico']) ?><?= cbH($r['name']) ?><?= (int)$r['dec_n']<$min?' · 低樣本':'' ?></a></td><td data-value="<?= cbH($r['wr']??'') ?>"><?= cbRate($r['wr']) ?></td><td data-value="<?= cbH($r['pick']) ?>"><?= cbRate($r['pick']) ?></td><td data-value="<?= (int)$r['n'] ?>"><?= number_format((int)$r['n']) ?></td><td data-value="<?= cbH($r['delta']??'') ?>" class="<?= ($r['delta']??0)>0?'cb-plus':(($r['delta']??0)<0?'cb-minus':'') ?>"><?= cbDelta($r['delta']) ?></td><td><?= cbSpark($trends[(int)$r['id']]??[]) ?></td></tr><?php endforeach; ?></tbody></table></div>
<div class="cb-pagination"><span id="cb-page-status" class="cb-muted" aria-live="polite"></span><div class="cb-page-buttons" id="cb-page-buttons" role="group" aria-label="角色列表分頁"></div><label style="flex:0 0 auto">每頁 <select class="form-control" id="cb-page-size" aria-label="每頁角色數"><option value="10">10</option><option value="15" selected>15</option><option value="20">20</option><option value="30">30</option></select></label></div>
</section></div>
<aside class="cb-panel cb-stack" id="cb-detail">
<?php if($current): ?><div class="cb-detail-head"><?= cbImg($current['stand_ico']?:$current['ico'],'cb-portrait') ?><div><h2><?= cbH($current['name']) ?></h2><div class="cb-muted">L1–R5 合併 · <?= (int)$current['dec_n']<$min?'低樣本':'符合樣本門檻' ?></div><a class="btn btn-default btn-sm" href="/pages/character.php?<?= cbH(http_build_query(['char_base'=>$current['id'].'-'.$current['name'],'days'=>in_array($days,[14,30,90],true)?$days:14])) ?>">完整角色分析</a></div></div>
<div class="cb-tabbar" role="group" aria-label="角色詳細分析分頁">
<?php foreach(['overview'=>'總覽','levels'=>'等級','mates'=>'搭配','opponents'=>'對局'] as $tab=>$label): ?><button type="button" class="btn btn-default" data-cb-tab="<?= $tab ?>" aria-pressed="<?= $tab==='overview'?'true':'false' ?>"><?= cbH($label) ?></button><?php endforeach; ?>
</div>
<div class="cb-metrics" data-cb-section="overview"><div><span class="cb-muted">勝率</span><strong><?= cbRate($current['wr']) ?></strong><span class="cb-muted"><?= cbDelta($current['delta']) ?></span></div><div><span class="cb-muted">出場率</span><strong><?= cbRate($current['pick']) ?></strong><span class="cb-muted"><?= cbDelta($current['pick_delta']) ?></span></div><div><span class="cb-muted">隊伍樣本</span><strong><?= number_format((int)$current['n']) ?></strong></div></div>
<div class="cb-sub" data-cb-section="levels"><h3>等級表現（勝率）</h3><div class="cb-levels"><?php $byLevel=array_column($levels,null,'level'); foreach(['L1','L2','L3','L4','L5','R1','R2','R3','R4','R5'] as $lv): $r=$byLevel[$lv]??null; ?><div class="cb-level"><span class="cb-level-rate"><?= cbRate($r['wr']??null) ?></span><div class="cb-track"><i style="height:<?= max(0,min(100,(float)($r['wr']??0))) ?>%"></i></div><strong><?= $lv ?></strong><div class="cb-muted"><?= (int)($r['n']??0) ?> 隊</div></div><?php endforeach; ?></div></div>
<div class="cb-pair" data-cb-section="mates"><div class="cb-sub"><h3>搭配勝率</h3><?php foreach($mates as $r): ?><div class="cb-person"><?= cbImg($r['ico']) ?><span><?= cbH($r['name']) ?><small class="cb-muted"> · <?= (int)$r['n'] ?></small></span><strong><?= cbRate($r['wr']) ?></strong></div><?php endforeach; if(!$mates): ?><p class="cb-muted">樣本不足</p><?php endif; ?></div><div class="cb-sub"><h3>常見三人組合</h3><?php foreach($combos as $r): ?><div class="cb-combo" title="<?= cbH($r['n1'].' / '.$r['n2'].' / '.$r['n3']) ?>"><?= cbImg($r['i1']) ?><?= cbImg($r['i2']) ?><?= cbImg($r['i3']) ?><strong><?= cbRate($r['wr']) ?></strong><small class="cb-muted"><?= (int)$r['n'] ?> 隊</small></div><?php endforeach; if(!$combos): ?><p class="cb-muted">樣本不足</p><?php endif; ?></div></div>
<div class="cb-pair" data-cb-section="opponents"><?php $good=array_slice(array_values(array_filter($opponents,fn($r)=>(float)$r['wr']>50)),0,3); $bad=array_slice(array_reverse(array_values(array_filter($opponents,fn($r)=>(float)$r['wr']<50))),0,3); foreach([['優勢對局',$good,'cb-plus'],['劣勢對局',$bad,'cb-minus']] as [$t,$list,$cl]): ?><div class="cb-sub"><h3><?= $t ?></h3><?php foreach($list as $r): ?><div class="cb-person"><?= cbImg($r['ico']) ?><span><?= cbH($r['name']) ?><small class="cb-muted"> · <?= (int)$r['n'] ?></small></span><strong class="<?= $cl ?>"><?= cbRate($r['wr']) ?></strong></div><?php endforeach; if(!$list): ?><p class="cb-muted">樣本不足</p><?php endif; ?></div><?php endforeach; ?></div>
<p class="cb-muted">勝率及樣本門檻排除和局；列表保留並標記低樣本角色。出場率＝包含該角色的隊伍／合格隊伍。對局為敵方隊伍含該角色的觀察勝率，非直接交手或因果剋制。搭配為共現勝率。等長前期比較以百分點（pp）表示。迷你折線為最近七個24小時區間的實際勝負，缺資料不補零或跨段連線。</p>
<?php else: ?><p>請選擇角色。</p><?php endif; ?></aside></div></div></section></div>
<script>
(()=>{
const table=document.getElementById('cb-table'),search=document.getElementById('cb-search'),buttons=document.getElementById('cb-page-buttons'),status=document.getElementById('cb-page-status'),size=document.getElementById('cb-page-size');
if(!table||!search||!buttons||!status||!size)return;
const body=table.tBodies[0];let last=-1,dir=-1,page=1;
const all=[...body.rows];const filtered=()=>{const q=search.value.trim().toLocaleLowerCase();return all.filter(tr=>tr.cells[0].textContent.toLocaleLowerCase().includes(q));};
function render(){const rows=filtered(),n=Number(size.value),pages=Math.max(1,Math.ceil(rows.length/n));page=Math.min(Math.max(page,1),pages);
const start=(page-1)*n,end=Math.min(start+n,rows.length),shown=new Set(rows.slice(start,end));all.forEach(tr=>tr.hidden=!shown.has(tr));
status.textContent=rows.length?'顯示 '+(start+1)+'–'+end+' / '+rows.length+' 名':'沒有符合搜尋的角色';buttons.replaceChildren();
function btn(label,num,disabled,current){const b=document.createElement('button');b.type='button';b.className='btn btn-default btn-sm';b.textContent=label;b.disabled=disabled;b.dataset.page=String(num);if(current)b.setAttribute('aria-current','page');if(current)b.classList.add('active');b.onclick=()=>{page=num;render()};buttons.append(b);}
btn('‹',page-1,page===1,false);for(let i=1;i<=pages;i++)btn(String(i),i,false,i===page);btn('›',page+1,page===pages,false);}
search.addEventListener('input',()=>{page=1;render()});size.addEventListener('change',()=>{page=1;render()});
for(const b of table.querySelectorAll('button[data-col]'))b.addEventListener('click',()=>{const i=Number(b.dataset.col);dir=last===i?-dir:i===0?1:-1;last=i;all.sort((x,y)=>{const p=x.cells[i].dataset.value,q=y.cells[i].dataset.value;if(!p||!q)return !p&&!q?0:!p?1:-1;return dir*(i===0?p.localeCompare(q,'zh-Hant'):Number(p)-Number(q))});all.forEach(tr=>body.append(tr));page=1;render()});
const selected=all.findIndex(tr=>tr.classList.contains('cb-row-active'));if(selected>=0)page=Math.floor(selected/Number(size.value))+1;render();
})();
document.querySelectorAll('[data-cb-tab]').forEach(button=>button.addEventListener('click',()=>{
    const tab=button.dataset.cbTab;
    document.querySelectorAll('[data-cb-tab]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));
    document.querySelectorAll('[data-cb-section]').forEach(panel=>panel.hidden=tab!=='overview'&&panel.dataset.cbSection!==tab);
}));
</script>
<script>
(()=>{
const card=document.getElementById('cb-meta-card'),svg=document.getElementById('cb-meta-svg');
if(!card||!svg)return;
const stage=document.getElementById('cb-meta-stage'),tip=document.getElementById('cb-meta-tip'),search=document.getElementById('cb-meta-search'),status=document.getElementById('cb-meta-status');
const data=JSON.parse(document.getElementById('cb-meta-data').textContent);
const points=data.points, byId=new Map(points.map(p=>[p.id,p]));
const NS='http://www.w3.org/2000/svg';
const el=(tag,attrs={},txt)=>{const n=document.createElementNS(NS,tag);for(const [k,v] of Object.entries(attrs))n.setAttribute(k,String(v));if(txt!==undefined)n.textContent=txt;return n;};
const minY=Math.max(0,Math.floor((Math.min(50,...points.map(p=>p.y))-3)/5)*5),maxY=Math.min(100,Math.ceil((Math.max(50,...points.map(p=>p.y))+3)/5)*5);
const full={x0:0,x1:data.maxX,y0:Math.min(minY,45),y1:Math.max(maxY,55)};
let domain={...full},mode='smart',matches=new Set(),hoverId=null,expanded=false,drag=null,skipClick=false,geom=null;
const topPick=[...points].sort((a,b)=>b.x-a.x).slice(0,5),topWin=[...points].sort((a,b)=>b.y-a.y||b.n-a.n).slice(0,3);
const priority=new Set([...topPick,...topWin].map(p=>p.id));priority.add(data.selected);
const fmt=v=>Number(v).toFixed(1)+'%';
const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
const collides=(a,b)=>a.x<b.x+b.w&&a.x+a.w>b.x&&a.y<b.y+b.h&&a.y+a.h>b.y;
const measure=document.createElement('canvas').getContext('2d');if(measure)measure.font='12px Arial, sans-serif';
function hideTip(){tip.hidden=true;hoverId=null;}
function showTip(p,clientX,clientY){
 hoverId=p.id;const img=tip.querySelector('img');img.hidden=!p.ico;if(p.ico)img.src=p.ico;else img.removeAttribute('src');
 tip.querySelector('strong').textContent=p.name;tip.querySelector('[data-meta-stats]').textContent='勝率 '+fmt(p.y)+' ｜ 出場率 '+fmt(p.x)+' ｜ '+p.n.toLocaleString()+' 隊';
 tip.querySelector('a').href=p.href;tip.hidden=false;
 const rect=stage.getBoundingClientRect(),w=tip.offsetWidth,h=tip.offsetHeight;
 tip.style.left=clamp(clientX-rect.left+12,4,Math.max(4,rect.width-w-4))+'px';
 tip.style.top=clamp(clientY-rect.top+12,4,Math.max(4,rect.height-h-4))+'px';
}
function bindTarget(a,p){
 a.addEventListener('pointerenter',e=>showTip(p,e.clientX,e.clientY));
 a.addEventListener('focus',()=>{const r=a.getBoundingClientRect();showTip(p,r.left+r.width/2,r.top+r.height/2)});
 a.addEventListener('click',e=>{if(skipClick){e.preventDefault();return;}if(e.pointerType==='touch'){e.preventDefault();showTip(p,e.clientX,e.clientY);}});
}
function render(){
 hideTip();const W=Math.max(300,Math.floor(stage.clientWidth||700)),H=expanded?Math.max(380,Math.min(680,window.innerHeight-220)):Math.max(330,Math.min(450,W*.68));
 const L=46,R=W-16,T=22,B=H-40;
 const X=v=>L+(v-domain.x0)/(domain.x1-domain.x0)*(R-L),Y=v=>B-(v-domain.y0)/(domain.y1-domain.y0)*(B-T);
 geom={L,R,T,B,W,H,X,Y};svg.replaceChildren();
 svg.setAttribute('viewBox','0 0 '+W+' '+H);svg.style.height=H+'px';
 const split=clamp(X(data.median),L,R),mid=clamp(Y(50),T,B);
 const rect=(x,y,w,h,fill,opacity)=>el('rect',{x,y,width:Math.max(0,w),height:Math.max(0,h),fill,opacity});
 svg.append(rect(L,T,split-L,mid-T,'#384f40',.28),rect(split,T,R-split,mid-T,'#6c583b',.25),rect(L,mid,split-L,B-mid,'#6d617b',.08),rect(split,mid,R-split,B-mid,'#633b47',.22));
 const line=(x1,y1,x2,y2,stroke,op=1,dash='')=>el('line',{x1,y1,x2,y2,stroke,'stroke-opacity':op,'stroke-dasharray':dash,'pointer-events':'none'});
 const txt=(x,y,str,anchor='start',color='#bdb4c7',font=11)=>el('text',{x,y,'text-anchor':anchor,fill:color,'font-size':font,'pointer-events':'none'},str);
 function tickStep(span){const raw=span/5,pow=10**Math.floor(Math.log10(raw));return [1,2,2.5,5,10].map(v=>v*pow).find(v=>v>=raw)||10*pow;}
 function ticks(a,b){const step=tickStep(b-a),out=[];for(let i=Math.ceil((a-1e-8)/step)*step;i<=b+1e-8;i+=step)out.push(+i.toFixed(5));return out;}
 for(const v of ticks(domain.y0,domain.y1)){svg.append(line(L,Y(v),R,Y(v),'#bfb4c5',.15),txt(L-6,Y(v)+4,v+'%','end'));}
 for(const v of ticks(domain.x0,domain.x1)){svg.append(line(X(v),T,X(v),B,'#bfb4c5',.08),txt(X(v),B+17,v+'%','middle'));}
 if(data.median>=domain.x0&&data.median<=domain.x1)svg.append(line(split,T,split,B,'#bfb4c5',.65,'4 4'));
 if(domain.y0<=50&&domain.y1>=50)svg.append(line(L,mid,R,mid,'#bfb4c5',.8,'4 4'));
 svg.append(txt((L+R)/2,B+34,'隊伍出場率','middle'));
 const visible=points.filter(p=>p.x>=domain.x0-1e-8&&p.x<=domain.x1+1e-8&&p.y>=domain.y0-1e-8&&p.y<=domain.y1+1e-8);
 const ordered=[...visible].sort((a,b)=>Number(matches.has(a.id)||a.id===data.selected)-Number(matches.has(b.id)||b.id===data.selected)||a.n-b.n);
 const coords=new Map(),occupied=[];
 for(const p of ordered){
 const x=X(p.x),y=Y(p.y),rad=clamp(2.5+Math.sqrt(p.n)/9,3,10),active=matches.has(p.id)||p.id===data.selected;
 coords.set(p.id,{x,y,rad});occupied.push({x:x-rad-1,y:y-rad-1,w:rad*2+2,h:rad*2+2});
 const a=el('a',{href:p.href,class:'cb-plot-point'+(active?' cb-meta-highlight':''),'data-character':p.id,'aria-label':p.name+' '+fmt(p.y)+' '+fmt(p.x)});
 a.append(el('title',{},p.name+'｜勝率 '+fmt(p.y)+'｜出場率 '+fmt(p.x)),el('circle',{cx:x,cy:y,r:rad+(active?1:0),fill:active?'#e5c88d':'#b99bcf',opacity:matches.size&&!matches.has(p.id)&&p.id!==data.selected?.28:active?1:.68,stroke:active?'#fff':'#c5b3d2','stroke-width':active?1.5:.5}));
 bindTarget(a,p);svg.append(a);
 }
 const labelPoints=ordered.filter(p=>mode==='all'||(mode==='smart'&&(priority.has(p.id)||matches.has(p.id)))||(mode==='dot'&&matches.has(p.id))).reverse();
 let extra=B+45;
 for(const p of labelPoints){
 const {x,y,rad}=coords.get(p.id),w=Math.min(R-L,Math.ceil(measure?measure.measureText(p.name).width:Math.max(30,p.name.length*12))+12),h=18,g=rad+4;
 const candidates=[[x+g,y-h/2],[x-g-w,y-h/2],[x-w/2,y-g-h],[x-w/2,y+g],[x+g,y-g-h],[x-g-w,y-g-h],[x+g,y+g],[x-g-w,y+g]];
 const valid=r=>r.x>=L&&r.x+r.w<=R&&r.y>=T&&r.y+r.h<=B&&!occupied.some(o=>collides(r,o));
 let box=candidates.map(([x,y])=>({x,y,w,h})).find(valid);
 if(!box&&mode==='all'){let best=Infinity;for(let ly=T;ly+h<=B;ly+=19)for(let lx=L;lx+w<=R;lx+=10){const q={x:lx,y:ly,w,h},dist=(lx+w/2-x)**2+(ly+h/2-y)**2;if(dist<best&&valid(q)){box=q;best=dist;}}}
 if(!box&&mode==='all'){box={x:L,y:extra,w,h};extra+=h+3;}
 if(!box)continue;
 occupied.push(box);
 if(mode==='all'&&Math.hypot(box.x+w/2-x,box.y+h/2-y)>rad+18)svg.append(line(x,y,box.x+w/2,box.y+h/2,'#a7a0ba',.4));
 const a=el('a',{href:p.href,class:'cb-plot-label','data-character':p.id,'aria-label':p.name});
 a.append(el('rect',{class:'cb-plot-label-bg',x:box.x,y:box.y,width:w,height:h,rx:3,fill:'#211d29','fill-opacity':.92}),txt(box.x+w/2,box.y+12,p.name,'middle',p.id===data.selected||matches.has(p.id)?'#e5c88d':'#e8e4f0',12));
 bindTarget(a,p);svg.append(a);
 }
 if(extra>B+45){const height=extra+8;svg.setAttribute('viewBox','0 0 '+W+' '+height);svg.style.height=height+'px';}
 status.textContent='顯示 '+visible.length+' / '+points.length+' 名｜勝率軸 '+domain.y0.toFixed(1)+'–'+domain.y1.toFixed(1)+'%'+(matches.size?'｜搜尋 '+matches.size+' 名':'');
}
function update(next){const width=next.x1-next.x0,height=next.y1-next.y0;const x0=clamp(next.x0,full.x0,Math.max(full.x0,full.x1-width)),y0=clamp(next.y0,full.y0,Math.max(full.y0,full.y1-height));domain={x0,x1:Math.min(full.x1,x0+width),y0,y1:Math.min(full.y1,y0+height)};render();}
function zoom(factor,cx=(domain.x0+domain.x1)/2,cy=(domain.y0+domain.y1)/2){
 const w=clamp((domain.x1-domain.x0)*factor,full.x1/16,full.x1),h=clamp((domain.y1-domain.y0)*factor,Math.max(2,(full.y1-full.y0)/16),full.y1-full.y0);
 update({x0:cx-w/2,x1:cx+w/2,y0:cy-h/2,y1:cy+h/2});
}
function reset(){domain={...full};render();}
card.querySelectorAll('[data-meta-mode]').forEach(b=>b.addEventListener('click',()=>{mode=b.dataset.metaMode;card.querySelectorAll('[data-meta-mode]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));render()}));
search.addEventListener('input',()=>{const q=search.value.trim().toLocaleLowerCase();matches=new Set(q?points.filter(p=>p.name.toLocaleLowerCase().includes(q)).map(p=>p.id):[]);if(matches.size){domain={...full};}render();});
card.querySelector('[data-meta-action="in"]').onclick=()=>zoom(.65);
card.querySelector('[data-meta-action="out"]').onclick=()=>zoom(1/.65);
card.querySelector('[data-meta-action="reset"]').onclick=reset;
card.querySelector('[data-meta-action="dense"]').onclick=()=>update({...full,x1:Math.min(full.x1,Math.max(full.x1/8,data.median*2,1))});
function setExpanded(value){expanded=value;card.classList.toggle('cb-meta-expanded',value);card.querySelector('[data-meta-action="expand"]').textContent=value?'關閉全螢幕':'全螢幕';card.querySelector('[data-meta-action="expand"]').setAttribute('aria-expanded',String(value));render();}
card.querySelector('[data-meta-action="expand"]').onclick=()=>setExpanded(!expanded);
document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(expanded)setExpanded(false);else hideTip();}});
tip.querySelector('button').onclick=hideTip;
svg.addEventListener('pointerdown',e=>{if(e.button!==0||e.target.closest('a'))return;drag={x:e.clientX,y:e.clientY,domain:{...domain}};skipClick=false;svg.setPointerCapture(e.pointerId);});
svg.addEventListener('pointermove',e=>{if(!drag)return;const dx=e.clientX-drag.x,dy=e.clientY-drag.y;if(Math.abs(dx)+Math.abs(dy)<5)return;skipClick=true;const d=drag.domain,sx=(d.x1-d.x0)/(geom.R-geom.L),sy=(d.y1-d.y0)/(geom.B-geom.T);update({x0:d.x0-dx*sx,x1:d.x1-dx*sx,y0:d.y0+dy*sy,y1:d.y1+dy*sy});});
const stopDrag=()=>{drag=null;setTimeout(()=>skipClick=false,0);};svg.addEventListener('pointerup',stopDrag);svg.addEventListener('pointercancel',stopDrag);
svg.addEventListener('wheel',e=>{if(!e.ctrlKey&&!e.metaKey)return;e.preventDefault();const r=svg.getBoundingClientRect(),x=clamp((e.clientX-r.left-geom.L)/(geom.R-geom.L),0,1),y=clamp((geom.B-(e.clientY-r.top))/(geom.B-geom.T),0,1);zoom(e.deltaY<0?.8:1.25,domain.x0+x*(domain.x1-domain.x0),domain.y0+y*(domain.y1-domain.y0));},{passive:false});
let lastWidth=0;new ResizeObserver(()=>{const w=stage.clientWidth;if(w!==lastWidth){lastWidth=w;render();}}).observe(stage);
render();
})();
</script>
<?php $pageContent=ob_get_clean(); include __DIR__ . '/../layout/base.php'; ?>
