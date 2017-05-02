<?php
namespace dxkite\suda;

use suda\core\Application;
use suda\core\Query;
use suda\core\Storage;
use suda\tool\ArrayHelper;
use suda\archive\DTOReader;

class DBManager
{
    public static $dtohead=<<< 'Table'

    try {
    /** Open Transaction Avoid Error **/
    Query::beginTransaction();
    $effect=($create=new Query('CREATE DATABASE IF NOT EXISTS '.conf('database.name').';'))->exec();
    if ($create->erron()==0){
           dxkite\suda\DBManager::log('Create Database '.conf('database.name').' Ok,effect '.$effect.' rows');
        }
        else{
            dxkite\suda\DBManager::log('Database '.conf('database.name').'create filed!');
            _D()->error('Database '.conf('database.name').'create filed!');
        }

Table;

    public static $dtoend=<<< 'End'
    /** End Querys **/
    Query::commit();
    return true;
    } 
    catch (Exception $e)
    {
        _D()->logException($e);
        dxkite\suda\DBManager::log($e->getLine().':'.$e->getMessage());
        Query::rollBack();
        return false;
    }
End;
    public static function parseDTOs()
    {
        $modules=Application::getModules();
        foreach ($modules as $module) {
            self::parseMDTOs($module);
        }
    }
    public static function createTables()
    {
        $modules=Application::getModules();
        foreach ($modules as $module) {
            self::createTable($module);
        }
    }
    public static function execFile(string $file)
    {
        if (Storage::exist($file)) {
            return require $file;
        }
        return false;
    }

    public static function createTable(string $module)
    {
        $module_dir=Application::getModuleDir($module);
        $create=DATA_DIR.'/backup/laster/create/'.$module_dir.'.php';
        if (Storage::exist($create)) {
            self::execFile($create);
        } else {
            self::log("file no found :${create}");
        }
    }

    public static function parseMDTOs(string $module)
    {
        $module_dir=Application::getModuleDir($module);
        $dto_path=MODULES_DIR.'/'.$module_dir.'/resource/dto';
        if (!Storage::isDir($dto_path)) {
            self::log("not exist {$dto_path}\r\n");
            return;
        }
        $create=DATA_DIR.'/backup/laster/create/'.$module_dir.'.php';
        Storage::path(dirname($create));
        $tables=Storage::readDirFiles($dto_path, true, '/\.dto$/', true);
        file_put_contents($create, '<?php  /* create:'.date('Y-m-d H:i:s')."*/\r\n".self::$dtohead);
        foreach ($tables as $table) {
            $name=pathinfo($table, PATHINFO_FILENAME);
            $namespace=preg_replace('/\\\\\//', '\\', dirname($table));
            $table_name=self::tablename($namespace, $name);
            $name=ucfirst($name);
            $builder=new DTOReader;
            $builder->load($dto_path.'/'.$table);
            $builder->setName($name);
            $builder->setTableName($table_name);
            $table_names[]=$table_name;
            $sql=$builder->getCreateSQL();
            $query=self::createQuery("DROP TABLE IF EXISTS #{{$table_name}}")
            .self::createQueryMessage(self::sql($sql), 'create table '.$table_name);
            file_put_contents($create, '/* table '.$table_name.'*/'.$query."\r\n", FILE_APPEND);
        }
        $tablefile=DATA_DIR.'/backup/laster/table/'.$module_dir.'.php';
        Storage::path(dirname($tablefile));
        ArrayHelper::export($tablefile, '_tables', $table_names);
        file_put_contents($create, self::$dtoend, FILE_APPEND);
        self::log('output file: '.$create);
        return true;
    }

    public static function log(string $message)
    {
        _D()->trace($message);
        echo $message.'<br/>';
        echo str_repeat(' ', 4096);
        flush();
        ob_flush();
    }

    public static function getTableStruct(string $table)
    {
        $table_info=($q=new Query("show create table {$table};"))->fetch();
        if ($table_info) {
            return $table_info['Create Table'];
        }
        return false;
    }

    protected static function createQueryMessage(string $sql, string $message)
    {
        _D()->trace($sql, $message);
        $data=base64_encode($sql);
        $message=base64_encode($message);
        $name=md5($sql);
        $string='$rows=($_'.$name.'=new Query(base64_decode(\''.$data.'\')))->exec();';
        $string.='dxkite\suda\DBManager::log($_'.$name.'->erron()==0? base64_decode(\''.$message.'\'). "effect {$rows} rows"  :\'query\'.base64_decode(\''.$data.'\').\' error\');';
        return $string;
    }

    protected static function createQuery(string $sql)
    {
        $data=base64_encode($sql);
        return ' (new Query(base64_decode(\''.$data.'\')))->exec();';
    }

    protected static function tablename($namespace, $name)
    {
        if ($namespace==='.') {
            return $name;
        }
        if (preg_match('/'.preg_quote(DIRECTORY_SEPARATOR.$name, '/').'$/i', $namespace)) {
            $namespace=preg_replace('/'.preg_quote(DIRECTORY_SEPARATOR.$name).'$/i', '', $namespace);
        }
        return ($name===$namespace?$name:preg_replace_callback('/(\\\\|[A-Z])/', function ($match) {
            if ($match[0]==='\\') {
                return '_';
            } else {
                return '_'.strtolower($match[0]);
            }
        }, $namespace.'\\'.$name));
    }

    protected static function sql(string $sql)
    {
        return preg_replace('/CREATE TABLE `(.+?)` /', 'CREATE TABLE `#{$1}` ', $sql);
    }
}