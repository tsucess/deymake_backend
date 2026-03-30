<?php

return array_replace_recursive(require __DIR__.'/../en/messages.php', [
    'home' => ['retrieved' => 'Les données de la page d’accueil ont été récupérées avec succès.'],
    'categories' => ['retrieved' => 'Les catégories ont été récupérées avec succès.'],
    'profile' => [
        'retrieved' => 'Le profil a été récupéré avec succès.',
        'updated' => 'Le profil a été mis à jour avec succès.',
    ],
    'feeds' => [
        'posts_retrieved' => 'Les publications ont été récupérées avec succès.',
        'liked_retrieved' => 'Les vidéos aimées ont été récupérées avec succès.',
        'saved_retrieved' => 'Les vidéos enregistrées ont été récupérées avec succès.',
        'drafts_retrieved' => 'Les brouillons vidéo ont été récupérés avec succès.',
    ],
    'preferences' => [
        'retrieved' => 'Les préférences ont été récupérées avec succès.',
        'updated' => 'Les préférences ont été mises à jour avec succès.',
    ],
    'notifications' => [
        'new_message_title' => 'Nouveau message',
        'comment_title' => 'Nouveau commentaire sur votre vidéo',
        'comment_body' => ':name a commenté votre vidéo.',
        'reply_title' => 'Nouvelle réponse à votre commentaire',
        'reply_body' => ':name a répondu à votre commentaire.',
        'comment_like_title' => 'Mention J’aime sur votre commentaire',
        'comment_like_body' => ':name a aimé votre commentaire.',
        'comment_dislike_title' => 'Mention Je n’aime pas sur votre commentaire',
        'comment_dislike_body' => ':name n’a pas aimé votre commentaire.',
        'subscription_title' => 'Nouvel abonné',
        'subscription_body' => ':name s’est abonné à votre profil.',
        'live_now_title' => ':name est en direct',
        'live_now_body' => 'Rejoignez maintenant le direct de :name.',
        'video_like_title' => 'Mention J’aime sur votre vidéo',
        'video_like_body' => ':name a aimé votre vidéo.',
        'video_dislike_title' => 'Mention Je n’aime pas sur votre vidéo',
        'video_dislike_body' => ':name n’a pas aimé votre vidéo.',
    ],
    'videos' => [
        'live_started' => 'Le direct a démarré avec succès.',
        'live_stopped' => 'Le direct a été arrêté avec succès.',
        'only_video_can_go_live' => 'Seules les vidéos peuvent être diffusées en direct.',
    ],
    'validation' => ['language_supported' => 'Veuillez choisir une langue d’application prise en charge.'],
]);