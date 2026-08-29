<?php

return [
    'utility_title' => 'Produkte',
    'utility_nav' => 'Produkte',
    'utility_description' => 'Was es zu kaufen gibt, was es kostet und was es freischaltet.',

    'empty_heading' => 'Noch keine Produkte',
    'empty_title' => 'Hier ist noch nichts',
    'empty_description' => 'Ein Produkt ist das Ding selbst: ein Name, ein Listenpreis und die Zugänge, die ein bezahltes Exemplar öffnet. Wie es beworben und zu welchem Preis es gerade angeboten wird, entscheiden Angebote.',

    'column_name' => 'Produkt',
    'column_handle' => 'Kennung',
    'column_amount' => 'Listenpreis',
    'column_digital' => 'Leistung',
    'column_grants' => 'Schaltet frei',
    'column_active' => 'Aktiv',

    'new_product' => 'Neues Produkt',
    'edit_product' => 'Produkt bearbeiten',
    'saved' => '„:name" gespeichert.',
    'deleted' => 'Produkt gelöscht.',
    'delete_title' => 'Produkt löschen',
    'delete_body' => '„:name" wird gelöscht. Angebote, die darauf zeigen, lassen sich danach nicht mehr verkaufen.',
    'delete_refused_sold' => 'Dieses Produkt wurde schon verkauft und kann nicht gelöscht werden. Seine Kennung steht auf Zahlungen und Rechnungen, die weiterhin lesbar bleiben müssen. Setz es stattdessen auf inaktiv.',

    'field_name' => 'Name',
    'field_name_help' => 'Steht auf der Rechnung, also so, wie ein Käufer es wiedererkennt.',
    'field_handle' => 'Kennung',
    'field_handle_help' => 'Damit sprechen Angebote und Rechnungen das Produkt an. Kleinbuchstaben, Ziffern, Binde- und Unterstriche.',
    'handle_frozen' => 'Dieses Produkt wurde bereits verkauft. Die Kennung steht damit auf Zahlungszeilen und Rechnungen, die sich nicht mitziehen lassen — sie bleibt, wie sie ist.',
    'sold_note' => 'Schon verkauft. Die Kennung ist festgeschrieben.',

    'field_amount' => 'Listenpreis',
    'field_amount_help' => 'In Cent. 0 heißt kostenlos.',
    'field_currency' => 'Währung',
    'field_currency_help' => 'Leer lassen: die des Shops.',

    'field_digital' => 'Art der Leistung',
    'field_digital_help' => 'Steuerangabe, kein Medium: entscheidet über den Leistungsort und den Pflichthinweis auf der Rechnung. Bewusst ohne Vorauswahl.',
    'digital_yes' => 'Elektronisch erbracht',
    'digital_no' => 'Vor Ort oder auf Papier',

    'field_grants' => 'Schaltet frei',
    'field_grants_help' => 'Was ein bezahltes Exemplar öffnet. Mehrere sind erlaubt, leer heißt: nichts.',
    'field_grants_placeholder' => 'Zugang hinzufügen',

    'column_type' => 'Art',
    'column_ref' => 'Zeigt auf',

    'field_type' => 'Art',
    'field_type_help' => 'Eine Auskunft, kein Automat: ausgeliefert wird auf der Website.',
    'field_ref_help' => 'Zeigt auf das Ding dieser Art. Bleibt leer bei einem Download.',

    'type_download' => 'Download',
    'type_download_description' => 'PDF, Workbook, Aufzeichnung. Das Ding ist die Datei selbst.',
    'type_zugang' => 'Zugang',
    'type_zugang_description' => 'Kurs, Mitgliederbereich, Community.',
    'type_termin' => 'Termin',
    'type_termin_description' => 'Live-Event, Workshop, Konzert, Webinar.',
    'type_sitzungen' => 'Sitzungen',
    'type_sitzungen_description' => 'Ein Paket aus mehreren Terminen, 1:1 oder in der Gruppe.',
    'type_kohorte' => 'Kohorte',
    'type_kohorte_description' => 'Programm mit Start, Ende und fester Gruppe.',
    'type_feed' => 'Feed',
    'type_feed_description' => 'Bezahlter Podcast oder Newsletter.',

    'ref_download' => 'Kein Ziel',
    'ref_zugang' => 'Statamic-Eintrag (ID)',
    'ref_termin' => 'Termin aus statamic-events (UUID)',
    'ref_sitzungen' => 'Buchungsstrecke aus statamic-booking (Kennung)',
    'ref_kohorte' => 'Statamic-Eintrag (ID)',
    'ref_feed' => 'Statamic-Collection (Kennung)',

    'dangling_banner' => '{1} Ein Produkt zeigt auf etwas, das es nicht gibt. Verkauft man es so, ist bezahlt und nichts dahinter.|[2,*] :count Produkte zeigen auf etwas, das es nicht gibt. Verkauft man sie so, ist bezahlt und nichts dahinter.',
    'ref_missing_badge' => 'Ziel fehlt',
    'ref_missing_warning' => 'Das Ziel dieser Kennung existiert nicht. Wird das Produkt so verkauft, ist bezahlt und nichts dahinter — und niemand merkt es, weil kein Fehler entsteht.',

    'field_active' => 'Aktiv',

    'shadowed_badge' => 'Aus der Config',
    'shadowed_warning' => 'Diese Kennung steht auch in der Config-Datei, und die gewinnt. Verkauft wird der Preis aus der Datei, nicht der hier eingetragene. Entweder die Zeile aus der Config nehmen oder dieses Produkt anders benennen.',

    'yes' => 'Ja',
    'no' => 'Nein',
];
