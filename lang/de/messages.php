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

    'field_active' => 'Aktiv',

    'shadowed_badge' => 'Aus der Config',
    'shadowed_warning' => 'Diese Kennung steht auch in der Config-Datei, und die gewinnt. Verkauft wird der Preis aus der Datei, nicht der hier eingetragene. Entweder die Zeile aus der Config nehmen oder dieses Produkt anders benennen.',

    'yes' => 'Ja',
    'no' => 'Nein',
];
