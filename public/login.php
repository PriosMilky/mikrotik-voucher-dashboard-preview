<?php
require_once __DIR__ . '/../app/bootstrap.php';
if (!empty($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 verify_csrf();
 $st=db()->prepare('SELECT id,password_hash FROM admins WHERE username=?');
 $st->execute([trim($_POST['username'] ?? '')]); $a=$st->fetch();
 if ($a && password_verify($_POST['password'] ?? '', $a['password_hash'])) {
   session_regenerate_id(true); $_SESSION['admin_id']=$a['id']; header('Location: index.php'); exit;
 }
 $error='Username atau password salah.';
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login</title><link rel="stylesheet" href="style.css"></head><body><main class="login"><h1>Admin Login</h1><?php if($error):?><p class="notice"><?=e($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Username</label><input name="username" required autofocus><label>Password</label><input type="password" name="password" required><button>Login</button></form></main></body></html>
