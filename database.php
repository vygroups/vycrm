<?php
class Database
{

    private static $master_host = 'localhost';
    private static $master_user = 'u495954467_vycrm';
    private static $master_pass = 'Tn02aps2391*';
    private static $master_db = 'u495954467_vycrm';
    private static $m_prefix = 'master_';

    // MASTER DB
    public static function getMasterConn()
    {
        try {
            $conn = new PDO(
                "mysql:host=" . self::$master_host . ";dbname=" . self::$master_db,
                self::$master_user,
                self::$master_pass
                );
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $conn;
        }
        catch (PDOException $e) {
            die("Master DB Error: " . $e->getMessage());
        }
    }

    // TENANT DB (IMPORTANT FIX)
    public static function getTenantConn($dbName)
    {

        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);

        // 🔥 RULE: user = db name
        $dbUser = $dbName;

        // 🔥 RULE: same password
        $dbPass = self::$master_pass;

        try {
            $conn = new PDO(
                "mysql:host=" . self::$master_host . ";dbname=" . $dbName,
                $dbUser,
                $dbPass
                );
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $conn;

        }
        catch (PDOException $e) {
            return null;
        }
    }

    public static function getMasterPrefix()
    {
        return self::$m_prefix;
    }
}
?>