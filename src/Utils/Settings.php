<?php

declare(strict_types=1);

namespace CarmeloSantana\AlpacaBot\Utils;

class Settings
{
    private array $fields = [];

    private array $page_wrap_class = ['wrap', 'options-page'];

    private array $sections = [];

    private int $position = 100;

    private string $capability = 'manage_options';

    private string $icon_url = 'dashicons-admin-generic';

    private string $parent_slug;

    private string $page_title;

    private string $prefix = '_';

    private string $menu_slug;

    private string $menu_title;

    public function getActiveTab(string $default = ' '): string
    {
        if (!isset($this->sections)) {
            return $default;
        }

        $active_tab = $_GET['tab'] ?? $_POST['tab'] ?? array_key_first($this->sections);
        $active_tab = sanitize_key($active_tab);

        return $active_tab;
    }

    public static function getAllowedTags(): array
    {
        $htmx = [
            'aria-label' => [],
            'class' => [],
            'id' => [],
            'onclick' => [],
            'hx-boost' => [],
            'hx-disabled-elt' => [],
            'hx-ext' => [],
            'hx-get' => [],
            'hx-indicator' => [],
            'hx-headers' => [],
            'hx-post' => [],
            'hx-target' => [],
            'hx-trigger' => [],
            'hx-swap' => [],
            'hx-vals' => [],
            'hx-vars' => [],
        ];

        return [
            'a' => [
                'href' => [],
                'title' => [],
            ],
            'abbr' => [
                'title' => [],
            ],
            'b' => [],
            'blockquote' => [
                'cite' => [],
            ],
            'br' => [],
            'cite' => [
                'title' => [],
            ],
            'code' => [
                'class' => [],
            ],
            'del' => [
                'datetime' => [],
                'title' => [],
            ],
            'div' => [
                'class' => [],
                'id' => [],
            ],
            'em' => [],
            'i' => [],
            'img' => [
                'alt' => [],
                'class' => [],
                'height' => [],
                'src' => [],
                'width' => [],
            ],
            'input' => [
                'checked' => [],
                'class' => [],
                'disabled' => [],
                'id' => [],
                'name' => [],
                'readonly' => [],
                'type' => [],
                'value' => [],
            ],
            'p' => [
                'class' => [],
                'id' => [],
            ],
            'pre' => [],
            'q' => [
                'cite' => [],
                'title' => [],
            ],
            's' => [],
            'script' => [
                'type' => [],
            ],
            'small' => [],
            'span' => $htmx,
            'strike' => [],
            'strong' => [],
            'time' => [
                'class' => [],
                'datetime' => [],
            ],
            'zero-md' => [],
        ];
    }

    public function getMenuType(): string
    {
        if (self::validateValue($this->parent_slug)) {
            return 'add_submenu_page';
        } else {
            return 'add_menu_page';
        }
    }

    public function register(): object
    {
        // Add classes
        if (!empty($this->page_wrap_class)) {
            $this->page_wrap_class[] = ($this->prefix != '_' ? $this->prefix : 'custom') . 'options-page';
            $this->page_wrap_class = array_unique($this->page_wrap_class);
        }

        // Add actions
        $this->addActions();

        return $this;
    }

    public function setCapability(string $capability): object
    {
        $this->capability = $capability;

        return $this;
    }

    public function setIconUrl(string $icon_url): object
    {
        $this->icon_url = $icon_url;

        return $this;
    }

    public function setFields(array $fields): object
    {
        $this->fields = $fields;

        return $this;
    }

    public function setMenuSlug(string $menu_slug): object
    {
        $this->menu_slug = $menu_slug;

        return $this;
    }

    public function setMenuTitle(string $menu_title): object
    {
        $this->menu_title = $menu_title;

        return $this;
    }

    public function setPageTitle(string $page_title): object
    {
        $this->page_title = $page_title;

        return $this;
    }

    public function setParentSlug(string $parent_slug): object
    {
        $this->parent_slug = $parent_slug;

        return $this;
    }

    public function setPrefix(string $prefix): object
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function setPosition(int $position): object
    {
        $this->position = $position;

        return $this;
    }

    public function setSections(array $sections): object
    {
        $this->sections = $sections;

        return $this;
    }

    public function addActions(): void
    {
        add_action('admin_init', function () {
            $this->registerSettings($this->fields, $this->sections, $this->menu_slug);
        });
        add_action('admin_menu', [$this, 'addAdminMenu']);
    }

    public function addAdminMenu(): void
    {
        $menu_type = $this->getMenuType();

        switch ($menu_type) {
            case 'add_menu_page':
                add_menu_page(
                    $this->page_title,
                    $this->menu_title,
                    $this->capability,
                    $this->menu_slug,
                    function () {
                        $this->renderOptionsPage($this->fields, $this->sections, $this->menu_slug, $this->page_title);
                    },
                    $this->icon_url,
                    $this->position
                );
                break;

            case 'add_submenu_page':
                add_submenu_page(
                    $this->parent_slug,
                    $this->page_title,
                    $this->menu_title,
                    $this->capability,
                    $this->menu_slug,
                    function () {
                        $this->renderOptionsPage($this->fields, $this->sections, $this->menu_slug, $this->page_title);
                    },
                    $this->position
                );
                break;
        }
    }

    public function addPageWrapClass(string $class): object
    {
        $this->page_wrap_class[] = $class;

        return $this;
    }

    public function clearPageWrapClass(): object
    {
        $this->page_wrap_class = [];

        return $this;
    }

    private function outputPageWrapClass(): string
    {
        return implode(' ', $this->page_wrap_class);
    }

    private function registerSettings(array $options = [], array $sections = [], string $menu_slug = '')
    {
        // Add wp.media
        add_action('admin_enqueue_scripts', function () {
            wp_enqueue_media();
        });

        $default_option = [
            'default' => false,
            'description' => null,
            'description_callback' => false,
            'field_callback' => null,
            'label' => '',
            'placeholder' => null,
            'type' => 'text',
        ];

        // only register settings for the active tab
        $active_tab = $this->getActiveTab();
        $sections = array_filter($sections, function ($key) use ($active_tab) {
            return $key == $active_tab;
        }, ARRAY_FILTER_USE_KEY);

        foreach ($sections as $key => $section) {
            $_id_key = $menu_slug . '-' . $key;

            add_settings_section(
                $_id_key,
                $section['title'],
                function () use ($section) {
                    echo '<p>' . wp_kses($section['description'], self::getAllowedTags()) . '</p>';
                },
                $_id_key
            );

            foreach ($options as $key2 => $option) {
                $option = wp_parse_args($option, $default_option);

                if ($option['section'] == $active_tab) {
                    add_settings_field(
                        self::prefix($key2),
                        $option['label'],
                        function () use ($key2, $option) {
                            $value = get_option(self::prefix($key2), $option['default']);
                            switch ($option['type']) {
                                case 'callback':
                                    call_user_func($option['field_callback']);
                                    break;

                                case 'checkbox':
                                    echo '<input type="checkbox" name="' . esc_attr(self::prefix($key2)) . '" value="true" ' . ($value ? 'checked' : '') . '>';
                                    break;

                                case 'media':
                                    echo '<input type="text" name="' . esc_attr(self::prefix($key2)) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($option['placeholder']) . '" class="regular-text">';
                                    echo '<button class="button button-secondary" id="' . esc_attr(self::prefix($key2)) . '_button">Upload</button>';

                                    echo '<script>
                                        jQuery(document).ready(function($) {
                                            var custom_uploader;
                                            $("#' . esc_attr(self::prefix($key2)) . '_button").click(function(e) {
                                                e.preventDefault();
                                                if (custom_uploader) {
                                                    custom_uploader.open();
                                                    return;
                                                }
                                                custom_uploader = wp.media.frames.file_frame = wp.media({
                                                    title: "Choose Image",
                                                    button: {
                                                        text: "Choose Image"
                                                    },
                                                    multiple: false
                                                });
                                                custom_uploader.on("select", function() {
                                                    var attachment = custom_uploader.state().get("selection").first().toJSON();
                                                    $("input[name=' . esc_attr(self::prefix($key2)) . ']").val(attachment.url);
                                                });
                                                custom_uploader.open();
                                            });
                                        });
                                    </script>';
                                    break;

                                case 'number':
                                    echo '<input type="number" name="' . esc_attr(self::prefix($key2)) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($option['placeholder']) . '" class="regular-text">';
                                    break;

                                case 'radio':
                                    foreach ($option['options'] as $key3 => $option2) {
                                        echo '<label><input type="radio" name="' . esc_attr(self::prefix($key2)) . '" value="' . esc_attr($key3) . '" ' . ($value == $key3 ? 'checked' : '') . '> ' . esc_html($option2) . '</label><br>';
                                    }
                                    break;

                                case 'password':
                                    echo '<input type="password" name="' . esc_attr(self::prefix($key2)) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($option['placeholder']) . '" class="regular-text">';
                                    break;

                                case 'select':
                                    echo '<select name="' . esc_attr(self::prefix($key2)) . '">';
                                    foreach ($option['options'] as $key3 => $option2) {
                                        echo '<option value="' . esc_attr($key3) . '" ' . ($value == $key3 ? 'selected' : '') . '>' . esc_html($option2) . '</option>';
                                    }
                                    echo '</select>';
                                    break;

                                case 'text':
                                    echo '<input type="text" name="' . esc_attr(self::prefix($key2)) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($option['placeholder']) . '" class="regular-text">';
                                    break;

                                case 'textarea':
                                    echo '<textarea name="' . esc_attr(self::prefix($key2)) . '" placeholder="' . esc_attr($option['placeholder']) . '" class="large-text code" rows="10" cols"50">' . esc_html($value) . '</textarea>';
                                    break;
                            }

                            if ($option['description']) {
                                echo '<p class="description">' . wp_kses($option['description'], self::getAllowedTags()) . '</p>';
                            }

                            // if $option['description_callback'] and admin screen is options panel
                            if ($option['description_callback']) {
                                call_user_func($option['description_callback']);
                            }
                        },
                        $_id_key,
                        $_id_key
                    );
                    register_setting($menu_slug, esc_attr(self::prefix($key2)));
                }
            }
        }
    }

    private function renderOptionsPage(array $options = [], array $sections = [], string $menu_slug = '', string $title = '')
    {
        // get active tab, or first tab
        $active_tab = $this->getActiveTab();
?>
        <div class="<?php echo esc_attr($this->outputPageWrapClass() . $active_tab); ?>" id="<?php echo esc_attr($menu_slug); ?>">
            <h1><?php echo esc_html($title); ?></h1>
            <h2 class="nav-tab-wrapper">
                <?php foreach ($sections as $key => $section) :
                    $url = '?page=' . esc_attr($menu_slug) . '&tab=' . esc_attr($key);
                    $nav_tab = $active_tab == $key ? 'nav-tab-active' : '';
                ?>
                    <a href="<?php echo esc_url($url); ?>" class="nav-tab <?php echo esc_attr($nav_tab); ?>"><?php echo esc_html($section['title']); ?></a>
                <?php endforeach; ?>
            </h2>
            <form method="post" action="options.php">
                <?php
                settings_fields($menu_slug);
                do_settings_sections($menu_slug . '-' . $active_tab);
                // output hidden field with current tab, this sitting isn't saved
                echo '<input type="hidden" name="tab" value="' . esc_attr($active_tab) . '">';
                submit_button();
                ?>
            </form>
        </div>
<?php
    }

    public function prefix(string $key = '', string $separator = '_')
    {
        return str_replace('-', $separator, $this->prefix . (!empty($key) ? $separator . $key : ''));
    }

    public function prefixDash(string $key = '')
    {
        return $this->prefix($key, '-');
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
