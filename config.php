<?php
// =====================================================================
//  Moodle-Konfiguration — Verbandsgemeinde Kirchen, Einstellungstest
// ---------------------------------------------------------------------
//  Auditierbare, versionierte Konfiguration.
//  In dieser Datei gesetzte Werte sind in der Moodle-Oberflaeche
//  AUSGEGRAUT und dort nicht mehr aenderbar. Bei einem geharteten
//  Pruefungssystem ist genau das gewollt: die Haertung ist im Code
//  festgeschrieben, nicht per Klick reversibel.
// =====================================================================

unset($CFG);
global $CFG;
$CFG = new stdClass();

// --- Datenbank ---
$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MOODLE_DB_HOST') ?: 'db';
$CFG->dbname    = getenv('MOODLE_DB_NAME');
$CFG->dbuser    = getenv('MOODLE_DB_USER');
$CFG->dbpass    = getenv('MOODLE_DB_PASSWORD');
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
    'dbpersist'   => false,
    'dbsocket'    => false,
    'dbport'      => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
);

// --- URL & Datenverzeichnis ---
// wwwroot MUSS exakt der Adresse entsprechen, unter der aufgerufen wird:
//   Phase 1 (lokal per IP):  http://192.168.x.x:8080
//   Phase 2 (NPM -> Domain): https://moodle.deine-domain.de
$CFG->wwwroot   = getenv('MOODLE_WWWROOT');
$CFG->dataroot  = '/var/moodledata';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 02777;

// --- Reverse Proxy / TLS ---
// In Phase 2 stehen beide auf true: der Nginx Proxy Manager terminiert TLS
// und leitet per HTTP an den Container weiter. reverseproxy=true sorgt zudem
// dafuer, dass Moodle die ECHTE Client-IP (X-Forwarded-For) sieht — wichtig
// fuer die Netzwerkadress-Beschraenkung im Test.
if (filter_var(getenv('MOODLE_REVERSEPROXY'), FILTER_VALIDATE_BOOLEAN)) {
    $CFG->reverseproxy = true;
}
if (filter_var(getenv('MOODLE_SSLPROXY'), FILTER_VALIDATE_BOOLEAN)) {
    $CFG->sslproxy = true;
}

// =====================================================================
//  HAERTUNG — gesperrte Sicherheitsvorgaben fuer den Pruefungsbetrieb
// =====================================================================

// Kein anonymer Zugriff — jede Seite erfordert Login
$CFG->forcelogin                = true;
$CFG->forceloginforprofiles     = true;
$CFG->forceloginforprofileimage = true;

// Nicht von Suchmaschinen indexieren lassen
$CFG->opentowebcrawlers         = false;

// Cookie-Haertung.
// cookiehttponly ist immer sinnvoll (auch ueber HTTP) und bleibt an.
// cookiesecure NUR ueber HTTPS aktivieren — sonst schlaegt der Login fehl.
// Gesteuert ueber MOODLE_COOKIESECURE:
//   Phase 1 (lokal per IP, HTTP)   -> false
//   Phase 2 (NPM -> Domain, HTTPS) -> true
$CFG->cookiehttponly           = true;
if (filter_var(getenv('MOODLE_COOKIESECURE'), FILTER_VALIDATE_BOOLEAN)) {
    $CFG->cookiesecure = true;
}

// Selbstregistrierung & Gast-Login aus
$CFG->registerauth             = '';
$CFG->guestloginbutton         = 0;
$CFG->authloginviaemail        = 0;

// Messaging aus — Teilnehmer koennen sich nicht gegenseitig schreiben
$CFG->messaging                = false;

// Webservices / Mobile-App-Schnittstelle aus (nicht benoetigt)
$CFG->enablewebservices        = false;

// Cron nur ueber CLI erreichbar, nicht ueber das Web
$CFG->cronclionly              = true;

// Ausfuehrungspfade nicht ueber die Oberflaeche aenderbar
$CFG->preventexecpath          = true;

// --- Ende Haertung ---

require_once(__DIR__ . '/lib/setup.php');

// Es darf NICHTS hinter dieser Zeile stehen!
