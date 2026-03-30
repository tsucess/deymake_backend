<?php

return array_replace_recursive(require __DIR__.'/../en/messages.php', [
    'home' => ['retrieved' => 'Imininingwane yekhasi lasekhaya itholakele ngempumelelo.'],
    'categories' => ['retrieved' => 'Izigaba zitholakele ngempumelelo.'],
    'profile' => [
        'retrieved' => 'Iphrofayela itholakele ngempumelelo.',
        'updated' => 'Iphrofayela ibuyekeziwe ngempumelelo.',
    ],
    'feeds' => [
        'posts_retrieved' => 'Okuthunyelwe kwakho kutholakele ngempumelelo.',
        'liked_retrieved' => 'Amavidiyo owathandile atholakele ngempumelelo.',
        'saved_retrieved' => 'Amavidiyo owagcinile atholakele ngempumelelo.',
        'drafts_retrieved' => 'Okusalungiswa kwamavidiyo kutholakele ngempumelelo.',
    ],
    'preferences' => [
        'retrieved' => 'Okuncamelayo kutholakele ngempumelelo.',
        'updated' => 'Okuncamelayo kubuyekeziwe ngempumelelo.',
    ],
    'validation' => ['language_supported' => 'Sicela ukhethe ulimi lohlelo lokusebenza olusekelwayo.'],
]);