<?php
/** ULGG-SORT-BRIDGE-V1: full-result server-side sorting. Original Quest PHP untouched. */
declare(strict_types=1);

function ulggSortSpecs(string $view): array {
    return $view === 'regions' ? [
        1=>['name','地域','zone_name','text'],
        2=>['samples','樣本 n','eligible_samples','number'],
        3=>['qph','QP/h','qph','number'],
        4=>['valh','VAL/h','valh','number'],
        5=>['avgqp','平均 QP','average_qp','number'],
        6=>['avgval','平均 VAL','average_value','number'],
        7=>['confidence','可信度','confidence','confidence'],
        8=>['median','中位時間','median_sec','number'],
        9=>['qpap','QP/AP','qp_per_ap','number'],
        10=>['valap','VAL/AP','value_per_ap','number'],
        11=>['composite','綜合分 V2','composite_score','number'],
    ] : [
        1=>['name','Quest','quest_name','text'],
        2=>['type','類型','quest_type','text'],
        3=>['star','★','star','number'],
        4=>['samples','樣本','occurrences','observed_number'],
        5=>['completion','完成場數','completed_count','observed_number'],
        6=>['qp','QP','qp','number'],
        7=>['qph','QP/h','qph','number'],
        8=>['valh','VAL/h','valh','number'],
        9=>['val','VAL','ulgg_val','number'],
        10=>['searchap','搜尋 AP','search_ap','number'],
        11=>['executeap','執行 AP','execute_ap','number'],
        12=>['median','中位時間','median_sec','number'],
    ];
}

function ulggSortValMap(): array {
    $path=__DIR__.'/quest_val_panel_v1.json';
    if (!is_readable($path)) return [];
    $doc=json_decode((string)file_get_contents($path),true);
    if (!is_array($doc)||($doc['schema']??'')!=='ULGG_QUEST_VAL_PANEL_V1') return [];
    $map=[];
    foreach (($doc['rows']??[]) as $v) {
        if (is_array($v) && isset($v['quest_key']) && array_key_exists('val',$v)
            && is_numeric($v['val']) && is_finite((float)$v['val'])) {
            $map[(string)$v['quest_key']]=(float)$v['val'];
        }
    }
    return $map;
}

function ulggSortValue(array $r, array $spec, array $values): mixed {
    $field=$spec[2]; $kind=$spec[3];
    $value=$field==='ulgg_val' ? ($r['average_value'] ?? $values[(string)($r['quest_key']??'')]??null) : ($r[$field]??null);
    if ($kind==='observed_number' && empty($r['observed'])) return null;
    if ($value===null || $value==='' || $value==='—') return null;
    if ($kind==='confidence') return ['低'=>1,'中'=>2,'高'=>3][$value]??null;
    if ($kind==='text') return (string)$value;
    return (is_numeric($value) && is_finite((float)$value))?(float)$value:null;
}

function ulggSortCompare(mixed $a, mixed $b, string $kind, string $dir): int {
    if ($a===null) return $b===null?0:1;
    if ($b===null) return -1;
    $cmp=$kind==='text' ? strcmp((string)$a,(string)$b) : ($a<=>$b);
    return $dir==='desc' ? -$cmp : $cmp;
}

function ulggSortRows(array $rows, array $spec, string $dir, array $values): array {
    $items=[];
    foreach ($rows as $idx=>$row) $items[]=['r'=>$row,'i'=>$idx,'val'=>ulggSortValue($row,$spec,$values)];
    usort($items,static function($a,$b)use($spec,$dir){
        $c=ulggSortCompare($a['val'],$b['val'],$spec[3],$dir);
        return $c===0?($a['i']<=>$b['i']):$c;
    });
    return array_column($items,'r');
}

function ulggSortHref(string $view, string $key, string $dir): string {
    $params=['view'=>$view];
    foreach (['q','window','region','quest','duration'] as $p) {
        if (!isset($_GET[$p]) || !is_scalar($_GET[$p])) continue;
        $value=(string)$_GET[$p];
        if (strlen($value)<=250 && $value!=='') $params[$p]=$value;
    }
    $params['u_sort']=$key; $params['u_dir']=$dir;
    return '/pages/quest_native_preview.php?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}

function ulggSortHeader(string $table, string $view, string $active, string $activeDir): string {
    $specs=ulggSortSpecs($view);
    return (string)preg_replace_callback('~(<thead\b[^>]*>)(.*?)(</thead>)~si',static function($m)use($specs,$view,$active,$activeDir){
        $index=0;
        $thead=preg_replace_callback('~<th\b([^>]*)>(.*?)</th>~si',static function($t)use(&$index,$specs,$view,$active,$activeDir){
            $col=$index++;
            if (!isset($specs[$col])) return $t[0];
            [$key,$label]=$specs[$col];
            $next=($active===$key && $activeDir==='desc')?'asc':'desc';
            $arrow=$active===$key ? ($activeDir==='asc'?' ▲':' ▼'):' ↕';
            $attrs=preg_replace('~\saria-sort=(?:"[^"]*"|\'[^\']*\')~i','',$t[1]);
            if (!preg_match('~\bclass\s*=~i',$attrs)) $attrs.=' class="qpub-th-sortable"';
            else $attrs=preg_replace('~\bclass=("|\')(.*?)\1~i','class=$1$2 qpub-th-sortable$1',$attrs,1);
            if ($active===$key) $attrs.=' aria-sort="'.($activeDir==='asc'?'ascending':'descending').'"';
            $help='';
            if (preg_match('~<button\b[^>]*data-ulgg-val-info[^>]*>.*?</button>~si',$t[2],$b)) $help=' '.$b[0];
            $href=htmlspecialchars(ulggSortHref($view,$key,$next),ENT_QUOTES,'UTF-8');
            return '<th'.$attrs.'><a class="ulgg-sort-link" href="'.$href.'">'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').$arrow.'</a>'.$help.'</th>';
        },$m[2]);
        return $m[1].$thead.$m[3];
    },$table,1);
}

function ulggSortTableRows(string $table, array $rows, array $ordered, int $colCount): string {
    if (!preg_match('~<tbody\b[^>]*>(.*?)</tbody>~si',$table,$tbody)) throw new RuntimeException('STOP=TABLE_TBODY_MISSING');
    preg_match_all('~<tr\b[^>]*>.*?</tr>~si',$tbody[1],$matches);
    if (count($matches[0])!==count($rows)) throw new RuntimeException('STOP=TABLE_ROW_COUNT_DRIFT');
    $lookup=[];
    foreach ($rows as $idx=>$row) {
        $key=(string)($row['zone_key']??'');
        if (isset($lookup[$key])) throw new RuntimeException('STOP=REGION_DUP_KEY');
        $lookup[$key]=$matches[0][$idx];
    }
    $render=[];
    foreach ($ordered as $idx=>$row) {
        $key=(string)($row['zone_key']??'');
        if (!isset($lookup[$key])) throw new RuntimeException('STOP=REGION_KEY_DRIFT');
        $html=$lookup[$key];
        $html=preg_replace('~(<td\b[^>]*class="[^"]*\bqpub-col-rank\b[^"]*"[^>]*>)\s*[^<]*\s*(</td>)~si','$1'.($idx+1).'$2',$html,1);
        $render[]=$html;
    }
    return str_replace($tbody[0],str_replace($tbody[1],implode("\n",$render),$tbody[0]),$table);
}

function ulggSortQuestRow(array $r, int $rank, array $values): string {
    $e=static fn(mixed $v):string=>htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $observed=!empty($r['observed']);
    $val=$r['average_value']??($values[(string)($r['quest_key']??'')]??null);
    $num=static function(mixed $v,int $d=0):string {
        if ($v===null || !is_numeric($v) || !is_finite((float)$v)) return '—';
        return number_format((float)$v,$d,'.',',');
    };
    $int=static fn(mixed $v):string=>($v===null||!is_numeric($v))?'—':number_format((float)$v,0,'.',',');
    $low=$observed && !empty($r['low_sample']) ? ' class="is-low-sample"':'';
    $id=isset($r['quest_id']) && is_numeric($r['quest_id']) ? '<span class="qpub-sub">#'.$int($r['quest_id']).'</span>':'';
    $name=$e($r['quest_name']??'');
    $ambiguous=!empty($r['name_ambiguous']) ? ' <span class="qpub-badge">同名多 ID</span>' : '';
    $td=static fn(string $v,string $cls='qpub-col-num'):string=>'<td class="'.$cls.'">'.$v.'</td>';
    $cells=[
        $td((string)$rank,'qpub-col-rank'),
        $td($name.' '.$id.$ambiguous,'qpub-col-name'),
        '<td>'.$e($r['quest_type']??'').'</td>',
        $td($int($r['star']??null)),
        $td($int($observed?($r['occurrences']??null):null)),
        $td($int($observed?($r['completed_count']??null):null),'qpub-col-num qpub-col-optional'),
        $td($int($r['qp']??null),'qpub-col-num qpub-col-optional'),
        $td($num($r['qph']??null,1),'qpub-col-num qpub-col-strong'),
        $td($num($r['valh']??null,0)),
        $td($num($val,0),'qpub-col-num qpub-col-optional'),
        $td($int($r['search_ap']??null),'qpub-col-num qpub-col-optional'),
        $td($int($r['execute_ap']??null),'qpub-col-num qpub-col-optional'),
        $td($e($r['median_text']??'—'),'qpub-col-num qpub-col-optional'),
    ];
    return '<tr'.$low.'>'.implode("\n",$cells).'</tr>';
}

function ulggSortQuestBody(string $table,array $sorted,int $page,int $pageSize,array $values): string {
    if (!preg_match('~<tbody\b[^>]*>(.*?)</tbody>~si',$table,$body)) throw new RuntimeException('STOP=QUEST_TBODY_MISSING');
    $subset=array_slice($sorted,($page-1)*$pageSize,$pageSize);
    $html=[];
    foreach ($subset as $i=>$row) $html[]=ulggSortQuestRow($row,($page-1)*$pageSize+$i+1,$values);
    return str_replace($body[0],str_replace($body[1],implode("\n",$html),$body[0]),$table);
}

function ulggSortPager(string $content,string $key,string $dir): string {
    return (string)preg_replace_callback('~<nav\b[^>]*class="[^"]*\bqpub-pager\b[^"]*"[^>]*>.*?</nav>~si',static function($m)use($key,$dir){
        return (string)preg_replace_callback('~\bhref="([^"]+)"~si',static function($h)use($key,$dir){
            $url=html_entity_decode($h[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
            $p=parse_url($url);
            if ($p===false) return $h[0];
            $q=[];parse_str($p['query']??'',$q);
            $q['u_sort']=$key;$q['u_dir']=$dir;
            return 'href="'.htmlspecialchars(($p['path']??'quest.php').'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986),ENT_QUOTES,'UTF-8').'"';
        },$m[0]);
    },$content);
}

function ulggSortableTables(string $content,string $view,array $meta):string {
    $specs=ulggSortSpecs($view);
    $byKey=[];foreach ($specs as $spec) $byKey[$spec[0]]=$spec;
    $requested=isset($_GET['u_sort']) && is_string($_GET['u_sort']) ? $_GET['u_sort'] : '';
    $key=isset($byKey[$requested])?$requested:'';
    $direction=isset($_GET['u_dir'])&&$_GET['u_dir']==='asc'?'asc':'desc';
    $native=isset($_GET['sort'])&&is_string($_GET['sort'])?$_GET['sort']:'qph';
    $nativeMap=['ap'=>'qpap','composite'=>'composite'];
    $native=$nativeMap[$native]??$native;
    $active=$key?:($view==='regions'&&$native==='qph'?'qph':(isset($byKey[$native])?$native:'qph'));
    $activeDir=$key?$direction:(isset($_GET['dir'])&&$_GET['dir']==='asc'?'asc':'desc');
    $valMap=$view==='quest'?ulggSortValMap():[];
    $regionIndex=0;
    $content=(string)preg_replace_callback('~<table\b[^>]*class=("|\')[^"\']*\bqpub-table\b[^"\']*\1[^>]*>.*?</table>~si',
        static function($m)use($view,$meta,$specs,$byKey,$key,$direction,$active,$activeDir,$valMap,&$regionIndex){
            $table=$m[0];
            // Only the 12/13-column Quest analytics tables (never modal/other tables).
            preg_match('~<thead\b[^>]*>(.*?)</thead>~si',$table,$thead);
            $thCount=preg_match_all('~<th\b[^>]*>~si',$thead[1]??'');
            if ($thCount!==($view==='regions'?12:13)) return $table;
            // Stars probability matrix may also have 12 columns: never touch it.
            if ($view==='regions' && (!str_contains($thead[1], '平均 QP') || !str_contains($thead[1], 'VAL/AP'))) return $table;
            if ($view==='quest' && (!str_contains($thead[1], '搜尋 AP') || !str_contains($thead[1], '完成場數'))) return $table;
            $table=ulggSortHeader($table,$view,$active,$activeDir);
            if (!$key) return $table;
            if ($view==='regions') {
                $groups=$meta['groups']??[];
                if (!isset($groups[$regionIndex]) || !is_array($groups[$regionIndex])) throw new RuntimeException('STOP=REGION_GROUP_MISSING');
                $rows=$groups[$regionIndex++];
                $ordered=ulggSortRows($rows,$byKey[$key],$direction,[]);
                return ulggSortTableRows($table,$rows,$ordered,12);
            }
            $all=$meta['all']??null;
            if (!is_array($all)) throw new RuntimeException('STOP=QUEST_ALL_ROWS_MISSING');
            $ordered=ulggSortRows($all,$byKey[$key],$direction,$valMap);
            return ulggSortQuestBody($table,$ordered,(int)($meta['page']??1),(int)($meta['pageSize']??50),$valMap);
        },$content);
    if ($key && $view==='regions' && $regionIndex!==count($meta['groups']??[])) throw new RuntimeException('STOP=REGION_GROUP_COUNT');
    if ($key && $view==='quest') $content=ulggSortPager($content,$key,$direction);
    $content.='<style>.quest-native-content .qpub-table th a.ulgg-sort-link{color:inherit;text-decoration:none;white-space:nowrap}.quest-native-content .qpub-table th a.ulgg-sort-link:hover,.quest-native-content .qpub-table th a.ulgg-sort-link:focus-visible{color:#80beff;text-decoration:underline}.quest-native-content .qpub-table th[aria-sort] a.ulgg-sort-link{font-weight:700}</style>';
    return $content;
}
