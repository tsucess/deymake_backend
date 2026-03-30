<?php

return array_replace_recursive(require __DIR__.'/../en/messages.php', [
    'home' => ['retrieved' => 'An samo bayanan shafin gida cikin nasara.'],
    'categories' => ['retrieved' => 'An samo rukunai cikin nasara.'],
    'profile' => [
        'retrieved' => 'An samo bayanan profile cikin nasara.',
        'updated' => 'An sabunta bayanan profile cikin nasara.',
    ],
    'feeds' => [
        'posts_retrieved' => 'An samo wallafe-wallafenka cikin nasara.',
        'liked_retrieved' => 'An samo bidiyoyin da ka so cikin nasara.',
        'saved_retrieved' => 'An samo bidiyoyin da ka ajiye cikin nasara.',
        'drafts_retrieved' => 'An samo daftarin bidiyo cikin nasara.',
    ],
    'preferences' => [
        'retrieved' => 'An samo saitunan ka cikin nasara.',
        'updated' => 'An sabunta saitunan ka cikin nasara.',
    ],
    'validation' => ['language_supported' => 'Da fatan zaɓi harshen manhaja da ake tallafawa.'],
]);