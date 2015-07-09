<?php
namespace Core\Bootstrap;

use App;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Header;
use Core\Http\Cookies;
use Core\Router\Router;
use Core\Logger\Logger;
use Core\Db;
use Core\Session\Session;
use Core\Session\Handler\Memcached;
use Core\View\ViewFactory;

/**
 * 默认引导程序
 *
 * 执行应用的初始化工作，注册核心对象的初始化方法，使用者可自由定制引导程序，注册新的全局对象或者改变框架的默认行为。
 *
 * @package Core\Bootstrap
 */
class Bootstrap implements BootstrapInterface
{

	public function startup()
	{
        //注册错误处理函数
        set_error_handler(function ($code, $str, $file, $line) {
            throw new \ErrorException($str, $code, 0, $file, $line);
        });

        //设置时区
        if (App::conf('app', 'timezone')) {
            date_default_timezone_set(App::conf('app', 'timezone'));
        } elseif (ini_get('date.timezone') == '') {
            date_default_timezone_set('Asia/Shanghai'); //设置默认时区为中国时区
        }

		//注册shutdown函数
		register_shutdown_function(function() {
			$error = error_get_last();
			if ($error) {
				$errTypes = array(E_ERROR => 'E_ERROR', E_PARSE => 'E_PARSE', E_USER_ERROR => 'E_USER_ERROR');
				if (isset($errTypes[$error['type']])) {
					$info = $errTypes[$error['type']].": {$error['message']} in {$error['file']} on line {$error['line']}";
					App::logger()->error($info);
				}
			}
		});

        //注册异常处理器
        if (class_exists('\\App\\Exception\\Handler')) {
            \App\Exception\Handler::factory(App::logger(), DEBUG)->register();
        } else {
            \Core\Exception\Handler::factory(App::logger(), DEBUG)->register();
        }

	}

    //注册DB初始化方法
	public function initDb($name)
	{
        $config = App::conf('app', 'database');
        if (!isset($config[$name])) {
            throw new \InvalidArgumentException("数据配置不存在: {$name}");
        }
        $db = new Db($config[$name]);
        $db->setLogger(App::logger());
        return $db;
	}

	public function initSession()
	{
		$config = App::conf('app', 'session', array());
		$session = new Session();
        if (isset($config['type'])) {
            switch ($config['type']) {
                case 'file':
                    if (!empty($config['file']['save_path'])) {
                        $session->setSavePath($config['file']['save_path']);
                    }
                    break;
                case 'memcached':
                    $session->setHandler(new Memcached($config['memcached']['servers']));
                    break;
            }
        }
		$session->start();
		return $session;
	}

	public function initCache($name)
	{
        $config = App::conf('app','cache');
        if ($name == 'default') {
            $name = $config['default'];
        }
        return \Core\Cache\Cache::factory($name, $config[$name]);
	}

    //注册路由
	public function initRouter()
	{
        $options = App::conf('app', 'router');
        $router = Router::factory($options);
        $router->setConfig(App::conf('route'));
        $router->setRequest(App::getRequest());
        return $router;
	}

    //注册输出对象
	public function initResponse()
	{
        return new Response();
	}

    //注册请求对象
	public function initRequest()
	{
		return new Request(Header::createFrom($_SERVER), new Cookies($_COOKIE));
	}

    //注册日志记录器
	public function initLogger($name)
	{
        $config = App::conf('app', 'logger', array());
        $logger = new Logger($name);
        $logger->setTimeZone(new \DateTimeZone('PRC'));
        if (isset($config[$name])) {
            foreach ($config[$name] as $conf) {
                $class = '\\Core\\Logger\\Handler\\' . $conf['handler'];
                $logger->setHandler(new $class($conf['config']), $conf['level']);
            }
        }
        return $logger;
	}

    //初始化视图模板对象
    public function initView()
    {
        $viewConf = App::conf('app', 'view');
        return ViewFactory::create($viewConf['engine'], $viewConf['options']);
    }
}
