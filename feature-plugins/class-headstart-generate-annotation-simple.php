<?php
error_log('Headstart_Generate_Annotation_Simple: Begin load');
/**
 * Headstart_Annotation_Generator_Simple: Companion to Headstart_Annotation_Generator.
 *
 * The _Simple version has these constraints:
 *   - Cannot require WPCOM only libraries.
 *     Expects information gathered with them to be passed in as parameters instead.
 *   - Will not run switch_to_blog() or restore_current_blog().
 *
 **/

/**
 * !!! SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC !!!
 * -----------------------------------------------------------------------
 *   All changes to this file must be updated on both WPCOM and WPCOMSH.
 *   The file should be identical on the two codebases.
 *   WPCOM:   wp-content/lib/headstart/class-headstart-generate-annotation-simple.php
 *   WPCOMSH: wpcom-headstart/class-headstart-generate-annotation-simple.php
 * -----------------------------------------------------------------------
 * !!! SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC SYNC !!!
 **/


class Headstart_Annotation_Generator_Simple {
	/**
	 * Create a headstart annotation.
	 *
	 * @param string $type                 "base" or "copy", the type of annotation being generated.
	 * @param object $options              Some important headstart-specific site options (not all of them).
	 * @param array  $widgets              An array of all active widgets (see format_widget).
	 * @param array  $template_for_post_id An optional mapping of post_ids (int key) to template names (string values).
	 * @param int    $posts_required       For base annotations, how many posts to include.
	 * @param array  $excluded_post_types  An array of post types to exclude from the content array.
	 *
	 * @return array  Headstart-formatted post objects.
	 */
	public static function generate_theme_annotation( $type, $options, $widgets, $template_for_post_id, $posts_required = 1, $excluded_post_types = [] ) {
		// Create the basic annotation.
		$theme_annotation = self::generate_theme_annotation_skeleton( $type, $options );

		// Fetch additional site data for copy annotations.
		if ( 'copy' === $type ) {
			$theme_annotation['widgets'] = $widgets;
			$theme_annotation['content'] = self::get_content( $excluded_post_types, $type, $options, $template_for_post_id );
			$theme_annotation['menus']   = ! self::has_navigation( $theme_annotation['content'] ) ? self::get_menus() : [];
			$theme_annotation['images']  = self::get_images( $theme_annotation['content'] );
		} elseif ( 'base' === $type ) {
			// Add in data for `base` annotations (used by Headstart).
			$theme_annotation['widgets'] = self::generate_base_widgets();
			$theme_annotation['content'] = self::generate_base_content( $posts_required, $options, $template_for_post_id );
			$theme_annotation['menus']   = self::generate_base_menus();
		}

		// Set any placeholders.
		$theme_annotation = self::set_placeholders( $theme_annotation, $type );

		// Set annotation meta (Information about the annotation)
		$theme_annotation['meta'] = self::set_annotation_meta();
		return $theme_annotation;
	}

	/**
	 * Retrieve a site's content (pages and posts) as an array of Headstart-formatted post objects.
	 *
	 * @param array $excluded_post_types  An array of post types to exclude from the content array.
	 * @param string $type                "base" or "copy", the type of annotation being generated.
	 * @param object $options             Some important headstart-specific site options (not all of them).
	 * @param array $template_for_post_id An optional mapping of post_ids (int key) to template names (string values).
	 *
	 * @return array  Headstart-formatted post objects.
	 */
	public static function get_content( $excluded_post_types = [], $type, $options, $template_for_post_id = [] ) {
		// Get page/post content
		$content = self::get_pages_and_posts( $excluded_post_types );

		foreach ( self::get_navigation() as $nav ) {
			array_push( $content, $nav );
		}

		// Merge content with presets
		$new_posts = [];
		foreach ( $content as $post ) {
			$new_posts[ $post->ID ] = self::get_filtered_post( $post, $type, $options );

			// Merge page templates into array.
			if ( array_key_exists( $post->ID, $template_for_post_id ) ) {
				$new_posts[ $post->ID ]['page_template'] = $template_for_post_id[ $post->ID ];
			}

			// Get the featured image attachment_url if one exists
			$attachment_url = get_the_post_thumbnail_url( $post->ID, 'full' );
			if ( false !== $attachment_url ) {
				$new_posts[ $post->ID ]['attachment_url'] = $attachment_url;
			}
		}

		return $new_posts;
	}

	/**
	 * Retrieve content from site.
	 *
	 * @param array $excluded_post_types  An array of post types to exclude from the content array.
	 *
	 * @return array  Posts.
	 */
	private static function get_pages_and_posts( $excluded_post_types = [] ) {
		$post_types = [ 'page', 'post', 'jetpack-portfolio', 'jetpack-testimonial', 'wp_block', 'product' ];

		if ( ! empty( $excluded_post_types ) ) {
			$post_types = array_diff( $post_types, $excluded_post_types );
		}

		$args = [
			'nopaging' => true,
			'post_status' => 'publish',
			'post_type' => $post_types,
		];

		return get_posts( $args );
	}

	public static function has_navigation( $content ) {
		return in_array( 'wp_navigation', array_column( $content, 'post_type' ) );
	}

	private static function get_navigation() {
		$args = [
			'nopaging' => true,
			'post_status' => 'publish',
			'post_type' => [ 'wp_navigation' ],
		];

		return get_posts( $args );
	}

	public static function get_images( $annotation_content = [] ) {
		$args = [
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
		];

		$query_images = get_posts( $args );

		$images = array();
		foreach ( $query_images as $image ) {
			$images[] = wp_get_attachment_url( $image->ID );
		}

		// Filter out images that are not referenced anywhere in the annotation's post content.
		if ( ! empty( $annotation_content ) && ! apply_filters( 'headstart_get_all_images', true ) ) {
			$images = array_values(
				array_filter(
					$images,
					function ( $image ) use ( $annotation_content ) {
						$image_filename = basename( $image );
						foreach ( $annotation_content as $post_data ) {
							// Include the image if the filename of the image is in post_content.
							if ( ! empty( $post_data['post_content'] ) && false !== strpos( $post_data['post_content'], $image_filename ) ) {
								return true;
							}
							// Include the image if the filename of the image is in the attachment_url.
							if ( ! empty( $post_data['attachment_url'] ) && false !== strpos( $post_data['attachment_url'], $image_filename ) ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}

		return $images;
	}

	public static function generate_theme_annotation_skeleton( $type = 'copy', $options ) {
		$theme_annotation             = array();
		$theme_annotation['settings'] = array();
		$theme_annotation['widgets']  = array();
		$theme_annotation['content']  = array();
		$theme_annotation['menus']    = array();

		$theme_annotation['settings']['options']    = $options;
		$theme_annotation['settings']['theme_mods'] = self::get_site_theme_mods();
		$theme_annotation['settings']['headstart']  = [
			'mapped_id_options'    => [],
			'mapped_id_theme_mods' => [],
			'keep_submenu_items'   => true,
		];

		if ( 'copy' === $type ) {
			$theme_annotation['images'] = array();
		}

		// These get set on the site via a post's `hs_custom_meta`.
		unset( $theme_annotation['settings']['options']['page_on_front'] );
		unset( $theme_annotation['settings']['options']['page_for_posts'] );

		return $theme_annotation;
	}

	private static function get_site_theme_mods() {
		$mods = get_theme_mods();

		// support for custom logo
		$custom_logo_key = 'custom_logo';
		if ( isset( $mods[ $custom_logo_key ] ) && is_int( $mods[ $custom_logo_key ] ) ) {
			$logo_url = wp_get_attachment_image_src( $mods[ $custom_logo_key ], 'full' );
			if ( is_array( $logo_url ) ) {
				$mods[ $custom_logo_key ] = $logo_url[0];
			}
		}

		return $mods;
	}

	/**
	 * Modifies a post object to include Headstart-required meta.
	 *
	 * @param object $post    WordPress post object.
	 * @param string $type    "base" or "copy", the type of annotation being generated.
	 * @param object $options Some important headstart-specific site options (not all of them).
	 *
	 * @return array  Headstart-formatted post object.
	 */
	private static function get_filtered_post( $post, $type, $options ) {
		$filtered = [
			'post_type' => $post->post_type,
			'post_title' => $post->post_title,
			'post_content' => $post->post_content,
			'post_name' => $post->post_name,
			'post_excerpt' => $post->post_excerpt,
			'menu_order' => $post->menu_order,
			'post_status' => $post->post_status,
			'comment_status' => $post->comment_status,
			'ping_status' => $post->ping_status,
			'_starter_page_template' => $post->_starter_page_template,
			'hs_old_id' => $post->ID,
			'hs_post_parent' => $post->post_parent,
			'hs_taxonomies' => self::get_taxonomies_for_post( $post ),
			'hs_custom_meta' => self::get_custom_meta_for_post( $post, $type, $options ),
		];

		return apply_filters( 'hs_get_filtered_post', $filtered, $post );
	}

	private static function get_custom_meta_for_post( $post, $type, $options ) {
		// See if post already has Headstart custom meta, set to '_hs_extra' if not.
		$hs_custom_meta = get_post_meta( $post->ID, '_headstart_post', true );
		if ( '' !== $hs_custom_meta ) {
			return $hs_custom_meta;
		}

		// Set front page and blog page for copied annotation.
		if ( 'copy' === $type && $options ) {
			if ( $options['page_on_front'] && $post->ID === (int) $options['page_on_front'] ) {
				return '_hs_front_page';
			}

			if ( $options['page_for_posts'] && $post->ID === (int) $options['page_for_posts'] ) {
				return '_hs_blog_page';
			}
		}

		return '_hs_extra';
	}

	private static function get_taxonomies_for_post( $post ) {
		/*
		 * Get taxonomies, map to new taxonomies
		 * HS doesn't currently process hs_taxonomies but might for Featured Content later
		 */
		$taxonomies = array();
		if ( 'post' === $post->post_type ) {
			$taxonomies['category_name'] = wp_get_object_terms( $post->ID, 'category', array( 'fields' => 'names' ) );
			$taxonomies['post_tag_name'] = wp_get_object_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
		}
		return $taxonomies;
	}

	public static function generate_featured_content_posts( $featured_tag = 'featured' ) {
		return [
			[
				'post_title'     => 'Featured Content',
				'post_content'   => 'This is a featured content post. Click the Edit link to modify or delete it, or <a href="https://wordpress.com/post">start a new post</a>.',
				'post_excerpt'   => 'This is the excerpt for a featured post.',
				'post_status'    => 'publish',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_type'      => 'post',
				'hs_custom_meta' => '_hs_featured_content',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'tags_input'     => [ $featured_tag ],
				'attachment_url' => 'https://headstartdata.files.wordpress.com/2016/08/hwijjf7rwopgej1nb4zb_img_3773.jpg',
				'hs_old_id'      => 105,
			],
			[
				'post_title'     => 'Featured Content',
				'post_content'   => 'This is a featured content post. Click the Edit link to modify or delete it, or <a href="https://wordpress.com/post">start a new post</a>.',
				'post_excerpt'   => 'This is the excerpt for a featured post.',
				'post_status'    => 'publish',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_type'      => 'post',
				'hs_custom_meta' => '_hs_featured_content',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'tags_input'     => [ $featured_tag ],
				'attachment_url' => 'https://headstartdata.files.wordpress.com/2016/06/drink-coffee2.jpg',
				'hs_old_id'      => 106,
			],
		];
	}

	public static function generate_portfolio_projects() {
		return [
			[
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'post_content'   => sprintf( __( 'This is a <a href="%1$s">project</a>. Click the Edit link to modify or delete it, or <a href="%2$s">add a new project</a>.' ), 'https://en.support.wordpress.com/portfolios', 'https://wordpress.com/types/jetpack-portfolio' ),
				'post_excerpt'   => __( 'This is the excerpt for a project.' ),
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'jetpack-portfolio',
				'hs_custom_meta' => '_hs_portfolio',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'attachment_url' => 'https://headstartdata.files.wordpress.com/2016/10/wood-architect-table-work1.jpeg',
				'hs_old_id'      => 200,
			],
			[
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'post_title'     => sprintf( __( 'Project #%d' ), 2 ),
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'post_content'   => sprintf( __( 'This is a <a href="%1$s">project</a>. Click the Edit link to modify or delete it, or <a href="%2$s">add a new project</a>.' ), 'https://en.support.wordpress.com/portfolios', 'https://wordpress.com/types/jetpack-portfolio' ),
				'post_excerpt'   => __( 'This is the excerpt for a project.' ),
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'jetpack-portfolio',
				'hs_custom_meta' => '_hs_portfolio',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'attachment_url' => 'https://headstartdata.files.wordpress.com/2016/10/pexels-photo-28855.jpg',
				'hs_old_id'      => 201,
			],
			[
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'post_title'     => sprintf( __( 'Project #%d' ), 3 ),
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'post_content'   => sprintf( __( 'This is a <a href="%1$s">project</a>. Click the Edit link to modify or delete it, or <a href="%2$s">add a new project</a>.' ), 'https://en.support.wordpress.com/portfolios', 'https://wordpress.com/types/jetpack-portfolio' ),
				'post_excerpt'   => __( 'This is the excerpt for a project.' ),
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'jetpack-portfolio',
				'hs_custom_meta' => '_hs_portfolio',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'attachment_url' => 'https://headstartdata.files.wordpress.com/2016/10/light-bulb-current-light-glow-40889-e1472733487728.jpeg',
				'hs_old_id'      => 202,
			],
		];
	}

	public static function generate_testmonials() {
		return [
			[
				'post_title'     => __( 'A big fan of yours' ),
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'post_content'   => sprintf( __( 'This is a <a href="%1$s">testimonial</a>. Click the Edit link to modify or delete it, or <a href="%2$s">add a new testimonial</a>.' ), 'https://en.support.wordpress.com/testimonials/', 'https://wordpress.com/types/jetpack-testimonial' ),
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'jetpack-testimonial',
				'hs_custom_meta' => '_hs_testimonial',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'attachment_url' => false,
				'hs_old_id'      => 300,
			],
			[
				'post_title'     => __( 'A big fan of yours' ),
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'post_content'   => sprintf( __( 'This is a second <a href="%1$s">testimonial</a>. Click the Edit link to modify or delete it, or <a href="%2$s">add a new testimonial</a>.' ), 'https://en.support.wordpress.com/testimonials/', 'https://wordpress.com/types/jetpack-testimonial' ),
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'jetpack-testimonial',
				'hs_custom_meta' => '_hs_testimonial',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'attachment_url' => false,
				'hs_old_id'      => 301,
			],
		];
	}

	public static function generate_base_widgets() {
		return [
			[
				'id'       => 'search-2',
				'id_base'  => 'search',
				'settings' => [ 'title' => 'Search' ],
				'sidebar'  => 'sidebar-1',
				'position' => 0,

			],
			[
				'id'       => 'text-2',
				'id_base'  => 'text',
				'settings' => [
					'title' => 'Text Widget',
					'text'  => 'This is a text widget, which allows you to add text or HTML to your sidebar. You can use them to display text, links, images, HTML, or a combination of these. Edit them in the Widget section of the <a href="https://wordpress.com/customize/">Customizer</a>.',
				],
				'sidebar'  => 'sidebar-1',
				'position' => 1,

			],
		];
	}

	public static function generate_base_menus() {
		return [
			'social-media' => [
				'name' => 'Social Media',
				'items' => [
					[
						'menu-item-title' => 'Facebook',
						'menu-item-db-id' => 175,
						'menu-item-object-id' => '175',
						'menu-item-object' => 'custom',
						'menu-item-type' => 'custom',
						'menu-item-status' => 'publish',
						'menu-item-position' => 0,
						'menu-item-parent-id' => '0',
						'menu-item-description' => '',
						'menu-item-url' => 'http://www.facebook.com',
					],
					[
						'menu-item-title' => 'LinkedIn',
						'menu-item-db-id' => 176,
						'menu-item-object-id' => '176',
						'menu-item-object' => 'custom',
						'menu-item-type' => 'custom',
						'menu-item-status' => 'publish',
						'menu-item-position' => 1,
						'menu-item-parent-id' => '0',
						'menu-item-description' => '',
						'menu-item-url' => 'http://www.linkedin.com',
					],
					[
						'menu-item-title' => 'Twitter',
						'menu-item-db-id' => 177,
						'menu-item-object-id' => '177',
						'menu-item-object' => 'custom',
						'menu-item-type' => 'custom',
						'menu-item-status' => 'publish',
						'menu-item-position' => 2,
						'menu-item-parent-id' => '0',
						'menu-item-description' => '',
						'menu-item-url' => 'http://www.twitter.com',
					],
					[
						'menu-item-title' => 'Instagram',
						'menu-item-db-id' => 178,
						'menu-item-object-id' => '178',
						'menu-item-object' => 'custom',
						'menu-item-type' => 'custom',
						'menu-item-status' => 'publish',
						'menu-item-position' => 3,
						'menu-item-parent-id' => '0',
						'menu-item-description' => '',
						'menu-item-url' => 'http://www.instagram.com',
					],
				],
			],
		];
	}

	/**
	 * Find the template assigned to the homepage, if one exists.
	 *
	 * @param object $options             Some important headstart-specific site options (not all of them).
	 * @param array $template_for_post_id An optional mapping of post_ids (int key) to template names (string values).
	 *
	 * @return string|False The template that is assigned to the homepage, or false if there is none.
	 */
	private static function get_front_page_template( $options, $template_for_post_id = [] ) {
		if ( array_key_exists( $options['page_on_front'], $template_for_post_id ) ) {
			return $template_for_post_id[ $options['page_on_front'] ];
		}
		return false;
	}


	public static function generate_home_and_blog_pages( $options, $template_for_post_id ) {
		// Determine the home page's page template, if any.
		$front_page_template = self::get_front_page_template( $options, $template_for_post_id );
		if ( ! $front_page_template ) {
			$front_page_template = 'default';
		}

		return [
			[
				'post_title'     => __( 'Home' ),
				'post_content'   => __( 'Welcome to your new site!  You can edit this page by clicking on the Edit link.  For more information about customizing your site check out <a href="http://learn.wordpress.com/">http://learn.wordpress.com/</a>' ),
				'post_status'    => 'publish',
				'menu_order'     => 1,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'page',
				'hs_custom_meta' => '_hs_front_page',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'attachment_url' => 'https://headstartdata.files.wordpress.com/2016/06/skyline-buildings-new-york-skyscrapers.jpg',
				'page_template'  => $front_page_template,
				'hs_old_id'      => 103,
			],
			[
				'post_title'     => __( 'Blog' ),
				'post_content'   => __( 'This is the page where users will find your site\'s blog' ),
				'post_status'    => 'publish',
				'menu_order'     => 4,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'page',
				'hs_custom_meta' => '_hs_blog_page',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'hs_old_id'      => 104,
			],
		];
	}

	private static function generate_placeholder_post( $index = 0 ) {
		return [
			'post_title'     => 'Blog post title',
			'post_content'   => 'This is an additional placeholder post. Click the Edit link to modify or delete it, or <a href="https://wordpress.com/post">start a new post</a>.',
			'post_excerpt'   => 'This is the excerpt for a placeholder post.',
			'post_status'    => 'publish',
			'comment_status' => 'open',
			'ping_status'    => 'open',
			'post_type'      => 'post',
			'hs_custom_meta' => '_hs_generic_post',
			'hs_sharing'     => 1,
			'hs_like_status' => 1,
			'attachment_url' => false,
			'hs_old_id'      => 1000 + $index,
		];
	}

	/**
	 * Generate a generic set of posts and pages for a site.
	 * Behavior can change depending if the current theme supports 'jetpack-testimonial', 'jetpack-portfolio',
	 * or the value of the 'featured-content' and 'show_on_front' options.
	 *
	 * @param int    $posts_required       How many posts to include.
	 * @param object $options              Some important headstart-specific site options (not all of them).
	 * @param array  $template_for_post_id An optional mapping of post_ids (int key) to template names (string values).
	 *
	 * @return array Headstart-formatted post objects.
	 */
	public function generate_base_content( $posts_required = 1, $options, $template_for_post_id ) {
		$content = [
			[
				'post_title'     => 'About',
				'post_content'   => 'This is an example of an about page. Unlike posts, pages are better suited for more timeless content that you want to be easily accessible, like your About or Contact information. Click the Edit link to make changes to this page or <a href="https://wordpress.com/page">add another page</a>.',
				'post_status'    => 'publish',
				'menu_order'     => 2,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'page',
				'hs_custom_meta' => '_hs_about_page',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'attachment_url' => 'https://headstartdata.files.wordpress.com/2016/06/stairs-lights-abstract-bubbles1.jpg',
				'hs_old_id'      => 100,
			],
			[
				'post_title'     => 'Contact',
				'post_content'   => 'This is a contact page with some basic contact information and a contact form. [contact-form][contact-field label="Name" type="name" required="1"/][contact-field label="Email" type="email" required="1"/][contact-field label="Website" type="url"/][contact-field label="Comment" type="textarea" required="1"/][/contact-form]',
				'post_status'    => 'publish',
				'menu_order'     => 3,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'page',
				'hs_custom_meta' => '_hs_contact_page',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'attachment_url' => 'https://headstartdata.files.wordpress.com/2016/06/person-smartphone-office-table.jpeg',
				'hs_old_id'      => 101,
			],
			[
				'post_title'     => 'First blog post',
				'post_content'   => 'This is your very first post. Click the Edit link to modify or delete it, or <a href="https://wordpress.com/post">start a new post</a>. If you like, use this post to tell readers why you started this blog and what you plan to do with it.',
				'post_excerpt'   => 'This is the excerpt for your very first post.',
				'post_status'    => 'publish',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_type'      => 'post',
				'hs_custom_meta' => '_hs_first_post',
				'hs_sharing'     => 1,
				'hs_like_status' => 1,
				'attachment_url' => 'https://headstartdata.files.wordpress.com/2016/06/pexels-photo-30732.jpg',
				'hs_old_id'      => 102,
			],
		];

		// Add home and blog pages for `page_on_front` themes.
		if ( 'page' === $options['show_on_front'] ) {
			$content = array_merge( $content, self::generate_home_and_blog_pages( $options, $template_for_post_id ) );
		}

		// Add featured Content posts if in use
		if ( is_array( $options['featured-content'] ) && ! empty( $options['featured-content'] ) ) {
			$featured_tag_name = empty( $options['featured-content']['tag-name'] ) ? 'featured' : $options['featured-content']['tag-name'];
			$content = array_merge( $content, self::generate_featured_content_posts( $featured_tag_name ) );
		}

		// Add any additional required posts. We skip one because of the first blog post above.
		for ( $i = 1; $i < $posts_required; $i++ ) {
			$content[] = self::generate_placeholder_post( $i );
		}

		// Add Jetpack Portfolio projects if in use
		if ( current_theme_supports( 'jetpack-portfolio' ) ) {
			$content = array_merge( $content, self::generate_portfolio_projects() );
		}

		// Add Jetpack Testimonials if in use
		if ( current_theme_supports( 'jetpack-testimonial' ) ) {
			$content = array_merge( $content, self::generate_testmonials() );
		}

		return $content;
	}



	public static function get_menus() {
		return self::get_formatted_menus( self::get_current_menus_data() );
	}

	/**
	 * Convert menus to format used by wp_update_nav_menu_item
	 */
	private static function get_formatted_menus( $menus ) {
		$formatted_menus = array();
		foreach ( $menus as $menu_slug => $menu_parts ) {
			$formatted_menus[ $menu_slug ] = array(
				'name' => $menu_parts['name'],
				'items' => array(),
			);
			foreach ( $menu_parts['items'] as $menu_item ) {
				array_push( $formatted_menus[ $menu_slug ]['items'], self::get_formatted_menu_item( $menu_item ) );
			}
		}
		return $formatted_menus;
	}

	/**
	 * Return array of menu item objects, keyed by menu name
	 */
	private static function get_current_menus_data() {
		$nav_menus = get_terms( 'nav_menu', [ 'hide_empty' => false ] );
		if ( empty( $nav_menus ) || is_wp_error( $nav_menus ) ) {
			return array();
		}
		$menus = array();
		foreach ( $nav_menus as $nav_menu ) {
			$items = wp_get_nav_menu_items( $nav_menu->slug );
			if ( ! $items ) {
				$items = array();
			}
			$menus[ $nav_menu->slug ] = array(
				'name' => $nav_menu->name,
				'items' => $items,
			);
		}
		return $menus;
	}

	private static function get_formatted_menu_item( $menu_item ) {
		$formatted_menus_item = array(
			'menu-item-title'       => $menu_item->title,
			'menu-item-db-id'       => $menu_item->db_id,
			'menu-item-object-id'   => $menu_item->object_id,
			'menu-item-object'      => $menu_item->object,
			'menu-item-type'        => $menu_item->type,
			'menu-item-status'      => 'publish',
			'menu-item-position'    => $menu_item->menu_order,
			'menu-item-parent-id'   => $menu_item->menu_item_parent,
			'menu-item-description' => $menu_item->description,
		);

		if ( 'custom' === $menu_item->type ) {
			$formatted_menus_item['menu-item-url'] = $menu_item->url;
		}

		if ( 'custom' === $menu_item->type && 'Home' === $menu_item->title ) {
			$formatted_menus_item['menu-item-url'] = '/';
		}
		return $formatted_menus_item;
	}

	public static function set_placeholders( $theme_annotation, $type ) {
		$options = $theme_annotation['settings']['options'];
		$theme_mods = $theme_annotation['settings']['theme_mods'];
		if ( ! $theme_mods ) {
			$theme_mods = array();
		}

		// Unset 'posts_per_page' if default.
		if ( '10' === $options['posts_per_page'] ) {
			unset( $theme_annotation['settings']['options']['posts_per_page'] );
		}

		// Unset 'header_textcolor' theme_mod if default.
		if ( isset( $theme_mods['header_textcolor'] ) && 'blank' === $theme_mods['header_textcolor'] ) {
			unset( $theme_annotation['settings']['theme_mods']['header_textcolor'] );
		}

		// Unset 'background_color' theme_mod if default.
		if ( isset( $theme_mods['background_color'] ) && '' === $theme_mods['background_color'] ) {
			unset( $theme_annotation['settings']['theme_mods']['background_color'] );
		}

		// Set sticky posts.
		if ( isset( $options['sticky_posts'] ) && is_array( $options['sticky_posts'] ) ) {
			foreach ( $options['sticky_posts'] as $sticky_post ) {
				if ( array_key_exists( $sticky_post, $theme_annotation['content'] ) ) {
					$theme_annotation['content'][ $sticky_post ]['sticky'] = true;
				}
			}

			unset( $theme_annotation['settings']['options']['sticky_posts'] );
		}

		// Set 'nav_menu_locations' to menu names.
		if ( isset( $theme_mods['nav_menu_locations'] ) && is_array( $theme_mods['nav_menu_locations'] ) ) {
			// Handle menus for a base annotation.
			if ( 'base' === $type ) {
				$theme_annotation['settings']['theme_mods']['nav_menu_locations'] = [
					'primary' => 'primary',
					'social'  => 'social-media',
				];
			} else {
				foreach ( $theme_mods['nav_menu_locations'] as $nav_menu_location => $nav_menu ) {
					$menu_slug = wp_get_nav_menu_object( $nav_menu )->slug;
					$theme_annotation['settings']['theme_mods']['nav_menu_locations'][ $nav_menu_location ] = $menu_slug;
				}
			}
		}

		// Remove widget location theme_mod if there aren't any widgets.
		if ( empty( $theme_annotation['widgets'] ) ) {
			unset( $theme_annotation['settings']['theme_mods']['sidebars_widgets'] );
		}

		// Set menu slug for nav_menu widgets.
		foreach ( $theme_annotation['widgets'] as $widget => $settings ) {
			if ( 'nav_menu' === $settings['id_base'] && isset( $settings['settings']['nav_menu'] ) ) {
				$nav_menu_object = wp_get_nav_menu_object( $settings['settings']['nav_menu'] );

				if ( false !== $nav_menu_object ) {
					$theme_annotation['widgets'][ $widget ]['settings']['nav_menu'] = $nav_menu_object->slug;
				} else {
					$theme_annotation['widgets'][ $widget ]['settings']['nav_menu'] = 'primary';
				}
			}
		}

		return $theme_annotation;
	}

	public static function set_annotation_meta() {
		return [
			'blog_id'          => get_current_blog_id(),
			'url'              => get_site_url(),
			'update_date'      => gmdate( 'F j, Y, g:i a' ),
			'update_timestamp' => time(),
		];
	}
}
