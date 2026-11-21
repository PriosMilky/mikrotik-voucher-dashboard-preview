<?php
declare(strict_types=1);

use RouterOS\Client;
use RouterOS\Query;

final class Router {
    private Client $client;
    private bool $demoMode;

    public function __construct() {
        $settings = router_config_load();
        $this->demoMode = $settings === null
            && filter_var(envv('DEMO_MODE', 'false'), FILTER_VALIDATE_BOOLEAN);
        if ($this->demoMode) {
            return;
        }

        if (!class_exists(Client::class)) {
            throw new RuntimeException('RouterOS API package belum terpasang. Jalankan composer require evilfreelancer/routeros-api-php');
        }
        $ssl = $settings['ssl'] ?? filter_var(envv('MT_SSL','true'), FILTER_VALIDATE_BOOLEAN);
        $sslOptions = [
            'verify_peer' => filter_var(envv('MT_SSL_VERIFY','true'), FILTER_VALIDATE_BOOLEAN),
            'verify_peer_name' => filter_var(envv('MT_SSL_VERIFY','true'), FILTER_VALIDATE_BOOLEAN),
        ];
        $caFile = envv('MT_SSL_CA_FILE');
        if ($caFile) $sslOptions['cafile'] = $caFile;
        $options = [
            'host' => $settings['host'] ?? envv('MT_HOST'),
            'user' => $settings['username'] ?? envv('MT_USER'),
            'pass' => $settings['password'] ?? envv('MT_PASS'),
            'port' => $settings['port'] ?? (int)envv('MT_PORT','8729'),
            'ssl' => $ssl,
            'ssl_options' => $sslOptions,
            'timeout' => 5,
            'attempts' => 1,
        ];
        // Package options for certificate validation may differ by release.
        // Keep TLS verification enabled at the PHP/OpenSSL layer; install the CA certificate
        // in the Windows trust store or configure the package's CA options after checking its docs.
        $this->client = new Client($options);
    }

    public function addVoucher(string $username, string $password, string $profile, string $limitUptime): void {
        if ($this->demoMode) {
            return;
        }

        $q = new Query('/ip/hotspot/user/add');
        $q->equal('name', $username)
          ->equal('password', $password)
          ->equal('profile', $profile)
          ->equal('limit-uptime', $limitUptime)
          ->equal('comment', 'Created by Voucher Dashboard');
        $this->client->query($q)->read();
    }

    public function setDisabled(string $username, bool $disabled): void {
        if ($this->demoMode) {
            return;
        }

        $rows = $this->client->query((new Query('/ip/hotspot/user/print'))
            ->where('name', $username))->read();
        if (!$rows) throw new RuntimeException('Voucher tidak ditemukan di MikroTik: ' . $username);
        $id = $rows[0]['.id'];
        $q = new Query('/ip/hotspot/user/set');
        $q->equal('.id', $id)->equal('disabled', $disabled ? 'yes' : 'no');
        $this->client->query($q)->read();
    }

    public function deleteVoucher(string $username): void {
        if ($this->demoMode) {
            return;
        }

        $rows = $this->client->query((new Query('/ip/hotspot/user/print'))
            ->where('name', $username))->read();
        if (!$rows) return;
        $q = new Query('/ip/hotspot/user/remove');
        $q->equal('.id', $rows[0]['.id']);
        $this->client->query($q)->read();
    }

    public function activeUsers(): array {
        if ($this->demoMode) {
            return [
                ['user' => 'demo-user-01', 'address' => '192.168.88.201'],
                ['user' => 'demo-user-02', 'address' => '192.168.88.202'],
            ];
        }

        return $this->client->query(new Query('/ip/hotspot/active/print'))->read();
    }

    public function profiles(): array {
        if ($this->demoMode) {
            return [
                ['name' => 'default'],
                ['name' => 'voucher-1h'],
                ['name' => 'voucher-10h'],
            ];
        }

        return $this->client->query(new Query('/ip/hotspot/user/profile/print'))->read();
    }

    public function isDemoMode(): bool {
        return $this->demoMode;
    }

    public function testConnection(): void {
        if ($this->demoMode) {
            throw new RuntimeException('Mode demo aktif. Simpan konfigurasi MikroTik untuk menguji koneksi nyata.');
        }

        $this->client->query(new Query('/system/resource/print'))->read();
    }
}
