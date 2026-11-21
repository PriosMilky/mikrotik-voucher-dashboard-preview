<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
session_start();

function envv(string $key, ?string $default = null): ?string {
    static $env = null;
    if ($env === null) {
        $env = [];
        $path = dirname(__DIR__) . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    return $env[$key] ?? $default;
}
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        envv('DB_HOST','127.0.0.1'), envv('DB_PORT','3306'), envv('DB_NAME','mikrotik_vouchers'));
    $pdo = new PDO($dsn, envv('DB_USER','root'), envv('DB_PASS',''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    return $pdo;
}
function e(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function require_login(): void {
    if (empty($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419); exit('Invalid CSRF token');
    }
}
function audit(string $action, string $details = ''): void {
    $st = db()->prepare('INSERT INTO audit_logs(admin_id,action,details) VALUES(?,?,?)');
    $st->execute([$_SESSION['admin_id'] ?? null, $action, $details]);
}

function router_config_path(): string {
    return dirname(__DIR__) . '/storage/router-config.json';
}

function router_config_load(): ?array {
    $path = router_config_path();
    if (!is_file($path)) return null;
    $contents = file_get_contents($path);
    if ($contents === false) throw new RuntimeException('Konfigurasi MikroTik tidak dapat dibaca.');
    $stored = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($stored)
        || ($stored['format'] ?? null) !== 'mikrotik-voucher-router-config'
        || ($stored['version'] ?? null) !== 1
        || !is_string($stored['host'] ?? null)
        || !is_int($stored['port'] ?? null)
        || !is_string($stored['username'] ?? null)
        || !is_string($stored['password_enc'] ?? null)
        || !is_bool($stored['ssl'] ?? null)) {
        throw new RuntimeException('Format konfigurasi MikroTik tidak valid.');
    }

    return [
        'host' => $stored['host'],
        'port' => $stored['port'],
        'username' => $stored['username'],
        'password' => app_decrypt($stored['password_enc']),
        'ssl' => $stored['ssl'],
    ];
}

function router_config_save(string $host, int $port, string $username, string $password, bool $ssl): void {
    $host = trim($host);
    $username = trim($username);
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new InvalidArgumentException('Masukkan alamat IPv4 MikroTik yang valid.');
    }
    $octets = array_map('intval', explode('.', $host));
    $private = $octets[0] === 10
        || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
        || ($octets[0] === 192 && $octets[1] === 168);
    if (!$private) {
        throw new InvalidArgumentException('Gunakan IP privat router yang dapat dijangkau dari PC ini.');
    }
    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('Port harus berada di antara 1 dan 65535.');
    }
    if ($username === '' || strlen($username) > 64 || preg_match('/[\x00-\x1f\x7f]/', $username)) {
        throw new InvalidArgumentException('Username API tidak valid.');
    }

    $existing = router_config_load();
    if ($password === '' && $existing === null) {
        throw new InvalidArgumentException('Password API wajib diisi untuk koneksi pertama.');
    }
    if ($password === '' && $existing !== null) {
        $password = $existing['password'];
    }
    if (!is_dir(dirname(router_config_path()))
        && !mkdir(dirname(router_config_path()), 0700, true)
        && !is_dir(dirname(router_config_path()))) {
        throw new RuntimeException('Folder penyimpanan konfigurasi tidak dapat dibuat.');
    }

    $stored = [
        'format' => 'mikrotik-voucher-router-config',
        'version' => 1,
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'password_enc' => app_encrypt($password),
        'ssl' => $ssl,
    ];
    $path = router_config_path();
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    try {
        $json = json_encode($stored, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Konfigurasi MikroTik tidak dapat ditulis.');
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Konfigurasi MikroTik tidak dapat disimpan.');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function router_demo_mode(): bool {
    return router_config_load() === null
        && filter_var(envv('DEMO_MODE', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function app_encryption_key(): string {
    $appKey = envv('APP_KEY', '');
    if ($appKey === '' || $appKey === 'replace-with-a-long-random-secret') {
        throw new RuntimeException('APP_KEY belum diatur pada file .env.');
    }
    return hash('sha256', $appKey, true);
}

function app_encrypt(string $plain): string {
    $key = app_encryption_key();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Tidak dapat mengenkripsi data rahasia.');
    return base64_encode($iv . $tag . $cipher);
}
function app_decrypt(string $encoded): string {
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Data rahasia tidak valid.');
    $key = app_encryption_key();
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException('Gagal membuka data rahasia. Periksa APP_KEY.');
    return $plain;
}
