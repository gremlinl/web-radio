<?php
$allowedExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];
$allFiles = glob('tracks/*') ?: [];
$audioFiles = array_values(array_filter($allFiles, function ($path) use ($allowedExts) {
    return is_file($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $allowedExts, true);
}));

$currentList = file_exists('playlist.json') ? json_decode(file_get_contents('playlist.json'), true) : [];
if (!is_array($currentList)) $currentList = [];

if (count($audioFiles) !== count($currentList)) {
    include 'scan.php';
}

$jsonFile = 'playlist.json';
$playlist = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];
if (!is_array($playlist)) $playlist = [];

if (count($playlist) > 1) {
    $seed = crc32(date('Y-m-d'));
    mt_srand($seed);
    for ($i = count($playlist) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$playlist[$i], $playlist[$j]] = [$playlist[$j], $playlist[$i]];
    }
    mt_srand();
}

if (isset($_GET['sync'])) {
    header('Content-Type: application/json');
    $crossfade = 4;
    $totalDuration = 0;

    foreach ($playlist as &$track) {
        $track['effective'] = max(5, ($track['duration'] ?? 180) - $crossfade);
        $parts = explode('/', $track['file']);
        $track['url'] = implode('/', array_map('rawurlencode', $parts));
        $totalDuration += $track['effective'];
    }
    unset($track);

    if ($totalDuration <= 0) {
        echo json_encode(['error' => 'В папке tracks/ нет аудиофайлов']);
        exit;
    }

    $now = microtime(true);
    $cyclePos = fmod($now, $totalDuration);
    $accumulated = 0;
    $currentIndex = 0;
    $seekTime = 0;

    foreach ($playlist as $idx => $track) {
        if ($cyclePos < $accumulated + $track['effective']) {
            $currentIndex = $idx;
            $seekTime = $cyclePos - $accumulated;
            break;
        }
        $accumulated += $track['effective'];
    }

    echo json_encode([
        'playlist' => $playlist,
        'index' => $currentIndex,
        'seek' => $seekTime,
        'crossfade' => $crossfade
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Онлайн Радио 24/7</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #090d16;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            position: relative;
            padding: 20px;
        }

        .bg-blur {
            position: fixed;
            inset: -40px;
            background-size: cover;
            background-position: center;
            filter: blur(55px) brightness(0.38) saturate(1.4);
            transition: background-image 1.2s ease;
            z-index: 0;
            transform: scale(1.1);
        }

        .player-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 390px;
            background: rgba(15, 23, 42, 0.68);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 28px;
            padding: 28px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.65);
            text-align: center;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
        }

        .top-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .live-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(239, 68, 68, 0.16);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
            font-size: 11px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 99px;
            letter-spacing: 0.08em;
        }

        .live-dot {
            width: 7px;
            height: 7px;
            background: #ef4444;
            border-radius: 50%;
            box-shadow: 0 0 8px #ef4444;
            animation: pulse 1.6s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        .admin-link {
            text-decoration: none;
            color: #cbd5e1;
            font-size: 12px;
            font-weight: 600;
            padding: 5px 11px;
            border-radius: 99px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
            transition: all 0.2s ease;
        }

        .admin-link:hover {
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff;
        }

        .eq {
            display: flex;
            align-items: flex-end;
            gap: 3px;
            height: 16px;
            opacity: 0.3;
            transition: opacity 0.3s;
        }
        .eq.active { opacity: 1; }
        .eq span {
            width: 3px;
            height: 4px;
            background: #38bdf8;
            border-radius: 2px;
        }
        .eq.active span:nth-child(1) { animation: eq 0.7s infinite alternate; }
        .eq.active span:nth-child(2) { animation: eq 0.5s infinite alternate 0.2s; }
        .eq.active span:nth-child(3) { animation: eq 0.8s infinite alternate 0.1s; }
        .eq.active span:nth-child(4) { animation: eq 0.6s infinite alternate 0.3s; }

        @keyframes eq {
            0% { height: 4px; }
            100% { height: 16px; }
        }

        .cover-wrap {
            position: relative;
            width: 240px;
            height: 240px;
            margin: 0 auto 22px;
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(135deg, #1e293b, #0f172a);
            box-shadow: 0 18px 36px rgba(0, 0, 0, 0.45);
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .cover-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
            transition: transform 0.5s ease;
        }

        .cover-wrap.playing img {
            transform: scale(1.03);
        }

        .cover-placeholder {
            width: 84px;
            height: 84px;
            color: #475569;
        }

        .track-name {
            font-size: 19px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .track-artist {
            font-size: 14px;
            font-weight: 500;
            color: #94a3b8;
            margin-bottom: 18px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .progress-area {
            margin-bottom: 22px;
        }
        .progress-bar {
            width: 100%;
            height: 5px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 99px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #38bdf8, #818cf8);
            border-radius: 99px;
            transition: width 0.25s linear;
        }
        .time-row {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #64748b;
            margin-top: 6px;
            font-variant-numeric: tabular-nums;
        }

        .play-btn {
            width: 100%;
            padding: 15px 24px;
            border: none;
            border-radius: 16px;
            background: #ffffff;
            color: #0f172a;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s ease;
            box-shadow: 0 8px 20px rgba(255, 255, 255, 0.15);
        }

        .play-btn:hover {
            transform: translateY(-1px);
            background: #f1f5f9;
        }

        .play-btn.playing {
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: none;
        }

        .volume-box {
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #94a3b8;
        }

        input[type="range"] {
            -webkit-appearance: none;
            flex: 1;
            height: 4px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 99px;
            outline: none;
            cursor: pointer;
        }
        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #ffffff;
            cursor: pointer;
        }

        .up-next {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 12px;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .up-next span {
            color: #cbd5e1;
            font-weight: 500;
        }
    </style>
</head>
<body>

<div class="bg-blur" id="bgBlur"></div>

<div class="player-card">
    <div class="top-bar">
        <div class="live-badge"><span class="live-dot"></span> В ЭФИРЕ 24/7</div>
        <div class="top-right">
            <div class="eq" id="eq">
                <span></span><span></span><span></span><span></span>
            </div>
            <a href="admin.php" class="admin-link" title="Управление эфиром">Админка</a>
        </div>
    </div>

    <div class="cover-wrap" id="coverWrap">
        <img id="coverImg" src="" alt="Обложка">
        <svg class="cover-placeholder" id="coverPlaceholder" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="12" cy="12" r="10"/>
            <circle cx="12" cy="12" r="3"/>
            <path d="M12 2a10 10 0 0 1 10 10"/>
        </svg>
    </div>

    <div class="track-name" id="trackName">Онлайн-радио</div>
    <div class="track-artist" id="trackArtist">Нажмите кнопку, чтобы подключиться</div>

    <div class="progress-area">
        <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
        <div class="time-row">
            <span id="timeCurrent">0:00</span>
            <span id="timeTotal">0:00</span>
        </div>
    </div>

    <button class="play-btn" id="playBtn">▶ Слушать эфир</button>

    <div class="volume-box">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
            <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
            <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
        </svg>
        <input type="range" id="volSlider" min="0" max="1" step="0.01" value="0.85">
    </div>

    <div class="up-next" id="upNext">Далее: <span>—</span></div>
</div>

<script>
    const playBtn = document.getElementById('playBtn');
    const trackName = document.getElementById('trackName');
    const trackArtist = document.getElementById('trackArtist');
    const coverImg = document.getElementById('coverImg');
    const coverPlaceholder = document.getElementById('coverPlaceholder');
    const coverWrap = document.getElementById('coverWrap');
    const bgBlur = document.getElementById('bgBlur');
    const eq = document.getElementById('eq');
    const volSlider = document.getElementById('volSlider');
    const progressFill = document.getElementById('progressFill');
    const timeCurrent = document.getElementById('timeCurrent');
    const timeTotal = document.getElementById('timeTotal');
    const upNext = document.getElementById('upNext');

    const deckA = new Audio();
    const deckB = new Audio();
    deckA.crossOrigin = "anonymous";
    deckB.crossOrigin = "anonymous";

    let activeDeck = deckA;
    let nextDeck = deckB;

    let isPlaying = false;
    let state = null;
    let fadeTriggered = false;
    let masterVolume = 0.85;

    function formatTime(sec) {
        if (!sec || isNaN(sec)) return '0:00';
        const m = Math.floor(sec / 60);
        const s = Math.floor(sec % 60);
        return `${m}:${s < 10 ? '0' : ''}${s}`;
    }

    function updateTrackUI(index) {
        const track = state.playlist[index];
        const nextIdx = (index + 1) % state.playlist.length;
        const nextTrack = state.playlist[nextIdx];

        if (track.track && track.artist) {
            trackName.textContent = track.track;
            trackArtist.textContent = track.artist;
        } else if (track.title && track.title.includes('—')) {
            const parts = track.title.split('—');
            trackArtist.textContent = parts[0].trim();
            trackName.textContent = parts.slice(1).join('—').trim();
        } else {
            trackName.textContent = track.title || 'Без названия';
            trackArtist.textContent = 'В эфире';
        }

        upNext.innerHTML = `Далее: <span>${nextTrack.title}</span>`;

        if (track.cover && track.cover !== 'default-cover.jpg') {
            coverImg.src = track.cover;
            coverImg.onload = () => {
                coverImg.style.display = 'block';
                coverPlaceholder.style.display = 'none';
                bgBlur.style.backgroundImage = `url('${track.cover}')`;
            };
            coverImg.onerror = () => resetCover();
        } else {
            resetCover();
        }
    }

    function resetCover() {
        coverImg.style.display = 'none';
        coverPlaceholder.style.display = 'block';
        bgBlur.style.backgroundImage = 'none';
    }

    volSlider.addEventListener('input', (e) => {
        masterVolume = parseFloat(e.target.value);
        if (isPlaying && !fadeTriggered) {
            activeDeck.volume = masterVolume;
        }
    });

    playBtn.addEventListener('click', async () => {
        if (isPlaying) {
            deckA.pause();
            deckB.pause();
            isPlaying = false;
            playBtn.textContent = '▶ Слушать эфир';
            playBtn.classList.remove('playing');
            eq.classList.remove('active');
            coverWrap.classList.remove('playing');
            return;
        }

        playBtn.textContent = 'Подключение к эфиру...';
        try {
            const res = await fetch('index.php?sync=1');
            state = await res.json();
        } catch (err) {
            trackArtist.textContent = 'Ошибка соединения с сервером';
            playBtn.textContent = '▶ Слушать эфир';
            return;
        }

        if (state.error) {
            trackArtist.textContent = state.error;
            playBtn.textContent = '▶ Слушать эфир';
            return;
        }

        isPlaying = true;
        playBtn.textContent = '⏸ Пауза';
        playBtn.classList.add('playing');
        eq.classList.add('active');
        coverWrap.classList.add('playing');
        startTrack(state.index, state.seek);
    });

    function bindDeckEvents(deck) {
        deck.ontimeupdate = () => {
            if (!isPlaying || deck !== activeDeck) return;

            const dur = deck.duration || 0;
            const cur = deck.currentTime || 0;
            timeCurrent.textContent = formatTime(cur);
            timeTotal.textContent = formatTime(dur);
            if (dur > 0) {
                progressFill.style.width = `${Math.min(100, (cur / dur) * 100)}%`;
            }

            const timeLeft = dur - cur;
            if (!fadeTriggered && dur > state.crossfade * 2 && timeLeft <= state.crossfade) {
                fadeTriggered = true;
                crossfadeToNext();
            }
        };

        deck.onended = () => {
            if (!fadeTriggered && isPlaying && deck === activeDeck) {
                const nextIdx = (state.index + 1) % state.playlist.length;
                startTrack(nextIdx, 0);
            }
        };
    }

    function startTrack(index, seekTime = 0) {
        if (!isPlaying) return;
        fadeTriggered = false;
        const track = state.playlist[index];
        state.index = index;

        updateTrackUI(index);

        activeDeck.src = track.url || track.file;
        activeDeck.volume = masterVolume;

        activeDeck.onloadedmetadata = () => {
            if (seekTime > 0 && seekTime < activeDeck.duration - 2) {
                activeDeck.currentTime = seekTime;
            }
            activeDeck.play();
        };

        bindDeckEvents(activeDeck);
    }

    function crossfadeToNext() {
        const nextIdx = (state.index + 1) % state.playlist.length;
        const nextTrack = state.playlist[nextIdx];

        nextDeck.src = nextTrack.url || nextTrack.file;
        nextDeck.currentTime = 0;
        nextDeck.volume = 0;
        nextDeck.play();

        state.index = nextIdx;
        updateTrackUI(nextIdx);

        const steps = 20;
        const intervalTime = (state.crossfade * 1000) / steps;
        let step = 0;

        const fadeTimer = setInterval(() => {
            step++;
            const progress = step / steps;
            activeDeck.volume = Math.max(0, masterVolume * (1 - progress));
            nextDeck.volume = Math.min(masterVolume, masterVolume * progress);

            if (step >= steps) {
                clearInterval(fadeTimer);
                activeDeck.pause();
                activeDeck.ontimeupdate = null;
                activeDeck.onended = null;

                [activeDeck, nextDeck] = [nextDeck, activeDeck];
                fadeTriggered = false;
                bindDeckEvents(activeDeck);
            }
        }, intervalTime);
    }
</script>

</body>
</html>