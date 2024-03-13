<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Log;

use CarmeloSantana\AlpacaBot\Utils\Options;

class Post
{
    public function __construct()
    {
        add_action('init', [$this, 'register']);
        add_action('manage_chat_log_posts_custom_column', [$this, 'customColumns'], 10, 2);

        add_filter('manage_chat_log_posts_columns', [$this, 'registerCustomColumns']);
        add_filter('manage_edit-chat_log_sortable_columns', [$this, 'sortableColumns']);
    }

    // add to alpaca-bot menu
    public function register(): void
    {
        $labels = array(
            'name' => _x('Logs', 'Post type general name', 'alpaca-bot'),
            'singular_name' => _x('Log', 'Post type singular name', 'alpaca-bot'),
            'menu_name' => _x('Logs', 'Admin Menu text', 'alpaca-bot'),
            'name_admin_bar' => _x('Log', 'Add New on Toolbar', 'alpaca-bot'),
            'all_items' => __('Logs', 'alpaca-bot'),
        );

        $args = array(
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => AB_SLUG,
            'query_var' => true,
            'rewrite' => ['slug' => 'chat_log'],
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => null,
            'supports' => [
                'author',
            ],
            'capabilities' => [
                'create_posts' => false,
            ],
            'map_meta_cap' => false,
        );

        register_post_type('chat_log', $args);
    }

    // register custom columns to show metadata and tokens per second which is the result of eval_duration / eval_count
    public function registerCustomColumns($columns)
    {
        unset($columns['title']);
        unset($columns['date']);

        $columns['time'] = __('Time Ago', 'alpaca-bot');
        $columns['model'] = __('Model', 'alpaca-bot');
        $columns['total_duration'] = __('Total Duration', 'alpaca-bot');
        $columns['prompt_eval_count'] = __('Prompt Eval Count', 'alpaca-bot');
        $columns['eval_count'] = __('Eval Count', 'alpaca-bot');
        $columns['tokens_per_second'] = __('Tokens/s', 'alpaca-bot');

        return $columns;
    }

    // get data for custom columns
    public function customColumns($column, $post_id)
    {
        switch ($column) {
            case 'model':
                $out = get_post_meta($post_id, 'model', true);

                $out = sprintf(
                    '<a href="https://ollama.com/library/%s" target="_blank">%s</a>',
                    $out,
                    $out
                );
                break;

            case 'time':
                $out = sprintf(
                    '<time class="timeago" datetime="%s">%s</time>',
                    get_the_date('c', $post_id),
                    human_time_diff(get_the_date('U', $post_id), current_time('timestamp'))
                );
                break;

            case 'tokens_per_second':
                $eval_count = (int) get_post_meta($post_id, 'eval_count', true);
                $eval_duration = (int) get_post_meta($post_id, 'eval_duration', true);
                $out = $eval_count / ($eval_duration / 1000000000);
                // https://stackoverflow.com/a/14531760/1007492
                $out = number_format($out, 1) + 0;
                break;

            default:
                $out = (int) get_post_meta($post_id, $column, true);

                if (strpos($column, 'duration') !== false) {
                    $out = $out / 1000000000;
                    $out = number_format($out, 2);
                }
                break;
        }

        echo $out;
    }

    // disable add new post
    public function disableAddNew()
    {
        // if screen is post_type = log, disable add new
        $post_type = get_current_screen()->post_type ?? false;
        if ($post_type == 'chat_log') {
            echo '<style>
                .page-title-action {
                    display: none !important;
                }
            </style>';
        }
    }

    // redirect away from post-new.php?post_type=log
    public function redirectToLogs()
    {
        global $pagenow;

        $post_type = $_GET['post_type'] ?? false;

        if (is_admin() and $pagenow == 'post-new.php' and $post_type == 'chat_log') {
            wp_redirect(admin_url('edit.php?post_type=chat_log'));
            exit;
        }
    }

    // make custom columns sortable
    public function sortableColumns($columns)
    {
        $columns['total_duration'] = 'total_duration';
        $columns['load_duration'] = 'load_duration';
        $columns['prompt_eval_count'] = 'prompt_eval_count';
        $columns['prompt_eval_duration'] = 'prompt_eval_duration';
        $columns['eval_count'] = 'eval_count';
        $columns['eval_duration'] = 'eval_duration';
        $columns['tokens_per_second'] = 'tokens_per_second';

        return $columns;
    }
}
