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

    public function getActiveTab(string $default = ''): string
    {
        if (!isset($this->sections)) {
            return $default;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : array_key_first($this->sections);

        return $active_tab;
    }

    public static function getAllowedTags(): array
    {
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
            'em' => [],
            'i' => [],
            'q' => [
                'cite' => [],
                'title' => [],
            ],
            's' => [],
            'script' => [
                'type' => [],
            ],
            'small' => [],
            'span' => [
                'class' => [],
            ],
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
            $_id_key = $menu_slug . '-' . $key;

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
                        self::prefix($key2),
                        $option['label'],
                        function () use ($key2, $option) {
                            $value = get_option(self::prefix($key2), $option['default']);
                            switch ($option['type']) {
                                case 'callback':
                                    call_user_func($option['field_callback']);
                                    break;

                                case 'checkbox':
                                    echo '<input type="checkbox" name="' . self::prefix($key2) . '" value="true" ' . ($value ? 'checked' : '') . '>';
                                    break;

                                case 'number':
                                    echo '<input type="number" name="' . self::prefix($key2) . '" value="' . $value . '" placeholder="' . ($option['placeholder'] ?? null) . '" class="regular-text">';
                                    break;

                                case 'radio':
                                    foreach ($option['options'] as $key3 => $option2) {
                                        echo '<label><input type="radio" name="' . self::prefix($key2) . '" value="' . $key3 . '" ' . ($value == $key3 ? 'checked' : '') . '> ' . $option2 . '</label><br>';
                                    }
                                    break;

                                case 'password':
                                    echo '<input type="password" name="' . self::prefix($key2) . '" value="' . $value . '" placeholder="' . ($option['placeholder'] ?? null) . '" class="regular-text">';
                                    break;

                                case 'select':
                                    echo '<select name="' . self::prefix($key2) . '">';
                                    foreach ($option['options'] as $key3 => $option2) {
                                        echo '<option value="' . $key3 . '" ' . ($value == $key3 ? 'selected' : '') . '>' . $option2 . '</option>';
                                    }
                                    echo '</select>';
                                    break;

                                case 'text':
                                    echo '<input type="text" name="' . self::prefix($key2) . '" value="' . $value . '" placeholder="' . ($option['placeholder'] ?? null) . '" class="regular-text">';
                                    break;

                                case 'textarea':
                                    echo '<textarea name="' . self::prefix($key2) . '" placeholder="' . ($option['placeholder'] ?? null) . '" class="regular-text">' . $value . '</textarea>';
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
                    register_setting($menu_slug, self::prefix($key2));
                }
            }
        }
    }

    private function renderOptionsPage(array $options = [], array $sections = [], string $menu_slug = '', string $title = '')
    {
        // get active tab, or first tab
        $active_tab = $this->getActiveTab();
?>
        <div class="<?php echo $this->outputPageWrapClass(); ?> <?php echo $active_tab; ?>" id="<?php echo $menu_slug; ?>">
            <h1><?php echo $title; ?></h1>
            <h2 class="nav-tab-wrapper">
                <?php foreach ($sections as $key => $section) : ?>
                    <a href="?page=<?php echo $menu_slug; ?>&tab=<?php echo $key; ?>" class="nav-tab <?php echo $active_tab == $key ? 'nav-tab-active' : ''; ?>"><?php echo $section['title']; ?></a>
                <?php endforeach; ?>
            </h2>
            <form method="post" action="options.php">
                <?php
                settings_fields($menu_slug);
                do_settings_sections($menu_slug . '-' . $active_tab);
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
                                echo '<textarea name="' . self::prefix($key) . '" class="hidden">' . get_option(self::prefix($key), $option['default']) . '</textarea>';
                                break;
                            default:
                                echo '<input type="hidden" name="' . self::prefix($key) . '" value="' . get_option(self::prefix($key), $option['default']) . '">';
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
