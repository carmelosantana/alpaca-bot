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

        add_filter('manage_log_posts_columns', [$this, 'registerCustomColumns']);
        add_action('manage_log_posts_custom_column', [$this, 'customColumns'], 10, 2);
    }
    // add to alpaca-bot menu
    public function register(): void
    {
        $labels = array(
            'name' => _x('Logs', 'Post type general name', 'textdomain'),
            'singular_name' => _x('Log', 'Post type singular name', 'textdomain'),
            'menu_name' => _x('Logs', 'Admin Menu text', 'textdomain'),
            'name_admin_bar' => _x('Log', 'Add New on Toolbar', 'textdomain'),
            'all_items' => __('Logs', 'textdomain'),
        );

        $args = array(
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => 'alpaca-bot',
            'query_var' => true,
            'rewrite' => ['slug' => 'log'],
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => null,
            'supports' => [
                'author',
            ],
        );

        register_post_type('log', $args);
    }

    // register custom columns to show metadata and tokens per second which is the result of eval_duration / eval_count
    public function registerCustomColumns($columns)
    {
        unset($columns['title']);
        unset($columns['date']);
        
        $columns['time'] = __('Time Ago', 'textdomain');
        $columns['model'] = __('Model', 'textdomain');
        $columns['total_duration'] = __('Total Duration', 'textdomain');
        $columns['load_duration'] = __('Load Duration', 'textdomain');
        $columns['prompt_eval_count'] = __('Prompt Eval Count', 'textdomain');
        $columns['prompt_eval_duration'] = __('Prompt Eval Duration', 'textdomain');
        $columns['eval_count'] = __('Eval Count', 'textdomain');
        $columns['eval_duration'] = __('Eval Duration', 'textdomain');
        $columns['tokens_per_second'] = __('Tokens Per Second', 'textdomain');

        return $columns;
    }

    // get data for custom columns
    public function customColumns($column, $post_id)
    {
        switch ($column) {
            case 'time':
                // timeago from published date
                $out = sprintf(
                    '<time class="timeago" datetime="%s">%s</time>',
                    get_the_date('c', $post_id),
                    human_time_diff(get_the_date('U', $post_id), current_time('timestamp'))
                );
                break;

            case 'model':
                $out = get_post_meta($post_id, 'model', true);
                break;

                // durations are in nanoseconds, convert to seconds
            case 'tokens_per_second':
                $eval_count = (int) get_post_meta($post_id, 'eval_count', true);
                $eval_duration = (int) get_post_meta($post_id, 'eval_duration', true);
                $out = $eval_count / ($eval_duration / 1000000000);
                $out = number_format($out, 2);
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
        if ($post_type == 'log') {
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

        if (is_admin() and $pagenow == 'post-new.php' and $post_type == 'log') {
            wp_redirect(admin_url('edit.php?post_type=log'));
            exit;
        }
    }
}
