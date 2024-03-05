<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Log;

class Post
{
    public function __construct()
    {
        add_action('init', [$this, 'register']);
        add_action('admin_head', [$this, 'disableAddNew']);
        add_action('admin_init', [$this, 'redirectToLogs']);

        add_filter('manage_chat_log_posts_columns', [$this, 'registerCustomColumns']);
        add_action('manage_chat_log_posts_custom_column', [$this, 'customColumns'], 10, 2);
    }
    // add to alpaca-bot menu
    public function register(): void
    {
        $labels = array(
            'name' => _x('Logs', 'Post type general name', AB_SLUG),
            'singular_name' => _x('Log', 'Post type singular name', AB_SLUG),
            'menu_name' => _x('Logs', 'Admin Menu text', AB_SLUG),
            'name_admin_bar' => _x('Log', 'Add New on Toolbar', AB_SLUG),
            'all_items' => __('Logs', AB_SLUG),
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
        );

        register_post_type('chat_log', $args);
    }

    // register custom columns to show metadata and tokens per second which is the result of eval_duration / eval_count
    public function registerCustomColumns($columns)
    {
        unset($columns['title']);
        unset($columns['date']);

        $columns['time'] = __('Time Ago', AB_SLUG);
        $columns['model'] = __('Model', AB_SLUG);
        $columns['total_duration'] = __('Total Duration', AB_SLUG);
        $columns['load_duration'] = __('Load Duration', AB_SLUG);
        $columns['prompt_eval_count'] = __('Prompt Eval Count', AB_SLUG);
        $columns['prompt_eval_duration'] = __('Prompt Eval Duration', AB_SLUG);
        $columns['eval_count'] = __('Eval Count', AB_SLUG);
        $columns['eval_duration'] = __('Eval Duration', AB_SLUG);
        $columns['tokens_per_second'] = __('Tokens Per Second', AB_SLUG);

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
}
