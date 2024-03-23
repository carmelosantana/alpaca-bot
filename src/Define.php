<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

use CarmeloSantana\AlpacaBot\Api\Ollama;
use CarmeloSantana\AlpacaBot\Utils\Options;

class Define
{
    public static function getModels()
    {
        // get models from Ollama
        $ollama = new Ollama();
        $cache = get_transient(Options::appendPrefix('ollama-models'));

        $models = [];

        if ($cache) {
            foreach ($cache as $model) {
                $models[$model['name']] = $ollama->getModelNameSize($model);
            }
        }

        return $models;
    }

    public static function fieldApiUrlValidate()
    {
        $api_url = Options::get('api_url');

        if ($api_url and !empty($api_url)) {
            $response = wp_remote_get($api_url);
            $body = wp_remote_retrieve_body($response);
            $header_x_ollama = wp_remote_retrieve_header($response, 'x-ollama-proxy');

            if (is_wp_error($response)) {
                echo '<p class="description"><span class="material-symbols-outlined">edit</span><span>' . esc_html__('Please enter a valid URL.', 'alpaca-bot') . '</span></p>';
            } elseif (preg_match('/^"?(Ollama is running)"?$/', $body)) {
                if ($header_x_ollama) {
                    echo '<p class="description"><span class="material-symbols-outlined label-success">verified</span><span>' . esc_html__('Alpaca Bot Proxy connection established.', 'alpaca-bot') . '</span></p>';
                } else {
                    echo '<p class="description"><span class="material-symbols-outlined label-success">check_circle</span><span>' . esc_html__('Verified connection.', 'alpaca-bot') . '</span></p>';
                }
            } else {
                echo '<p class="description"><span class="material-symbols-outlined label-error">error</span><span>' . esc_html__('Invalid response.', 'alpaca-bot') . '</span></p>';
            }
        } elseif (empty($api_url)) {
            $patreon = '<a href="' . esc_url(Define::support()['patreon']['url']) . '">' . Define::support()['patreon']['title'] . '</a>';
            echo '<p class="description"><span>No server? We got you covered! ' . wp_kses($patreon, Options::getAllowedTags()) . ' and share our community hosted instances.<span></p>';
        }
    }

    public static function fieldAvatarPreview()
    {
        $avatar = Options::getPlaceholder('default_avatar', self::fields());
        if ($avatar) {
            echo '<p><img src="' . esc_url($avatar) . '" style="width: 32px; height: 32px; border-radius: 50%;"></p>';
        }
    }

    public static function fields()
    {
        return [
            'api_url' => [
                'label' => __('Ollama API URL', 'alpaca-bot'),
                'description' => __('The URL of your <a href="https://github.com/ollama/ollama">Ollama</a> installation, without trailing slash.', 'alpaca-bot'),
                'placeholder' => 'http://localhost:11434',
                'section' => 'api',
                'description_callback' => [__CLASS__, 'fieldApiUrlValidate'],
            ],
            'api_username' => [
                'label' => __('API Username', 'alpaca-bot'),
                'description' => __('This is optional.', 'alpaca-bot'),
                'section' => 'api',
            ],
            'api_password' => [
                'label' => __('API Application Password', 'alpaca-bot'),
                'description' => __('This is optional.', 'alpaca-bot'),
                'section' => 'api',
                'type' => 'password',
            ],
            'ollama_timeout' => [
                'label' => __('Timeout', 'alpaca-bot'),
                'description' => __('The time in seconds to wait for a response from <a href="https://github.com/ollama/ollama">Ollama</a>.', 'alpaca-bot'),
                'section' => 'api',
                'type' => 'number',
                'placeholder' => 60,
            ],
            'default_model' => [
                'label' => __('Default Model', 'alpaca-bot'),
                'type' => 'select',
                'options' => self::getModels(),
                'section' => 'chat',
            ],
            'user_can_change_model' => [
                'label' => __('Can users change model?', 'alpaca-bot'),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', 'alpaca-bot'),
                    'false' => __('No', 'alpaca-bot'),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'chat_history_save' => [
                'label' => __('Save chat history?', 'alpaca-bot'),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', 'alpaca-bot'),
                    'false' => __('No', 'alpaca-bot'),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'chat_history_limit' => [
                'label' => __('Limit chat history', 'alpaca-bot'),
                'description' => __('The number of chat messages to send to the model. Set to 0 to send all messages. Sending more than a few messages may result in losing context and increased token usage.', 'alpaca-bot'),
                'section' => 'chat',
                'type' => 'number',
                'placeholder' => 5,
            ],
            'chat_response_log' => [
                'label' => __('Log chat response?', 'alpaca-bot'),
                'description' => __('Log additional information provided by the completion. This does not include conversation history.', 'alpaca-bot'),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', 'alpaca-bot'),
                    'false' => __('No', 'alpaca-bot'),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'default_system_message' => [
                'label' => __('Default system message', 'alpaca-bot'),
                'placeholder' => __('How can I help you today?', 'alpaca-bot'),
                'section' => 'chat',
            ],
            'default_message_placeholder' => [
                'label' => __('Default message placeholder', 'alpaca-bot'),
                'placeholder' => __('Start chatting with Abie', 'alpaca-bot'),
                'section' => 'chat',
            ],
            'user_agent' => [
                'label' => __('User Agent', 'alpaca-bot'),
                'description' => __('Browser user agent to use when making requests.', 'alpaca-bot'),
                'section' => 'agents',
                'placeholder' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            ],
            'spellcheck' => [
                'label' => __('Spellcheck', 'alpaca-bot'),
                'description' => __('Enable spellcheck on the chat input.', 'alpaca-bot'),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', 'alpaca-bot'),
                    'false' => __('No', 'alpaca-bot'),
                ],
                'section' => 'privacy',
                'default' => false,
            ],
            'default_avatar' => [
                'label' => __('Default Avatar', 'alpaca-bot'),
                'description' => __('The URL of the default avatar to use for the chat.', 'alpaca-bot'),
                'section' => 'assistant',
                'type' => 'media',
                'placeholder' => esc_url(AB_DIR_URL . 'assets/img/ollama-large.png'),
                'description_callback' => [__CLASS__, 'fieldAvatarPreview'],
            ],
            'default_system' => [
                'label' => __('Default System Message', 'alpaca-bot'),
                'description' => __('The <code>SYSTEM</code> instruction specifies the system message to be used in the template, if applicable.', 'alpaca-bot'),
                'section' => 'assistant',
                'type' => 'textarea',
                'placeholder' => '"""<system message>"""',
            ],
            'default_template' => [
                'label' => __('Default Template', 'alpaca-bot'),
                'description' => __('The <code>TEMPLATE</code> to be passed into the model. It may include (optionally) a system message, a user\'s message and the response from the model. Note: syntax may be model specific. Templates use Go <a href="https://pkg.go.dev/text/template">template syntax</a>.', 'alpaca-bot'),
                'section' => 'assistant',
                'type' => 'textarea',
                'placeholder' => '"""{{ if .System }}<|im_start|>system
{{ .System }}<|im_end|>
{{ end }}{{ if .Prompt }}<|im_start|>user
{{ .Prompt }}<|im_end|>
{{ end }}<|im_start|>assistant
"""',
            ],
            'default_mirostat' => [
                'label' => __('Mirostat', 'alpaca-bot'),
                'description' => __('Enable Mirostat sampling for controlling perplexity. (default: 0, 0 = disabled, 1 = Mirostat, 2 = Mirostat 2.0)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'placeholder' => 0,
            ],
            'default_mirostat_eta' => [
                'label' => __('Mirostat Eta', 'alpaca-bot'),
                'description' => __('Influences how quickly the algorithm responds to feedback from the generated text. A lower learning rate will result in slower adjustments, while a higher learning rate will make the algorithm more responsive. (Default: 0.1)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'step' => 0.1,
                'placeholder' => 0.1,
            ],
            'default_mirostat_tau' => [
                'label' => __('Mirostat Tau', 'alpaca-bot'),
                'description' => __('Controls the balance between coherence and diversity of the output. A lower value will result in more focused and coherent text. (Default: 5.0)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'step' => 0.1,
                'placeholder' => 5.0,
            ],
            'default_num_ctx' => [
                'label' => __('Num Ctx', 'alpaca-bot'),
                'description' => __('Sets the size of the context window used to generate the next token. (Default: 2048)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'placeholder' => 2048,
            ],
            'default_num_gqa' => [
                'label' => __('Num GQA', 'alpaca-bot'),
                'description' => __('The number of GQA groups in the transformer layer. Required for some models, for example it is 8 for llama2:70b', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'placeholder' => 1,
            ],
            'default_num_gpu' => [
                'label' => __('Num GPU', 'alpaca-bot'),
                'description' => __('The number of layers to send to the GPU(s). On macOS it defaults to 1 to enable metal support, 0 to disable.', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'placeholder' => 50,
            ],
            'default_num_thread' => [
                'label' => __('Num Thread', 'alpaca-bot'),
                'description' => __('Sets the number of threads to use during computation. By default, Ollama will detect this for optimal performance. It is recommended to set this value to the number of physical CPU cores your system has (as opposed to the logical number of cores).', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'placeholder' => 8,
            ],
            'default_repeat_last_n' => [
                'label' => __('Repeat Last N', 'alpaca-bot'),
                'description' => __('Sets how far back for the model to look back to prevent repetition. (Default: 64, 0 = disabled, -1 = num_ctx)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'placeholder' => 64,
            ],
            'default_repeat_penalty' => [
                'label' => __('Repeat Penalty', 'alpaca-bot'),
                'description' => __('Sets how strongly to penalize repetitions. A higher value (e.g., 1.5) will penalize repetitions more strongly, while a lower value (e.g., 0.9) will be more lenient. (Default: 1.1)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'step' => 0.1,
                'placeholder' => 1.1,
            ],
            'default_temperature' => [
                'label' => __('Temperature', 'alpaca-bot'),
                'description' => __('The temperature of the model. Increasing the temperature will make the model answer more creatively. (Default: 0.8)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'step' => 0.1,
                'placeholder' => 0.8,
            ],
            'default_seed' => [
                'label' => __('Seed', 'alpaca-bot'),
                'description' => __('Sets the random number seed to use for generation. Setting this to a specific number will make the model generate the same text for the same prompt. (Default: 0)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'placeholder' => 0,
            ],
            'default_stop' => [
                'label' => __('Stop', 'alpaca-bot'),
                'description' => __('Sets the stop sequences to use. When this pattern is encountered the LLM will stop generating text and return. Multiple stop patterns may be set by specifying multiple separate stop parameters in a modelfile.', 'alpaca-bot'),
                'section' => 'parameters',
                'placeholder' => 'AI assistant:',
            ],
            'default_tfs_z' => [
                'label' => __('TFS Z', 'alpaca-bot'),
                'description' => __('Tail free sampling is used to reduce the impact of less probable tokens from the output. A higher value (e.g., 2.0) will reduce the impact more, while a value of 1.0 disables this setting. (default: 1)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'placeholder' => 1,
            ],
            'default_num_predict' => [
                'label' => __('Num Predict', 'alpaca-bot'),
                'description' => __('Maximum number of tokens to predict when generating text. (Default: 128, -1 = infinite generation, -2 = fill context)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'placeholder' => 128,
            ],
            'default_top_k' => [
                'label' => __('Top K', 'alpaca-bot'),
                'description' => __('Reduces the probability of generating nonsense. A higher value (e.g. 100) will give more diverse answers, while a lower value (e.g. 10) will be more conservative. (Default: 40)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'placeholder' => 40,
            ],
            'default_top_p' => [
                'label' => __('Top P', 'alpaca-bot'),
                'description' => __('Works together with top-k. A higher value (e.g., 0.95) will lead to more diverse text, while a lower value (e.g., 0.5) will generate more focused and conservative text. (Default: 0.9)', 'alpaca-bot'),
                'section' => 'parameters',
                'type' => 'number',
                'step' => 0.1,
                'placeholder' => 0.9,
            ],

        ];
    }

    public static function getFieldsInSection(string $section)
    {
        $fields = self::fields();
        $fieldsInSection = [];

        foreach ($fields as $key => $field) {
            if ($field['section'] === $section) {
                $fieldsInSection[$key] = $field;
            }
        }

        return $fieldsInSection;
    }

    public static function sections()
    {
        return [
            'api' => [
                'title' => __('API', 'alpaca-bot'),
                'description' => __('Configure your <a href="https://github.com/ollama/ollama">Ollama</a> settings. ', 'alpaca-bot'),
            ],
            'chat' => [
                'title' => __('Chat', 'alpaca-bot'),
                'description' => __('Customize the user experience.', 'alpaca-bot'),
            ],
            'assistant' => [
                'title' => __('Assistant', 'alpaca-bot'),
                'description' => __('Override the modelfile and create a custom assistant. Applies to Single-turn Chat generations and shortcodes., ', 'alpaca-bot'),
            ],
            'parameters' => [
                'title' => __('Parameters', 'alpaca-bot'),
                'description' => __('Sets the <a href="https://github.com/ollama/ollama/blob/main/docs/modelfile.md#parameter">parameters</a> for how <a href="https://github.com/ollama/ollama">Ollama</a> will run the model.', 'alpaca-bot'),
            ],
            'agents' => [
                'title' => __('Agents', 'alpaca-bot'),
                'description' => __('Manage your agents.', 'alpaca-bot'),
            ],
            'privacy' => [
                'title' => __('Privacy', 'alpaca-bot'),
                'description' => __('Privacy settings.', 'alpaca-bot'),
            ],
        ];
    }

    public static function support()
    {
        $support = [
            'discord' => [
                'description' => __('Join our <a href="https://discord.gg/vWQTHphkVt">Discord</a> community.', 'alpaca-bot'),
                'title' => __('Join our Discord', 'alpaca-bot'),
                'url' => 'https://discord.gg/vWQTHphkVt',
            ],
            'patreon' => [
                'description' => __('Support the development of this plugin by becoming a <a href="https://www.patreon.com/carmelosantana">Patreon</a>.', 'alpaca-bot'),
                'title' => __('Become a Patreon', 'alpaca-bot'),
                'url' => 'https://www.patreon.com/carmelosantana',
            ]
        ];

        return $support;
    }
}
