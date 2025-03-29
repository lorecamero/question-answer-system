<?php
/**
 * Frontend Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class CFQA_Frontend {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('init', array($this, 'register_shortcodes'));
        add_filter('the_content', array($this, 'append_qa_section'));
    }

    public function enqueue_scripts() {
        // Ensure Dashicons are loaded
        wp_enqueue_style('dashicons');
        
        wp_enqueue_style('cfqa-frontend', plugin_dir_url(dirname(__FILE__)) . 'assets/css/frontend.css', array(), time());
        wp_enqueue_script('cfqa-frontend', plugin_dir_url(dirname(__FILE__)) . 'assets/js/frontend.js', array('jquery'), time(), true);

        // Add debug mode in development
        $debug_mode = defined('WP_DEBUG') && WP_DEBUG;

        wp_localize_script('cfqa-frontend', 'cfqaAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cfqa-frontend-nonce'),
            'debug' => $debug_mode,
            'loadMoreAction' => 'cfqa_load_more_questions',
            'submitQuestionAction' => 'cfqa_submit_question',
            'currentPageId' => get_the_ID(),
            'i18n' => array(
                'loadMoreError' => __('Error loading more questions. Please try again.', 'code-fortress-qa'),
                'submitError' => __('Error submitting question. Please try again.', 'code-fortress-qa'),
                'networkError' => __('Network error occurred. Please check your connection and try again.', 'code-fortress-qa'),
            )
        ));
    }

    public function register_shortcodes() {
        add_shortcode('cfqa_form', array($this, 'question_form_shortcode'));
        add_shortcode('cfqa_list', array($this, 'questions_list_shortcode'));
    }

    public function append_qa_section($content) {
        global $post;

        if (!is_singular(array('sfwd-courses', 'sfwd-lessons', 'sfwd-topic'))) {
            return $content;
        }

        // Get settings instance
        $settings = CFQA_Settings::get_instance();

        // Check if auto-insert is enabled for this post type
        $post_type = get_post_type();
        $setting_key = 'auto_insert_' . str_replace('sfwd-', '', $post_type);
        
        if ($settings->get_option($setting_key) !== 'yes') {
            return $content;
        }

        ob_start();
        ?>
        <div class="cfqa-section">
            <h3><?php _e('Questions & Answers', 'code-fortress-qa'); ?></h3>
            <?php 
            echo do_shortcode('[cfqa_form]');
            echo do_shortcode('[cfqa_list]');
            ?>
        </div>
        <?php
        $qa_section = ob_get_clean();

        return $content . $qa_section;
    }

    private function is_instructor($user_id) {
        // Check if user has instructor role in LearnDash
        $group_leader_role = 'group_leader';
        $instructor_role = 'ld_instructor';
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }

        return in_array($group_leader_role, $user->roles) || in_array($instructor_role, $user->roles);
    }

    public function question_form_shortcode($atts) {
        if (!is_user_logged_in()) {
            return sprintf(
                '<p>%s</p>',
                __('Please log in to ask questions.', 'code-fortress-qa')
            );
        }

        ob_start();
        ?>
        <div class="cfqa-question-form">
            <form id="cfqa-ask-question" method="post">
                <?php wp_nonce_field('cfqa-frontend-nonce', 'nonce'); ?>
                <input type="hidden" name="action" value="cfqa_submit_question">
                <input type="hidden" name="post_id" value="<?php echo esc_attr(get_the_ID()); ?>">
                
                <!-- Honeypot field for spam protection -->
                <div class="cfqa-honeypot" style="display:none;">
                    <input type="text" name="cfqa_website" value="">
                </div>

                <div class="cfqa-form-group">
                    <input type="text" id="question_title" name="question_title" placeholder="Name" required>
                </div>

                <div class="cfqa-form-group">
                    <textarea id="question_content" name="question_content" rows="5" placeholder="What's your question?" required></textarea>
                </div>

                <div class="cfqa-form-submit">
                    <button type="submit" class="cfqa-submit-btn">
                        <?php _e('Submit', 'code-fortress-qa'); ?> <img src="/wp-content/uploads/2025/03/Arrow-1.svg" />
                    </button>
                    <span class="cfqa-spinner" style="display: none;"></span>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function questions_list_shortcode($atts) {
        // Early return if not on a LearnDash post type
        $post_id = get_the_ID();
        $post_type = get_post_type($post_id);
        
        if (!$post_id || !in_array($post_type, array('sfwd-courses', 'sfwd-lessons', 'sfwd-topic'))) {
            error_log('CFQA Debug: Invalid post type or ID in questions_list_shortcode - ID: ' . $post_id . ', Type: ' . $post_type);
            return '';
        }

        // Get current page from query var
        $paged = get_query_var('paged') ? absint(get_query_var('paged')) : 1;
        if ($paged < 1) $paged = 1;
        
        // Set number of posts per page
        $posts_per_page = 2;

        // Set up the query arguments
        $args = array(
            'post_type' => 'cfqa_question',
            'post_status' => 'publish',
            'posts_per_page' => $posts_per_page,
            'paged' => $paged,
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
        );

        $questions = new WP_Query($args);

        ob_start();
        
        if ($questions->have_posts()) {
            echo '<div class="cfqa-questions-container" data-post-id="' . esc_attr($post_id) . '" data-per-page="' . esc_attr($posts_per_page) . '">';
            echo '<div class="cfqa-questions-list">';
            
            while ($questions->have_posts()) {
                $questions->the_post();
                include(plugin_dir_path(dirname(__FILE__)) . 'templates/question-item.php');
            }
            
            echo '</div>'; // End questions-list

            // Add pagination if there are multiple pages
            if ($questions->max_num_pages > 1) {
                $current_page_url = get_pagenum_link(1);
                $current_page_url = remove_query_arg('paged', $current_page_url);
                
                echo '<div class="cfqa-pagination">';
                echo paginate_links(array(
                    'base' => $current_page_url . '%_%',
                    'format' => '?paged=%#%',
                    'current' => max(1, $paged),
                    'total' => $questions->max_num_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'type' => 'list',
                    'mid_size' => 2,
                    'end_size' => 1,
                    'add_args' => false
                ));
                echo '</div>';
            }

            echo '</div>'; // End questions-container
        } else {
            echo '<p class="cfqa-no-questions">' . __('No questions found.', 'code-fortress-qa') . '</p>';
        }
        
        $output = ob_get_clean();
        wp_reset_postdata();

        return $output;
    }

    public function render_answer($comment, $args, $depth) {
        $GLOBALS['comment'] = $comment;
        include(plugin_dir_path(dirname(__FILE__)) . 'templates/answer-item.php');
    }

    public function render_questions_list($post_id) {
        if (!$post_id) {
            error_log('CFQA Error: Invalid post ID in render_questions_list');
            return '';
        }

        $post_type = get_post_type($post_id);
        if (!in_array($post_type, array('sfwd-courses', 'sfwd-lessons', 'sfwd-topic'))) {
            error_log('CFQA Error: Invalid post type in render_questions_list - ' . $post_type);
            return '';
        }

        // Get current page from query var
        $paged = get_query_var('paged') ? absint(get_query_var('paged')) : 1;
        if ($paged < 1) $paged = 1;
        
        // Set number of posts per page
        $posts_per_page = 2;

        $args = array(
            'post_type' => 'cfqa_question',
            'post_status' => 'publish',
            'posts_per_page' => $posts_per_page,
            'paged' => $paged,
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
        );

        $questions = new WP_Query($args);

        ob_start();
        
        if ($questions->have_posts()) {
            echo '<div class="cfqa-questions-container" data-post-id="' . esc_attr($post_id) . '" data-per-page="' . esc_attr($posts_per_page) . '">';
            echo '<div class="cfqa-questions-list">';
            
            while ($questions->have_posts()) {
                $questions->the_post();
                include(plugin_dir_path(dirname(__FILE__)) . 'templates/question-item.php');
            }
            
            echo '</div>'; // End questions-list

            // Add pagination if there are multiple pages
            if ($questions->max_num_pages > 1) {
                $current_page_url = get_pagenum_link(1);
                $current_page_url = remove_query_arg('paged', $current_page_url);
                
                echo '<div class="cfqa-pagination">';
                echo paginate_links(array(
                    'base' => $current_page_url . '%_%',
                    'format' => '?paged=%#%',
                    'current' => max(1, $paged),
                    'total' => $questions->max_num_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'type' => 'list',
                    'mid_size' => 2,
                    'end_size' => 1,
                    'add_args' => false
                ));
                echo '</div>';
            }

            echo '</div>'; // End questions-container
        } else {
            echo '<p class="cfqa-no-questions">' . __('No questions found.', 'code-fortress-qa') . '</p>';
        }
        
        $output = ob_get_clean();
        wp_reset_postdata();

        return $output;
    }
}

// Initialize the class
CFQA_Frontend::get_instance(); 