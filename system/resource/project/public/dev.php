<?php
    // 应用所在目录
    define('APP_DIR', __DIR__.'/../app');
    // 日志所在目录
    define('APP_LOG', APP_DIR.'/data/logs');
    // 系统所在目录
    define('SYSTEM',__DIR__.'/../suda/system');
    // 网站根目录位置
    define('APP_PUBLIC',__DIR__);
    // 开发者模式
    define('DEBUG',true);
    // 日志纪录等级
    define('LOG_LEVEL', 'info');
    // 输出日志详细信息到json文档
    define('LOG_JSON',true);
    // 输出详细信息添加到日志末尾
    define('LOG_FILE_APPEND',true);
    require_once SYSTEM.'/suda.php';