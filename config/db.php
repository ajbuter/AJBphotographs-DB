<?php
/**
 * CS 2300 Database Project (Phase 3) — Group N715
 * Web Accessible Image Database System (MariaDB / MySQL Compatible)
 * Authors: Aiden Buter, Claude Brown, Wesley Oxman
 * File: config/db.php
 *
 * MariaDB / MySQL PDO Database Connection Helper
 * 
 * Reads connection credentials from environment variables if set, falling back
 * to the local defaults created by sql/init_database.sql.
 *
 * When deploying, set DB_HOST, DB_NAME, DB_USER, and DB_PASS (or edit the fallback defaults below).
 */

declare(strict_types=1);

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: 'db5022005240.hosting-data.io';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'dbs16389798';
    $user = getenv('DB_USER') ?: 'dbu419306';
    $pass = getenv('DB_PASS') ?: 'DenaliButterAdmin';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
