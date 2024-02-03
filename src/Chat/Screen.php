<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress\Chat;

use CarmeloSantana\OllamaPress\Options;
use CarmeloSantana\OllamaPress\Api\Render;

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
            // add url to ollama.press
            $footer = '<a href="https://ollama.press" target="_blank">Ollama Press</a>'  . ' v' . OP_VERSION;
            return $footer;
        }, 11);

        // Load HTMX renderer
        $htmx = new Render(get_current_user_id()); ?>
        <form id="op-chat-form">
            <div id="op-chat-container" class="wrap nosubsub">
                <h1 class="wp-heading-inline"><?php esc_html_e('Ollama Press'); ?></h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ollama-press')); ?>" class="page-title-action"><?php echo esc_html__('New Chat'); ?></a>
                <hr class="wp-header-end">
                <div class="op-chat">
                    <div class="op-toolbar">
                        <div class="op-tags">
                            <?php if (Options::getDefault('user_can_change_model') == true) { ?>
                                <select name="model" id="model"></select>
                                <br>
                                <p <?php echo $htmx->outputWpNonce('wp/user/update'); ?> hx-post="<?php $htmx->outputRenderEndpoint('wp/user/update'); ?>" hx-vals='{"set_default_model": true}' id="set_default_model">Set as default</p>
                                <input <?php echo $htmx->outputWpNonce('htmx/tags'); ?> type="hidden" hx-get="<?php $htmx->outputRenderEndpoint('htmx/tags'); ?>" hx-trigger="load" hx-target="#model">
                            <?php } ?>
                        </div>
                        <?php if (Options::getDefault('save_chat_history')) { ?>
                            <select name="chat_log_id" id="chat_log_id" <?php $htmx->outputHxMultiSwapLoadChat('wp/chat', 'change'); ?>></select>
                            <input <?php echo $htmx->outputWpNonce('wp/history'); ?> type="hidden" hx-get="<?php $htmx->outputRenderEndpoint('wp/history'); ?>" hx-trigger="load" hx-target="#chat_log_id">
                        <?php } ?>
                    </div>
                    <div id="op-hello">
                        <?php echo $htmx->getAssistantAvatarImg('system'); ?>
                        <p><?php echo Options::getPlaceholder('default_system_message'); ?></p>
                    </div>
                    <div id="op-response">
                    </div>
                    <img id="indicator" class="htmx-indicator" src="<?php echo OP_DIR_URL . 'assets/img/grid.svg'; ?>">
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
                markedUrl: '<?php echo OP_DIR_URL . 'assets/js/marked.min.js'; ?>',
                prismUrl: '<?php echo OP_DIR_URL . 'assets/js/prism.min.js'; ?>',
                cssUrls: ['<?php echo OP_DIR_URL . 'assets/css/github-markdown.css'; ?>', '<?php echo OP_DIR_URL . 'assets/css/prism.css'; ?>'],
            }
        </script>
        <script type="module" src="<?php echo OP_DIR_URL . 'assets/js/zero-md.min.js'; ?>"></script>
<?php
    }
}
