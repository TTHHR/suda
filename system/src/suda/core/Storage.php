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

class Storage
{
    public static $charset=['GBK','GB2312','BIG5'];
    // 递归创建文件夹
    public static function mkdirs(string $dir, int $mode=0777):bool
    {
        $dir=self::tpath($dir);
        if (!self::isDir($dir)) {
            if (!self::mkdirs(dirname($dir), $mode)) {
                return false;
            }
            // debug()->warning(__('mkdir %s->%o', $dir,$mode));
            if (!self::mkdir($dir, $mode)) {
                return false;
            }
        }
        return true;
    }
    
    public static function path(string $path)
    {
        $path=self::tpath($path);
        self::mkdirs($path);
        return realpath($path);
    }
    
    public static function abspath(string $path)
    {
        if (empty($path)) {
            return false;
        }
        $path=self::tpath($path);
        return realpath($path);
    }

    public static function readDirFiles(string $dirs, bool $repeat=false, string $preg='/^.+$/', bool $cut=false):array
    {
        $dirs=self::abspath($dirs);
        $file_totu=[];
        if ($dirs && self::isDir($dirs)) {
            $hd=opendir($dirs);
            while ($file=readdir($hd)) {
                if (strcmp($file, '.') !== 0 && strcmp($file, '..') !==0) {
                    $path=$dirs.'/'.$file;
                    if (self::exist($path) && preg_match($preg, $file)) {
                        $file_totu[]=$path;
                    } elseif ($repeat && self::isDir($path)) {
                        foreach (self::readDirFiles($path, $repeat, $preg) as $files) {
                            $file_totu[]=$files;
                        }
                    }
                }
            }
            closedir($hd);
        }
        if ($cut) {
            $cutfile=[];
            foreach ($file_totu as $file) {
                $cutfile[]=self::cut($file, $dirs);
            }
            return $cutfile;
        }
        return $file_totu;
    }

    public static function cut(string $path, string $basepath=ROOT_PATH)
    {
        return trim(preg_replace('/[\\\\\\/]+/', DIRECTORY_SEPARATOR, preg_replace('/^'.preg_quote($basepath, '/').'/', '', $path)), '\\/');
    }

    public static function readDirs(string $dirs, bool $repeat=false, string $preg='/^.+$/'):array
    {
        $dirs=self::tpath($dirs);
        $reads=[];
        if (self::isDir($dirs)) {
            $hd=opendir($dirs);
            while ($read=readdir($hd)) {
                if (strcmp($read, '.') !== 0 && strcmp($read, '..') !==0) {
                    $path=$dirs.'/'.$read;
                    if (self::isDir($path) && preg_match($preg, $read)) {
                        $reads[]=$read;
                        if ($repeat) {
                            foreach (self::readDirs($path, $repeat, $preg) as $read) {
                                $reads[]=$read;
                            }
                        }
                    }
                }
            }
            closedir($hd);
        }
        return $reads;
    }

    public static function delete(string $path)
    {
        if (empty($path)) {
            return false;
        }
        if (self::isFile($path)) {
            return self::remove($path);
        } elseif (self::isDir($path)) {
            return self::rmdirs($path);
        }
    }

    // 递归删除文件夹
    public static function rmdirs(string $dir)
    {
        $dir=self::abspath($dir);
        if ($dir  && $handle=opendir($dir)) {
            while (false!== ($item=readdir($handle))) {
                if ($item!= '.' && $item != '..') {
                    if (self::isDir($next= $dir.'/'.$item)) {
                        self::rmdirs($next);
                    } elseif (file_exists($file=$dir.'/'.$item)) { // Non-Thread-Safe
                        $errorhandler=function ($erron, $error, $file, $line) {
                            Debug::warning($error);
                        };
                        set_error_handler($errorhandler);
                        unlink($file);
                        restore_error_handler();
                    }
                }
            }
            if (self::emptyDir($dir)) {
                rmdir($dir);
            }
            closedir($handle);
        }
        return true;
    }

    public static function emptyDir(string $dirOpen)
    {
        if ($dirOpen && self::abspath($dirOpen)) {
            $handle=opendir($dirOpen);
            while (false!== ($item=readdir($handle))) {
                if ($item!= '.' && $item != '..') {
                    return false;
                }
            }
            closedir($handle);
        }
        return true;
    }

    public static function copydir(string $src, string $dest, string $preg='/^.+$/')
    {
        $src=self::tpath($src);
        $dest=self::tpath($dest);
        // debug()->trace(__('copy %s->%s', $src, $dest));
        self::mkdirs($dest);
        $hd=opendir($src);
        while ($read=readdir($hd)) {
            if (strcmp($read, '.') !== 0 && strcmp($read, '..') !==0 && preg_match($preg, $read)) {
                if (self::isDir($src.'/'.$read)) {
                    self::copydir($src.'/'.$read, $dest.'/'.$read);
                } else {
                    self::copy($src.'/'.$read, $dest.'/'.$read);
                }
            }
        }
        closedir($hd);
        return true;
    }
    
    public static function movedir(string $src, string $dest, string $preg='/^.+$/')
    {
        $src=self::tpath($src);
        $dest=self::tpath($dest);
        self::mkdirs($dest);
        $hd=opendir($src);
        while ($read=readdir($hd)) {
            if (strcmp($read, '.') !== 0 && strcmp($read, '..') !==0 && preg_match($preg, $read)) {
                if (self::isDir($src.'/'.$read)) {
                    self::movedir($src.'/'.$read, $dest.'/'.$read);
                } else {
                    self::move($src.'/'.$read, $dest.'/'.$read);
                }
            }
        }
        closedir($hd);
        return true;
    }
    
    public static function copy(string $source, string $dest):bool
    {
        $source=self::tpath($source);
        $dest=self::tpath($dest);
        if (self::exist($source)) {
            return copy($source, $dest);
        }
        return false;
    }

    public static function move(string $src, string $dest):bool
    {
        $src=self::tpath($src);
        $dest=self::tpath($dest);
        if (self::exist($src)) {
            return rename($src, $dest);
        }
        return false;
    }

    // 创建文件夹
    public static function mkdir(string $path, int $mode=0777):bool
    {
        $path=self::tpath($path);
        return !self::isDir($path) && mkdir($path, $mode);
    }

    // 删除文件夹
    public static function rmdir(string $path):bool
    {
        $path=self::tpath($path);
        return rmdir($path);
    }

    public static function put(string $name, $content, int $flags = 0):bool
    {
        $name=self::tpath($name);
        if (self::isDir(dirname($name))) {
            debug()->trace(__('put file %s', $name));
            return file_put_contents($name, $content, $flags);
        }
        return false;
    }

    public static function get(string $name):string
    {
        $name=self::tpath($name);
        if ($file=self::exist($name)) {
            if (is_string($file)) {
                $name=$file;
            }
            return file_get_contents($name);
        }
        return '';
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function remove(string $name) : bool
    {
        $name=self::tpath($name);
        if ($file=self::exist($name)) {
            if (is_string($file)) {
                $name=$file;
            }
            return unlink($name);
        }
        return true;
    }
    
    public static function isFile(string $name):bool
    {
        $name=self::tpath($name);
        return is_file($name);
    }

    public static function isDir(string $name):bool
    {
        $name=self::tpath($name);
        return is_dir($name);
    }

    public static function isReadable(string $name):bool
    {
        $name=self::tpath($name);
        return is_readable($name);
    }

    public static function isWritable(string $name):bool
    {
        $name=self::tpath($name);
        return is_writable($name);
    }
    
    public static function size(string $name):int
    {
        $name=self::tpath($name);
        if ($file=self::exist($name)) {
            if (is_string($file)) {
                $name=$file;
            }
            return filesize($name);
        }
        return 0;
    }

    public static function download(string $url, string $save):int
    {
        $save=self::tpath($save);
        return self::put($save, self::curl($url));
    }
    
    public static function curl(string $url, int $timeout=3)
    {
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        $file=curl_exec($ch);
        curl_close($ch);
        return $file;
    }

    public static function type(string $name):int
    {
        $name=self::tpath($name);
        if ($file=self::exist($name)) {
            if (is_string($file)) {
                $name=$file;
            }
            return filetype($name);
        }
        return 0;
    }

    public static function exist(string $name, array $charset=[])
    {
        $name=self::tpath($name);
        // UTF-8 格式文件路径
        if (self::exist_case($name)) {
            return true;
        }
        // Windows 文件中文编码
        $charset=array_merge(self::$charset, $charset);
        foreach ($charset as $code) {
            $file = iconv('UTF-8', $code, $name);
            if (self::exist_case($file)) {
                return $file;
            }
        }
        return false;
    }

    // 判断文件存在
    private static function exist_case($name):bool
    {
        $name=self::tpath($name);
        if (file_exists($name) && is_file($name) && $real=realpath($name)) {
            if (basename($real) === basename($name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 修正路径分割符
     *
     * @param string $path
     * @return void
     */
    private static function tpath(string $path)
    {
        return preg_replace('/[\\\\\/]+/', DIRECTORY_SEPARATOR, $path);
    }
}
