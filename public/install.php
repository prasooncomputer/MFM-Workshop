<?php
require_once __DIR__ . '/lib/db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = [
        'host' => trim($_POST['host'] ?? '127.0.0.1'),
        'port' => trim($_POST['port'] ?? '3306'),
        'name' => trim($_POST['name'] ?? 'mfm_workshop'),
        'user' => trim($_POST['user'] ?? 'root'),
        'pass' => (string)($_POST['pass'] ?? ''),
    ];

    try {
        test_db_connection($cfg, false);
        $rootPdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = file_get_contents(__DIR__ . '/../docs/schema.sql');
        $schema = preg_replace('/CREATE DATABASE IF NOT EXISTS\s+[^;]+;\s*/i', '', $schema);
        $schema = preg_replace('/USE\s+[^;]+;\s*/i', '', $schema);
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            $pdo->exec($stmt);
        }

        $export = "<?php\nreturn " . var_export($cfg, true) . ";\n";
        file_put_contents(__DIR__ . '/config.local.php', $export);
        $message = 'Installation successful. You can now use the system.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html><head><title>Installer</title><link rel="stylesheet" href="style.css"></head><body>
<?php include 'nav.php'; ?>
<div class="container">
<div class="card"><h2>Workshop System Installer</h2><p>Configure DB connection and initialize schema.</p></div>
<?php if ($message): ?><div class="card success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="card error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="card">
<form method="post" class="grid grid-2">
  <label>DB Host<input name="host" value="<?= htmlspecialchars($_POST['host'] ?? '127.0.0.1') ?>"></label>
  <label>DB Port<input name="port" value="<?= htmlspecialchars($_POST['port'] ?? '3306') ?>"></label>
  <label>DB Name<input name="name" value="<?= htmlspecialchars($_POST['name'] ?? 'mfm_workshop') ?>"></label>
  <label>DB User<input name="user" value="<?= htmlspecialchars($_POST['user'] ?? 'root') ?>"></label>
  <label>DB Password<input type="password" name="pass" value="<?= htmlspecialchars($_POST['pass'] ?? '') ?>"></label>
  <div style="align-self:end"><button type="submit">Save & Initialize</button></div>
</form>
</div>
</div>
</body></html>
