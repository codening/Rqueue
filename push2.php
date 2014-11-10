<?php

$Redis = new redis();
$Redis->connect('192.168.140.129', 6379, 2.5);
// $Redis->auth('test');

$queue_key = 'user_log';
$queue_key2 = 'user_log2';
$queue_key3 = 'user_log3';
$queue_key4 = 'user_log4';
$queue_key5 = 'user_log5';

$count = 10000000;
for ($i=0; $i < $count; $i++) { 
//	$Redis->lPush($queue_key, $i);
//	$Redis->lPush($queue_key2, $i);
//	$Redis->lPush($queue_key3, $i);
	$Redis->lPush($queue_key4, $i);
	$Redis->lPush($queue_key5, $i);
}
// $Redis->setex("name", 60, 'zhanghaining');
// var_dump($Redis->lSize($queue_key));
// $Redis->connect("")
