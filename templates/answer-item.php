<?php
/**
 * Template for displaying a single answer
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Ensure $comment is available
if (!isset($comment) && isset($GLOBALS['comment'])) {
    $comment = $GLOBALS['comment'];
}

if (!$comment) {
    return;
}

$comment_id = $comment->comment_ID;
$author_id = $comment->user_id;
$author_name = $comment->comment_author;
$date = get_comment_date('F j, Y', $comment_id);
$time = get_comment_date('g:i a', $comment_id);
$content = apply_filters('comment_text', $comment->comment_content, $comment);
$avatar = get_avatar($author_id, 50);
?>

<div class="cfqa-answer-item" id="answer-<?php echo esc_attr($comment_id); ?>" data-answer-id="<?php echo esc_attr($comment_id); ?>">
    <div class="cfqa-answer-meta">
        <div class="cfqa-answer-author">
            <?php echo $avatar; ?>
            <div class="cfqa-author-info">
                <span class="cfqa-author-name"><?php echo esc_html($author_name); ?></span>
                <span class="cfqa-answer-date">
                    <?php echo esc_html($date); ?> at <?php echo esc_html($time); ?>
                </span>
            </div>
        </div>
    </div>
    <div class="cfqa-answer-content">
        <?php echo $content; ?>
    </div>
</div> 