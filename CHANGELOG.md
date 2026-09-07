# Changelog

## 1.5.0 — 2026-09-07

### Neu: ein Produkt kann einen Zahlungsrhythmus tragen

Vier Spalten auf `products`: `interval`, `times`, `trial_days`, `trial_amount_cent`. Alle
nullable, und `interval` ist der Schalter — ohne ihn verhält sich ein Produkt exakt wie
vorher.

**Das ist keine neue Fähigkeit, sondern eine wiederhergestellte.** `statamic-payments` kann
Abo, Ratenzahlung und Testphase seit 1.5.0 — ein Mechanismus, drei Gesichter: `times = null`
ist ein Abo, `times = N` eine Ratenzahlung, `trial_days` eine Testphase
(`Subscriptions::planFor()`). Gelesen wird der Plan aus dem Katalog.

Solange der Katalog eine Config war, stand er dort und funktionierte. Seit dieses Addon die
Produkte in eine Tabelle geholt hat, gab `Product::toCatalogueEntry()` genau `handle`, `name`,
`amount_cent`, `currency`, `digital` und `grants` weiter — und damit **konnte eine Zeile aus
der Datenbank keinen Plan mehr tragen**. `planFor()` gab für jedes Tabellen-Produkt `null`
zurück, ohne Fehler und ohne Log. Die Fähigkeit war nicht kaputt, sie war unerreichbar.

Aufgefallen am 06.09.2026 an einer konkreten Stelle: die ChoirAccelerator-Seite von
adriangoldner.com verspricht „2 × 780 EUR oder 3 × 520 EUR", und beim Umzug des Kaufs auf den
eigenen Funnel stellte sich heraus, dass die eigene Kasse das nicht anbieten kann.

### Control Panel

Vier Felder im Produkt-Formular, zusammen als eine Entscheidung. Anzahl, Testtage und
Testbetrag sind ohne Rhythmus deaktiviert und werden beim Speichern mit geleert — sonst bleibt
an einem einmalig verkauften Produkt ein `times = 3` hängen, das niemand sieht und das wirkt,
sobald jemand später ein Intervall setzt.

Null Abbuchungen werden abgewiesen: das ist ein Tippfehler, keine Anweisung.

### Warum keine Aufzählung für `interval`

Freitext im Wortlaut des Anbieters (`1 month`, `12 weeks`).
`Subscriptions::afterOneInterval()` reicht den Wert an Carbon weiter und fällt bei Unlesbarem
auf einen Monat zurück, statt zu werfen. Eine engere Regel hier beschnitte, was das
Zahlungs-Addon kann.

### Tests

`PaymentPlanTest` (5) und drei im `ProductScreenTest`. Der wichtigste ist der erste: ein
Produkt **ohne** Rhythmus bekommt weiterhin keinen. Eine Migration mit Standardwerten hätte
aus jedem bestehenden Produkt ein Abo gemacht.

`down()` prüft auf die Tabelle, bevor es Spalten wirft — `CatalogueTest` löscht sie
absichtlich, um zu belegen, dass eine fehlende Tabelle die Kasse nicht mitreißt.

## 1.4.0 — 2026-09-05

### Neu: Produkte im Verkaufs-Abschnitt der Seitenleiste

Der Produkt-Bildschirm ist als Statamic-Utility registriert und stand deshalb unter „Hilfsmittel",
zwischen Cache und PHP-Info (Adrian, 03.09.2026, F36). Jetzt hängt er im Verkaufs-Abschnitt, den
`statamic-payments` mit `Cp\SuiteNav::section()` benennt: derselbe Abschnitt wie Zahlungen,
Angebote und Funnels, damit nicht zwei fast gleich benannte Abschnitte nebeneinander stehen,
denn Statamic übersetzt Abschnittsnamen nicht.

Route und Recht bleiben. Der Eintrag unter „Hilfsmittel" wird ausgehängt, sonst stünde der
Bildschirm zweimal da; so war es im ersten Anlauf vom 04.09.

`Cp\SuiteNav` gibt es erst seit `goldnead/statamic-payments` 1.18.0, der Constraint erlaubt
weiterhin `^1.15`. Deshalb steht der Aufruf hinter `class_exists()`, wie in `statamic-booking`:
mit älterem payments bekommt der Bildschirm einen eigenen Abschnitt „Produkte" statt eines
`Class not found` beim Aufbau der ganzen CP-Navigation. Den gemeinsamen Verkaufs-Abschnitt gibt
es ab payments 1.18.0.

Intern: `tests/Fakes/insights-table-metric.php` auf insights 1.2.1 nachgezogen (`bucketed()`
sortiert die Eimer explizit).

## 1.3.0 — 2026-09-02

### Neu: das Produkt zeigt seine Angebote und seine Käufer

Bisher kannte die Familie nur die Hinrichtung: ein Angebot zeigt auf ein Produkt, eine
Zahlungszeile trägt seine Kennung. Vom Produkt aus gab es keinen Weg zurück, und „wer hat
das gekauft" hieß: zwei andere Bildschirme öffnen und suchen.

Jetzt hat jedes Produkt eine eigene Seite (`GET utilities/products/{product}`, aus der Liste
über „Angebote und Käufer" in den Zeilenaktionen und aus dem Bearbeiten-Stapel). Sie zeigt
die Fakten des Produkts und darunter zwei Abschnitte:

- **Angebote** — jedes Angebot, das dieses Produkt verkauft, als Hauptprodukt (`product`)
  oder im Bündel (`products`), mit Platz, Preis (Listenpreis, wenn das Angebot keinen
  eigenen hat), Aktiv und einem Sprung in die Angebotsliste, dort schon auf die Kennung
  gefiltert.
- **Käufer** — die letzten 50 bezahlten Käufe über `payment_items` ⋈ `payments`, also auch
  als Order-Bump oder Nachkauf: E-Mail, Datum, Betrag der Zeile, Erstattet-Abzeichen, Sprung
  in die Zahlungsliste.

Die Seite ist markenverengt wie die Liste: im Multi-Brand-Betrieb ist ein Produkt einer anderen
Marke ein 404, und die Käuferliste ist zusätzlich auf `payments.brand_id` des Produkts
eingeschränkt. Ohne aktuelle Marke gibt es keine Seite (fail-closed, wie überall in der Familie).

Beide Abschnitte gibt es nur, wenn das jeweilige Addon installiert und migriert ist
(`Support\Siblings`, Klassen- plus Tabellenprüfung). Fehlt es, fehlt der Abschnitt — `null`
ist „kann ich nicht wissen", eine leere Liste ist „niemand", und der Bildschirm zeigt nur
Letzteres als Leerzustand.

Die Sprünge in die Nachbarlisten sind Suchen (`?search=`), keine Detailseiten: weder Angebote
noch Zahlungen haben eine. Ist die Nachbar-Utility nicht registriert, gibt es keinen Knopf.

## 1.2.0 — 2026-08-30

### Behoben: ein abgebrochener Kauf fror die Kennung für immer ein

`hasBeenSold()` fragte, ob **irgendeine** Zahlungszeile die Kennung trägt. `Checkout::start()`
schreibt aber die `payments`-Zeile und ihre `payment_items` **bevor** es den Anbieter aufruft, mit
Status `initiated` — und `prune_unpaid_after_days` steht ab Werk auf `0`, also räumt sie niemand
weg.

Folge: Ein Besucher öffnet den Bezahlvorgang und schließt den Tab. Danach ist die Kennung des
Produkts gesperrt und Löschen wird verweigert, dauerhaft, für ein Produkt, das nie jemand gekauft
hat. Der Grund war nirgends sichtbar — die Meldung sagt „wurde schon verkauft".

Jetzt zählt nur noch `status = paid`. Eine Erstattung hebt die Sperre nicht auf: Erstattungen sind
Spalten auf einer bezahlten Zeile, der Status bleibt `paid`, und die Rechnung existiert weiter.

### Behoben: ein PATCH ohne `active` oder `grants` löschte beide, still, mit 200

`$request->boolean()` liest einen fehlenden Schlüssel als `false`, und `(array) null` ist `[]`. Wer
per PATCH nur den Preis änderte, schaltete damit das Produkt ab und warf seine Zugänge weg — und
bekam `200` zurück. Das eigene Formular schickt immer alle Felder, deshalb ist es dort nie
aufgefallen.

Jetzt wird nur geschrieben, was tatsächlich gesendet wurde. `grants: []` bleibt eine Aussage und
leert weiterhin.

**Beide Fehler waren an einer grünen Testsuite vorbeigekommen**, weil jedes Fixture mit `paid`
zahlte und kein Test ein Teil-PATCH schickte. Fünf Tests dazu, gegengeprüft: gegen den alten Stand
werden sie rot.

Gefunden beim Schreiben der Dokumentation — von einem Agenten, der die Prosa gegen den Code prüfte.

## 1.1.0 — 2026-08-30

### Behoben: die Arten hießen auf Deutsch, gespeichert wird in dieser Familie Englisch

`statamic-payments` speichert `paid`, `open`, `expired`. `statamic-offers` speichert `bump`,
`post_purchase`, `standalone`. `statamic-booking` speichert `booked`, `cancelled`. Dieses Addon
hatte in 1.0.0 `zugang`, `termin`, `sitzungen` und `kohorte` — deutsche Werte in einer englischen
Codebasis, in einer Spalte, die ein Käufer in seiner eigenen Datenbank liest.

Neu: `access`, `event`, `sessions`, `cohort`. `download` und `feed` waren schon englisch.

Eine Migration schreibt vorhandene Zeilen um; sie ist umkehrbar, weil ein Rollback, der Werte
zurücklässt, die der alte Code nicht kennt, kein Rollback ist. Aufgefallen ist es beim Audit des
Statamic Addon Studio, wenige Stunden nach 1.0.0 und bevor jemand installiert hatte — ein
gespeicherter Wert ist ab der ersten Installation festgeschrieben.

**Wer 1.0.0 schon installiert hat, braucht nur `php artisan migrate`.**

## 1.0.0 — 2026-08-30

Erste Fassung. Braucht `goldnead/statamic-payments` **1.15** — dort sitzt
`Catalogue::contribute()`, ohne das ein Produkt zwar kaufbar wäre, aber in keiner Auswahl
auftauchte.

### Neu: ein Produkt ist endlich ein Ding

Ein Produkt lag bisher auf drei Stellen verteilt, und keine wusste, was es ist: `statamic-offers`
wusste, wie man eines präsentiert, `statamic-payments` wusste, was eines kostet — eine Zeile in
einer Config-Datei, ohne Bildschirm —, und `statamic-entitlements` wusste, dass jemand Zugang dazu
hat, als freie Zeichenkette. Das Ding selbst gab es nirgends, also erfand es jede Website neu. Auf
adriangoldner.com wurde es zweimal erfunden, als `member_packages` und als `access_packages`, und
die beiden liefen auseinander.

Eine Tabelle, ein Bildschirm unter **Hilfsmittel → Produkte**, und zwei Anschlüsse an den Katalog
des Zahlungs-Addons. Mehr nicht: **das Addon liefert nichts aus.** Was ein Kurs *anzeigt*, bleibt
Sache der Website. Es sagt, dass ein Kurs existiert, was er kostet und was er öffnet.

### Neu: eine Art und ein Zeiger

`type` und `ref`. Sechs Arten: Download, Zugang, Termin, Sitzungen, Kohorte, Feed.

**Die Art ist eine Auskunft, kein Automat.** Ein Produkt „Termin" zu nennen sagt, dass es ein
Live-Datum ist; es reserviert keinen Platz. Bei Kajabi und Podia ist die Produktart die
Auslieferung selbst — die Kursart *ist* der Player —, und dieser Weg endet darin, Kursplayer,
Community-Engine, Terminverwaltung und Podcast-Hosting selbst zu bauen. Ausgeliefert wird auf der
Website und in den Nachbar-Addons. Manche davon gibt es noch nicht.

Deshalb hat ein Zeiger **drei** Zustände, nicht zwei: gefunden, weg, und *niemand hier kann es
sagen*. Die beiden letzten zu verwechseln heißt entweder, ein einwandfreies Produkt anzuklagen,
oder einen kaputten Zeiger durchzuwinken, weil das Paket fehlt, das ihn bemerkt hätte. Ein
Termin-Produkt lässt sich anlegen, bevor `statamic-events` installiert ist.

**Was ins Leere zeigt, wird gezählt, nicht nur markiert.** Das Abzeichen an der Zeile sagt, welches
Produkt betroffen ist; die Zahl über der Tabelle sagt, dass überhaupt welche betroffen sind. Jede
Spalte im Control Panel lässt sich abwählen, und ein Katalog, der sauber aussieht, weil jemand eine
Spalte ausgeblendet hat, ist genau der stille Fehler, gegen den das Feld gebaut ist.

`statamic-events` und `statamic-booking` sind optional und liegen als `require-dev` bei — damit die
Auflösung gegen deren echte Migration und deren echtes Modell getestet wird und nicht gegen eine
Tabelle, die dieses Addon sich selbst ausgedacht hat.

### Die Entscheidungen, die drinstecken

**Die Kennung ist über alle Marken eindeutig, auch wenn die Zeile es nicht ist.** Sie steht auf
Zahlungszeilen und Rechnungen, die in Jahren noch lesbar sein müssen, und keine dieser Stellen
kennt eine Marke — ein Anbieter-Webhook am wenigsten. Eine Agentur mit drei Marken benennt ihre
Produkte deshalb auseinander. Das kostet etwas, und es kostet weniger als ein Webhook, der nicht
bepreisen kann, was er geschickt bekam.

**Die Kennung eines verkauften Produkts ist festgeschrieben.** Umbenennen bricht nichts laut — es
sorgt dafür, dass eine alte Rechnung eine Zeile zeigt, deren Produkt niemand mehr findet. Alles
andere daran bleibt änderbar; ein Preis, der sich nach dem ersten Verkauf nie mehr korrigieren
ließe, wäre die schlechtere Regel.

**Ein verkauftes Produkt wird nicht gelöscht, sondern stillgelegt.** Der Löschknopf sagt nein,
statt still etwas anderes zu tun.

**`digital` hat keine Vorauswahl.** Es ist keine Beschreibung des Mediums, sondern die Angabe, die
über den Leistungsort und damit über den Pflichthinweis auf der Rechnung entscheidet (§ 3a UStG).
Jede Vorbelegung ist für die Hälfte eines Katalogs falsch, und eine falsche Vorbelegung zeigt sich
als Steuerzeile, die niemand geprüft hat.

**Config schlägt Tabelle.** Ein Preis in einer Datei steht in der Versionsverwaltung und wurde
absichtlich hingeschrieben. Die Kollision wird trotzdem gezeigt — Abzeichen in der Liste, Warnung
im Formular —, weil zwei Wahrheiten über einen Preis genau die Krankheit sind, an der eine Kasse
330 abbuchte, während der Katalog 332 sagte.

**Preise auflösen braucht keine Marke, Produkte auflisten schon.** `extend()` wird von allem
erreicht, was ein Browser schickt, und von einem Webhook Stunden nach dem Kauf; `contribute()` nur
von einem Bildschirm. Der eine antwortet ungefiltert, der andere fällt zu.

**Eine fehlende Tabelle nimmt die Kasse nicht mit.** Zwischen `composer require` und
`php artisan migrate` liegen auf einem echten Host Minuten und auf einer vergessenen Staging-Box
Monate. In diesem Fenster antwortet der Katalog, als wäre das Addon nicht installiert — und
schreibt es ins Log, weil ein leerer Katalog und ein kaputter von außen gleich aussehen.
