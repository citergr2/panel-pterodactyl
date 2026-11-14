<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace($config, require __DIR__ . '/config.local.php');
}
session_name((string)($config['session_name'] ?? 'ptero_portal_session'));
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);

session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();
csrf_token();
$panels = $config['panels'] ?? [];
$selectedPanel = $_SESSION['selected_panel'] ?? 'panel1';

if (!isset($panels[$selectedPanel])) {
    $selectedPanel = 'panel1';
    $_SESSION['selected_panel'] = $selectedPanel;
}

$config['panel_url'] = $panels[$selectedPanel]['url'];
$config['ptla_key'] = $panels[$selectedPanel]['api_key'];
if (isset($_GET['debug_session'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'HTTPS: ' . (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'on' : 'off'
    ) . PHP_EOL;
    echo 'Session ID exists: ' . (session_id() !== '' ? 'yes' : 'no') . PHP_EOL;
    echo 'CSRF exists: ' . (!empty($_SESSION['csrf']) ? 'yes' : 'no') . PHP_EOL;
    echo 'Cookie received: ' . (isset($_COOKIE[session_name()]) ? 'yes' : 'no') . PHP_EOL;
    exit;
}
function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}
function verify_csrf(): void {
    $sent = (string)($_POST['csrf'] ?? '');

    if (
        $sent === '' ||
        !hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)
    ) {
        http_response_code(400);
        exit('Token keamanan tidak valid. Muat ulang halaman dan coba lagi.');
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'select_panel'
) {
    verify_csrf();

    $selected = (string)($_POST['panel'] ?? '');
    $panels = $config['panels'] ?? [];

    if (
        !in_array($selected, ['panel1', 'panel2'], true) ||
        !isset($panels[$selected])
    ) {
        http_response_code(400);
        exit('Pilihan panel tidak valid.');
    }

    $_SESSION['selected_panel'] = $selected;

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
function panel_base(array $config): string {
    $url = rtrim((string)($config['panel_url'] ?? ''), '/');
    if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        throw new RuntimeException('PTERO_PANEL_URL harus URL HTTPS yang valid.');
    }
    return $url;
}
function api_request(array $config, string $apiType, string $method, string $path, ?array $body = null, ?string $clientKey = null): array {
    $base = panel_base($config);
    $key = $apiType === 'application' ? (string)($config['ptla_key'] ?? '') : (string)$clientKey;
    if ($key === '') return ['ok'=>false, 'status'=>0, 'error'=> $apiType === 'application' ? 'PTLA belum dikonfigurasi.' : 'PTLC belum dimasukkan.'];
    if (!function_exists('curl_init')) return ['ok'=>false, 'status'=>0, 'error'=>'Ekstensi PHP cURL belum aktif.'];
    $url = $base . '/api/' . $apiType . '/' . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . $key,
        'Accept: Application/vnd.pterodactyl.v1+json',
        'Content-Type: application/json',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return ['ok'=>false, 'status'=>$status, 'error'=>'Koneksi API gagal: '.$curlError];
    $data = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300) {
        $detail = is_array($data) ? ($data['errors'][0]['detail'] ?? $data['message'] ?? 'Panel menolak permintaan.') : 'Respons API tidak dikenali.';
        return ['ok'=>false, 'status'=>$status, 'error'=>(string)$detail];
    }
    return ['ok'=>true, 'status'=>$status, 'data'=>is_array($data) ? $data : []];
}
function app_get(array $config, string $path): array {
    return api_request($config, 'application', 'GET', $path);
}
function getRandomAllocation(array $config, int $nodeId): int {
    $available = [];
    $page = 1;
    $totalPages = 1;

    do {
        $r = app_get(
            $config,
            "nodes/{$nodeId}/allocations?page={$page}&per_page=100"
        );

        if (!$r['ok']) {
            throw new RuntimeException(
                'Gagal mengambil allocation: ' . $r['error']
            );
        }

        foreach (($r['data']['data'] ?? []) as $row) {
            $a = $row['attributes'] ?? [];

            if (
                isset($a['id'], $a['assigned']) &&
                $a['assigned'] === false
            ) {
                $available[] = (int)$a['id'];
            }
        }

        $totalPages = (int)(
            $r['data']['meta']['pagination']['total_pages'] ?? $page
        );

        $page++;
    } while ($page <= $totalPages);

    if (!$available) {
        throw new RuntimeException(
            "Tidak ada allocation kosong di Node ID {$nodeId}."
        );
    }

    return $available[random_int(0, count($available) - 1)];
}
function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type'=>$type, 'message'=>$message];
}
function list_data(array $response): array {
    return $response['ok'] ? (array)($response['data']['data'] ?? []) : [];
}
function attr(array $row): array {
    return (array)($row['attributes'] ?? []);
}
function require_login(): void {
    if (empty($_SESSION['logged_in'])) throw new RuntimeException('Silakan login terlebih dahulu.');
}
function env_json(string $field, string $fallback = '{}'): array {
    $raw = (string)($_POST[$field] ?? $fallback);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || array_is_list($decoded)) {
        // Empty object decodes as [] too, so permit literal {} explicitly.
        if (trim($raw) !== '{}' && trim($raw) !== '[]') throw new RuntimeException("$field harus berupa JSON object.");
    }
    return $decoded ?? [];
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
  if ($action === 'login') {
            $expected = (string)($config['admin_password'] ?? '');
            if ($expected === '') throw new RuntimeException('PTERO_ADMIN_PASSWORD belum dikonfigurasi di VPS.');
            if (!hash_equals($expected, (string)($_POST['password'] ?? ''))) throw new RuntimeException('Password portal salah.');
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            flash('success', 'Berhasil login.');
        } else {
            require_login();
            if ($action === 'save_ptlc') {
                $key = trim((string)($_POST['ptlc_key'] ?? ''));
                if ($key !== '' && !preg_match('/^ptlc_[A-Za-z0-9_-]+$/', $key)) throw new RuntimeException('Format key PTLC tidak sesuai. Pastikan menggunakan Client API key.');
                $_SESSION['ptlc_key'] = $key;
                flash('success', $key === '' ? 'Key PTLC dihapus dari sesi.' : 'Key PTLC disimpan sementara di sesi login.');
            } elseif ($action === 'create_user') {
                $email = trim((string)($_POST['email'] ?? ''));
                $username = trim((string)($_POST['username'] ?? ''));
                $first = trim((string)($_POST['first_name'] ?? ''));
                $last = trim((string)($_POST['last_name'] ?? ''));
                $password = (string)($_POST['user_password'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Format email tidak valid.');
                if (!preg_match('/^[A-Za-z0-9_.-]{1,191}$/', $username)) throw new RuntimeException('Username hanya boleh berisi huruf, angka, titik, underscore, dan strip.');
                if ($first === '' || $last === '') throw new RuntimeException('Nama depan dan belakang wajib diisi.');
                if (strlen($password) < 12) throw new RuntimeException('Password awal minimal 12 karakter.');
                $r = api_request($config, 'application', 'POST', 'users', [
                    'email'=>$email, 'username'=>$username, 'first_name'=>$first, 'last_name'=>$last, 'password'=>$password
                ]);
                if (!$r['ok']) throw new RuntimeException('Buat user gagal (HTTP '.$r['status'].'): '.$r['error']);
                flash('success', 'User berhasil dibuat. ID: '.($r['data']['attributes']['id'] ?? 'cek daftar user'));
                } elseif ($action === 'create_server') {
    $userId = filter_var(
        $_POST['user_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    $eggId = filter_var(
        $_POST['egg_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    $name = trim((string)($_POST['server_name'] ?? ''));

    if (!$userId || !$eggId || $name === '') {
        throw new RuntimeException(
            'Nama server, User ID, dan Egg ID wajib diisi.'
        );
    }
    // Node yang digunakan untuk mencari allocation kosong.
    $nodeId = 2;

    // Pilih allocation kosong secara acak.
    $allocationId = getRandomAllocation($config, $nodeId);

    $environment = env_json('environment');
    $features = env_json(
        'feature_limits',
        '{"databases":1,"backups":1,"allocations":1}'
    );

    $dockerImage = trim(
        (string)($_POST['docker_image'] ?? '')
    );


    $limits = [
        'memory' => max(0, (int)($_POST['memory'] ?? 2048)),
        'swap'   => max(0, (int)($_POST['swap'] ?? 0)),
        'disk'   => max(0, (int)($_POST['disk'] ?? 10240)),
        'io'     => max(10, min(1000, (int)($_POST['io'] ?? 500))),
        'cpu'    => max(0, (int)($_POST['cpu'] ?? 200)),
    ];

    if ($dockerImage === '') {
        throw new RuntimeException('Docker image wajib diisi.');
    }

    // Startup kosong akan menggunakan startup default Egg.
    // Jangan mengganti default Egg dengan npm start secara otomatis.
    $payload = [
        'name' => $name,
        'user' => (int)$userId,
        'egg' => (int)$eggId,
        'docker_image' => $dockerImage,
        'environment' => $environment,
        'limits' => $limits,
        'feature_limits' => $features,
        'allocation' => [
            'default' => $allocationId,
        ],
        'start_on_completion' =>
            !empty($_POST['start_on_completion']),
    ];


// Ambil startup dari form.
$startup = trim((string)($_POST['startup'] ?? ''));

if ($startup === '') {
    throw new RuntimeException(
        'Startup wajib diisi. Masukkan startup asli dari Egg Node.js.'
    );
}
$payload['startup'] = $startup;

    $r = api_request(
        $config,
        'application',
        'POST',
        'servers',
        $payload
    );

    if (!$r['ok']) {
        throw new RuntimeException(
            'Buat server gagal (HTTP ' .
            $r['status'] . '): ' . $r['error']
        );
    }

    flash(
        'success',
        'Server berhasil dibuat. ID: ' .
        ($r['data']['attributes']['id'] ?? 'cek daftar server') .
        '. Allocation ID: ' . $allocationId
    );
     } elseif ($action === 'create_admin') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (
        $username === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        $firstName === '' ||
        $lastName === '' ||
        strlen($password) < 12
    ) {
        throw new RuntimeException(
            'Lengkapi data dengan benar. Password minimal 12 karakter.'
        );
    }

    $payload = [
        'username' => $username,
        'email' => $email,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'password' => $password,
        'root_admin' => true,
        'language' => 'en',
    ];

    $r = api_request(
        $config,
        'application',
        'POST',
        'users',
        $payload
    );

    if (!$r['ok']) {
        throw new RuntimeException(
            'Create admin gagal (HTTP ' .
            $r['status'] . '): ' . $r['error']
        );
    }

    flash(
        'success',
        'Admin berhasil dibuat. User ID: ' .
        ($r['data']['attributes']['id'] ?? 'cek daftar user')
    );
   } elseif ($action === 'delete_user') {
    $userId = filter_var(
        $_POST['user_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$userId || $userId < 1) {
        throw new RuntimeException('User ID tidak valid.');
    }

    // Pastikan user ada sebelum menghapus.
    $check = app_get($config, "users/{$userId}");

    if (!$check['ok']) {
        throw new RuntimeException(
            'User tidak ditemukan atau gagal diperiksa.'
        );
    }

    $r = api_request(
        $config,
        'application',
        'DELETE',
        "users/{$userId}"
    );

    if (!$r['ok']) {
        throw new RuntimeException(
            'Hapus user gagal (HTTP ' .
            $r['status'] . '): ' . $r['error']
        );
    }

    flash('success', "User ID {$userId} berhasil dihapus.");
            } elseif ($action === 'delete_server') {
                $id = filter_var($_POST['server_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) throw new RuntimeException('ID server tidak valid.');
                $r = api_request($config, 'application', 'DELETE', 'servers/'.$id);
                if (!$r['ok']) throw new RuntimeException('Hapus server gagal (HTTP '.$r['status'].'): '.$r['error']);
                flash('success', 'Permintaan hapus server berhasil dikirim.');
            } elseif ($action === 'client_power') {
                $identifier = trim((string)($_POST['server_identifier'] ?? ''));
                $signal = (string)($_POST['signal'] ?? '');
                if (!preg_match('/^[A-Za-z0-9-]{6,64}$/', $identifier)) throw new RuntimeException('Identifier server tidak valid.');
                if (!in_array($signal, ['start','stop','restart'], true)) throw new RuntimeException('Perintah daya tidak valid.');
                $r = api_request($config, 'client', 'POST', 'servers/'.rawurlencode($identifier).'/power', ['signal'=>$signal], (string)($_SESSION['ptlc_key'] ?? ''));
                if (!$r['ok']) throw new RuntimeException('Perintah PTLC gagal (HTTP '.$r['status'].'): '.$r['error']);
                flash('success', 'Perintah '.strtoupper($signal).' berhasil dikirim.');
            } else {
                throw new RuntimeException('Aksi tidak dikenal.');
            }
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    header('Location: index.php'); exit;
}

$isLoggedIn = !empty($_SESSION['logged_in']);
$notice = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$users = $servers = $nodes = $eggs = $locations = $clientServers = [];
$errors = [];
$panelHost = '';
if ($isLoggedIn) {
    try { $panelHost = (string)parse_url(panel_base($config), PHP_URL_HOST); }
    catch (Throwable $ex) { $errors[] = $ex->getMessage(); }
    if (!$errors) {
        $r = app_get($config, 'users?per_page=100');
        if ($r['ok']) $users = list_data($r); else $errors[] = 'Users: '.$r['error'];
        $r = app_get($config, 'servers?per_page=100');
        if ($r['ok']) $servers = list_data($r); else $errors[] = 'Servers: '.$r['error'];
        $r = app_get($config, 'nodes?per_page=100');
        if ($r['ok']) $nodes = list_data($r); else $errors[] = 'Nodes: '.$r['error'];
        $r = app_get($config, 'locations?per_page=100');
        if ($r['ok']) $locations = list_data($r); else $errors[] = 'Locations: '.$r['error'];
        if (!empty($_SESSION['selected_node_id'])) {
            $nodeId = (int)$_SESSION['selected_node_id'];
            $r = app_get($config, 'nodes/'.$nodeId.'/eggs?per_page=100');
            if ($r['ok']) $eggs = list_data($r);
        }
        if (!empty($_SESSION['ptlc_key'])) {
            $r = api_request($config, 'client', 'GET', '', null, (string)$_SESSION['ptlc_key']);
            if ($r['ok']) $clientServers = list_data($r); else $errors[] = 'PTLC: '.$r['error'];
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<title>XITERCSX CPANEL</title>
<style>
:root{--bg:#080d19;--panel:#10182a;--panel2:#151f35;--line:#293752;--text:#edf2ff;--muted:#98a8c8;--accent:#8ea1ff;--accent2:#b6c1ff;--good:#8de3b7;--bad:#ffaaaa;--warn:#ffd98b}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 18% 0,#1a2a51 0,transparent 42%),var(--bg);color:var(--text);font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
a{color:var(--accent2)}.wrap{max-width:1240px;margin:auto;padding:25px 18px 60px}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}.brand{display:flex;align-items:center;gap:13px}.logo{width:44px;height:44px;display:grid;place-items:center;background:linear-gradient(135deg,#8ea1ff,#bca8ff);color:#10162b;border-radius:13px;font-size:22px;font-weight:900}h1{font-size:26px;letter-spacing:-.6px;margin:0}h2{font-size:17px;margin:0 0 13px}h3{font-size:14px;margin:0 0 10px}.muted{color:var(--muted);font-size:12px}.grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:15px}.card{min-width:0;padding:18px;border:1px solid var(--line);background:linear-gradient(155deg,rgba(21,31,53,.97),rgba(13,21,37,.97));border-radius:16px;box-shadow:0 12px 35px #0002}.s12{grid-column:span 12}.s8{grid-column:span 8}.s6{grid-column:span 6}.s4{grid-column:span 4}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:15px}.stat{font-size:26px;font-weight:800;letter-spacing:-1px}.stat-label{font-size:12px;color:var(--muted)}.stat-icon{float:right;color:var(--accent2);font-size:20px}.pill{display:inline-block;border:1px solid var(--line);padding:4px 8px;border-radius:99px;color:#d7e0ff;font-size:11px}.notice{padding:12px 14px;margin-bottom:15px;border:1px solid var(--line);background:#111c30;border-radius:11px}.success{color:var(--good)}.error{color:var(--bad)}.warning{color:var(--warn)}label{display:block;font-size:12px;color:#cbd5ee;margin:0 0 4px}input,select,textarea{width:100%;margin:0 0 12px;padding:10px 11px;border:1px solid #344360;border-radius:9px;background:#0a1222;color:var(--text);font:inherit;outline:none}input:focus,select:focus,textarea:focus{border-color:var(--accent)}textarea{min-height:95px;resize:vertical}.row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.row>*{min-width:0}.field-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.field-row.three{grid-template-columns:repeat(3,minmax(0,1fr))}button,.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:9px;padding:10px 13px;background:var(--accent);color:#0a1022;font-weight:750;font-size:12px;text-decoration:none;cursor:pointer}button:hover,.btn:hover{filter:brightness(1.07)}button.secondary,.btn.secondary{background:#273653;color:#e9eeff}button.danger{background:#ffb0b0;color:#2a0909}button.ghost{background:transparent;border:1px solid var(--line);color:var(--text)}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px}th,td{text-align:left;padding:10px;border-bottom:1px solid #26334c;white-space:nowrap}th{color:#a7b6d7;font-weight:650}td{color:#e0e7fa}.empty{text-align:center;color:var(--muted);padding:24px}.smallform{display:inline}.smallform button{padding:7px 9px}.section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.login{max-width:430px;margin:8vh auto}.help{border-left:3px solid #6f82df;padding:8px 11px;background:#101a2d;color:var(--muted);font-size:12px;margin:4px 0 12px}.check{display:flex;gap:9px;align-items:center;margin:0 0 12px}.check input{width:auto;margin:0}.footer{margin-top:22px;color:var(--muted);font-size:11px;text-align:center}
@media(max-width:800px){.s8,.s6,.s4{grid-column:span 12}.stats{gap:8px}.stat{font-size:22px}.card{padding:14px}.wrap{padding:16px 11px 40px}h1{font-size:23px}.field-row.three{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:440px){.stats{grid-template-columns:1fr}.field-row,.field-row.three{grid-template-columns:1fr}}
.card:has(input[name="first_name"]) form > div input {
    width: 100%;
    max-width: 100%;
    display: block;
    box-sizing: border-box;
}

.card:has(input[name="first_name"]) form {
    width: 100%;
}

.card:has(input[name="first_name"]) {
    width: 100%;
    box-sizing: border-box;
}
</style>
</head>
<body><main class="wrap">
<header class="top"><div class="brand"><div class="logo">P</div><div><h1>Xitercsx CPANEL</h1><div class="muted">Pterodactyl 1.15.2 · Application API + Client API</div></div></div>
<?php if ($isLoggedIn): ?><div class="row"><span class="pill"><?=e($panelHost ?: 'Panel belum valid')?></span><a class="btn secondary" href="?logout=1">Keluar</a></div><?php endif; ?></header>
<?php if ($notice): ?><div class="notice <?=e($notice['type'] ?? '')?>"><?=e($notice['message'] ?? '')?></div><?php endif; ?>

<?php if (!$isLoggedIn): ?>
<section class="card login"><h2>Masuk CPANEL</h2><p class="muted">Gunakan password admin portal yang dikonfigurasi di VPS. Ini bukan password akun Pterodactyl.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="login">
<label>Password admin</label><input type="password" name="password" autocomplete="current-password" required autofocus>
<button type="submit">Masuk dashboard <span>→</span></button></form>
<div class="help">Digunakan untuk cpanel dan cadmin pterodactyl</div>
</section>
<?php else: ?>
<?php if ($errors): ?><div class="notice error"><strong>Periksa koneksi API</strong><ul><?php foreach($errors as $error): ?><li><?=e($error)?></li><?php endforeach; ?></ul><span class="muted">Pastikan URL panel dan izin API benar. Respons error tidak menampilkan API key.</span></div><?php endif; ?>

<div class="stats">
<div class="card"><span class="stat-icon">♙</span><div class="stat"><?=count($users)?></div><div class="stat-label">User dalam halaman hasil</div></div>
<div class="card"><span class="stat-icon">▣</span><div class="stat"><?=count($servers)?></div><div class="stat-label">Server dalam halaman hasil</div></div>
<div class="card"><span class="stat-icon">⌘</span><div class="stat"><?=count($nodes)?></div><div class="stat-label">Node ditemukan</div></div>
</div>

<div class="grid">
<section class="card s6"><div class="section-head"><h2>Tambah user</h2><span class="pill">PTLA</span></div>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_user">
<label>Email</label><input type="email" name="email" required>
<label>Username</label><input name="username" maxlength="191" pattern="[A-Za-z0-9_.-]+" required>
<div class="field-row"><div><label>Nama depan</label><input name="first_name" required></div><div><label>Nama belakang</label><input name="last_name" required></div></div>
<label>Password awal (minimal 12 karakter)</label><input type="password" name="user_password" minlength="12" autocomplete="new-password" required>
<button type="submit">＋ Buat user</button></form></section>
<section class="card s6">
    <h2>Pilih Panel</h2>

    <form method="post">
        <input type="hidden" name="csrf"
               value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action"
               value="select_panel">

        <select name="panel" required>
            <option value="panel1"
                <?= $selectedPanel === 'panel1' ? 'selected' : '' ?>>
                Panel XITERCSX
            </option>

            <option value="panel2"
                <?= $selectedPanel === 'panel2' ? 'selected' : '' ?>>
                Panel XECKO
            </option>
        </select>

        <button type="submit">Pilih Panel</button>
    </form>
</section>
<section class="card s12">
    <h2>Create Admin</h2>

    <form method="post"
          onsubmit="return confirm('Yakin ingin membuat akun administrator baru?');">

        <input type="hidden" name="csrf"
               value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_admin">

        <div>
            <label>Username</label>
            <input type="text" name="username"
                   required autocomplete="username">
        </div>

        <div>
            <label>Email</label>
            <input type="email" name="email"
                   required autocomplete="email">
        </div>

        <div>
            <label>Nama Depan</label>
            <input type="text" name="first_name" required>
        </div>

        <div>
            <label>Nama Belakang</label>
            <input type="text" name="last_name" required>
        </div>

        <div>
            <label>Password</label>
            <input type="password" name="password"
                   minlength="12" required
                   autocomplete="new-password">
        </div>

        <button type="submit">Buat Admin</button>
    </form>
</section>
<section class="card s6"><div class="section-head"><h2>Client API</h2><span class="pill">PTLC</span></div>
<p class="muted">Key ini hanya disimpan di sesi login PHP dan tidak ditulis ke file konfigurasi. Server yang tampil bergantung pada akses key.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_ptlc">
<label>Client API key</label><input type="password" name="ptlc_key" placeholder="ptlc_..." autocomplete="off">
<div class="row"><button type="submit">Simpan key</button><button class="ghost" type="submit" name="ptlc_key" value="">Hapus key sesi</button></div></form>
<div class="help">Gunakan Client API key milik akun yang berhak mengakses server. Jangan masukkan Application API key pada bagian ini.</div></section>

<section class="card s12"><div class="section-head"><h2>Buat server</h2><span class="pill">PTLA · Application API</span></div>
<p class="muted">Data node dan egg diambil dari panel. Pilih node/egg, lalu masukkan Allocation ID yang tersedia untuk node tersebut dari panel admin. Form ini tidak menebak port/alokasi.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_server">
<div class="field-row three">
<div><label>Nama server</label><input name="server_name" required placeholder="Minecraft Survival"></div>
<div><label>User ID (owner)</label><input type="number" name="user_id" min="1" required placeholder="ID dari daftar user"></div>
<p class="muted">
Allocation kosong dipilih secara acak dari Node ID 2.
Pastikan Egg, Docker image, dan environment sesuai dengan server yang dibuat.
</p>
</div>
<div class="field-row three">
<div><label>Node ID</label><select name="node_display" id="node_display" onchange="document.getElementById('node_help').textContent='Node dipilih: '+this.value+'. Gunakan Egg ID dari node ini.'"><option value="">Pilih node dari daftar di bawah</option><?php foreach($nodes as $n): $a=attr($n); ?><option value="<?=e($a['id']??'')?>"><?=e(($a['name']??'Node').' · ID '.($a['id']??''))?></option><?php endforeach; ?></select><div class="muted">Node ID tidak diperlukan langsung oleh endpoint create jika Allocation ID valid, tetapi dipakai untuk memilih egg yang benar.</div></div>
<div><label>Egg ID</label><input type="number" name="egg_id" min="1" required placeholder="ID egg"></div>
<div><label>Docker image</label><input name="docker_image" required value="ghcr.io/parkervcp/yolks:nodejs_24"></div>
</div>
<div id="node_help" class="help">Daftar node tersedia:</div>
<div class="field-row three">
<div><label>RAM (MiB)</label><input type="number" name="memory" min="0" value="2048" required></div>
<div><label>Disk (MiB)</label><input type="number" name="disk" min="0" value="10240" required></div>
<div><label>CPU (%) · 0 sesuai aturan panel</label><input type="number" name="cpu" min="0" value="200" required></div>
<div><label>Swap (MiB)</label><input type="number" name="swap" min="0" value="0"></div>
<div><label>IO weight (10–1000)</label><input type="number" name="io" min="10" max="1000" value="500"></div>
<div><label>Startup override (optional)</label><input name="startup" placeholder="Kosong = startup egg default"></div>
</div>
<div class="field-row">
<div><label>Environment JSON (sesuai egg)</label><textarea name="environment" rows="8">{
  "GIT_ADDRESS": "",
  "BRANCH": "main",
  "USERNAME": "",
  "ACCESS_TOKEN": "",
  "AUTO_UPDATE": "0",
  "NODE_PACKAGES": "",
  "UNNODE_PACKAGES": "",
  "CMD_RUN": "npm start"
}</textarea><div class="muted">Contoh untuk sebagian egg Minecraft Java. Hapus/ubah variabel agar cocok dengan egg pilihanmu.</div></div>
<div><label>Feature limits JSON</label><textarea name="feature_limits" required>{"databases":1,"backups":1,"allocations":1}</textarea><label class="check"><input type="checkbox" name="start_on_completion" value="1" checked> Jalankan server setelah selesai dibuat</label></div>
</div>
<div class="row"><button type="submit" onclick="return confirm('Buat server sekarang? Pastikan owner, egg, allocation, environment, dan limit benar.')">＋ Buat server</button></div>
</form>
</section>
<section class="card s12">
    <h2>Hapus User</h2>
    <form method="post"
          onsubmit="return confirm('Yakin ingin menghapus user ini?');">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="delete_user">

        <label>User ID</label>
        <input type="number" name="user_id" min="1" required>

        <button type="submit">Hapus User</button>
    </form>
</section>
<section class="card s12"><div class="section-head"><h2>Users</h2><span class="pill">PTLA</span></div><div class="table-wrap"><table><thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Nama</th><th>Admin</th></tr></thead><tbody>
<?php foreach($users as $row): $a=attr($row); ?><tr><td><?=e($a['id']??'')?></td><td><?=e($a['username']??'')?></td><td><?=e($a['email']??'')?></td><td><?=e(trim(($a['first_name']??'').' '.($a['last_name']??'')))?></td><td><?=!empty($a['root_admin'])?'Ya':'Tidak'?></td></tr><?php endforeach; ?>
<?php if (!$users): ?><tr><td colspan="5" class="empty">Belum ada data user atau API tidak mengembalikan hasil.</td></tr><?php endif; ?></tbody></table></div></section>

<section class="card s12"><div class="section-head"><h2>Servers</h2><span class="pill">PTLA</span></div><div class="table-wrap"><table><thead><tr><th>ID</th><th>Nama</th><th>Identifier</th><th>Node</th><th>Owner</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($servers as $row): $a=attr($row); ?><tr><td><?=e($a['id']??'')?></td><td><?=e($a['name']??'')?></td><td><?=e($a['identifier']??'')?></td><td><?=e($a['node']??'')?></td><td><?=e($a['user']??'')?></td><td><form class="smallform" method="post" onsubmit="return confirm('Hapus server ini? Data server dapat hilang permanen.')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_server"><input type="hidden" name="server_id" value="<?=e($a['id']??'')?>"><button class="danger" type="submit">Hapus</button></form></td></tr><?php endforeach; ?>
<?php if (!$servers): ?><tr><td colspan="6" class="empty">Belum ada server atau API tidak mengembalikan hasil.</td></tr><?php endif; ?></tbody></table></div></section>

<section class="card s12"><div class="section-head"><h2>Server yang bisa diakses Client API</h2><span class="pill">PTLC</span></div><div class="table-wrap"><table><thead><tr><th>Nama</th><th>Identifier</th><th>Node</th><th>Status</th><th>Kontrol daya</th></tr></thead><tbody>
<?php foreach($clientServers as $row): $a=attr($row); ?><tr><td><?=e($a['name']??'')?></td><td><?=e($a['identifier']??'')?></td><td><?=e($a['node']??'')?></td><td><?=e($a['status']??'—')?></td><td><div class="row"><?php foreach(['start','restart','stop'] as $signal): ?><form class="smallform" method="post" onsubmit="return confirm('Kirim perintah <?=e($signal)?>?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="client_power"><input type="hidden" name="server_identifier" value="<?=e($a['identifier']??'')?>"><input type="hidden" name="signal" value="<?=e($signal)?>"><button class="secondary" type="submit"><?=e(ucfirst($signal))?></button></form><?php endforeach; ?></div></td></tr><?php endforeach; ?>
<?php if (!$clientServers): ?><tr><td colspan="5" class="empty">Masukkan Client API key untuk memuat server milik akun tersebut.</td></tr><?php endif; ?></tbody></table></div></section>
</div>
<div class="footer">Ptero Portal · Jangan bagikan key API · Gunakan HTTPS · Hapus akses yang tidak diperlukan</div>
<?php endif; ?>
</main></body></html>
