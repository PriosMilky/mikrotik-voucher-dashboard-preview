<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$batchId = filter_input(INPUT_GET, 'batch_id', FILTER_VALIDATE_INT);
if (!$batchId) { http_response_code(400); exit('Batch tidak valid.'); }
$st = db()->prepare('SELECT b.id,b.profile_name,b.limit_uptime,c.client_no,c.customer_name,c.phone FROM voucher_batches b JOIN clients c ON c.id=b.client_id WHERE b.id=?');
$st->execute([$batchId]);
$batch = $st->fetch();
if (!$batch) { http_response_code(404); exit('Data transaksi tidak ditemukan.'); }
$st = db()->prepare('SELECT username,password_enc FROM vouchers WHERE batch_id=? ORDER BY id');
$st->execute([$batchId]);
$vouchers = $st->fetchAll();
$lines = [
    'Halo ' . $batch['customer_name'] . '!', '',
    'Terima kasih sudah menggunakan layanan WiFi CO&CO Hub.',
    'Berikut detail voucher WiFi Anda:', '',
    'Nomor Client: ' . $batch['client_no'],
    'Nama: ' . $batch['customer_name'],
    'Profile: ' . $batch['profile_name'],
    'Durasi: ' . $batch['limit_uptime'] . ' (akumulatif)', '',
    'Detail login:'
];
foreach ($vouchers as $i => $v) {
    $lines[] = ($i + 1) . '. Username: ' . $v['username'] . ' | Password: ' . app_decrypt($v['password_enc']);
}
$lines[] = '';
$lines[] = 'Silakan hubungkan perangkat ke WiFi CO&CO Hub dan login menggunakan akun di atas.';
$lines[] = 'Selamat menikmati fasilitas kami!';
$phone = preg_replace('/\D+/', '', $batch['phone']);
$pdo = db();
$update = $pdo->prepare("UPDATE voucher_batches SET whatsapp_status=IF(whatsapp_status='confirmed','confirmed','opened') WHERE id=?");
$update->execute([$batchId]);
audit('whatsapp_opened', 'Batch ' . $batchId . ' / ' . $batch['client_no']);
if (router_demo_mode()) {
    $message = implode("\n", $lines);
    ?>
    <!doctype html>
    <html lang="id">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Pratinjau WhatsApp - CO&amp;CO Hub</title>
      <link rel="stylesheet" href="style.css">
    </head>
    <body>
      <header><h1>CO&amp;CO Hub · Voucher Manager</h1><nav><a href="index.php">Dashboard</a></nav></header>
      <main>
        <p><a href="index.php">← Kembali ke Dashboard</a></p>
        <section class="result-card">
          <h2>Pratinjau Pesan WhatsApp</h2>
          <p class="notice">Mode demo: pesan ini tidak dikirim dan halaman ini tidak menghubungi WhatsApp.</p>
          <p>Tujuan simulasi: <b><?=e($phone)?></b></p>
          <pre class="message-preview"><?=e($message)?></pre>
          <form method="post" action="confirm-whatsapp.php" class="inline-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="batch_id" value="<?=$batchId?>">
            <button type="submit">Simulasikan konfirmasi operator</button>
          </form>
          <p><a href="index.php">Kembali ke Dashboard</a></p>
        </section>
      </main>
    </body>
    </html>
    <?php
    exit;
}

$url = 'https://wa.me/' . $phone . '?text=' . rawurlencode(implode("\n", $lines));
header('Location: ' . $url, true, 302);
exit;
