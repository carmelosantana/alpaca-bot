<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

use CarmeloSantana\AlpacaBot\Utils\Options;
use Parsedown;

class Help
{
    public function __construct()
    {
        add_action('admin_head', [$this, 'addHelpTabs']);
        add_filter('plugin_action_links_' . AB_SLUG . '/' . AB_SLUG . '.php', [$this, 'addPluginActionLinks']);
        add_filter('plugin_row_meta', [$this, 'addPluginRowMeta'], 10, 2);
    }


    public function addHelpTabs()
    {
        $screen = get_current_screen();

        $help_full = [
            'toplevel_page_' . AB_SLUG,
            AB_SLUG . '_page_' . Options::appendPrefix('agents', '-'),
            AB_SLUG . '_page_' . Options::appendPrefix('settings', '-'),
            'edit-log',
            'edit-chat',
        ];

        $help_short = [
            'post',
            'page',
        ];

        $ignore = $include = [];

        if (in_array($screen->id, $help_full)) {
            $ignore = [
                'Requirements',
                'Installation'
            ];
        } elseif (in_array($screen->id, $help_short)) {
            $include = [
                'Shortcodes',
                'Agents',
            ];
        } else {
            return;
        }

        // Load README.md
        $readme = file_get_contents(AB_DIR_PATH . 'README.md');

        // Parse README.md
        $parsedown = new Parsedown();

        // convert README like an array, create arrays from each h2 section
        $readmeArray = explode('<h2>', $parsedown->text($readme));

        $sections = [];
        foreach ($readmeArray as $section) {
            $section = explode('</h2>', $section);
            if (count($section) > 1) {
                $sections[trim($section[0])] = trim($section[1]);
            }
        }

        // Add help tabs
        foreach ($sections as $title => $content) {
            if (in_array($title, $ignore)) {
                continue;
            }

            if (!empty($include) and !in_array($title, $include)) {
                continue;
            }

            $screen->add_help_tab(array(
                'id' => Options::appendPrefix($title),
                'title' => __($title),
                'content' => $content,
            ));
        }

        $sidebar = '<p><strong>Support Alpaca Bot</strong></p>';

        $sidebar .= '<ul>';
        foreach (Define::support() as $source => $options) {
            $sidebar .= '<li><a href="' . $options['url'] . '" target="_blank">' . $options['title'] . '</a></li>';
        }
        $sidebar .= '</ul>';

        $screen->set_help_sidebar($sidebar);
    }

    public function addPluginActionLinks($links)
    {
        $links[] = '<a href="' . admin_url('admin.php?page=' . AB_SLUG) . '">Chat</a>';
        $links[] = '<a href="' . admin_url('admin.php?page=' . Options::appendPrefix('settings', '-')) . '">Settings</a>';

        return $links;
    }

    public function addPluginRowMeta($links, $file)
    {
        if ($file === AB_SLUG . '/' . AB_SLUG . '.php') {
            $links[] = '<a href="' . Define::support()['discord']['url'] . '" target="_blank">Discord</a>';
            $links[] = '<a href="' . Define::support()['patreon']['url'] . '" target="_blank">Patreon</a>';
        }

        return $links;
    }
}
