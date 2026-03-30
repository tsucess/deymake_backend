<?php

return array_replace_recursive(require __DIR__.'/../en/messages.php', [
    'home' => ['retrieved' => 'Data za ukurasa wa mwanzo zimepatikana kwa mafanikio.'],
    'categories' => ['retrieved' => 'Kategoria zimepatikana kwa mafanikio.'],
    'profile' => [
        'retrieved' => 'Wasifu umepatikana kwa mafanikio.',
        'updated' => 'Wasifu umesasishwa kwa mafanikio.',
    ],
    'feeds' => [
        'posts_retrieved' => 'Machapisho yako yamepatikana kwa mafanikio.',
        'liked_retrieved' => 'Video ulizopenda zimepatikana kwa mafanikio.',
        'saved_retrieved' => 'Video ulizohifadhi zimepatikana kwa mafanikio.',
        'drafts_retrieved' => 'Rasimu za video zimepatikana kwa mafanikio.',
    ],
    'preferences' => [
        'retrieved' => 'Mapendeleo yako yamepatikana kwa mafanikio.',
        'updated' => 'Mapendeleo yako yamesasishwa kwa mafanikio.',
    ],
    'validation' => ['language_supported' => 'Tafadhali chagua lugha ya programu inayotumika.'],
]);