<?php
    define('DB_HOST', 'sql101.infinityfree.com');
    define('DB_NAME', 'if0_42533255_bhbhbh');
    define('DB_USER', 'if0_42533255');
    define('DB_PASS', 'vamaC9JgzFRljk');

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME .
  ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        die('Database connection failed: ' .
  htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
