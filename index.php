<?php
/**
 * Energie-Dashboard (Startseite)
 *
 * Zeigt Wechselrichter-/Smartmeter-Daten, FRITZ!DECT-Geräte und optional Charts an.
 *
 * Aufbau der Datei:
 *   1. Konfiguration
 *   2. Hilfsfunktionen (Escaping, Dateien lesen)
 *   3. SVG-Bausteine und Icons
 *   4. Grafik-Zustand und SVG-Container (DC, Batterie, Netz, Haus, Wechselrichter)
 *   5. Datenquellen (Wechselrichter, Smartmeter, DECT-Geräte, Shelly, FRITZ!Box-Steckdose)
 *   6. HTML-Renderer (Navigation, Details, Charts)
 *   7. Hauptablauf
 *   8. Ausgabe (Vollseite oder JSON mit den Werten der Wechselrichter-Grafik)
 *
 * Benötigt PHP >= 7.1
 */

########################################################################################
## 1. Konfiguration
########################################################################################
require __DIR__ . '/config.php';

const DEBUG               = false;
const SCALE               = 1000;   // Umrechnung Rohwerte -> W bzw. kWh
const BATTERY_MIN_PERCENT = 10;
const SHOW_DECT_TOGGLE    = true;   // Boiler-Schalter (AUS / AUTO / AN) und Status-Pill in der Grafik anzeigen
const PICTURE_REFRESH_MS = 1000;   // Aktualisierung der Wechselrichter-Grafik per XHR
const DECT_OVERWRITE_FILE = '/home/tmp_www/dect_overwrite.tmp';   // Marker-Datei Zwangs-aus (siehe dect_overwrite.php)
const DECT_FORCE_ON_FILE  = '/home/tmp_www/dect_force_on.tmp';    // Marker-Datei Zwangs-an
const SHELLY_STATUS_URL   = 'http://192.168.1.190/rpc/Shelly.GetStatus'; // '' = Lampe deaktivieren
const SWITCH_STATE_DIR    = '/home/tmp_www';   // Ablage der Schaltzustände (TV, Drucker), siehe saveSwitchState()

const NAV_ITEMS = [
    ['label' => 'Quickinfo', 'href' => 'index_quick.php',  'icon' => 'pics/icon_quickinfo.svg'],
    ['label' => 'Tables',    'href' => 'index_tables.php', 'icon' => 'pics/icon_tables.svg'],
];

// Einstellungen aus config.php einmalig normalisieren (fehlende Werte -> Standard)
$cfg = [
    'datafile'          => $datafile ?? '',
    'use_webcam'        => !empty($use_webcam),
    'use_inverter'      => !empty($use_inverter),
    'use_smart_meter'   => !empty($use_smart_meter),
    'use_blocks'        => !empty($use_blocks),
    'inverter_data'     => $inverter_data ?? '',
    'smart_power'       => $data_smart_power ?? '',
    'smart_consumption' => $data_smart_verbrauch ?? '',
    'smart_feed_in'     => $data_smart_einspeise ?? '',
    'dect_solar'        => isset($dect_solar) ? (string)$dect_solar : null,
    'home_show_data'    => array_values(array_map('strval', (array)($home_show_data ?? []))),   // Reihenfolge wie in config.php
    'home_specials'     => array_values(array_map('strval', (array)($home_specials ?? []))),    // Reihenfolge wie in config.php
        'charts'            => [
        'energy_consumption' => !empty($show_energy_consumption),
        'solar_power'        => !empty($use_solar_power),
        'solar_production'   => !empty($use_solar_production),
        'warm_water'         => !empty($use_warm_water),
        'heating_temp'       => !empty($use_heating_temp),
    ],
];

########################################################################################
## 2. Hilfsfunktionen
########################################################################################

/** HTML-Escaping für Ausgabe. */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Liest eine Datei; bei Fehler wird eine Meldung gesammelt und null geliefert. */
function readDataFile(string $path, array &$errors): ?string
{
    $content = is_readable($path) ? file_get_contents($path) : false;
    if ($content === false) {
        $errors[] = 'Datei nicht lesbar: ' . $path;
        return null;
    }
    return $content;
}

/**
 * URL einer statischen Datei mit Versionsnummer (Änderungszeit) als Cache-Buster.
 * Ändert sich die Datei, ändert sich die URL, und der Browser lädt sie neu.
 */
function assetUrl(string $file): string
{
    $mtime = @filemtime(__DIR__ . '/' . $file);
    return $file . ($mtime ? '?v=' . $mtime : '');
}

/** Position (nullbasiert) in $home_specials, die zu special_device_NN gehört: 01 -> 0, 02 -> 1, 03 -> 2, 04 -> 3. */
function specialPosition(string $nr): int
{
    return (int)$nr - 1;
}

/** AIN ohne Leerzeichen, für Vergleiche ("12345 0123456" entspricht "123450123456"). */
function normalizeAin(string $ain): string
{
    return preg_replace('/\s+/', '', $ain);
}

/**
 * AIN des Kreises $nr: Sie wird direkt aus der Position des Kreises in $home_specials (config.php) genommen, also aus
 * derselben Stelle, aus der auch die Anzeige des Kreises stammt. '' = dort steht nichts (keine Schaltfunktion).
 */
function specialAin(array $cfg, string $nr): string
{
    return $cfg['home_specials'][specialPosition($nr)] ?? '';
}

/** Nummer des Kreises, der zum Gerät $device im Schaltbefehl gehört (z. B. "tv" -> "03"); null, wenn es keinen gibt. */
function switchSlot(string $device): ?string
{
    foreach (SPECIAL_DEVICES as $nr => $def) {
        if (($def['switch'] ?? null) === $device) {
            return $nr;
        }
    }
    return null;
}

/** Zeilen-Array -> HTML (jede Zeile mit <br> abgeschlossen). */
function renderLines(array $lines): string
{
    $html = '';
    foreach ($lines as $line) {
        $html .= $line . '<br>';
    }
    return $html;
}

########################################################################################
## 3. SVG-Bausteine und Icons
########################################################################################

// Pfaddaten der Icons: [Tag, Attribut, Wert]
const SVG_ICONS = [
    'dc' => [
        ['path', 'd', 'M37.226,79.223h-0.031H24.669h-0.031v-21.5c0-1.018,1.791-2.434,2.961-2.434h6.574c1.17,0,3.021,1.416,3.021,2.434v0.197c0.004,0.029,0.031,0.043,0.031,0.074V79.223z'],
        ['path', 'd', 'M52.919,79.223H52.87H40.362h-0.047V45.138c0-1.018,1.789-2.434,2.959-2.434h6.572c1.172,0,3.023,1.416,3.023,2.434v0.184c0.008,0.068,0.049,0.087,0.049,0.172V79.223z'],
        ['path', 'd', 'M68.612,79.223h-0.049H56.058h-0.051V49.697c0-1.018,1.791-2.433,2.963-2.433h6.572c0.676,0,1.543,0.485,2.188,1.079c0.188,0.141,0.338,0.307,0.473,0.49c0.023,0.031,0.045,0.055,0.066,0.086c0.195,0.301,0.344,0.619,0.344,0.957V79.223z'],
        ['path', 'd', 'M52.851,27.771l-6.018-5.653l4.525-6.933l-8.244-0.466l-0.986-8.196l-6.611,4.947l-6.041-5.652l-1.889,8.037l-8.238-0.46l3.688,7.375l-6.609,4.947l7.6,3.274l-1.881,8.05l7.896-2.391l3.744,7.368l4.527-6.902l7.592,3.242l-0.957-8.202L52.851,27.771z M43.537,28.14c-1.289,2.402-3.236,3.997-5.85,4.783c-2.595,0.785-5.099,0.534-7.508-0.753c-2.408-1.287-4.008-3.235-4.795-5.847c-0.786-2.612-0.538-5.118,0.753-7.521c1.287-2.401,3.238-3.996,5.849-4.782c2.611-0.786,5.116-0.536,7.518,0.75c2.402,1.289,4,3.239,4.787,5.85C45.078,23.233,44.827,25.738,43.537,28.14z'],
    ],
    'battery' => [
        ['path', 'd', 'M66.98,23.292h-4.813v-4.892H48.871v4.892H36.206v-4.892H22.909v4.892h-4.85c-3.068,0-5.579,2.511-5.579,5.573v32.203c0,3.064,2.511,5.566,5.579,5.566L66.98,66.64c3.064,0,5.578-2.502,5.578-5.572V28.864C72.559,25.802,70.045,23.292,66.98,23.292z M35.875,48.401h-6.391v6.419h-4.096v-6.419h-6.394V44.33h6.394v-6.387h4.096v6.387h6.391V48.401z M64.344,48.028h-4.836h-3.863h-4.83v-4.213h4.83h3.863h4.836V48.028z'],
    ],
    'grid' => [
        ['polygon', 'points', '51.239,79.899 33.679,80.163 37.28,50 17.649,50 38.329,40.512 38.849,33 21.835,33 39.919,24.06 42.509,5.043 45.075,24.041 63.062,33 46.13,33 46.651,40.541 67.39,50 47.683,50'],
    ],
    'home' => [
        ['path', 'd', 'M74.143,40.328h-4.577l-22.151-20.56c-2.704-2.692-7.091-2.779-9.755-0.12L15.317,40.328h-4.429c-2.145,0-2.627-1.042-1.068-2.526L39.679,9.575c1.563-1.481,4.115-1.439,5.68,0.042l29.854,28.165C76.78,39.267,76.298,40.328,74.143,40.328z'],
        ['path', 'd', 'M45.4,23.087c-1.25-1.238-3.524-1.23-4.71-0.051L18.741,43.483V72.24c0,1.654,0.91,4.314,2.562,4.314h42.431c1.652,0,3.687-2.66,3.687-4.314V43.619L45.4,23.087z M32.13,41.135c3.578,0,6.576,2.085,7.352,4.872h-2.701c-0.75-1.372-2.55-2.343-4.651-2.343c-2.1,0.001-3.899,0.971-4.651,2.343h-2.702C25.555,43.22,28.554,41.135,32.13,41.135zM42.799,67.912c-8.229,0-14.986-5.471-15.76-12.464h31.523C57.791,62.441,51.031,67.912,42.799,67.912z M58.099,46.007c-0.749-1.372-2.552-2.343-4.649-2.343c-2.101,0-3.9,0.971-4.653,2.343h-2.701c0.776-2.787,3.774-4.872,7.354-4.872c3.575,0,6.576,2.085,7.352,4.872H58.099z'],
    ],
    'inverter' => [
        ['path', 'd', 'M20.489,70.718c-0.179-0.707-1.472-50.964-1.472-51.419c0-1.286,2.489-3.865,4.158-5.077l1.218,61.281C22.83,74.184,20.781,71.901,20.489,70.718z'],
        ['path', 'd', 'M64.55,70.71c-0.319,1.258-2.583,3.728-4.171,4.997l1.226-61.673c1.646,1.11,4.417,3.901,4.417,5.265C66.022,19.753,64.726,69.994,64.55,70.71z'],
        ['path', 'd', 'M42.508,26.043c-3.549,0-6.436,2.886-6.436,6.437c0,3.546,2.887,6.434,6.436,6.434c3.548,0,6.435-2.888,6.435-6.434C48.943,28.929,46.056,26.043,42.508,26.043z'],
        ['path', 'd', 'M42.517,12.469c-5.914,0.142-12.839,0.4-16.454,0.858l1.258,63.4c3.489,0.478,10.836,0.864,13.705,0.864c0.319,0,0.604-0.006,0.845-0.011l0.631-0.028c0.171,0.011,0.436,0.022,0.655,0.028c0.247,0.005,0.53,0.011,0.845,0.011 c2.798,0,9.858-0.369,13.433-0.831l1.26-63.467C55.012,12.855,48.294,12.608,42.517,12.469z M50.244,53.027c0,1.133-0.921,2.055-2.053,2.055H36.823c-1.129,0-2.052-0.922-2.052-2.055v-5.099c0-1.128,0.923-2.05,2.052-2.05h11.368c1.131,0,2.053,0.922,2.053,2.05V53.027z M42.508,41.829c-5.155,0-9.351-4.195-9.351-9.349c0-5.157,4.196-9.351,9.351-9.351c5.155,0,9.347,4.194,9.347,9.351C51.855,37.634,47.663,41.829,42.508,41.829z'],
    ],
];

/**
 * Erzeugt ein SVG-Element. Das Attribut _ngcontent-c4 bleibt erhalten, falls index.css
 * darauf selektiert (Stylesheet stammt vermutlich aus einer Angular-App).
 * $inner wird NICHT escaped und muss bereits sicherer HTML/SVG-Code sein.
 */
function svgTag(string $tag, array $attrs = [], string $inner = ''): string
{
    $attrHtml = ' _ngcontent-c4=""';
    foreach ($attrs as $name => $value) {
        $attrHtml .= ' ' . $name . '="' . h($value) . '"';
    }
    return "<{$tag}{$attrHtml}>{$inner}</{$tag}>";
}

function svgPolyline(string $id, string $class, string $points, float $width = 3): string
{
    return svgTag('polyline', [
        'class' => $class, 'fill' => 'none', 'id' => $id, 'points' => $points, 'stroke-width' => $width,
    ]);
}

function svgCircle(string $id, float $cx, float $cy, string $class, float $r = 14, array $extra = []): string
{
    return svgTag('circle', array_merge(['cx' => $cx, 'cy' => $cy, 'id' => $id, 'r' => $r, 'class' => $class], $extra));
}

/** Icon aus SVG_ICONS (weiß gefüllt) an Position x/y. */
function svgIcon(string $id, string $icon, float $x, float $y, float $size): string
{
    $shapes = '';
    foreach (SVG_ICONS[$icon] as list($tag, $attr, $value)) {
        $shapes .= svgTag($tag, [$attr => $value, 'fill' => '#ffffff']);
    }
    return svgTag('svg', [
        'height' => $size, 'id' => $id, 'viewBox' => '0 0 85.04 85.04', 'width' => $size, 'x' => $x, 'y' => $y,
    ], $shapes);
}

/** Beschriftungs-Badge (abgerundetes Rechteck mit zentriertem Text). $text muss HTML-sicher sein. */
function svgLabel(
    string $canvasId, string $textId, float $x, float $y, float $width,
    string $class, string $text, float $fontSize = 3.5, ?float $rectWidth = null, array $rectAttrs = []
): string {
    $rectWidth = $rectWidth ?? $width;
    $attrs = ['height' => 6, 'id' => $canvasId, 'rx' => 2, 'ry' => 2, 'width' => $rectWidth, 'x' => 0, 'y' => 0];
    if ($class !== '') {
        $attrs['class'] = $class;
    }
    $rect = svgTag('rect', array_merge($attrs, $rectAttrs));
    $label = svgTag('text', [
        'fill' => '#ffffff', 'font-family' => 'Roboto, Verdana', 'font-size' => $fontSize,
        'id' => $textId, 'text-anchor' => 'middle', 'x' => $rectWidth / 2, 'y' => 4,
    ], $text);
    return svgTag('svg', ['height' => 6, 'width' => $width, 'x' => $x, 'y' => $y], svgTag('g', [], $rect . $label));
}

/** Richtungspfeil; Drehung und Farbe stammen aus dem Grafik-Zustand (Standard: Haus-Pfeil). */
function svgIndicator(string $name, float $x, float $y, array $st): string
{
    $transform  = $st["indicator-{$name}-rot"]['transform'] ?? 'rotate(0 7.5 7.5)';
    $colorClass = $st["indicator-{$name}-color"]['class']   ?? 'svg-orange-fill';

    $arrow = svgTag('polygon', ['class' => 'ind-outline', 'fill' => '#ffffff', 'points' => '1.5,0.5 13.5,0.5 7.5,15'])   // Rand folgt der Hintergrundfarbe
           . svgTag('polygon', ['class' => $colorClass, 'id' => "indicator-{$name}-color", 'points' => '2,0.0 13,0.0 7.5,13.5']);
    $group = svgTag('g', ['id' => "indicator-{$name}-rot", 'transform' => $transform], $arrow);
    return svgTag('svg', [
        'height' => 10, 'id' => "indicator-{$name}", 'viewBox' => '0 0 15 15', 'width' => 10, 'x' => $x, 'y' => $y,
    ], $group);
}

########################################################################################
## 4. Grafik-Zustand und SVG-Container
########################################################################################

// Boiler-Schiebeschalter mit 3 Positionen (Reihenfolge = Positionen von links nach rechts)
//   off  = Zwangs-aus (rotes Kreuz), auto = Standard, on = Zwangs-an (grüner Haken)
const BOILER_MODES = [   // Farben kommen aus index.css (Klassen svg-red, svg-grey, svg-green und die -stroke-Varianten)
    'off'  => ['label' => 'AUS',  'fill' => 'svg-red',   'stroke' => 'svg-red-stroke'],
    'auto' => ['label' => 'AUTO', 'fill' => 'svg-grey',  'stroke' => 'svg-grey-stroke'],
    'on'   => ['label' => 'AN',   'fill' => 'svg-green', 'stroke' => 'svg-green-stroke'],
];
const BOILER_CENTER_X = 99.5;   // horizontale Mitte des Boilers (Schalter und Pills sind darauf zentriert)
const BOILER_SWITCH_CY = 43.2;  // Schalter: Mittelpunkt (y)
const BOILER_ROW_Y    = 48;     // Zeile mit Leistungs- und Status-Pill (y)
const BOILER_POWER_W  = 10;     // Breite der Leistungs-Pill
const BOILER_STATE_W  = 9;      // Breite der Status-Pill
const BOILER_PILL_GAP = 1;      // Abstand zwischen den Pills
const BOILER_SWITCH_W = BOILER_POWER_W + BOILER_PILL_GAP + BOILER_STATE_W;   // Schalter so breit wie beide Pills

// Geräte-Kreise ("special_device"): Position n in $home_specials (config.php) gehört zu special_device_0n, also
//   $home_specials[0] -> 01 (Boiler), [1] -> 02 (Pumpe), [2] -> 03 (TV), [3] -> 04 (Drucker),
// siehe specialPosition(). Maßgeblich ist allein diese Reihenfolge in $home_specials, nicht die der Geräte in der XML.
// Alle Elemente eines Kreises tragen die Nummer in ihrer ID, z. B. text-special-device-04_a, image-special-device-04,
// ring-special-device-04. Auch die JSON-Werte verwenden diese IDs. 'label' = Lage des Leistungswerts in der Grafik.
const SPECIAL_DEVICES = [
    '01' => ['name' => 'Boiler',  'label' => [94.5, 48]],
    '02' => ['name' => 'Pumpe',   'label' => [98.5, 68]],
    '03' => ['name' => 'TV',      'label' => [68, 94],  'switch' => 'tv'],
    '04' => ['name' => 'Drucker', 'label' => [92, 94],  'switch' => 'printer'],
];
// 'switch' = Name des Geräts im Schaltbefehl (index.php?switch=on|off&device=<Name>). Nur Kreise mit diesem Eintrag haben eine
// Schaltfunktion (Tipp auf den Ring); die AIN kommt aus der Position des Kreises in $home_specials. Der Boiler (01) hat seinen
// eigenen Schalter (AUS / AUTO / AN), die Pumpe keine Schaltfunktion.

/** Restlaufzeit der Batterie (Laden bis voll bzw. Entladen bis leer) als Klartext. */
function estimateBatteryTime(int $batteryPower, int $soc, int $capacityWh): string
{
    if ($batteryPower === 0) {
        return '∞';
    }
    // Negative Leistung = Laden -> Zeit bis voll; positive = Entladen -> Zeit bis leer
    $energyWh = $batteryPower < 0
        ? $capacityWh * (100 - $soc) / 100
        : $capacityWh * $soc / 100;
    $minutes = (int)ceil($energyWh / abs($batteryPower) * 60);

    if ($minutes > 2880) {
        return '∞';
    }
    if ($minutes > 120) {
        return '~' . intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'min';
    }
    return '~' . $minutes . 'min';
}

/**
 * Berechnet alle veränderlichen Werte der Grafik (einzige Quelle für HTML und JSON).
 * Ergebnis: Element-ID => ['text' => Klartext] | ['class' => ...] | ['transform' => ...] | ['attrs' => [Attribut => Wert]]
 * Texte sind Klartext (ohne HTML); die Lampe gehört bewusst nicht dazu.
 */
function buildPictureState(array $p): array
{
    $st = [];

    // DC
    $st['text-dc']       = ['text' => "{$p['dc']} W"];
    $st['text-dc-small'] = ['text' => "{$p['dcSmall']} W"];

    // Batterie (positive Leistung = Entladen)
    $bat         = $p['battery'];
    $discharging = $bat > 0;
    $color       = $discharging ? 'orange' : 'green';
    $st['line-battery']             = ['class' => "svg-{$color}-stroke"];
    $st['canvas-battery']           = ['class' => "svg-{$color}"];
    $st['text-battery-cap']         = ['text'  => "{$p['batteryCapacity']} Wh"];
    $st['canvas-text-battery']      = ['class' => "svg-{$color}"];
    $st['text-battery']             = ['text'  => "{$bat} W"];
    $st['text-battery-soc']         = ['text'  => $p['soc'] . '%' . ($p['soc'] < 40 ? ' (' . BATTERY_MIN_PERCENT . '%)' : '')];
    $st['text-battery-cycles']      = ['text'  => "{$p['cycles']} Cycles"];
    $st['canvas-text-battery-time'] = ['class' => $discharging ? 'svg-orange-light' : 'svg-green-light'];
    $st['text-battery-time']        = ['text'  => estimateBatteryTime($bat, $p['soc'], $p['batteryCapacity'])];
    $st['indicator-battery-rot']    = ['transform' => 'rotate(' . ($discharging ? 180 : 0) . ' 7.5 7.5)'];
    $st['indicator-battery-color']  = ['class' => $discharging ? 'svg-orange-fill' : 'svg-green'];

    // Netz (positive Leistung = Bezug)
    $grid      = $p['grid'];
    $importing = $grid > 0;
    $color     = $importing ? 'orange' : 'green';
    $st['line-grid']             = ['class' => "svg-{$color}-stroke"];
    $st['canvas-grid']           = ['class' => "svg-{$color}"];
    $st['canvas-text-grid']      = ['class' => "svg-{$color}"];
    $st['text-grid']             = ['text'  => "{$grid} W"];
    $st['indicator-grid-rot']    = ['transform' => 'rotate(' . ($importing ? 0 : 180) . ' 7.5 7.5)'];
    $st['indicator-grid-color']  = ['class' => $importing ? 'svg-orange-fill' : 'svg-green'];

    // Haus
    $st['text-home'] = ['text' => "{$p['home']} W"];
    foreach ($p['specials'] as $i => $text) {
        $st["text-home-special-{$i}"] = ['text' => $text];
    }
    // Geräte-Kreise ("special_device"): Leistungswert und Symbol-Zustand je Nummer; die Zuordnung Nummer -> AIN
    // wurde bereits in collectDeviceData() über die Position in $home_specials festgelegt
    foreach (SPECIAL_DEVICES as $nr => $def) {
        $power = $p['special'][$nr]['power'] ?? null;   // null = AIN nicht konfiguriert oder nicht in der XML
        if ($power === null) {
            continue;
        }
        $st["text-special-device-{$nr}_a"] = ['text' => $power . 'W'];
        if ($nr !== '01') {                              // das Boiler-Symbol folgt dem Zwangs-aus-Modus (unten)
            $st["image-special-device-{$nr}"] = ['attrs' => iconAttrs($power <= 0)];   // Original bei Verbrauch, ausgegraut bei 0 W
        }
    }
    // Schaltbare Geräte (TV 03, Drucker 04): der zuletzt erfolgreich geschaltete Zustand gilt, bis neue Messdaten vorliegen
    foreach ($p['switches'] as $nr => $switch) {
        if ($switch['on'] !== null) {
            $st["image-special-device-{$nr}"] = ['attrs' => iconAttrs(!$switch['on'])];
        }
    }

    // Boiler-Schalter (nur wenn aktiviert): Knopf-Position und -Symbol, Pill, Bild ausgrauen bei Zwangs-aus
    if ($p['dectMode'] !== null) {
        $mode = $p['dectMode'];                      // 'off' | 'auto' | 'on'
        $st['image-special-device-01'] = ['attrs' => iconAttrs($mode === 'off')];   // Boiler-Symbol (Kreis 01)
        $st['boiler-switch']       = ['attrs' => ['data-mode' => $mode]];
        $st['boiler-knob']         = ['attrs' => ['transform' => boilerKnobTransform($mode)]];
        $st['boiler-knob-symbol']  = ['class' => BOILER_MODES[$mode]['stroke'], 'attrs' => boilerSymbol($mode)];
        $st['boiler-pill-bg']      = ['class' => BOILER_MODES[$mode]['fill']];
        $st['text-boiler-pill']    = ['text'  => BOILER_MODES[$mode]['label']];
    }

    return $st;
}

/**
 * Attribute für ein Symbol-Bild: ausgegraut (Filter icon-off + verringerte Deckkraft)
 * bzw. $onFilter (Standard: keiner) mit voller Deckkraft.
 */
function iconAttrs(bool $off, string $onFilter = 'none'): array
{
    return $off
        ? ['filter' => 'url(#icon-off)', 'opacity' => ICON_OFF_OPACITY]
        : ['filter' => $onFilter, 'opacity' => '1'];
}

/** Attribute eines Elements: feste Werte, überschrieben durch den Zustand (attrs). */
function attrsWithState(array $st, string $id, array $attrs): array
{
    return array_merge($attrs, $st[$id]['attrs'] ?? []);
}

/** Zugriffshelfer auf den Zustand (stText liefert HTML-escaped für die Ausgabe). */
function stText(array $st, string $id): string
{
    return h($st[$id]['text'] ?? '');
}

function stClass(array $st, string $id, string $default = ''): string
{
    return $st[$id]['class'] ?? $default;
}

/** x-Mitte einer Schalterposition ('off' links, 'auto' Mitte, 'on' rechts). */
function boilerSlotX(string $mode): float
{
    $index = array_search($mode, array_keys(BOILER_MODES), true);
    $start = BOILER_CENTER_X - BOILER_SWITCH_W / 2;
    return $start + BOILER_SWITCH_W / 3 * ($index + 0.5);
}

function boilerKnobTransform(string $mode): string
{
    return sprintf('translate(%s %s)', boilerSlotX($mode), BOILER_SWITCH_CY);
}

/** Form eines Symbols: Kreuz (Zwangs-aus), Punkt (Auto), Haken (Zwangs-an); die Farbe kommt aus BOILER_MODES. */
function boilerSymbol(string $mode): array
{
    $paths = [
        'off'  => ['d' => 'M-0.9 -0.9L0.9 0.9M-0.9 0.9L0.9 -0.9', 'stroke-width' => 0.7],   // Kreuz
        'auto' => ['d' => 'M0 0L0 0',                              'stroke-width' => 1.4],   // Punkt
        'on'   => ['d' => 'M-1.1 0.1L-0.3 0.9L1.2 -0.9',           'stroke-width' => 0.7],   // Haken
    ];
    return $paths[$mode];
}

/**
 * Positionen der Pills über dem Boiler. Mit Leistungs-Pill stehen beide nebeneinander
 * und sind gemeinsam auf BOILER_CENTER_X zentriert; ohne sie steht die Status-Pill mittig allein.
 */
function boilerLayout(array $st): array
{
    if (isset($st['text-special-device-01_a'])) {
        $start = BOILER_CENTER_X - (BOILER_POWER_W + BOILER_PILL_GAP + BOILER_STATE_W) / 2;
        return ['powerX' => $start, 'stateX' => $start + BOILER_POWER_W + BOILER_PILL_GAP];
    }
    return ['powerX' => null, 'stateX' => BOILER_CENTER_X - BOILER_STATE_W / 2];
}

const ICON_OFF_OPACITY  = '0.45';                   // Deckkraft ausgegrauter Symbole (Filter icon-off)

// Haus: Lampe, TV und Drucker sitzen in gleich großen, dünnen grünen Ringen (alle mit Schaltfunktion) und sind nur waagrecht mit dem Haus verbunden.
// Die Symbole (pics/*.svg, runde Plaketten von 10 x 10) werden auf DEVICE_ICON_SIZE skaliert und im Ring zentriert.
const HOME_CENTER       = [85, 85];          // Mittelpunkt des Haus-Kreises
const HOME_LINE_WIDTH   = 0.6;               // Linienstärke der dünnen Verbindungen
const DEVICE_RING_R     = 5;                 // Radius der Ringe
const DEVICE_ICON_SIZE  = 7;                 // Kantenlänge, in die das Symbol eingepasst wird
const LAMP_CENTER       = [65.5, 75];        // Mitte des Rings um die Lampe
const TV_CENTER         = [65.5, 93];        // gleiche x-Mitte wie die Lampe: beide Ringe liegen exakt übereinander
const PRINTER_CENTER    = [104.5, 93];       // spiegelbildlich zum TV (gleicher Abstand zur Haus-Mitte)

/**
 * DC-Bereich oben links: großes Symbol (Wechselrichter-Strings) und rechts daneben, überlappend,
 * ein kleines Symbol (Solar an der Steckdose). Beide Leitungen führen nach unten und dann nach rechts
 * in die Mitte und verlaufen parallel (großes Symbol auf y=45, kleines Symbol etwas höher auf y=40).
 */
function buildDcContainer(array $st): string
{
    // Leitungen zuerst, damit die Symbole darüber liegen
    $svg  = svgPolyline('line-dc',       'svg-green-stroke', '15,15 15,45 50,45');
    $svg .= svgPolyline('line-dc-small', 'svg-green-stroke', '32,15 32,40 50,40');

    // Symbole: das kleine liegt über dem großen; ein weißer Rand trennt die überlappenden Kreise
    $svg .= svgCircle('canvas-dc', 15, 15, 'svg-green');
    $svg .= svgCircle('canvas-dc-small', 32, 15, 'svg-green dc-outline', 8, ['stroke' => '#ffffff', 'stroke-width' => 0.8]);   // Rand folgt der Hintergrundfarbe
    $svg .= svgIcon('shape-dc', 'dc', 2.5, 2.5, 24);
    $svg .= svgIcon('shape-dc-small', 'dc', 25, 8, 14);

    // Werte auf den Leitungen
    $svg .= svgLabel('canvas-text-dc',       'text-dc',       18, 42, 16, 'svg-green', stText($st, 'text-dc'));
    $svg .= svgLabel('canvas-text-dc-small', 'text-dc-small', 24, 27, 16, 'svg-green', stText($st, 'text-dc-small'));
    return svgTag('g', ['id' => 'DcContainer'], $svg);
}

function buildBatteryContainer(array $st): string
{
    $svg  = svgPolyline('line-battery', stClass($st, 'line-battery'), '15,85 15,55 50,55');
    $svg .= svgCircle('canvas-battery', 15, 85, stClass($st, 'canvas-battery'));
    $svg .= svgTag('text', [
        'fill' => '#ffffff', 'font-family' => 'Roboto, Verdana', 'font-size' => 3.5,
        'id' => 'text-battery-cap', 'text-anchor' => 'middle', 'x' => 15, 'y' => 96,
    ], stText($st, 'text-battery-cap'));
    $svg .= svgIcon('shape-battery', 'battery', 2.5, 72.5, 25);
    $svg .= svgLabel('canvas-text-battery',        'text-battery',        18, 52, 16, stClass($st, 'canvas-text-battery'), stText($st, 'text-battery'));
    $svg .= svgLabel('canvas-text-battery-soc',    'text-battery-soc',    20, 72, 26, 'svg-grey', stText($st, 'text-battery-soc'));
    $svg .= svgLabel('canvas-text-battery-cycles', 'text-battery-cycles', 20, 80, 26, 'svg-grey', stText($st, 'text-battery-cycles'));
    $svg .= svgLabel('canvas-text-battery-time',   'text-battery-time',   20, 88, 26, stClass($st, 'canvas-text-battery-time'), stText($st, 'text-battery-time'));
    $svg .= svgIndicator('battery', 10, 58, $st);
    return svgTag('g', ['id' => 'BatteryContainer'], $svg);
}

/**
 * Netz-Bereich oben rechts: kleinerer Kreis (2/3 der früheren Größe). Die Leitung führt von der Mitte
 * zuerst nach oben und dann nach rechts in den Kreis; Wert und Richtungspfeil liegen darauf.
 */
function buildGridContainer(array $st): string
{
    $r    = 14 * 2 / 3;     // Radius des Kreises (früher 14)
    $icon = 25 * 2 / 3;     // Größe des Blitz-Icons (früher 25)

    $svg  = svgPolyline('line-grid', stClass($st, 'line-grid'), '85,15 50,15 50,45');
    $svg .= svgCircle('canvas-grid', 85, 15, stClass($st, 'canvas-grid'), $r);
    $svg .= svgLabel('canvas-text-grid', 'text-grid', 56, 12, 16, stClass($st, 'canvas-text-grid'), stText($st, 'text-grid'));
    $svg .= svgIcon('shape-grid', 'grid', 85 - $icon / 2, 14.5 - $icon / 2, $icon);
    $svg .= svgIndicator('grid', 45, 22, $st);
    return svgTag('g', ['id' => 'GridContainer'], $svg);
}

/**
 * Stern, der sich im Uhrzeigersinn auf dem Ring bewegt (Wartezustand). Wird per index.js (display) ein- und
 * ausgeblendet; die Drehung läuft per SMIL um den Ring-Mittelpunkt.
 */
function buildRingStar(string $name, array $center): string
{
    list($cx, $cy) = $center;
    $outer = 1.15;
    $inner = 0.5;
    $points = [];
    for ($i = 0; $i < 10; $i++) {
        $r = $i % 2 === 0 ? $outer : $inner;
        $a = M_PI * $i / 5;                       // Spitze zeigt nach oben
        $points[] = round($r * sin($a), 3) . ',' . round(-$r * cos($a), 3);
    }
    $star = svgTag('polygon', [
        'class' => 'ring-star', 'points' => implode(' ', $points),
        'transform' => sprintf('translate(%s %s)', $cx, $cy - (DEVICE_RING_R - 0.25)),   // oben auf dem Ring
    ]);
    $spin = svgTag('animateTransform', [
        'attributeName' => 'transform', 'type' => 'rotate', 'from' => "0 {$cx} {$cy}", 'to' => "360 {$cx} {$cy}",
        'dur' => '1.4s', 'repeatCount' => 'indefinite',
    ]);
    return svgTag('g', ['id' => "star-{$name}", 'display' => 'none'], svgTag('g', [], $spin . $star));
}

/**
 * Symbol (Lampe, TV, Drucker) in einem dünnen grünen Ring. Alle sehen gleich aus; das Bild wird in den Ring
 * eingepasst (Seitenverhältnis bleibt erhalten). Mit $onclick wird der Ring zur Tippfläche (Lampe, TV, Drucker).
 * Mit $busyStar gibt es zusätzlich einen Stern für den Wartezustand (siehe buildRingStar).
 *
 * @param array $center     [x, y] Mitte des Rings
 * @param array $imageAttrs weitere Attribute des Bildes (href, id, Filter ...)
 */
function buildRingedIcon(string $name, array $center, array $imageAttrs, ?string $onclick = null, bool $busyStar = false): string
{
    list($cx, $cy) = $center;
    $half = DEVICE_ICON_SIZE / 2;

    $svg = svgTag('circle', [
        'id' => "ring-{$name}", 'cx' => $cx, 'cy' => $cy, 'r' => DEVICE_RING_R - 0.25,
        'class' => 'svg-green-stroke dev-ring', 'fill' => 'none', 'stroke-width' => 0.5,
    ]);
    if ($onclick !== null) {
        // Unsichtbare Tippfläche über den ganzen Ring
        $svg .= svgTag('circle', [
            'id' => "hit-{$name}", 'cx' => $cx, 'cy' => $cy, 'r' => DEVICE_RING_R, 'fill' => 'transparent',
            'onclick' => $onclick, 'style' => 'cursor:pointer;',
        ]);
    }
    $svg .= svgTag('image', [
        'class' => 'dev-icon', 'x' => $cx - $half, 'y' => $cy - $half, 'width' => DEVICE_ICON_SIZE, 'height' => DEVICE_ICON_SIZE,
    ] + $imageAttrs);
    return $svg . ($busyStar ? buildRingStar($name, $center) : '');
}

/**
 * @param int   $specialCount Anzahl der Zusatzwerte (Texte stehen im Zustand)
 * @param array $switches     je Kreis-Nummer (03 TV, 04 Drucker): 'enabled' = Kreis hat in $home_specials eine AIN, also Schaltfunktion
 * @param ?bool $lampOn       Zustand der Shelly-Lampe nur für die erste Darstellung
 *                            (null = unbekannt, wird als "aus" gezeigt)
 */
function buildHomeContainer(array $st, int $specialCount, ?bool $lampOn, array $switches = []): string
{
    $svg  = svgPolyline('line-home', 'svg-orange-stroke', '85,85 85,55 50,55');

    // Dünne grüne Verbindungen: nur waagrecht, vom Ring bis zur Mitte des Haus-Kreises
    // (liegen unter Haus-Kreis und Ringen, sichtbar bleibt das Stück dazwischen)
    foreach (['lamp' => LAMP_CENTER, 'special-device-03' => TV_CENTER, 'special-device-04' => PRINTER_CENTER] as $name => $center) {
        $towardsHome = $center[0] < HOME_CENTER[0] ? 1 : -1;
        $from        = $center[0] + $towardsHome * (DEVICE_RING_R - 0.4);   // beginnt unter dem Ringrand
        $svg .= svgPolyline(
            "line-home-{$name}", 'svg-green-stroke',
            sprintf('%s,%s %s,%s', $from, $center[1], HOME_CENTER[0], $center[1]), HOME_LINE_WIDTH
        );
    }

    $svg .= svgCircle('canvas-home', 85, 85, 'svg-green');
    $svg .= svgLabel('canvas-text-home', 'text-home', 66, 52, 16, 'svg-orange-fill', stText($st, 'text-home'));
    $svg .= svgIcon('shape-home', 'home', 72.5, 72, 25);

    // Gerätesymbole ohne Ring
    $deviceImages = [
        ['pics/pic_boiler.svg', 92, 53, 'image-special-device-01'],   // Boiler: wird bei Zwangs-aus ausgegraut (icon-off)
        ['pics/pic_pumpe.svg',  90, 66, 'image-special-device-02'],   // Pumpe: grün bei Verbrauch, grau bei 0 W
    ];
    foreach ($deviceImages as list($href, $x, $y, $id)) {
        $svg .= svgTag('image', attrsWithState($st, $id, ['id' => $id, 'class' => 'dev-icon', 'href' => $href, 'x' => $x, 'y' => $y]));
    }

    // TV und Drucker: wie die Lampe im Ring; Original (grün) bei Verbrauch, grau bei 0 W. Mit Schaltfunktion (Tipp auf den Ring),
    // wenn für den Kreis eine AIN in $home_specials steht; der Stern zeigt, dass die Anfrage läuft.
    $ringed = ['03' => [TV_CENTER, 'pics/pic_tv.svg'], '04' => [PRINTER_CENTER, 'pics/pic_printer.svg']];
    foreach ($ringed as $nr => list($center, $href)) {
        $slot       = "special-device-{$nr}";
        $switchable = !empty($switches[$nr]['enabled']);
        $imageAttrs = attrsWithState($st, "image-{$slot}", ['id' => "image-{$slot}", 'href' => $href]);
        if ($switchable) {
            $imageAttrs['style'] = 'pointer-events:none;';   // Klicks gehen an die Tippfläche des Rings
        }
        $svg .= buildRingedIcon(
            $slot, $center, $imageAttrs,
            $switchable ? "toggleSpecialDevice('{$slot}', '" . SPECIAL_DEVICES[$nr]['switch'] . "');" : null, $switchable
        );
    }

    // Shelly-Lampe im Ring (klickbar; Zustand wird nicht per XHR aktualisiert, aus = ausgegraut, index.js führt das nach)
    if (SHELLY_STATUS_URL !== '') {
        $svg .= buildRingedIcon('lamp', LAMP_CENTER, [
            'id'      => 'lamp_01',
            'href'    => $lampOn ? 'pics/pic_bulp_on.svg' : 'pics/pic_bulp_off.svg',
            'onclick' => 'toggleLamp(' . ($lampOn ? 'false' : 'true') . ');',
            'style'   => 'cursor:pointer;',
        ] + iconAttrs(!$lampOn), 'clickLamp();');
    }

    // Zusatzwerte links (erste 3) und rechts (ab dem 4.)
    for ($i = 0; $i < $specialCount; $i++) {
        $x = $i < 3 ? 61 : 85;
        $y = 72 + (($i < 3 ? $i : $i - 3) * 8);
        $svg .= svgLabel("canvas-text-home-special_{$i}", "text-home-special-{$i}", $x, $y, 20, 'svg-grey', stText($st, "text-home-special-{$i}"), 2.5);
    }

    // Kurzwerte an den Gerätesymbolen
    foreach (SPECIAL_DEVICES as $nr => $def) {
        if (isset($st["text-special-device-{$nr}_a"])) {
            list($x, $y) = $def['label'];
            if ($nr === '01' && isset($st['boiler-switch'])) {   // neben der Status-Pill, mittig über dem Boiler
                $x = boilerLayout($st)['powerX'];
                $y = BOILER_ROW_Y;
            }
            $svg .= svgLabel(
                "canvas-text-special-device_{$nr}_a", "text-special-device-{$nr}_a",
                $x, $y, 20, 'svg-grey', stText($st, "text-special-device-{$nr}_a"), 2.5, BOILER_POWER_W
            );
        }
    }

    if (isset($st['boiler-switch'])) {
        $svg .= buildBoilerControl($st);
    }

    $svg .= svgIndicator('home', 80, 58, $st);
    return svgTag('g', ['id' => 'homeContainer'], $svg);
}

/**
 * Boiler-Schiebeschalter mit 3 Positionen (AUS / AUTO / AN), so breit wie die beiden Pills darunter,
 * und die Status-Pill neben der Leistungs-Pill. Ein Tipp auf eine Position wählt sie aus.
 */
function buildBoilerControl(array $st): string
{
    $start = BOILER_CENTER_X - BOILER_SWITCH_W / 2;
    $cy    = BOILER_SWITCH_CY;
    $modes = array_keys(BOILER_MODES);

    // Schiene
    $svg = svgTag('rect', [
        'id' => 'boiler-track', 'class' => 'sw-track', 'x' => $start, 'y' => $cy - 3.2, 'width' => BOILER_SWITCH_W, 'height' => 6.4, 'rx' => 3.2,
        'fill' => '#cccccc', 'stroke' => '#b3b3b3', 'stroke-width' => 0.3,
    ]);

    // Blasse Markierungen der drei Positionen (die aktive liegt unter dem Knopf)
    foreach ($modes as $mode) {
        $svg .= svgTag('path', [
            'class' => BOILER_MODES[$mode]['stroke'],
            'transform' => sprintf('translate(%s %s)', boilerSlotX($mode), $cy),
            'opacity' => 0.45, 'fill' => 'none', 'stroke-linecap' => 'round',
        ] + boilerSymbol($mode));
    }

    // Knopf mit Symbol der aktiven Position
    $symbol = svgTag('path', attrsWithState($st, 'boiler-knob-symbol', [
        'id' => 'boiler-knob-symbol', 'class' => stClass($st, 'boiler-knob-symbol', BOILER_MODES['auto']['stroke']),
        'fill' => 'none', 'stroke-linecap' => 'round',
    ] + boilerSymbol('auto')));
    // Lade-Ring um den Knopf: dreht sich, solange eine Anfrage läuft (Anzeige wird per index.js ein-/ausgeblendet)
    $spinner = svgTag('path', [
        'id' => 'boiler-spinner', 'd' => 'M0 -2.95A2.95 2.95 0 0 1 2.95 0', 'fill' => 'none',
        'class' => 'sw-spinner', 'stroke-width' => 0.6, 'stroke-linecap' => 'round', 'display' => 'none',
    ], svgTag('animateTransform', [
        'attributeName' => 'transform', 'type' => 'rotate', 'from' => '0 0 0', 'to' => '360 0 0',
        'dur' => '0.8s', 'repeatCount' => 'indefinite',
    ]));
    $knob = svgTag('circle', ['class' => 'sw-knob', 'r' => 2.55, 'fill' => '#ffffff', 'stroke' => '#9a9d99', 'stroke-width' => 0.3]) . $symbol . $spinner;
    $svg .= svgTag('g', attrsWithState($st, 'boiler-knob', [
        'id' => 'boiler-knob', 'transform' => boilerKnobTransform('auto'),
    ]), $knob);

    // Tippflächen: je ein Drittel der Schiene
    foreach ($modes as $i => $mode) {
        $svg .= svgTag('rect', [
            'x' => $start + $i * BOILER_SWITCH_W / 3, 'y' => $cy - 4.2, 'width' => BOILER_SWITCH_W / 3, 'height' => 8.4,
            'fill' => 'transparent', 'data-mode' => $mode, 'onclick' => "setBoilerMode('{$mode}');", 'style' => 'cursor:pointer;',
        ]);
    }
    $control = svgTag('g', attrsWithState($st, 'boiler-switch', ['id' => 'boiler-switch', 'data-mode' => 'auto']), $svg);

    // Status-Pill im Stil der Leistungs-Pill (gleiche Höhe, Schrift und Rundung); Farbklasse aus dem Zustand
    $control .= svgLabel(
        'boiler-pill-bg', 'text-boiler-pill', boilerLayout($st)['stateX'], BOILER_ROW_Y, BOILER_STATE_W,
        stClass($st, 'boiler-pill-bg', BOILER_MODES['auto']['fill']), stText($st, 'text-boiler-pill'), 2.5
    );
    return svgTag('g', ['id' => 'boiler-control'], $control);
}

/** Gesamtbild: Wechselrichter in der Mitte, DC/Batterie/Netz/Haus außen. */
function buildInverterPicture(array $p, array $st, ?bool $lampOn): string
{
    // Filter für Symbole: icon-off = ausgegraut (Boiler bei Zwangs-aus, Geräte bei 0 W, Lampe aus).
    // Aktive Symbole bleiben unverändert: die Plaketten in pics/*.svg sind bereits grün.
    $svg  = '<defs><filter id="icon-off"><feColorMatrix type="saturate" values="0"></feColorMatrix></filter></defs>';
    $svg .= buildDcContainer($st);
    $svg .= buildBatteryContainer($st);
    $svg .= buildGridContainer($st);
    $svg .= buildHomeContainer($st, count($p['specials']), $lampOn, $p['switches']);
    $svg .= svgCircle('canvas-inverter', 50, 50, 'svg-green');
    $svg .= svgIcon('shape-inverter', 'inverter', 37.5, 37.5, 25);

    $root = '<svg _ngcontent-c4="" class="mainsvg" height="75%" id="liveSVG" viewBox="0 0 110 100" width="100%">'
          . $svg . '</svg>';

    // Banner für Fehler der Live-Aktualisierung (siehe Skript unten, Aussehen in index.css); liegt über dem ganzen Bild
    $banner = "<div id='picture-banner' class='picture-banner' role='status' aria-live='polite'></div>";

    return "<div _ngcontent-c4='' class='energy-picture'><div _ngcontent-c4='' id='test'></div>"
         . $root . $banner . '</div>';
}

########################################################################################
## 5. Datenquellen
########################################################################################

/**
 * Zerlegt die Wechselrichter-Rohdaten ("{'Key': 1.0, ...}") in ein Key=>Wert-Array.
 */
function parseInverterData(string $raw): array
{
    $pos = strpos($raw, '{');
    $raw = substr($raw, $pos === false ? 0 : $pos);
    $raw = str_replace('{', ',', $raw);
    $raw = str_replace(["'", ' ', '}'], '', $raw);

    $values = [];
    foreach (explode(',', $raw) as $pair) {
        $parts = explode(':', $pair);
        if (count($parts) >= 2) {
            $values[trim($parts[0])] = $parts[1];
        }
    }
    return $values;
}

/**
 * Liest den Wechselrichter.
 * @return array|null ['lines' => Textzeilen, plus Werte für das SVG-Bild]
 */
function loadInverter(string $path, array &$errors): ?array
{
    $raw = readDataFile($path, $errors);
    if ($raw === null) {
        return null;
    }
    $v     = parseInverterData($raw);
    $float = function (string $key) use ($v): float { return isset($v[$key]) ? (float)$v[$key] : 0.0; };
    $trunc = function (string $key) use ($float): int { return (int)$float($key); };
    $ceil  = function (string $key) use ($float): int { return (int)ceil($float($key)); };
    $text  = function (string $key) use ($v): string { return h($v[$key] ?? ''); };

    $lines = [
        'Verbrauch von Wechselrichter erfüllt: ' . $trunc('HomeOwn_P') . ' W',
        ' -> ' . $trunc('HomeBat_P') . ' W aus der Batterie',
        ' -> ' . $trunc('HomePv_P') . ' W aus der Solaranlage',
        ' -> ' . $trunc('HomeGrid_P') . ' W aus dem Netz',
        'Batterie',
        ' -> Lade Batterie mit ' . $trunc('PV2Bat_P') . ' W und entlade mit ' . $trunc('P') . ' W',
        ' -> Ladestand: ' . $text('SoC') . '%',
        ' -> Ladecyclen: ' . $text('Cycles') . ' ',
        ' -> Kapazität: ' . $text('WorkCapacity') . ' Wh',
        ' -> ChargeCap: ' . $text('FullChargeCap_E'),
        '',
    ];

    return [
        'lines'           => $lines,
        'pvPower'         => (int)ceil($float('P_pv1') + $float('P_pv2')),
        'battery'         => $ceil('P'),
        'grid'            => $ceil('HomeGrid_P'),
        'homePower'       => $ceil('Home_P'),
        'soc'             => $ceil('SoC'),
        'cycles'          => (int)$float('Cycles'),
        'batteryCapacity' => $ceil('WorkCapacity'),
    ];
}

/** Liest einen Smartmeter-Wert aus "zeitstempel;XXwert" (die ersten 2 Zeichen des Werts sind ein Präfix). */
function readSmartValue(string $path, array &$errors): ?string
{
    $raw = readDataFile($path, $errors);
    if ($raw === null) {
        return null;
    }
    $parts = explode(';', $raw);
    if (!isset($parts[1])) {
        $errors[] = 'Unerwartetes Format: ' . $path;
        return null;
    }
    return trim(substr($parts[1], 2));
}

/**
 * Liest das Smartmeter.
 * @return array|null ['power' => string, 'consumption' => string, 'feedIn' => string]
 */
function loadSmartMeter(array $cfg, array &$errors): ?array
{
    $power       = readSmartValue($cfg['smart_power'], $errors);
    $consumption = readSmartValue($cfg['smart_consumption'], $errors);
    $feedIn      = readSmartValue($cfg['smart_feed_in'], $errors);

    if ($power === null || $consumption === null || $feedIn === null) {
        return null;
    }
    return ['power' => $power, 'consumption' => $consumption, 'feedIn' => $feedIn];
}

/** Textzeilen für das Smartmeter, aufgeteilt nach Abschnitt. */
function describeSmartMeter(array $smart): array
{
    $color = (substr($smart['power'], 0, 1) === '-') ? 'green-txt' : 'red-txt';   // Einspeisung grün, Bezug rot
    $power = "<span class='{$color}'>" . h($smart['power']) . ' W</span>';

    return [
        'power'       => ["<a href='index_smart.php'>Smartmeter</a>: {$power}"],
        'consumption' => [
            'Smartmeter Verbrauch: '   . (int)((float)$smart['consumption'] / SCALE) . ' kWh',
            'Smartmeter Einspeisung: ' . (int)((float)$smart['feedIn'] / SCALE) . ' kWh',
        ],
        'details'     => [
            '<b>Details Smartmeter:</b>',
            "Leistung: {$power}",
            'Verbrauch: ' . (int)$smart['consumption'] . ' Wh',
            'Einspeise: ' . (int)$smart['feedIn'] . ' Wh',
            '',
        ],
    ];
}

/**
 * Wertet die FRITZ!DECT-XML aus (Gerätetyp 1 = Steckdose mit Messung, 2 = nur Temperatur).
 */
function collectDeviceData($xml, array $cfg): array
{
    $result = [
        'temps' => [], 'power' => [], 'consumption' => [], 'details' => [],
        'pvSmall' => 0, 'specials' => [], 'special' => [],
    ];
    foreach (SPECIAL_DEVICES as $nr => $def) {
        $result['special'][$nr] = ['ain' => null, 'name' => null, 'power' => null];
    }
    if (!$xml) {
        return $result;
    }

    $byAin = [];   // normalisierte AIN -> ['name' => ..., 'power' => W oder null (Gerät ohne Messung)]
    foreach ($xml->device as $device) {
        $type = (string)$device['type'];
        if ($type !== '1' && $type !== '2') {
            continue;
        }
        $rawName = (string)$device['name'];
        $name    = h($rawName);
        $ain  = (string)$device['ain'];
        $temp = h($device->temp);

        $result['temps'][]   = "{$name}: {$temp} °C";
        $result['details'][] = "<b>Details {$name} (AIN=" . h($ain) . '):</b>';
        $result['details'][] = "Temperatur: {$temp} °C";

        $power = null;
        if ($type === '1') {
            $power       = (float)$device->strom / SCALE;
            $consumption = (float)$device->verbrauch / SCALE;

            $result['power'][]       = "{$name}: {$power} W";
            $result['consumption'][] = "{$name}: " . (int)$consumption . ' kWh';
            $result['details'][]     = "Leistung: {$power} W";
            $result['details'][]     = "Verbrauch: {$consumption} kWh";
        }
        $key = normalizeAin($ain);
        if (!isset($byAin[$key]) || $power !== null) {   // ein Eintrag mit Messwert wird nicht durch einen ohne Messwert ersetzt
            $byAin[$key] = ['name' => $rawName, 'power' => $power];
        }
        $result['details'][] = '';
    }

    if ($cfg['use_inverter']) {
        // Solar-Steckdose: AIN direkt aus der Konfiguration
        if ($cfg['dect_solar'] !== null) {
            $solar = $byAin[normalizeAin($cfg['dect_solar'])] ?? null;
            if ($solar !== null && $solar['power'] !== null) {
                $result['pvSmall'] = (int)$solar['power'];
            }
        }
        // Zusatzwerte links/rechts: in der Reihenfolge von $home_show_data (nicht der XML)
        foreach ($cfg['home_show_data'] as $ain) {
            $d = $byAin[normalizeAin($ain)] ?? null;
            if ($d !== null && $d['power'] !== null) {
                $result['specials'][] = "{$d['name']}: " . (int)ceil($d['power']) . 'W';
            }
        }
        // Geräte-Kreise: Der Wert wird zu special_device_NN geschrieben, wobei NN die Position der AIN in $home_specials ist
        // (Position 1 -> 01 ...). Gesucht wird die AIN in der XML, nicht die Position dort. Fehlt ein Gerät in der XML,
        // bleibt nur sein Kreis leer; die anderen verschieben sich nicht.
        foreach (SPECIAL_DEVICES as $nr => $def) {
            $ain = $cfg['home_specials'][specialPosition($nr)] ?? null;
            $d   = $ain === null ? null : ($byAin[normalizeAin($ain)] ?? null);
            $result['special'][$nr] = [
                'ain'   => $ain,
                'name'  => $d['name'] ?? null,
                'power' => ($d !== null && $d['power'] !== null) ? (int)ceil($d['power']) : null,
            ];
        }
    }
    return $result;
}

/** Fragt den Lampenzustand beim Shelly ab (kurzer Timeout, da die Abfrage jede Sekunde läuft). */
function fetchLampState(string $url): ?bool
{
    if ($url === '') {
        return null;
    }
    $context = stream_context_create(['http' => ['timeout' => 1]]);
    $payload = @file_get_contents($url, false, $context);
    if ($payload === false) {
        return null;
    }
    $data = json_decode($payload, true);
    return isset($data['switch:0']['output']) ? (bool)$data['switch:0']['output'] : null;
}

// --- FRITZ!Box-Steckdose (Boiler Zwangs-aus / Zwangs-an) --------------------------------

/**
 * Zugangsdaten der FRITZ!Box aus dect_overwrite.config.php (Variablen $fritz_url, $fritz_user, $fritz_password,
 * $fritz_ain, $fritz_verify_ssl, $fritz_timeout). Null, wenn die Datei fehlt.
 * 'ain' ist die AIN der Boiler-Steckdose; andere Steckdosen übergeben ihre AIN direkt an fritzSwitch().
 */
function fritzConfig(): ?array
{
    $file = __DIR__ . '/dect_overwrite.config.php';
    if (!is_readable($file)) {
        return null;
    }
    require $file;   // setzt die $fritz_*-Variablen lokal in dieser Funktion
    return [
        'url'        => $fritz_url ?? '',
        'user'       => $fritz_user ?? '',
        'password'   => $fritz_password ?? '',
        'ain'        => $fritz_ain ?? '',
        'verify_ssl' => $fritz_verify_ssl ?? false,
        'timeout'    => $fritz_timeout ?? 5,
    ];
}

/** HTTP-GET mit Zeitlimit; liefert den Antworttext oder null bei Fehler. */
function fritzGet(string $url, array $c): ?string
{
    $context = stream_context_create([
        'http' => ['timeout' => $c['timeout'], 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => (bool)$c['verify_ssl'], 'verify_peer_name' => (bool)$c['verify_ssl']],
    ]);
    $body = @file_get_contents($url, false, $context);
    return $body === false ? null : $body;
}

/** Liest SID und Challenge aus der XML-Antwort von login_sid.lua. */
function fritzParseSession(?string $xmlText): ?array
{
    $xml = $xmlText === null ? false : @simplexml_load_string($xmlText);
    if ($xml === false) {
        return null;
    }
    return ['sid' => (string)$xml->SID, 'challenge' => (string)$xml->Challenge];
}

/** Berechnet die Login-Antwort (PBKDF2 ab FRITZ!OS 7.24, sonst MD5 für ältere Versionen). */
function fritzResponse(string $challenge, string $password): string
{
    if (strpos($challenge, '2$') === 0) {
        // PBKDF2: "2$<Iterationen1>$<Salt1>$<Iterationen2>$<Salt2>"
        list(, $iter1, $salt1, $iter2, $salt2) = explode('$', $challenge);
        $hash1 = hash_pbkdf2('sha256', $password, hex2bin($salt1), (int)$iter1, 0, true);
        $hash2 = hash_pbkdf2('sha256', $hash1, hex2bin($salt2), (int)$iter2, 0, true);
        return $salt2 . '$' . bin2hex($hash2);
    }
    // Älteres Verfahren: MD5 über "<Challenge>-<Kennwort>" in UTF-16LE
    $text  = $challenge . '-' . $password;
    $utf16 = function_exists('mb_convert_encoding')
        ? mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')
        : iconv('UTF-8', 'UTF-16LE', $text);
    return $challenge . '-' . md5($utf16);
}

/** Meldet sich an der FRITZ!Box an; liefert die Session-ID oder null. */
function fritzLogin(array $c): ?string
{
    $emptySid = '0000000000000000';

    $session = fritzParseSession(fritzGet($c['url'] . '/login_sid.lua?version=2', $c));
    if ($session === null || $session['challenge'] === '') {
        return null;
    }
    $response = fritzResponse($session['challenge'], $c['password']);
    $session  = fritzParseSession(fritzGet(
        $c['url'] . '/login_sid.lua?version=2&username=' . rawurlencode($c['user']) . '&response=' . rawurlencode($response),
        $c
    ));
    if ($session === null || $session['sid'] === '' || $session['sid'] === $emptySid) {
        return null;   // falsche Zugangsdaten
    }
    return $session['sid'];
}

/** Beendet die Session (Aufräumen, Fehler sind unkritisch). */
function fritzLogout(string $sid, array $c): void
{
    fritzGet($c['url'] . '/login_sid.lua?version=2&logout=1&sid=' . rawurlencode($sid), $c);
}

/**
 * Schaltet die Steckdose mit der AIN $ain ein oder aus (Leerzeichen in der AIN sind egal).
 * Liefert null bei Erfolg, sonst eine Fehlermeldung.
 */
function fritzSwitch(bool $on, string $ain, array $c): ?string
{
    $sid = fritzLogin($c);
    if ($sid === null) {
        return 'Anmeldung an der FRITZ!Box fehlgeschlagen';
    }
    $url = $c['url'] . '/webservices/homeautoswitch.lua'
         . '?ain=' . rawurlencode(str_replace(' ', '', $ain))
         . '&switchcmd=' . ($on ? 'setswitchon' : 'setswitchoff')
         . '&sid=' . rawurlencode($sid);
    $answer = fritzGet($url, $c);
    fritzLogout($sid, $c);

    if ($answer === null) {
        return 'Keine Antwort von der FRITZ!Box';
    }
    // Die FRITZ!Box antwortet mit dem neuen Schaltzustand: 1 = an, 0 = aus ("inval" = AIN unbekannt)
    if (trim($answer) !== ($on ? '1' : '0')) {
        return 'Steckdose nicht geschaltet (Antwort: ' . trim($answer) . ')';
    }
    return null;
}

/** Datei, in der der zuletzt per Dashboard geschaltete Zustand einer Steckdose steht. */
function switchStateFile(string $ain): string
{
    return SWITCH_STATE_DIR . '/switch_' . preg_replace('/\D/', '', $ain) . '.state';
}

/** Merkt sich den Zustand ("on" | "off") nach erfolgreichem Schalten (atomar: temporäre Datei, dann umbenennen). */
function saveSwitchState(string $ain, string $state): bool
{
    $file = switchStateFile($ain);
    $tmp  = $file . '.' . getmypid();
    if (@file_put_contents($tmp, $state, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Zuletzt geschalteter Zustand für die Anzeige: true = an, false = aus. Null, wenn nichts gemerkt ist oder die
 * Messdaten (XML) seither neu geschrieben wurden; dann gilt wieder der gemessene Verbrauch.
 */
function readSwitchState(string $ain, $dataMtime): ?bool
{
    $file = switchStateFile($ain);
    if ($ain === '' || !is_readable($file)) {
        return null;
    }
    if ($dataMtime && @filemtime($file) < $dataMtime) {
        return null;   // neuere Messdaten zeigen den tatsächlichen Zustand
    }
    $state = trim((string)@file_get_contents($file));
    return $state === 'on' ? true : ($state === 'off' ? false : null);
}

/**
 * XHR-Endpunkt ?switch=on|off[&device=boiler|tv|printer]: schaltet eine Steckdose über die FRITZ!Box.
 * Die AIN kommt aus der Konfiguration (Boiler: dect_overwrite.config.php; TV, Drucker: Position des Kreises in $home_specials),
 * nie vom Browser. Beim Boiler setzt danach dect_overwrite.php die Marker-Dateien (index.js ruft beide nacheinander auf);
 * bei TV und Drucker wird der Zustand für die Anzeige gemerkt. Antwort: JSON {"plug":"on|off"} bzw. {"error":"..."} (HTTP 400/502).
 */
function handleSwitchRequest(string $state, string $device, array $cfg): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $slot = $device === 'boiler' ? null : switchSlot($device);   // Kreis-Nummer des Geräts (null = Boiler)
    if (($state !== 'on' && $state !== 'off') || ($device !== 'boiler' && $slot === null)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unbekannter Aufruf'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $config = fritzConfig();
    $ain    = $slot === null ? ($config['ain'] ?? '') : specialAin($cfg, $slot);
    if ($config === null) {
        $error = 'Zugangsdaten fehlen (dect_overwrite.config.php)';
    } elseif ($ain === '') {
        $error = $slot === null
            ? 'AIN des Boilers fehlt ($fritz_ain)'
            : 'AIN fehlt für ' . SPECIAL_DEVICES[$slot]['name'] . ' (Position ' . (specialPosition($slot) + 1) . ' in $home_specials)';
    } else {
        $error = fritzSwitch($state === 'on', $ain, $config);
        if ($error === null && $slot !== null && !saveSwitchState($ain, $state)) {
            $error = 'Zustand konnte nicht gespeichert werden';
        }
    }
    if ($error !== null) {
        http_response_code(502);
        echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode(['plug' => $state]);
}

########################################################################################
## 6. HTML-Renderer
########################################################################################

function renderNavigation(array $cfg, bool $showCharts, string $standText): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $self = $_SERVER['SCRIPT_NAME'] ?? 'index.php';

    $items = NAV_ITEMS;
    if ($cfg['use_webcam']) {
        $items[] = ['label' => 'Webcams', 'href' => "https://{$host}/reo_live.php", 'icon' => 'pics/icon_cams.svg'];
    }
    $items[] = ['label' => 'Links', 'href' => "https://{$host}/index_links.php", 'icon' => 'pics/icon_links.svg'];
    $items[] = $showCharts
        ? ['label' => 'Zurück', 'href' => $self,              'icon' => 'pics/icon_back.svg']
        : ['label' => 'Charts', 'href' => $self . '?charts=yes', 'icon' => 'pics/icon_charts.svg'];

    // Schlanke Leiste: Symbole links (mit Innenabstand als größere Tippfläche), Stand rechts in derselben Zeile
    $html = "<div class='nav-bar'>\n";
    foreach ($items as $item) {
        $html .= "<a href='" . h($item['href']) . "' title='" . h($item['label']) . "' class='nav-link'>"
               . "<img class='nav-icon' src='" . h($item['icon']) . "' alt='" . h($item['label']) . "'></a>\n";   // Größe: --nav-icon-size in index.css
    }
    $html .= "<span id='stand-time' class='stand'>" . h($standText) . "</span>\n";
    $html .= renderThemeToggle() . "</div>\n";
    return $html;
}

/** Schalter für hellen/dunklen Modus (Mond im hellen, Sonne im dunklen Modus; Umschaltung per CSS). */
function renderThemeToggle(): string
{
    $attrs = "viewBox='0 0 24 24' width='20' height='20' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'";
    $moon  = "<svg class='ico-moon' {$attrs}><path d='M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z'></path></svg>";
    $sun   = "<svg class='ico-sun' {$attrs}><circle cx='12' cy='12' r='4.5'></circle>"
           . "<path d='M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8'></path></svg>";
    return "<button id='theme-toggle' type='button' class='theme-btn' onclick='toggleTheme();'"
         . " title='Darkmode umschalten' aria-label='Darkmode umschalten'>{$moon}{$sun}</button>\n";
}

/** Text für die Anzeige oben rechts: Uhrzeit der letzten Aktualisierung (Serverzeit). */
function standText(): string
{
    return 'Stand ' . date('H:i:s');
}

/** Einklappbarer Detailbereich (Standard: ausgeblendet). */
function renderCollapsible(string $content): string
{
    return "<input type='button' id='toggleblocks' class='details-btn'"
         . " onclick='toggleDetails();' value='Details einblenden'><br><br>"
         . "<div id='nonblocks' style='display:none'>{$content}</div>";
}

/** Liste der Chart-Bilder abhängig von der Konfiguration. */
function chartImages(array $cfg): array
{
    $charts = $cfg['use_smart_meter']
        ? ['pic_smart.php']
        : ['pic_verbrauch.php', 'pic_verbrauch_monthly.php'];

    if ($cfg['use_inverter']) {
        $charts[] = 'pic_inv_soc.php';
    }
    if ($cfg['charts']['energy_consumption']) {
        array_push($charts, 'pic_energy_consumption_daily.php', 'pic_energy_consumption_monthly.php');
    }
    if ($cfg['charts']['solar_power']) {
        array_push($charts, 'pic_solar_power.php', 'pic_solar_power_daily.php');
    }
    if ($cfg['charts']['solar_production']) {
        array_push($charts, 'pic_solar_production.php', 'pic_solar_production_monthly.php');
    }
    if ($cfg['charts']['warm_water']) {
        $charts[] = 'pic_warm_water.php';
    }
    array_push($charts, 'pic_energy.php', 'pic_temp.php');
    if ($cfg['charts']['heating_temp']) {
        $charts[] = 'pic_heating_temp.php';
    }
    return $charts;
}

function renderCharts(array $cfg): string
{
    $html = '';
    foreach (chartImages($cfg) as $src) {
        $html .= '<img class="chart-img" src="' . h($src) . '" alt="Chart" />';
    }
    return $html;
}

/**
 * Boiler-Modus für die Anzeige aus den Marker-Dateien von dect_overwrite.php:
 * 'off' = Zwangs-aus, 'on' = Zwangs-an, sonst 'auto'. Zwangs-aus hat Vorrang, falls beide existieren.
 */
function readDectMode(): string
{
    if (file_exists(DECT_OVERWRITE_FILE)) {
        return 'off';
    }
    if (file_exists(DECT_FORCE_ON_FILE)) {
        return 'on';
    }
    return 'auto';
}

/**
 * Rohwerte für die Wechselrichter-Grafik oder null, wenn sie nicht erzeugt werden kann.
 * (Enthält bewusst nicht den Lampenzustand.)
 */
function collectPictureParams(array $cfg, ?array $inverter, ?array $smart, array $devices): ?array
{
    if (!$cfg['use_inverter'] || !$cfg['use_smart_meter'] || $inverter === null || $smart === null) {
        return null;
    }
    // Schaltbare Geräte: je Kreis, ob eine AIN in $home_specials steht (Schaltfunktion) und der zuletzt geschaltete Zustand
    $switches = [];
    foreach (SPECIAL_DEVICES as $nr => $def) {
        if (!isset($def['switch'])) {
            continue;
        }
        $ain = specialAin($cfg, $nr);
        $switches[$nr] = [
            'enabled' => $ain !== '',
            'on'      => $ain !== '' ? readSwitchState($ain, @filemtime($cfg['datafile'])) : null,   // zuletzt geschaltet (oder null)
        ];
    }
    return [
        'dc'              => $inverter['pvPower'],
        'dcSmall'         => $devices['pvSmall'],
        'battery'         => $inverter['battery'],
        'grid'            => (int)$smart['power'],            // Smartmeter hat Vorrang
        'home'            => $inverter['homePower'] + $devices['pvSmall'],
        'soc'             => $inverter['soc'],
        'cycles'          => $inverter['cycles'],
        'batteryCapacity' => $inverter['batteryCapacity'],
        'specials'        => $devices['specials'],      // Klartext "Name: 123W" (Reihenfolge wie $home_show_data)
        'special'         => $devices['special'],       // je Kreis-Nummer: ain, name, power (Reihenfolge wie $home_specials)
        'dectMode'        => SHOW_DECT_TOGGLE ? readDectMode() : null,   // 'off' | 'auto' | 'on'
        'switches'        => $switches,                           // TV (03) und Drucker (04): Schaltfunktion und gemerkter Zustand
    ];
}

/** Erste Darstellung der Grafik als HTML. */
/** Diagnose: welche AIN (Position in $home_specials) zu welchem special_device gehört und welcher Wert dort steht. */
function describeSpecialMapping(array $special): array
{
    $rows = [];
    foreach (SPECIAL_DEVICES as $nr => $def) {
        $entry  = $special[$nr] ?? [];
        $rows[] = [
            'position_in_home_specials' => specialPosition($nr) + 1,
            'special_device'            => $nr,
            'ain'                       => $entry['ain'] ?? null,
            'geraet_in_xml'             => $entry['name'] ?? null,
            'wert'                      => isset($entry['power']) ? $entry['power'] . 'W' : null,
        ];
    }
    return $rows;
}

function renderPicture(array $params, ?bool $lampOn): string
{
    return buildInverterPicture($params, buildPictureState($params), $lampOn) . '<br><br>';
}

########################################################################################
## 7. Hauptablauf
########################################################################################
// --- XHR-Endpunkt: Steckdose schalten (benötigt keine Messdaten) ------------------------
if (isset($_GET['switch'])) {
    handleSwitchRequest((string)$_GET['switch'], (string)($_GET['device'] ?? 'boiler'), $cfg);
    exit;
}

$errors      = [];
$showCharts  = isset($_GET['charts']) && $_GET['charts'] === 'yes';
$showPicture = !$showCharts;

// --- Daten laden ----------------------------------------------------------------------
$inverter = $cfg['use_inverter']    ? loadInverter($cfg['inverter_data'], $errors) : null;
$smart    = $cfg['use_smart_meter'] ? loadSmartMeter($cfg, $errors)                : null;

$deviceXml = null;
if (is_readable($cfg['datafile'])) {
    $deviceXml = @simplexml_load_file($cfg['datafile']) ?: null;
}
if ($deviceXml === null) {
    $errors[] = 'Gerätedaten nicht lesbar: ' . $cfg['datafile'];
}

// --- Textabschnitte zusammensetzen ----------------------------------------------------
$sections = [
    'inverter'    => $inverter ? $inverter['lines'] : [],
    'power'       => [],
    'consumption' => [],
    'temps'       => [],
    'details'     => [],
];

if ($smart !== null) {
    $smartText = describeSmartMeter($smart);
    $sections['power']       = array_merge($sections['power'],       $smartText['power']);
    $sections['consumption'] = array_merge($sections['consumption'], $smartText['consumption']);
    $sections['details']     = array_merge($sections['details'],     $smartText['details']);
}

$devices = collectDeviceData($deviceXml, $cfg);
foreach (['temps', 'power', 'consumption', 'details'] as $key) {
    $sections[$key] = array_merge($sections[$key], $devices[$key]);
}

// --- XHR-Endpunkt: nur die Zahlenwerte der Grafik als JSON ---------------------------
$pictureParams = collectPictureParams($cfg, $inverter, $smart, $devices);

if (($_GET['data'] ?? '') === 'picture') {
    header('Cache-Control: no-store');
    if ($pictureParams === null) {
        http_response_code(503);   // Daten nicht verfügbar -> Client behält die alte Anzeige und zeigt das Banner
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $errors ? implode('; ', $errors) : 'Daten nicht verfügbar'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $state = buildPictureState($pictureParams);
    $state['stand-time'] = ['text' => standText()];   // Uhrzeit oben rechts, bei jeder Antwort aktualisiert
    if (isset($_GET['debug'])) {                      // Prüfhilfe: index.php?data=picture&debug=1
        $state['_zuordnung'] = describeSpecialMapping($pictureParams['special']);
    }
    echo json_encode($state, JSON_PARTIAL_OUTPUT_ON_ERROR | (isset($_GET['debug']) ? JSON_PRETTY_PRINT : 0));
    exit;
}

// --- Vollseite (wird nur einmal geladen) ----------------------------------------------
$modified = is_readable($cfg['datafile']) ? filemtime($cfg['datafile']) : false;
$readAt   = 'Ausgelesen am: ' . ($modified ? date('d.m.Y H:i:s.', $modified) : 'unbekannt');   // steht im Detailbereich

$body = renderNavigation($cfg, $showCharts, standText());
foreach ($errors as $error) {
    $body .= "<div class='err'>" . h($error) . '</div>';
}

// Energiefluss-Grafik (nicht in der Chart-Ansicht); Werte werden per XHR aktualisiert
$pollPicture = $showPicture && $pictureParams !== null;
if ($showPicture && $pictureParams !== null) {
    $body .= renderPicture($pictureParams, fetchLampState(SHELLY_STATUS_URL));
}

// Detailbereich
if ($cfg['use_blocks']) {
    $body .= $readAt . '<br><br>' . renderLines($sections['details']) . '<br>';
} else {
    $details = $readAt . '<br><br>';
    if ($cfg['use_inverter']) {
        $details .= '<b>Wechselrichterdaten:</b><br>' . renderLines($sections['inverter']);
    }
    $details .= '<b>Leistungen:</b><br>'   . renderLines($sections['power'])       . '<br>';
    $details .= '<b>Verbrauch:</b><br>'    . renderLines($sections['consumption']) . '<br>';
    $details .= '<b>Temperaturen:</b><br>' . renderLines($sections['temps'])       . '<br><br>';
    $body .= renderCollapsible($details);
}

if (DEBUG) {
    $body .= '<br><br>DEBUG:<br><br><pre>' . h(print_r($deviceXml, true)) . '</pre>';
}

if ($showCharts) {
    $body .= renderCharts($cfg);
}

########################################################################################
## 8. Ausgabe
########################################################################################
header('Content-Type: text/html; charset=utf-8');
$dataUrl = ($_SERVER['SCRIPT_NAME'] ?? 'index.php') . '?data=picture';
?>
<html lang="de">
<head>
<meta charset="utf-8">
<script>
// Manuell gewählten Modus sofort setzen (vor dem ersten Zeichnen, kein Aufblitzen); ohne Wahl gilt die Browser-Einstellung
try {
    var savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark' || savedTheme === 'light') { document.documentElement.setAttribute('data-theme', savedTheme); }
} catch (e) { /* Speicher nicht verfügbar */ }
</script>
<link rel="stylesheet" href="<?= h(assetUrl('index.css')) ?>">
<script src="<?= h(assetUrl('index.js')) ?>"></script>
<script>
// ---- Heller/dunkler Modus -------------------------------------------------------------
function systemPrefersDark() {
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
}

function isDark() {
    var chosen = document.documentElement.getAttribute('data-theme');
    return chosen ? chosen === 'dark' : systemPrefersDark();
}

function updateThemeButton() {
    var button = document.getElementById('theme-toggle');
    if (!button) { return; }
    button.setAttribute('aria-pressed', isDark() ? 'true' : 'false');
    button.title = isDark() ? 'Zum hellen Modus wechseln' : 'Zum dunklen Modus wechseln';
}

// Umschalten: wählt den jeweils anderen Modus und merkt sich die Wahl im Browser
function toggleTheme() {
    var next = isDark() ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('theme', next); } catch (e) { /* Speicher nicht verfügbar */ }
    updateThemeButton();
}

document.addEventListener('DOMContentLoaded', updateThemeButton);
if (window.matchMedia) {
    var themeQuery = window.matchMedia('(prefers-color-scheme: dark)');
    if (themeQuery.addEventListener) { themeQuery.addEventListener('change', updateThemeButton); }   // Browser-Einstellung ändert sich
}

// ---- Details ein-/ausblenden ----------------------------------------------------------
function toggleDetails() {
    var el  = document.getElementById('nonblocks');
    var btn = document.getElementById('toggleblocks');
    var hidden = el.style.display === 'none';
    el.style.display = hidden ? '' : 'none';
    btn.value = hidden ? 'Details ausblenden' : 'Details einblenden';
}

// ---- Werte der Wechselrichter-Grafik per XHR aktualisieren ----------------------------
// Der Server liefert { elementId: { text?, class?, transform?, attrs? } }; nur Unterschiede werden geschrieben.
(function () {
    var enabled  = <?= $pollPicture ? 'true' : 'false' ?>;
    var interval = <?= (int)PICTURE_REFRESH_MS ?>;
    var url      = <?= json_encode($dataUrl) ?>;
    var busy     = false;
    var timer    = null;
    var waiters      = [];
    var restartAfter = false;

    function applyValues(values) {
        for (var id in values) {
            if (!Object.prototype.hasOwnProperty.call(values, id)) { continue; }
            var el = document.getElementById(id);
            if (!el) { continue; }
            var spec = values[id];
            if (typeof spec.text === 'string' && el.textContent !== spec.text) {
                el.textContent = spec.text;
            }
            if (typeof spec['class'] === 'string' && el.getAttribute('class') !== spec['class']) {
                el.setAttribute('class', spec['class']);
            }
            if (typeof spec.transform === 'string' && el.getAttribute('transform') !== spec.transform) {
                el.setAttribute('transform', spec.transform);
            }
            if (spec.attrs && typeof spec.attrs === 'object') {
                for (var name in spec.attrs) {
                    if (Object.prototype.hasOwnProperty.call(spec.attrs, name)
                        && el.getAttribute(name) !== String(spec.attrs[name])) {
                        el.setAttribute(name, String(spec.attrs[name]));
                    }
                }
            }
        }
    }

    // Fehlerbanner über dem Bild: text = Meldung, '' = Daten sind wieder da (Banner verschwindet).
    // Das Bild wird abgedunkelt (Klasse is-stale), solange die Werte veraltet sind.
    function setStatus(text) {
        var banner  = document.getElementById('picture-banner');
        var picture = document.querySelector('.energy-picture');
        if (!banner) { return; }
        if (text) {
            var stand = document.getElementById('stand-time');
            var last  = stand ? stand.textContent.replace(/^Stand\s*/, '') : '';   // Uhrzeit der letzten erfolgreichen Antwort
            banner.textContent = text + (last ? ' (letzter Stand ' + last + ')' : '');
            banner.classList.add('is-visible');
            if (picture) { picture.classList.add('is-stale'); }
        } else {
            banner.classList.remove('is-visible');
            if (picture) { picture.classList.remove('is-stale'); }
        }
    }

    // Ursache aus der Serverantwort ({"error":"..."}), falls vorhanden
    function serverReason(xhr) {
        try {
            var data = JSON.parse(xhr.responseText);
            return data && data.error ? ' – ' + data.error : '';
        } catch (e) {
            return '';
        }
    }

    // Nächsten Durchlauf so planen, dass ein fester Takt eingehalten wird
    function schedule(startedAt) {
        timer = setTimeout(update, Math.max(0, interval - (Date.now() - startedAt)));
    }

    // Rückrufe, die erst nach einem frischen Ergebnis laufen (z. B. Ende der Boiler-Animation)
    function flushWaiters() {
        var list = waiters;
        waiters = [];
        list.forEach(function (fn) { try { fn(); } catch (e) { /* Rückruf-Fehler ignorieren */ } });
    }

    function finish(startedAt) {
        busy = false;
        if (restartAfter) { restartAfter = false; update(); return; }   // laufende Anfrage war evtl. veraltet
        flushWaiters();
        schedule(startedAt);
    }

    function update() {
        timer = null;
        var startedAt = Date.now();
        if (busy) { return; }                                                    // keine parallelen Anfragen
        if (document.hidden && waiters.length === 0) { schedule(startedAt); return; }   // im Hintergrund-Tab pausieren

        busy = true;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url + '&_=' + startedAt, true);
        xhr.timeout = 5000;
        xhr.onload = function () {
            if (xhr.status === 200) {
                try {
                    applyValues(JSON.parse(xhr.responseText));
                    setStatus('');
                } catch (e) {
                    setStatus('Ungültige Antwort vom Server');
                }
            } else {
                setStatus('Aktualisierung fehlgeschlagen (HTTP ' + xhr.status + ')' + serverReason(xhr));
            }
            finish(startedAt);
        };
        xhr.onerror = xhr.ontimeout = function () {
            setStatus('Keine Verbindung – neuer Versuch folgt');
            finish(startedAt);
        };
        xhr.send();
    }

    // Sofortige Aktualisierung (aus index.js aufrufbar). done läuft, sobald ein frisches Ergebnis angezeigt ist.
    // Läuft gerade eine Anfrage, könnte sie veraltet sein: dann wird danach sofort neu geladen.
    window.refreshPicture = function (done) {
        if (typeof done === 'function') { waiters.push(done); }
        if (!enabled) { flushWaiters(); return; }
        if (timer) { clearTimeout(timer); timer = null; }
        if (busy) { restartAfter = true; return; }
        update();
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (enabled) { schedule(Date.now()); }
    });
})();
</script>
</head>
<body>
<?= $body ?>
</body>
</html>
