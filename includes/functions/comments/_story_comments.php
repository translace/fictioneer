<?php

// =============================================================================
// BUILD STORY COMMENT
// =============================================================================

if ( ! function_exists( 'fictioneer_build_story_comment' ) ) {
  /**
   * Outputs a single comment for the story comments list
   *
   * @since Fictioneer 4.0
   *
   * @param  object $comment      The comment.
   * @param  array  $chapter_data Array of [post_id] tuples with title [0] and
   *                              permalink [1] of the associated chapter.
   * @param  int    $depth        The current depth of the comment. Default 0.
   */

  function fictioneer_build_story_comment( $comment, $chapter_data, $depth = 0 ) {
    // Setup
    $post_id = $comment->comment_post_ID;
    $type = $comment->comment_type;
    $post = get_post( $post_id );
    $parent = get_comment( $comment->comment_parent );
    $name = empty( $comment->comment_author ) ? fcntr( 'anonymous_guest' ) : $comment->comment_author;

    if ( $type == 'private' ) {
      echo '<div class="fictioneer-comment__hidden-notice">' . __( 'Comment has been marked as private.', 'fictioneer' ) . '</div>';
      return;
    }

    // Get badge (if any)
    $badge = fictioneer_get_comment_badge( get_user_by( 'id', $comment->user_id ), $comment, $post->post_author );

    // Start HTML ---> ?>
    <div class="fictioneer-comment__header">
      <?php echo get_avatar( $comment->user_id , 32 ) ?? ''; ?>
      <div class="fictioneer-comment__meta">
        <div class="fictioneer-comment__author truncate _1-1"><span><?php echo $name; ?></span> <?php echo $badge; ?></div>
        <div class="fictioneer-comment__info truncate _1-1">
          <?php
            printf(
              _x( '%1$s <span>&bull;</span> %2$s', 'Comment meta: [Date] in [Chapter]', 'fictioneer' ),
              '<span class="fictioneer-comment__date">' . date_format(
                date_create( $comment->comment_date ),
                sprintf(
                  _x( '%1$s \a\t %2$s', 'Comment time format string.', 'fictioneer' ),
                  get_option( 'fictioneer_subitem_date_format', 'M j, y' ),
                  get_option( 'time_format' )
                )
              ) . '</span>',
              '<a href="' . $chapter_data[ $post_id ][1] . '" class="fictioneer-comment__link">' . $chapter_data[ $post_id ][0] . '</a>'
            );
          ?>
        </div>
      </div>
    </div>
    <div class="fictioneer-comment__body clearfix">
      <?php if ( $parent ) : ?>
        <?php $parent_name = empty( $parent->comment_author ) ? fcntr( 'anonymous_guest' ) : $parent->comment_author;  ?>
        <div class="fictioneer-comment__replied-to">
          <?php if ( $depth > 3 ) : ?>
            <i class="fas fa-reply"></i>&nbsp;<?php
              printf(
                __( '<span>In reply to</span> <span>%s</span>', 'fictioneer' ),
                $parent_name
              );
            ?>
          <?php else : ?>
            <details>
              <summary>
                <i class="fas fa-reply"></i>&nbsp;<?php
                  printf(
                    __( '<span>In reply to</span> <span>%s</span>', 'fictioneer' ),
                    $parent_name
                  );
                ?>
              </summary>
              <div class="fictioneer-comment__parent-comment"><?php
                fictioneer_build_story_comment( $parent, $chapter_data, $depth + 1 );
              ?></div>
            </details>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php comment_text( $comment ); ?>
    </div>
    <?php // <--- End HTML
  }
}

// =============================================================================
// SEND STORY COMMENTS - AJAX
// =============================================================================

if ( ! function_exists( 'fictioneer_request_story_comments' ) ) {
  /**
   * Sends a block of comments for a given story via AJAX
   *
   * @since Fictioneer 4.0
   */

  function fictioneer_request_story_comments() {
    // Nonce
    check_ajax_referer( 'fictioneer_nonce', 'nonce' );

    // Validations
    $story_id = isset( $_GET['post_id'] ) ? fictioneer_validate_id( $_GET['post_id'], 'fcn_story' ) : false;

    // Abort if not a story
    if ( ! $story_id ) {
      wp_send_json_error( ['error' => __( 'Comments could not be loaded.', 'fictioneer' )] );
    }

    // Setup
    $page = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
    $story = fictioneer_get_story_data( $story_id );
    $chapter_ids = $story['chapter_ids']; // Only contains publicly visible chapters
    $comments_per_page = get_option( 'comments_per_page' );
    $chapter_data = [];
    $report_threshold = get_option( 'fictioneer_comment_report_threshold', 10 ) ?? 10;

    // Abort if no chapters have been found
    if ( count( $chapter_ids ) < 1 ) die();

    // Collect titles and permalinks of all chapters
    foreach ( $chapter_ids as $chapter_id ) {
      $chapter_data[ $chapter_id ] = [get_the_title( $chapter_id ), get_the_permalink( $chapter_id )];
    }

    // Get comments
    $comments = get_comments(
      array(
        'status' => 'approve',
        'post_type' => ['fcn_chapter'],
        'post__in' => $chapter_ids,
        'number' => $comments_per_page,
        'paged' => $page,
        'meta_query' => array(
          array(
            'relation' => 'OR',
            array(
              'key' => 'fictioneer_marked_offensive',
              'value' => [true, 1, '1'],
              'compare' => 'NOT IN'
            ),
            array(
              'key' => 'fictioneer_marked_offensive',
              'compare' => 'NOT EXISTS'
            )
          )
        )
      )
    );

    // Calculate how many more comments (if any) are there after this batch
    $remaining = $story['comment_count'] - $page * $comments_per_page;

    // Start buffer
    ob_start();

    // Make sure there are comments to display...
    if ( count( $comments ) > 0 ) {
      foreach ( $comments as $comment ) {
        $reports = get_comment_meta( $comment->comment_ID, 'fictioneer_user_reports', true );

        // Render empty if...
        if ( get_comment_meta( $comment->comment_ID, 'fictioneer_deleted_by_user', true ) ) {
          // Start HTML ---> ?>
          <li class="fictioneer-comment _deleted">
            <div class="fictioneer-comment__container">
              <div class="fictioneer-comment__hidden-notice"><?php _e( 'Comment has been deleted by user.', 'fictioneer' ); ?></div>
            </div>
          </li>
          <?php // <--- End HTML

          continue;
        }

        // Hidden by reports...
        if ( get_option( 'fictioneer_enable_comment_reporting' ) ) {
          if ( ! empty( $reports ) && count( $reports ) >= $report_threshold ) {
            // Start HTML ---> ?>
            <li class="fictioneer-comment _deleted">
              <div class="fictioneer-comment__container">
                <div class="fictioneer-comment__hidden-notice"><?php _e( 'Comment is hidden due to negative reports.', 'fictioneer' ); ?></div>
              </div>
            </li>
            <?php // <--- End HTML
          }
        }

        // Start HTML ---> ?>
        <li class="fictioneer-comment <?php echo $comment->comment_type; ?>">
          <div class="fictioneer-comment__container">
            <?php fictioneer_build_story_comment( $comment, $chapter_data ); ?>
          </div>
        </li>
        <?php // <--- End HTML
      }

      // Load more button and loading placeholder if there are still comments left
      if ( $remaining > 0 ) {
        $load_n = $remaining > $comments_per_page ? $comments_per_page : $remaining;

        // Start HTML ---> ?>
        <li class="load-more-list-item">
          <button onclick="fcn_loadStoryComments()" class="load-more-comments-button"><?php
            printf(
              _n(
                'Load next comment (may contain spoilers)',
                'Load next %s comments (may contain spoilers)',
                $load_n,
                'fictioneer'
              ),
              $load_n
            );
          ?></button>
        </li>
        <div class="comments-loading-placeholder hidden"><i class="fas fa-spinner spinner"></i></div>
        <?php // <--- End HTML
      }
    } else {
      // Start HTML ---> ?>
      <li class="fictioneer-comment private">
        <div class="fictioneer-comment__container">
          <div class="fictioneer-comment__hidden-notice"><?php _e( 'No public comments to display.', 'fictioneer' ); ?></div>
        </div>
      </li>
      <?php // <--- End HTML
    }

    // Get buffer
    $output = ob_get_clean();

    // Return buffer
    wp_send_json_success( ['html' => $output, 'postId' => $story_id, 'page' => $page] );
  }
}
add_action( 'wp_ajax_nopriv_fictioneer_request_story_comments', 'fictioneer_request_story_comments' );
add_action( 'wp_ajax_fictioneer_request_story_comments', 'fictioneer_request_story_comments' );

?>
