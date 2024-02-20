<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot;

class Options
{

    public function addActions()
    {
        add_action('admin_init', function () {
            $menu_id = Options::prefixDash('options');
            self::registerSettings(self::getFields(), self::getSections(), $menu_id);
        });
        add_action('admin_menu', [$this, 'addAdminMenu']);
    }

    public function addAdminMenu()
    {
        add_submenu_page(
            AB_SLUG,
            __('Settings', AB_SLUG),
            __('Settings', AB_SLUG),
            'manage_options',
            Options::prefixDash('options'),
            function () {
                $menu_id = Options::prefixDash('options');
                self::renderOptionsPage(self::getFields(), self::getSections(), $menu_id, __('Settings', AB_SLUG));
            },
            10
        );
    }

    public static function registerSettings(array $options = [], array $sections = [], string $id = '')
    {
        if (empty($name)) {
            $name = md5(json_encode($options));
        }

        $default_option = [
            'default' => false,
            'description' => null,
            'description_callback' => false,
            'field_callback' => null,
            'label' => '',
            'placeholder' => null,
            'type' => 'text',
        ];

        foreach ($sections as $key => $section) {
            $_id_key = $id . '-' . $key;

            add_settings_section(
                $_id_key,
                $section['title'],
                function () use ($section) {
                    echo '<p>' . $section['description'] . '</p>';
                },
                $_id_key
            );

            foreach ($options as $key2 => $option) {
                $option = wp_parse_args($option, $default_option);

                if ($option['section'] == $key) {
                    add_settings_field(
                        self::appendPrefix($key2),
                        $option['label'],
                        function () use ($key2, $option) {
                            $value = get_option(self::appendPrefix($key2), $option['default']);
                            switch ($option['type']) {
                                case 'callback':
                                    call_user_func($option['field_callback']);
                                    break;

                                case 'checkbox':
                                    echo '<input type="checkbox" name="' . self::appendPrefix($key2) . '" value="true" ' . ($value ? 'checked' : '') . '>';
                                    break;

                                case 'radio':
                                    foreach ($option['options'] as $key3 => $option2) {
                                        echo '<label><input type="radio" name="' . self::appendPrefix($key2) . '" value="' . $key3 . '" ' . ($value == $key3 ? 'checked' : '') . '> ' . $option2 . '</label><br>';
                                    }
                                    break;

                                case 'select':
                                    echo '<select name="' . self::appendPrefix($key2) . '">';
                                    foreach ($option['options'] as $key3 => $option2) {
                                        echo '<option value="' . $key3 . '" ' . ($value == $key3 ? 'selected' : '') . '>' . $option2 . '</option>';
                                    }
                                    echo '</select>';
                                    break;

                                case 'text':
                                    echo '<input type="text" name="' . self::appendPrefix($key2) . '" value="' . $value . '" placeholder="' . ($option['placeholder'] ?? null) . '" class="regular-text">';
                                    break;

                                case 'textarea':
                                    echo '<textarea name="' . self::appendPrefix($key2) . '" placeholder="' . ($option['placeholder'] ?? null) . '" class="regular-text">' . $value . '</textarea>';
                                    break;
                            }

                            if ($option['description']) {
                                echo '<p class="description">' . $option['description'] . '</p>';
                            }

                            if ($option['description_callback']) {
                                call_user_func($option['description_callback']);
                            }
                        },
                        $_id_key,
                        $_id_key
                    );
                    register_setting($id, self::appendPrefix($key2));
                }
            }
        }
    }

    public static function renderOptionsPage(array $options = [], array $sections = [], string $id = '', string $title = AB_TITLE)
    {
        if (empty($name)) {
            $name = md5(json_encode($options));
        }

        // get active tab, or first tab
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : array_key_first($sections); ?>
        <div class="wrap <?php echo AB_SLUG; ?> <?php echo Options::prefixDash('options'); ?> <?php echo $active_tab; ?>" id="<?php echo $id; ?>">
            <h1><?php echo $title; ?></h1>
            <h2 class="nav-tab-wrapper">
                <?php foreach ($sections as $key => $section) : ?>
                    <a href="?page=<?php echo $id; ?>&tab=<?php echo $key; ?>" class="nav-tab <?php echo $active_tab == $key ? 'nav-tab-active' : ''; ?>"><?php echo $section['title']; ?></a>
                <?php endforeach; ?>
            </h2>
            <form method="post" action="options.php">
                <?php
                settings_fields($id);
                do_settings_sections($id . '-' . $active_tab);
                // display none other settings
                foreach ($options as $key => $option) {
                    $default_option = [
                        'default' => false,
                    ];
                    $option = wp_parse_args($option, $default_option);
                    if ($option['section'] != $active_tab) {
                        switch ($option['type'] ?? null) {
                            case 'callback':
                            case 'codemirror':
                            case 'textarea':
                                echo '<textarea name="' . self::appendPrefix($key) . '" class="hidden">' . get_option(self::appendPrefix($key), $option['default']) . '</textarea>';
                                break;
                            default:
                                echo '<input type="hidden" name="' . self::appendPrefix($key) . '" value="' . get_option(self::appendPrefix($key), $option['default']) . '">';
                                break;
                        }
                    }
                }
                submit_button();
                ?>
            </form>
        </div>
<?php
    }

    public static function appendPrefix(string $key = '', string $separator = '_')
    {
        return str_replace('-', $separator, AB_SLUG . (!empty($key) ? $separator . $key : ''));
    }

    public static function prefixDash(string $key = '')
    {
        return self::appendPrefix($key, '-');
    }

    public static function prefixUnderscore(string $key = '')
    {
        return self::appendPrefix($key, '_');
    }

    public static function getFields()
    {
        return [
            'api_url' => [
                'label' => __('Ollama API URL', AB_SLUG),
                'description' => __('The URL of your <a href="https://github.com/ollama/ollama">Ollama</a> installation.', AB_SLUG),
                'placeholder' => 'http://localhost:11434',
                'section' => 'api',
                'description_callback' => [__CLASS__, 'fieldApiUrlValidate'],
            ],
            'api_token' => [
                'label' => __('API Token', AB_SLUG),
                'description' => __('This is optional.', AB_SLUG),
                'section' => 'api',
            ],
            'default_model' => [
                'label' => __('Default Model', AB_SLUG),
                'type' => 'select',
                'options' => self::getModels(),
                'section' => 'chat',
            ],
            'user_can_change_model' => [
                'label' => __('Can users change model?', AB_SLUG),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', AB_SLUG),
                    'false' => __('No', AB_SLUG),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'save_chat_history' => [
                'label' => __('Save chat history?', AB_SLUG),
                'type' => 'radio',
                'options' => [
                    'true' => __('Yes', AB_SLUG),
                    'false' => __('No', AB_SLUG),
                ],
                'section' => 'chat',
                'default' => true,
            ],
            'default_system_message' => [
                'label' => __('Default system message', AB_SLUG),
                'placeholder' => __('How can I help you today?', AB_SLUG),
                'section' => 'chat',
            ],
            'default_message_placeholder' => [
                'label' => __('Default message placeholder', AB_SLUG),
                'placeholder' => __('Start chatting with Abie', AB_SLUG),
                'section' => 'chat',
            ],
        ];
    }

    public static function getModels()
    {
        if (self::get('api_url')) {
            // get transient
            $models = get_transient('ollama_models');

            if ($models) {
                return $models;
            }

            $url = self::get('api_url') . '/api/tags';
            $response = wp_remote_get($url);

            $body = wp_remote_retrieve_body($response);
            $body = json_decode($body, true);

            // if ['models'] exists loop and set each key value to model[name]
            if (isset($body['models'])) {
                $models = [];
                foreach ($body['models'] as $model) {
                    $models[$model['name']] = $model['name'];
                }
                set_transient('ollama_models', $models, 60 * 60 * 5);
            }
        } else {
            $models = [];
        }

        return $models;
    }

    public static function getSections()
    {
        return [
            'api' => [
                'title' => __('API', AB_SLUG),
                'description' => __('Configure your <a href="https://github.com/ollama/ollama">Ollama</a> settings.', AB_SLUG),
            ],
            'chat' => [
                'title' => __('Chat', AB_SLUG),
                'description' => __('Customize the user experience.', AB_SLUG),
            ],
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
                echo '<p class="description">' . __('Invalid URL', AB_SLUG) . '</p>';
            } elseif (preg_match('/^"?(Ollama is running)"?$/', $body)) {
                if ($header_x_ollama) {
                    echo '<p class="description"><span class="material-symbols-outlined label-success">verified</span><span>' . __('Alpaca Bot Proxy connection established.', AB_SLUG) . '</span></p>';
                } else {
                    echo '<p class="description"><span class="material-symbols-outlined label-success">check_circle</span><span>' . __('Verified connection.', AB_SLUG) . '</span></p>';
                }
            } else {
                echo '<p class="description"><span class="material-symbols-outlined label-error">error</span><span>' . __('Invalid response.', AB_SLUG) . '</span></p>';
            }
        } elseif (empty($api_url)) {
            echo '<p class="description"><span class="material-symbols-outlined">edit</span><span>' . __('Please enter a URL.', AB_SLUG) . '</span></p>';
        }
    }

    public static function get(string $key, $default = null, $placeholder = false)
    {
        $value = get_option(self::appendPrefix($key));

        $value = self::validateValue($value);

        if (!$value and $default !== null) {
            $options = self::getFields();
            $value = $default ? $default : $options[$key]['default'] ?? false;

            if ($placeholder and isset($options[$key]['placeholder'])) {
                $value = $options[$key]['placeholder'];
            }
        }

        return $value;
    }

    public static function getDefault(string $key, $default = false)
    {
        return self::get($key, $default);
    }


    public static function getPlaceholder(string $key, $default = false)
    {
        return self::get($key, $default, true);
    }

    public static function validateValue($value)
    {
        if (is_string($value) and in_array(strtolower($value), ['true', 'false'])) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } elseif (is_string($value) and empty($value)) {
            return false;
        }

        return $value;
    }
}
