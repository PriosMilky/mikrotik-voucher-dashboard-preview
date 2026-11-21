<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Router.php';

$router = null;
$demoMode = router_demo_mode();
$profiles = [];
$error = '';
$created = [];
$clientNo = '';
$customerName = '';
$phone = '';
$waUrl = '';
$batchId = 0;

try {
    $router = new Router();
    $demoMode = $router->isDemoMode();
    $profiles = $router->profiles();
}
catch (Throwable $ex) { $error = 'Tidak dapat membaca profile MikroTik: ' . $ex->getMessage(); }

function normalize_wa_number(string $phone): string {
    $digits = preg_replace('/\\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0')) $digits = '62' . substr($digits, 1);
    if (str_starts_with($digits, '8')) $digits = '62' . $digits;
    if (!str_starts_with($digits, '62') || strlen($digits) < 10 || strlen($digits) > 15) {
        throw new InvalidArgumentException('Nomor WhatsApp harus nomor Indonesia yang valid, contoh 081234567890.');
    }
    return $digits;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $customerName = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $profile = trim($_POST['profile'] ?? '');
    $qty = 1;

    if (!$router) $error = 'Koneksi MikroTik belum siap. Periksa konfigurasi dan status koneksi pada dashboard.';
    elseif ($customerName === '' || mb_strlen($customerName) > 120) $error = 'Nama pelanggan wajib diisi (maksimal 120 karakter).';
    elseif (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $profile)) $error = 'Profile Hotspot tidak valid.';
    else {
        try {
            $waNumber = normalize_wa_number($phone);
            $pdo = db();
            $pdo->beginTransaction();
            $dateKey = date('Y-m-d');
            $seq = $pdo->prepare('INSERT INTO client_sequences(client_date,last_number) VALUES(?,1) ON DUPLICATE KEY UPDATE last_number=last_number+1');
            $seq->execute([$dateKey]);
            $getSeq = $pdo->prepare('SELECT last_number FROM client_sequences WHERE client_date=? FOR UPDATE');
            $getSeq->execute([$dateKey]);
            $sequence = (int)$getSeq->fetchColumn();
            $clientNo = 'CC-' . date('ymd') . '-' . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT);

            $client = $pdo->prepare('INSERT INTO clients(client_no,customer_name,phone,created_by) VALUES(?,?,?,?)');
            $client->execute([$clientNo, $customerName, $waNumber, $_SESSION['admin_id']]);
            $clientId = (int)$pdo->lastInsertId();
            $batchCode = 'B' . date('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
            $batch = $pdo->prepare("INSERT INTO voucher_batches(client_id,batch_code,profile_name,limit_uptime,quantity,created_by) VALUES(?,?,?,'10h',?,?)");
            $batch->execute([$clientId, $batchCode, $profile, $qty, $_SESSION['admin_id']]);
            $batchId = (int)$pdo->lastInsertId();
            $insertVoucher = $pdo->prepare("INSERT INTO vouchers(batch_id,username,password_enc,profile_name,limit_uptime) VALUES(?,?,?,?,'10h')");

            for ($i = 0; $i < $qty; $i++) {
                $username = 'CC' . strtoupper(bin2hex(random_bytes(4)));
                $password = strtoupper(bin2hex(random_bytes(4)));
                $router->addVoucher($username, $password, $profile, '10h');
                $created[] = ['username' => $username, 'password' => $password, 'profile' => $profile];
                $insertVoucher->execute([$batchId, $username, app_encrypt($password), $profile]);
            }
            $pdo->commit();
            audit('voucher_batch_created', "$batchCode; $clientNo; $customerName; $waNumber; $qty vouchers");

            $waUrl = 'whatsapp.php?batch_id=' . $batchId;
        } catch (Throwable $ex) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            foreach ($created as $voucher) { try { $router->deleteVoucher($voucher['username']); } catch (Throwable $ignored) {} }
            $created = [];
            $error = 'Gagal membuat voucher: ' . $ex->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Buat Voucher - CO&CO Hub</title><link rel="stylesheet" href="style.css"></head>
<body>
<header><h1>CO&CO Hub · Voucher Manager</h1><nav><a href="index.php">Dashboard</a><a href="logout.php">Logout</a></nav></header>
<main>
  <p><a href="index.php">← Kembali ke Dashboard</a></p>
  <h2>Buat Voucher Pelanggan</h2>
  <p class="muted">Setiap pelanggan mendapat tepat satu voucher: satu ID dan satu password.</p>
  <?php if ($demoMode): ?><p class="notice">Mode demo: voucher hanya disimpan di database lokal. MikroTik dan WhatsApp tidak dihubungi.</p><?php endif; ?>
  <?php if ($error): ?><p class="notice"><?=e($error)?></p><?php endif; ?>
  <?php if (!$created): ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <label>Nama pelanggan</label><input name="customer_name" maxlength="120" value="<?=e($customerName)?>" placeholder="Contoh: Budi Santoso" required>
    <label>Nomor WhatsApp</label><input name="phone" type="tel" value="<?=e($phone)?>" placeholder="081234567890" required>
    <small class="muted">Nomor Client dibuat otomatis: CC-YYMMDD-001. Setiap transaksi mendapat nomor Client baru.</small>
    <label>Profile Hotspot</label>
    <select name="profile" required><option value="">Pilih profile</option><?php foreach ($profiles as $p): $pn=$p['name']??''; ?><option value="<?=e($pn)?>" <?=($_POST['profile']??'')===$pn?'selected':''?>><?=e($pn)?></option><?php endforeach; ?></select>
    <p class="muted">Batas setiap voucher: 10 jam pemakaian akumulatif, diatur langsung oleh MikroTik.</p>
    <button type="submit">Generate Voucher</button>
  </form>
  <?php else: ?>
  <section class="result-card">
    <h2>Voucher berhasil dibuat</h2>
    <p><span class="pill">Nomor Client: <?=e($clientNo)?></span></p>
    <p><b><?=e($customerName)?></b> · <?=e($phone)?></p>
    <p class="muted">Profile <?=e($created[0]['profile'])?> · 10 jam akumulatif</p>
    <div class="table-wrap"><table><tr><th>ID / Username</th><th>Password</th></tr>
    <?php foreach ($created as $v): ?><tr><td><b><?=e($v['username'])?></b></td><td><b><?=e($v['password'])?></b></td></tr><?php endforeach; ?>
    </table></div>
    <p class="muted"><?php if ($demoMode): ?>Lihat simulasi pesan WhatsApp. Preview ini tidak mengirim pesan ke pelanggan.<?php else: ?>Klik tombol WhatsApp. Pesan akan terbuka di WhatsApp; operator tetap perlu menekan Send. Sistem tidak bisa memastikan pesan benar-benar terkirim hanya dari link yang dibuka.<?php endif; ?></p>
    <a class="button whatsapp" href="<?=e($waUrl)?>"><?php if ($demoMode): ?>Lihat Pratinjau Pesan<?php else: ?>Buka WhatsApp dan Kirim Voucher<?php endif; ?></a>
    <form method="post" action="confirm-whatsapp.php" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="batch_id" value="<?=$batchId?>"><button type="submit">Tandai sudah dikirim</button></form>
    <p><a href="generate.php">Buat transaksi / pelanggan berikutnya</a> · <a href="index.php">Kembali ke Dashboard</a></p>
  </section>
  <?php endif; ?>
</main></body></html>
