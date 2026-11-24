<?php
set_time_limit(0);

$musicDir = 'tracks/';
$jsonFile = 'playlist.json';
$allowedExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];

if (!is_dir($musicDir)) {
    mkdir($musicDir, 0755, true);
}

$existing = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];
$knownTracks = [];
if (is_array($existing)) {
    foreach ($existing as $item) {
        if (!empty($item['recognized']) && !empty($item['file']) && file_exists($item['file'])) {
            $knownTracks[$item['file']] = $item;
        }
    }
}

$playlist = [];
$allFiles = glob($musicDir . '*') ?: [];
$audioFiles = array_values(array_filter($allFiles, function ($path) use ($allowedExts) {
    if (!is_file($path)) return false;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, $allowedExts, true);
}));

foreach ($audioFiles as $file) {
    if (isset($knownTracks[$file])) {
        $playlist[] = $knownTracks[$file];
        continue;
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    // Очищаем имя файла от номеров треков и технических суффиксов в квадратных скобках
    $rawBase = pathinfo($file, PATHINFO_FILENAME);
    $cleanName = preg_replace('/\s*\[[^\]]+\]\s*$/', '', $rawBase);
    $cleanName = preg_replace('/^[0-9\.\-\s_]+/', '', $cleanName);
    $cleanName = trim(str_replace(['_', '  '], ' ', $cleanName)) ?: $rawBase;

    $artist = 'В эфире';
    $trackName = $cleanName;
    $title = $cleanName;
    $cover = '';
    $recognized = false;

    // Если в имени файла уже есть разделитель "Артист - Трек", разбиваем заранее как fallback
    if (strpos($cleanName, ' — ') !== false || strpos($cleanName, ' - ') !== false) {
        $sep = strpos($cleanName, ' — ') !== false ? ' — ' : ' - ';
        [$pArtist, $pTrack] = explode($sep, $cleanName, 2);
        if (trim($pArtist) !== '' && trim($pTrack) !== '') {
            $artist = trim($pArtist);
            $trackName = trim($pTrack);
            $title = $artist . ' — ' . $trackName;
        }
    }

    // Поиск метаданных и обложки через iTunes Search API (бесплатно, без токена)
    $appleData = searchAppleItunes($cleanName);
    if ($appleData) {
        $recognized = true;
        $artist = $appleData['artist'] ?: $artist;
        $trackName = $appleData['track'] ?: $trackName;
        $title = $artist . ' — ' . $trackName;
        $cover = $appleData['cover'];
    }

    $oldDuration = $knownTracks[$file]['duration'] ?? null;
    $duration = ($oldDuration && $oldDuration > 1)
        ? $oldDuration
        : ($ext === 'mp3' ? getMp3Duration($file) : 180);

    $playlist[] = [
        'file' => $file,
        'artist' => $artist,
        'track' => $trackName,
        'title' => $title,
        'duration' => $duration,
        'cover' => $cover,
        'recognized' => $recognized
    ];
}

file_put_contents($jsonFile, json_encode($playlist, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

function searchAppleItunes(string $query): ?array {
    $url = 'https://itunes.apple.com/search?term=' . urlencode($query) . '&media=music&entity=song&limit=1';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 6,
            'ignore_errors' => true
        ]
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    $item = $data['results'][0] ?? null;
    if (!is_array($item)) return null;

    $cover = '';
    if (!empty($item['artworkUrl100'])) {
        $cover = str_replace('100x100bb', '600x600bb', (string)$item['artworkUrl100']);
    }

    return [
        'artist' => trim((string)($item['artistName'] ?? '')),
        'track'  => trim((string)($item['trackName'] ?? '')),
        'cover'  => $cover
    ];
}

function getMp3Duration(string $file): int {
    $f = @fopen($file, 'rb');
    if (!$f) return 180;

    $header = fread($f, 10);
    $offset = 0;
    if (substr($header, 0, 3) === 'ID3') {
        $size = ((ord($header[6]) & 0x7f) << 21)
              | ((ord($header[7]) & 0x7f) << 14)
              | ((ord($header[8]) & 0x7f) << 7)
              | (ord($header[9]) & 0x7f);
        $offset = $size + 10;
    }

    fseek($f, $offset);
    $chunk = fread($f, 8192);
    fclose($f);

    $bitrates = [
        1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
        2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160]
    ];

    for ($i = 0; $i < strlen($chunk) - 4; $i++) {
        if (ord($chunk[$i]) === 0xFF && (ord($chunk[$i + 1]) & 0xE0) === 0xE0) {
            $ver = (ord($chunk[$i + 1]) & 0x18) >> 3;
            $brIdx = (ord($chunk[$i + 2]) & 0xF0) >> 4;
            $verKey = ($ver === 3) ? 1 : 2;
            if (isset($bitrates[$verKey][$brIdx]) && $bitrates[$verKey][$brIdx] > 0) {
                $bitrate = $bitrates[$verKey][$brIdx] * 1000;
                $audioBytes = max(1, filesize($file) - $offset);
                return max(10, (int)round(($audioBytes * 8) / $bitrate));
            }
        }
    }
    return 180;
}