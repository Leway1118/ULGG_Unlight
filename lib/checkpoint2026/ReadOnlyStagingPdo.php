<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Local synthetic SQLite readonly connection with scoped filesystem path.
 * PRAGMA query_only rejects DML/DDL in the current connection. This is NOT
 * a substitute for MariaDB SELECT-only grants in eventual production.
 */
final class ReadOnlyStagingPdo
{
    public static function open(string $dbFile,string $approvedRoot): PDO
    {
        $dir=realpath($approvedRoot);
        $path=realpath($dbFile);
        if($dir===false || $path===false || !is_dir($dir) || !is_file($path)){
            throw new InvalidArgumentException('MISSING_ISOLATED_STAGING_DB');
        }
        $prefix=rtrim($dir,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $a=str_replace('\\','/',$path);$b=str_replace('\\','/',$prefix);
        if(strncasecmp($a,$b,strlen($b))!==0 || strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='sqlite'){
            throw new InvalidArgumentException('DB_PATH_OUTSIDE_ALLOWED_STAGING');
        }
        $pdo=new PDO('sqlite:'.$path,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>3,
        ]);
        $pdo->exec('PRAGMA query_only = ON');
        if((int)$pdo->query('PRAGMA query_only')->fetchColumn()!==1){
            throw new RuntimeException('READONLY_GATE_NOT_ACTIVE');
        }
        return $pdo;
    }
}
