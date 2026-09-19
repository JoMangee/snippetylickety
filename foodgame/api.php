<?php
declare(strict_types=1);
ini_set('display_errors','0'); error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff');
function out(array $body,int $status=200,array $headers=[]): void { http_response_code($status); foreach($headers as $k=>$v){header($k.': '.$v);} $json=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); echo $json===false ? '{"ok":false,"error":"server_error"}' : $json; exit; }
function atomic_save(string $file,string $text): bool { $tmp=tempnam(dirname($file),'.foodgame-'); if($tmp===false)return false; if(file_put_contents($tmp,$text,LOCK_EX)===false){@unlink($tmp);return false;} @chmod($tmp,0600); if(!@rename($tmp,$file)){@unlink($tmp);return false;} return true; }
function blank_player(): array { return ['level'=>1,'total_xp'=>0,'streak'=>0,'spice_tolerance'=>8,'entries'=>[]]; }
function stats(array $p): array { $level=max(1,(int)($p['level']??1)); $xp=max(0,(int)($p['total_xp']??0)); $floor=($level-1)*($level-1)*10; $ceiling=$level*$level*10; return ['level'=>$level,'total_xp'=>$xp,'xp_progress'=>max(0,$xp-$floor),'xp_needed'=>max(1,$ceiling-$floor),'xp_to_next'=>$ceiling,'streak'=>max(0,(int)($p['streak']??0)),'spice_tolerance'=>max(0,min(8,(int)($p['spice_tolerance']??8))),'meals_logged'=>count($p['entries']??[])]; }
function recent(array $p): array { return array_values(array_slice(array_reverse(is_array($p['entries']??null)?$p['entries']:[]),0,25)); }
$placeholder='REPLACE_WITH_A_LONG_RANDOM_TOKEN'; $config=dirname(__DIR__).DIRECTORY_SEPARATOR.'foodgame-config.php';
if(is_file($config)){ $loaded=require $config; if(is_array($loaded)&&isset($loaded['api_key'])&&!defined('FOODGAME_API_KEY'))define('FOODGAME_API_KEY',(string)$loaded['api_key']); }
$secret=defined('FOODGAME_API_KEY')?(string)constant('FOODGAME_API_KEY'):$placeholder; $key=$_GET['key']??'';
if(!is_string($key)||$secret===''||$secret===$placeholder||!hash_equals($secret,$key))out(['ok'=>false,'error'=>'unauthorized'],401);
$dir=defined('FOODGAME_STORAGE_DIR')?(string)constant('FOODGAME_STORAGE_DIR'):dirname(__DIR__).DIRECTORY_SEPARATOR.'foodgame-data';
if($dir===''||(!is_dir($dir)&&!@mkdir($dir,0700,true)))out(['ok'=>false,'error'=>'storage_unavailable'],500); @chmod($dir,0700);
$now=time(); $ip=is_string($_SERVER['REMOTE_ADDR']??null)?$_SERVER['REMOTE_ADDR']:'unknown'; $rateLock=@fopen($dir.DIRECTORY_SEPARATOR.'rate.lock','c');
if($rateLock===false||!@flock($rateLock,LOCK_EX)){if(is_resource($rateLock))@fclose($rateLock);out(['ok'=>false,'error'=>'rate_limit_unavailable'],503);}
$rateFile=$dir.DIRECTORY_SEPARATOR.'rate-'.hash('sha256',$ip).'.json'; $old=json_decode((string)@file_get_contents($rateFile),true); $old=is_array($old)?$old:[]; $old=array_values(array_filter($old,static fn($t)=>(int)$t>$now-60));
if(count($old)>=60){@flock($rateLock,LOCK_UN);@fclose($rateLock);out(['ok'=>false,'error'=>'rate_limited'],429,['Retry-After'=>'60']);}
$old[]=$now; $saved=atomic_save($rateFile,json_encode($old)?:'[]'); @flock($rateLock,LOCK_UN); @fclose($rateLock); if(!$saved)out(['ok'=>false,'error'=>'rate_limit_unavailable'],503);
$dataFile=$dir.DIRECTORY_SEPARATOR.'foodgame-data.json'; $lock=@fopen($dir.DIRECTORY_SEPARATOR.'foodgame-data.lock','c');
if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);out(['ok'=>false,'error'=>'storage_unavailable'],500);}
$store=json_decode((string)@file_get_contents($dataFile),true); if(!is_array($store))$store=['version'=>1,'players'=>[]]; if(!is_array($store['players']??null))$store['players']=[];
$player=$_GET['player']??'Cooper'; $action=$_GET['action']??'stats';
if(!is_string($player)||!preg_match('/^[A-Za-z0-9 _-]{1,32}$/',$player)){$e=$lock;@flock($e,LOCK_UN);@fclose($e);out(['ok'=>false,'error'=>'invalid_player'],400);}
if(!is_string($action)||!in_array($action,['stats','entries','log'],true)){$e=$lock;@flock($e,LOCK_UN);@fclose($e);out(['ok'=>false,'error'=>'invalid_action'],400);}
$catalog=['noodle-masterpiece'=>['name'=>'Noodle Masterpiece','spice'=>8],'scrap-mechanic-snack'=>['name'=>'Scrap Mechanic Snack','spice'=>3],'boss'=>['name'=>'Boss Meal','spice'=>6],'fruit-fuel'=>['name'=>'Fruit Fuel','spice'=>1]];
$meal=$_GET['meal']??null; if($meal!==null&&(!is_string($meal)||!isset($catalog[$meal]))){@flock($lock,LOCK_UN);@fclose($lock);out(['ok'=>false,'error'=>'invalid_meal'],400);}
$rating=null; if(isset($_GET['rating'])){$r=$_GET['rating'];if(!is_string($r)||!preg_match('/^(10|[0-9])$/',$r)){@flock($lock,LOCK_UN);@fclose($lock);out(['ok'=>false,'error'=>'invalid_rating'],400);}$rating=(int)$r;}
$new=false; if(isset($_GET['new'])&&is_string($_GET['new']))$new=in_array(strtolower($_GET['new']),['1','true','yes','on'],true);
$p=is_array($store['players'][$player]??null)?$store['players'][$player]:blank_player(); if(!is_array($p['entries']??null))$p['entries']=[];
$result=['ok'=>true,'player'=>$player,'meal'=>$meal,'rating'=>$rating,'new'=>$new,'stats'=>stats($p),'entries'=>recent($p)];
if($action==='log'){
 if($meal===null||$rating===null){@flock($lock,LOCK_UN);@fclose($lock);out(['ok'=>false,'error'=>'log_requires_meal_and_rating'],400);}
 $info=$catalog[$meal]; $xp=10+($new?15:0)+($rating>=7?5:0)+($meal==='boss'?20:0); $tol=max(0,min(8,(int)($p['spice_tolerance']??8))); $spice=(int)$info['spice']; $chance=0;$heat=0;$duration=0;$debuff=false;
 if($tol>=$spice){$resultName='easy';$chance=100;} elseif($tol>=4){$resultName='hot';$chance=(int)round(50+($tol-4)*12.5);} elseif($tol>=1){$resultName='low-tolerance';$chance=30;$heat=random_int(0,5);$duration=60;$debuff=true;} else {$resultName='debuffed';$debuff=true;}
 $buffs=[]; if($meal==='noodle-masterpiece'&&$chance>0&&random_int(1,100)<=$chance)$buffs=['highly energized (2x base energy)','heated engine (+70% speed, +20% acceleration)','energy consumption 2x','duration 15 minutes'];
 $entry=['timestamp'=>gmdate('c'),'meal'=>$meal,'meal_name'=>$info['name'],'spice'=>$spice,'rating'=>$rating,'new'=>$new,'xp_gained'=>$xp,'buffs_applied'=>$buffs,'spice_result'=>$resultName,'buff_chance_percent'=>$chance,'heat_damage_per_4_seconds'=>$heat,'heat_duration_seconds'=>$duration,'debuff'=>$debuff];
 $p['total_xp']=max(0,(int)($p['total_xp']??0))+$xp; $p['level']=max(1,(int)($p['level']??1)); while($p['total_xp']>=$p['level']*$p['level']*10)$p['level']++; $p['streak']=max(0,(int)($p['streak']??0))+1; $p['spice_tolerance']=$tol; $p['entries'][]=$entry; if(count($p['entries'])>100)$p['entries']=array_slice($p['entries'],-100); $store['version']=1;$store['players'][$player]=$p;
 $json=json_encode($store,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); if($json===false||!atomic_save($dataFile,$json.PHP_EOL)){@flock($lock,LOCK_UN);@fclose($lock);out(['ok'=>false,'error'=>'storage_write_failed'],500);}
 $result['xp_gained']=$xp;$result['entry']=$entry;$result['buffs_applied']=$buffs;$result['stats']=stats($p);$result['entries']=recent($p);
}
@flock($lock,LOCK_UN);@fclose($lock); out($result);
