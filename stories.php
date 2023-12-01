<?php
/**
 * Template Name: Stories
 *
 * Shows a list of all published stories with a statistics block and hooks
 * for customization. It's paginated using the "Blog pages show at most"
 * (posts_per_page) option under Settings > Reading.
 *
 * @package WordPress
 * @subpackage Fictioneer
 * @since 3.0
 * @see fictioneer_clause_sticky_stories()
 */
?>

<?php

// Setup
$page = get_query_var( 'paged', 1 ) ?: 1; // Main query
$order = array_intersect( [sanitize_key( $_GET['order'] ?? 0 )], ['desc', 'asc'] );
$order = reset( $order ) ?: 'desc';
$orderby = array_intersect( [sanitize_key( $_GET['orderby'] ?? 0 )], fictioneer_allowed_orderby() );
$orderby = reset( $orderby ) ?: 'modified';
$ago = $_GET['ago'] ?? 0;
$ago = is_numeric( $ago ) ? absint( $ago ) : sanitize_text_field( $ago );

// Prepare query
$query_args = array (
  'fictioneer_query_name' => 'stories_list',
  'post_type' => 'fcn_story',
  'post_status' => 'publish',
  'order' => $order,
  'orderby' => $orderby,
  'paged' => $page,
  'posts_per_page' => get_option( 'posts_per_page', 8 ),
  'update_post_term_cache' => ! get_option( 'fictioneer_hide_taxonomies_on_story_cards' ),
  'meta_query' => array(
    'relation' => 'OR',
    array(
      'key' => 'fictioneer_story_hidden',
      'value' => '0'
    ),
    array(
      'key' => 'fictioneer_story_hidden',
      'compare' => 'NOT EXISTS'
    )
  )
);

// Order by latest chapter update timestamp?
if ( FICTIONEER_ORDER_STORIES_BY_LATEST_CHAPTER && $orderby === 'modified' ) {
  $query_args['orderby'] = 'meta_value modified';
  $query_args['meta_key'] = 'fictioneer_chapters_added';
}

// Append date query (if any)
$query_args = fictioneer_append_date_query( $query_args, $ago, $orderby );

// Filter query arguments
$query_args = apply_filters( 'fictioneer_filter_stories_query_args', $query_args, get_the_ID() );

// Query stories
$list_of_stories = new WP_Query( $query_args );

// Prime author cache
if ( function_exists( 'update_post_author_caches' ) ) {
  update_post_author_caches( $list_of_stories->posts );
}

?>

<?php get_header(); ?>

<main id="main" class="main singular stories">
  <div class="observer main-observer"></div>
  <?php do_action( 'fictioneer_main' ); ?>
  <div class="main__background polygon polygon--main background-texture"></div>
  <div class="main__wrapper">
    <?php do_action( 'fictioneer_main_wrapper' ); ?>

    <?php while ( have_posts() ) : the_post(); ?>

      <?php
        // Setup
        $title = trim( get_the_title() );
        $breadcrumb_name = empty( $title ) ? __( 'Stories', 'fictioneer' ) : $title;
        $this_breadcrumb = [ $breadcrumb_name, get_the_permalink() ];

        // Arguments for hooks and templates/etc.
        $hook_args = array(
          'current_page' => $page,
          'post_id' => $post->ID,
          'stories' => $list_of_stories,
          'queried_type' => 'fcn_story',
          'query_args' => $query_args,
          'order' => $order,
          'orderby' => $orderby,
          'ago' => $ago
        );
      ?>

      <article id="singular-<?php the_ID(); ?>" class="singular__article stories__article padding-top padding-left padding-right padding-bottom">

        <?php if ( ! empty( $title ) ) : ?>
          <header class="singular__header stories__header">
            <h1 class="singular__title stories__title"><?php echo $title; ?></h1>
          </header>
        <?php endif; ?>

        <?php if ( get_the_content() ) : ?>
          <section class="singular__content stories__content content-section">
            <?php the_content(); ?>
          </section>
        <?php endif; ?>

        <?php
          // Renders statistics and list of stories
          do_action( 'fictioneer_stories_after_content', $hook_args );
        ?>

      </article>

    <?php endwhile; ?>
  </div>
</main>

<?php
  // Footer arguments
  $footer_args = array(
    'post_type' => 'page',
    'post_id' => $post->ID,
    'current_page' => $page,
    'stories' => $list_of_stories,
    'breadcrumbs' => array(
      [fcntr( 'frontpage' ), get_home_url()]
    )
  );

  // Add current breadcrumb
  $footer_args['breadcrumbs'][] = $this_breadcrumb;

  // Get footer with breadcrumbs
  get_footer( null, $footer_args );
?>
