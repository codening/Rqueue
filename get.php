<?php

$Redis = new redis();
$Redis->connect('192.168.140.129', 6379, 2.5);
$Redis->auth('test');

$queue_key = 'user_log';

$count = 10;
for ($i=0; $i < $count; $i++) { 
	$Redis->rPush($queue_key, $i);
}
// $Redis->setex("name", 60, 'zhanghaining');
var_dump($Redis->get('name'));
// $Redis->connect("")
