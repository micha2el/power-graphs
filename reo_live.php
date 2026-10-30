<?php
/**
 * Reolink Live-Viewer (MJPEG-Proxy) mit zwei Übertragungsmodi
 * -----------------------------------------------------------
 * Modus "snapshot": Snapshots per Reolink-HTTP-API (kein ffmpeg nötig, geringe Last)
 * Modus "ffmpeg"  : RTSP-Stream der Kamera wird per ffmpeg in MJPEG gewandelt
 *                   (flüssigeres Bild, höhere Bildrate, benötigt ffmpeg + proc_open)
 *
 * Die Seite zeigt eine Ladeanzeige, bis das erste Bild ankommt, und danach
 * Datenmenge, Datenrate, Bildanzahl und Laufzeit des laufenden Streams.
 *
 * Aufrufe:
 *   reolink_live.php                                        -> Webseite mit Auswahl
 *   ?action=stream&cam=ID&mode=snapshot|ffmpeg&quality=sub|main
 *                                                           -> JSON (per XHR geladen)
 *   ?action=stats&sid=STREAM_ID                             -> JSON mit Zählerständen (per XHR)
 *   ?action=mjpeg&cam=ID[&sid=..]                           -> MJPEG via Snapshots
 *   ?action=mjpeg_ffmpeg&cam=ID&quality=sub|main[&sid=..]   -> MJPEG via ffmpeg/RTSP
 *   ?action=snapshot&cam=ID                                 -> einzelnes JPEG
 *
 * Einbinden in eine andere Seite (ohne Zähler, "sid" ist optional):
 *   <img src="reolink_live.php?action=mjpeg_ffmpeg&cam=cam1&quality=sub">
 */

// =====================================================================
// KONFIGURATION (Dummy-Daten bitte ersetzen)
// =====================================================================
include("config.php");

// --- Allgemein ---
$FPS             = 1;      // Bilder/Sek. im Snapshot-Modus (1-10 sinnvoll)
$CURL_TIMEOUT    = 10;      // Timeout pro Kameraanfrage (Snapshot-Modus) in Sekunden
$MAX_STREAM_SECS = 0;      // 0 = unbegrenzt, sonst Stream nach X Sekunden beenden

// --- ffmpeg-Modus ---
$ALLOW_FFMPEG       = true;      // ffmpeg-Modus generell erlauben
$FFMPEG_BIN         = 'ffmpeg';  // ggf. voller Pfad, z. B. /usr/bin/ffmpeg
$FFMPEG_FPS         = 10;        // Ausgabe-Bildrate des MJPEG-Streams
$FFMPEG_QUALITY     = 5;         // JPEG-Qualität: 2 (beste) bis 31 (schlechteste)
$FFMPEG_SCALE_WIDTH = 0;         // 0 = Originalgröße, sonst Breite in Pixel (Höhe automatisch)
$FFMPEG_MAX_STREAMS = 3;         // max. gleichzeitige ffmpeg-Prozesse (schützt die CPU)
$FFMPEG_STALL_SECS  = 10;        // Abbruch, wenn so lange keine Daten von ffmpeg kommen
// =====================================================================


// ---------------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------------

const ALLOWED_MODES   = ['snapshot', 'ffmpeg'];
const ALLOWED_QUALITY = ['sub', 'main'];

/** JSON-Fehler ausgeben und beenden. */
function json_error(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message]);
    exit;
}

/** Basis-URL einer Kamera (HTTP-API). */
function cam_base(array $cam): string
{
    return ($cam['https'] ? 'https://' : 'http://') . $cam['ip'];
}

/** HTTP-Request per cURL (GET oder POST mit JSON). Gibt Body oder false zurück. */
function http_request(string $url, ?string $postJson = null)
{
    global $CURL_TIMEOUT;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $CURL_TIMEOUT,
        CURLOPT_TIMEOUT        => $CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,   // Reolink nutzt meist selbstsignierte Zertifikate
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    if ($postJson !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? $body : false;
}

/** Login an der Reolink-API, liefert Token oder null. */
function reolink_login(array $cam): ?string
{
    $url  = cam_base($cam) . '/cgi-bin/api.cgi?cmd=Login';
    $json = json_encode([[
        'cmd'   => 'Login',
        'param' => ['User' => [
            'userName' => $cam['user'],
            'password' => $cam['password'],
        ]],
    ]]);
    $resp = http_request($url, $json);
    if ($resp === false) {
        return null;
    }
    $data = json_decode($resp, true);
    return $data[0]['value']['Token']['name'] ?? null;
}

/** Holt ein JPEG-Snapshot. Gibt Binärdaten oder null zurück. $token wird ggf. erneuert. */
function reolink_snapshot(array $cam, ?string &$token): ?string
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        if ($token === null) {
            $token = reolink_login($cam);
            if ($token === null) {
                return null;
            }
        }
        $url = cam_base($cam) . '/cgi-bin/api.cgi?' . http_build_query([
            'cmd'     => 'Snap',
            'channel' => (int)$cam['channel'],
            'rs'      => bin2hex(random_bytes(4)),
            'token'   => $token,
        ]);
        $img = http_request($url);
        // JPEG beginnt mit FF D8 – sonst kam eine JSON-Fehlermeldung (Token abgelaufen)
        if ($img !== false && strncmp($img, "\xFF\xD8", 2) === 0) {
            return $img;
        }
        $token = null; // Neu einloggen und noch einmal versuchen
    }
    return null;
}

/** Kamera anhand der ID aus dem Request holen (nur IDs aus der Konfiguration erlaubt). */
function get_cam_or_fail(array $cameras): array
{
    $id = $_GET['cam'] ?? '';
    if (!is_string($id) || !isset($cameras[$id])) {
        json_error(404, 'Unbekannte Kamera');
    }
    return [$id, $cameras[$id]];
}

/** Qualität (sub/main) aus dem Request lesen, Whitelist-geprüft. */
function get_quality(): string
{
    $q = $_GET['quality'] ?? 'sub';
    return (is_string($q) && in_array($q, ALLOWED_QUALITY, true)) ? $q : 'sub';
}

/** Ausgabe-Puffer abschalten, damit der Stream sofort beim Client ankommt. */
function prepare_streaming_output(): void
{
    set_time_limit(0);
    ignore_user_abort(false);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ini_set('zlib.output_compression', '0');
}

// ---------------------------------------------------------------------
// Zähler (Datenmenge, Bilder) – werden pro Stream in einer Temp-Datei
// abgelegt und von der Webseite per XHR abgefragt
// ---------------------------------------------------------------------

/** Stream-ID aus dem Request (16 Hex-Zeichen) oder null. */
function valid_sid(): ?string
{
    $sid = $_GET['sid'] ?? '';
    return (is_string($sid) && preg_match('/^[a-f0-9]{16}$/', $sid)) ? $sid : null;
}

function stats_path(string $sid): string
{
    return sys_get_temp_dir() . '/reolink_stats_' . $sid . '.json';
}

/** Zählerstand schreiben (ohne Stream-ID passiert nichts). */
function stats_write(?string $sid, array $stats): void
{
    if ($sid === null) {
        return;
    }
    $stats['updated'] = time();
    @file_put_contents(stats_path($sid), json_encode($stats), LOCK_EX);
}

/** Zählerstand lesen, null wenn nicht vorhanden. */
function stats_read(string $sid): ?array
{
    $fh = @fopen(stats_path($sid), 'r');
    if (!$fh) {
        return null;
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : null;
}

/** Schreibt den Zählerstand höchstens einmal pro Sekunde (oder sofort mit $force). */
function stats_tick(?string $sid, array $stats, float &$lastWrite, bool $force = false): void
{
    $now = microtime(true);
    if ($force || ($now - $lastWrite) >= 1.0) {
        stats_write($sid, $stats);
        $lastWrite = $now;
    }
}

/** Löscht Zählerdateien, die länger als 10 Minuten nicht aktualisiert wurden. */
function stats_cleanup(): void
{
    foreach (glob(sys_get_temp_dir() . '/reolink_stats_*.json') ?: [] as $file) {
        if (@filemtime($file) < time() - 600) {
            @unlink($file);
        }
    }
}

// ---------------------------------------------------------------------
// ffmpeg-Hilfsfunktionen
// ---------------------------------------------------------------------

/** Prüft, ob ffmpeg ausführbar und proc_open verfügbar ist (Ergebnis pro Request gecacht). */
function ffmpeg_available(): bool
{
    global $ALLOW_FFMPEG, $FFMPEG_BIN;
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    // function_exists() liefert false, wenn proc_open in disable_functions steht
    if (!$ALLOW_FFMPEG || !function_exists('proc_open')) {
        return $ok = false;
    }
    $proc = @proc_open(escapeshellarg($FFMPEG_BIN) . ' -version', [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($proc)) {
        return $ok = false;
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return $ok = (proc_close($proc) === 0);
}

/**
 * Belegt einen von $max "Slots" per Lock-Datei. Der Lock wird automatisch frei,
 * sobald das Skript (bzw. die Verbindung) endet. Gibt Dateihandle oder null zurück.
 */
function acquire_ffmpeg_slot(int $max)
{
    for ($i = 1; $i <= $max; $i++) {
        $fh = @fopen(sys_get_temp_dir() . '/reolink_ffmpeg_slot_' . $i . '.lock', 'c');
        if ($fh && flock($fh, LOCK_EX | LOCK_NB)) {
            return $fh;
        }
        if ($fh) {
            fclose($fh);
        }
    }
    return null;
}

/** RTSP-URL einer Kamera (Zugangsdaten URL-kodiert). */
function rtsp_url(array $cam, string $quality): string
{
    $codec = ($cam['codec'] ?? 'h264') === 'h265' ? 'h265' : 'h264';
    return sprintf(
        'rtsp://%s:%s@%s:%d/%sPreview_%02d_%s',
        rawurlencode($cam['user']),
        rawurlencode($cam['password']),
        $cam['ip'],
        (int)($cam['rtsp_port'] ?? 554),
        $codec,
        (int)$cam['channel'] + 1,
        $quality
    );
}

/** Baut die ffmpeg-Kommandozeile (RTSP -> MJPEG auf stdout). */
function build_ffmpeg_command(array $cam, string $quality): string
{
    global $FFMPEG_BIN, $FFMPEG_FPS, $FFMPEG_QUALITY, $FFMPEG_SCALE_WIDTH;

    $filters = [];
    if ($FFMPEG_SCALE_WIDTH > 0) {
        $filters[] = 'scale=' . (int)$FFMPEG_SCALE_WIDTH . ':-2';
    }

    $parts = [
        escapeshellarg($FFMPEG_BIN),
        '-nostdin', '-hide_banner', '-loglevel error',
        '-fflags nobuffer', '-flags low_delay',
        '-rtsp_transport tcp',
        '-i ' . escapeshellarg(rtsp_url($cam, $quality)),
        '-an',
        $filters ? '-vf ' . escapeshellarg(implode(',', $filters)) : '',
        '-r ' . max(1, (int)$FFMPEG_FPS),
        '-c:v mjpeg', '-pix_fmt yuvj420p',
        '-q:v ' . min(31, max(2, (int)$FFMPEG_QUALITY)),
        '-f mpjpeg', '-boundary_tag frame',
        'pipe:1',
    ];
    $cmd = implode(' ', array_filter($parts, 'strlen'));

    // "exec" ersetzt die Shell durch ffmpeg, damit proc_terminate() den richtigen Prozess trifft
    return (PHP_OS_FAMILY === 'Windows' ? '' : 'exec ') . $cmd;
}

// ---------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------
$action = $_GET['action'] ?? 'page';

switch ($action) {

    // ---- JSON-Antwort für den XMLHttpRequest ---------------------------
    case 'stream':
        [$id, $cam] = get_cam_or_fail($CAMERAS);

        $mode = $_GET['mode'] ?? 'snapshot';
        if (!is_string($mode) || !in_array($mode, ALLOWED_MODES, true)) {
            json_error(400, 'Ungültiger Modus');
        }

        stats_cleanup();

        $sid  = bin2hex(random_bytes(8));   // Stream-ID für den Zähler
        $self = strtok($_SERVER['REQUEST_URI'], '?');
        $info = ['id' => $id, 'name' => $cam['name'], 'mode' => $mode, 'sid' => $sid];

        if ($mode === 'ffmpeg') {
            if (!ffmpeg_available()) {
                json_error(503, 'ffmpeg ist auf dem Server nicht verfügbar oder deaktiviert.');
            }
            // Prüfen, ob noch ein Slot frei ist (und direkt wieder freigeben)
            $slot = acquire_ffmpeg_slot($FFMPEG_MAX_STREAMS);
            if ($slot === null) {
                json_error(503, 'Maximale Anzahl gleichzeitiger ffmpeg-Streams erreicht.');
            }
            flock($slot, LOCK_UN);
            fclose($slot);

            $quality         = get_quality();
            $info['quality'] = $quality;
            $info['fps']     = $FFMPEG_FPS;
            $info['url']     = $self . '?action=mjpeg_ffmpeg&cam=' . urlencode($id)
                             . '&quality=' . $quality . '&sid=' . $sid;
        } else {
            $info['fps'] = $FPS;
            $info['url'] = $self . '?action=mjpeg&cam=' . urlencode($id) . '&sid=' . $sid;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($info);
        exit;

    // ---- Zählerstände für die Anzeige (per XHR abgefragt) --------------
    case 'stats':
        $sid = valid_sid();
        if ($sid === null) {
            json_error(400, 'Ungültige Stream-ID');
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $s = stats_read($sid);
        if ($s === null) {
            echo json_encode(['exists' => false]);   // Stream hat noch nicht begonnen
            exit;
        }
        // Als beendet werten, wenn der Stream-Prozess seit 30 s nichts mehr geschrieben hat
        $active = !empty($s['active']) && (time() - (int)$s['updated']) < 30;
        $end    = $active ? time() : (int)$s['updated'];
        echo json_encode([
            'exists'  => true,
            'active'  => $active,
            'bytes'   => (int)$s['bytes'],
            'frames'  => (int)$s['frames'],
            'elapsed' => max(0, $end - (int)$s['started']),
        ]);
        exit;

    // ---- Einzelbild ----------------------------------------------------
    case 'snapshot':
        [$id, $cam] = get_cam_or_fail($CAMERAS);
        $token = null;
        $img   = reolink_snapshot($cam, $token);
        if ($img === null) {
            http_response_code(502);
            exit('Kamera nicht erreichbar');
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($img));
        echo $img;
        exit;

    // ---- MJPEG-Stream über Snapshots ----------------------------------
    case 'mjpeg':
        [$id, $cam] = get_cam_or_fail($CAMERAS);
        prepare_streaming_output();

        $boundary = 'reolinkframe';
        header('Content-Type: multipart/x-mixed-replace; boundary=' . $boundary);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Connection: close');
        header('X-Accel-Buffering: no'); // nginx: nicht puffern

        // Zähler initialisieren; beim Beenden (auch bei Verbindungsabbruch) als inaktiv markieren
        $sid       = valid_sid();
        $stats     = ['bytes' => 0, 'frames' => 0, 'started' => time(), 'active' => true];
        $lastWrite = microtime(true);
        stats_write($sid, $stats);
        register_shutdown_function(function () use (&$stats, $sid) {
            $stats['active'] = false;
            stats_write($sid, $stats);
        });

        $token    = null;
        $start    = time();
        $interval = (int)(1000000 / max(1, $FPS));
        $fails    = 0;

        while (!connection_aborted()) {
            $t0    = microtime(true);
            $force = false;
            $img   = reolink_snapshot($cam, $token);

            if ($img !== null) {
                $fails = 0;
                $part  = "--{$boundary}\r\n"
                       . "Content-Type: image/jpeg\r\n"
                       . 'Content-Length: ' . strlen($img) . "\r\n\r\n"
                       . $img . "\r\n";
                echo $part;
                flush();
                $stats['bytes'] += strlen($part);
                $stats['frames']++;
                $force = ($stats['frames'] === 1);   // erstes Bild sofort melden
            } elseif (++$fails >= 5) {
                break; // Kamera dauerhaft nicht erreichbar
            }

            stats_tick($sid, $stats, $lastWrite, $force);

            if ($MAX_STREAM_SECS > 0 && (time() - $start) >= $MAX_STREAM_SECS) {
                break;
            }

            // Restzeit bis zum nächsten Frame warten
            $elapsed = (int)((microtime(true) - $t0) * 1000000);
            if ($elapsed < $interval) {
                usleep($interval - $elapsed);
            }
        }
        exit;

    // ---- MJPEG-Stream über ffmpeg (RTSP) ------------------------------
    case 'mjpeg_ffmpeg':
        [$id, $cam] = get_cam_or_fail($CAMERAS);

        if (!ffmpeg_available()) {
            json_error(503, 'ffmpeg ist auf dem Server nicht verfügbar oder deaktiviert.');
        }
        $slot = acquire_ffmpeg_slot($FFMPEG_MAX_STREAMS);
        if ($slot === null) {
            json_error(503, 'Maximale Anzahl gleichzeitiger ffmpeg-Streams erreicht.');
        }

        $quality = get_quality();
        $devnull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        $proc = proc_open(build_ffmpeg_command($cam, $quality), [
            0 => ['file', $devnull, 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $devnull, 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            json_error(500, 'ffmpeg konnte nicht gestartet werden.');
        }

        // Zähler initialisieren
        $sid       = valid_sid();
        $stats     = ['bytes' => 0, 'frames' => 0, 'started' => time(), 'active' => true];
        $lastWrite = microtime(true);
        stats_write($sid, $stats);

        // Auch bei hartem Abbruch: ffmpeg beenden und Zähler als inaktiv markieren
        register_shutdown_function(function () use ($proc, &$stats, $sid) {
            if (is_resource($proc)) {
                @proc_terminate($proc);
            }
            $stats['active'] = false;
            stats_write($sid, $stats);
        });

        prepare_streaming_output();
        header('Content-Type: multipart/x-mixed-replace; boundary=frame');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Connection: close');
        header('X-Accel-Buffering: no');

        stream_set_blocking($pipes[1], false);
        $start    = time();
        $lastData = time();
        $tail     = '';   // Rest des vorigen Blocks, damit Bildgrenzen über Blockgrenzen gezählt werden

        while (!connection_aborted()) {
            $force = false;
            $r = [$pipes[1]];
            $w = $e = null;
            $n = @stream_select($r, $w, $e, 1);   // max. 1 Sek. warten, dann Abbruch prüfen
            if ($n === false) {
                break;
            }
            if ($n > 0) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk === false || ($chunk === '' && feof($pipes[1]))) {
                    break; // ffmpeg wurde beendet (Kamera weg, falsche Zugangsdaten, ...)
                }
                if ($chunk !== '') {
                    echo $chunk;
                    flush();
                    $lastData = time();

                    $stats['bytes'] += strlen($chunk);
                    $before          = $stats['frames'];
                    $buf             = $tail . $chunk;
                    $stats['frames'] += substr_count($buf, '--frame');
                    $tail            = substr($buf, -6);
                    $force           = ($before === 0 && $stats['frames'] > 0);
                }
            } elseif ((time() - $lastData) >= $FFMPEG_STALL_SECS) {
                break; // keine Daten mehr -> Stream beenden
            }

            stats_tick($sid, $stats, $lastWrite, $force);

            if ($MAX_STREAM_SECS > 0 && (time() - $start) >= $MAX_STREAM_SECS) {
                break;
            }
        }

        // Aufräumen: ffmpeg beenden, Pipes schließen, Slot freigeben
        @proc_terminate($proc);
        fclose($pipes[1]);
        proc_close($proc);
        flock($slot, LOCK_UN);
        fclose($slot);
        exit;
}

// ---------------------------------------------------------------------
// HTML-Seite (nur Kamera-IDs und Namen, keine Zugangsdaten)
// ---------------------------------------------------------------------
$options = [];
foreach ($CAMERAS as $id => $cam) {
    $options[$id] = $cam['name'];
}
$ffmpegOk = ffmpeg_available();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reolink Live</title>
<style>
    :root { --bg:#f4f5f7; --fg:#1d1f23; --card:#fff; --accent:#0b6bcb; --muted:#6b7280; --border:#d9dce1; }
    @media (prefers-color-scheme: dark) {
        :root { --bg:#14161a; --fg:#e8eaed; --card:#1e2127; --accent:#4c9aff; --muted:#9aa0a8; --border:#30343b; }
    }
    * { box-sizing: border-box; }
    body { margin:0; font-family: system-ui, sans-serif; background:var(--bg); color:var(--fg); }
    .wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
    h1 { font-size: 1.2rem; margin: 0 0 12px; }
    .bar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
    select, button {
        font: inherit; padding: 8px 12px; border-radius: 8px;
        border: 1px solid var(--border); background: var(--card); color: var(--fg);
    }
    button { cursor:pointer; }
    button.primary { background: var(--accent); color:#fff; border-color: var(--accent); }
    [hidden] { display:none !important; }

    .view { background:#000; border-radius:12px; overflow:hidden; aspect-ratio:16/9;
            display:flex; align-items:center; justify-content:center; position:relative; }
    .view img { width:100%; height:100%; object-fit:contain; display:block; }
    .view img.idle { visibility:hidden; }

    /* Ladeanzeige */
    .loader { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center;
              justify-content:center; gap:16px; background:rgba(0,0,0,.6); color:#e8eaed; text-align:center; padding:16px; }
    .spinner { width:52px; height:52px; border-radius:50%;
               border:4px solid rgba(255,255,255,.2); border-top-color:#fff;
               animation: spin .9s linear infinite; }
    .loader-bar { width:160px; height:4px; border-radius:2px; background:rgba(255,255,255,.2); overflow:hidden; }
    .loader-bar::before { content:""; display:block; width:40%; height:100%; background:#fff; border-radius:2px;
                          animation: sweep 1.2s ease-in-out infinite; }
    @keyframes spin  { to { transform: rotate(360deg); } }
    @keyframes sweep { 0% { transform: translateX(-100%); } 100% { transform: translateX(250%); } }

    /* Zähler */
    .stats { display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:8px; margin-top:12px; }
    .stat { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:8px 12px; }
    .stat .label { display:block; font-size:.75rem; color:var(--muted); }
    .stat .value { display:block; font-size:1.05rem; font-variant-numeric: tabular-nums; }

    .status { margin-top:8px; font-size:.85rem; color:var(--muted); min-height:1.2em; }
    .status.err { color:#d33; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Reolink Live-Ansicht</h1>

    <div class="bar">
        <select id="camSelect" aria-label="Kamera wählen">
            <?php foreach ($options as $id => $name): ?>
                <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="modeSelect" aria-label="Übertragungsmodus">
            <option value="snapshot">Snapshot (HTTP-API)</option>
            <option value="ffmpeg" <?= $ffmpegOk ? '' : 'disabled' ?>>
                ffmpeg (RTSP)<?= $ffmpegOk ? '' : ' – nicht verfügbar' ?>
            </option>
        </select>

        <select id="qualitySelect" aria-label="Streamqualität" hidden>
            <option value="sub">Substream (leichter)</option>
            <option value="main">Hauptstream (hohe Qualität)</option>
        </select>

        <button id="btnReload" class="primary">Neu laden</button>
        <button id="btnStop">Stop</button>
    </div>

    <div class="view">
        <img id="liveImg" class="idle" alt="Live-Bild">

        <div id="loader" class="loader" role="status" aria-live="polite">
            <div class="spinner"></div>
            <div class="loader-bar"></div>
            <div id="loaderText">Stream wird geladen …</div>
        </div>
    </div>

    <div class="stats" aria-label="Übertragungsstatistik">
        <div class="stat"><span class="label">Übertragen</span><span class="value" id="statBytes">–</span></div>
        <div class="stat"><span class="label">Datenrate</span><span class="value" id="statRate">–</span></div>
        <div class="stat"><span class="label">Bilder</span><span class="value" id="statFrames">–</span></div>
        <div class="stat"><span class="label">Laufzeit</span><span class="value" id="statTime">–</span></div>
    </div>

    <div id="status" class="status"></div>
</div>

<script>
(function () {
    var FRAME_TIMEOUT_MS = 20000;   // so lange auf das erste Bild warten
    var POLL_MS          = 1000;    // Abfrageintervall für die Zähler

    var camSelect     = document.getElementById('camSelect');
    var modeSelect    = document.getElementById('modeSelect');
    var qualitySelect = document.getElementById('qualitySelect');
    var img           = document.getElementById('liveImg');
    var status        = document.getElementById('status');
    var loader        = document.getElementById('loader');
    var loaderText    = document.getElementById('loaderText');
    var statBytes     = document.getElementById('statBytes');
    var statRate      = document.getElementById('statRate');
    var statFrames    = document.getElementById('statFrames');
    var statTime      = document.getElementById('statTime');

    var token = 0;            // wird bei jedem Start/Stopp erhöht, macht alte Callbacks wirkungslos
    var sid = null, info = null, gotFrame = false;
    var pollTimer = null, frameTimer = null, loaderTimer = null;
    var loaderStart = 0, loaderBase = '';
    var lastBytes = 0, lastTime = 0;

    // ---------- Anzeige-Helfer ----------
    function setStatus(text, isError) {
        status.textContent = text;
        status.className = 'status' + (isError ? ' err' : '');
    }

    function formatBytes(n) {
        var units = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return n.toLocaleString('de-DE', {
            minimumFractionDigits: i ? 1 : 0,
            maximumFractionDigits: i ? 2 : 0
        }) + ' ' + units[i];
    }

    function formatDuration(sec) {
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
        function p(x) { return (x < 10 ? '0' : '') + x; }
        return (h ? h + ':' + p(m) : m) + ':' + p(s);
    }

    function resetStats() {
        statBytes.textContent = statRate.textContent = statFrames.textContent = statTime.textContent = '–';
        lastBytes = 0; lastTime = 0;
    }

    // ---------- Ladeanzeige ----------
    function renderLoader() {
        var s = Math.floor((Date.now() - loaderStart) / 1000);
        loaderText.textContent = loaderBase + ' …' + (s > 0 ? ' (' + s + ' s)' : '');
    }
    function showLoader(text) {
        loaderBase = text;
        loaderStart = Date.now();
        renderLoader();
        loader.hidden = false;
        clearInterval(loaderTimer);
        loaderTimer = setInterval(renderLoader, 250);
    }
    function hideLoader() {
        clearInterval(loaderTimer);
        loader.hidden = true;
    }

    // ---------- Stream-Steuerung ----------
    function clearTimers() {
        clearTimeout(pollTimer);
        clearTimeout(frameTimer);
    }

    // Stream beenden: leeres src schließt die offene MJPEG-Verbindung
    function stopStream(silent) {
        token++;
        sid = null; info = null; gotFrame = false;
        clearTimers();
        hideLoader();
        img.removeAttribute('src');
        img.classList.add('idle');
        if (!silent) { setStatus('Gestoppt.'); }
    }

    function fail(message) {
        stopStream(true);
        setStatus(message, true);
    }

    // Erstes Bild ist angekommen -> Ladeanzeige ausblenden
    function onFirstFrame() {
        if (gotFrame || !info) { return; }
        gotFrame = true;
        clearTimeout(frameTimer);
        hideLoader();
        img.classList.remove('idle');
        var label = (info.mode === 'ffmpeg')
            ? 'ffmpeg/RTSP, ' + (info.quality === 'main' ? 'Hauptstream' : 'Substream')
            : 'Snapshot';
        setStatus('Live: ' + info.name + ' (' + label + ', ' + info.fps + ' fps)');
    }

    // ---------- Zähler per XMLHttpRequest abfragen ----------
    function schedulePoll(my, delay) {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(function () { pollStats(my); }, delay);
    }

    function updateStats(d) {
        var now = performance.now();
        if (lastTime && d.active) {
            var dt = (now - lastTime) / 1000;
            statRate.textContent = formatBytes(dt > 0 ? Math.max(0, (d.bytes - lastBytes) / dt) : 0) + '/s';
        } else if (!d.active) {
            statRate.textContent = formatBytes(0) + '/s';
        }
        lastBytes = d.bytes;
        lastTime  = now;

        statBytes.textContent  = formatBytes(d.bytes);
        statFrames.textContent = d.frames.toLocaleString('de-DE');
        statTime.textContent   = formatDuration(d.elapsed);
    }

    function pollStats(my) {
        if (my !== token || !sid) { return; }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', '?action=stats&sid=' + encodeURIComponent(sid) + '&_=' + Date.now(), true);
        xhr.timeout = 5000;

        xhr.onload = function () {
            if (my !== token) { return; }
            var d = null;
            try { d = JSON.parse(xhr.responseText); } catch (e) {}

            if (d && d.exists) {
                updateStats(d);
                if (d.frames > 0) { onFirstFrame(); }

                if (!d.active) {
                    if (d.frames === 0) {
                        fail('Keine Daten von der Kamera empfangen. IP, Zugangsdaten und Kameraeinstellungen prüfen.');
                    } else {
                        clearTimers();
                        hideLoader();
                        setStatus('Stream wurde vom Server beendet (Kamera nicht mehr erreichbar?).', true);
                    }
                    return;
                }
            }
            schedulePoll(my, POLL_MS);
        };
        xhr.onerror = xhr.ontimeout = function () {
            if (my === token) { schedulePoll(my, POLL_MS); }
        };
        xhr.send();
    }

    // ---------- Stream-Infos per XMLHttpRequest laden ----------
    function loadStream() {
        stopStream(true);
        var my = token;
        resetStats();
        setStatus('');
        showLoader('Verbinde mit Server');

        var query = '?action=stream'
            + '&cam='  + encodeURIComponent(camSelect.value)
            + '&mode=' + encodeURIComponent(modeSelect.value);
        if (modeSelect.value === 'ffmpeg') {
            query += '&quality=' + encodeURIComponent(qualitySelect.value);
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', query, true);
        xhr.timeout = 10000;

        xhr.onload = function () {
            if (my !== token) { return; }
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) {}

            if (xhr.status !== 200 || !data || !data.url) {
                fail('Fehler: ' + ((data && data.error) ? data.error : 'HTTP ' + xhr.status));
                return;
            }

            info = data;
            sid  = data.sid;

            // Jetzt auf das erste Bild warten
            loaderBase  = 'Warte auf erstes Bild von der Kamera';
            img.src     = data.url + '&t=' + Date.now();   // Zeitstempel verhindert Caching
            frameTimer  = setTimeout(function () {
                if (my === token && !gotFrame) {
                    fail('Zeitüberschreitung: Es wurde kein Bild empfangen.');
                }
            }, FRAME_TIMEOUT_MS);
            schedulePoll(my, 500);
        };
        xhr.onerror   = function () { if (my === token) { fail('Netzwerkfehler beim Laden des Streams.'); } };
        xhr.ontimeout = function () { if (my === token) { fail('Zeitüberschreitung beim Laden des Streams.'); } };
        xhr.send();
    }

    // Wird (je nach Browser) beim ersten Bild des MJPEG-Streams ausgelöst
    img.onload = function () {
        if (img.getAttribute('src') && sid) { onFirstFrame(); }
    };
    img.onerror = function () {
        if (!img.getAttribute('src')) { return; }
        if (!gotFrame) {
            fail('Stream konnte nicht geöffnet werden (Kamera oder ffmpeg nicht erreichbar).');
        } else {
            clearTimers();
            setStatus('Stream unterbrochen oder Kamera nicht erreichbar.', true);
        }
    };

    function updateControls() {
        qualitySelect.hidden = (modeSelect.value !== 'ffmpeg');   // Qualität nur im ffmpeg-Modus
    }

    camSelect.addEventListener('change', loadStream);
    qualitySelect.addEventListener('change', loadStream);
    modeSelect.addEventListener('change', function () { updateControls(); loadStream(); });
    document.getElementById('btnReload').addEventListener('click', loadStream);
    document.getElementById('btnStop').addEventListener('click', function () { stopStream(false); });

    // Beim Verlassen der Seite Verbindung sauber schließen
    window.addEventListener('beforeunload', function () { img.removeAttribute('src'); });

    updateControls();
    loadStream();   // erste Kamera automatisch laden
})();
</script>
</body>
</html>

