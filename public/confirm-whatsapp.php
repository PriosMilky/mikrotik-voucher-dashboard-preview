<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
verify_csrf();
$batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
if (!$batchId) { http_response_code(400); exit('Batch tidak valid.'); }
$st = db()->prepare("UPDATE voucher_batches SET whatsapp_status='confirmed',whatsapp_confirmed_at=NOW() WHERE id=?");
$st->execute([$batchId]);
audit('whatsapp_marked_sent', 'Batch ' . $batchId . ' ditandai sudah dikirim operator');
header('Location: index.php');
exit;
