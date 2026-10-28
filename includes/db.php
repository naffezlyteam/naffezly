<?php
if (!defined('APP_NAME')) {
    require_once __DIR__ . '/config.php';
}

function getConnection(): mysqli
{
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $connection->set_charset('utf8mb4');
        return $connection;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Unknown database') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
            $temp = new mysqli(DB_HOST, DB_USER, DB_PASS);
            $temp->query('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $temp->close();
            $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $connection->set_charset('utf8mb4');
            return $connection;
        }

        throw new RuntimeException('Unable to connect to database: ' . $e->getMessage());
    }
}

function db(): mysqli
{
    return getConnection();
}

function initializeDatabase(): void
{
    $connection = db();
    $migrationTable = $connection->query(
        "SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'"
    )->fetch_assoc();
    if ((int) $migrationTable['total'] > 0) {
        $version = $connection->query('SELECT MAX(version) AS current_version FROM schema_migrations')->fetch_assoc();
        if ((int) ($version['current_version'] ?? 0) >= 1) {
            if ((int) ($version['current_version'] ?? 0) < 8) {
                require_once __DIR__ . '/migrations.php';
                migrateApplicationSchema($connection);
            }
            return;
        }
    }

    $schemaPath = dirname(__DIR__) . '/database/schema.sql';
    if (!file_exists($schemaPath)) {
        return;
    }

    $sql = file_get_contents($schemaPath);
    if ($sql === false || trim($sql) === '') {
        return;
    }

    $connection->multi_query($sql);
    do {
        if ($result = $connection->store_result()) {
            $result->free();
        }
    } while ($connection->more_results() && $connection->next_result());

    $seedPath = dirname(__DIR__) . '/database/seed.sql';
    if (file_exists($seedPath)) {
        $countResult = $connection->query('SELECT COUNT(*) AS total FROM projects');
        $hasProjects = $countResult && $countResult->fetch_assoc()['total'] > 0;
        if (!$hasProjects) {
            $seedSql = file_get_contents($seedPath);
            if ($seedSql !== false && trim($seedSql) !== '') {
                $seedConn = db();
                $seedConn->multi_query($seedSql);
                do {
                    if ($result = $seedConn->store_result()) {
                        $result->free();
                    }
                } while ($seedConn->more_results() && $seedConn->next_result());
            }

        }
    }

    require_once __DIR__ . '/migrations.php';
    migrateApplicationSchema(db());
}

initializeDatabase();
