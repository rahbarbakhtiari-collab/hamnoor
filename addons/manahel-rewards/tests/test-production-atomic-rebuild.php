<?php
define('ABSPATH', __DIR__ . '/');
class WP_Error { public $code; public $data; function __construct($c='',$m='',$d=null){$this->code=$c;$this->data=$d;} function get_error_code(){return $this->code;} }
function __($s,$d=null){return $s;}
$GLOBALS['mr_log']=array();
class Manahel_Rewards_DB {
    public static $fail_update=false;
    public static function storage_is_transactional(){return true;}
    public static function begin(){$GLOBALS['mr_log'][]='begin';return true;}
    public static function commit(){$GLOBALS['mr_log'][]='commit';return true;}
    public static function rollback(){$GLOBALS['mr_log'][]='rollback';return true;}
    public static function table($n){return 't_'.$n;}
    public static function utc_now_mysql(){return '2026-08-26 17:00:00';}
}
class FakeWpdb {
    public $last_error='';
    public function prepare($q,...$args){
        foreach($args as $a){$q=preg_replace('/%[ds]/',is_int($a)?(string)$a:"'".addslashes((string)$a)."'",$q,1);} return $q;
    }
    public function get_row($q){$GLOBALS['mr_log'][]=(stripos($q,'FOR UPDATE')!==false?'lock':'row');return (object)array('id'=>7,'balance'=>90,'status'=>'active');}
    public function get_var($q){$GLOBALS['mr_log'][]='sum';return 120;}
    public function query($q){
        if(stripos($q,'UPDATE t_accounts')!==false){$GLOBALS['mr_log'][]='update'; return Manahel_Rewards_DB::$fail_update?false:1;}
        return 1;
    }
}
$GLOBALS['wpdb']=new FakeWpdb();
require_once dirname(__DIR__) . '/includes/class-manahel-rewards-account-service.php';
$fails=array(); function ar($ok,$label){global $fails;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$fails[]=$label;}
$result=Manahel_Rewards_Account_Service::rebuild_balance(7);
ar(120===$result,'atomic rebuild returns authoritative Ledger SUM');
ar(array('begin','lock','sum','update','commit')===$GLOBALS['mr_log'],'lock order is begin -> account FOR UPDATE -> Ledger SUM -> balance UPDATE -> commit');

$GLOBALS['mr_log']=array(); Manahel_Rewards_DB::$fail_update=true;
$result=Manahel_Rewards_Account_Service::rebuild_balance(7);
ar($result instanceof WP_Error,'failed balance update returns WP_Error');
ar(in_array('rollback',$GLOBALS['mr_log'],true) && !in_array('commit',$GLOBALS['mr_log'],true),'failure path rolls back and never commits');
if($fails){fwrite(STDERR,count($fails)." atomic rebuild test(s) failed.\n");exit(1);} echo "All atomic rebuild runtime tests passed.\n";
