<?php
abstract class Rqueue
{
	public static $Rlog = null;

	public static $masterPid = 0;

	// 子进程map,array('pid'=>array('work_name'=>'abc', 'start_time'=>0, 'restart_time'=>0, 'restart_count'=>0))
	public static $pid_workers = array();

	public static function set_log($log)
	{
		static::$Rlog = $log;
	}

	abstract function work();

	/**
     * 使之脱离终端，变为守护进程
     * @return void
     */
    public static function daemonize()
    {
        // 设置umask
        umask(0);
        // fork一次
        $pid = pcntl_fork();
        if(-1 == $pid)
        {
            // 出错退出
            exit("Can not fork");
        }
        elseif($pid > 0)
        {
            // 父进程，退出
            exit(0);
        }
        // 成为session leader
        if(-1 == posix_setsid())
        {
            // 出错退出
            exit("Setsid fail");
        }
    
        // 再fork一次
        $pid2 = pcntl_fork();
        if(-1 == $pid2)
        {
            // 出错退出
            exit("Can not fork");
        }
        elseif(0 !== $pid2)
        {
            // 禁止进程重新打开控制终端
            exit(0);
        }
    }

    /**
     * 保存主进程pid
     * @return void
     */
    public static function save_pid()
    {
        // 保存在变量中
        self::$masterPid = posix_getpid();
        
        // 保存到文件中，用于实现停止、重启
        if(false === @file_put_contents(RQUEUE_PID_FILE, self::$masterPid))
        {
            exit("\033[31;40mCan not save pid to pid-file(" . RQUEUE_PID_FILE . ")\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
        }
        
        // 更改权限
        chmod(RQUEUE_PID_FILE, 0644);
    }

	/**
	 * 创建子进程
	 */
	public static function create_work($obj)
	{
		$pid = pcntl_fork(); //创建子进程
		
		// 先处理收到的信号
        pcntl_signal_dispatch();

		if ($pid > 0) { //父进程
			return $pid;
		} else if ($pid == 0) {//子进程
			// 子进程忽略所有信号
			static::ignoreSignal();
			$pid = posix_getpid();

			// 存入变量中
			static::$pid_workers[$pid]['work_name'] = get_class($obj);
			static::$pid_workers[$pid]['start_time'] = time();
			static::$pid_workers[$pid]['restart_time'] = 0;
			static::$pid_workers[$pid]['restart_count'] = 0;
			static::$pid_workers[$pid]['status_name'] = 'runing';
			static::$Rlog->info('server',"Process {$pid} was created");
			echo PHP_EOL."\033[32;40m * Process ".static::$pid_workers[$pid]['work_name']." [{$pid}] is runing\033[0m\n";
			while(true)
			{
				$rt = $obj->work();
			}
			exit(0);
		} else {
			static::$Rlog->notice('server',"create worker fail");
		}
	}

	public static function loop()
	{
		while(true)
        {
            sleep(1);
            // 检查是否有进程退出
            self::check_worker_exit();
            // 触发信号处理
            pcntl_signal_dispatch();
        }
	}

	/**
	 * 检测work进程是否退出
	 */
	public static function check_worker_exit()
	{
		while(($pid = pcntl_waitpid(-1, $status, WUNTRACED | WNOHANG)) != 0)
		{
			// 出错
            if($pid < 0)
            {
                static::$Rlog->notice('server','pcntl_waitpid return '.$pid.' and pcntl_get_last_error = ' . pcntl_get_last_error());
                return $pid;
            }

            // 重启退出的work
            var_dump($pid);
            // var_dump(static::$pid_workers);
            // self::create_work(new static::$pid_workers[$pid]['work_name']());
		}
		/*$pid = pcntl_wait($status, WUNTRACED);;
		if (pcntl_wifexited($status)) {
			echo "\n\n* Sub process: {$pid} exited with {$status} ";
		}*/
	}

	/**
     * 忽略信号
     * @return void
     */
    protected static function ignoreSignal()
    {
        // 设置忽略信号
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGTTOU, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGALRM, SIG_IGN);
        pcntl_signal(SIGINT, SIG_IGN);
        pcntl_signal(SIGUSR1, SIG_IGN);
        pcntl_signal(SIGUSR2, SIG_IGN);
        pcntl_signal(SIGHUP, SIG_IGN);
    }

    /**
     * 设置server信号处理函数
     * @param null $null
     * @param int $signal
     * @return void
     */
    public static function signal_handler($signal)
    {
        switch($signal)
        {
            // 停止服务信号
            case SIGINT:
            	static::$Rlog->notice('server', 'Rqueue is shutting down');
                self::all_stop();
                break;
            // 测试用
            case SIGUSR1:
                break;
            // worker退出信号
            case SIGCHLD:
                // 这里什么也不做
                // self::checkWorkerExit();
                break;
            // 平滑重启server信号
            case SIGHUP:
            	static::$Rlog->notice('server', 'Rqueue is reloading');
            	self::restart_workers();
                // Lib\Config::reload();
                // self::notice("Workerman reloading");
                // $pid_worker_name_map = self::getPidWorkerNameMap();
                // $pids_to_restart = array();
                // foreach($pid_worker_name_map as $pid=>$worker_name)
                // {
                //     // 如果对应进程配置了不热启动则不重启对应进程
                //     if(Lib\Config::get($worker_name.'.no_reload'))
                //     {
                //         // 发送reload信号，以便触发onReload方法
                //         posix_kill($pid, SIGHUP);
                //         continue;
                //     }
                //     $pids_to_restart[] = $pid;
                // }
                // self::addToRestartPids($pids_to_restart);
                // self::restartPids();
                break;
        }
    }

    /**
     * 安装相关信号控制器
     * @return void
     */
    public static function install_signal()
    {
        // 设置终止信号处理函数
        pcntl_signal(SIGINT, array('Rqueue', 'signal_handler'), false);
        // 设置SIGUSR1信号处理函数,测试用
        pcntl_signal(SIGUSR1, array('Rqueue', 'signal_handler'), false);
        // 设置SIGUSR2信号处理函数,平滑重启Server
        pcntl_signal(SIGHUP, array('Rqueue', 'signal_handler'), false);
        // 设置子进程退出信号处理函数
        pcntl_signal(SIGCHLD, array('Rqueue', 'signal_handler'), false);
    
        // 设置忽略信号
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGTTOU, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGALRM, SIG_IGN);
    }

    public static function all_restart()
    {

    }

    /**
     * 重启workers
     */
    public static function restart_workers()
    {
    	// 向所有子进程发送重启信号
        foreach(static::$pid_workers as $pid=>&$worker)
        {
        	$worker['restart_time'] = time();
        	$worker['restart_count'] = $worker['restart_count'] ++;
        	$worker['status'] = 'restart';
            // 发送SIGINT信号
            posix_kill($pid, SIGHUP);
        }
    }

    /**
     * 终止所有进程
     */
    public static function all_stop()
    {
    	// 如果没有子进程，则直接终止
    	if (empty(static::$pid_workers)) exit(0);
    	// 向所有子进程发送终止信号
        foreach(static::$pid_workers as $pid=>&$worker)
        {
            // 发送SIGINT信号
            posix_kill($pid, SIGINT);
        }
    }
}