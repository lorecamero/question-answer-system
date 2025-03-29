<?php
/**
 * Admin Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class CFQA_Admin {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Load required classes
        $this->load_dependencies();
        
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_filter('manage_cfqa_question_posts_columns', array($this, 'add_custom_columns'));
        add_action('manage_cfqa_question_posts_custom_column', array($this, 'manage_custom_columns'), 10, 2);
        add_filter('manage_edit-cfqa_question_sortable_columns', array($this, 'sortable_columns'));
        
        // Remove unwanted columns added by plugins
        add_filter('manage_cfqa_question_posts_columns', array($this, 'remove_plugin_columns'), 100);
        
        add_action('restrict_manage_posts', array($this, 'add_admin_filters'));
        add_action('pre_get_posts', array($this, 'modify_admin_query'));
        add_filter('post_row_actions', array($this, 'add_quick_actions'), 10, 2);
        add_action('wp_ajax_cfqa_approve_question', array($this, 'handle_approve_question'));
        add_action('wp_ajax_cfqa_disapprove_question', array($this, 'handle_disapprove_question'));
        add_action('wp_ajax_cfqa_get_question_details', array($this, 'handle_get_question_details'));
        add_action('wp_ajax_cfqa_submit_answer', array($this, 'handle_submit_answer'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_footer', array($this, 'admin_footer'));
        
        // Add hooks for answer count tracking
        add_action('save_post', array($this, 'update_answer_count'), 10, 3);
        add_action('before_delete_post', array($this, 'update_answer_count'), 10, 3);
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        // Load the notifications class if not already loaded
        if (!class_exists('CFQA_Notifications')) {
            $file_path = plugin_dir_path(dirname(__FILE__)) . 'includes/class-cfqa-notifications.php';
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
        
        // Load the settings class if not already loaded
        if (!class_exists('CFQA_Settings')) {
            $file_path = plugin_dir_path(dirname(__FILE__)) . 'includes/class-cfqa-settings.php';
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }

    public function enqueue_admin_scripts($hook) {
        global $post_type;
        
        if ($post_type !== 'cfqa_question') {
            return;
        }

        wp_enqueue_style('cfqa-admin', plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin.css', array(), time());
        wp_enqueue_script('cfqa-admin', plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js', array('jquery'), time(), true);
        
        wp_localize_script('cfqa-admin', 'cfqaAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cfqa-admin-nonce'),
            'i18n' => array(
                'confirmApprove' => __('Are you sure you want to approve this question?', 'code-fortress-qa'),
                'approveSuccess' => __('Question approved successfully!', 'code-fortress-qa'),
                'approveError' => __('Error approving question. Please try again.', 'code-fortress-qa'),
                'answerSuccess' => __('Answer submitted successfully!', 'code-fortress-qa'),
                'answerError' => __('Error submitting answer. Please try again.', 'code-fortress-qa'),
                'loadingQuestion' => __('Loading question...', 'code-fortress-qa'),
            )
        ));
    }

    private function is_plugin_page($hook) {
        $plugin_pages = array(
            'cfqa_question_page_cfqa-dashboard',
            'cfqa_question_page_cfqa-settings',
            'edit.php',
            'post.php',
            'post-new.php'
        );

        if (in_array($hook, $plugin_pages)) {
            // For edit.php and similar pages, check if we're on our post type
            if (in_array($hook, array('edit.php', 'post.php', 'post-new.php'))) {
                $screen = get_current_screen();
                return $screen && $screen->post_type === 'cfqa_question';
            }
            return true;
        }

        return false;
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=cfqa_question',
            __('Q&A Dashboard', 'code-fortress-qa'),
            __('Dashboard', 'code-fortress-qa'),
            'manage_options',
            'cfqa-dashboard',
            array($this, 'render_admin_page')
        );
    }

    public function add_meta_boxes() {
        add_meta_box(
            'cfqa_moderation',
            __('Question Moderation', 'code-fortress-qa'),
            array($this, 'render_moderation_meta_box'),
            'cfqa_question',
            'side',
            'high'
        );
    }

    public function render_moderation_meta_box($post) {
        wp_nonce_field('cfqa_moderation', 'cfqa_moderation_nonce');

        $status = wp_get_object_terms($post->ID, 'cfqa_status', array('fields' => 'slugs'));
        $current_status = !empty($status) ? $status[0] : 'pending';
        ?>
        <div class="cfqa-moderation-controls">
            <p>
                <label for="cfqa_status"><?php _e('Status:', 'code-fortress-qa'); ?></label>
                <select name="cfqa_status" id="cfqa_status">
                    <option value="pending" <?php selected($current_status, 'pending'); ?>>
                        <?php _e('Pending', 'code-fortress-qa'); ?>
                    </option>
                    <option value="approved" <?php selected($current_status, 'approved'); ?>>
                        <?php _e('Approved', 'code-fortress-qa'); ?>
                    </option>
                    <option value="rejected" <?php selected($current_status, 'rejected'); ?>>
                        <?php _e('Rejected', 'code-fortress-qa'); ?>
                    </option>
                </select>
            </p>
            <p class="description">
                <?php _e('Change the moderation status of this question.', 'code-fortress-qa'); ?>
            </p>
        </div>
        <?php
    }

    public function add_custom_columns($columns) {
        $new_columns = array();
        
        // Define columns to exclude
        $exclude_columns = array('comments', 'aioseo-details', 'status');
        
        // Add new columns explicitly in the order we want them
        $new_columns['cb'] = isset($columns['cb']) ? $columns['cb'] : '';
        $new_columns['title'] = isset($columns['title']) ? $columns['title'] : __('Title', 'code-fortress-qa');
        $new_columns['author'] = isset($columns['author']) ? $columns['author'] : __('Author', 'code-fortress-qa');
        $new_columns['status'] = __('Status', 'code-fortress-qa'); // Add status column once
        $new_columns['has_answer'] = __('Has Answer', 'code-fortress-qa');
        $new_columns['related_content'] = __('Related Content', 'code-fortress-qa');
        $new_columns['actions'] = __('Actions', 'code-fortress-qa');
        $new_columns['date'] = isset($columns['date']) ? $columns['date'] : __('Date', 'code-fortress-qa');
        
        // Add any other columns we didn't explicitly include, except excluded ones
        foreach ($columns as $key => $value) {
            if (!isset($new_columns[$key]) && !in_array($key, $exclude_columns)) {
                $new_columns[$key] = $value;
            }
        }
        
        return $new_columns;
    }

    public function manage_custom_columns($column, $post_id) {
        switch ($column) {
            case 'status':
                $terms = wp_get_object_terms($post_id, 'cfqa_status');
                if (!empty($terms) && !is_wp_error($terms)) {
                    $status = $terms[0]->name;
                    $status_class = 'cfqa-status-' . $terms[0]->slug;
                    echo '<span class="cfqa-status ' . esc_attr($status_class) . '">' . esc_html($status) . '</span>';
                }
                break;

            case 'has_answer':
                $answers = get_posts(array(
                    'post_type' => 'cfqa_answer',
                    'post_parent' => $post_id,
                    'posts_per_page' => -1,
                    'fields' => 'ids'
                ));
                
                $answer_count = count($answers);
                echo $answer_count;
                break;

            case 'related_content':
                $source_page_id = get_post_meta($post_id, '_cfqa_source_page_id', true);
                if ($source_page_id) {
                    $source_page = get_post($source_page_id);
                    if ($source_page) {
                        echo '<div class="cfqa-related-item">';
                        echo '<a href="' . get_edit_post_link($source_page_id) . '" class="cfqa-related-link">';
                        echo '<strong>' . esc_html($source_page->post_title) . '</strong>';
                        echo ' <span class="cfqa-id-badge">(ID: ' . esc_html($source_page_id) . ')</span>';
                        echo '</a>';
                        echo '</div>';
                    }
                } else {
                    echo '<em>' . __('No source page', 'code-fortress-qa') . '</em>';
                }
                break;

            case 'actions':
                $status_terms = wp_get_object_terms($post_id, 'cfqa_status');
                $current_status = !empty($status_terms) ? $status_terms[0]->slug : '';
                
                if ($current_status === 'pending') {
                    echo '<button type="button" class="button cfqa-approve-question" data-question-id="' . esc_attr($post_id) . '">'
                        . '<i class="dashicons dashicons-yes"></i> ' . __('Approve', 'code-fortress-qa')
                        . '</button>';
                }
                
                echo ' <button type="button" class="button cfqa-answer-question" data-question-id="' . esc_attr($post_id) . '">'
                    . '<i class="dashicons dashicons-format-chat"></i> ' . __('Answer', 'code-fortress-qa')
                    . '</button>';
                break;
        }
    }

    public function sortable_columns($columns) {
        $columns['status'] = 'status';
        $columns['has_answer'] = 'answer_count';
        $columns['answers'] = 'answers';
        return $columns;
    }

    public function add_admin_filters() {
        global $typenow;

        if ($typenow !== 'cfqa_question') {
            return;
        }

        // Status filter
        $current_status = isset($_GET['cfqa_status']) ? $_GET['cfqa_status'] : '';
        $statuses = get_terms(array(
            'taxonomy' => 'cfqa_status',
            'hide_empty' => false,
        ));

        if (!empty($statuses)) {
            echo '<select name="cfqa_status">';
            echo '<option value="">' . __('All Statuses', 'code-fortress-qa') . '</option>';
            
            foreach ($statuses as $status) {
                echo sprintf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($status->slug),
                    selected($current_status, $status->slug, false),
                    esc_html($status->name)
                );
            }
            
            echo '</select>';
        }

        // Course filter
        $current_course = isset($_GET['cfqa_course']) ? $_GET['cfqa_course'] : '';
        $courses = get_posts(array(
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
        ));

        if (!empty($courses)) {
            echo '<select name="cfqa_course">';
            echo '<option value="">' . __('All Courses', 'code-fortress-qa') . '</option>';
            
            foreach ($courses as $course) {
                echo sprintf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($course->ID),
                    selected($current_course, $course->ID, false),
                    esc_html($course->post_title)
                );
            }
            
            echo '</select>';
        }
    }

    public function modify_admin_query($query) {
        global $pagenow;

        if (!is_admin() || $pagenow !== 'edit.php' || !$query->is_main_query() || $query->get('post_type') !== 'cfqa_question') {
            return;
        }

        // Status filter
        if (!empty($_GET['cfqa_status'])) {
            $query->set('tax_query', array(
                array(
                    'taxonomy' => 'cfqa_status',
                    'field' => 'slug',
                    'terms' => sanitize_text_field($_GET['cfqa_status']),
                ),
            ));
        }

        // Course filter
        if (!empty($_GET['cfqa_course'])) {
            $query->set('meta_query', array(
                array(
                    'key' => '_cfqa_related_course',
                    'value' => intval($_GET['cfqa_course']),
                ),
            ));
        }

        // Answer count sorting
        if ($query->get('orderby') === 'answer_count') {
            $query->set('meta_key', '_cfqa_answer_count');
            $query->set('orderby', 'meta_value_num');
        }
    }

    public function add_quick_actions($actions, $post) {
        if ($post->post_type === 'cfqa_question') {
            $terms = wp_get_post_terms($post->ID, 'cfqa_status');
            $status = !empty($terms) ? $terms[0]->slug : 'pending';
            
            if ($status === 'pending') {
                $actions['approve'] = sprintf(
                    '<a href="#" class="cfqa-approve-btn" data-question-id="%s">%s</a>',
                    esc_attr($post->ID),
                    esc_html__('Approve', 'code-fortress-qa')
                );
            } elseif ($status === 'approved') {
                $actions['disapprove'] = sprintf(
                    '<a href="#" class="cfqa-disapprove-btn" data-question-id="%s">%s</a>',
                    esc_attr($post->ID),
                    esc_html__('Disapprove', 'code-fortress-qa')
                );
            }
        }
        
        return $actions;
    }

    public function handle_approve_question() {
        $this->verify_admin_ajax();
        
        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        
        if (!$question_id) {
            wp_send_json_error(array('message' => __('Invalid question ID.', 'code-fortress-qa')));
        }
        
        // Get the current status before changing it
        $current_status_terms = wp_get_object_terms($question_id, 'cfqa_status');
        $current_status = !empty($current_status_terms) ? $current_status_terms[0]->slug : 'pending';
        
        // Set the new status
        $result = wp_set_object_terms($question_id, 'approved', 'cfqa_status');
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Get the post to grab post status
        $question = get_post($question_id);
        $old_post_status = $question ? $question->post_status : '';
        
        if ($question && $question->post_status !== 'publish') {
            // Update post status to publish if it's not already
            wp_update_post(array(
                'ID' => $question_id,
                'post_status' => 'publish'
            ));
        }
        
        // Only trigger notification if the status is actually changing from pending to approved
        if ($current_status !== 'approved' && class_exists('CFQA_Notifications')) {
            $notifications = CFQA_Notifications::get_instance();
            // Pass the actual old status
            $notifications->notify_on_question_status_change('publish', $old_post_status, $question);
        }
        
        wp_send_json_success(array(
            'message' => __('Question approved successfully.', 'code-fortress-qa'),
            'status' => 'approved'
        ));
    }

    public function handle_disapprove_question() {
        $this->verify_admin_ajax();
        
        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        
        if (!$question_id) {
            wp_send_json_error(array('message' => __('Invalid question ID.', 'code-fortress-qa')));
        }
        
        $result = wp_set_object_terms($question_id, 'pending', 'cfqa_status');
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => __('Question disapproved.', 'code-fortress-qa'),
            'status' => 'pending'
        ));
    }

    public function handle_get_question_details() {
        // Verify nonce and capabilities
        check_ajax_referer('cfqa-admin-nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'code-fortress-qa')));
        }

        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        if (!$question_id) {
            wp_send_json_error(array('message' => __('Invalid question ID.', 'code-fortress-qa')));
        }

        // Get question
        $question = get_post($question_id);
        if (!$question || $question->post_type !== 'cfqa_question') {
            wp_send_json_error(array('message' => __('Question not found.', 'code-fortress-qa')));
        }

        // Get question status
        $status_terms = wp_get_object_terms($question_id, 'cfqa_status');
        $status = !empty($status_terms) ? $status_terms[0]->name : __('Pending', 'code-fortress-qa');

        // Get related content info
        $related_content = '';
        $post_types = array('course' => __('Course', 'code-fortress-qa'), 
                           'lesson' => __('Lesson', 'code-fortress-qa'), 
                           'topic' => __('Topic', 'code-fortress-qa'));
        
        foreach ($post_types as $type => $label) {
            $related_id = get_post_meta($question_id, '_cfqa_related_' . $type, true);
            if ($related_id) {
                $related_post = get_post($related_id);
                if ($related_post) {
                    $related_content .= sprintf(
                        '<div class="cfqa-related-item"><strong>%s:</strong> %s</div>',
                        esc_html($label),
                        esc_html($related_post->post_title)
                    );
                }
            }
        }

        // Get author info
        $author = get_user_by('id', $question->post_author);
        $author_info = '';
        if ($author) {
            $author_info = sprintf(
                '<div class="cfqa-author-info"><strong>%s:</strong> %s</div>',
                __('Asked by', 'code-fortress-qa'),
                esc_html($author->display_name)
            );
        }

        // Build meta information
        $meta = sprintf(
            '<div class="cfqa-question-meta">
                <div class="cfqa-status-info"><strong>%s:</strong> <span class="cfqa-status cfqa-status-%s">%s</span></div>
                %s
                %s
                <div class="cfqa-date-info"><strong>%s:</strong> %s</div>
            </div>',
            __('Status', 'code-fortress-qa'),
            strtolower($status),
            esc_html($status),
            $author_info,
            $related_content,
            __('Asked on', 'code-fortress-qa'),
            get_the_date('', $question)
        );

        wp_send_json_success(array(
            'title' => $question->post_title,
            'content' => wpautop($question->post_content),
            'meta' => $meta
        ));
    }

    public function handle_submit_answer() {
        try {
            // Verify nonce using check_ajax_referer
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cfqa-admin-nonce')) {
                wp_send_json_error(array(
                    'message' => __('Security check failed. Please refresh the page and try again.', 'code-fortress-qa'),
                    'code' => 'nonce_failed'
                ));
            }

            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array(
                    'message' => __('You do not have permission to perform this action.', 'code-fortress-qa'),
                    'code' => 'insufficient_permissions'
                ));
            }

            // Rate limiting
            $user_id = get_current_user_id();
            $rate_limit_key = 'cfqa_answer_rate_limit_' . $user_id;
            $last_submission = get_transient($rate_limit_key);
            
            if ($last_submission !== false) {
                wp_send_json_error(array(
                    'message' => __('Please wait a few seconds before submitting another answer.', 'code-fortress-qa'),
                    'code' => 'rate_limit'
                ));
            }

            // Validate input
            $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
            $content = isset($_POST['content']) ? wp_kses_post($_POST['content']) : '';

            if (!$question_id) {
                wp_send_json_error(array(
                    'message' => __('Invalid question ID.', 'code-fortress-qa'),
                    'code' => 'invalid_question'
                ));
            }

            if (empty($content) || strlen(trim($content)) < 10) {
                wp_send_json_error(array(
                    'message' => __('Please provide a more detailed answer (minimum 10 characters).', 'code-fortress-qa'),
                    'code' => 'invalid_content'
                ));
            }

            // Verify question exists and is the correct post type
            $question = get_post($question_id);
            if (!$question || $question->post_type !== 'cfqa_question') {
                wp_send_json_error(array(
                    'message' => __('Question not found or invalid.', 'code-fortress-qa'),
                    'code' => 'question_not_found'
                ));
            }

            // Create the answer
            $answer_data = array(
                'post_type' => 'cfqa_answer',
                'post_title' => wp_trim_words($content, 10, '...'),
                'post_content' => $content,
                'post_status' => 'publish',
                'post_author' => $user_id,
                'post_parent' => $question_id
            );

            $answer_id = wp_insert_post($answer_data, true);

            if (is_wp_error($answer_id)) {
                error_log('CFQA Error: Failed to create answer - ' . $answer_id->get_error_message());
                wp_send_json_error(array(
                    'message' => __('Failed to create answer. Please try again.', 'code-fortress-qa'),
                    'code' => 'create_failed',
                    'debug' => $answer_id->get_error_message()
                ));
            }

            // Set rate limit
            set_transient($rate_limit_key, time(), 5); // 5 seconds rate limit

            // Add answer meta
            update_post_meta($answer_id, '_cfqa_answer_type', 'instructor');

            // Update question status if needed
            $status_terms = wp_get_object_terms($question_id, 'cfqa_status');
            $current_status = !empty($status_terms) ? $status_terms[0]->slug : '';
            
            if ($current_status === 'pending') {
                wp_set_object_terms($question_id, 'answered', 'cfqa_status');
            }

            // Send admin notification
            $this->send_answer_notification($question_id, $answer_id);
            
            // Notify question author
            $this->notify_question_author($question_id, $answer_id);

            // Get updated row HTML for the response
            ob_start();
            $this->render_answer_row($answer_id);
            $row_html = ob_get_clean();

            wp_send_json_success(array(
                'message' => __('Answer submitted successfully!', 'code-fortress-qa'),
                'answer_id' => $answer_id,
                'row_html' => $row_html
            ));

        } catch (Exception $e) {
            error_log('CFQA Error: Exception in answer submission - ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('An unexpected error occurred. Please try again.', 'code-fortress-qa'),
                'code' => 'exception',
                'debug' => $e->getMessage()
            ));
        }
    }

    private function send_answer_notification($question_id, $answer_id) {
        // Get notification settings
        $settings = get_option('cfqa_settings', array());
        
        // Check if notification email or CC emails are configured
        $has_notification_targets = false;
        
        if (!empty($settings['notification_email'])) {
            $has_notification_targets = true;
        }
        
        // Get CC email addresses
        $cc_emails = array();
        if (!empty($settings['cc_email_addresses'])) {
            $email_lines = explode("\n", $settings['cc_email_addresses']);
            foreach ($email_lines as $email) {
                $email = trim($email);
                if (is_email($email)) {
                    $cc_emails[] = $email;
                    $has_notification_targets = true;
                }
            }
        }
        
        // Get instructor email addresses
        $instructor_notification_email = !empty($settings['instructor_notification_email']) ? $settings['instructor_notification_email'] : '';
        
        // Get instructor email from auto-insert settings
        $instructor_email_address = '';
        if (class_exists('CFQA_Settings')) {
            $cfqa_settings = CFQA_Settings::get_instance();
            if ($cfqa_settings) {
                $instructor_email_address = $cfqa_settings->get_option('instructor_email_address', '');
            }
        }
        
        if (is_email($instructor_notification_email) || is_email($instructor_email_address)) {
            $has_notification_targets = true;
        }
        
        // If no notification targets, exit
        if (!$has_notification_targets) {
            return;
        }

        // Get post and user data
        $question = get_post($question_id);
        $answer = get_post($answer_id);
        $answerer = get_userdata($answer->post_author);
        
        if (!$question || !$answer || !$answerer) {
            return;
        }

        // Check if notifications class exists
        if (class_exists('CFQA_Notifications')) {
            // Use the notifications class for better email handling
            $notifications = CFQA_Notifications::get_instance();
            $subject = sprintf(
                __('[%s] New Answer Posted', 'code-fortress-qa'),
                get_bloginfo('name')
            );
            
            // Prepare the data for template parsing
            $data = array(
                'question_title' => $question->post_title,
                'answer_content' => $answer->post_content,
                'answer_author' => $answerer->display_name,
                'question_link' => add_query_arg('answer_id', $answer_id, get_permalink($question_id)),
                'site_name' => get_bloginfo('name')
            );
            
            $emailContent = sprintf(
                __("A new answer has been posted:\n\nQuestion: %s\nAnswered by: %s\nAnswer: %s\n\nView it here: %s", 'code-fortress-qa'),
                $question->post_title,
                $answerer->display_name,
                wp_trim_words($answer->post_content, 50, '...'),
                add_query_arg('answer_id', $answer_id, get_permalink($question_id))
            );
            
            // Use the notifications class send_email method through reflection
            // since it's a private method
            try {
                $reflection = new ReflectionClass($notifications);
                $method = $reflection->getMethod('send_email');
                $method->setAccessible(true);
                
                // We need at least one primary recipient
                $primary_recipient = false;
                
                // Send notification to admin if configured
                if (!empty($settings['notification_email'])) {
                    $method->invoke($notifications, $settings['notification_email'], $subject, $emailContent);
                    $primary_recipient = true;
                }
                
                // Send to CC emails
                foreach ($cc_emails as $cc_email) {
                    $method->invoke($notifications, $cc_email, $subject, $emailContent);
                    $primary_recipient = true;
                }
                
                // Send notification to instructor notification email if configured
                if (!empty($instructor_notification_email) && is_email($instructor_notification_email)) {
                    $method->invoke($notifications, $instructor_notification_email, $subject, $emailContent);
                    $primary_recipient = true;
                }
                
                // If we have no primary recipient but we do have an instructor email in auto-insert settings,
                // we need to send directly to it (otherwise it would just be CC'd)
                if (!$primary_recipient && !empty($instructor_email_address) && is_email($instructor_email_address)) {
                    $method->invoke($notifications, $instructor_email_address, $subject, $emailContent);
                }
                
                return;
            } catch (Exception $e) {
                error_log('CFQA Error: Failed to use notifications class: ' . $e->getMessage());
                // Fall back to simple mail if reflection fails
            }
        }
        
        // Fallback to simple email if notifications class isn't available
        $subject = sprintf(
            __('[%s] New Answer Posted', 'code-fortress-qa'),
            get_bloginfo('name')
        );

        $message = sprintf(
            __("A new answer has been posted:\n\nQuestion: %s\nAnswered by: %s\n\nView it here: %s", 'code-fortress-qa'),
            $question->post_title,
            $answerer->display_name,
            add_query_arg('answer_id', $answer_id, get_permalink($question_id))
        );

        $notification_email = !empty($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email');
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . $notification_email . '>'
        );
        
        // Add CC header for instructor email from auto-insert settings
        if (!empty($instructor_email_address) && is_email($instructor_email_address)) {
            $headers[] = 'Cc: ' . $instructor_email_address;
        }

        // We need at least one primary recipient
        $primary_recipient = false;
        
        // Send to admin email if configured
        if (!empty($settings['notification_email'])) {
            wp_mail($settings['notification_email'], $subject, wpautop($message), $headers);
            $primary_recipient = true;
        }
        
        // Send to CC emails
        foreach ($cc_emails as $cc_email) {
            wp_mail($cc_email, $subject, wpautop($message), $headers);
            $primary_recipient = true;
        }
        
        // Send to instructor notification email if configured
        if (!empty($instructor_notification_email) && is_email($instructor_notification_email)) {
            wp_mail($instructor_notification_email, $subject, wpautop($message), $headers);
            $primary_recipient = true;
        }
        
        // If we have no primary recipient but we do have an instructor email address,
        // send directly to it
        if (!$primary_recipient && !empty($instructor_email_address) && is_email($instructor_email_address)) {
            wp_mail($instructor_email_address, $subject, wpautop($message), $headers);
        }
    }

    /**
     * Notify the author of a question when their question receives an answer
     *
     * @param int $question_id ID of the question
     * @param int $answer_id ID of the answer
     */
    private function notify_question_author($question_id, $answer_id) {
        $question = get_post($question_id);
        $answer = get_post($answer_id);
        
        if (!$question || !$answer) {
            return;
        }
        
        // Get the question author
        $question_author = get_user_by('id', $question->post_author);
        if (!$question_author || $question_author->ID == $answer->post_author) {
            // Don't notify if author is answering their own question
            return;
        }
        
        if (class_exists('CFQA_Notifications')) {
            // Get the notifications instance
            $notifications = CFQA_Notifications::get_instance();
            
            // Directly use the notify_on_new_answer method
            $notifications->notify_on_new_answer($answer_id, $answer, false);
            return;
        }
        
        // Fallback if notifications class isn't available
        $admin_email = get_option('admin_email');
        $subject = sprintf(
            __('[%s] New Answer to Your Question', 'code-fortress-qa'),
            get_bloginfo('name')
        );
        
        $answerer = get_userdata($answer->post_author);
        $message = sprintf(
            __("Hello %s,\n\nYour question \"%s\" has received a new answer from %s.\n\nAnswer: %s\n\nView it here: %s", 'code-fortress-qa'),
            $question_author->display_name,
            $question->post_title,
            $answerer ? $answerer->display_name : __('Unknown', 'code-fortress-qa'),
            wp_trim_words($answer->post_content, 50, '...'),
            add_query_arg('answer_id', $answer_id, get_permalink($question_id))
        );
        
        // Get instructor email from auto-insert settings
        $instructor_email_address = '';
        if (class_exists('CFQA_Settings')) {
            $cfqa_settings = CFQA_Settings::get_instance();
            if ($cfqa_settings) {
                $instructor_email_address = $cfqa_settings->get_option('instructor_email_address', '');
            }
        }
        
        $notification_email = get_option('cfqa_settings');
        if (!empty($notification_email['notification_email'])) {
            $from_email = $notification_email['notification_email'];
        } else {
            $from_email = $admin_email;
        }
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . $from_email . '>'
        );
        
        // Add CC header for instructor email
        if (!empty($instructor_email_address) && is_email($instructor_email_address)) {
            $headers[] = 'Cc: ' . $instructor_email_address;
        }
        
        wp_mail($question_author->user_email, $subject, wpautop($message), $headers);
    }

    private function render_answer_row($answer_id) {
        $answer = get_post($answer_id);
        if (!$answer) {
            return;
        }

        $author = get_userdata($answer->post_author);
        $answer_type = get_post_meta($answer_id, '_cfqa_answer_type', true);
        ?>
        <tr>
            <td class="author column-author">
                <?php echo esc_html($author ? $author->display_name : __('Unknown', 'code-fortress-qa')); ?>
            </td>
            <td class="content column-content">
                <?php echo wp_trim_words($answer->post_content, 20); ?>
            </td>
            <td class="type column-type">
                <?php echo esc_html($answer_type === 'instructor' ? __('Instructor', 'code-fortress-qa') : __('Student', 'code-fortress-qa')); ?>
            </td>
            <td class="date column-date">
                <?php echo get_the_date('', $answer_id); ?>
            </td>
        </tr>
        <?php
    }

    private function verify_admin_ajax() {
        check_ajax_referer('cfqa-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'code-fortress-qa')));
        }
    }

    public function admin_footer() {
        $screen = get_current_screen();
        
        // Check if we're on the questions list page
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'cfqa_question') {
            return;
        }
        ?>
        <!-- Answer Question Modal -->
        <div id="cfqa-answer-modal" class="cfqa-modal">
            <div class="cfqa-modal-content">
                <span class="cfqa-modal-close">&times;</span>
                <h2><?php _e('Answer Question', 'code-fortress-qa'); ?></h2>
                <div class="cfqa-question-preview"></div>
                <form id="cfqa-answer-form">
                    <input type="hidden" name="action" value="cfqa_submit_answer">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('cfqa-admin-nonce'); ?>">
                    <input type="hidden" name="question_id" id="cfqa-answer-question-id" value="">
                    <div class="cfqa-form-group">
                        <label for="cfqa-answer-content"><?php _e('Your Answer', 'code-fortress-qa'); ?></label>
                        <textarea id="cfqa-answer-content" name="content" rows="5" required></textarea>
                    </div>
                    <div class="cfqa-form-submit">
                        <button type="submit" class="button button-primary">
                            <?php _e('Submit Answer', 'code-fortress-qa'); ?>
                        </button>
                        <span class="spinner" style="float: none; margin-top: 0;"></span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Success Popup -->
        <div id="cfqa-success-popup" class="cfqa-popup">
            <div class="cfqa-popup-content">
                <div class="cfqa-popup-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="cfqa-popup-message">
                    <h3><?php _e('Answer Submitted!', 'code-fortress-qa'); ?></h3>
                    <p><?php _e('Your answer has been successfully submitted.', 'code-fortress-qa'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_admin_page() {
        // Get statistics with improved performance
        $questions_query = new WP_Query(array(
            'post_type' => 'cfqa_question',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false
        ));
        $total_questions = $questions_query->found_posts;

        $answers_query = new WP_Query(array(
            'post_type' => 'cfqa_answer',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false
        ));
        $total_answers = $answers_query->found_posts;

        // Get pending questions count more efficiently
        $pending_questions = get_terms(array(
            'taxonomy' => 'cfqa_status',
            'slug' => 'pending',
            'fields' => 'count',
            'hide_empty' => false
        ));
        $pending_questions = is_wp_error($pending_questions) ? 0 : $pending_questions;

        // Get recent activity with better error handling
        try {
            $recent_activity = new WP_Query(array(
                'post_type' => array('cfqa_question', 'cfqa_answer'),
                'posts_per_page' => 5,
                'orderby' => 'date',
                'order' => 'DESC',
                'no_found_rows' => true // Performance optimization
            ));
        } catch (Exception $e) {
            error_log('CFQA Error: Failed to fetch recent activity - ' . $e->getMessage());
            $recent_activity = new WP_Query();
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Code Fortress Q&A Dashboard', 'code-fortress-qa'); ?></h1>

            <div class="cfqa-admin-overview">
                <div class="cfqa-stats-grid">
                    <div class="cfqa-stat-box">
                        <h3><?php _e('Total Questions', 'code-fortress-qa'); ?></h3>
                        <span class="cfqa-stat-number"><?php echo number_format_i18n($total_questions); ?></span>
                    </div>

                    <div class="cfqa-stat-box">
                        <h3><?php _e('Total Answers', 'code-fortress-qa'); ?></h3>
                        <span class="cfqa-stat-number"><?php echo number_format_i18n($total_answers); ?></span>
                    </div>

                    <div class="cfqa-stat-box">
                        <h3><?php _e('Pending Questions', 'code-fortress-qa'); ?></h3>
                        <span class="cfqa-stat-number"><?php echo number_format_i18n($pending_questions); ?></span>
                        <?php if ($pending_questions > 0) : ?>
                            <a href="<?php echo esc_url(admin_url('edit.php?post_type=cfqa_question&cfqa_status=pending')); ?>" 
                               class="button button-secondary">
                                <?php _e('Review Pending', 'code-fortress-qa'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="cfqa-recent-activity">
                    <h2><?php _e('Recent Activity', 'code-fortress-qa'); ?></h2>
                    <?php if ($recent_activity->have_posts()) : ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Type', 'code-fortress-qa'); ?></th>
                                    <th><?php _e('Title', 'code-fortress-qa'); ?></th>
                                    <th><?php _e('Author', 'code-fortress-qa'); ?></th>
                                    <th><?php _e('Date', 'code-fortress-qa'); ?></th>
                                    <th><?php _e('Status', 'code-fortress-qa'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($recent_activity->have_posts()) : $recent_activity->the_post(); ?>
                                    <tr>
                                        <td>
                                            <?php 
                                            $post_type = get_post_type();
                                            echo esc_html($post_type === 'cfqa_question' ? __('Question', 'code-fortress-qa') : __('Answer', 'code-fortress-qa')); 
                                            ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo esc_url(get_edit_post_link()); ?>">
                                                <?php echo esc_html(get_the_title()); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php 
                                            $author = get_user_by('id', get_post_field('post_author'));
                                            echo esc_html($author ? $author->display_name : __('Unknown', 'code-fortress-qa'));
                                            ?>
                                        </td>
                                        <td><?php echo get_the_date(); ?></td>
                                        <td>
                                            <?php 
                                            if ($post_type === 'cfqa_question') {
                                                $status = wp_get_object_terms(get_the_ID(), 'cfqa_status');
                                                echo !empty($status) ? esc_html($status[0]->name) : __('Pending', 'code-fortress-qa');
                                            } else {
                                                _e('Published', 'code-fortress-qa');
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php _e('No recent activity.', 'code-fortress-qa'); ?></p>
                    <?php endif; ?>
                    <?php wp_reset_postdata(); ?>
                </div>

                <div class="cfqa-quick-links">
                    <h2><?php _e('Quick Links', 'code-fortress-qa'); ?></h2>
                    <div class="cfqa-quick-links-grid">
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=cfqa_question')); ?>" class="cfqa-quick-link">
                            <span class="dashicons dashicons-format-chat"></span>
                            <?php _e('Manage Questions', 'code-fortress-qa'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=cfqa_question&page=cfqa-dashboard')); ?>" class="cfqa-quick-link">
                            <span class="dashicons dashicons-dashboard"></span>
                            <?php _e('Dashboard', 'code-fortress-qa'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=cfqa_question&page=cfqa-settings')); ?>" class="cfqa-quick-link">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php _e('Settings', 'code-fortress-qa'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <style>
                .cfqa-admin-overview {
                    margin-top: 20px;
                }
                .cfqa-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }
                .cfqa-stat-box {
                    background: #fff;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    text-align: center;
                }
                .cfqa-stat-box h3 {
                    margin: 0 0 10px;
                    color: #23282d;
                }
                .cfqa-stat-number {
                    font-size: 2em;
                    font-weight: bold;
                    color: #0073aa;
                    display: block;
                    margin-bottom: 10px;
                }
                .cfqa-recent-activity {
                    background: #fff;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    margin-bottom: 30px;
                }
                .cfqa-quick-links {
                    background: #fff;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                .cfqa-quick-links-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin-top: 15px;
                }
                .cfqa-quick-link {
                    display: flex;
                    align-items: center;
                    padding: 15px;
                    background: #f8f9fa;
                    border-radius: 6px;
                    text-decoration: none;
                    color: #23282d;
                    transition: all 0.2s ease;
                }
                .cfqa-quick-link:hover {
                    background: #f1f1f1;
                    color: #0073aa;
                }
                .cfqa-quick-link .dashicons {
                    margin-right: 10px;
                    color: #0073aa;
                }
            </style>
        </div>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'code-fortress-qa'));
        }

        // Save settings
        if (isset($_POST['cfqa_save_settings']) && check_admin_referer('cfqa_settings_nonce')) {
            $settings = array(
                'answers_per_page' => absint($_POST['cfqa_answers_per_page']),
                'enable_voting' => isset($_POST['cfqa_enable_voting']),
                'require_moderation' => isset($_POST['cfqa_require_moderation']),
                'notification_email' => sanitize_email($_POST['cfqa_notification_email']),
                'instructor_notification_email' => sanitize_email($_POST['cfqa_instructor_notification_email']),
                'cc_email_addresses' => sanitize_textarea_field($_POST['cfqa_cc_email_addresses'])
            );
            update_option('cfqa_settings', $settings);
            add_settings_error('cfqa_messages', 'cfqa_message', __('Settings Saved', 'code-fortress-qa'), 'updated');
        }

        // Get current settings
        $settings = get_option('cfqa_settings', array(
            'answers_per_page' => 10,
            'enable_voting' => true,
            'require_moderation' => true,
            'notification_email' => get_option('admin_email'),
            'instructor_notification_email' => '',
            'cc_email_addresses' => ''
        ));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php settings_errors('cfqa_messages'); ?>

            <form method="post" action="">
                <?php wp_nonce_field('cfqa_settings_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="cfqa_answers_per_page"><?php _e('Answers per Page', 'code-fortress-qa'); ?></label>
                        </th>
                        <td>
                            <input name="cfqa_answers_per_page" type="number" id="cfqa_answers_per_page" 
                                   value="<?php echo esc_attr($settings['answers_per_page']); ?>" class="small-text">
                            <p class="description"><?php _e('Number of answers to display per page.', 'code-fortress-qa'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Features', 'code-fortress-qa'); ?></th>
                        <td>
                            <fieldset>
                                <label for="cfqa_enable_voting">
                                    <input name="cfqa_enable_voting" type="checkbox" id="cfqa_enable_voting" 
                                           value="1" <?php checked($settings['enable_voting']); ?>>
                                    <?php _e('Enable answer voting', 'code-fortress-qa'); ?>
                                </label>
                                <br>
                                <label for="cfqa_require_moderation">
                                    <input name="cfqa_require_moderation" type="checkbox" id="cfqa_require_moderation" 
                                           value="1" <?php checked($settings['require_moderation']); ?>>
                                    <?php _e('Require question moderation', 'code-fortress-qa'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cfqa_notification_email"><?php _e('Notification Email', 'code-fortress-qa'); ?></label>
                        </th>
                        <td>
                            <input name="cfqa_notification_email" type="email" id="cfqa_notification_email" 
                                   value="<?php echo esc_attr($settings['notification_email']); ?>" class="regular-text">
                            <p class="description"><?php _e('System email address used for sending notifications (From address).', 'code-fortress-qa'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cfqa_instructor_notification_email"><?php _e('Additional Notification Email', 'code-fortress-qa'); ?></label>
                        </th>
                        <td>
                            <input name="cfqa_instructor_notification_email" type="email" id="cfqa_instructor_notification_email" 
                                   value="<?php echo esc_attr($settings['instructor_notification_email']); ?>" class="regular-text">
                            <p class="description"><?php _e('Additional email address to receive notifications about questions and answers.', 'code-fortress-qa'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cfqa_cc_email_addresses"><?php _e('CC Email Addresses', 'code-fortress-qa'); ?></label>
                        </th>
                        <td>
                            <textarea name="cfqa_cc_email_addresses" id="cfqa_cc_email_addresses" 
                                      class="regular-text" rows="3"><?php echo esc_textarea($settings['cc_email_addresses']); ?></textarea>
                            <p class="description"><?php _e('Additional email addresses (one per line) that should receive copies of all question submissions.', 'code-fortress-qa'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="cfqa_save_settings" class="button button-primary" 
                           value="<?php esc_attr_e('Save Changes', 'code-fortress-qa'); ?>">
                </p>
            </form>
        </div>
        <?php
    }

    // Add this new method to update answer count
    public function update_answer_count($post_after, $post_before) {
        if ($post_after->post_type !== 'cfqa_answer') {
            return;
        }

        $question_id = $post_after->post_parent;
        if (!$question_id) {
            return;
        }

        $answers = get_posts(array(
            'post_type' => 'cfqa_answer',
            'post_parent' => $question_id,
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));

        update_post_meta($question_id, '_cfqa_answer_count', count($answers));
    }

    /**
     * Remove any columns added by plugins that we don't want
     * 
     * @param array $columns The current columns
     * @return array The filtered columns
     */
    public function remove_plugin_columns($columns) {
        // List of columns to remove
        $unwanted_columns = array(
            'aioseo-details',
            'rank_math_seo_details',
            'wpseo-score',
            'wpseo-score-readability',
            'wpseo-title',
            'wpseo-metadesc',
            'wpseo-focuskw',
            'seotitle',
            'seodesc',
            'seokeywords',
            'seo_score',
            'taxonomy-cfqa_status' // Remove the taxonomy-based status column
        );
        
        // Remove unwanted columns
        foreach ($unwanted_columns as $column) {
            if (isset($columns[$column])) {
                unset($columns[$column]);
            }
        }
        
        return $columns;
    }
}

// Initialize the class
CFQA_Admin::get_instance(); 
