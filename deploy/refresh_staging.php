<?php
/**
 * Copy the live IOU database into the staging database.
 *
 * Runs every night as a Plesk scheduled task, and can be run by hand
 * ("Run Now") to test with today's content. Live is only read; everything
 * in the staging database is replaced.
 *
 * Reads the live login from ~/iou/connection.php and the staging
 * login from ~/iou-staging/staging_db.php. Refuses to run when
 * both point at the same database.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$home = dirname(__DIR__, 2);

// Live: read the four settings from connection.php without running it.
$src = (string) @file_get_contents($home . '/iou/connection.php');
$live = [];
foreach (['servername', 'username', 'password', 'dbname'] as $key) {
    if (!preg_match('/\$' . $key . '\s*=\s*([\'"])(.*?)\1\s*;/', $src, $m)) {
        fwrite(STDERR, "Could not read \$$key from the live connection.php; nothing done.\n");
        exit(1);
    }
    $live[$key] = $m[2];
}

// Staging: staging_db.php sets $username, $password and $dbname.
$stagingFile = $home . '/iou-staging/staging_db.php';
if (!is_file($stagingFile)) {
    fwrite(STDERR, "staging_db.php not found; nothing done.\n");
    exit(1);
}
$staging = (function (string $file, array $defaults): array {
    $servername = $defaults['servername'];
    $username = $password = $dbname = null;
    require $file;
    return compact('servername', 'username', 'password', 'dbname');
})($stagingFile, $live);

if (!$staging['dbname'] || $staging['dbname'] === $live['dbname'] || $staging['username'] === $live['username']) {
    fwrite(STDERR, "Staging must use its own database and user; nothing done.\n");
    exit(1);
}

$connect = fn (array $c): PDO => new PDO(
    "mysql:host={$c['servername']};dbname={$c['dbname']};charset=utf8mb4",
    $c['username'], $c['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$from = $connect($live);
$to   = $connect($staging);
$q    = fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

$tables = array_column($from->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM), 0);

$to->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (array_column($to->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM), 1, 0) as $name => $type) {
    $to->exec(($type === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . $q($name));
}

$total = 0;
foreach ($tables as $table) {
    $to->exec($from->query('SHOW CREATE TABLE ' . $q($table))->fetch(PDO::FETCH_NUM)[1]);

    $rows = $from->query('SELECT * FROM ' . $q($table));
    $batch = [];
    $count = 0;
    $flush = function () use (&$batch, $to, $table, $q) {
        if (!$batch) return;
        $cols = array_keys($batch[0]);
        $place = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql = 'INSERT INTO ' . $q($table) . ' (' . implode(',', array_map($q, $cols)) . ') VALUES '
             . implode(',', array_fill(0, count($batch), $place));
        $values = [];
        foreach ($batch as $r) foreach ($r as $v) $values[] = $v;
        $to->prepare($sql)->execute($values);
        $batch = [];
    };
    while ($row = $rows->fetch()) {
        $batch[] = $row;
        $count++;
        if (count($batch) >= 200) $flush();
    }
    $flush();
    $total += $count;
    echo str_pad($table, 24) . " $count rows\n";
}

$to->exec('SET FOREIGN_KEY_CHECKS = 1');
echo 'Staging refreshed from live: ' . count($tables) . " tables, $total rows, " . date('Y-m-d H:i') . "\n";
