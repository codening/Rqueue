<?php
class UserLog5 extends Rqueue
{

	public static $count = 0;

	public function __construct()
	{

	}

	public function work()
	{
		static::$count ++;

		// static::$Rlog->info('UserLog2', " exec ".static::$count." .");
		sleep(2);
		// echo static::$count.PHP_EOL;
		// if (static::$count >= 10) exit(" exec ".static::$count." . user log is stop".PHP_EOL);
	}
}