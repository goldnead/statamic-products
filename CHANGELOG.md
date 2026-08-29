# Changelog

## Unveröffentlicht

Erste Fassung. Noch nicht getaggt: sie braucht `goldnead/statamic-payments` 1.15 mit
`Catalogue::contribute()`, und das ist selbst noch unveröffentlicht.

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
