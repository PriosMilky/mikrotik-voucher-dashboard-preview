<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$pdo = db();
$counts = [
 'clients' => (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn(),
 'vouchers' => (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE router_status <> 'deleted'")->fetchColumn(),
 'today' => (int)$pdo->query('SELECT COUNT(*) FROM clients WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
 'batches' => (int)$pdo->query('SELECT COUNT(*) FROM voucher_batches')->fetchColumn()
];
$recent = $pdo->query("SELECT c.client_no,c.customer_name,c.phone,c.created_at AS client_created,b.profile_name,b.quantity,b.whatsapp_status,b.id AS batch_id FROM voucher_batches b JOIN clients c ON c.id=b.client_id ORDER BY b.id DESC LIMIT 20")->fetchAll();
$active = null; $routerError = null;
$demoMode = false;
$routerConfig = null;
$configError = false;
$routerFlash = $_SESSION['router_flash'] ?? null;
unset($_SESSION['router_flash']);
try { $routerConfig = router_config_load(); }
catch (Throwable $ex) { $configError = true; }
try {
 require_once __DIR__.'/../vendor/autoload.php';
 require_once __DIR__.'/../app/Router.php';
 $router = new Router();
 $demoMode = $router->isDemoMode();
 $active = $router->activeUsers();
}
catch(Throwable $ex) { $routerError = $ex->getMessage(); }
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CO&CO Hub Voucher Manager</title><link rel="stylesheet" href="style.css"></head><body>
<header><h1>CO&CO Hub · Voucher Manager</h1><nav><a href="generate.php">+ Buat Voucher</a><a href="logout.php">Logout</a></nav></header>
<main>
<div class="welcome"><div><h2>Dashboard</h2><p class="muted">Kelola pelanggan dan voucher WiFi dengan mudah.</p></div><a class="button" href="generate.php">+ Buat Voucher Baru</a></div>
<div class="cards"><div class="card"><small>Total pelanggan/transaksi</small><strong><?=$counts['clients']?></strong></div><div class="card"><small>Total voucher dibuat</small><strong><?=$counts['vouchers']?></strong></div><div class="card"><small>Client hari ini</small><strong><?=$counts['today']?></strong></div><div class="card"><small>User online</small><strong><?=is_array($active)?count($active):'—'?></strong></div></div>
<?php if (is_array($routerFlash)): ?><p class="<?=($routerFlash['type'] ?? '') === 'success' ? 'success-line' : 'notice'?>"><?=e($routerFlash['message'] ?? '')?></p><?php endif; ?>
<?php if ($demoMode): ?><p class="notice">Mode demo aktif: data dan user online adalah simulasi. Tidak ada koneksi ke MikroTik atau pengiriman WhatsApp.</p>
<?php elseif ($routerError): ?><p class="notice">Status MikroTik belum tersedia: <?=e($routerError)?></p>
<?php else: ?><p class="success-line">● Terhubung ke MikroTik · <?=count($active)?> user online</p><?php endif; ?>
<h2>Transaksi pelanggan terbaru</h2><div class="table-wrap"><table><tr><th>Nomor Client</th><th>Nama</th><th>WhatsApp</th><th>Profile</th><th>Voucher</th><th>Status WA</th><th>Tanggal</th><th>Aksi</th></tr>
<?php foreach ($recent as $r): ?><tr><td><b><?=e($r['client_no'])?></b></td><td><?=e($r['customer_name'])?></td><td><?=e($r['phone'])?></td><td><?=e($r['profile_name'])?></td><td><?= (int)$r['quantity'] ?></td><td><?=e(['not_opened'=>'Belum dibuka','opened'=>'WhatsApp dibuka','confirmed'=>'Dikonfirmasi operator'][$r['whatsapp_status']] ?? $r['whatsapp_status'])?></td><td><?=e($r['client_created'])?></td><td><a href="whatsapp.php?batch_id=<?=(int)$r['batch_id']?>"><?=$demoMode ? 'Lihat pesan' : 'WhatsApp'?></a></td></tr><?php endforeach; ?>
</table></div>
<p class="muted">Catatan: status WhatsApp pada versi ini belum otomatis mendeteksi pesan terkirim. Membuka WhatsApp tidak sama dengan pesan berhasil dikirim.</p>
<section class="result-card">
  <h2>Koneksi MikroTik dari PC ini</h2>
  <p class="muted">PC yang menjalankan Apache menghubungi RouterOS melalui LAN. Simpan IP router dan akun API khusus di sini; password dienkripsi dan tidak ditampilkan kembali.</p>
  <?php if ($configError): ?><p class="notice">Konfigurasi router tersimpan tidak dapat dibaca. Periksa APP_KEY dan file storage.</p><?php endif; ?>
  <form method="post" action="router-settings.php" class="form router-form">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <label for="router-host">IP MikroTik di jaringan LAN</label>
    <input id="router-host" name="host" inputmode="decimal" placeholder="192.168.88.1" value="<?=e($routerConfig['host'] ?? '')?>" required>
    <label for="router-port">Port API</label>
    <input id="router-port" name="port" type="number" min="1" max="65535" value="<?=e((string)($routerConfig['port'] ?? 8728))?>" required>
    <label for="router-username">Username akun API MikroTik</label>
    <input id="router-username" name="username" maxlength="64" value="<?=e($routerConfig['username'] ?? '')?>" required>
    <label for="router-password">Password akun API MikroTik</label>
    <input id="router-password" name="password" type="password" autocomplete="new-password" placeholder="<?=$routerConfig === null ? 'Masukkan password API' : 'Kosongkan untuk mempertahankan password tersimpan'?>" <?=$routerConfig === null ? 'required' : ''?>>
    <label class="ssl-label"><input type="checkbox" name="ssl" value="1" <?=($routerConfig['ssl'] ?? false) ? 'checked' : ''?>> Gunakan API-SSL (umumnya port 8729)</label>
    <div class="router-actions">
      <button type="submit" name="action" value="save">Simpan konfigurasi</button>
      <button type="submit" name="action" value="test" <?=$routerConfig === null ? 'disabled' : ''?>>Test Connection</button>
    </div>
  </form>
  <p class="muted">API biasa menggunakan port 8728; API-SSL umumnya 8729 dan memerlukan sertifikat TLS yang valid. Jangan membuka port API ke internet. Saat preview tidak ada konfigurasi router, mode demo tetap aktif.</p>
</section>
</main></body></html>
