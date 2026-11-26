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

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'n715_image_db';
    $user = getenv('DB_USER') ?: 'n715_app';
    $pass = getenv('DB_PASS') ?: 'N715_ImgDB_2026!';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
