<?php
/**
 * Settings Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class CFQA_Settings {
    private static $instance = null;
    private $options;
    private $option_name = 'cfqa_settings';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $default_options = array(
            'auto_insert_courses' => 'yes',
            'auto_insert_lessons' => 'yes',
            'auto_insert_topic' => 'yes',
            'instructor_email_address' => '',
            'email_template_question_approved' => $this->get_default_template('question_approved'),
            'email_template_new_answer' => $this->get_default_template('new_answer'),
            'email_template_new_reply' => $this->get_default_template('new_reply'),
            'email_template_new_question' => $this->get_default_template('new_question')
        );
        $this->options = get_option($this->option_name, $default_options);
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_enqueue_scripts', 'enqueue_font_awesome', 5);
        add_action('admin_enqueue_scripts', 'enqueue_font_awesome', 5);
    }

    private function get_default_template($type) {
        switch ($type) {
            case 'question_approved':
                return __(
                    "Hello {user_name},\n\n" .
                    "Your question \"{question_title}\" has been approved and is now visible on the site.\n\n" .
                    "You can view it here: {question_link}\n\n" .
                    "Best regards,\n" .
                    "{site_name}",
                    'code-fortress-qa'
                );

            case 'new_answer':
                return __(
                    "Hello {user_name},\n\n" .
                    "A new answer has been posted to your question \"{question_title}\".\n\n" .
                    "Answer by {answer_author}:\n{answer_content}\n\n" .
                    "View the answer here: {question_link}\n\n" .
                    "Best regards,\n" .
                    "{site_name}",
                    'code-fortress-qa'
                );

            case 'new_reply':
                return __(
                    "Hello {user_name},\n\n" .
                    "A new reply has been posted to your answer on the question \"{question_title}\".\n\n" .
                    "Reply by {reply_author}:\n{reply_content}\n\n" .
                    "View the reply here: {question_link}\n\n" .
                    "Best regards,\n" .
                    "{site_name}",
                    'code-fortress-qa'
                );

            case 'new_question':
                return __(
                    "A new question has been submitted on {site_name}\n\n" .
                    "Title: {question_title}\n" .
                    "Author: {question_author}\n" .
                    "Content: {question_content}\n\n" .
                    "Review the question here: {question_link}",
                    'code-fortress-qa'
                );

            default:
                return '';
        }
    }

    public function get_email_template($type) {
        $option_key = 'email_template_' . $type;
        return isset($this->options[$option_key]) ? $this->options[$option_key] : $this->get_default_template($type);
    }

    public function add_menu_page() {
        add_submenu_page(
            'edit.php?post_type=cfqa_question',
            __('Settings', 'code-fortress-qa'),
            __('Settings', 'code-fortress-qa'),
            'manage_options',
            'cfqa-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting(
            $this->option_name,
            $this->option_name,
            array(
                'sanitize_callback' => array($this, 'sanitize_settings')
            )
        );

        // Auto-insert Section
        add_settings_section(
            'cfqa_auto_insert',
            __('Auto-insert Settings', 'code-fortress-qa'),
            array($this, 'render_auto_insert_section'),
            'cfqa-settings'
        );

        // Auto-insert fields for different post types
        $post_types = array(
            'courses' => __('Courses', 'code-fortress-qa'),
            'lessons' => __('Lessons', 'code-fortress-qa'),
            'topic' => __('Topics', 'code-fortress-qa')
        );

        foreach ($post_types as $type => $label) {
            add_settings_field(
                'auto_insert_' . $type,
                sprintf(__('Auto-insert in %s', 'code-fortress-qa'), $label),
                array($this, 'render_checkbox_field'),
                'cfqa-settings',
                'cfqa_auto_insert',
                array(
                    'id' => 'auto_insert_' . $type,
                    'description' => sprintf(__('Automatically insert Q&A section in %s', 'code-fortress-qa'), strtolower($label))
                )
            );
        }

        // Email Templates Section
        add_settings_section(
            'cfqa_email_templates',
            __('Email Notification Settings', 'code-fortress-qa'),
            array($this, 'render_email_templates_section'),
            'cfqa-settings'
        );
        
        // Add instructor email field in the email settings section
        add_settings_field(
            'instructor_email_address',
            __('Instructor Email Address', 'code-fortress-qa'),
            array($this, 'render_email_field'),
            'cfqa-settings',
            'cfqa_email_templates',
            array(
                'id' => 'instructor_email_address',
                'description' => __('This email address will be CC\'ed on all question submission notifications.', 'code-fortress-qa')
            )
        );

        // Add email template fields
        $templates = array(
            'question_approved' => __('Question Approved Email', 'code-fortress-qa'),
            'new_answer' => __('New Answer Email', 'code-fortress-qa'),
            'new_reply' => __('New Reply Email', 'code-fortress-qa'),
            'new_question' => __('New Question Email', 'code-fortress-qa')
        );

        foreach ($templates as $key => $label) {
            add_settings_field(
                'email_template_' . $key,
                $label,
                array($this, 'render_textarea_field'),
                'cfqa-settings',
                'cfqa_email_templates',
                array(
                    'id' => 'email_template_' . $key,
                    'description' => $this->get_template_placeholders($key)
                )
            );
        }
    }

    public function sanitize_settings($input) {
        // Verify nonce
        if (!isset($_POST['cfqa_settings_nonce']) || !wp_verify_nonce($_POST['cfqa_settings_nonce'], 'cfqa_settings_nonce')) {
            add_settings_error(
                'cfqa_messages',
                'cfqa_message',
                __('Security check failed. Settings not saved.', 'code-fortress-qa'),
                'error'
            );
            return $this->options; // Return existing options
        }

        $sanitized = array();

        // Sanitize auto-insert checkboxes
        $post_types = array('courses', 'lessons', 'topic');
        foreach ($post_types as $type) {
            $key = 'auto_insert_' . $type;
            $sanitized[$key] = isset($input[$key]) ? 'yes' : 'no';
        }
        
        // Sanitize instructor email
        if (isset($input['instructor_email_address'])) {
            $sanitized['instructor_email_address'] = sanitize_email($input['instructor_email_address']);
        }

        // Sanitize email templates
        $templates = array('question_approved', 'new_answer', 'new_reply', 'new_question');
        foreach ($templates as $template) {
            $key = 'email_template_' . $template;
            if (isset($input[$key])) {
                $sanitized[$key] = wp_kses_post($input[$key]);
            } else {
                $sanitized[$key] = $this->get_default_template($template);
            }
        }

        return $sanitized;
    }

    private function get_template_placeholders($template_key) {
        $placeholders = array(
            'question_approved' => '{user_name}, {question_title}, {question_link}, {site_name}',
            'new_answer' => '{user_name}, {answer_author}, {question_title}, {answer_content}, {question_link}, {site_name}',
            'new_reply' => '{user_name}, {reply_author}, {question_title}, {reply_content}, {question_link}, {site_name}',
            'new_question' => '{question_author}, {question_title}, {question_content}, {question_link}, {site_name}'
        );

        return sprintf(
            __('Available placeholders: %s', 'code-fortress-qa'),
            $placeholders[$template_key]
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'code-fortress-qa'));
        }

        // Check if settings were updated
        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            add_settings_error(
                'cfqa_messages',
                'cfqa_message',
                __('Settings Saved', 'code-fortress-qa'),
                'updated'
            );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php settings_errors('cfqa_messages'); ?>
            <form action="options.php" method="post">
                <?php
                settings_fields($this->option_name);
                do_settings_sections('cfqa-settings');
                wp_nonce_field('cfqa_settings_nonce', 'cfqa_settings_nonce');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_auto_insert_section() {
        echo '<p>' . __('Configure where the Q&A section should be automatically inserted.', 'code-fortress-qa') . '</p>';
    }

    public function render_email_templates_section() {
        echo '<p>' . __('Configure email notification settings and customize email templates. Use the available placeholders in each template.', 'code-fortress-qa') . '</p>';
    }

    public function render_checkbox_field($args) {
        $id = $args['id'];
        $value = $this->get_option($id);
        ?>
        <input type="checkbox" 
               id="<?php echo esc_attr($id); ?>" 
               name="<?php echo esc_attr($this->option_name . '[' . $id . ']'); ?>" 
               value="yes" 
               <?php checked($value, 'yes'); ?>>
        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($args['description']); ?></label>
        <?php
    }

    public function render_textarea_field($args) {
        $id = $args['id'];
        $value = $this->get_option($id);
        ?>
        <textarea id="<?php echo esc_attr($id); ?>" 
                  name="<?php echo esc_attr($this->option_name . '[' . $id . ']'); ?>" 
                  rows="5" 
                  class="large-text"><?php echo esc_textarea($value); ?></textarea>
        <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php
    }

    public function render_email_field($args) {
        $id = $args['id'];
        $value = $this->get_option($id);
        ?>
        <input type="email" 
               id="<?php echo esc_attr($id); ?>" 
               name="<?php echo esc_attr($this->option_name . '[' . $id . ']'); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text">
        <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php
    }

    public function get_option($key, $default = '') {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }
}

// Initialize the class
CFQA_Settings::get_instance();

function enqueue_font_awesome() {
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', array(), '6.4.0');
}
