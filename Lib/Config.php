<?php
class Config
{
    /**
     * 配置文件名称
     * @var string
     */
    public static $config_file;
    
    /**
     * 配置数据
     * @var array
     */
    public static $config = array();
    
    /**
     * 实例
     * @var instance of Config
     */
    protected static $instance = null;

    /**
     * 构造函数
     * @throws \Exception
     */
    private function __construct()
     {
        $file = RQUEUE_ROOT_DIR . '/Conf/config.conf';
        if (!file_exists($file)) 
        {
            throw new \Exception('Configuration file "' . $file . '" not found');
        }
        self::$config['Rqueue'] = self::parse_file($file);
        self::$config_file = realpath($file);
    }
    
    /**
     * 解析配置文件
     * @param string $config_file
     * @throws \Exception
     */
    protected static function parse_file($config_file)
    {
        $config = parse_ini_file($config_file, true);
        if (!is_array($config) || empty($config))
        {
            throw new \Exception('Invalid configuration format');
        }
        return $config;
    }

   /**
    * 获取实例
    * @return \Man\Core\Lib\instance
    */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取配置
     * @param string $uri
     * @return mixed
     */
    public static function get($uri)
    {
        $node = self::$config;
        $paths = explode('.', $uri);
        while (!empty($paths)) {
            $path = array_shift($paths);
            if (!isset($node[$path])) {
                return null;
            }
            $node = $node[$path];
        }
        return $node;
    }
    
    /**
     * 重新载入配置
     * @return void
     */
    public static function reload()
    {
        self::$instance = null;
        self::instance();
    }
    
}
