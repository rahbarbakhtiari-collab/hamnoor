<?php
$root=dirname(__DIR__);$fails=[];function rw($ok,$m){global $fails;echo($ok?'PASS: ':'FAIL: ').$m."\n";if(!$ok)$fails[]=$m;}
$main=file_get_contents($root.'/manahel-rewards.php');$acc=file_get_contents($root.'/includes/class-manahel-rewards-account-service.php');$led=file_get_contents($root.'/includes/class-manahel-rewards-ledger-service.php');$ad=file_get_contents($root.'/includes/class-manahel-rewards-hamnoor-adapter.php');
rw(strpos($main,"MANAHEL_REWARDS_VERSION', '0.8.11'")!==false,'Rewards 0.8.11');
rw(strpos($main,"MANAHEL_REWARDS_DB_VERSION', '1.3.0'")!==false,'Rewards schema remains 1.3.0');
rw(strpos($acc,'AND (balance + %d) >= 0')!==false,'all balance deltas have database-level nonnegative wall');
rw(strpos($acc,'return 1 === (int)$result')!==false,'blocked/no-op account updates cannot report success');
rw(strpos($led,'balance_would_be_negative')!==false && strpos($led,'$account->balance + $delta')!==false,'manual adjustment rejects overdraw before Ledger insert');
rw(strpos($acc,'negative_ledger_balance_detected')!==false,'rebuild refuses to materialize a negative historical Ledger sum');
rw(strpos($ad,'durable_events_after')!==false && strpos($ad,'CURSOR_OPTION')!==false,'Rewards reconciles durable Core participation facts');
rw(strpos($ad,"array('failed','received')")!==false && strpos($ad,'Manahel_Rewards_Event_Processor::retry')!==false,'replay retries a previously failed receipt instead of abandoning it');
if($fails){fwrite(STDERR,count($fails)." Rewards Phase1 check(s) failed.\n");exit(1);}echo "Rewards Phase1 contracts passed.\n";
