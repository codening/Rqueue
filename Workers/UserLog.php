<?php
class UserLog extends Rqueue
{

	public static $count = 0;

	public function __construct()
	{

	}

	public function work()
	{
		static::$count ++;

		// static::$Rlog->info('UserLog', " exec ".static::$count." .");
		sleep(1);
		// echo static::$count.PHP_EOL;
		// if (static::$count >= 10) exit(" exec ".static::$count." . user log is stop".PHP_EOL);
	}
}