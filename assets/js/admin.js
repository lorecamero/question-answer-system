jQuery(document).ready(function($) {
    'use strict';

    // Status change handling
    $('#cfqa_status').on('change', function() {
        const $select = $(this);
        const $postbox = $select.closest('.postbox');
        
        // Add visual feedback
        $postbox.css('opacity', '0.6');
        
        const data = {
            action: 'cfqa_update_status',
            post_id: $('#post_ID').val(),
            status: $select.val(),
            nonce: cfqaAdmin.nonce
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                // Show success notice
                const $notice = $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>')
                    .hide()
                    .insertAfter($postbox);
                
                $notice.slideDown();
                
                // Remove notice after delay
                setTimeout(function() {
                    $notice.slideUp(function() {
                        $(this).remove();
                    });
                }, 3000);
            } else {
                // Show error notice
                const $notice = $('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>')
                    .hide()
                    .insertAfter($postbox);
                
                $notice.slideDown();
            }
        }).always(function() {
            $postbox.css('opacity', '1');
        });
    });

    // Dynamic filters for related content
    $('#cfqa_related_course').on('change', function() {
        const courseId = $(this).val();
        const $lessonSelect = $('#cfqa_related_lesson');
        const $topicSelect = $('#cfqa_related_topic');
        
        if (!courseId) {
            // Reset lesson and topic dropdowns
            $lessonSelect.html('<option value="">' + cfqaAdmin.strings.select_lesson + '</option>').prop('disabled', true);
            $topicSelect.html('<option value="">' + cfqaAdmin.strings.select_topic + '</option>').prop('disabled', true);
            return;
        }

        // Show loading state
        $lessonSelect.prop('disabled', true).html('<option value="">' + cfqaAdmin.strings.loading + '</option>');
        
        // Fetch related lessons
        $.post(ajaxurl, {
            action: 'cfqa_get_course_content',
            course_id: courseId,
            content_type: 'lesson',
            nonce: cfqaAdmin.nonce
        }, function(response) {
            if (response.success) {
                let options = '<option value="">' + cfqaAdmin.strings.select_lesson + '</option>';
                
                $.each(response.data.items, function(id, title) {
                    options += '<option value="' + id + '">' + title + '</option>';
                });
                
                $lessonSelect.html(options).prop('disabled', false);
            }
        });
    });

    $('#cfqa_related_lesson').on('change', function() {
        const lessonId = $(this).val();
        const $topicSelect = $('#cfqa_related_topic');
        
        if (!lessonId) {
            // Reset topic dropdown
            $topicSelect.html('<option value="">' + cfqaAdmin.strings.select_topic + '</option>').prop('disabled', true);
            return;
        }

        // Show loading state
        $topicSelect.prop('disabled', true).html('<option value="">' + cfqaAdmin.strings.loading + '</option>');
        
        // Fetch related topics
        $.post(ajaxurl, {
            action: 'cfqa_get_course_content',
            lesson_id: lessonId,
            content_type: 'topic',
            nonce: cfqaAdmin.nonce
        }, function(response) {
            if (response.success) {
                let options = '<option value="">' + cfqaAdmin.strings.select_topic + '</option>';
                
                $.each(response.data.items, function(id, title) {
                    options += '<option value="' + id + '">' + title + '</option>';
                });
                
                $topicSelect.html(options).prop('disabled', false);
            }
        });
    });

    // Bulk actions enhancement
    $('.bulkactions select').on('change', function() {
        const action = $(this).val();
        
        if (action.startsWith('cfqa_')) {
            // Add confirmation for certain actions
            $(this).closest('form').on('submit', function(e) {
                if (!confirm(cfqaAdmin.strings.bulk_action_confirm)) {
                    e.preventDefault();
                }
            });
        }
    });

    // Quick edit enhancements
    if (typeof inlineEditPost !== 'undefined') {
        const wpInlineEdit = inlineEditPost.edit;
        
        inlineEditPost.edit = function(id) {
            wpInlineEdit.apply(this, arguments);
            
            if (typeof(id) === 'object') {
                id = parseInt(this.getId(id));
            }
            
            if (id > 0) {
                const $row = $('#edit-' + id);
                const $status = $('#post-' + id).find('.column-status').text();
                
                // Set the status in quick edit
                $row.find('select[name="_status"]').val($status);
            }
        };
    }

    // Answer management
    $('.cfqa-answer-actions').on('click', '.cfqa-toggle-answer', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const $answer = $button.closest('.cfqa-answer');
        const answerId = $answer.data('answer-id');
        
        $button.prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'cfqa_toggle_answer',
            answer_id: answerId,
            nonce: cfqaAdmin.nonce
        }, function(response) {
            if (response.success) {
                $answer.toggleClass('cfqa-answer-hidden');
                $button.text(response.data.button_text);
            }
        }).always(function() {
            $button.prop('disabled', false);
        });
    });

    // Dashboard widgets
    if ($('#cfqa-stats-widget').length) {
        $.post(ajaxurl, {
            action: 'cfqa_get_stats',
            nonce: cfqaAdmin.nonce
        }, function(response) {
            if (response.success) {
                const stats = response.data;
                const ctx = document.getElementById('cfqa-stats-chart').getContext('2d');
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: stats.labels,
                        datasets: [{
                            label: cfqaAdmin.strings.questions_label,
                            data: stats.questions,
                            borderColor: '#0073aa',
                            fill: false
                        }, {
                            label: cfqaAdmin.strings.answers_label,
                            data: stats.answers,
                            borderColor: '#46b450',
                            fill: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
        });
    }

    function handleQuestionStatus(action, questionId) {
        return $.ajax({
            url: cfqaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: action,
                question_id: questionId,
                nonce: cfqaAdmin.nonce
            }
        });
    }

    function updateQuestionRow($button, newStatus) {
        const $row = $button.closest('tr');
        const $statusCell = $row.find('td.column-status');
        const isApproved = newStatus === 'approved';
        
        // Update status label
        $statusCell.find('.cfqa-status')
            .removeClass('cfqa-status-approved cfqa-status-pending')
            .addClass('cfqa-status-' + newStatus)
            .text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
        
        // Update action button
        const newButton = isApproved ? 
            '<a href="#" class="cfqa-disapprove-btn button button-small" data-question-id="' + $button.data('question-id') + '"><span class="dashicons dashicons-no"></span> ' + 'Disapprove' + '</a>' :
            '<a href="#" class="cfqa-approve-btn button button-small" data-question-id="' + $button.data('question-id') + '"><span class="dashicons dashicons-yes"></span> ' + 'Approve' + '</a>';
        
        $button.replaceWith(newButton);
        
        // Update row actions
        const $rowActions = $row.find('.row-actions');
        const actionLink = isApproved ?
            '<span class="disapprove"><a href="#" class="cfqa-disapprove-btn" data-question-id="' + $button.data('question-id') + '">Disapprove</a></span>' :
            '<span class="approve"><a href="#" class="cfqa-approve-btn" data-question-id="' + $button.data('question-id') + '">Approve</a></span>';
        
        $rowActions.find('.approve, .disapprove').remove();
        $rowActions.append(' | ' + actionLink);
    }

    // Helper function to show admin notices
    function showNotice(message, type = 'success') {
        const notice = $('<div>')
            .addClass('notice notice-' + type + ' is-dismissible')
            .html('<p>' + message + '</p>')
            .hide()
            .insertAfter('.wp-header-end');

        notice.slideDown();

        // Add dismiss button
        const dismissButton = $('<button>')
            .attr('type', 'button')
            .addClass('notice-dismiss')
            .appendTo(notice);

        dismissButton.on('click', function() {
            notice.slideUp(function() {
                notice.remove();
            });
        });

        // Auto dismiss after 5 seconds
        setTimeout(function() {
            notice.slideUp(function() {
                notice.remove();
            });
        }, 5000);
    }

    // Handle approve button click
    $('.cfqa-approve-question').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $row = $button.closest('tr');
        const questionId = $button.data('question-id');

        // Don't proceed if already processing
        if ($button.hasClass('processing')) {
            return;
        }

        // Confirm action
        if (!confirm(cfqaAdmin.i18n.confirmApprove)) {
            return;
        }

        $button.addClass('processing');
        $button.prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cfqa_approve_question',
                question_id: questionId,
                nonce: cfqaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message);
                    // Always reload after successful approval
                    location.reload();
                } else {
                    showNotice(response.data.message, 'error');
                    $button.removeClass('processing');
                    $button.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
                showNotice(cfqaAdmin.i18n.approveError, 'error');
                $button.removeClass('processing');
                $button.prop('disabled', false);
            }
        });
    });

    // Handle disapprove button click
    $(document).on('click', '.cfqa-disapprove-btn', function(e) {
        e.preventDefault();
        const $button = $(this);
        const questionId = $button.data('question-id');

        if (!confirm(cfqaAdmin.strings.disapprove_confirm)) {
            return;
        }

        $button.addClass('updating-message');

        handleQuestionStatus('cfqa_disapprove_question', questionId)
            .done(function(response) {
                if (response.success) {
                    updateQuestionRow($button, 'pending');
                } else {
                    alert(response.data.message || cfqaAdmin.strings.error);
                }
            })
            .fail(function() {
                alert(cfqaAdmin.strings.error);
            })
            .always(function() {
                $button.removeClass('updating-message');
            });
    });

    // Answer Question Modal functionality
    $(document).ready(function() {
        // Initialize modal
        const $modal = $('#cfqa-answer-modal');
        const $modalClose = $('#cfqa-answer-modal .cfqa-modal-close');
        const $questionPreview = $('#cfqa-answer-modal .cfqa-question-preview');
        const $answerForm = $('#cfqa-answer-form');
        const $spinner = $answerForm.find('.spinner');

        console.log('Modal initialization:', {
            modalExists: $modal.length > 0,
            modalDisplay: $modal.css('display'),
            modalZIndex: $modal.css('z-index'),
            modalVisibility: $modal.css('visibility')
        });

        // Open modal when clicking answer button
        $(document).on('click', '.cfqa-answer-question', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const $button = $(this);
            const questionId = $button.data('question-id');
            
            console.log('Answer button clicked:', {
                button: $button[0],
                questionId: questionId,
                modalExists: $modal.length > 0
            });

            if (!questionId) {
                console.error('No question ID found');
                return;
            }

            // Show loading state
            $button.prop('disabled', true).addClass('processing');
            
            // Clear previous content and reset form
            $questionPreview.empty();
            $answerForm[0].reset();
            
            // Load question details via AJAX
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'cfqa_get_question_details',
                    question_id: questionId,
                    nonce: cfqaAdmin.nonce
                },
                beforeSend: function() {
                    console.log('Sending AJAX request for question details:', {
                        questionId: questionId,
                        nonce: cfqaAdmin.nonce
                    });
                },
                success: function(response) {
                    console.log('Question details response:', response);
                    if (response.success) {
                        // Update modal content
                        $questionPreview.html(`
                            <div class="cfqa-preview-title">${response.data.title}</div>
                            <div class="cfqa-preview-content">${response.data.content}</div>
                            ${response.data.meta ? `<div class="cfqa-preview-meta">${response.data.meta}</div>` : ''}
                        `);
                        
                        // Set question ID in form
                        $('#cfqa-answer-question-id').val(questionId);
                        
                        // Show modal
                        $modal.css({
                            display: 'block',
                            opacity: 0
                        }).animate({
                            opacity: 1
                        }, 300);

                        console.log('Modal shown:', {
                            display: $modal.css('display'),
                            opacity: $modal.css('opacity'),
                            visibility: $modal.css('visibility'),
                            zIndex: $modal.css('z-index')
                        });
                    } else {
                        console.error('Error in response:', response.data.message);
                        alert(response.data.message || 'Error loading question details');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    alert('Error loading question details. Please try again.');
                },
                complete: function() {
                    $button.prop('disabled', false).removeClass('processing');
                }
            });
        });

        // Close modal when clicking X
        $modalClose.on('click', function(e) {
            e.preventDefault();
            console.log('Close button clicked');
            $modal.fadeOut(300);
            $answerForm[0].reset();
        });

        // Close modal when clicking outside
        $(document).on('click', '#cfqa-answer-modal', function(e) {
            if ($(e.target).is($modal)) {
                console.log('Clicked outside modal content');
                $modal.fadeOut(300);
                $answerForm[0].reset();
            }
        });

        // Prevent modal from closing when clicking inside
        $('#cfqa-answer-modal .cfqa-modal-content').on('click', function(e) {
            e.stopPropagation();
        });

        // Handle form submission
        $answerForm.on('submit', function(e) {
            e.preventDefault();
            const $submitButton = $(this).find('button[type="submit"]');
            $submitButton.prop('disabled', true);
            $spinner.addClass('is-active');

            const formData = new FormData(this);
            formData.append('action', 'cfqa_submit_answer');

            console.log('Submitting answer:', {
                questionId: formData.get('question_id'),
                content: formData.get('content'),
                nonce: formData.get('nonce')
            });

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    console.log('Submit answer response:', response);
                    if (response.success) {
                        // Reset form immediately
                        $answerForm[0].reset();
                        
                        // Fade out the modal
                        $modal.fadeOut(300, function() {
                            // After modal is hidden, show success popup
                            const $popup = $('#cfqa-success-popup');
                            $popup.css('display', 'flex').addClass('active');
                            
                            // Hide popup and reload after delay
                            setTimeout(function() {
                                $popup.removeClass('active');
                                // Wait for popup fade out before reload
                                setTimeout(function() {
                                    location.reload();
                                }, 300);
                            }, 1500);
                        });
                    } else {
                        alert(response.data.message || cfqaAdmin.i18n.answerError);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Submit answer error:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    alert(cfqaAdmin.i18n.answerError);
                },
                complete: function() {
                    $submitButton.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });
    });
}); 