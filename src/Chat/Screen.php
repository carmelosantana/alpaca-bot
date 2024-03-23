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
        // add custom <script> to admin footer
        add_action('admin_footer', [$this, 'outputScriptZeroMd']);
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

    public static function getMode(string $default = '')
    {
        return Options::inputGet('mode', $default);
    }

    public function outputTitleHeader($page_title = AB_TITLE)
    { ?>
        <div class="header-wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html($page_title); ?></h1>
            <div class="ab-dropdown">
                <a href="<?php echo esc_url(admin_url('admin.php?page=alpaca-bot' . (self::getMode() ? '&mode=' . self::getMode() : ''))); ?>" class="page-title-action">New Chat <span class="material-symbols-outlined">expand_more</span></a>
                <div class="ab-dropdown-content">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=alpaca-bot')); ?>">
                        <span class="material-symbols-outlined">forum</span>
                        <strong>Multi-turn</strong>
                        <p>Ideal for tasks requiring back-and-forth interactions and providing a natural conversational experience.</p>
                        <p>• Conversation context</p>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=alpaca-bot&mode=generate')); ?>">
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
        $this->htmx = new Render(get_current_user_id()); ?>
        <form id="ab-chat-form" <?php echo esc_html($this->htmx->outputWpNonce()); ?>>
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
    { ?>
        <div class="typing-container">
            <div class="typing-content">
                <div class="typing-textarea">
                    <textarea name="message" id="message" <?php if (!Options::get('spellcheck')) echo ' spellcheck="false" '; ?>placeholder="<?php echo esc_html(Options::getPlaceholder('default_message_placeholder', Define::fields())); ?>" required></textarea>
                    <input type="hidden" name="prompt" id="prompt">
                    <input type="hidden" name="chat_id" id="chat_id" value="0">
                    <span class="material-symbols-outlined" id="submit" <?php echo wp_kses($this->htmx->getHxMultiSwapLoadChat('htmx/chat'), []); ?>>arrow_circle_up</span>
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
                    <p hx-post="<?php echo esc_url($this->htmx->getRenderEndpoint('wp/user/update')); ?>" hx-vals='{"set_default_model": true}' id="set_default_model">Set as default</p>
                    <select name="model" id="model" onclick="setDefaultModel()"></select>
                    <input type="hidden" hx-get="<?php echo esc_url($this->htmx->getRenderEndpoint('htmx/tags')); ?>" hx-trigger="load" hx-target="#model">
                <?php } else { ?>
                    <p><strong>Model</strong></p><code><?php echo esc_html(Options::get('default_model')); ?></code>
                <?php } ?>
            </div>
            <div class="ab-chat-logs">
                <?php if (Options::get('chat_history_save')) { ?>
                    <p><strong>Chat History</strong></p>
                    <select name="chat_history_id" id="chat_history_id" <?php echo wp_kses($this->htmx->getHxMultiSwapLoadChat('wp/chat', 'change'), Options::getAllowedTags()); ?>></select>
                    <input type="hidden" hx-get="<?php echo esc_url($this->htmx->getRenderEndpoint('wp/history')); ?>" hx-trigger="load" hx-target="#chat_history_id">
                <?php } ?>
            </div>
        </div>
    <?php
    }

    public function outputChatWelcomeMessage()
    { ?>
        <div id="ab-hello">
            <img src="<?php echo esc_url($this->htmx->getAssistantAvatarUrl('system')); ?>" alt="gravatar">
            <p><?php echo esc_html(Options::getPlaceholder('default_system_message', Define::fields())); ?></p>
        </div>
    <?php }

    public function outputResponseContainer()
    { ?>
        <div id="ab-response">
            <!-- htmx response -->
        </div>
        <img id="indicator" class="htmx-indicator" src="<?php echo esc_html(AB_DIR_URL); ?>assets/img/grid.svg">
    <?php }

    public function outputScriptZeroMd()
    { ?>
        <script>
            window.ZeroMdConfig = {
                markedUrl: '<?php echo esc_url(AB_DIR_URL . 'assets/js/marked.min.js'); ?>',
                prismUrl: '<?php echo esc_url(AB_DIR_URL . 'assets/js/prism.min.js'); ?>',
                cssUrls: ['<?php echo esc_url(AB_DIR_URL . 'assets/css/github-markdown.css'); ?>', '<?php echo esc_url(AB_DIR_URL . 'assets/css/prism.css'); ?>'],
            }
        </script>
        <script type="module" src="<?php echo esc_url(AB_DIR_URL . 'assets/js/zero-md.min.js'); ?>"></script>
<?php
    }

    public static function render()
    {
        $screen = new self();
        $screen->addFooterActions();
        $screen->addFooterFilters();

        $screen->outputChatForm();
    }
}
