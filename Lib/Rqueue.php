<?php
abstract class Rqueue
{
    /**
     * 服务状态 启动中
     * @var integer
     */ 
    const STATUS_STARTING = 1;
    
    /**
     * 服务状态 运行中
     * @var integer
     */
    const STATUS_RUNNING = 2;
    
    /**
     * 服务状态 关闭中
     * @var integer
     */
    const STATUS_SHUTDOWN = 4;
    
    /**
     * 服务状态 平滑重启中
     * @var integer
     */
    const STATUS_RESTARTING_WORKERS = 8;

    // 进程最大重启次数
    const RESTART_COUNT_MAX = 5;

    // 主进程id
    protected static $master_pid = 0;

    // 服务运行状态
    protected static $service_status = self::STATUS_STARTING;

    // 子进程map,array('pid'=>'worker_name')
    protected static $pid_worker = array();

    // 子进程信息 array('worker_name'=>array('pid'=>0, 'restart_time'=>0, 'restart_count'=>0))
    protected static $worker_info = array();

    abstract function work();

    /**
     * 使之脱离终端，变为守护进程
     * @return void
     */
    protected static function daemonize()
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
    protected static function save_pid()
    {
        // 保存在变量中
        self::$master_pid = posix_getpid();
        
        // 保存到文件中，用于实现停止、重启
        if(false === @file_put_contents(RQUEUE_PID_FILE, self::$master_pid))
        {
            exit("\033[31;40mCan not save pid to pid-file(" . RQUEUE_PID_FILE . ")\033[0m\n\n\033[31;40mService start fail\033[0m\n\n");
        }
        
        // 更改权限
        chmod(RQUEUE_PID_FILE, 0644);
    }

    public static function run()
    {
        self::notice("Rqueued is starting ...", true);
        // Rqueue初始化
        self::init();
        // 作为daemonize启动
        self::daemonize();
        // 生成pid文件
        self::save_pid();
        // 安装信号
        self::install_signal();
        // 生成works
        self::spawn_workers();
        // 标记服务为runing状态
        self::$service_status = self::STATUS_RUNNING;
        // 关闭标准输出
        self::close_std_output();
        // 主循环
        self::loop();
        exit(0);
    }

    protected static function init()
    {

    }

    protected static function spawn_workers()
    {
        $workers = self::get_worker_file();

        // 循环生成work
        foreach ($workers as $file) {
            include(RQUEUE_WORK_DIR.DS.$file);
            $f_name = trim($file, '.php');
            $_obj = new $f_name();
            if ($_obj instanceof Rqueue) {
                self::create_work_one($_obj);
            } else {
                self::notice($file.' is not extends Rqueue', true);
            }
        }
    }

    /**
     * 创建子进程
     */
    protected static function create_work_one($obj)
    {
        $_class = get_class($obj);
        // 检测worker重启次数
        self::check_worker_restart($_class);

        $pid = pcntl_fork(); //创建子进程
        
        // 先处理收到的信号
        pcntl_signal_dispatch();

        if ($pid > 0) { //父进程
            // 将子进程的状态更新到变量中
            static::$pid_worker[$pid] = $_class;
            static::$worker_info[$_class]['pid'] = $pid;
            static::$worker_info[$_class]['restart_time'] = isset(static::$worker_info[$_class]['restart_time']) ? time() : 0;
            static::$worker_info[$_class]['restart_count'] = isset(static::$worker_info[$_class]['restart_count']) ? static::$worker_info[$_class]['restart_count']+1 : 0;

            return $pid;
        } else if ($pid == 0) {//子进程
            // 子进程忽略所有信号
            static::ignore_signal();
            $pid = posix_getpid();

            self::notice("Process ".get_class($obj)." [{$pid}] is runing");
            echo PHP_EOL." * Process ".get_class($obj)." [{$pid}] \t [\033[32;40m ok \033[0m]";
            while(true)
            {
                $rt = $obj->work();
            }
            exit(0);
        } else {
            self::notice("create worker fail", true);
        }
    }

    protected static function check_worker_restart($class_name)
    {
        if (isset(static::$worker_info[$class_name]['restart_count']) && static::$worker_info[$class_name]['restart_count'] >= self::RESTART_COUNT_MAX) {
            exit(0);
        }
    }

    protected static function loop()
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
    protected static function check_worker_exit()
    {
        while(($pid = pcntl_waitpid(-1, $status, WUNTRACED | WNOHANG)) != 0)
        {
            // 出错
            if($pid < 0)
            {
                self::notice('pcntl_waitpid return '.$pid.' and pcntl_get_last_error = ' . pcntl_get_last_error(), true);
                return $pid;
            }

            // 如果服务是不是关闭中
            if(self::$service_status != self::STATUS_SHUTDOWN)
            {
                sleep(5);
                self::notice('process '.static::$pid_worker[$pid].' is restart.', true);

                $_obj = new static::$pid_worker[$pid]();

                // 删除被kill掉的进程信息
                self::clear_work_info($pid);

                if ($_obj instanceof Rqueue) {
                    self::create_work_one($_obj);
                } else {
                    self::notice($work_info[$pid]['work_name'].' is not extends Rqueue', true);
                }
            }
            // 判断是否都重启完毕
            else
            {
                // 发送提示
                self::notice("Rqueue is stoped", true);
                // 删除pid文件
                @unlink(RQUEUE_PID_FILE);
                exit(0);
            }//end if
        }
    }

    protected static function clear_work_info($pid)
    {
        unset(static::$pid_worker[$pid]);
        return true;
    }

    /**
     * 忽略信号
     * @return void
     */
    protected static function ignore_signal()
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
    protected static function signal_handler($signal)
    {
        switch($signal)
        {
            // 停止服务信号
            case SIGINT:
                self::notice('Rqueue is shutting down', true);
                self::stop_workers();
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
                // 暂不实现
                // self::notice('Rqueue is reloading', true);
                // self::restart_workers();
                break;
        }
    }

    /**
     * 安装相关信号控制器
     * @return void
     */
    protected static function install_signal()
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

    protected static function all_restart()
    {

    }

    /**
     * 重启workers
     */
    protected static function restart_workers()
    {
        // 向所有子进程发送重启信号
        foreach(static::$pid_worker as $pid=>&$worker)
        {
            $worker['restart_time'] = time();
            $worker['restart_count'] = $worker['restart_count'] ++;
            $worker['status'] = 'restart';
            // 发送SIGINT信号
            posix_kill($pid, SIGHUP);
        }
    }

    /**
     * 强制杀死进程
     * @param int $pid
     * @return void
     */
    public static function force_kill_worker($pid)
    {
        if(posix_kill($pid, 0))
        {
            self::notice("Kill workers $pid force!");
            posix_kill($pid, SIGKILL);
        }
    }

    /**
     * 终止所有work进程
     */
    protected static function stop_workers()
    {
        // 如果没有子进程，则直接终止
        if (empty(static::$pid_worker)) exit(0);
        // 向所有子进程发送终止信号
        foreach(static::$pid_worker as $pid=>&$worker)
        {
            // 发送SIGINT信号
            posix_kill($pid, SIGINT);
        }
    }

    public static function get_worker_file()
    {
        if (!is_dir(RQUEUE_WORK_DIR)) exit(RQUEUE_WORK_DIR." dir is not found.");
        $Workers = array();
        $_Workers = scandir(RQUEUE_WORK_DIR);
        foreach ($_Workers as $file) {
            if (is_file(RQUEUE_WORK_DIR.DS.$file)) {
                $Workers[] = $file;
            }
        }
        if (empty($Workers)) exit(RQUEUE_WORK_DIR." is not found file");
        return $Workers;
    }

    /**
     * 关闭标准输入输出
     * @return void
     */
    protected static function close_std_output($force = false)
    {
        // 如果此进程配置是no_debug，则关闭输出
        /*if(!$force)
        {
            // 开发环境不关闭标准输出，用于调试
            if(posix_ttyname(STDOUT))
            {
                return;
            }
        }*/
        global $STDOUT, $STDERR;
        @fclose(STDOUT);
        @fclose(STDERR);
        // 将标准输出重定向到/dev/null
        $STDOUT = fopen('/dev/null',"rw+");
        $STDERR = fopen('/dev/null',"rw+");
    }

    /**
     * notice,记录到日志
     * @param string $msg
     * @param bool $display
     * @return void
     */
    public static function notice($msg, $display = false)
    {
        Log::add("Server:".$msg);
        if($display)
        {
            if(self::$service_status == self::STATUS_STARTING)
            {
                echo($msg."\n");
            }
        }
    }
}