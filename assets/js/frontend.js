// Define the cfqaFrontend object
var cfqaFrontend = {
    ajaxurl: (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php',
    nonce: document.querySelector('input[name="answer_nonce"]') ? document.querySelector('input[name="answer_nonce"]').value : '',
    currentPageId: 0,
    loadMoreAction: 'cfqa_load_more_questions',
    strings: {
        submit_success: 'Your question has been submitted successfully.',
        submit_error: 'There was an error submitting your question.'
    },
    i18n: {
        loadMoreError: 'Error loading more questions.',
        networkError: 'Network error occurred.'
    }
};

// Use cfqaAjax if it exists, otherwise use cfqaFrontend
var cfqaAjax = (typeof cfqaAjax !== 'undefined') ? cfqaAjax : cfqaFrontend;

jQuery(document).ready(function($) {
    'use strict';

    // Store the original page URL when the page loads
    const originalPageUrl = window.location.href.split('?')[0];

    // Initialize heartbeat for questions container
    function initializeHeartbeat() {
        const $container = $('.cfqa-questions-container');
        if ($container.length) {
            // Set initial last check time
            $container.data('last-check', Math.floor(Date.now() / 1000));
            
            // Check if WordPress Heartbeat API is available
            if (typeof wp !== 'undefined' && wp.heartbeat) {
                try {
                    // Configure heartbeat
                    wp.heartbeat.interval('fast'); // Set to 'fast' for 5 second intervals
                    
                    console.log('Heartbeat initialized:', {
                        container: 'found',
                        initialTimestamp: $container.data('last-check'),
                        heartbeatInterval: wp.heartbeat.interval()
                    });
                } catch (error) {
                    console.error('Error configuring heartbeat:', error);
                }
            } else {
                console.warn('WordPress Heartbeat API not available - falling back to periodic refresh');
                // Fallback: Check for updates every 30 seconds
                setInterval(function() {
                    checkForNewApprovals($container);
                }, 30000);
            }
        }
    }

    // Function to check for new approvals
    function checkForNewApprovals($container) {
        const lastCheck = $container.data('last-check') || Math.floor(Date.now() / 1000);
        
        $.ajax({
            url: cfqaAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'cfqa_check_approvals',
                nonce: cfqaAjax.nonce,
                post_id: $container.data('post-id'),
                last_check: lastCheck
            },
            success: function(response) {
                if (response.success && response.data.new_approvals) {
                    reloadQuestionsList($container);
                    $container.data('last-check', response.data.current_time);
                }
            }
        });
    }

    // Function to reload questions list with Elementor compatibility
    function reloadQuestionsList($container) {
        console.log('Reloading questions list');
        
        // Check if container exists and is visible
        if (!$container.length || !$container.is(':visible')) {
            console.log('Questions container not found or not visible');
            return;
        }

        // Store current scroll position
        const scrollPos = $(window).scrollTop();

        $.ajax({
            url: cfqaAjax.ajaxurl,
            type: 'POST',
            data: {
                action: cfqaAjax.loadMoreAction,
                nonce: cfqaAjax.nonce,
                page: 1,
                post_id: $container.data('post-id'),
                per_page: $container.data('per-page') || 10
            },
            success: function(response) {
                console.log('Questions reload response:', response);
                if (response.success && response.data.html) {
                    const $questionsList = $container.find('.cfqa-questions-list');
                    
                    // Use requestAnimationFrame for smoother DOM updates
                    requestAnimationFrame(function() {
                        // Create a temporary container to parse the HTML
                        const $temp = $('<div>').html(response.data.html);
                        
                        // Check if the content is different before updating
                        if ($questionsList.html() !== $temp.html()) {
                            $questionsList.fadeOut(300, function() {
                                // Update content
                                $questionsList.html($temp.html());
                                
                                // Reinitialize Elementor frontend if available
                                if (window.elementorFrontend && window.elementorFrontend.init) {
                                    try {
                                        window.elementorFrontend.init();
                                    } catch (e) {
                                        console.warn('Elementor frontend reinit warning:', e);
                                    }
                                }
                                
                                // Show updated content
                                $questionsList.fadeIn(300, function() {
                                    // Restore scroll position if needed
                                    if (Math.abs($(window).scrollTop() - scrollPos) > 100) {
                                        $(window).scrollTop(scrollPos);
                                    }
                                });
                                
                                console.log('Questions list updated successfully');
                            });
                        } else {
                            console.log('Content unchanged, skipping update');
                        }
                    });
                }
            },
            error: function(xhr, status, error) {
                console.error('Error reloading questions:', {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
            }
        });
    }

    // Call initialization on page load
    initializeHeartbeat();

    // Helper function to manage loading states
    function setLoading(element, isLoading) {
        if (isLoading) {
            $(element).addClass('cfqa-loading');
        } else {
            $(element).removeClass('cfqa-loading');
        }
    }

    // Helper function to show notices
    function showNotice(message, type = 'success') {
        const notice = $('<div>')
            .addClass('cfqa-notice notice notice-' + type)
            .html('<p>' + message + '</p>')
            .hide()
            .prependTo('.cfqa-answers-section')
            .slideDown();

        setTimeout(function() {
            notice.slideUp(function() {
                notice.remove();
            });
        }, 3000);
    }

    // Question submission
    $('#cfqa-ask-question').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $submitArea = $form.find('.cfqa-form-submit');

        // Don't submit if already loading
        if ($submitArea.hasClass('cfqa-loading')) {
            return;
        }

        // Get form data
        const formData = new FormData(this);
        formData.append('post_id', cfqaAjax.currentPageId);

        setLoading($submitArea, true);

        $.ajax({
            url: cfqaAjax.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false, // Important for FormData
            contentType: false, // Important for FormData
            success: function(response) {
                if (response.success) {
                    showSuccessModal(response.data.message || cfqaAjax.strings.submit_success);
                    $form[0].reset();
                    // Reload the questions list without refreshing the page
                    reloadQuestionsList($('.cfqa-questions-container'));
                } else {
                    showNotice(response.data.message || cfqaAjax.strings.submit_error, 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
                showNotice(cfqaAjax.strings.submit_error, 'error');
            },
            complete: function() {
                setLoading($submitArea, false);
            }
        });
    });

    // Handle browser back/forward buttons
    $(window).on('popstate', function(event) {
        const $questionsContainer = $('.cfqa-questions-container');
        if (!$questionsContainer.length) return;
        
        // Get the current URL
        const currentUrl = window.location.href;
        
        // Check if we're on admin-ajax.php
        if (currentUrl.includes('admin-ajax.php')) {
            let newUrl;
            if (event.state && event.state.page && event.state.page > 1) {
                newUrl = originalPageUrl + (originalPageUrl.includes('?') ? '&' : '?') + 'paged=' + event.state.page;
            } else {
                newUrl = originalPageUrl;
            }
            // Redirect to the proper URL
            window.history.replaceState(event.state, '', newUrl);
        }
        
        // Get the page from the URL or from event state
        let page = 1;
        
        if (event.originalEvent && event.originalEvent.state && event.originalEvent.state.page) {
            page = event.originalEvent.state.page;
        } else {
            const url = new URL(window.location.href);
            page = url.searchParams.get('paged') || 1;
        }
        
        const per_page = $questionsContainer.data('per-page') || 2;
        loadPaginationContent(page, per_page, $questionsContainer);
    });
    
    // Helper function to load pagination content without affecting URL
    function loadPaginationContent(page, per_page, $container) {
        setLoading($container, true);
        
        // Use standard AJAX but prevent it from altering history
        $.ajax({
            method: 'POST',
            url: cfqaAjax.ajaxurl,
            data: {
                action: cfqaAjax.loadMoreAction,
                nonce: cfqaAjax.nonce,
                page: page,
                post_id: $container.data('post-id'),
                per_page: per_page
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $container.find('.cfqa-questions-list').html(response.data.html);
                    
                    if (response.data.pagination) {
                        $container.find('.cfqa-pagination').replaceWith(response.data.pagination);
                        
                        // Rebind click event to pagination links
                        $container.find('.cfqa-pagination a.page-numbers').off('click').on('click', function(e) {
                            e.preventDefault();
                            
                            const href = $(this).attr('href');
                            const pageMatch = href.match(/[?&]paged=(\d+)/);
                            const clickedPage = pageMatch ? parseInt(pageMatch[1]) : 1;
                            
                            // Update URL in browser
                            if (history.pushState) {
                                history.pushState({page: clickedPage}, '', href);
                            }
                            
                            // Scroll to top of container
                            $('html, body').animate({
                                scrollTop: $container.offset().top - 50
                            }, 300);
                            
                            // Load the content without affecting URL
                            loadPaginationContent(clickedPage, per_page, $container);
                        });
                    }
                } else {
                    showNotice(cfqaAjax.i18n.loadMoreError, 'error');
                }
            },
            error: function() {
                showNotice(cfqaAjax.i18n.networkError, 'error');
            },
            complete: function() {
                setLoading($container, false);
            }
        });
    }
    
    // Handle pagination clicks for AJAX page loading
    $(document).off('click', '.cfqa-pagination a.page-numbers').on('click', '.cfqa-pagination a.page-numbers', function(e) {
        e.preventDefault();
        
        const $link = $(this);
        const $questionsContainer = $('.cfqa-questions-container');
        const href = $link.attr('href');
        const pageMatch = href.match(/[?&]paged=(\d+)/);
        const page = pageMatch ? parseInt(pageMatch[1]) : 1;
        const per_page = $questionsContainer.data('per-page') || 2;
        
        // Update pagination links to show current selection
        $('.cfqa-pagination .current').removeClass('current');
        $link.addClass('current');
        
        // Scroll to the top of the questions container
        $('html, body').animate({
            scrollTop: $questionsContainer.offset().top - 50
        }, 300);
        
        // Update browser URL - handle page 1 specially
        if (history.pushState) {
            let newUrl;
            if (page === 1) {
                // For page 1, use the original page URL without parameters
                newUrl = originalPageUrl;
            } else {
                // For other pages, use the original URL with the paged parameter
                newUrl = originalPageUrl + (originalPageUrl.includes('?') ? '&' : '?') + 'paged=' + page;
            }
            history.pushState({page: page}, '', newUrl);
        }
        
        // Load the content
        loadPaginationContent(page, per_page, $questionsContainer);
        
        return false;
    });
    
    // Form validation for question submission
    const $questionForm = $('#cfqa-ask-question');
    const $submitButton = $questionForm.find('.cfqa-submit-btn');
    
    // Function to validate the form
    function validateQuestionForm() {
        const titleValue = $questionForm.find('#question_title').val().trim();
        const contentValue = $questionForm.find('#question_content').val().trim();
        
        // Both fields must have values
        if (titleValue.length > 0 && contentValue.length > 0) {
            $submitButton.prop('disabled', false);
        } else {
            $submitButton.prop('disabled', true);
        }
    }
    
    // Initialize form validation
    if ($questionForm.length) {
        // Disable submit button initially
        $submitButton.prop('disabled', true);
        
        // Validate on input
        $questionForm.find('#question_title, #question_content').on('input', function() {
            validateQuestionForm();
        });
        
        // Validate on form submission
        $questionForm.on('submit', function(e) {
            const titleValue = $questionForm.find('#question_title').val().trim();
            const contentValue = $questionForm.find('#question_content').val().trim();
            
            // Prevent submission if fields are empty
            if (!titleValue || !contentValue) {
                e.preventDefault();
                return false;
            }
        });
    }

    // Initialize tooltips if they exist
    if ($.fn.tooltip) {
        $('[data-tooltip]').each(function() {
            const $el = $(this);
            const content = $el.data('tooltip');

            $el.tooltip({
                content: content,
                position: {
                    my: 'center bottom-10',
                    at: 'center top',
                    collision: 'flipfit'
                },
                tooltipClass: 'cfqa-tooltip',
                show: {
                    effect: 'fadeIn',
                    duration: 200
                },
                hide: {
                    effect: 'fadeOut',
                    duration: 200
                }
            });
        });
    }

    // Dynamic textarea height
    $(document).on('input', '.cfqa-form-group textarea', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    // Add heartbeat listener for question approvals
    $(document).on('heartbeat-send', function(e, data) {
        const $container = $('.cfqa-questions-container');
        if ($container.length) {
            const lastCheck = $container.data('last-check') || Math.floor(Date.now() / 1000);
            
            console.log('Heartbeat Send Event:', {
                timestamp: new Date().toISOString(),
                container: 'found',
                postId: $container.data('post-id'),
                lastCheck: lastCheck
            });

            data.cfqa_check_approvals = {
                post_id: $container.data('post-id'),
                last_check: lastCheck
            };
            console.log('Heartbeat Data Being Sent:', data);
        } else {
            console.log('Heartbeat Send: No questions container found');
        }
    });

    // Log when heartbeat connection is lost/restored
    $(document).on('heartbeat-connection-lost', function(e, data) {
        console.error('Heartbeat Connection Lost:', data);
    });

    $(document).on('heartbeat-connection-restored', function(e, data) {
        console.log('Heartbeat Connection Restored:', data);
    });

    $(document).on('heartbeat-error', function(e, data) {
        console.error('Heartbeat Error:', data);
    });

    // Modified heartbeat handler for Elementor compatibility
    $(document).on('heartbeat-tick', function(e, data) {
        console.log('Heartbeat Tick Event:', {
            timestamp: new Date().toISOString(),
            receivedData: data
        });

        if (data.cfqa_new_approvals) {
            console.log('New approvals detected, updating questions list');
            const $container = $('.cfqa-questions-container');
            
            // Check if we're in Elementor editor
            const isElementorEditor = window.elementorFrontend && window.elementorFrontend.isEditMode();
            
            if ($container.length && !isElementorEditor) {
                // Update the last check timestamp
                const newTimestamp = data.cfqa_current_time;
                console.log('Updating last check timestamp:', {
                    oldTimestamp: $container.data('last-check'),
                    newTimestamp: newTimestamp
                });
                
                $container.data('last-check', newTimestamp);
                
                // Use debounced reload to prevent multiple rapid updates
                if (window.cfqaReloadTimeout) {
                    clearTimeout(window.cfqaReloadTimeout);
                }
                
                window.cfqaReloadTimeout = setTimeout(function() {
                    reloadQuestionsList($container);
                }, 500);
            } else {
                console.log('Questions container not found or in Elementor editor mode');
            }
        } else {
            console.log('No new approvals in heartbeat response');
        }
    });

    // Function to show success modal
    function showSuccessModal(message) {
        const modalHtml = `
            <div class="cfqa-popup active">
                <div class="cfqa-popup-content">
                    <div class="cfqa-popup-icon">
                        <i class="fa-solid fa-circle-check" style="color: #4CAF50; font-size: 48px;"></i>
                    </div>
                    <div class="cfqa-popup-message">
                        <h3>${message}</h3>
                    </div>
                    <button class="cfqa-popup-close">
                        <i class="fa-solid fa-xmark" style="font-size: 1.2rem; color: var(--text-primary);"></i>
                    </button>
                    <div class="cfqa-popup-actions">
                        <button class="cfqa-popup-ok-btn">OK</button>
                    </div>
                </div>
            </div>
        `;
        $('body').append(modalHtml);

        // Close modal on X button click
        $('.cfqa-popup-close').on('click', function() {
            $('.cfqa-popup').removeClass('active');
            setTimeout(() => $('.cfqa-popup').remove(), 300); // Remove from DOM after fade out
        });
        
        // Close modal on OK button click
        $('.cfqa-popup-ok-btn').on('click', function() {
            $('.cfqa-popup').removeClass('active');
            setTimeout(() => $('.cfqa-popup').remove(), 300); // Remove from DOM after fade out
        });
        
        // Close modal when clicking outside the content
        $('.cfqa-popup').on('click', function(e) {
            if ($(e.target).hasClass('cfqa-popup')) {
                $(this).removeClass('active');
                setTimeout(() => $(this).remove(), 300);
            }
        });

        // Automatically close the modal after 8 seconds
        setTimeout(() => {
            $('.cfqa-popup').removeClass('active');
            setTimeout(() => $('.cfqa-popup').remove(), 300); // Remove from DOM after fade out
        }, 8000); // 8000 milliseconds = 8 seconds
    }

    // Handle answer submission
    $('#cfqa-submit-answer').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $submitArea = $form.find('.cfqa-form-submit');

        // Don't submit if already loading
        if ($submitArea.hasClass('cfqa-loading')) {
            return;
        }

        // Get form data
        const formData = new FormData(this);
        
        // Add the action and nonce
        formData.append('action', 'cfqa_submit_answer');
        formData.append('nonce', cfqaAjax.nonce);

        setLoading($submitArea, true);

        $.ajax({
            url: cfqaAjax.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                console.log('Answer submission response:', response);
                
                if (response.success) {
                    // Show success message
                    showNotice(response.data.message || 'Your answer has been submitted successfully.', 'success');
                    
                    // Reset the form
                    $form[0].reset();
                    
                    // Reload the page to show the new answer
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    showNotice(response.data.message || 'There was an error submitting your answer.', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error submitting answer:', {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
                showNotice('There was an error submitting your answer. Please try again.', 'error');
            },
            complete: function() {
                setLoading($submitArea, false);
            }
        });
    });

    // Initialize with Elementor compatibility
    function initializeWithElementor() {
        // Initialize heartbeat only if not in Elementor editor
        if (!window.elementorFrontend || !window.elementorFrontend.isEditMode()) {
            initializeHeartbeat();
        } else {
            console.log('In Elementor editor mode - heartbeat disabled');
        }
    }

    // Handle Elementor frontend init
    $(window).on('elementor/frontend/init', function() {
        console.log('Elementor frontend initialized');
        initializeWithElementor();
    });

    // Fallback initialization if not using Elementor
    if (!window.elementorFrontend) {
        console.log('Standard initialization (non-Elementor)');
        initializeWithElementor();
    }
}); 