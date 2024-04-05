<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

use CarmeloSantana\AlpacaBot\Define;
use CarmeloSantana\AlpacaBot\Utils\Options;
use Parsedown;

class Help
{
    public function __construct()
    {
        add_action('admin_head', [$this, 'addHelpTabs']);
        add_filter('plugin_action_links_' . ALPACA_BOT . '/' . ALPACA_BOT . '.php', [$this, 'addPluginActionLinks']);
        add_filter('plugin_row_meta', [$this, 'addPluginRowMeta'], 10, 2);
    }

    public function addHelpTabs()
    {
        $screen = get_current_screen();

        $help_short = [
            'post',
            'page',
        ];

        $ignore = $include = [];

        if (in_array($screen->id, Define::getAdminPages())) {
            $ignore = [
                'Screenshots',
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

        // https://developer.wordpress.org/apis/filesystem/
        $url = admin_url('admin.php?page=' . ALPACA_BOT);
        $creds = request_filesystem_credentials($url, '', false, false, null);

        if (!WP_Filesystem($creds)) {
            request_filesystem_credentials($url, '', true, false, null);
            return;
        }

        // Load README.md
        global $wp_filesystem;
        $readme = $wp_filesystem->get_contents(ALPACA_BOT_DIR_PATH . 'README.md');

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
                'title' => $title,
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
        $links[] = '<a href="' . admin_url('admin.php?page=' . ALPACA_BOT) . '">Chat</a>';
        $links[] = '<a href="' . admin_url('admin.php?page=' . Options::appendPrefix('settings', '-')) . '">Settings</a>';

        return $links;
    }

    public function addPluginRowMeta($links, $file)
    {
        if ($file === ALPACA_BOT . '/' . ALPACA_BOT . '.php') {
            $links[] = '<a href="' . Define::support()['discord']['url'] . '" target="_blank">Discord</a>';
            $links[] = '<a href="' . Define::support()['patreon']['url'] . '" target="_blank">Patreon</a>';
        }

        return $links;
    }
}
