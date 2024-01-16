<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress\Chat;

use CarmeloSantana\OllamaPress\Options;

class Post
{
    public function __construct()
    {
        add_action('init', [$this, 'register']);
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu()
    {
        if (Options::get('debug', false)) {
            add_submenu_page(
                OP_SLUG,
                __('Chats', OP_SLUG),
                __('Chats', OP_SLUG),
                'manage_options',
                'edit.php?post_type=chat'
            );
        }
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
            'public' => Options::get('debug', false),
            'show_in_menu' => (Options::get('debug', false) ? 'admin.php?page=ollama-press' : false),
        ]);
    }
}
