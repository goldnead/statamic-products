<?php

return [
    'utility_title' => 'Products',
    'utility_nav' => 'Products',
    'utility_description' => 'What there is to buy, what it costs, and what it opens.',

    'empty_heading' => 'No products yet',
    'empty_title' => 'Nothing here yet',
    'empty_description' => 'A product is the thing itself: a name, a list price, and the access a paid copy opens. How it is presented, and what it costs right now, is an offer.',

    'column_name' => 'Product',
    'column_handle' => 'Handle',
    'column_amount' => 'List price',
    'column_digital' => 'Supply',
    'column_grants' => 'Opens',
    'column_active' => 'Active',

    'new_product' => 'New product',
    'edit_product' => 'Edit product',
    'saved' => '":name" saved.',
    'deleted' => 'Product deleted.',
    'delete_title' => 'Delete product',
    'delete_body' => '":name" will be deleted. Offers pointing at it can no longer be sold.',
    'delete_refused_sold' => 'This product has been sold and cannot be deleted. Its handle is on payments and invoices that still have to render. Set it to inactive instead.',

    'field_name' => 'Name',
    'field_name_help' => 'Goes onto the invoice, so write it the way a buyer would recognise it.',
    'field_handle' => 'Handle',
    'field_handle_help' => 'How offers and invoices refer to it. Lowercase letters, digits, hyphens and underscores.',
    'handle_frozen' => 'This product has already been sold. Its handle is on payment lines and invoices that cannot be migrated along with it, so it stays as it is.',
    'sold_note' => 'Already sold. The handle is fixed.',

    'field_amount' => 'List price',
    'field_amount_help' => 'In cents. 0 means free.',
    'field_currency' => 'Currency',
    'field_currency_help' => 'Leave empty for the shop currency.',

    'field_digital' => 'Kind of supply',
    'field_digital_help' => 'A tax fact, not a medium: it decides the place of supply and the mandatory notice on the invoice. Deliberately unselected.',
    'digital_yes' => 'Electronically supplied',
    'digital_no' => 'In person or on paper',

    'field_grants' => 'Opens',
    'field_grants_help' => 'What a paid copy opens. More than one is fine; empty means nothing.',
    'field_grants_placeholder' => 'Add access',

    'column_type' => 'Kind',
    'column_ref' => 'Points at',

    'field_type' => 'Kind',
    'field_type_help' => 'An answer, not an instruction: delivery happens on the website.',
    'field_ref_help' => 'Points at the thing of that kind. Empty for a download.',

    'type_download' => 'Download',
    'type_download_description' => 'PDF, workbook, recording. The thing is the file itself.',
    'type_zugang' => 'Access',
    'type_zugang_description' => 'A course, a members area, a community.',
    'type_termin' => 'Date',
    'type_termin_description' => 'Live event, workshop, concert, webinar.',
    'type_sitzungen' => 'Sessions',
    'type_sitzungen_description' => 'A package of several appointments, one to one or in a group.',
    'type_kohorte' => 'Cohort',
    'type_kohorte_description' => 'A programme with a start, an end and a fixed group.',
    'type_feed' => 'Feed',
    'type_feed_description' => 'A paid podcast or newsletter.',

    'ref_download' => 'No target',
    'ref_zugang' => 'Statamic entry (ID)',
    'ref_termin' => 'Event from statamic-events (UUID)',
    'ref_sitzungen' => 'Booking funnel from statamic-booking (handle)',
    'ref_kohorte' => 'Statamic entry (ID)',
    'ref_feed' => 'Statamic collection (handle)',

    'dangling_banner' => '{1} One product points at something that does not exist. Sold like that, the buyer pays and gets nothing.|[2,*] :count products point at something that does not exist. Sold like that, the buyer pays and gets nothing.',
    'ref_missing_badge' => 'Target gone',
    'ref_missing_warning' => 'What this identifier points at does not exist. Sold like this, the buyer pays and gets nothing behind it — and nobody notices, because no error happens.',

    'field_active' => 'Active',

    'shadowed_badge' => 'From config',
    'shadowed_warning' => 'This handle is also a line in the config file, and config wins. What gets charged is the price in the file, not the one entered here. Either remove the config line or name this product differently.',

    'yes' => 'Yes',
    'no' => 'No',
];
