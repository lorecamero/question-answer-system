<?php
/**
 * Template for displaying a single question item
 */

if (!defined('ABSPATH')) {
    exit;
}

$question_id = get_the_ID();
$author_id = get_post_field('post_author', $question_id);
$answer_count = count(get_posts(array(
    'post_type' => 'cfqa_answer',
    'post_parent' => $question_id,
    'posts_per_page' => -1,
    'fields' => 'ids'
)));
?>

<div class="cfqa-question-item">
    <div class="cfqa-question-header">
        <div class="cfqa-title-with-avatar">
            <div class="cfqa-author-avatar">
                <?php echo get_avatar($author_id, 32); ?>
            </div>
            <h3 class="cfqa-question-title">
                <?php the_title(); ?>
            </h3>
        </div>
        <?php if ($answer_count > 0): ?>
            <span class="cfqa-answer-badge">
                <i class="dashicons dashicons-format-chat"></i>
                <?php echo sprintf(_n('%d Answer', '%d Answers', $answer_count, 'code-fortress-qa'), $answer_count); ?>
            </span>
        <?php endif; ?>
        <span class="cfqa-submission-date"><?php echo get_the_date('F d, Y'); ?></span>
    </div>
    
    <div class="cfqa-question-content">
        <?php the_content(); ?>
    </div>

    <div class="cfqa-question-meta">
        <div class="cfqa-question-author">
            <div class="cfqa-author-info">
                <span class="cfqa-author-name">
                    <?php echo get_the_author(); ?>
                </span>
                <span class="cfqa-post-date">
                    <?php echo sprintf(__('Asked %s', 'code-fortress-qa'), get_the_date()); ?>
                </span>
            </div>
        </div>
    </div>

    <?php
    // Display answers if they exist
    if ($answer_count > 0):
        $answers = get_posts(array(
            'post_type' => 'cfqa_answer',
            'post_parent' => $question_id,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC'
        ));
    ?>
        <div class="cfqa-answers-section">
            <div class="cfqa-answers-list" 
                 data-question-id="<?php echo esc_attr(get_the_ID()); ?>"
                 data-last-answer-id="<?php 
                    $last_answer = get_comments(array(
                        'post_id' => get_the_ID(),
                        'type' => 'answer',
                        'status' => 'approve',
                        'number' => 1,
                        'orderby' => 'comment_ID',
                        'order' => 'DESC'
                    ));
                    echo esc_attr(!empty($last_answer) ? $last_answer[0]->comment_ID : '0');
                 ?>">
                <?php foreach ($answers as $answer): 
                    $answer_author_id = $answer->post_author;
                    $is_instructor = get_post_meta($answer->ID, '_cfqa_answer_type', true) === 'instructor';
                ?>
                    <div class="cfqa-answer-item <?php echo $is_instructor ? 'cfqa-instructor-answer' : ''; ?>">
                        <div class="cfqa-answer-content">
                            <?php echo apply_filters('the_content', $answer->post_content); ?>
                        </div>
                        <div class="cfqa-answer-meta">
                            <div class="cfqa-answer-author">
                                <div class="cfqa-author-avatar">
                                    <?php echo get_avatar($answer_author_id, 28); ?>
                                </div>
                                <div class="cfqa-author-info">
                                    <span class="cfqa-author-name">
                                        <?php 
                                        echo get_the_author_meta('user_login', $answer_author_id);
                                        if ($is_instructor) {
                                            echo ' <span class="cfqa-instructor-badge">Instructor</span>';
                                        }
                                        ?>
                                    </span>
                                    <span class="cfqa-post-date">
                                        <?php echo sprintf(__('Answered %s', 'code-fortress-qa'), get_the_date('', $answer->ID)); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div> 