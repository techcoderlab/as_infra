<?php

/**
 * Plugin Name: Agency SaaS Master Interceptor (Optimized)
 * Description: High-performance bridge that injects a Master Identity into frontend forms and captures them for your SaaS.
 * Version: 3.0.0
 * Author: Your Agency
 */
if (! defined('ABSPATH')) {
    exit;
}

class Agency_Master_Interceptor
{
    // Class Constants for easy maintenance
    const OPT_GROUP = 'as_master_settings';

    const OPT_URL = 'as_webhook_url';

    const OPT_FID = 'as_form_id';

    const OPT_SEL = 'as_css_selector';

    // The hidden field name injected into forms
    const IDENTITY_FIELD = 'as_form_identity';

    private $webhook_url;

    private $master_id;

    private $css_selector;

    public function __construct()
    {
        // 1. SETTINGS UI
        add_action('admin_menu', [$this, 'add_plugin_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // 2. OPTIMIZATION: Load options once.
        $this->webhook_url = get_option(self::OPT_URL);
        $this->master_id = get_option(self::OPT_FID);
        $this->css_selector = get_option(self::OPT_SEL, 'form');

        // Only attach hooks if configured
        if (! empty($this->webhook_url) && ! empty($this->master_id)) {
            // A. Inject the Identity via JS (Frontend Only)
            add_action('wp_footer', [$this, 'inject_form_identity_js']);

            // B. Listen for the submission (Global)
            add_action('init', [$this, 'intercept_global_request']);
        }
    }

    /**
     * A. INJECTOR: Adds hidden input field to forms
     * Uses pure JS for max compatibility (no jQuery dependency).
     */
    public function inject_form_identity_js()
    {
        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                var selector = '<?php echo esc_js($this->css_selector); ?>';
                var forms = document.querySelectorAll(selector);

                forms.forEach(function(form) {
                    // Prevent duplicate injection
                    if (!form.querySelector('input[name="<?php echo self::IDENTITY_FIELD; ?>"]')) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = '<?php echo self::IDENTITY_FIELD; ?>';
                        input.value = '<?php echo esc_js($this->master_id); ?>';
                        form.appendChild(input);
                    }
                });
            });
        </script>
<?php
    }

    /**
     * B. INTERCEPTOR: Captures traffic
     * Optimized: Fails fast if Identity is missing.
     */
    public function intercept_global_request()
    {
        // FAIL FAST 1: Method check
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        // FAIL FAST 2: Admin/Dashboard check
        // We only want frontend forms, not backend admin saves.
        if (is_admin()) {
            return;
        }

        // FAIL FAST 3: Identity Check (The "Master Key")
        // If the POST data doesn't have our injected ID, we ignore it immediately.
        // This makes the plugin extremely lightweight for non-target forms.
        if (! isset($_POST[self::IDENTITY_FIELD]) || $_POST[self::IDENTITY_FIELD] !== $this->master_id) {
            return;
        }

        // CAPTURE & CLEAN
        // Native array filtering is faster than foreach loops
        $submissionData = $_POST;

        // Remove our identity field so it doesn't clutter the payload
        unset($submissionData[self::IDENTITY_FIELD]);

        // Remove WP internals (keys starting with '_') and sensitive fields
        $cleanData = array_filter($submissionData, function ($key) {
            return $key[0] !== '_' && ! in_array($key, ['pwd', 'pass', 'password', 'confirm_password']);
        }, ARRAY_FILTER_USE_KEY);

        if (empty($cleanData)) {
            return;
        }

        // DISPATCH
        $this->push_to_webhook($cleanData);
    }

    private function push_to_webhook(array $data)
    {
        $payload = [
            'data' => [
                'formId' => $this->master_id, // Always reliable now
                'formSource' => 'wordpressform',
                // 'formName'   => 'Injected Form Capture: ' . ($_SERVER['REQUEST_URI'] ?? '/'),
                'fields' => $data,
                'meta' => [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                ],
            ],
        ];

        // Non-blocking request with timeout
        wp_safe_remote_post($this->webhook_url, [
            'body' => json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 3,
            'blocking' => true,
            'sslverify' => true,
        ]);
    }

    // --- ADMIN UI ---
    public function add_plugin_page()
    {
        add_options_page('Agency Master', 'Agency Master', 'manage_options', 'agency-master', [$this, 'create_admin_page']);
    }

    public function create_admin_page()
    {
        echo '<div class="wrap"><h1>Agency Form Identity Settings</h1><form method="post" action="options.php">';
        settings_fields(self::OPT_GROUP);
        do_settings_sections('agency-master');
        submit_button();
        echo '</form></div>';
    }

    public function register_settings()
    {
        register_setting(self::OPT_GROUP, self::OPT_URL);
        register_setting(self::OPT_GROUP, self::OPT_FID);
        register_setting(self::OPT_GROUP, self::OPT_SEL);

        add_settings_section('as_main', 'Webhook Configuration', null, 'agency-master');

        add_settings_field(self::OPT_URL, 'Webhook URL', function () {
            echo '<input type="url" name="'.self::OPT_URL.'" value="'.esc_attr(get_option(self::OPT_URL)).'" class="regular-text">';
            echo '<p class="description">The URL where the form data will be sent (Contact Admin).</p>';
        }, 'agency-master', 'as_main');

        add_settings_field(self::OPT_SEL, 'Target Form', function () {
            echo '<input type="text" name="'.self::OPT_SEL.'" value="'.esc_attr(get_option(self::OPT_SEL, 'form')).'" class="regular-text">';
            echo '<p class="description">Target Form Selector (e.g., <code>#contact-form</code>).</p>';
        }, 'agency-master', 'as_main');
        add_settings_field(self::OPT_FID, 'Reference ID', function () {
            echo '<input type="text" name="'.self::OPT_FID.'" value="'.esc_attr(get_option(self::OPT_FID)).'" class="regular-text">';
            echo '<p class="description">This is the identifier (Contact Admin).</p>';
        }, 'agency-master', 'as_main');
    }
}

new Agency_Master_Interceptor;
