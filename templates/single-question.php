<?php
/**
 * Template for displaying a single question
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Get the question data
$question_id = get_the_ID();
$question_title = get_the_title();
$question_content = get_the_content();
$question_date = get_the_date();
$question_author_id = get_post_field('post_author', $question_id);
$question_author = get_the_author_meta('display_name', $question_author_id);

// Get answers (comments)
$answers = get_comments(array(
    'post_id' => $question_id,
    'comment_type' => 'answer',
    'status' => 'approve',
    'orderby' => 'comment_date',
    'order' => 'ASC'
));

// Get the last answer ID for AJAX polling
$last_answer_id = 0;
if (!empty($answers)) {
    $last_answer = end($answers);
    $last_answer_id = $last_answer->comment_ID;
    reset($answers); // Reset the array pointer
}

// Get the source page (course, lesson, or topic)
$source_page_id = get_post_meta($question_id, '_cfqa_source_page_id', true);
$source_page_title = $source_page_id ? get_the_title($source_page_id) : '';
$source_page_url = $source_page_id ? get_permalink($source_page_id) : '';
?>

<div class="cfqa-single-question-container">
    <div class="cfqa-breadcrumbs">
        <a href="<?php echo esc_url(home_url()); ?>"><?php _e('Home', 'code-fortress-qa'); ?></a> &raquo;
        <?php if ($source_page_url && $source_page_title): ?>
            <a href="<?php echo esc_url($source_page_url); ?>"><?php echo esc_html($source_page_title); ?></a> &raquo;
        <?php endif; ?>
        <span><?php echo esc_html($question_title); ?></span>
    </div>

    <div class="cfqa-question-header">
        <h1><?php echo esc_html($question_title); ?></h1>
        <div class="cfqa-question-meta">
            <span class="cfqa-question-author"><?php echo esc_html($question_author); ?></span>
            <span class="cfqa-question-date"><?php echo esc_html($question_date); ?></span>
        </div>
    </div>

    <div class="cfqa-question-content">
        <?php echo apply_filters('the_content', $question_content); ?>
    </div>

    <div class="cfqa-answers-section">
        <div class="cfqa-answers-header">
            <h4><?php echo count($answers) . ' ' . _n('Answer', 'Answers', count($answers), 'code-fortress-qa'); ?></h4>
        </div>

        <div class="cfqa-answers-list" data-question-id="<?php echo esc_attr($question_id); ?>" data-last-answer-id="<?php echo esc_attr($last_answer_id); ?>">
            <?php
            if (!empty($answers)) {
                foreach ($answers as $comment) {
                    $GLOBALS['comment'] = $comment;
                    include(plugin_dir_path(dirname(__FILE__)) . 'templates/answer-item.php');
                }
            } else {
                echo '<div class="cfqa-no-answers">' . __('No answers yet. Be the first to answer!', 'code-fortress-qa') . '</div>';
            }
            ?>
        </div>

        <?php if (is_user_logged_in()): ?>
            <div class="cfqa-answer-form-container">
                <h4><?php _e('Your Answer', 'code-fortress-qa'); ?></h4>
                <form id="cfqa-submit-answer" class="cfqa-answer-form">
                    <?php wp_nonce_field('cfqa-frontend-nonce', 'answer_nonce'); ?>
                    <input type="hidden" name="action" value="cfqa_submit_answer">
                    <input type="hidden" name="question_id" value="<?php echo esc_attr($question_id); ?>">
                    
                    <div class="cfqa-form-group">
                        <label for="answer_content"><?php _e('Answer', 'code-fortress-qa'); ?></label>
                        <textarea id="answer_content" name="answer_content" rows="5" required></textarea>
                    </div>
                    
                    <div class="cfqa-form-submit">
                        <button type="submit" class="cfqa-submit-btn"><?php _e('Submit Answer', 'code-fortress-qa'); ?></button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="cfqa-login-prompt">
                <p><?php _e('Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to submit an answer.', 'code-fortress-qa'); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?> 