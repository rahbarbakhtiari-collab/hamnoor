<?php
$root=dirname(__DIR__);$fails=[];function de($ok,$m){global $fails;echo($ok?'PASS: ':'FAIL: ').$m."\n";if(!$ok)$fails[]=$m;}
$main=file_get_contents($root.'/hamnoor.php');$schema=file_get_contents($root.'/includes/core/domain/class-hamnoor-schema-manifest.php');$db=file_get_contents($root.'/includes/class-hamnoor-db.php');$bus=file_get_contents($root.'/includes/core/class-hamnoor-event-bus.php');$domain=file_get_contents($root.'/includes/core/class-hamnoor-domain-events.php');$api=file_get_contents($root.'/includes/core/class-hamnoor-core-api.php');$store=file_get_contents($root.'/includes/core/class-hamnoor-domain-event-store.php');$mig=file_get_contents($root.'/includes/core/migrations/class-hamnoor-migrations.php');
de(strpos($main,"HAMNOOR_VERSION', '4.0.19'")!==false,'Core version 4.0.19');
de(strpos($main,"HAMNOOR_DB_VERSION', '4.6.0'")!==false,'DB schema 4.6.0');
de(strpos($schema,"'domain_events'")!==false,'domain_events in schema manifest');
de(strpos($db,'CREATE TABLE $domain_events')!==false && strpos($db,'UNIQUE KEY event_key')!==false,'durable event table has unique business key');
de(strpos($mig,'foundation_4_6_0_durable_domain_events')!==false,'ordered 4.6 migration exists');
de(strpos($bus,'Hamnoor_Domain_Event_Store::append')!==false && strpos($bus,"do_action('hamnoor/event/'")>strpos($bus,'Hamnoor_Domain_Event_Store::append'),'event is journaled before listeners run');
de(substr_count($domain,"'durable' => true")>=2,'identity and participation facts request durable journal');
de(strpos($domain,'PARTICIPATION_COMPLETED . \':v\'')!==false && strpos($domain,'IDENTITY_CREATED . \':v\'')!==false,'canonical business event keys are stable');
de(strpos($api,'durable_events_after')!==false && strpos($api,'durable_event_for_subject')!==false,'Core API exposes read-only recovery surface');
de(strpos($store,'replayed')!==false,'replayed context is explicit');
if($fails){fwrite(STDERR,count($fails)." durable event check(s) failed.\n");exit(1);} echo "All durable domain event contracts passed.\n";
