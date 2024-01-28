<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress;

class Options
{

    public function addActions()
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addAdminMenu()
    {
        add_submenu_page(
            OP_SLUG,
            __('Settings', OP_SLUG),
            __('Settings', OP_SLUG),
            'manage_options',
            OP_SLUG . '-options',
            [$this, 'renderOptionsPage']
        );
    }

    public function registerSettings()
    {
        $options = self::getFields();
        $sections = self::getSections();
        $default_option = [
            'title' => '',
            'description' => null,
            'description_callback' => false,
            'type' => 'text',
            'placeholder' => null,
            'default' => false,
            'env' => '',
        ];
        foreach ($sections as $key => $section) {
            add_settings_section(
                OP_SLUG . '-options-' . $key,
                $section,
                function () use ($key) {
                    switch ($key) {
                        case 'api':
                            echo '<p>' . __('Configure your <a href="https://github.com/ollama/ollama">Ollama</a> settings.', OP_SLUG) . '</p>';
                            break;
                        case 'chat':
                            echo '<p>' . __('Configure your chat settings.', OP_SLUG) . '</p>';
                            break;
                    }
                },
                OP_SLUG . '-options-' . $key
            );
            foreach ($options as $key2 => $option) {
                $option = wp_parse_args($option, $default_option);

                if ($option['section'] == $key) {
                    add_settings_field(
                        self::appendPrefix($key2),
                        $option['title'],
                        function () use ($key2, $option) {
                            $value = get_option(self::appendPrefix($key2), $option['default']);
                            switch ($option['type']) {
                                case 'text':
                                    echo '<input type="text" name="' . self::appendPrefix($key2) . '" value="' . $value . '" placeholder="' . ($option['placeholder'] ?? null) . '" class="regular-text">';
                                    break;
                                case 'select':
                                    echo '<select name="' . self::appendPrefix($key2) . '">';
                                    foreach ($option['options'] as $key3 => $option2) {
                                        echo '<option value="' . $key3 . '" ' . ($value == $key3 ? 'selected' : '') . '>' . $option2 . '</option>';
                                    }
                                    echo '</select>';
                                    break;
                                case 'radio':
                                    foreach ($option['options'] as $key3 => $option2) {
                                        echo '<label><input type="radio" name="' . self::appendPrefix($key2) . '" value="' . $key3 . '" ' . ($value == $key3 ? 'checked' : '') . '> ' . $option2 . '</label><br>';
                                    }
                                    break;
                            }
                            if ($option['description']) {
                                echo '<p class="description">' . $option['description'] . '</p>';
                            }

                            if ($option['description_callback']) {
                                call_user_func($option['description_callback']);
                            }
                        },
                        OP_SLUG . '-options-' . $key,
                        OP_SLUG . '-options-' . $key
                    );
                    register_setting(OP_SLUG . '-options', self::appendPrefix($key2));
                }
            }
        }
    }

    public function renderOptionsPage()
    {
        $options = self::getFields();
        $sections = self::getSections();
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'api';
?>
        <div class="wrap ollama-press" id="ollama-press-options">
            <h1><?php echo __('Ollama', OP_SLUG); ?></h1>
            <h2 class="nav-tab-wrapper">
                <?php foreach ($sections as $key => $section) : ?>
                    <a href="?page=<?php echo OP_SLUG; ?>-options&tab=<?php echo $key; ?>" class="nav-tab <?php echo $active_tab == $key ? 'nav-tab-active' : ''; ?>"><?php echo $section; ?></a>
                <?php endforeach; ?>
            </h2>
            <form method="post" action="options.php">
                <?php
                settings_fields(OP_SLUG . '-options');
                do_settings_sections(OP_SLUG . '-options-' . $active_tab);
                // display none other settings
                foreach ($options as $key => $option) {
                    $default_option = [
                        'default' => false,
                    ];
                    $option = wp_parse_args($option, $default_option);
                    if ($option['section'] != $active_tab) {
                        echo '<input type="hidden" name="' . self::appendPrefix($key) . '" value="' . get_option(self::appendPrefix($key), $option['default']) . '">';
                    }
                }
                submit_button();
                ?>
            </form>
        </div>
<?php

    }

    public static function appendPrefix(string $key)
    {
        return 'ollama_' . $key;
    }

    public static function getFields()
    {
        $models = [];

        if (self::get('api_url')) {
            $url = self::get('api_url') . '/api/tags';
            $response = wp_remote_get($url);

            $body = wp_remote_retrieve_body($response);
            $body = json_decode($body, true);

            // if ['models'] exists loop and set each key value to model[name]
            if (isset($body['models'])) {
                foreach ($body['models'] as $model) {
                    $models[$model['name']] = $model['name'];
                }
            }
        }

        return [
            'api_url' => [
                'title' => __('Ollama API URL', OP_SLUG),
                'description' => __('The URL of your <a href="https://github.com/ollama/ollama">Ollama</a> installation.', OP_SLUG),
                'placeholder' => 'http://localhost:11434',
                'section' => 'api',
                'description_callback' => [__CLASS__, 'fieldApiUrlValidate'],
            ],
            'api_token' => [
                'title' => __('API Token', OP_SLUG),
                'description' => __('This is optional.', OP_SLUG),
                'section' => 'api',
            ],
            'default_model' => [
                'title' => __('Default Model', OP_SLUG),
                'type' => 'select',
                'options' => $models,
                'section' => 'chat',
            ],
            'user_can_change_model' => [
                'title' => __('Can users change model?', OP_SLUG),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', OP_SLUG),
                    'false' => __('No', OP_SLUG),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'save_chat_history' => [
                'title' => __('Save chat history?', OP_SLUG),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', OP_SLUG),
                    'false' => __('No', OP_SLUG),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'default_system_message' => [
                'title' => __('Default system message', OP_SLUG),
                'placeholder' => __('How can I help you today?', OP_SLUG),
                'section' => 'chat',
            ],
            'default_message_placeholder' => [
                'title' => __('Default message placeholder', OP_SLUG),
                'placeholder' => __('Start chatting with Ollama', OP_SLUG),
                'section' => 'chat',
            ],
        ];
    }

    public static function getSections()
    {
        return [
            'api' => __('API', OP_SLUG),
            'chat' => __('Chat', OP_SLUG),
        ];
    }

    public static function fieldApiUrlValidate()
    {
        $api_url = self::get('api_url');

        if ($api_url and !empty($api_url)) {
            $response = wp_remote_get($api_url);
            $body = wp_remote_retrieve_body($response);
            $header_x_ollama = wp_remote_retrieve_header($response, 'x-ollama-proxy');

            if (is_wp_error($response)) {
                echo '<p class="description">' . __('Invalid URL', OP_SLUG) . '</p>';
                // $body == '"Ollama is running"' or $body == 'Ollama is running' in regex
            } elseif (preg_match('/^"?(Ollama is running)"?$/', $body)) {
                if ($header_x_ollama) {
                    echo '<p class="description"><span class="material-symbols-outlined label-success">verified</span><span>' . __('Ollama Press Proxy connection established.', OP_SLUG) . '</span></p>';
                } else {
                    echo '<p class="description"><span class="material-symbols-outlined label-success">check_circle</span><span>' . __('Verified connection.', OP_SLUG) . '</span></p>';
                }
            } else {
                echo '<p class="description"><span class="material-symbols-outlined label-error">error</span><span>' . __('Invalid response.', OP_SLUG) . '</span></p>';
            }
        } elseif (empty($api_url)) {
            echo '<p class="description"><span class="material-symbols-outlined">edit</span><span>' . __('Please enter a URL.', OP_SLUG) . '</span></p>';
        }
    }

    public static function get(string $key, $default = false)
    {
        $default = $options[$key]['default'] ?? $default;
        $value = get_option(self::appendPrefix($key), $default);
        if (is_string($value) and in_array(strtolower($value), ['true', 'false'])) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } elseif (is_string($value) and empty($value)) {
            $value = false;
        }
        return apply_filters(self::appendPrefix($key), $value);
    }
}
