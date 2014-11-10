<?php
ini_set('default_socket_timeout', -1);
class UserLog extends Rqueue
{

	const REDIS_KEY = 'user_log';

	const REDIS_PROCESS_KEY = 'user_log_processing';
	const REDIS_SUCCESS_KEY = 'user_log_success';
	const REDIS_ERROR_KEY = 'user_log_error';

	public static $count = 0;

	public static $instance = null;

	public function __construct()
	{

	}

	public function work()
	{
		$Redis = self::get_redis_instance();

		if ($Redis->llen(self::REDIS_KEY) == 0) {
			sleep(1);
			return true;
		}

		$data = $Redis->brpoplpush(self::REDIS_KEY, self::REDIS_PROCESS_KEY, 0);
		// $data = $Redis->rpoplpush(self::REDIS_KEY, self::REDIS_PROCESS_KEY);

		$rt = $this->do_work($data);

		// 如果执行成功，则将该值删除
		if ($rt) {
			$Redis->lRem(self::REDIS_PROCESS_KEY, $data, 0);
		} else {
			// $Redis->brpoplpush(self::REDIS_PROCESS_KEY, self::REDIS_ERROR_KEY, 0);
			$Redis->rpoplpush(self::REDIS_PROCESS_KEY, self::REDIS_ERROR_KEY);
		}

		// $rt = static::$Rlog->info('UserLog', $data);
		// if (!$rt) static::$Rlog->error('UserLog', $data);
		// echo static::$count.PHP_EOL;
		// if (static::$count >= 10) exit(" exec ".static::$count." . user log is stop".PHP_EOL);
	}

	public function do_work()
	{
		$random = rand(1,2);
		if ($random == 1) {
			return true;
		} else {
			return false;
		}
	}

	public static function get_redis_instance()
	{
		if (!empty(self::$instance)) return self::$instance;

		try {
			$Redis = new redis();
			$Redis->connect('192.168.140.129', 6379, 2.5);
			self::$instance = $Redis;
			return self::$instance;
		}
		catch (Execption $e) {
			echo $e->getMessage();
			exit(0);
		}
	}
}

$UserLog = new Userlog();
$UserLog->work();