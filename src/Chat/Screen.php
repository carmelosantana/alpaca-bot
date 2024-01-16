<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress\Chat;

use CarmeloSantana\OllamaPress\Api\Htmx;

class Screen
{
    private object $htmx;

    public function __construct()
    {
        add_filter('admin_footer_text', [$this, 'outputFooterText']);

        $this->htmx = new Htmx();
        $this->outputHTML();
    }

    public function outputHTML()
    { ?>
        <form id="op-chat-form">
            <div id="op-chat-container" class="wrap nosubsub">
                <h1 class="wp-heading-inline"><?php esc_html_e('Ollama Press'); ?></h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ollama-press')); ?>" class="page-title-action"><?php echo esc_html__('New Chat'); ?></a>
                <hr class="wp-header-end">
                <div class="op-chat">
                    <div class="op-toolbar">
                        <div>
                            <select name="model" id="model"></select>
                            <br>
                            <p hx-post="<?php $this->htmx->outputRenderEndpoint('wp/user/update'); ?>" hx-vals='{"set_default_model": true}' id="set_default_model">Set as default</p>
                        </div>
                        <input type="hidden" hx-get="<?php $this->htmx->outputRenderEndpoint('tags'); ?>" hx-trigger="load" hx-target="#model">
                        <select name="chat_log_id" id="chat_log_id" <?php $this->htmx->outputHxMultiSwapLoadChat('wp/chat', 'change'); ?>></select>
                        <input type="hidden" hx-get="<?php $this->htmx->outputRenderEndpoint('wp/chats'); ?>" hx-trigger="load" hx-target="#chat_log_id">
                    </div>
                    <div id="op-hello">
                        <?php echo $this->htmx->getAssistantAvatarImg('system'); ?>
                        <p>How can I help you today?</p>
                    </div>
                    <div id="op-response">
                    </div>
                    <img id="indicator" class="htmx-indicator" src="<?php echo OP_DIR_URL . 'assets/img/grid.svg'; ?>">
                </div>
            </div>
            <div class="typing-container">
                <div class="typing-content">
                    <div class="typing-textarea">
                        <textarea name="message" id="message" spellcheck="false" placeholder="Start chatting with Ollama" required></textarea>
                        <input type="hidden" name="prompt" id="prompt">
                        <input type="hidden" name="chat_id" id="chat_id" value="0">
                        <span class="material-symbols-outlined" id="submit" <?php $this->htmx->outputHxMultiSwapLoadChat(); ?>>arrow_circle_up</span>
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

    public function outputFooterText()
    {
        echo 'Always verify important information to ensure accuracy.';
    }
}
