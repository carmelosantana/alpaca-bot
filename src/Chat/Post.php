<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress\Chat;

class Post
{
    public function __construct()
    {
        add_action('init', [$this, 'register']);
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu()
    {
        if (WP_DEBUG) {
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
            'public' => WP_DEBUG,
            'supports' => [
                'title',
                'editor',
                'excerpt',
                'author',
            ],
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'show_in_menu' => 'admin.php?page=ollama-press',
        ]);
    }
}
