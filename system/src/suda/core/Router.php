<?php
/**
 * Suda FrameWork
 *
 * An open source application development framework for PHP 7.0.0 or newer
 * 
 * Copyright (c)  2017 DXkite
 *
 * @category   PHP FrameWork
 * @package    Suda
 * @copyright  Copyright (c) DXkite
 * @license    MIT
 * @link       https://github.com/DXkite/suda
 * @version    since 1.2.4
 */
namespace suda\core;

use suda\tool\Command;
use suda\tool\Json;
use suda\tool\ArrayHelper;

// TODO:路由强化
// TODO:路由模块化（添加命名空间）

class Router
{
    protected $mapper;
    protected $matchs=[];
    protected $types=[];
    protected static $urltype=['int'=>'\d+','string'=>'[^\/]+','url'=>'.+'];
    protected static $router=null;
    protected $routers=[];

    private function __construct()
    {
        Hook::listen('system:404', 'Router::error404');
        Hook::listen('Router:dispatch::error', 'Router::error404');
        self::loadModulesRouter();
    }

    public static function getInstance()
    {
        if (is_null(self::$router)) {
            self::$router=new Router;
        }
        return self::$router;
    }
    
    public static function getModulePrefix(string $module)
    {
        $prefix= Application::getModulePrefix($module)??'';
        $admin_prefix='';
        if (is_array($prefix)) {
            if (in_array(key($prefix), ['admin','simple'], true)) {
                $admin_prefix=$prefix['admin'] ?? '';
                $prefix=$prefix['simple'] ?? '';
            } else {
                $admin_prefix=count($prefix)?array_shift($prefix):'';
                $prefix=count($prefix)?array_shift($prefix):'';
            }
        }
        return [$admin_prefix,$prefix];
    }

    public function load(string $module)
    {
        $simple_routers=[];
        $admin_routers=[];
        $module_path=Application::getModulePath($module);
        debug()->trace(__('load module:%s [%s] path:%s', $module, Application::getModuleFullName($module),$module_path));
        list($admin_prefix, $prefix)=self::getModulePrefix($module);
        $module=Application::getModuleFullName($module);
        $prefix_it= function (&$router, $key, $prefixinfo) use ($module) {
            $prefix=$prefixinfo[0]?conf('app.admin', '/admin'):'/';
            if (!(isset($router['anti-prefix']) && $router['anti-prefix'])) {
                $prefix.=$prefixinfo[1];
            }
            $router['visit']='/'.trim($prefix.$router['visit'], '/');
            $router['role']=$prefixinfo[0]?'admin':'simple';
            $router['module']=$module;
        };
        // 加载前台路由
        if (Storage::exist($file=$module_path.'/resource/config/router.json')) {
            $simple_routers= self::loadModuleJson($module, $file);
            debug()->trace(__('loading simple route from file %s', $file));
            array_walk($simple_routers, $prefix_it, [false,$prefix]);
        }
        // 加载后台路由
        if (Storage::exist($file=$module_path.'/resource/config/router_admin.json')) {
            $admin_routers= self::loadModuleJson($module, $file);
            debug()->trace(__('loading admin route from file  %s', $file));
            array_walk($admin_routers, $prefix_it, [true,$admin_prefix]);
        }
       
        $this->routers=array_merge($this->routers, $admin_routers, $simple_routers);
    }

    protected function loadModuleJson(string $module, string $jsonfile)
    {
        $routers=Json::loadFile($jsonfile);
        $router=[];
        foreach ($routers as $name => $value) {
            $router[$module.':'.$name]=$value;
        }
        return $router;
    }

    protected function loadFile()
    {
        $this->routers=require self::cacheFile('router.cache.php');
        $this->types=require self::cacheFile('types.cache.php');
        $this->matchs=require self::cacheFile('matchs.cache.php');
    }

    protected function saveFile()
    {
        ArrayHelper::export(self::cacheFile('router.cache.php'), '_router', $this->routers);
        ArrayHelper::export(self::cacheFile('types.cache.php'), '_types', $this->types);
        ArrayHelper::export(self::cacheFile('matchs.cache.php'), '_matchs', $this->matchs);
    }

    protected function loadModulesRouter()
    {
        // 如果DEBUG模式
        if (conf('debug', false)) {
            self::prepareRouterInfo();
        } else {
            if (!self::routerCached()) {
                self::prepareRouterInfo();
            }
            self::loadFile();
        }
    }

    
    public function routerCached()
    {
        if (!file_exists(self::cacheFile('router.cache.php'))) {
            return false;
        }
        if (!file_exists(self::cacheFile('types.cache.php'))) {
            return false;
        }
        if (!file_exists(self::cacheFile('matchs.cache.php'))) {
            return false;
        }
    }

    public function prepareRouterInfo()
    {
        $modules=Application::getLiveModules();
        foreach ($modules as $module) {
            self::load($module);
        }
        self::buildRouterMap();
        Hook::exec('Router:prepareRouterInfo', [$this]);
        // 缓存路由信息
        self::saveFile();
    }

    public function watch(string $name, string $url)
    {
        $this->matchs[$name]=self::buildMatch($name, $url);
    }

    protected function matchRouterMap()
    {
        $request=Request::getInstance();
        foreach ($this->matchs as $name=>$preg) {
            // debug()->d('url:'.$request->url().'; preg:'.'/^'.$preg.'$/');
            if (preg_match('/^'.$preg.'$/', $request->url(), $match)) {
                // 检验方法
                if (isset($this->routers[$name]['method']) && count($this->routers[$name]['method'])>0) {
                    // 调整方法大小
                    array_walk($this->routers[$name]['method'], function ($value) {
                        return strtoupper($value);
                    });
                    // 方法不匹配
                    if (!in_array(strtoupper($request->method()), $this->routers[$name]['method'])) {
                        continue;
                    }
                }
                // URL禁用
                if ($this->routers[$name]['hidden']??false) {
                    continue;
                }
                // 检验接口参数
                array_shift($match);
                if (count($match)>0) {
                    foreach ($this->types[$name] as $param_name =>$type) {
                        $value=array_shift($match);
                        if ($type==='int') {
                            $value=intval($value);
                        } else {
                            $value=urldecode($value);
                        }
                        // 填充$_GET
                        $_GET[$param_name]=$value;
                        $request->set($param_name, $value);
                    }
                }
                // 自定义过滤
                if (!Hook::execIf('Router:filter', [$name,$this->routers[$name]], false)) {
                    continue;
                }
                return $name;
            }
        }
        return false;
    }

    protected function buildRouterMap()
    {
        foreach ($this->routers as $name => $router) {
            self::watch($name, $router['visit']);
        }
    }


    protected function buildMatch(string $name, string $url)
    {
        $types=&$this->types;
        $urltype=self::$urltype;
        // 转义字符
        $url=preg_replace('/([\/\.\\\\\+\*\(\^\)\?\$\!\<\>\-])/', '\\\\$1', $url);
        // 添加忽略
        $url=preg_replace('/\[(\S+)\]/', '(?:$1)?', $url);
        // 编译页面参数
        $url=preg_replace_callback('/\{(?:(\w+)(?::(\w+))?)(?:=(\w+))?\}([?])?/', function ($match) use ($name, &$types, $urltype) {
            // debug()->debug($match);
            $size=isset($types[$name])?count($types[$name]):0;
            $param_name=$match[1]!==''?$match[1]:$size;
            $param_type=  $match[2] ?? 'string';
            $ignore=isset($match[4])?'?':'';
            $types[$name][$param_name]=$param_type;
            if (isset($urltype[$param_type])) {
                return '('.$urltype[$param_type].')'.$ignore;
            } else {
                return '(.+)'.$ignore;
            }
        }, $url);
        return $url;
    }
    
    /**
    * 解析模板名
    */
    public static function parseName(string $name, string $module_default=null)
    {
        // MODULE_NAME_PREG
        // [模块前缀名称/]模块名[:版本号]:(模板名|路由ID)
       preg_match('/^((?:[a-zA-Z0-9_-]+\/)?[a-zA-Z0-9_-]+)(?::([^:]+))?(?::(.+))?$/', $name, $match);
        if (count($match)===0) {
            $module=$module_default??Application::getActiveModule();
            $info=$name;
        } elseif (isset($match[1]) && count($match)==2) {
            // 单纯路由或者模板
                $module=$module_default??Application::getActiveModule();
            $info=$match[0];
        } else {
            $info=isset($match[3])?$match[3]:$match[2];
            $module=isset($match[3])?
                            (isset($match[1])?
                                $match[1].(
                                    $match[2]?
                                    ':'.$match[2]
                                    :'')
                                :($module_default??Application::getActiveModule()) // 未指定模板名
                            )
                        :$match[1];
        }
        return [$module,$info];
    }

    public function getRouterFullName(string $name)
    {
        list($module, $name)=self::parseName($name);
        $module=Application::getModuleFullName($module);
        return $module.':'.$name;
    }
    
    public function buildUrlArgs(string $name, array $args)
    {
        list($module, $name)=self::parseName($name);
        $module=Application::getModuleFullName($module);
        $name=$module.':'.$name;
        if (isset($this->types[$name])) {
            $keys=array_keys($this->types[$name]);
            $values=[];
            foreach ($keys as $key) {
                if (count($args)) {
                    $values[$key]=array_shift($args);
                } else {
                    break;
                }
            }
            return $values;
        }
        return [];
    }

    public function buildUrl(string $name, array $values=[])
    {
        list($module, $name)=self::parseName($name);
        $module=Application::getModuleFullName($module);
        $name=$module.':'.$name;
        // debug()->debug($name);
        $url= '';
        if (isset($this->routers[$name])) {
            // 路由存在
            $url.=preg_replace('/[?|]/', '\\\1', $this->routers[$name]['visit']);
            $url=preg_replace_callback('/\{(?:(\w+)(?::(\w+))?)(?:=(\w+))?\}/', function ($match) use ($name, & $values) {
                $param_name=$match[1];
                $param_type= $match[2] ?? 'url';
                $param_default=$match[3]??'';
                if (isset($values[$param_name])) {
                    if ($param_type==='int') {
                        $val= intval($values[$param_name]);
                    }
                    $val=$values[$param_name];
                    unset($values[$param_name]);
                    return $val;
                } else {
                    return $param_default;
                }
            }, preg_replace('/\[(.+?)\]/', '$1', $url));
        } else {
            debug()->warning(__('get url for %s failed,module:%s args:%s', $name, $module, json_encode($values)));
            return '#the-router-['.$name.']-is-undefined--please-check-out-router-list';
        }
        if (count($values)) {
            return Request::getInstance()->baseUrl(). trim($url, '/').'?'.http_build_query($values, 'v', '&', PHP_QUERY_RFC3986);
        }
        return Request::getInstance()->baseUrl(). trim($url, '/');
    }


    public function dispatch()
    {
        debug()->time('dispatch');
        self::buildRouterMap();
        // Hook前置路由（自定义过滤器|自定义路由）
        if (Hook::execIf('Router:dispatch::before', [Request::getInstance()], true)) {
            if (($router_name=self::matchRouterMap())!==false) {
                // debug()->debug('dispatch match '.$router_name);
                debug()->timeEnd('dispatch');
                Response::setName($router_name);
                debug()->time('run router');
                self::runRouter($this->routers[$router_name]);
                debug()->timeEnd('run router');
            } else {
                Hook::exec('system:404');
            }
        } else {
            Hook::execTail('Router:dispatch::error');
        }
    }

    /**
     * 获取路由
     *
     * @param string $name
     * @return void
     */
    public function getRouter(string $name)
    {
        $name=self::getRouterFullName($name);
        if (isset($this->routers[$name])) {
            $router= $this->routers[$name];
            $router['match']=$this->matchs[$name];
            return $router;
        }
    }
    
    /**
     * 设置路由别名
     *
     * @param string $name
     * @param string $alias
     * @return void
     */
    public function setRouterAlias(string $name, string $alias)
    {
        $name=self::getRouterFullName($name);
        $alias=self::getRouterFullName($alias);
        if (isset($this->routers[$name])) {
            $this->routers[$alias]=$this->routers[$alias]??$this->routers[$name];
            $this->matchs[$alias]=$this->matchs[$alias]??$this->matchs[$name];
        }
    }
    
    /**
     * 路由替换
     *
     * @param string $name
     * @param string $alias
     * @return void
     */
    public function routerReplace(string $name, string $alias)
    {
        $name=self::getRouterFullName($name);
        $alias=self::getRouterFullName($alias);
        if (isset($this->routers[$name])) {
            if (isset($this->routers[$alias])) {
                $this->routers[$name]['class']=$this->routers[$alias]['class'];
                $this->routers[$name]['method']=$this->routers[$alias]['method']??[];
                $this->routers[$name]['module']=$this->routers[$alias]['module'];
            }
        }
    }

    /**
     * 路由移动
     *
     * @param string $name
     * @param string $alias
     * @return void
     */
    public function routerMove(string $name, string $alias)
    {
        $name=self::getRouterFullName($name);
        $alias=self::getRouterFullName($alias);
        if (isset($this->routers[$name])) {
            if (isset($this->routers[$alias])) {
                $this->routers[$name]['class']=$this->routers[$alias]['class'];
                $this->routers[$name]['method']=$this->routers[$alias]['method']??[];
                $this->routers[$name]['module']=$this->routers[$alias]['module'];
                unset($this->router[$alias]);
            }
        }
    }
    

    /**
     * 动态添加运行命令
     *
     * @param string $name
     * @param string $url
     * @param string $class
     * @param string $module
     * @param array $method
     * @return void
     */
    public function addRouter(string $name, string $url, string $class, string $module, array $method=[])
    {
        $module=Application::getModuleFullName($module);
        $name=$module.':'.$name;
        $this->routers[$name]['class']=$class;
        $this->routers[$name]['method']=$method;
        $this->routers[$name]['module']=$module;
        $this->routers[$name]['visit']=$url;
        self::watch($name, $url);
        return $name;
    }

    /**
     * 替换匹配表达式
     *
     * @param string $name
     * @param string $url
     * @param bool $preg
     * @return void
     */
    public function replaceMatch(string $name, string $url, bool $preg=false)
    {
        $name=self::getRouterFullName($name);
        if (isset($this->matchs[$name])) {
            if ($preg) {
                return $this->matchs[$name]=$url;
            }
            return $this->matchs[$name]=self::buildMatch($name, $url);
        }
    }

    /**
     * 替换路由指定类
     *
     * @param string $name
     * @param string $class
     * @param array $method
     * @return void
     */
    public function replaceClass(string $name, string $class, string $module=null, array $method=null)
    {
        $name=self::getRouterFullName($name);
        if (isset($this->routers[$name])) {
            $router= $this->routers[$name];
            $router['class']= $class;
            if ($method) {
                $router['method']=$method;
            }
            if ($module) {
                $router['module']=Application::getModuleFullName($module);
            }
            return $this->routers[$name]=$router;
        }
    }

    protected static function runRouter(array $router)
    {
        // 全局钩子:重置Hook指向
        Hook::exec('Router:runRouter::before', [&$router]);
        // debug()->time('active Module');
        // 激活模块
        (new Command(System::getAppClassName().'::activeModule'))->exec([$router['module']]);
        // debug()->timeEnd('active Module');
        debug()->time('request');
        // 运行请求
        (new Command($router['class'].'->onRequest'))->exec([Request::getInstance()]);
        debug()->timeEnd('request');
        // 请求结束
        Hook::exec('Router:runRouter::after', [&$router]);
    }

    public static function error404()
    {
        $render=new class extends Response {
            public   function onRequest(Request $request)
            {
                $this->state(404);
                $this->page('suda:error404', ['title'=>'404 Error', 'path'=>$request->url()])->render();
            }
        };
        $render->onRequest(Request::getInstance());
    }

    public function getRouters(){
        return $this->routers;
    }

    private function cacheFile(string $name):string
    {
        $module_use=Application::getLiveModules();
        sort($module_use);
        $hash=substr(md5(implode('-', $module_use)), 0, 8);
        $path=CACHE_DIR.'/router/'.$hash;
        Storage::path($path);
        return $path.'/'.$name;
    }
}
