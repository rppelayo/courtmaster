<?php
function dbConfigEnv(string $key, string $default): string
{
    $value = getenv('COURTMASTER_' . strtoupper($key));
    if ($value === false || trim($value) === '') {
        return $default;
    }

    return trim($value);
}

$config = [
    'host' => dbConfigEnv('db_host', 'localhost'),
    'dbname' => dbConfigEnv('db_name', 'courtmaster'),
    'user' => dbConfigEnv('db_user', 'olan88'),
    'pass' => dbConfigEnv('db_pass', 'secret88!'),
    'charset' => dbConfigEnv('db_charset', 'utf8mb4'),
    'app_env' => dbConfigEnv('app_env', 'local'),
    'timezone' => dbConfigEnv('timezone', 'Asia/Manila'),
];

$credentialsPath = __DIR__ . '/db.credentials.php';
if (is_file($credentialsPath)) {
    $fileConfig = require $credentialsPath;
    if (is_array($fileConfig)) {
        foreach ($fileConfig as $key => $value) {
            if (array_key_exists($key, $config) && is_string($value) && trim($value) !== '') {
                $config[$key] = trim($value);
            }
        }
    }
}

if (!empty($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['dbname'],
        $config['charset']
    );

    $pdo = new PDO($dsn, $config['user'], $config['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $message = $config['app_env'] === 'production'
        ? 'DB Connection failed.'
        : 'DB Connection failed: ' . $e->getMessage();

    die($message);
}
?>
