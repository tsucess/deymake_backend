<?php

return array_replace_recursive(require __DIR__.'/../en/messages.php', [
    'home' => ['retrieved' => 'We don load homepage data successfully.'],
    'categories' => ['retrieved' => 'We don load categories successfully.'],
    'profile' => [
        'retrieved' => 'We don load profile successfully.',
        'updated' => 'We don update profile successfully.',
    ],
    'feeds' => [
        'posts_retrieved' => 'We don load your posts successfully.',
        'liked_retrieved' => 'We don load videos wey you like successfully.',
        'saved_retrieved' => 'We don load saved videos successfully.',
        'drafts_retrieved' => 'We don load draft videos successfully.',
    ],
    'preferences' => [
        'retrieved' => 'We don load your settings successfully.',
        'updated' => 'We don update your settings successfully.',
    ],
    'validation' => ['language_supported' => 'Abeg choose app language wey we support.'],
]);