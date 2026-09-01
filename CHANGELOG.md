# Changelog

## Unreleased

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
