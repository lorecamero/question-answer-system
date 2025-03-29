<?php
/**
 * Template for displaying a list of questions
 * 
 * @var WP_Query $questions The questions query
 * @var int $post_id The current post ID
 */

if (!defined('ABSPATH')) exit;

// Ensure we have the required variables
if (!isset($post_id) || !isset($questions)) {
    error_log('CFQA Error: Required variables not set in questions list template');
    return;
}

// Debug output if WP_DEBUG is enabled
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('CFQA Debug: Rendering questions list template');
    error_log('CFQA Debug: Post ID: ' . $post_id);
    error_log('CFQA Debug: Post Type: ' . get_post_type($post_id));
    error_log('CFQA Debug: Found ' . $questions->post_count . ' questions');
    error_log('CFQA Debug: Total pages: ' . $questions->max_num_pages);
}

$post_type = get_post_type($post_id);
if (!in_array($post_type, array('sfwd-courses', 'sfwd-lessons', 'sfwd-topic'))) {
    error_log('CFQA Error: Invalid post type in questions list template - ' . $post_type);
    return;
}
?>

<div class="cfqa-questions-container" 
     data-post-id="<?php echo esc_attr($post_id); ?>"
     data-post-type="<?php echo esc_attr($post_type); ?>"
     data-current-page="1"
     data-per-page="10"
     data-total-pages="<?php echo esc_attr($questions->max_num_pages); ?>">
    
    <?php if ($questions->have_posts()) : ?>
        <div class="cfqa-questions-list">
            <?php 
            while ($questions->have_posts()) : 
                $questions->the_post();
                include(plugin_dir_path(dirname(__FILE__)) . 'templates/question-item.php');
            endwhile; 
            ?>
        </div>

        <?php if ($questions->max_num_pages > 1) : ?>
            <div class="cfqa-load-more-container">
                <button class="cfqa-load-more" 
                        type="button"
                        data-page="1"
                        <?php if ($questions->max_num_pages <= 1) echo 'style="display: none;"'; ?>>
                    <?php esc_html_e('Load More Questions', 'code-fortress-qa'); ?>
                </button>
                <div class="cfqa-loading-spinner" style="display: none;">
                    <?php esc_html_e('Loading...', 'code-fortress-qa'); ?>
                </div>
            </div>
        <?php endif; ?>

    <?php else : ?>
        <p class="cfqa-no-questions">
            <?php esc_html_e('No questions found.', 'code-fortress-qa'); ?>
        </p>
    <?php endif; ?>
</div> 