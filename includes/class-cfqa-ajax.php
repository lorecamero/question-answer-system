<?php
/**
 * AJAX Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class CFQA_Ajax {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Question submission
        add_action('wp_ajax_cfqa_submit_question', array($this, 'handle_question_submission'));
        add_action('wp_ajax_nopriv_cfqa_submit_question', array($this, 'handle_not_logged_in'));
        
        // Load more questions
        add_action('wp_ajax_cfqa_load_more_questions', array($this, 'handle_load_more_questions'));
        add_action('wp_ajax_nopriv_cfqa_load_more_questions', array($this, 'handle_load_more_questions'));
        
        // Question approval
        add_action('wp_ajax_cfqa_approve_question', array($this, 'handle_question_approval'));
        
        // Check for new approvals
        add_action('wp_ajax_cfqa_check_approvals', array($this, 'handle_check_approvals'));
        add_action('wp_ajax_nopriv_cfqa_check_approvals', array($this, 'handle_check_approvals'));

        // Quick Reply
        add_action('wp_ajax_cfqa_submit_reply', array($this, 'handle_submit_reply'));

        // Answer submission - only handle not logged in case
        add_action('wp_ajax_nopriv_cfqa_submit_answer', array($this, 'handle_not_logged_in'));
    }

    public function handle_not_logged_in() {
        wp_send_json_error(array(
            'message' => __('You must be logged in to perform this action.', 'code-fortress-qa'),
        ));
    }

    public function handle_question_submission() {
        try {
            error_log('CFQA Debug: Starting question submission');
            
            // Verify nonce and user permissions
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cfqa-frontend-nonce')) {
                wp_send_json_error(array(
                    'message' => __('Security check failed.', 'code-fortress-qa')
                ));
            }

            // Get and validate input
            $content = isset($_POST['question_content']) ? sanitize_textarea_field($_POST['question_content']) : '';
            $title = isset($_POST['question_title']) ? sanitize_text_field($_POST['question_title']) : '';
            $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

            if (empty($content) || empty($post_id) || empty($title)) {
                error_log('CFQA Error: Missing required fields');
                wp_send_json_error(array(
                    'message' => __('Please fill in all required fields.', 'code-fortress-qa'),
                    'debug' => array(
                        'has_content' => !empty($content),
                        'has_post_id' => !empty($post_id),
                        'has_title' => !empty($title)
                    )
                ));
            }

            // Verify post exists and is of correct type
            $post_type = get_post_type($post_id);
            if (!$post_type || !in_array($post_type, array('sfwd-courses', 'sfwd-lessons', 'sfwd-topic'))) {
                error_log('CFQA Error: Invalid post type - ' . $post_type);
                wp_send_json_error(array(
                    'message' => __('Invalid course, lesson, or topic.', 'code-fortress-qa')
                ));
            }

            // Create the question
            $question_data = array(
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => 'publish',
                'post_type' => 'cfqa_question',
                'post_author' => get_current_user_id(),
            );

            error_log('CFQA Debug: Creating question with data - ' . wp_json_encode($question_data));

            $question_id = wp_insert_post($question_data, true);

            if (is_wp_error($question_id)) {
                error_log('CFQA Error: Failed to create question - ' . $question_id->get_error_message());
                wp_send_json_error(array(
                    'message' => __('Failed to create question. Please try again.', 'code-fortress-qa'),
                    'debug' => $question_id->get_error_message()
                ));
            }

            // Set the initial status to 'pending' in the taxonomy
            $term_result = wp_set_object_terms($question_id, 'pending', 'cfqa_status');
            if (is_wp_error($term_result)) {
                error_log('CFQA Error: Failed to set question status - ' . $term_result->get_error_message());
            }

            // Store the source page ID
            update_post_meta($question_id, '_cfqa_source_page_id', $post_id);

            // Add relationship to course/lesson/topic
            $meta_key = '_cfqa_related_' . str_replace('sfwd-', '', $post_type);
            $meta_result = update_post_meta($question_id, $meta_key, $post_id);
            
            error_log('CFQA Debug: Updated post meta - ' . wp_json_encode(array(
                'meta_key' => $meta_key,
                'post_id' => $post_id,
                'result' => $meta_result
            )));

            // If it's a lesson or topic, also store the course relationship
            if (in_array($post_type, array('sfwd-lessons', 'sfwd-topic'))) {
                $course_id = learndash_get_course_id($post_id);
                if ($course_id) {
                    update_post_meta($question_id, '_cfqa_related_course', $course_id);
                }
            }

            error_log('CFQA Debug: Question submission completed successfully');

            wp_send_json_success(array(
                'message' => __('Your question has been submitted and is pending approval.', 'code-fortress-qa'),
                'question_id' => $question_id,
            ));

        } catch (Exception $e) {
            error_log('CFQA Error: Unexpected error - ' . $e->getMessage());
            error_log('CFQA Error: Stack trace - ' . $e->getTraceAsString());
            wp_send_json_error(array(
                'message' => __('An unexpected error occurred. Please try again.', 'code-fortress-qa'),
                'debug' => $e->getMessage()
            ));
        }
    }

    public function handle_load_more_questions() {
        try {
            // Verify nonce
            if (!check_ajax_referer('cfqa-frontend-nonce', 'nonce', false)) {
                wp_send_json_error(array(
                    'message' => __('Security check failed.', 'code-fortress-qa'),
                ));
            }

            // Get and validate parameters
            $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 2; // Default to 2 per page

            if (empty($post_id)) {
                wp_send_json_error(array(
                    'message' => __('Invalid request: Missing or invalid post ID.', 'code-fortress-qa'),
                ));
            }

            // Verify post exists and is of correct type
            $post = get_post($post_id);
            if (!$post) {
                wp_send_json_error(array(
                    'message' => __('Invalid request: Post not found.', 'code-fortress-qa'),
                ));
            }

            $post_type = get_post_type($post);
            if (!in_array($post_type, array('sfwd-courses', 'sfwd-lessons', 'sfwd-topic'))) {
                wp_send_json_error(array(
                    'message' => __('Invalid request: Invalid post type.', 'code-fortress-qa'),
                ));
            }

            $args = array(
                'post_type' => 'cfqa_question',
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'meta_query' => array(
                    array(
                        'key' => '_cfqa_related_' . str_replace('sfwd-', '', $post_type),
                        'value' => $post_id,
                    ),
                ),
                'tax_query' => array(
                    array(
                        'taxonomy' => 'cfqa_status',
                        'field' => 'slug',
                        'terms' => 'approved',
                        'operator' => 'IN'
                    ),
                ),
                'orderby' => 'date',
                'order' => 'DESC',
            );

            $questions = new WP_Query($args);
            
            // Generate questions HTML
            $html = '';
            if ($questions->have_posts()) {
                ob_start();
                while ($questions->have_posts()) {
                    $questions->the_post();
                    include(plugin_dir_path(dirname(__FILE__)) . 'templates/question-item.php');
                }
                $html = ob_get_clean();
                wp_reset_postdata();
            }
            
            // Generate pagination HTML
            $pagination_html = '';
            if ($questions->max_num_pages > 1) {
                ob_start();
                echo '<div class="cfqa-pagination">';
                $current_page_url = get_pagenum_link(1);
                $current_page_url = remove_query_arg('paged', $current_page_url);
                
                echo paginate_links(array(
                    'base' => $current_page_url . '%_%',
                    'format' => '?paged=%#%',
                    'current' => max(1, $page),
                    'total' => $questions->max_num_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'type' => 'list',
                    'mid_size' => 2,
                    'end_size' => 1,
                    'add_args' => false
                ));
                echo '</div>';
                $pagination_html = ob_get_clean();
            }

            wp_send_json_success(array(
                'html' => $html,
                'pagination' => $pagination_html,
                'has_more' => $questions->max_num_pages > $page,
                'total_pages' => $questions->max_num_pages,
                'current_page' => $page,
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('An error occurred while loading more questions.', 'code-fortress-qa'),
            ));
        }
    }

    /**
     * Handle question approval AJAX request
     */
    public function handle_question_approval() {
        try {
            // Verify nonce
            if (!check_ajax_referer('cfqa-admin-nonce', 'nonce', false)) {
                wp_send_json_error(array(
                    'message' => __('Security check failed.', 'code-fortress-qa')
                ));
            }

            // Check user capabilities
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array(
                    'message' => __('You do not have permission to perform this action.', 'code-fortress-qa')
                ));
            }

            // Get and validate question ID
            $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
            if (!$question_id) {
                wp_send_json_error(array(
                    'message' => __('Invalid question ID.', 'code-fortress-qa')
                ));
            }

            // Verify question exists and is of correct type
            $question = get_post($question_id);
            if (!$question || $question->post_type !== 'cfqa_question') {
                wp_send_json_error(array(
                    'message' => __('Question not found.', 'code-fortress-qa')
                ));
            }

            // Update the question status in the taxonomy
            $result = wp_set_object_terms($question_id, 'approved', 'cfqa_status');
            if (is_wp_error($result)) {
                wp_send_json_error(array(
                    'message' => __('Failed to approve question.', 'code-fortress-qa'),
                    'error' => $result->get_error_message()
                ));
            }

            // Store the last approval timestamp
            update_option('cfqa_last_approval_time', time());

            // Send success response
            wp_send_json_success(array(
                'message' => __('Question approved successfully.', 'code-fortress-qa'),
                'question_id' => $question_id
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('An error occurred while approving the question.', 'code-fortress-qa'),
                'error' => $e->getMessage()
            ));
        }
    }

    /**
     * Handle quick reply submission
     */
    public function handle_submit_reply() {
        try {
            // Verify nonce
            if (!check_ajax_referer('cfqa-admin-nonce', 'nonce', false)) {
                wp_send_json_error(array(
                    'message' => __('Security check failed.', 'code-fortress-qa')
                ));
            }

            // Check user capabilities
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array(
                    'message' => __('You do not have permission to perform this action.', 'code-fortress-qa')
                ));
            }

            // Get and validate parameters
            $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
            $content = isset($_POST['content']) ? wp_kses_post($_POST['content']) : '';

            if (!$question_id || empty($content)) {
                wp_send_json_error(array(
                    'message' => __('Missing required fields.', 'code-fortress-qa')
                ));
            }

            // Verify question exists and is of correct type
            $question = get_post($question_id);
            if (!$question || $question->post_type !== 'cfqa_question') {
                wp_send_json_error(array(
                    'message' => __('Question not found.', 'code-fortress-qa')
                ));
            }

            // Create the comment/reply
            $comment_data = array(
                'comment_post_ID' => $question_id,
                'comment_content' => $content,
                'user_id' => get_current_user_id(),
                'comment_type' => 'answer',
                'comment_approved' => 1
            );

            $comment_id = wp_insert_comment($comment_data);

            if (!$comment_id) {
                wp_send_json_error(array(
                    'message' => __('Failed to submit reply.', 'code-fortress-qa')
                ));
            }

            wp_send_json_success(array(
                'message' => __('Reply submitted successfully.', 'code-fortress-qa'),
                'comment_id' => $comment_id
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('An error occurred while submitting the reply.', 'code-fortress-qa'),
                'error' => $e->getMessage()
            ));
        }
    }

    /**
     * Handle checking for new approvals
     */
    public function handle_check_approvals() {
        try {
            // Verify nonce
            if (!check_ajax_referer('cfqa-frontend-nonce', 'nonce', false)) {
                wp_send_json_error(array(
                    'message' => __('Security check failed.', 'code-fortress-qa')
                ));
            }

            // Get parameters
            $last_check = isset($_POST['last_check']) ? intval($_POST['last_check']) : 0;
            $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

            if (!$post_id) {
                wp_send_json_error(array(
                    'message' => __('Invalid post ID.', 'code-fortress-qa')
                ));
            }

            // Get the last approval time
            $last_approval = get_option('cfqa_last_approval_time', 0);
            
            wp_send_json_success(array(
                'new_approvals' => ($last_approval > $last_check),
                'current_time' => time()
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('An error occurred while checking for approvals.', 'code-fortress-qa'),
                'error' => $e->getMessage()
            ));
        }
    }
}

// Initialize the class
CFQA_Ajax::get_instance(); 