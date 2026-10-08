<?php
declare(strict_types=1);

use LessHeadCMS\ApiException;
use LessHeadCMS\ApiKeys;
use LessHeadCMS\Auth;
use LessHeadCMS\Bootstrap;
use LessHeadCMS\Cms;
use LessHeadCMS\ColumnPrefs;
use LessHeadCMS\Config;
use LessHeadCMS\Database;
use LessHeadCMS\Http;
use LessHeadCMS\Languages;
use LessHeadCMS\Maintenance;
use LessHeadCMS\Media;
use LessHeadCMS\Permissions;
use LessHeadCMS\PumlExporter;
use LessHeadCMS\PumlParser;
use LessHeadCMS\SchemaBackup;
use LessHeadCMS\SchemaException;
use LessHeadCMS\SchemaIds;
use LessHeadCMS\SchemaIndexes;
use LessHeadCMS\SchemaMigration;
use LessHeadCMS\Settings;
use LessHeadCMS\TestData;
use LessHeadCMS\TranslationCsv;
use LessHeadCMS\Users;
use LessHeadCMS\Workflows;

// Lokal mit `php -S`: vorhandene Dateien unter /assets/ und /media/ (Mediathek) direkt ausliefern.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = realpath(__DIR__ . rawurldecode($path));
    foreach (['/assets', '/media'] as $dir) {
        $base = realpath(__DIR__ . $dir);
        if ($file !== false && $base !== false && is_file($file) && strpos($file, $base . DIRECTORY_SEPARATOR) === 0
            && substr($file, -4) !== '.php' && basename($file) !== '.htaccess') {
            return false;
        }
    }
}

// Überschreitet ein Request post_max_size (z. B. ein zu großer Upload in die Mediathek), gibt PHP schon vor diesem Skript
// eine Warnung aus, falls display_errors an ist. Liegt sie noch im Ausgabepuffer (output_buffering), wird sie verworfen -
// sonst stünde sie vor dem JSON mit der verständlichen 422-Meldung (Media::upload()) und machte es unlesbar.
$postMax = trim((string) ini_get('post_max_size'));
$postMaxBytes = (int) $postMax * ([
    'k' => 1024, 'm' => 1048576, 'g' => 1073741824,
][strtolower(substr($postMax, -1))] ?? 1);
if ($postMaxBytes > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMaxBytes) {
    while (ob_get_level() > 0 && @ob_end_clean()) {
    }
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'error',
        'message' => 'vendor/ fehlt – "composer install" ausführen bzw. den kompletten deploy/-Ordner hochladen.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require $autoload;

Config::init(__DIR__);

// HTTP-Methoden-Override (Flight: ?_method=… bzw. X-HTTP-Method-Override) abschalten. Die Oberfläche nutzt echte
// HTTP-Verben (PUT/DELETE per fetch), ein Override wird nie gebraucht. Angeschaltet wäre er ein Loch: Http::api()
// entscheidet anhand der ECHTEN Request-Methode, ob eine Session nötig ist (GET gilt als öffentlicher Lesezugriff),
// während Flight den Request zugleich an den Schreib-Handler (PUT/DELETE) weiterreicht - ein GET mit ?_method=DELETE
// würde also ohne Anmeldung und ohne Rechteprüfung schreiben/löschen (zugleich ein CSRF-Vektor, da GET).
Flight::set('flight.allow_method_override', false);

// ------------------------------------------------------------------ Fehlerbehandlung

Flight::map('notFound', function () {
    Http::json(['error' => 'not_found', 'message' => 'Route nicht gefunden'], 404);
});

Flight::map('error', function ($e) {
    error_log('[LessHeadCMS] ' . get_class($e) . ': ' . $e->getMessage());
    Http::jsonNow(['status' => 'error', 'message' => 'Interner Fehler'], 500);
});

// ------------------------------------------------------------------ Frontend

Flight::route('GET /', function () {
    $index = Config::assetsDir() . '/index.html';
    if (!is_file($index)) {
        Http::json(['status' => 'error', 'message' => 'Frontend fehlt (assets/index.html).'], 500);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
});

// ------------------------------------------------------------------ Bootstrap

Flight::route('GET /bootstrap', function () {
    $problem = Bootstrap::tokenProblem($_GET['token'] ?? null);
    if ($problem !== null) {
        Http::json(['status' => 'forbidden', 'message' => $problem], 403);
        return;
    }
    try {
        Http::json(Bootstrap::run($_GET['schema'] ?? null));
    } catch (ApiException $e) {
        Http::json($e->payload, $e->status);
    } catch (\Throwable $e) {
        error_log('[LessHeadCMS] Bootstrap: ' . $e->getMessage());
        Http::json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
});

// ------------------------------------------------------------------ Auth
// Muss VOR den generischen /api/@entity-Routen stehen (sonst wäre "login" eine Entität).

Flight::route('POST /api/login', function () {
    Http::auth(function () {
        return Auth::login(Http::body());
    });
});

Flight::route('POST /api/logout', function () {
    Http::auth(function () {
        return Auth::logout();
    });
});

Flight::route('GET /api/me', function () {
    Http::auth(function () {
        return Auth::me();
    });
});

Flight::route('PUT /api/me/password', function () {
    Http::auth(function () {
        return Auth::changePassword(Http::body());
    });
});

// ------------------------------------------------------------------ Nutzerverwaltung
// Systemebene, unabhängig vom PlantUML-Content-Schema; für role = admin oder mit Berechtigung auf den Systembereich "users"
// (Http::adminApi(..., 'users'), siehe Permissions) - Letztere dürfen keine Administratoren anlegen oder ändern.
// Muss VOR den generischen /api/@entity-Routen stehen (sonst wäre "users" eine Entität).

Flight::route('GET /api/users', function () {
    Http::adminApi(function (Users $users) {
        return [200, $users->all()];
    }, 'users');
});

Flight::route('POST /api/users', function () {
    Http::adminApi(function (Users $users, PDO $pdo, array $actor) {
        return [201, $users->create(Http::body(), $actor)];
    }, 'users');
});

Flight::route('PUT /api/users/@id:[0-9]+', function ($id) {
    Http::adminApi(function (Users $users, PDO $pdo, array $admin) use ($id) {
        return [200, $users->update((int) $id, Http::body(), $admin['id'], $admin)];
    }, 'users');
});

// Löschen ist für den MVP nicht vorgesehen (Soft-Delete per PUT .../active=false statt DELETE).
Flight::route('DELETE /api/users/@id:[0-9]+', function () {
    Http::json([
        'error'   => 'method_not_allowed',
        'message' => 'Nutzer können nicht gelöscht werden – stattdessen per PUT active=false setzen',
    ], 405);
});

// ------------------------------------------------------------------ Testdaten
// Für role = admin oder mit Berechtigung auf den Systembereich "testdata" (Http::adminApi(), wie /api/users). Muss VOR den generischen /api/@entity-Routen stehen; der
// Pfad beginnt mit "_", eine Entität kann ihn ohnehin nicht belegen (Klassennamen dürfen nicht mit _ beginnen).

Flight::route('POST /api/_testdata/generate', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        Maintenance::guard(); // schreibt Content - nicht während einer Schema-Migration
        $model = Database::loadModel($pdo);
        if ($model === null) {
            throw new ApiException(503, [
                'error'   => 'not_bootstrapped',
                'message' => 'Datenbank noch nicht initialisiert – bitte /bootstrap?token=... aufrufen',
            ]);
        }
        return [200, (new TestData(new Cms($pdo, $model), $pdo, $model))->generate(Http::body())];
    }, 'testdata');
});

// ------------------------------------------------------------------ Schema-Bearbeitung
// Aktives Diagramm einer befüllten Installation ändern, ohne DB-Reset (SchemaMigration). Nur für role = admin
// (Http::adminApi(), auch GET). Muss VOR den generischen /api/@entity-Routen stehen; "_schema" kann keine Entität belegen.

/** Aktive Schema-Datei (_meta.active_schema) samt Modell; 409, wenn es (noch) keine gibt */
function activeSchema(PDO $pdo): array
{
    $model = Database::loadModel($pdo);
    $file = $model !== null ? Database::getMeta($pdo, 'active_schema') : null;
    if ($model === null || $file === null || !is_file(Config::schemaDir() . '/' . $file)) {
        throw new ApiException(409, [
            'error'   => 'no_active_schema',
            'message' => 'Kein aktives Schema gefunden - bitte zuerst /bootstrap?token=... aufrufen (legt es fest).',
        ]);
    }
    return [$model, $file, Config::schemaDir() . '/' . $file];
}

/** Body {"source": "..."} -> .puml-Text (422 ohne) */
function schemaSource(array $body): string
{
    $source = $body['source'] ?? null;
    if (!is_string($source) || trim($source) === '') {
        throw new ApiException(422, ['error' => 'validation', 'message' => 'Schema-Text fehlt', 'errors' => ['source' => 'Text erwartet']]);
    }
    return $source;
}

Flight::route('GET /api/_schema/source', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        [, $file, $path] = activeSchema($pdo);
        $source = (string) file_get_contents($path);
        return [200, [
            'file'   => $file,
            'source' => $source,
            // false: Die Datei wurde nach dem letzten Bootstrap/Anwenden geändert (z. B. per FTP) - verglichen wird trotzdem
            // immer mit dem aktiven Schema in der Datenbank
            'file_matches_active' => hash_equals((string) Database::getMeta($pdo, 'schema_hash'), hash('sha256', $source)),
            // Sicherungen vor jedem Anwenden (data/schema-backups/): Liste, Aufbewahrung, ggf. Speicherplatz-Hinweis
            'backup' => SchemaBackup::overview(),
        ]];
    });
});

Flight::route('POST /api/_schema/analyze', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        [$model] = activeSchema($pdo);
        return [200, (new SchemaMigration($pdo, $model))->analyze(schemaSource(Http::body()))];
    });
});

// Model-JSON (PumlParser::modelJson) für die Diagramm-Ansicht: Body {"source": "..."} wie bei analyze, beliebiger Text (auch
// ungespeichert, unabhängig vom aktiven Schema) -> 200 Model-JSON samt warnings, 422 bei Schemafehlern (gleiche Meldung wie
// analyze). Ändert nichts.
// Seit Phase 2 mit stabilen IDs: 'id' ist die gespeicherte ID (schema_ids) der Entsprechung im aktiven Schema (gleiche
// Zuordnung wie beim Anwenden), sonst vorläufig "draft:<schlüssel>"; 'key' ist der aus dem Namen abgeleitete Schlüssel.
// Schreibt auch schema_ids nicht. Ohne aktives Schema sind alle IDs vorläufig. Seit Phase 3 zusätzlich 'active' = {name, label}
// der Entsprechung im aktiven Schema (null bei vorläufiger ID): daraus setzt der Diagramm-Editor {renamed_from:…}.
Flight::route('POST /api/_schema/model', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        $source = schemaSource(Http::body());
        $active = Database::loadModel($pdo);
        if ($active !== null) {
            return [200, (new SchemaMigration($pdo, $active))->previewModel($source)];
        }
        try {
            return [200, SchemaIds::decorate(PumlParser::modelJson($source), [], [])];
        } catch (SchemaException $e) {
            throw new ApiException(422, ['error' => 'schema_error', 'message' => $e->getMessage()]);
        }
    });
});

// Diagramm-Editor: im Diagramm bearbeitetes Model-JSON zurück als Text (PumlExporter). Body {"model": {...}} -> 200 {"source"},
// 422 bei ungültiger Form (PumlExporter::check). Prüft nicht, ob das Schema gültig ist - das macht die Oberfläche danach mit
// /api/_schema/model. Ändert nichts, das Ergebnis landet erst über "Speichern" im Textfeld.
Flight::route('POST /api/_schema/export', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        $model = Http::body()['model'] ?? null;
        $errors = PumlExporter::check($model);
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => implode(' ', array_slice($errors, 0, 5)),
                'errors' => $errors]);
        }
        return [200, ['source' => PumlExporter::toPuml($model)]];
    });
});

// Gespeichertes Diagramm-Layout (SchemaIds): Positionen je stabiler ID + Viewport, unabhängig von Migrationen. GET legt
// fehlende IDs des aktiven Schemas an (Installationen vor Phase 2), PUT {positions?, viewport?} speichert und erhöht
// layout_version (positions ersetzt das ganze Layout).
Flight::route('GET /api/_schema/layout', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        activeSchema($pdo);
        SchemaIds::ensure($pdo);
        return [200, SchemaIds::layout($pdo)];
    });
});

Flight::route('PUT /api/_schema/layout', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        activeSchema($pdo);
        SchemaIds::ensure($pdo);
        return [200, SchemaIds::saveLayout($pdo, Http::body())];
    });
});

// Body {"source", "confirm": [Schlüssel], "backfill": {Schlüssel: Wert}} -> 200 Zusammenfassung, 422 (Schemafehler, fehlende
// Bestätigung/Backfill), 409 (Konflikt mit Daten, gesperrt) - in jedem Fehlerfall bleibt die Datenbank unverändert
Flight::route('POST /api/_schema/apply', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        [$model, , $path] = activeSchema($pdo);
        $body = Http::body();
        return [200, (new SchemaMigration($pdo, $model))->apply(schemaSource($body), $body, $path)];
    });
});

// "Fehlende Indizes nachrüsten" (SchemaIndexes): Indizes auf Fremdschlüssel-Spalten und Zwischentabellen für
// Installationen, die vor deren Einführung entstanden sind. Ändert keine Daten, deshalb ohne Analyse, Bestätigung und
// Sicherung. Antwort {created: [...], existing: n, removed: [...]}.
Flight::route('POST /api/_schema/indexes', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        Maintenance::guard();
        [$model] = activeSchema($pdo);
        @set_time_limit(300);
        return [200, SchemaIndexes::sync($pdo, $model)];
    });
});

// Notfall-Wiederherstellung einer Sicherung (SchemaBackup::restore): Body {"stamp", "confirm": "WIEDERHERSTELLEN"} -> 200
// Zusammenfassung samt Sicherung des Stands davor; 422 ohne exakten Bestätigungstext (dann passiert nichts), 404 unbekannte
// Sicherung, 409 gesperrt/unvollständig/unbrauchbar. Funktioniert auch, wenn die aktive .puml fehlt (Notfall).
Flight::route('POST /api/_schema/restore', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        $body = Http::body();
        if (($body['confirm'] ?? null) !== SchemaBackup::RESTORE_CONFIRM) {
            throw new ApiException(422, ['error' => 'confirmation_required', 'message' => 'Zum Wiederherstellen bitte genau „'
                . SchemaBackup::RESTORE_CONFIRM . '“ eintippen. Es wurde nichts geändert.',
                'errors' => ['confirm' => SchemaBackup::RESTORE_CONFIRM . ' erwartet']]);
        }
        $stamp = $body['stamp'] ?? null;
        if (!is_string($stamp) || $stamp === '') {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Sicherung fehlt', 'errors' => ['stamp' => 'Zeitstempel erwartet']]);
        }
        $file = Database::getMeta($pdo, 'active_schema');
        $path = $file !== null && is_file(Config::schemaDir() . '/' . $file) ? Config::schemaDir() . '/' . $file : null;
        return [200, SchemaBackup::restore($pdo, $stamp, $path)];
    });
});

// ------------------------------------------------------------------ Workflows
// Workflow-Dateien (schema/workflows/) verwalten und die aktive anwenden: nur role = admin. Analyse und Anwenden laufen
// über denselben Weg wie die Schema-Bearbeitung (SchemaMigration, samt Sicherung) - geprüft wird immer das aktive
// Hauptschema zusammen mit der Workflow-Datei. /bootstrap kennt Workflows nicht. Pfade mit "_", keine Entität kann sie
// belegen.

/** Body {"file": Dateiname|null, "source": Text} -> Kandidat für SchemaMigration; file null/leer = Workflows abschalten */
function workflowCandidate(array $body): array
{
    $file = $body['file'] ?? null;
    if ($file === null || $file === '') {
        return ['file' => null, 'source' => null];
    }
    if (!is_string($body['source'] ?? null)) {
        throw new ApiException(422, ['error' => 'validation', 'message' => 'Workflow-Text fehlt', 'errors' => ['source' => 'Text erwartet']]);
    }
    return ['file' => Workflows::fileName($file), 'source' => $body['source']];
}

/** Aktives Hauptschema für die kombinierte Analyse: [Modell, angewendeter Text, Pfad der .puml] */
function workflowBase(PDO $pdo): array
{
    [$model, , $path] = activeSchema($pdo);
    $source = SchemaIds::activeSource($pdo);
    if ($source === null) {
        throw new ApiException(409, ['error' => 'no_active_schema', 'message' => 'Der Text des aktiven Schemas ist nicht '
            . 'lesbar - bitte zuerst unter System → Schema „Anwenden“ ausführen.']);
    }
    return [$model, $source, $path];
}

// Liste der Workflow-Dateien, die aktive und die Klassen mit Workflow-Feld. Legt die (leere) Aufgaben-Tabelle an.
Flight::route('GET /api/_workflow/files', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        Workflows::ensureTasks($pdo);
        return [200, Workflows::files($pdo) + ['backup' => SchemaBackup::overview()]];
    });
});

Flight::route('GET /api/_workflow/source', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        return [200, Workflows::source($pdo, $_GET['file'] ?? null)];
    });
});

// Body {"name"}: neue, leere Workflow-Datei
Flight::route('POST /api/_workflow/files', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        Maintenance::guard();
        return [201, Workflows::createFile(Http::body())];
    });
});

// Body {"file", "source"} -> Analyse wie /api/_schema/analyze, zusätzlich 'workflow' (Übersicht der Workflows)
Flight::route('POST /api/_workflow/analyze', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        [$model, $source] = workflowBase($pdo);
        return [200, (new SchemaMigration($pdo, $model, workflowCandidate(Http::body())))->analyze($source)];
    });
});

// Body {"file", "source", "confirm", "backfill"}: Text in die Datei schreiben, sie zur aktiven machen und das Modell
// anpassen - Antworten wie /api/_schema/apply
Flight::route('POST /api/_workflow/apply', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        [$model, $source, $path] = workflowBase($pdo);
        $body = Http::body();
        return [200, (new SchemaMigration($pdo, $model, workflowCandidate($body)))->apply($source, $body, $path, true)];
    });
});

// Eigene Rollen und Gruppen (Namen) für die Vorab-Auswahl der Transitionen im Formular; jede Rolle, mit Session
Flight::route('GET /api/_workflow/me', function () {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) {
        return [200, Workflows::memberships($pdo, $user)];
    });
});

// Alle Aufgaben (nur Admin, zur Kontrolle): ?entity=, ?record_id=, ?status=open|done
Flight::route('GET /api/_tasks', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        return [200, Workflows::tasks($pdo, $_GET)];
    });
});

// „Meine Aufgaben“: dem angemeldeten Nutzer direkt oder über eine Gruppe zugewiesen; jede Rolle, mit Session.
// ?status=all auch erledigte, ?count=1 nur die Anzahl der offenen (Zähler in der Sidebar)
Flight::route('GET /api/_tasks/mine', function () {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) {
        return [200, Workflows::myTasks($pdo, $user, $_GET)];
    });
});

// ------------------------------------------------------------------ Mehrsprachigkeit (Languages)
// Sprachen und Übersetzungen der Schema-Beschriftungen pflegen: Admins bzw. Berechtigung auf den Systembereich "languages"
// bzw. "translations". Liste der Sprachen, eigene Anzeigesprache und die
// Anzeige-Texte: jede Rolle mit Session. Pfade mit "_" - keine Entität kann sie belegen (siehe _testdata).

Flight::route('GET /api/_languages', function () {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) {
        return [200, Languages::overview($pdo, $user['id'])];
    });
});

// Body {code, name}; die erste Sprache wird Standardsprache (Ersteinrichtung)
Flight::route('POST /api/_languages', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        return [201, Languages::create($pdo, Http::body())];
    }, 'languages');
});

Flight::route('PUT /api/_languages/@id:[0-9]+', function ($id) {
    Http::adminApi(function (Users $users, PDO $pdo) use ($id) {
        return [200, Languages::update($pdo, (int) $id, Http::body())];
    }, 'languages');
});

// samt aller Übersetzungen dieser Sprache; 409 für die Standardsprache
Flight::route('DELETE /api/_languages/@id:[0-9]+', function ($id) {
    Http::adminApi(function (Users $users, PDO $pdo) use ($id) {
        return [200, Languages::delete($pdo, (int) $id)];
    }, 'languages');
});

// Anzeigesprache des angemeldeten Nutzers, Body {language_id}
Flight::route('PUT /api/_prefs/language', function () {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) {
        return [200, Languages::choose($pdo, $user['id'], Http::body())];
    });
});

// Anzeige-Texte (übersetzte Beschriftungen) in der Sprache des angemeldeten Nutzers
Flight::route('GET /api/_i18n', function () {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) {
        return [200, Languages::labels($pdo, $user['id'])];
    });
});

// Übersetzungen der festen UI-Texte (Phase 2), auch ohne Anmeldung (Login-Seite, Pflicht-Passwortwechsel): mit Session in der
// Sprache des Nutzers, sonst in der per ?lang=<Kürzel> angefragten bzw. der Standardsprache
Flight::route('GET /api/_ui_texts', function () {
    Http::auth(function () {
        $pdo = Database::connectIfExists();
        $user = $pdo && Database::tableExists($pdo, 'users') ? Auth::currentUser($pdo) : null;
        $code = is_string($_GET['lang'] ?? null) ? $_GET['lang'] : null;
        return [200, Languages::publicUiTexts($pdo, $user, $code)];
    });
});

Flight::route('GET /api/_translations', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        $id = filter_var($_GET['language_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Sprache fehlt', 'errors' => ['language_id' => 'ID erwartet']]);
        }
        return [200, Languages::catalog($pdo, $id)];
    }, 'translations');
});

// Body {language_id, items: [{kind, ref_id, value?, text}]}; leerer Text entfernt die Übersetzung
Flight::route('PUT /api/_translations', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        return [200, Languages::save($pdo, Http::body())];
    }, 'translations');
});

// Übersetzungen als CSV (TranslationCsv): Export einer Sprache, Liste der per FTP in translations/ abgelegten Dateien und
// Import in zwei Schritten - preview rechnet nur, apply speichert. Body beider: {language_id, content | file} (content =
// Text einer hochgeladenen CSV, file = Dateiname aus translations/). Alles nur für Admins.
Flight::route('GET /api/_translations/export', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        $id = filter_var($_GET['lang'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Sprache fehlt', 'errors' => ['lang' => 'ID erwartet']]);
        }
        return [200, TranslationCsv::export($pdo, $id)];
    }, 'translations');
});

Flight::route('GET /api/_translations/files', function () {
    Http::adminApi(function () {
        return [200, TranslationCsv::files()];
    }, 'translations');
});

Flight::route('POST /api/_translations/import/preview', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        return [200, TranslationCsv::preview($pdo, Http::body())];
    }, 'translations');
});

Flight::route('POST /api/_translations/import/apply', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        Maintenance::guard(); // löst über das aktive Schema auf - nicht während einer Schema-Migration
        return [200, TranslationCsv::apply($pdo, Http::body())];
    }, 'translations');
});

// ------------------------------------------------------------------ Spaltenauswahl der Listenansicht
// Je Nutzer und Entität (Tabelle user_column_prefs), jede Rolle, immer an die Session gebunden (Http::userApi()).
// Muss VOR den generischen /api/@entity-Routen stehen; "_prefs" kann keine Entität belegen (siehe _testdata).

Flight::route('GET /api/_prefs/columns/@entity', function ($entity) {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) use ($entity) {
        return [200, (new ColumnPrefs($pdo, $cms, $user['id']))->get($entity)];
    });
});

Flight::route('PUT /api/_prefs/columns/@entity', function ($entity) {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) use ($entity) {
        return [200, (new ColumnPrefs($pdo, $cms, $user['id']))->put($entity, Http::body())];
    });
});

// Gespeicherte Auswahl verwerfen -> wieder Standardauswahl
Flight::route('DELETE /api/_prefs/columns/@entity', function ($entity) {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) use ($entity) {
        return [200, (new ColumnPrefs($pdo, $cms, $user['id']))->delete($entity)];
    });
});

// ------------------------------------------------------------------ Mediathek
// Globale Mediathek (Systemtabellen media, media_tags, media_tag_links, siehe Media): immer mit Session (Http::userApi(),
// auch für GET), Admins bzw. Berechtigung auf den Systembereich "media" (Standard für Redakteure: gesperrt). Muss VOR den generischen /api/@entity-Routen stehen; "_media" kann keine Entität
// belegen (siehe _testdata). Die Dateien selbst liefert Apache direkt aus media/ aus, öffentlich.

Flight::route('GET /api/_media', function () {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) {
        return [200, (new Media($pdo, $cms->model(), $user))->all($_GET)];
    }, 'media');
});

// multipart/form-data: file, optional title, alt_text, description, tag_ids[], tags[]
Flight::route('POST /api/_media', function () {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) {
        return [201, (new Media($pdo, $cms->model(), $user))->upload($_POST, $_FILES)];
    }, 'media');
});

Flight::route('GET /api/_media/limits', function () {
    Http::userApi(function () {
        return [200, Media::limits()];
    }, 'media');
});

Flight::route('GET /api/_media/tags', function () {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) {
        return [200, (new Media($pdo, $cms->model(), $user))->tags()];
    }, 'media');
});

Flight::route('POST /api/_media/tags', function () {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) {
        return [201, (new Media($pdo, $cms->model(), $user))->createTag(Http::body())];
    }, 'media');
});

Flight::route('PUT /api/_media/tags/@id:[0-9]+', function ($id) {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) use ($id) {
        return [200, (new Media($pdo, $cms->model(), $user))->renameTag((int) $id, Http::body())];
    }, 'media');
});

Flight::route('DELETE /api/_media/tags/@id:[0-9]+', function ($id) {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) use ($id) {
        return [200, (new Media($pdo, $cms->model(), $user))->deleteTag((int) $id)];
    }, 'media');
});

// Datei eines Mediums austauschen (multipart/form-data, Feld "file"): id und Verknüpfungen bleiben
Flight::route('POST /api/_media/@id:[0-9]+/file', function ($id) {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) use ($id) {
        return [200, (new Media($pdo, $cms->model(), $user))->replaceFile((int) $id, $_FILES)];
    }, 'media');
});

Flight::route('GET /api/_media/@id:[0-9]+', function ($id) {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) use ($id) {
        return [200, (new Media($pdo, $cms->model(), $user))->find((int) $id)];
    }, 'media');
});

Flight::route('PUT /api/_media/@id:[0-9]+', function ($id) {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) use ($id) {
        return [200, (new Media($pdo, $cms->model(), $user))->update((int) $id, Http::body())];
    }, 'media');
});

// 409, solange ein Datensatz-Feld (Typ media) das Medium noch verwendet
Flight::route('DELETE /api/_media/@id:[0-9]+', function ($id) {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) use ($id) {
        return [200, (new Media($pdo, $cms->model(), $user))->delete((int) $id)];
    }, 'media');
});

// ------------------------------------------------------------------ Rollen und Rechte (Permissions)
// Verwaltung von Rollen, Gruppen, Berechtigungen und Zuweisungen: fest nur für role = admin (kein Systembereich - sonst
// könnte sich ein Nutzer selbst Rechte geben). Pfade mit "_", keine Entität kann sie belegen. Die Tabellen entstehen beim
// ersten Aufruf.

/** Handler der Verwaltungs-API: Tabellen sicherstellen; schreibend nicht während einer Schema-Migration */
function permissionsApi(callable $handler): void
{
    Http::adminApi(function (Users $users, PDO $pdo) use ($handler) {
        Maintenance::guardWrite();
        Permissions::ensureTables($pdo);
        return $handler($pdo);
    });
}

Flight::route('GET /api/_roles', function () {
    permissionsApi(function (PDO $pdo) {
        return [200, Permissions::roles($pdo)];
    });
});

Flight::route('POST /api/_roles', function () {
    permissionsApi(function (PDO $pdo) {
        return [201, Permissions::createRole($pdo, Http::body())];
    });
});

// 409 für die eingebaute Rolle "Admin" (weder umbenennen noch löschen)
Flight::route('PUT /api/_roles/@id:[0-9]+', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, Permissions::updateRole($pdo, (int) $id, Http::body())];
    });
});

Flight::route('DELETE /api/_roles/@id:[0-9]+', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, Permissions::deleteRole($pdo, (int) $id)];
    });
});

Flight::route('GET /api/_groups', function () {
    permissionsApi(function (PDO $pdo) {
        return [200, Permissions::groups($pdo)];
    });
});

Flight::route('POST /api/_groups', function () {
    permissionsApi(function (PDO $pdo) {
        return [201, Permissions::createGroup($pdo, Http::body())];
    });
});

Flight::route('PUT /api/_groups/@id:[0-9]+', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, Permissions::updateGroup($pdo, (int) $id, Http::body())];
    });
});

Flight::route('DELETE /api/_groups/@id:[0-9]+', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, Permissions::deleteGroup($pdo, (int) $id)];
    });
});

// Zuweisungen setzen (ersetzt jeweils die ganze Liste): Body {role_ids: [...]} bzw. {group_ids: [...]}
Flight::route('PUT /api/_groups/@id:[0-9]+/roles', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, Permissions::setGroupRoles($pdo, (int) $id, Http::body())];
    });
});

Flight::route('PUT /api/_users/@id:[0-9]+/roles', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, Permissions::setUserRoles($pdo, (int) $id, Http::body())];
    });
});

Flight::route('PUT /api/_users/@id:[0-9]+/groups', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, Permissions::setUserGroups($pdo, (int) $id, Http::body())];
    });
});

// Zuweisungen eines Nutzers {user_id, role_ids, group_ids} und seine effektiven Berechtigungen (wie /api/_permissions/me)
Flight::route('GET /api/_users/@id:[0-9]+/assignments', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, Permissions::userAssignments($pdo, (int) $id)];
    });
});

Flight::route('GET /api/_users/@id:[0-9]+/permissions', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        $user = (new Users($pdo))->find((int) $id);
        return [200, Permissions::effective($pdo, $user, Database::loadModel($pdo) ?? ['entities' => []])];
    });
});

// API-Schlüssel eines API-Nutzers (role = api, siehe ApiKeys): nur für role = admin. GET liefert den Zustand (Vorschau,
// erzeugt, zuletzt verwendet, Status, "Nur lesen") - nie den Schlüssel. POST erzeugt einen neuen und macht den bisherigen
// sofort ungültig; nur diese Antwort enthält 'key' im Klartext. DELETE widerruft ohne Ersatz. "Nur lesen" schaltet
// PUT /api/users/{id} mit {"api_read_only": true|false}.
Flight::route('GET /api/_users/@id:[0-9]+/api_key', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, ApiKeys::info($pdo, (int) $id)];
    });
});

Flight::route('POST /api/_users/@id:[0-9]+/api_key', function ($id) {
    Http::adminApi(function (Users $users, PDO $pdo, array $admin) use ($id) {
        Maintenance::guardWrite();
        return [201, ApiKeys::generate($pdo, (int) $id, $admin['id'])];
    });
});

Flight::route('DELETE /api/_users/@id:[0-9]+/api_key', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, ApiKeys::revoke($pdo, (int) $id)];
    });
});

// ------------------------------------------------------------------ Einstellungen (Settings)
// Über die Oberfläche (System → Einstellungen) statt in config.php änderbare Einstellungen der Installation: nur für
// role = admin. GET liefert alle {name: wert}; PUT ändert die im Body enthaltenen und wirkt sofort.
Flight::route('GET /api/_settings', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        return [200, Settings::all($pdo)];
    });
});

Flight::route('PUT /api/_settings', function () {
    Http::adminApi(function (Users $users, PDO $pdo) {
        Maintenance::guardWrite();
        return [200, Settings::update($pdo, Http::body())];
    });
});

// Eigene effektive Berechtigungen (jede Rolle, mit Session): Abweichungen vom Standard - gesperrte Entitäten und Felder,
// freigegebene Systembereiche. Die Oberfläche blendet danach die System-Einträge der Sidebar ein.
Flight::route('GET /api/_permissions/me', function () {
    Http::userApi(function (Cms $cms, PDO $pdo, array $user) {
        return [200, Permissions::effective($pdo, $user, $cms->model())];
    });
});

// Mögliche Ziele: Systembereiche und die Elemente des aktiven Schemas mit stabiler ID (ref_id) und lesbarem Schlüssel (ref)
Flight::route('GET /api/_permissions/targets', function () {
    permissionsApi(function (PDO $pdo) {
        return [200, Permissions::targets($pdo)];
    });
});

// optional ?owner_type=role|group|user&owner_id=<id>
// Herkunfts-Anzeige im Berechtigungs-Editor: was ein Nutzer bzw. eine Gruppe bereits aus anderen Quellen erhält
Flight::route('GET /api/_permissions/inherited', function () {
    permissionsApi(function (PDO $pdo) {
        return [200, Permissions::inherited($pdo, $_GET)];
    });
});

Flight::route('GET /api/_permissions', function () {
    permissionsApi(function (PDO $pdo) {
        return [200, Permissions::all($pdo, $_GET)];
    });
});

// Body {owner_type, owner_id, scope: entity|field|systable, ref_id | ref (bei entity/field), systable_name (bei systable),
// effect: allow|deny}. 422 u. a. bei deny auf ein Pflichtfeld, 409 wenn es die Berechtigung schon gibt.
Flight::route('POST /api/_permissions', function () {
    permissionsApi(function (PDO $pdo) {
        return [201, Permissions::create($pdo, Http::body())];
    });
});

Flight::route('PUT /api/_permissions/@id:[0-9]+', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, Permissions::update($pdo, (int) $id, Http::body())];
    });
});

Flight::route('DELETE /api/_permissions/@id:[0-9]+', function ($id) {
    permissionsApi(function (PDO $pdo) use ($id) {
        return [200, Permissions::delete($pdo, (int) $id)];
    });
});

// ------------------------------------------------------------------ REST-API
// GET ist öffentlich (außer mit der Einstellung "require_auth_for_read"); POST/PUT/DELETE prüft Http::api() per Session
// oder API-Schlüssel (siehe dort).

Flight::route('GET /api', function () {
    Http::api(function (Cms $cms) {
        return [200, ['entities' => $cms->entityNames()]];
    });
});

// _schema muss vor /api/@entity/@id registriert sein
Flight::route('GET /api/@entity/_schema', function ($entity) {
    Http::api(function (Cms $cms) use ($entity) {
        return [200, $cms->describe($entity)];
    });
});

// Liste, immer seitenweise: {data: [...], total, page, per_page, total_pages}. Ohne Parameter Seite 1 mit 50 Zeilen.
// Blättern (page, per_page bis 500), Sortieren (sort, order) und Filtern (filter[feld], eq[feld], ids, q, for/for_id) siehe
// ListQuery. Bezeichnungen verknüpfter Zeilen werden mit dem Entitätsnamen durchsucht, den der Aufrufer sieht (mit Session
// seine Anzeigesprache, öffentlich die Standardsprache) und mit dem Namen aus dem Diagramm;
// ungültige Parameter -> 422, eine absehbar zu große Antwort -> 413. Gilt genauso für die öffentliche API ohne Session.
// Entitäten mit visibility-Feld: öffentlich nur veröffentlichte Zeilen. ?include_drafts=true liefert zusätzlich
// Entwürfe, aber nur mit Session (sonst 401). Ohne visibility-Feld bleibt alles offen (der Parameter wird ignoriert).
Flight::route('GET /api/@entity', function ($entity) {
    Http::api(function (Cms $cms, PDO $pdo) use ($entity) {
        $drafts = false;
        if (Http::flag('include_drafts') && $cms->visibilityField($entity) !== null) {
            Auth::requireSession($pdo);
            $drafts = true;
        }
        return [200, $cms->page($entity, $_GET, $drafts, function () use ($pdo) {
            $user = Auth::currentUser($pdo);
            return Languages::entityNames($pdo, $user !== null ? $user['id'] : null);
        })];
    });
});

Flight::route('POST /api/@entity', function ($entity) {
    Http::api(function (Cms $cms) use ($entity) {
        return [201, $cms->create($entity, Http::body())];
    });
});

// Entwurf ohne Session -> 404 (nicht 403: die Existenz des Datensatzes wird nicht verraten)
Flight::route('GET /api/@entity/@id:[0-9]+', function ($entity, $id) {
    Http::api(function (Cms $cms, PDO $pdo) use ($entity, $id) {
        $row = $cms->find($entity, (int) $id);
        if ($cms->isDraft($entity, $row) && !Auth::canSeeDrafts($pdo)) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Datensatz nicht gefunden']);
        }
        return [200, $row];
    });
});

Flight::route('PUT /api/@entity/@id:[0-9]+', function ($entity, $id) {
    Http::api(function (Cms $cms) use ($entity, $id) {
        return [200, $cms->update($entity, (int) $id, Http::body())];
    });
});

// Workflow-Transition: Body {"transition": "<label>"} - der einzige Weg, den Zustand eines Workflow-Felds zu ändern
// (Cms::transition()); 404 für Entitäten ohne Workflow
Flight::route('POST /api/@entity/@id:[0-9]+/transition', function ($entity, $id) {
    Http::api(function (Cms $cms) use ($entity, $id) {
        return [200, $cms->transition($entity, (int) $id, Http::body()['transition'] ?? null)];
    });
});

Flight::route('DELETE /api/@entity/@id:[0-9]+', function ($entity, $id) {
    Http::api(function (Cms $cms) use ($entity, $id) {
        // cascade_deleted nur, wenn {cascade} tatsächlich Zeilen mitgelöscht hat (sonst Antwort wie bisher)
        $cascaded = $cms->delete($entity, (int) $id);
        return [200, ['deleted' => (int) $id] + ($cascaded ? ['cascade_deleted' => $cascaded] : [])];
    });
});

Flight::start();
