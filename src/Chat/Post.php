<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Chat;

class Post
{
    public function __construct()
    {
        add_action('init', [$this, 'register']);
    }

    public function register(): void
    {
        register_post_type('chat', [
            'delete_with_user' => true,
            'supports' => [
                'title',
                'excerpt',
            ],
            'show_in_rest' => false,
            'exclude_from_search' => true,
            // Debug
            'public' => false,
            'show_in_menu' => false,
        ]);
    }
}
