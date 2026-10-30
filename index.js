// Steuerfunktionen für das Energie-Dashboard (index.php)

// ---- Hilfsfunktionen ---------------------------------------------------------------

/**
 * Sendet eine GET-Anfrage. Bei Erfolg (HTTP 200) wird onSuccess aufgerufen, bei Fehler
 * (anderer Status, Netzwerkfehler, Zeitüberschreitung) onError. Fehler stehen zusätzlich in der Konsole.
 */
function sendRequest(url, onSuccess, onError, timeoutMs) {
	const xhr = new XMLHttpRequest();
	xhr.open("GET", url, true); // asynchron
	xhr.timeout = timeoutMs || 10000;
	xhr.onload = () => {
		if (xhr.status === 200) {
			if (onSuccess) onSuccess();
		} else {
			console.error(url + ": HTTP " + xhr.status + " " + xhr.statusText + " " + xhr.responseText);
			if (onError) onError();
		}
	};
	xhr.onerror = () => {
		console.error(url + ": Netzwerkfehler");
		if (onError) onError();
	};
	xhr.ontimeout = () => {
		console.error(url + ": Zeitüberschreitung");
		if (onError) onError();
	};
	xhr.send();
}

/**
 * Führt mehrere Anfragen nacheinander aus. Beim ersten Fehler wird abgebrochen (onError), die übrigen
 * Anfragen werden dann nicht mehr gesendet. onSuccess läuft nach der letzten.
 */
function sendRequests(urls, onSuccess, onError, timeoutMs) {
	const next = (index) => {
		if (index >= urls.length) {
			if (onSuccess) onSuccess();
			return;
		}
		sendRequest(urls[index], () => next(index + 1), onError, timeoutMs);
	};
	next(0);
}

// ---- Boiler-Schalter ----------------------------------------------------------------

/** Stößt die Aktualisierung der Wechselrichter-Grafik an (definiert in index.php); done läuft nach dem frischen Ergebnis. */
function refreshNow(done) {
	if (typeof window.refreshPicture === "function") {
		window.refreshPicture(done);
	} else if (done) {
		done();
	}
}

let boilerBusy = false;
let boilerBusyGuard = null;

/** Wartezustand: Lade-Ring dreht sich am Knopf, weitere Tipps werden ignoriert. */
function setBoilerBusy(busy) {
	boilerBusy = busy;
	const spinner = document.getElementById("boiler-spinner");
	if (spinner) spinner.setAttribute("display", busy ? "inline" : "none");
	document.querySelectorAll("#boiler-switch rect[data-mode]").forEach((rect) => {
		rect.style.cursor = busy ? "wait" : "pointer";
	});
	clearTimeout(boilerBusyGuard);
	if (busy) boilerBusyGuard = setTimeout(() => setBoilerBusy(false), 45000); // Sicherung gegen Hängenbleiben
}

/** Fehlerhinweis: Schiene leuchtet kurz rot (Farbe steht in index.css, Klasse sw-error). */
function flashBoilerError() {
	const track = document.getElementById("boiler-track");
	if (!track) return;
	track.classList.add("sw-error");
	setTimeout(() => track.classList.remove("sw-error"), 1500);
}

/**
 * Sendet einen Boiler-Befehl (eine oder mehrere Anfragen nacheinander). Die Animation läuft, bis der neue
 * Zustand in der Grafik angezeigt wird (Anfragen angenommen -> Grafik frisch laden -> Animation beenden)
 * bzw. bis ein Fehler auftritt.
 */
function runBoilerCommand(urls) {
	setBoilerBusy(true);
	sendRequests(
		urls,
		() => refreshNow(() => setBoilerBusy(false)),
		() => {
			setBoilerBusy(false);
			flashBoilerError();
		},
		30000 // Login, Schaltbefehl und Abmeldung an der FRITZ!Box können einige Sekunden dauern
	);
}

/**
 * Adresse des Schaltbefehls für eine Steckdose (index.php, Parameter switch=on|off).
 * device: "boiler" (Standard), "tv" oder "printer"; die AIN kommt serverseitig aus der Konfiguration.
 */
function plugUrl(state, device) {
	return window.location.pathname + "?switch=" + state + (device ? "&device=" + device : "");
}

/**
 * setOn = true: Zwangs-aus aktivieren (erst Steckdose aus, dann Marker setzen),
 * false: zurück auf Auto (nur Marker entfernen, ohne Schaltbefehl).
 */
function callDectOverwrite(setOn) {
	runBoilerCommand(setOn
		? [plugUrl("off"), "/dect_overwrite.php?set=1"]
		: ["/dect_overwrite.php?remove=1"]);
}

/** Zwangs-an aktivieren: erst Steckdose an, dann Marker setzen (entfernt dabei Zwangs-aus). */
function callDectForceOn() {
	runBoilerCommand([plugUrl("on"), "/dect_overwrite.php?set_on=1"]);
}

/** Tipp auf eine Schalterposition: mode = "off" (Zwangs-aus), "auto" oder "on" (Zwangs-an). */
function setBoilerMode(mode) {
	const sw = document.getElementById("boiler-switch");
	if (!sw || boilerBusy) return;                             // läuft schon eine Anfrage
	if (sw.getAttribute("data-mode") === mode) return;         // bereits aktiv

	if (mode === "off") callDectOverwrite(true);
	else if (mode === "auto") callDectOverwrite(false);
	else if (mode === "on") callDectForceOn();
}

// ---- Geräte-Ringe: Wartezustand (Stern auf dem Ring) und Fehlerhinweis -------------------

const ringBusy = {};
const ringBusyGuard = {};

/** Wartezustand eines Rings (name = Kennung wie "special-device-04"): Stern läuft im Uhrzeigersinn, weitere Tipps werden ignoriert. */
function setRingBusy(name, busy) {
	ringBusy[name] = busy;
	const star = document.getElementById("star-" + name);
	if (star) star.setAttribute("display", busy ? "inline" : "none");
	const hit = document.getElementById("hit-" + name);
	if (hit) hit.style.cursor = busy ? "wait" : "pointer";
	clearTimeout(ringBusyGuard[name]);
	if (busy) ringBusyGuard[name] = setTimeout(() => setRingBusy(name, false), 45000); // Sicherung gegen Hängenbleiben
}

/** Fehlerhinweis: Ring leuchtet kurz rot (Farbe steht in index.css, Klasse ring-error). */
function flashRingError(name) {
	const ring = document.getElementById("ring-" + name);
	if (!ring) return;
	ring.classList.add("ring-error");
	setTimeout(() => ring.classList.remove("ring-error"), 1500);
}

/**
 * Tipp auf einen Geräte-Kreis mit Schaltfunktion (TV, Drucker): schaltet die Steckdose über die FRITZ!Box.
 * slot   = Kennung des Kreises in der "special_device"-Schreibweise, z. B. "special-device-03" (TV) oder "special-device-04" (Drucker)
 *          (IDs: image-<slot>, ring-<slot>, hit-<slot>, star-<slot>)
 * device = Gerät für den Server ("tv" oder "printer"); die AIN kommt dort aus $home_specials (config.php).
 * Der Stern läuft, bis die Anfrage beantwortet und der neue Zustand in der Grafik angezeigt ist;
 * der Status ändert sich erst nach erfolgreichem Aufruf (der Server merkt sich den Zustand).
 */
function toggleSpecialDevice(slot, device) {
	if (ringBusy[slot]) return;                         // läuft schon eine Anfrage
	const icon = document.getElementById("image-" + slot);
	const isOff = !!icon && (icon.getAttribute("filter") || "").indexOf("icon-off") !== -1;

	setRingBusy(slot, true);
	sendRequest(
		plugUrl(isOff ? "on" : "off", device),
		() => refreshNow(() => setRingBusy(slot, false)),
		() => {
			setRingBusy(slot, false);
			flashRingError(slot);
		},
		30000 // Login, Schaltbefehl und Abmeldung an der FRITZ!Box können einige Sekunden dauern
	);
}

// ---- Shelly-Lampe ------------------------------------------------------------------

/** Klick auf den Ring um die Lampe: löst denselben Klick aus wie auf die Lampe selbst. */
function clickLamp() {
	const lamp = document.getElementById("lamp_01");
	if (lamp) lamp.dispatchEvent(new MouseEvent("click"));
}

function toggleLamp(setOn) {
	const lamp = document.getElementById("lamp_01");

	// Visuelles Feedback sofort; der Lampenzustand wird nicht per XHR nachgeladen
	if (lamp) {
		lamp.setAttribute("href", setOn ? "pics/pic_bulp_on.svg" : "pics/pic_bulp_off.svg");
		lamp.setAttribute("onclick", "toggleLamp(" + !setOn + ");");
		// aus = ausgegraut: Filter icon-off (in der Grafik definiert) und Deckkraft wie in index.php (ICON_OFF_OPACITY)
		lamp.setAttribute("filter", setOn ? "none" : "url(#icon-off)");
		lamp.setAttribute("opacity", setOn ? "1" : "0.45");
	}

	// Der Endpunkt schaltet um (toggle), unabhängig vom Zielzustand
	sendRequest("/toggle_shelly.php?id=1", () => console.log("shelly toggled"));
}
