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
			if ($Redis->llen(self::REDIS_PROCESS_KEY) != 0) {
				$Redis->rpoplpush(self::REDIS_PROCESS_KEY, self::REDIS_KEY);
			} else {
				sleep(1);
				return true;
			}
		}

		$this->do_work();
	}

	public function _do($data)
	{
		return true;
		$random = rand(1,2);
		if ($random == 1) {
			return true;
		} else {
			return false;
		}
	}

	public function do_work()
	{
		$Redis = self::get_redis_instance();
		$data = $Redis->rpoplpush(self::REDIS_KEY, self::REDIS_PROCESS_KEY);

		// 处理数据
		$rt = $this->_do($data);
		if ($rt) {
			$this->success();
		} else {
			$this->error();
		}
	}

	public function success()
	{
		$Redis = self::get_redis_instance();
		$Redis->rpoplpush(self::REDIS_PROCESS_KEY, self::REDIS_SUCCESS_KEY);
	}

	public function error()
	{
		$Redis = self::get_redis_instance();
		$Redis->rpoplpush(self::REDIS_PROCESS_KEY, self::REDIS_ERROR_KEY);
	}

	public static function get_redis_instance()
	{
		if (!empty(self::$instance)) return self::$instance;

		try {
			$redis_conf['host'] = '127.0.0.1';
			$redis_conf['port'] = '6379';
			$redis_conf['timeout'] = '2.5';
			$redis_conf['auth'] = 'test';
			$Redis = new redis();
			$Redis->connect($redis_conf['host'], $redis_conf['port'], $redis_conf['timeout']);
			if (isset($redis_conf['auth']) && !empty($redis_conf['auth'])) $Redis->auth($redis_conf['auth']);
			
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