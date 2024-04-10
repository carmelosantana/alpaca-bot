<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Chat;

use CarmeloSantana\AlpacaBot\Define;
use CarmeloSantana\AlpacaBot\Api\Render;
use CarmeloSantana\AlpacaBot\Utils\Options;

class Screen
{
    private object $htmx;

    public function addFooterActions()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
    }

    public function addFooterFilters()
    {
        // Apply to footer only on this page
        add_filter('admin_footer_text', function () {
            esc_html_e('Always verify important information to ensure accuracy.', 'alpaca-bot');
        });

        // Change the footer version to the plugin version
        add_filter('update_footer', function ($footer) {
            // add url to alpaca.bot
            $footer = '<a href="https://alpaca.bot" target="_blank">Alpaca Bot</a>'  . ' v' . \CarmeloSantana\AlpacaBot\VERSION;
            return $footer;
        }, 11);
    }

    public function getAdminUrl(string $mode = '')
    {
        $query = [
            'page' => ALPACA_BOT . ($mode ? '-' . $mode : ''),
        ];
        return add_query_arg($query, admin_url('admin.php'));
    }

    public function getMode(string $default = '')
    {
        // get_current_screen()->id
        // toplevel_page_alpaca-bot = chat
        // alpaca-bot_page_alpaca-bot-generate = generate
        $screen = get_current_screen();

        switch ($screen->id) {
            case 'toplevel_page_alpaca-bot':
                return 'chat';
            case 'alpaca-bot_page_alpaca-bot-generate':
                return 'generate';
            default:
                return $default;
        }
    }

    public function outputTitleHeader($page_title = ALPACA_BOT_TITLE)
    { ?>
        <div class="header-wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html($page_title); ?></h1>
            <div class="ab-dropdown">
                <?php
                switch ($this->getMode()) {
                    case 'generate':
                        echo '<a href="' . esc_url($this->getAdminUrl('generate')) . '" class="page-title-action">New Generation <span class="material-symbols-outlined">expand_more</span></a>';
                        break;
                    default:
                        echo '<a href="' . esc_url($this->getAdminUrl()) . '" class="page-title-action">New Chat <span class="material-symbols-outlined">expand_more</span></a>';
                        break;
                }

                ?>
                <div class="ab-dropdown-content">
                    <a href="<?php echo esc_url($this->getAdminUrl()); ?>">
                        <span class="material-symbols-outlined">forum</span>
                        <strong>Multi-turn</strong>
                        <p>Ideal for tasks requiring back-and-forth interactions and providing a natural conversational experience.</p>
                        <p>• Conversation context</p>
                    </a>
                    <a href="<?php echo esc_url($this->getAdminUrl('generate')); ?>">
                        <span class="material-symbols-outlined">chat_apps_script</span>
                        <strong>Single-turn</strong>
                        <p>Optimal for content generation, summarization, and question-answering.</p>
                        <p>• Customizable assistants</p>
                    </a>
                </div>
            </div>
            <hr class="wp-header-end">
        </div>
    <?php }

    public function outputChatForm()
    {
        $nonce = wp_create_nonce('wp_rest');
    ?>
        <form id="ab-chat-form" hx-headers='{"X-WP-Nonce": "<?php echo esc_attr($nonce); ?>"}'>
            <div id="ab-chat-container" class="wrap nosubsub">
                <?php $this->outputTitleHeader(); ?>
                <div class="ab-chat">
                    <?php $this->outputChatToolbar(); ?>
                    <?php $this->outputChatWelcomeMessage(); ?>
                    <?php $this->outputResponseContainer(); ?>
                </div>
            </div>
            <?php $this->outputChatTextarea(); ?>
        </form>
    <?php
    }

    public function outputChatTextarea()
    {
        switch ($this->getMode()) {
            case 'generate':
                if ($default_placeholder = Options::get('default_assistant_prompt_placeholder')) {
                    $placeholder = apply_filters(Options::appendPrefix('default_assistant_prompt_placeholder'), $default_placeholder);
                }

            default:
                if (!isset($placeholder)) {
                    $placeholder = Define::fields()['default_assistant_prompt_placeholder']['placeholder'];
                }
                break;
        }
    ?>
        <div class="typing-container">
            <div class="typing-content">
                <div class="typing-textarea">
                    <textarea name="message" id="message" <?php if (!Options::get('spellcheck')) echo ' spellcheck="false" '; ?>placeholder="<?php echo esc_html($placeholder); ?>" required></textarea>
                    <input type="hidden" name="chat_wpnonce" id="chat_wpnonce" value="<?php echo esc_html(wp_create_nonce('wp_rest')); ?>">
                    <input type="hidden" name="prompt" id="prompt">
                    <input type="hidden" name="chat_id" id="chat_id" value="0">
                    <input type="hidden" name="chat_mode" id="chat_mode" value="<?php echo esc_html($this->getMode('chat')); ?>">
                    <button type="submit" name="submit" id="submit" class="material-symbols-outlined" <?php echo wp_kses($this->htmx->getHxMultiSwapLoadChat('htmx/chat'), Options::getAllowedTags('htmx')); ?>>arrow_circle_up</button>
                </div>
            </div>
        </div>
    <?php }

    public function outputChatToolbar()
    { ?>
        <div class="ab-toolbar">
            <div class="ab-tags">
                <?php if (Options::get('user_can_change_model')) { ?>
                    <p><strong>Model</strong></p>
                    <p hx-post="<?php echo esc_url($this->htmx->getRenderEndpoint('wp/user/update')); ?>" hx-vals='{"set_default_model": true}' id="set_default_model"></p>
                    <select name="model" id="model" onclick="setDefaultModel()"></select>
                    <input type="hidden" hx-get="<?php echo esc_url($this->htmx->getRenderEndpoint('htmx/tags')); ?>" hx-trigger="load" hx-target="#model">
                <?php } else { ?>
                    <p><strong>Model</strong></p><code><?php echo esc_html(Options::get('default_model')); ?></code>
                <?php } ?>
            </div>
            <div class="ab-chat-logs">
                <?php if (Options::get('chat_history_save')) { ?>
                    <p><strong>Chat History</strong></p>
                    <select name="chat_history_id" id="chat_history_id" <?php echo wp_kses($this->htmx->getHxMultiSwapLoadChat('wp/chat', 'change'), Options::getAllowedTags('htmx')); ?>></select>
                    <input type="hidden" hx-post="<?php echo esc_url($this->htmx->getRenderEndpoint('wp/history')); ?>" hx-trigger="load" hx-target="#chat_history_id">
                <?php } ?>
            </div>
        </div>
    <?php
    }

    public function outputChatWelcomeMessage()
    {
        switch ($this->getMode()) {
            case 'generate':
                if ($default_avatar = Options::get('default_avatar')) {
                    $gravatar = apply_filters(Options::appendPrefix('default_avatar'), $default_avatar);
                }
                if ($default_welcome = Options::get('default_assistant_welcome_message')) {
                    $welcome = apply_filters(Options::appendPrefix('default_assistant_welcome_message'), $default_welcome);
                }

            default:
                if (!isset($gravatar)) {
                    $gravatar = $this->htmx->getAssistantAvatarUrl('system');
                }
                if (!isset($welcome)) {
                    $welcome = Define::fields()['default_assistant_welcome_message']['placeholder'];
                }
                break;
        }
    ?>
        <div id="ab-hello">
            <img src="<?php echo esc_url($gravatar); ?>" alt="gravatar">
            <p><?php echo esc_html($welcome); ?></p>
        </div>
    <?php }

    public function outputResponseContainer()
    { ?>
        <div id="ab-response">
            <!-- htmx response -->
        </div>
        <img id="indicator" class="htmx-indicator" src="<?php echo esc_html(ALPACA_BOT_DIR_URL); ?>assets/img/grid.svg">
<?php }

    public function render()
    {
        $this->htmx = new Render(get_current_user_id());

        $this->addFooterActions();
        $this->addFooterFilters();

        // TODO use apply filters to allow for custom chat forms
        $this->outputChatForm();
    }
}
