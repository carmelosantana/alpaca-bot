<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Chat;

use CarmeloSantana\AlpacaBot\Options;
use CarmeloSantana\AlpacaBot\Api\Render;

class Screen
{
    static public function outputHTML()
    {
        // Apply to footer only on this page
        add_filter('admin_footer_text', function () {
            echo 'Always verify important information to ensure accuracy.';
        });

        // Change the footer version to the plugin version
        add_filter('update_footer', function ($footer) {
            // add url to alpaca.bot
            $footer = '<a href="https://alpaca.bot" target="_blank">Alpaca Bot</a>'  . ' v' . \CarmeloSantana\AlpacaBot\VERSION;
            return $footer;
        }, 11);

        // Load HTMX renderer
        $htmx = new Render(get_current_user_id()); ?>
        <form id="ab-chat-form">
            <div id="ab-chat-container" class="wrap nosubsub">
                <h1 class="wp-heading-inline"><?php esc_html_e('Alpaca Bot'); ?></h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=alpaca-bot')); ?>" class="page-title-action"><?php echo esc_html__('New Chat'); ?></a>
                <hr class="wp-header-end">
                <div class="ab-chat">
                    <div class="ab-toolbar">
                        <div class="ab-tags">
                            <?php if (Options::get('user_can_change_model')) { ?>
                                <p><strong>Model</strong></p>
                                <p <?php echo $htmx->outputWpNonce('wp/user/update'); ?> hx-post="<?php $htmx->outputRenderEndpoint('wp/user/update'); ?>" hx-vals='{"set_default_model": true}' id="set_default_model">Set as default</p>
                                <!-- onclick setDefaultModel -->
                                <select name="model" id="model" onclick="setDefaultModel()"></select>
                                <input <?php echo $htmx->outputWpNonce('htmx/tags'); ?> type="hidden" hx-get="<?php $htmx->outputRenderEndpoint('htmx/tags'); ?>" hx-trigger="load" hx-target="#model">
                            <?php } else { ?>
                                <p><strong>Model</strong></p><code><?php echo Options::get('default_model'); ?></code>
                            <?php } ?>
                        </div>
                        <div class="ab-chat-logs">
                            <?php if (Options::getDefault('save_chat_history')) { ?>
                                <p><strong>Chat History</strong></p>
                                <select name="chat_log_id" id="chat_log_id" <?php $htmx->outputHxMultiSwapLoadChat('wp/chat', 'change'); ?>></select>
                                <input <?php echo $htmx->outputWpNonce('wp/history'); ?> type="hidden" hx-get="<?php $htmx->outputRenderEndpoint('wp/history'); ?>" hx-trigger="load" hx-target="#chat_log_id">
                            <?php } ?>
                        </div>
                    </div>
                    <div id="ab-hello">
                        <?php echo $htmx->getAssistantAvatarImg('system'); ?>
                        <p><?php echo Options::getPlaceholder('default_system_message'); ?></p>
                    </div>
                    <div id="ab-response">
                    </div>
                    <img id="indicator" class="htmx-indicator" src="<?php echo AB_DIR_URL . 'assets/img/grid.svg'; ?>">
                </div>
            </div>
            <div class="typing-container">
                <div class="typing-content">
                    <div class="typing-textarea">
                        <textarea name="message" id="message" spellcheck="false" placeholder="<?php echo Options::getPlaceholder('default_message_placeholder'); ?>" required></textarea>
                        <input type="hidden" name="prompt" id="prompt">
                        <input type="hidden" name="chat_id" id="chat_id" value="0">
                        <span class="material-symbols-outlined" id="submit" <?php $htmx->outputHxMultiSwapLoadChat('htmx/chat'); ?>>arrow_circle_up</span>
                    </div>
                </div>
            </div>
        </form>
        <script>
            window.ZeroMdConfig = {
                markedUrl: '<?php echo AB_DIR_URL . 'assets/js/marked.min.js'; ?>',
                prismUrl: '<?php echo AB_DIR_URL . 'assets/js/prism.min.js'; ?>',
                cssUrls: ['<?php echo AB_DIR_URL . 'assets/css/github-markdown.css'; ?>', '<?php echo AB_DIR_URL . 'assets/css/prism.css'; ?>'],
            }
        </script>
        <script type="module" src="<?php echo AB_DIR_URL . 'assets/js/zero-md.min.js'; ?>"></script>
<?php
    }
}
