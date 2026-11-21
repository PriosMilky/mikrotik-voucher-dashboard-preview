<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Router.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

verify_csrf();
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$value = static fn(string $key): string => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';

try {
    if ($action === 'save') {
        $portText = $value('port');
        if (!ctype_digit($portText)) {
            throw new InvalidArgumentException('Port API harus berupa angka.');
        }

        router_config_save(
            $value('host'),
            (int) $portText,
            $value('username'),
            is_string($_POST['password'] ?? null) ? $_POST['password'] : '',
            ($value('ssl') === '1')
        );
        $_SESSION['router_flash'] = [
            'type' => 'success',
            'message' => 'Konfigurasi MikroTik tersimpan terenkripsi. Tekan Test Connection untuk menguji koneksi.',
        ];
    } elseif ($action === 'test') {
        if (router_config_load() === null) {
            throw new InvalidArgumentException('Simpan konfigurasi MikroTik terlebih dahulu.');
        }
        (new Router())->testConnection();
        $_SESSION['router_flash'] = [
            'type' => 'success',
            'message' => 'Koneksi API MikroTik berhasil.',
        ];
    } else {
        throw new InvalidArgumentException('Aksi konfigurasi tidak valid.');
    }
} catch (InvalidArgumentException $exception) {
    $_SESSION['router_flash'] = [
        'type' => 'error',
        'message' => $exception->getMessage(),
    ];
} catch (Throwable $exception) {
    error_log('[Voucher Dashboard] Router operation failed (' . get_class($exception) . ')');
    $_SESSION['router_flash'] = [
        'type' => 'error',
        'message' => $action === 'save'
            ? 'Konfigurasi tidak dapat disimpan. Periksa APP_KEY dan permission folder storage.'
            : 'Koneksi gagal. Periksa IP, port API, kredensial, sertifikat TLS, dan firewall MikroTik.',
    ];
}

header('Location: index.php', true, 303);
exit;
