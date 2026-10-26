<?php
declare(strict_types=1);

/*
 * Rusher Cloud Uploader
 * Uploads each selected file to https://tmpfile.link/api/upload.
 * Configure upload limits in your PHP hosting settings if needed.
 */

const RUSHER_CLOUD_API_URL = 'https://tmpfile.link/api/upload';
const MAX_FILES_PER_REQUEST = 20;
const MAX_FILE_SIZE = 100 * 1024 * 1024; // 100 MB per file, per service limits

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function uploadOneFile(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'File melebihi batas upload PHP di server.',
            UPLOAD_ERR_FORM_SIZE => 'File melebihi batas ukuran form.',
            UPLOAD_ERR_PARTIAL => 'File hanya ter-upload sebagian.',
            UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diterima.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary PHP tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file sementara.',
            UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP.',
        ];
        return ['success' => false, 'fileName' => $file['name'] ?? 'unknown', 'error' => $messages[$code] ?? 'Upload gagal.'];
    }

    $tmpPath = $file['tmp_name'] ?? '';
    $fileName = basename((string)($file['name'] ?? 'file'));
    $fileSize = (int)($file['size'] ?? 0);

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['success' => false, 'fileName' => $fileName, 'error' => 'File upload tidak valid.'];
    }
    if ($fileSize <= 0) {
        return ['success' => false, 'fileName' => $fileName, 'error' => 'File kosong atau ukurannya tidak valid.'];
    }
    if ($fileSize > MAX_FILE_SIZE) {
        return ['success' => false, 'fileName' => $fileName, 'error' => 'Ukuran file melebihi batas 100 MB.'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'fileName' => $fileName, 'error' => 'Ekstensi PHP cURL belum aktif di hosting.'];
    }

    $mimeType = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $tmpPath);
            if (is_string($detected) && $detected !== '') $mimeType = $detected;
            finfo_close($finfo);
        }
    }

    $curlFile = new CURLFile($tmpPath, $mimeType, $fileName);
    $ch = curl_init(RUSHER_CLOUD_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => $curlFile],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        return ['success' => false, 'fileName' => $fileName, 'error' => 'Gagal menghubungi Rusher Cloud: ' . ($curlError ?: 'network error')];
    }

    $payload = json_decode($responseBody, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($payload)) {
        $message = is_array($payload) ? ($payload['error'] ?? $payload['message'] ?? '') : '';
        return [
            'success' => false,
            'fileName' => $fileName,
            'error' => $message !== '' ? (string)$message : "Rusher Cloud mengembalikan HTTP {$httpCode}.",
        ];
    }

    $url = $payload['downloadLink'] ?? $payload['downloadLinkEncoded'] ?? null;
    if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'fileName' => $fileName, 'error' => 'Respons API tidak berisi URL download yang valid.'];
    }

    return [
        'success' => true,
        'fileName' => (string)($payload['fileName'] ?? $fileName),
        'downloadLink' => $url,
        'size' => (int)($payload['size'] ?? $fileSize),
        'type' => (string)($payload['type'] ?? $mimeType),
        'uploadedTo' => (string)($payload['uploadedTo'] ?? 'public'),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'upload') {
    // PHP may discard the entire request when post_max_size is exceeded.
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = ini_get('post_max_size');
    $postMaxBytes = 0;
    if (is_string($postMax) && preg_match('/^\\s*(\\d+(?:\\.\\d+)?)\\s*([KMG]?)\\s*$/i', $postMax, $m)) {
        $postMaxBytes = (int)((float)$m[1] * match (strtoupper($m[2])) {
            'G' => 1024 * 1024 * 1024,
            'M' => 1024 * 1024,
            'K' => 1024,
            default => 1,
        });
    }
    if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes && empty($_POST) && empty($_FILES)) {
        jsonResponse(['success' => false, 'error' => 'Request ditolak oleh batas post_max_size PHP (' . $postMax . '). Jalankan server dengan post_max_size=110M.'], 413);
    }
    if (!isset($_FILES['files'])) {
        jsonResponse(['success' => false, 'error' => 'Pilih minimal satu file terlebih dahulu.'], 400);
    }

    $incoming = $_FILES['files'];
    if (!is_array($incoming['name'] ?? null)) {
        jsonResponse(['success' => false, 'error' => 'Format file tidak valid.'], 400);
    }

    $count = count($incoming['name']);
    if ($count < 1) jsonResponse(['success' => false, 'error' => 'Pilih minimal satu file terlebih dahulu.'], 400);
    if ($count > MAX_FILES_PER_REQUEST) {
        jsonResponse(['success' => false, 'error' => 'Maksimal ' . MAX_FILES_PER_REQUEST . ' file per batch.'], 400);
    }

    $results = [];
    foreach ($incoming['name'] as $i => $name) {
        $file = [
            'name' => $incoming['name'][$i] ?? 'file',
            'type' => $incoming['type'][$i] ?? '',
            'tmp_name' => $incoming['tmp_name'][$i] ?? '',
            'error' => $incoming['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $incoming['size'][$i] ?? 0,
        ];
        $results[] = uploadOneFile($file);
    }

    $successCount = count(array_filter($results, fn($item) => !empty($item['success'])));
    jsonResponse([
        'success' => $successCount > 0,
        'total' => count($results),
        'uploaded' => $successCount,
        'failed' => count($results) - $successCount,
        'results' => $results,
    ]);
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b1020">
<title>Rusher Cloud Uploader</title>
<style>
:root{color-scheme:dark;--bg:#080d19;--panel:#10182a;--line:#24314a;--muted:#9aa9c2;--text:#eef4ff;--accent:#7c9cff;--good:#4adea0;--bad:#fb7185}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at top,#172440 0%,var(--bg) 58%);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--text);min-height:100vh}
main{width:min(920px,calc(100% - 32px));margin:54px auto}.brand{display:flex;align-items:center;gap:12px;margin-bottom:26px}.logo{display:grid;place-items:center;width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#7c9cff,#9e7cff);font-size:22px;color:#08101f;font-weight:900}.eyebrow{color:#a7baff;font-size:12px;letter-spacing:.14em;text-transform:uppercase;font-weight:800}.brand h1{font-size:clamp(26px,5vw,38px);letter-spacing:-.04em;margin:3px 0 0}.intro{color:var(--muted);margin:0 0 24px;max-width:650px}
.panel{background:rgba(16,24,42,.92);border:1px solid var(--line);border-radius:22px;padding:24px;box-shadow:0 24px 70px #0004}
.drop{border:1.5px dashed #42557b;border-radius:17px;padding:34px 18px;text-align:center;background:#0b1324;transition:.2s;cursor:pointer}.drop.drag{border-color:var(--accent);background:#172342}.drop .symbol{font-size:32px}.drop h2{font-size:18px;margin:8px 0 4px}.drop p{margin:0;color:var(--muted);font-size:13px}.drop input{display:none}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:17px}.btn{border:1px solid #354664;border-radius:11px;background:#18243a;color:var(--text);padding:11px 16px;font-weight:700;cursor:pointer}.btn.primary{background:var(--accent);border-color:var(--accent);color:#08101f}.btn:disabled{opacity:.45;cursor:not-allowed}.btn:hover:not(:disabled){filter:brightness(1.1)}
.filehead{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:24px 0 10px}.filehead h3{margin:0;font-size:15px}.muted{color:var(--muted);font-size:13px}.files{display:grid;gap:9px}.file{display:grid;grid-template-columns:40px minmax(0,1fr) auto;gap:12px;align-items:center;border:1px solid var(--line);border-radius:13px;padding:12px;background:#0c1425}.fileicon{width:40px;height:40px;display:grid;place-items:center;border-radius:10px;background:#1a2945;font-size:19px}.filename{font-weight:700;overflow-wrap:anywhere}.filesub{color:var(--muted);font-size:12px}.status{font-size:12px;font-weight:800;color:var(--muted)}.status.ok{color:var(--good)}.status.fail{color:var(--bad)}.linkrow{display:flex;gap:8px;margin-top:8px}.linkrow input{min-width:0;flex:1;background:#080e1b;color:#cfe0ff;border:1px solid #2a3a56;border-radius:8px;padding:8px;font-size:12px}.smallbtn{padding:7px 10px;font-size:12px}.progress{height:4px;background:#26334b;border-radius:9px;overflow:hidden;margin-top:8px}.progress span{display:block;height:100%;width:0;background:var(--accent);transition:width .2s}.notice{color:var(--muted);font-size:12px;margin-top:18px}.summary{margin-top:15px;color:var(--muted);font-size:13px}.error{color:var(--bad);font-size:12px;margin-top:5px;overflow-wrap:anywhere}.hidden{display:none!important}footer{text-align:center;color:#6f7f9b;font-size:12px;margin:20px 0}
@media(max-width:560px){main{margin:28px auto}.panel{padding:15px}.drop{padding:28px 12px}.file{grid-template-columns:34px minmax(0,1fr)}.fileicon{width:34px;height:34px}.status{grid-column:2}.filehead{align-items:flex-start}}
</style>
</head>
<body>
<main>
  <header class="brand"><div class="logo">↗</div><div><div class="eyebrow">Temporary file uploader</div><h1>Rusher Cloud Uploader</h1></div></header>
  <p class="intro">Upload banyak file sekaligus dan dapatkan URL download dari Rusher Cloud. Setiap file diproses secara terpisah agar hasil dan error-nya mudah dilacak.</p>
  <section class="panel">
    <div class="drop" id="drop">
      <div class="symbol">☁️</div>
      <h2>Drop file di sini</h2>
      <p>Pilih atau drop file untuk langsung memulai upload · maksimal 20 file per pemilihan · 100 MB per file</p>
      <input id="picker" type="file" multiple>
    </div>
    <div class="actions">
      <button class="btn primary" id="choose">Pilih file</button>
      <button class="btn" id="clear">Bersihkan</button>
    </div>
    <div class="filehead"><h3>Daftar file</h3><span class="muted" id="count">0 file dipilih</span></div>
    <div class="files" id="files"></div>
    <div class="summary" id="summary" aria-live="polite"></div>
    <p class="notice">File upload anonim dapat dihapus otomatis setelah 7 hari. Jangan unggah file rahasia jika URL-nya bisa dibagikan ke orang lain.</p>
  </section>
  <footer>Powered by Rusher Cloud · PHP + cURL</footer>
</main>
<script>
(() => {
  const picker = document.getElementById('picker');
  const drop = document.getElementById('drop');
  const filesEl = document.getElementById('files');
  const chooseBtn = document.getElementById('choose');
  const clearBtn = document.getElementById('clear');
  const countEl = document.getElementById('count');
  const summary = document.getElementById('summary');
  let selected = [];
  let processing = false;

  const formatSize = n => n < 1024 ? `${n} B` : n < 1048576 ? `${(n/1024).toFixed(1)} KB` : `${(n/1048576).toFixed(2)} MB`;
  const iconFor = name => {
    const ext = (name.split('.').pop() || '').toLowerCase();
    if (['png','jpg','jpeg','gif','webp','svg','bmp'].includes(ext)) return '🖼️';
    if (['mp4','mov','mkv','webm','avi'].includes(ext)) return '🎬';
    if (['mp3','wav','ogg','flac','m4a'].includes(ext)) return '🎵';
    if (['zip','rar','7z','tar','gz'].includes(ext)) return '📦';
    if (['pdf','doc','docx','txt','rtf'].includes(ext)) return '📄';
    return '📁';
  };
  const escapeHtml = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function render() {
    countEl.textContent = `${selected.length} file dipilih`;
    filesEl.innerHTML = '';
    selected.forEach((item, i) => {
      const el = document.createElement('div');
      el.className = 'file';
      let result = '';
      if (item.url) result = `<div class="linkrow"><input readonly value="${escapeHtml(item.url)}" aria-label="URL ${escapeHtml(item.file.name)}"><button class="btn smallbtn copyone" data-i="${i}">Copy</button></div>`;
      el.innerHTML = `<div class="fileicon">${iconFor(item.file.name)}</div><div><div class="filename">${escapeHtml(item.file.name)}</div><div class="filesub">${formatSize(item.file.size)} · ${escapeHtml(item.file.type || 'application/octet-stream (tipe terdeteksi di server)')}</div>${result}<div class="progress ${item.progress >= 100 || item.status === 'error' ? 'hidden' : ''}"><span style="width:${item.progress || 0}%"></span></div>${item.error ? `<div class="error">${escapeHtml(item.error)}</div>` : ''}</div><div class="status ${item.status === 'done' ? 'ok' : item.status === 'error' ? 'fail' : ''}">${item.status === 'done' ? 'Berhasil' : item.status === 'error' ? 'Gagal' : item.status === 'uploading' ? 'Uploading…' : item.status === 'queued' ? 'Menunggu' : 'Siap'}</div>`;
      filesEl.appendChild(el);
    });
    // Keep file selection available during uploads so newly selected files
    // are appended to the queue and processed automatically.
    chooseBtn.disabled = false;
    clearBtn.disabled = processing;
    document.querySelectorAll('.copyone').forEach(btn => btn.addEventListener('click', async () => {
      const item = selected[Number(btn.dataset.i)];
      if (item?.url) await copyText(item.url, btn);
    }));
  }

  function addFiles(list) {
    const incoming = Array.from(list || []);
    if (incoming.length === 0) return;
    // Each selection/drop can contain up to 20 files. More selections can be
    // added later, including while earlier files are still uploading.
    const accepted = incoming.slice(0, 20);
    accepted.forEach(file => selected.push({file, status:'queued', progress:0, url:'', error:''}));
    if (incoming.length > 20) summary.textContent = 'Maksimal 20 file untuk sekali pemilihan. 20 file pertama masuk antrean.';
    else summary.textContent = ` ${accepted.length} file ditambahkan. Upload otomatis dimulai…`;
    render();
    void processQueue();
  }

  async function copyText(value, button) {
    try {
      await navigator.clipboard.writeText(value);
      const old = button.textContent;
      button.textContent = 'Tersalin!';
      setTimeout(() => { button.textContent = old; }, 1200);
    } catch {
      const temp = document.createElement('textarea');
      temp.value = value; document.body.appendChild(temp); temp.select();
      document.execCommand('copy'); temp.remove();
      button.textContent = 'Tersalin!';
      setTimeout(() => { button.textContent = 'Copy'; }, 1200);
    }
  }

  async function uploadOne(item, index) {
    item.status = 'uploading'; item.progress = 20; item.error = ''; render();
    try {
      // One file per request: upload API accepts a single `file` field.
      const single = new FormData();
      single.append('files[]', item.file, item.file.name);
      const response = await fetch('?action=upload', {method:'POST', body:single});
      item.progress = 80; render();
      const responseText = await response.text();
      let data;
      try {
        data = JSON.parse(responseText);
      } catch {
        const detail = responseText.trim().slice(0, 180).replace(/\s+/g, ' ');
        throw new Error(`Server tidak mengembalikan JSON (HTTP ${response.status}). ${detail || 'Periksa terminal PHP dan konfigurasi upload.'}`);
      }
      const result = data.results?.[0];
      if (!response.ok || !result || !result.success) throw new Error(result?.error || data.error || `HTTP ${response.status}`);
      item.url = result.downloadLink;
      item.status = 'done'; item.progress = 100;
    } catch (e) {
      item.status = 'error'; item.progress = 0; item.error = e.message || 'Upload gagal.';
    }
    render();
  }

  async function processQueue() {
    // A single worker consumes the queue sequentially. If more files are
    // selected while a request is in flight, they will be picked up by this loop.
    if (processing) return;
    processing = true;
    summary.textContent = 'Upload otomatis berjalan. File tambahan yang dipilih akan masuk antrean.';
    render();

    while (true) {
      const next = selected.find(item => item.status === 'queued');
      if (!next) break;
      await uploadOne(next);
    }

    processing = false;
    const ok = selected.filter(x => x.status === 'done').length;
    const failed = selected.filter(x => x.status === 'error').length;
    const queued = selected.filter(x => x.status === 'queued').length;
    if (queued > 0) {
      summary.textContent = `${ok} berhasil, ${failed} gagal, ${queued} menunggu upload.`;
    } else {
      summary.textContent = `Selesai: ${ok} berhasil${failed ? `, ${failed} gagal` : ''}.`;
    }
    render();
  }

  chooseBtn.addEventListener('click', () => picker.click());
  drop.addEventListener('click', e => { if (e.target !== picker) picker.click(); });
  picker.addEventListener('change', () => { addFiles(picker.files); picker.value = ''; });
  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
  drop.addEventListener('drop', e => addFiles(e.dataTransfer.files));
  clearBtn.addEventListener('click', () => { if (!processing) { selected = []; summary.textContent = ''; render(); } });
  render();
})();
</script>
</body>
</html>
