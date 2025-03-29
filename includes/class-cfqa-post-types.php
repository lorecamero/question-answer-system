<?php
/**
 * Post Types Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class CFQA_Post_Types {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Only add hooks if not in activation context
        if (!defined('CFQA_ACTIVATING') || !CFQA_ACTIVATING) {
            $this->init_hooks();
        }
    }

    private function init_hooks() {
        add_action('init', array($this, 'register_post_types'));
        add_action('init', array($this, 'register_taxonomies'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_question_meta'));
    }

    public function register_post_types($force = false) {
        // Only register if we're forcing (during activation) or if it's a normal WordPress init
        if (!$force && (!did_action('init') && !doing_action('init'))) {
            return;
        }

        // Register Question Post Type
        register_post_type('cfqa_question', array(
            'labels' => array(
                'name' => __('Questions', 'code-fortress-qa'),
                'singular_name' => __('Question', 'code-fortress-qa'),
                'add_new' => __('Add New Question', 'code-fortress-qa'),
                'add_new_item' => __('Add New Question', 'code-fortress-qa'),
                'edit_item' => __('Edit Question', 'code-fortress-qa'),
                'new_item' => __('New Question', 'code-fortress-qa'),
                'view_item' => __('View Question', 'code-fortress-qa'),
                'search_items' => __('Search Questions', 'code-fortress-qa'),
                'not_found' => __('No questions found', 'code-fortress-qa'),
                'not_found_in_trash' => __('No questions found in trash', 'code-fortress-qa'),
                'menu_name' => __('Q&A System', 'code-fortress-qa'),
            ),
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'capability_type' => 'post',
            'hierarchical' => false,
            'rewrite' => array('slug' => 'question'),
            'supports' => array('title', 'editor', 'author', 'comments'),
            'menu_icon' => 'dashicons-format-chat',
            'show_in_rest' => true,
        ));

        // Register Answer Post Type
        register_post_type('cfqa_answer', array(
            'labels' => array(
                'name' => __('Answers', 'code-fortress-qa'),
                'singular_name' => __('Answer', 'code-fortress-qa'),
                'add_new' => __('Add New Answer', 'code-fortress-qa'),
                'add_new_item' => __('Add New Answer', 'code-fortress-qa'),
                'edit_item' => __('Edit Answer', 'code-fortress-qa'),
                'new_item' => __('New Answer', 'code-fortress-qa'),
                'view_item' => __('View Answer', 'code-fortress-qa'),
                'search_items' => __('Search Answers', 'code-fortress-qa'),
                'not_found' => __('No answers found', 'code-fortress-qa'),
                'not_found_in_trash' => __('No answers found in trash', 'code-fortress-qa'),
            ),
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=cfqa_question',
            'capability_type' => 'post',
            'hierarchical' => false,
            'rewrite' => array('slug' => 'answer'),
            'supports' => array('editor', 'author', 'comments'),
            'show_in_rest' => true,
        ));
    }

    public function register_taxonomies($force = false) {
        // Only register if we're forcing (during activation) or if it's a normal WordPress init
        if (!$force && (!did_action('init') && !doing_action('init'))) {
            return;
        }

        // Register Status Taxonomy
        register_taxonomy('cfqa_status', array('cfqa_question'), array(
            'labels' => array(
                'name' => __('Status', 'code-fortress-qa'),
                'singular_name' => __('Status', 'code-fortress-qa'),
                'menu_name' => __('Status', 'code-fortress-qa'),
                'all_items' => __('All Statuses', 'code-fortress-qa'),
                'edit_item' => __('Edit Status', 'code-fortress-qa'),
                'view_item' => __('View Status', 'code-fortress-qa'),
                'update_item' => __('Update Status', 'code-fortress-qa'),
                'add_new_item' => __('Add New Status', 'code-fortress-qa'),
                'new_item_name' => __('New Status Name', 'code-fortress-qa'),
                'search_items' => __('Search Statuses', 'code-fortress-qa'),
                'not_found' => __('No statuses found', 'code-fortress-qa'),
            ),
            'hierarchical' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'question-status'),
            'show_in_rest' => true,
        ));

        // Add default statuses if they don't exist
        $default_terms = array(
            'pending' => __('Pending', 'code-fortress-qa'),
            'approved' => __('Approved', 'code-fortress-qa'),
            'rejected' => __('Rejected', 'code-fortress-qa')
        );

        foreach ($default_terms as $slug => $name) {
            if (!term_exists($slug, 'cfqa_status')) {
                $result = wp_insert_term($name, 'cfqa_status', array('slug' => $slug));
                if (is_wp_error($result)) {
                    error_log(sprintf('Failed to create term %s: %s', $slug, $result->get_error_message()));
                }
            }
        }
    }

    public function add_meta_boxes() {
        add_meta_box(
            'cfqa_question_details',
            __('Question Details', 'code-fortress-qa'),
            array($this, 'render_question_meta_box'),
            'cfqa_question',
            'normal',
            'high'
        );
    }

    public function render_question_meta_box($post) {
        wp_nonce_field('cfqa_question_meta_box', 'cfqa_question_meta_box_nonce');

        $related_course = get_post_meta($post->ID, '_cfqa_related_course', true);
        $related_lesson = get_post_meta($post->ID, '_cfqa_related_lesson', true);
        $related_topic = get_post_meta($post->ID, '_cfqa_related_topic', true);

        // Get courses the current user has access to
        $args = array(
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        );

        // Add capability check for non-admins
        if (!current_user_can('manage_options')) {
            $args['author'] = get_current_user_id();
        }

        $courses = get_posts($args);
        ?>
        <p>
            <label for="cfqa_related_course"><?php _e('Related Course:', 'code-fortress-qa'); ?></label><br>
            <select name="cfqa_related_course" id="cfqa_related_course" class="widefat">
                <option value=""><?php _e('Select Course', 'code-fortress-qa'); ?></option>
                <?php foreach ($courses as $course) : ?>
                    <option value="<?php echo esc_attr($course->ID); ?>" <?php selected($related_course, $course->ID); ?>>
                        <?php echo esc_html($course->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <?php if ($related_course) : ?>
            <p>
                <label for="cfqa_related_lesson"><?php _e('Related Lesson:', 'code-fortress-qa'); ?></label><br>
                <?php
                $lessons = learndash_get_course_lessons_list($related_course);
                ?>
                <select name="cfqa_related_lesson" id="cfqa_related_lesson" class="widefat">
                    <option value=""><?php _e('Select Lesson', 'code-fortress-qa'); ?></option>
                    <?php foreach ($lessons as $lesson) : ?>
                        <option value="<?php echo esc_attr($lesson['post']->ID); ?>" <?php selected($related_lesson, $lesson['post']->ID); ?>>
                            <?php echo esc_html($lesson['post']->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php if ($related_lesson) : ?>
                <p>
                    <label for="cfqa_related_topic"><?php _e('Related Topic:', 'code-fortress-qa'); ?></label><br>
                    <?php
                    $topics = learndash_get_topic_list($related_lesson);
                    ?>
                    <select name="cfqa_related_topic" id="cfqa_related_topic" class="widefat">
                        <option value=""><?php _e('Select Topic', 'code-fortress-qa'); ?></option>
                        <?php foreach ($topics as $topic) : ?>
                            <option value="<?php echo esc_attr($topic->ID); ?>" <?php selected($related_topic, $topic->ID); ?>>
                                <?php echo esc_html($topic->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <script>
        jQuery(document).ready(function($) {
            $('#cfqa_related_course').on('change', function() {
                // Reload the page with the new course selection
                var url = new URL(window.location.href);
                url.searchParams.set('course_id', $(this).val());
                window.location.href = url.toString();
            });

            $('#cfqa_related_lesson').on('change', function() {
                // Reload the page with the new lesson selection
                var url = new URL(window.location.href);
                url.searchParams.set('lesson_id', $(this).val());
                window.location.href = url.toString();
            });
        });
        </script>
        <?php
    }

    public function save_question_meta($post_id) {
        if (!isset($_POST['cfqa_question_meta_box_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['cfqa_question_meta_box_nonce'], 'cfqa_question_meta_box')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = array(
            '_cfqa_related_course',
            '_cfqa_related_lesson',
            '_cfqa_related_topic',
        );

        foreach ($fields as $field) {
            $key = str_replace('_cfqa_', 'cfqa_', $field);
            if (isset($_POST[$key])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$key]));
            }
        }
    }
}

// Initialize the class
CFQA_Post_Types::get_instance(); 