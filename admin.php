<?php
session_start();
$password = ''; // парроооооль
$authError = '';
$notice = '';
$errorMsg = '';

if (isset($_POST['pass'])) {
    if ($_POST['pass'] === $password) {
        $_SESSION['auth'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $authError = 'Неверный пароль';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (empty($_SESSION['auth'])) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Вход в админку — Радио 24/7</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Inter', -apple-system, sans-serif;
                background: radial-gradient(circle at top, #1e293b, #090d16 70%);
                color: #f8fafc;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .login-card {
                width: 100%;
                max-width: 360px;
                background: rgba(15, 23, 42, 0.75);
                backdrop-filter: blur(20px);
                border: 1px solid rgba(255, 255, 255, 0.12);
                border-radius: 24px;
                padding: 32px;
                box-shadow: 0 25px 50px rgba(0, 0, 0, 0.55);
                text-align: center;
            }
            h2 { font-size: 20px; margin-bottom: 8px; }
            p { font-size: 13px; color: #94a3b8; margin-bottom: 22px; }
            input[type="password"] {
                width: 100%;
                padding: 13px 15px;
                border-radius: 12px;
                border: 1px solid rgba(255, 255, 255, 0.14);
                background: rgba(255, 255, 255, 0.06);
                color: #fff;
                font-size: 14px;
                outline: none;
                margin-bottom: 14px;
            }
            input[type="password"]:focus { border-color: #38bdf8; }
            button {
                width: 100%;
                padding: 13px;
                border: none;
                border-radius: 12px;
                background: #ffffff;
                color: #0f172a;
                font-weight: 700;
                font-size: 14px;
                cursor: pointer;
                transition: 0.2s;
            }
            button:hover { background: #e2e8f0; }
            .error { color: #fca5a5; font-size: 13px; margin-bottom: 12px; }
            .back-link { display: inline-block; margin-top: 18px; color: #94a3b8; font-size: 12px; text-decoration: none; }
            .back-link:hover { color: #fff; }
        </style>
    </head>
    <body>
        <form method="post" class="login-card">
            <h2>Панель управления</h2>
            <p>Введите пароль для доступа к эфиру</p>
            <?php if ($authError): ?><div class="error"><?= htmlspecialchars($authError) ?></div><?php endif; ?>
            <input type="password" name="pass" placeholder="Пароль" required autofocus>
            <button type="submit">Войти</button>
            <a href="index.php" class="back-link">← Вернуться на страницу радио</a>
        </form>
    </body>
    </html>
    <?php
    exit;
}

$tracksDir = __DIR__ . DIRECTORY_SEPARATOR . 'tracks' . DIRECTORY_SEPARATOR;
if (!is_dir($tracksDir)) {
    mkdir($tracksDir, 0755, true);
}

$jsonFile = __DIR__ . DIRECTORY_SEPARATOR . 'playlist.json';
$playlist = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];
if (!is_array($playlist)) $playlist = [];

function canRunShell(): bool {
    if (!function_exists('exec')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

function findYtDlpBinary(): ?string {
    $localWin = __DIR__ . DIRECTORY_SEPARATOR . 'yt-dlp.exe';
    if (file_exists($localWin)) {
        return $localWin;
    }
    $localNix = __DIR__ . DIRECTORY_SEPARATOR . 'yt-dlp';
    if (file_exists($localNix) && is_executable($localNix)) {
        return $localNix;
    }

    $candidates = ['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'];
    foreach ($candidates as $bin) {
        if (is_executable($bin)) {
            return $bin;
        }
    }

    $checkCmd = (PHP_OS_FAMILY === 'Windows') ? 'where yt-dlp 2>NUL' : 'which yt-dlp 2>/dev/null';
    $out = [];
    $code = 1;
    @exec($checkCmd, $out, $code);
    if ($code === 0 && !empty($out[0])) {
        return trim($out[0]);
    }

    return null;
}

function parseJsonStreamFromOutput(array $outputLines): array {
    $joined = implode("\n", $outputLines);
    $metas = [];
    $len = strlen($joined);
    $depth = 0;
    $inString = false;
    $escape = false;
    $start = null;

    for ($i = 0; $i < $len; $i++) {
        $ch = $joined[$i];

        if ($inString) {
            if ($escape) {
                $escape = false;
            } elseif ($ch === '\\') {
                $escape = true;
            } elseif ($ch === '"') {
                $inString = false;
            }
            continue;
        }

        if ($ch === '"') {
            $inString = true;
            continue;
        }

        if ($ch === '{') {
            if ($depth === 0) {
                $start = $i;
            }
            $depth++;
        } elseif ($ch === '}') {
            if ($depth > 0) {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $chunk = substr($joined, $start, $i - $start + 1);
                    $decoded = json_decode($chunk, true);
                    if (is_array($decoded) && !empty($decoded['id'])) {
                        $metas[] = $decoded;
                    }
                    $start = null;
                }
            }
        }
    }

    return $metas;
}

// Импорт плейлиста или трека по ссылке через yt-dlp
if (isset($_POST['playlist_url'])) {
    $url = trim((string)$_POST['playlist_url']);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $errorMsg = 'Введите корректную ссылку на плейлист или трек.';
    } elseif (!canRunShell()) {
        $errorMsg = 'На этом сервере отключена функция exec().';
    } else {
        $ytDlp = findYtDlpBinary();
        if (!$ytDlp) {
            $errorMsg = 'Файл yt-dlp.exe не найден в корне проекта (' . __DIR__ . '). Положите yt-dlp.exe, ffmpeg.exe и ffprobe.exe в папку проекта.';
        } else {
            @set_time_limit(0);

            $ffmpegArg = '';
            if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'ffmpeg.exe') || file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'ffmpeg')) {
                $ffmpegArg = ' --ffmpeg-location ' . escapeshellarg(__DIR__);
            }

            $byFile = [];
            foreach ($playlist as $item) {
                if (!empty($item['file'])) {
                    $byFile[$item['file']] = $item;
                }
            }

            // Сначала получаем метаданные всех элементов плейлиста
            $metaCmd = escapeshellarg($ytDlp)
                . ' --dump-json --yes-playlist --ignore-errors '
                . escapeshellarg($url) . ' 2>&1';

            $metaLines = [];
            $metaCode = 1;
            @exec($metaCmd, $metaLines, $metaCode);
            $metas = parseJsonStreamFromOutput($metaLines);

            $importedCount = 0;

            if (!empty($metas)) {
                foreach ($metas as $meta) {
                    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$meta['id']);
                    if ($id === '') continue;

                    $entryUrl = (string)($meta['webpage_url'] ?? $meta['url'] ?? '');
                    if (!filter_var($entryUrl, FILTER_VALIDATE_URL)) {
                        continue;
                    }

                    // Удаляем старые временные файлы перед скачиванием трека
                    foreach (glob($tracksDir . 'import_tmp.*') ?: [] as $oldTmp) {
                        @unlink($oldTmp);
                    }

                    $dlCmd = escapeshellarg($ytDlp)
                        . $ffmpegArg
                        . ' -x --audio-format mp3 --audio-quality 0 --no-part --no-playlist'
                        . ' -o ' . escapeshellarg($tracksDir . 'import_tmp.%(ext)s')
                        . ' ' . escapeshellarg($entryUrl) . ' 2>&1';

                    $dlOut = [];
                    $dlCode = 1;
                    @exec($dlCmd, $dlOut, $dlCode);

                    $finalDiskFile = $tracksDir . $id . '.mp3';
                    $tmpMp3 = $tracksDir . 'import_tmp.mp3';

                    if (file_exists($tmpMp3)) {
                        if (file_exists($finalDiskFile)) {
                            @unlink($finalDiskFile);
                        }
                        @rename($tmpMp3, $finalDiskFile);
                    } else {
                        $anyTmp = glob($tracksDir . 'import_tmp.*') ?: [];
                        if (!empty($anyTmp)) {
                            $extFound = pathinfo($anyTmp[0], PATHINFO_EXTENSION);
                            $altTarget = $tracksDir . $id . ($extFound ? '.' . $extFound : '.mp3');
                            if (file_exists($altTarget)) {
                                @unlink($altTarget);
                            }
                            @rename($anyTmp[0], $altTarget);
                            $finalDiskFile = $altTarget;
                        }
                    }

                    if (!file_exists($finalDiskFile) || filesize($finalDiskFile) === 0) {
                        continue;
                    }

                    $relPath = 'tracks/' . basename($finalDiskFile);
                    $rawTitle = trim((string)($meta['track'] ?? $meta['title'] ?? $id));
                    $artist = trim((string)($meta['artist'] ?? $meta['uploader'] ?? ''));

                    if ($artist === '' && (strpos($rawTitle, ' - ') !== false || strpos($rawTitle, ' — ') !== false)) {
                        $sep = strpos($rawTitle, ' — ') !== false ? ' — ' : ' - ';
                        [$pArtist, $pTrack] = explode($sep, $rawTitle, 2);
                        $artist = trim($pArtist) ?: 'В эфире';
                        $trackName = trim($pTrack) ?: $rawTitle;
                    } else {
                        $artist = $artist ?: 'В эфире';
                        $trackName = $rawTitle;
                    }

                    $cover = (string)($meta['thumbnail'] ?? '');
                    if ($cover === '' && !empty($meta['thumbnails']) && is_array($meta['thumbnails'])) {
                        $lastThumb = end($meta['thumbnails']);
                        $cover = (string)($lastThumb['url'] ?? '');
                    }
                    if ($cover !== '') {
                        $cover = str_replace(['-large.', '-t500x500.'], '-t500x500.', $cover);
                    }

                    $byFile[$relPath] = [
                        'file' => $relPath,
                        'artist' => $artist,
                        'track' => $trackName,
                        'title' => $artist . ' — ' . $trackName,
                        'duration' => max(10, (int)round((float)($meta['duration'] ?? 180))),
                        'cover' => $cover,
                        'recognized' => true
                    ];
                    $importedCount++;
                }
            }

            $playlist = array_values($byFile);
            file_put_contents($jsonFile, json_encode($playlist, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            if ($importedCount > 0) {
                $notice = "Импортировано треков: {$importedCount}.";
            } else {
                $lastLog = substr(implode(' | ', $metaLines), -600);
                $errorMsg = 'Треки не сохранились в папку tracks/. Лог: ' . $lastLog;
            }
        }
    }
}

// Ручной запуск поиска обложек и названий через Apple / iTunes
if (isset($_GET['rescan'])) {
    if (isset($_GET['force'])) {
        @unlink($jsonFile);
        $playlist = [];
    }
    include 'scan.php';
    header('Location: admin.php?scanned=1');
    exit;
}

// Загрузка файлов с компьютера
if (!empty($_FILES['audio']['name'])) {
    $durations = $_POST['durations'] ?? [];
    $allowedUploadExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];

    foreach ($_FILES['audio']['tmp_name'] as $i => $tmpName) {
        if ($_FILES['audio']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $rawName = basename($_FILES['audio']['name'][$i]);
        $safeName = preg_replace('/[^\w\.\-\s\(\)—–а-яА-ЯёЁ]/u', '_', $rawName);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        $dur = floatval($durations[$i] ?? 0);

        if (in_array($ext, $allowedUploadExts, true) && $dur > 1) {
            $target = 'tracks/' . $safeName;
            $absTarget = $tracksDir . $safeName;
            if (move_uploaded_file($tmpName, $absTarget)) {
                $playlist = array_values(array_filter($playlist, fn($t) => ($t['file'] ?? '') !== $target));
                $baseTitle = pathinfo($safeName, PATHINFO_FILENAME);
                $playlist[] = [
                    'file' => $target,
                    'artist' => 'В эфире',
                    'track' => $baseTitle,
                    'title' => $baseTitle,
                    'duration' => $dur,
                    'cover' => '',
                    'recognized' => false
                ];
            }
        }
    }

    file_put_contents($jsonFile, json_encode(array_values($playlist), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    include 'scan.php';
    header('Location: admin.php?uploaded=1');
    exit;
}

// Удаление трека
if (isset($_GET['del'])) {
    $safeDel = basename($_GET['del']);
    $delRel = 'tracks/' . $safeDel;
    $delAbs = $tracksDir . $safeDel;
    if (file_exists($delAbs)) @unlink($delAbs);
    $playlist = array_values(array_filter($playlist, fn($t) => ($t['file'] ?? '') !== $delRel));
    file_put_contents($jsonFile, json_encode($playlist, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    header('Location: admin.php');
    exit;
}

$playlist = file_exists($jsonFile) ? (json_decode(file_get_contents($jsonFile), true) ?: []) : [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админка — Онлайн Радио 24/7</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: radial-gradient(circle at top right, #1e293b, #090d16 65%);
            color: #f8fafc;
            min-height: 100vh;
            padding: 32px 16px;
        }
        .container { max-width: 780px; margin: 0 auto; }
        .header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin-bottom: 24px;
        }
        .header h1 { font-size: 22px; font-weight: 700; }
        .nav-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-link {
            text-decoration: none;
            color: #e2e8f0;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 8px 14px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s;
        }
        .btn-link:hover { background: rgba(255, 255, 255, 0.16); color: #fff; }
        .btn-link.danger { color: #fca5a5; border-color: rgba(239, 68, 68, 0.25); background: rgba(239, 68, 68, 0.1); }

        .card {
            background: rgba(15, 23, 42, 0.72);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 22px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
        }
        .card-title { font-size: 15px; font-weight: 600; margin-bottom: 12px; }

        .row-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        input[type="url"], input[type="file"] {
            flex: 1;
            min-width: 220px;
            color: #f8fafc;
            font-size: 13px;
            background: rgba(255, 255, 255, 0.06);
            padding: 11px 14px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            outline: none;
        }
        input[type="url"]:focus { border-color: #38bdf8; }
        input[type="file"] { border-style: dashed; color: #cbd5e1; }

        .submit-btn {
            padding: 12px 20px;
            border: none;
            border-radius: 12px;
            background: #38bdf8;
            color: #090d16;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: 0.2s;
        }
        .submit-btn:hover:not(:disabled) { background: #7dd3fc; }
        .submit-btn:disabled { opacity: 0.45; cursor: not-allowed; }

        .status-text { margin-top: 10px; font-size: 12px; color: #94a3b8; }
        .alert-ok {
            background: rgba(34, 197, 94, 0.14);
            border: 1px solid rgba(34, 197, 94, 0.35);
            color: #86efac;
            padding: 12px 16px;
            border-radius: 14px;
            font-size: 13px;
            margin-bottom: 18px;
        }
        .alert-err {
            background: rgba(239, 68, 68, 0.14);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
            padding: 12px 16px;
            border-radius: 14px;
            font-size: 13px;
            margin-bottom: 18px;
            word-break: break-word;
        }

        .list-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
        .list-header h2 { font-size: 16px; font-weight: 600; }

        .track-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
        }
        .track-item:last-child { border-bottom: none; padding-bottom: 0; }

        .track-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .thumb {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: #1e293b;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 18px;
            overflow: hidden;
        }
        .thumb img { width: 100%; height: 100%; object-fit: cover; }

        .track-meta { min-width: 0; }
        .track-main {
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .track-sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .del-link {
            color: #f87171;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 6px 10px;
            border-radius: 8px;
            transition: 0.2s;
            flex-shrink: 0;
        }
        .del-link:hover { background: rgba(239, 68, 68, 0.14); }
        .empty { text-align: center; color: #64748b; padding: 24px 0; font-size: 14px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Управление эфиром</h1>
        <div class="nav-actions">
            <a href="index.php" class="btn-link">← На страницу радио</a>
            <a href="?rescan=1" class="btn-link">↻ Найти обложки (Apple)</a>
            <a href="?logout=1" class="btn-link danger">Выйти</a>
        </div>
    </div>

    <?php if ($notice): ?><div class="alert-ok"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if (isset($_GET['uploaded'])): ?><div class="alert-ok">Треки загружены и отправлены на поиск обложек в Apple.</div><?php endif; ?>
    <?php if (isset($_GET['scanned'])): ?><div class="alert-ok">Метаданные и обложки обновлены через Apple.</div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert-err"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

    <div class="card">
        <div class="card-title">Импорт плейлиста или трека по ссылке (SoundCloud и др.)</div>
        <form method="post" class="row-form" onsubmit="document.getElementById('importBtn').textContent='Скачиваем...';">
            <input type="url" name="playlist_url" placeholder="https://soundcloud.com/.../sets/..." required>
            <button type="submit" id="importBtn" class="submit-btn">Скачать в эфир</button>
        </form>
        <p class="status-text">В корне проекта должны лежать <code>yt-dlp.exe</code>, <code>ffmpeg.exe</code> и <code>ffprobe.exe</code>. Названия и обложки подтянутся автоматически.</p>
    </div>

    <div class="card">
        <div class="card-title">Загрузка аудиофайлов</div>
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <div class="row-form">
                <input type="file" id="fileInput" name="audio[]" accept=".mp3,.wav,.ogg,.m4a,.aac,.flac" multiple required>
                <div id="hiddenDurations"></div>
                <button type="submit" id="submitBtn" class="submit-btn" disabled>Загрузить</button>
            </div>
            <p class="status-text" id="status">Выберите аудиофайлы (MP3, WAV, OGG, M4A, FLAC) — длительность определится автоматически</p>
        </form>
    </div>

    <div class="card">
        <div class="list-header">
            <h2>Треки в ротации (<?= count($playlist) ?>)</h2>
        </div>

        <?php if (empty($playlist)): ?>
            <div class="empty">Плейлист пуст. Загрузите треки или вставьте ссылку на плейлист выше.</div>
        <?php else: ?>
            <?php foreach ($playlist as $track): ?>
                <?php
                    $dur = (int)($track['duration'] ?? 0);
                    $ext = strtoupper(pathinfo($track['file'] ?? '', PATHINFO_EXTENSION));
                ?>
                <div class="track-item">
                    <div class="track-left">
                        <div class="thumb">
                            <?php if (!empty($track['cover'])): ?>
                                <img src="<?= htmlspecialchars($track['cover']) ?>" alt="">
                            <?php else: ?>
                                ♪
                            <?php endif; ?>
                        </div>
                        <div class="track-meta">
                            <div class="track-main"><?= htmlspecialchars($track['track'] ?? $track['title'] ?? 'Без названия') ?></div>
                            <div class="track-sub">
                                <?= htmlspecialchars($track['artist'] ?? 'В эфире') ?>
                                <?php if ($ext): ?> • <?= $ext ?><?php endif; ?>
                                <?php if ($dur > 0): ?> • <?= gmdate("i:s", $dur) ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <a href="?del=<?= urlencode(basename($track['file'])) ?>" onclick="return confirm('Удалить трек из эфира?')" class="del-link">Удалить</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    const fileInput = document.getElementById('fileInput');
    const hiddenContainer = document.getElementById('hiddenDurations');
    const submitBtn = document.getElementById('submitBtn');
    const status = document.getElementById('status');

    fileInput.addEventListener('change', async () => {
        hiddenContainer.innerHTML = '';
        submitBtn.disabled = true;
        const files = Array.from(fileInput.files);
        if (!files.length) {
            status.textContent = 'Выберите аудиофайлы (MP3, WAV, OGG, M4A, FLAC) — длительность определится автоматически';
            return;
        }

        status.textContent = 'Считываем длительность треков...';

        for (let i = 0; i < files.length; i++) {
            const duration = await getAudioDuration(files[i]);
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `durations[${i}]`;
            input.value = duration;
            hiddenContainer.appendChild(input);
        }

        status.textContent = `Готово к загрузке: ${files.length} файл(ов).`;
        submitBtn.disabled = false;
    });

    function getAudioDuration(file) {
        return new Promise((resolve) => {
            const audio = document.createElement('audio');
            audio.preload = 'metadata';
            audio.onloadedmetadata = () => {
                URL.revokeObjectURL(audio.src);
                resolve(audio.duration);
            };
            audio.onerror = () => resolve(0);
            audio.src = URL.createObjectURL(file);
        });
    }
</script>
</body>
</html>