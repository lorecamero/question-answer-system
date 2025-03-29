<?php
/**
 * Notifications Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class CFQA_Notifications {
    private static $instance = null;
    private $settings;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Make sure settings class exists and is loaded
        if (!class_exists('CFQA_Settings')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-cfqa-settings.php';
        }
        
        $this->settings = CFQA_Settings::get_instance();
        
        // Only add hooks if settings are properly initialized
        if ($this->settings) {
            add_action('transition_post_status', array($this, 'notify_on_question_status_change'), 10, 3);
            add_action('wp_insert_post', array($this, 'notify_on_new_answer'), 10, 3);
            add_action('wp_insert_post', array($this, 'notify_on_new_question'), 10, 3);
        }
    }

    public function notify_on_question_status_change($new_status, $old_status, $post) {
        if ($post->post_type !== 'cfqa_question') {
            return;
        }

        // Make sure settings are available
        if (!$this->settings) {
            error_log('CFQA Error: Settings not initialized in notifications class');
            return;
        }

        // Notify user when their question is approved
        if ($new_status === 'publish' && $old_status !== 'publish') {
            $user = get_user_by('id', $post->post_author);
            if (!$user) {
                return;
            }

            $subject = sprintf(
                __('[%s] Your question has been approved', 'code-fortress-qa'),
                get_bloginfo('name')
            );

            $template = $this->settings->get_email_template('question_approved');
            if (empty($template)) {
                error_log('CFQA Warning: Empty email template for question_approved');
                return;
            }

            $message = $this->parse_template($template, array(
                'user_name' => $user->display_name,
                'question_title' => $post->post_title,
                'question_link' => get_permalink($post->ID),
                'site_name' => get_bloginfo('name')
            ));

            // Send notification to the user
            $this->send_email($user->user_email, $subject, $message);
            
            // Also notify instructor about the approval with a different subject
            $instructor_subject = sprintf(
                __('[%s] Question was approved', 'code-fortress-qa'),
                get_bloginfo('name')
            );
            
            $instructor_message = sprintf(
                __("A question has been approved:\n\nTitle: %s\nApproved for: %s\n\nView it here: %s", 'code-fortress-qa'),
                $post->post_title,
                $user->display_name,
                get_permalink($post->ID)
            );
            
            // Get admin notification settings
            $cfqa_settings = get_option('cfqa_settings', array());
            
            // Get instructor notification email
            $instructor_notification_email = !empty($cfqa_settings['instructor_notification_email']) ? 
                $cfqa_settings['instructor_notification_email'] : '';
                
            if (!empty($instructor_notification_email) && is_email($instructor_notification_email)) {
                $this->send_email($instructor_notification_email, $instructor_subject, $instructor_message);
            }
            
            // The instructor_email_address from Auto-Insert settings will be automatically CC'd
            // by the send_email method, so we don't need to explicitly send to it here
        }
    }

    /**
     * Notify users when a new answer is posted
     * This method is now refactored to work with cfqa_answer post type instead of comments
     * 
     * @param int $post_id The answer post ID
     * @param WP_Post $post The answer post object
     * @param bool $update Whether this is an update or a new post
     */
    public function notify_on_new_answer($post_id, $post = null, $update = false) {
        // Skip if this is an update or not an answer post type
        if ($update || !$post || $post->post_type !== 'cfqa_answer') {
            return;
        }
        
        // Make sure settings are available
        if (!$this->settings) {
            error_log('CFQA Error: Settings not initialized in notifications class');
            return;
        }

        // Get the question (parent post)
        $question_id = $post->post_parent;
        if (!$question_id) {
            return;
        }
        
        $question = get_post($question_id);
        if (!$question || $question->post_type !== 'cfqa_question') {
            return;
        }

        // Get the answer author
        $answer_author = get_user_by('id', $post->post_author);
        if (!$answer_author) {
            return;
        }

        // Notify question author
        $question_author = get_user_by('id', $question->post_author);
        if ($question_author && $question_author->ID !== $answer_author->ID) {
            $subject = sprintf(
                __('[%s] New answer to your question', 'code-fortress-qa'),
                get_bloginfo('name')
            );

            $template = $this->settings->get_email_template('new_answer');
            if (empty($template)) {
                error_log('CFQA Warning: Empty email template for new_answer');
                return;
            }

            $message = $this->parse_template($template, array(
                'user_name' => $question_author->display_name,
                'question_title' => $question->post_title,
                'answer_content' => $post->post_content,
                'answer_author' => $answer_author->display_name,
                'question_link' => add_query_arg('answer_id', $post_id, get_permalink($question_id)),
                'site_name' => get_bloginfo('name')
            ));

            $this->send_email($question_author->user_email, $subject, $message);
        }
    }

    public function notify_on_new_question($post_id, $post, $update) {
        // Early return if this is an update or not a question
        if ($update || $post->post_type !== 'cfqa_question') {
            return;
        }

        // Make sure settings are available
        if (!$this->settings) {
            error_log('CFQA Error: Settings not initialized in notifications class');
            return;
        }

        // Get settings
        $cfqa_settings = get_option('cfqa_settings', array());
        
        // Get CC email addresses
        $cc_email_addresses = !empty($cfqa_settings['cc_email_addresses']) ? 
            $cfqa_settings['cc_email_addresses'] : '';
            
        // Parse CC email addresses
        $cc_emails = array();
        if (!empty($cc_email_addresses)) {
            $email_lines = explode("\n", $cc_email_addresses);
            foreach ($email_lines as $email) {
                $email = trim($email);
                if (is_email($email)) {
                    $cc_emails[] = $email;
                }
            }
        }
        
        // Get instructor notification email from settings
        $instructor_notification_email = !empty($cfqa_settings['instructor_notification_email']) ? 
            $cfqa_settings['instructor_notification_email'] : '';
            
        // Get instructor email from Auto-Insert settings
        $instructor_email_address = $this->settings->get_option('instructor_email_address', '');
        
        // If no CC emails, no instructor notification email, and no instructor email address in auto-insert settings
        // Don't send any notifications as there are no recipients
        if (empty($cc_emails) && empty($instructor_notification_email) && empty($instructor_email_address)) {
            return;
        }
        
        $subject = sprintf(
            __('[%s] New question pending approval', 'code-fortress-qa'),
            get_bloginfo('name')
        );

        // Get template with fallback
        $template = $this->settings->get_email_template('new_question');
        if (empty($template)) {
            $template = $this->get_default_template('new_question');
        }

        $message = $this->parse_template($template, array(
            'question_title' => $post->post_title,
            'question_content' => $post->post_content,
            'question_author' => get_the_author_meta('display_name', $post->post_author),
            'question_link' => get_edit_post_link($post_id, ''),
            'site_name' => get_bloginfo('name')
        ));

        // We need at least one primary recipient
        $primary_recipient = false;
        
        // Send to CC emails
        foreach ($cc_emails as $cc_email) {
            $this->send_email($cc_email, $subject, $message);
            $primary_recipient = true;
        }
        
        // Send to instructor notification email if provided
        if (!empty($instructor_notification_email) && is_email($instructor_notification_email)) {
            $this->send_email($instructor_notification_email, $subject, $message);
            $primary_recipient = true;
        }
        
        // If we have an instructor email address but no primary recipients yet,
        // we need to send directly to this email as well (it will normally just be CC'd)
        if (!$primary_recipient && !empty($instructor_email_address) && is_email($instructor_email_address)) {
            $this->send_email($instructor_email_address, $subject, $message);
        }
    }

    private function get_default_template($template_name) {
        $templates = array(
            'new_question' => "A new question has been submitted on {site_name}\n\n" .
                            "Title: {question_title}\n" .
                            "Author: {question_author}\n" .
                            "Content: {question_content}\n\n" .
                            "Review the question here: {question_link}",
        );
        
        return isset($templates[$template_name]) ? $templates[$template_name] : '';
    }

    private function parse_template($template, $data) {
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        return $template;
    }

    private function send_email($to, $subject, $message) {
        // Get settings for notification email
        $settings = get_option('cfqa_settings', array());
        $notification_email = !empty($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email');
        
        // Get instructor email from settings class for CC
        $instructor_email = '';
        if ($this->settings) {
            // Get instructor email from Auto-Insert settings section
            $instructor_email = $this->settings->get_option('instructor_email_address', '');
            
            // If for some reason it's empty, try direct approach from options
            if (empty($instructor_email) && isset($settings['instructor_email_address'])) {
                $instructor_email = sanitize_email($settings['instructor_email_address']);
            }
        }
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . $notification_email . '>',
        );
        
        // Add CC header if instructor email is set
        if (!empty($instructor_email) && is_email($instructor_email) && $to !== $instructor_email) {
            $headers[] = 'Cc: ' . $instructor_email;
        }

        $message = wpautop($message); // Convert line breaks to paragraphs
        $message = $this->get_email_template_wrapper($message);

        return wp_mail($to, $subject, $message, $headers);
    }

    private function get_email_template_wrapper($content) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
            <title><?php echo get_bloginfo('name'); ?></title>
        </head>
        <body style="margin: 0; padding: 0; background-color: #f6f6f6;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f6f6f6;">
                <tr>
                    <td style="padding: 40px 0;">
                        <table align="center" border="0" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <tr>
                                <td style="padding: 40px;">
                                    <?php echo $content; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 20px 40px; background-color: #f9f9f9; border-top: 1px solid #eee;">
                                    <p style="margin: 0; color: #666; font-size: 12px;">
                                        <?php echo get_bloginfo('name'); ?><br>
                                        <?php _e('To manage your email preferences, please visit your account settings.', 'code-fortress-qa'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

// Initialize the class
CFQA_Notifications::get_instance(); 