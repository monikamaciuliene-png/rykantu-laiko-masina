<?php
// Pasidalink: receives one contribution, keeps it outside public_html and emails it.
// The same file is deployed in every place folder (rykantai, hales-turgus); the folder name says which place.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const TO       = 'info@participio.lt';
const FROM     = 'noreply@laikomasinos.lt';
const MAX_FILE = 10 * 1024 * 1024;   // 10 MB
const MAX_MAIL_ATTACH = 8 * 1024 * 1024;
const PLACES   = ['rykantai' => 'Rykantai', 'hales-turgus' => 'Hales turgus'];   // ASCII: used in email headers
const KINDS    = ['nuotrauka' => 'Nuotrauka', 'daiktas' => 'Daiktas', 'prisiminimas' => 'Prisiminimas', 'kita' => 'Kita'];
const TYPES    = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/heic' => 'heic', 'image/heif' => 'heic', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

function done(int $code, string $err = ''): void {
  http_response_code($code);
  echo json_encode($err === '' ? ['ok' => true] : ['ok' => false, 'error' => $err]);
  exit;
}
function field(string $k, int $max): string {
  $v = trim((string)($_POST[$k] ?? ''));
  $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
  return mb_substr($v, 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') done(405, 'method');
$placeKey = basename(__DIR__);
if (!isset(PLACES[$placeKey])) done(500, 'place');
$place = PLACES[$placeKey];

// Spam: hidden field must stay empty, and a person needs a few seconds to fill the form.
if (field('svetaine', 200) !== '') done(200);
$t = (int)($_POST['t'] ?? 0);
if ($t <= 0 || time() - intdiv($t, 1000) < 4) done(400, 'fast');

$kind    = field('kind', 20);
$story   = field('story', 5000);
$name    = field('name', 120);
$contact = field('contact', 200);
$agree   = ($_POST['agree'] ?? '') === '1';
$byName  = ($_POST['byname'] ?? '') === '1';
if (!isset(KINDS[$kind]) || mb_strlen($story) < 3 || $contact === '' || !$agree) done(400, 'fields');

// Storage: next to public_html, never inside it.
$root = dirname(__DIR__, 2) . '/pasidalink-gauta';
if (!is_dir($root) && !mkdir($root, 0700, true)) done(500, 'store');

// Rate limit: 8 per hour per address.
$ipKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $placeKey);
$rl = "$root/.limit-$ipKey";
$hits = array_filter(is_file($rl) ? array_map('intval', file($rl)) : [], fn($h) => $h > time() - 3600);
if (count($hits) >= 8) done(429, 'limit');
$hits[] = time();
file_put_contents($rl, implode("\n", $hits));

$id  = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
$dir = "$root/$placeKey/$id";
if (!mkdir($dir, 0700, true)) done(500, 'store');

$file = null;
if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
  $f = $_FILES['file'];
  if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > MAX_FILE) done(400, 'file');
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
  if (!isset(TYPES[$mime])) done(400, 'type');
  $file = ['name' => 'failas.' . TYPES[$mime], 'mime' => $mime, 'size' => (int)$f['size'],
           'original' => mb_substr(basename((string)$f['name']), 0, 120)];
  if (!move_uploaded_file($f['tmp_name'], "$dir/{$file['name']}")) done(500, 'store');
}

$rec = ['id' => $id, 'place' => $place, 'received' => date('c'), 'kind' => KINDS[$kind], 'story' => $story,
        'name' => $name, 'contact' => $contact, 'agree_processing' => $agree, 'publish_with_name' => $byName, 'file' => $file];
file_put_contents("$dir/irasas.json", json_encode($rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Email (the saved copy above is the safety net if mail fails).
$size = $file ? ($file['size'] < 1048576 ? max(1, round($file['size'] / 1024)) . " KB" : round($file['size'] / 1048576, 1) . " MB") : '';
// Email labels are plain ASCII; only the text people typed keeps its letters.
$body = "Naujas irasas: $place\n\n"
      . "Kas: " . KINDS[$kind] . "\n"
      . "Pasakojimas:\n$story\n\n"
      . "Vardas: " . ($name ?: '-') . "\n"
      . "Kontaktas: $contact\n"
      . "Galima skelbti su vardu: " . ($byName ? 'taip' : 'ne') . "\n"
      . "Failas: " . ($file ? "{$file['original']} ($size)" : '-') . "\n\n"
      . "Serveryje: pasidalink-gauta/$placeKey/$id\n";
$subject = "Pasidalink: $place, " . KINDS[$kind];
$headers = "From: Laiko masinos <" . FROM . ">\r\nMIME-Version: 1.0\r\n";
if (filter_var($contact, FILTER_VALIDATE_EMAIL)) $headers .= "Reply-To: $contact\r\n";
if ($file && $file['size'] <= MAX_MAIL_ATTACH) {
  $b = 'b' . bin2hex(random_bytes(8));
  $headers .= "Content-Type: multipart/mixed; boundary=\"$b\"";
  $msg = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body))
       . "--$b\r\nContent-Type: {$file['mime']}; name=\"{$file['name']}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$file['name']}\"\r\n\r\n"
       . chunk_split(base64_encode((string)file_get_contents("$dir/{$file['name']}"))) . "--$b--";
} else {
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
  $msg = chunk_split(base64_encode($body));
}
@mail(TO, $subject, $msg, $headers, '-f' . FROM);
done(200);
