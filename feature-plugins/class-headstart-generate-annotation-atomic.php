<?php

class Headstart_Generate_Annotation_Atomic {
	public static function generate_theme_annotation() {
		$type                 = 'copy';
		$options              = self::get_site_options();
		$widgets              = array();
		$template_for_post_id = self::get_template_for_post_id();
		$posts_required       = 1;
		$excluded_post_types  = array();

		$anno = Headstart_Annotation_Generator_Simple::generate_theme_annotation( $type, $options, $widgets, $template_for_post_id, $posts_required, $excluded_post_types );

		// Add Terms, Term Meta, and Term Assignments.
		$anno['custom_terms_by_taxonomy'] = self::build_custom_terms();
		$custom_term_ids                  = self::get_custom_term_ids( $anno['custom_terms_by_taxonomy'] );
		$anno['custom_term_meta']         = self::build_custom_term_meta( $custom_term_ids );
		$anno['custom_term_assignments']  = self::build_product_term_assignments();

		// Fetch post_meta data on product posts.
		$product_data = self::build_woocommerce_product_data();

		// The data needs to be transformed by adding it or merging it to the
		// "hs_post_meta" key for each post in "content".
		foreach ( array_keys( $anno['content'] ) as $post_id ) {
			if ( empty( $product_data[$post_id] ) ) {
				continue;
			}
			if ( empty( $anno['content'][$post_id]['hs_post_meta'] ) ) {
				$anno['content'][$post_id]['hs_post_meta'] = $product_data[$post_id];
			} else {
				$anno['content'][$post_id]['hs_post_meta'] = array_merge( $anno['content'][$post_id]['hs_post_meta'], $product_data[$post_id] );
			}
		}

		// Re-index the `posts` array.
		$anno['content'] = array_values( $anno['content'] );

		return $anno;
	}

	private static function get_template_for_post_id() {
		$template_for_post_id = array();

		$all_post_ids = get_posts( array( 'fields' => 'ids', 'nopaging' => true, 'post_type' => 'any' ) );
		foreach ( $all_post_ids as $post_id ) {
			$template = get_post_meta( $post_id, '_wp_page_template', true );
			if ( empty( $template) || $template === 'default' ) {
				continue;
			}
			$template_for_post_id[$post_id] = $template;
		}
		return $template_for_post_id;
	}

	private static function get_site_options() {
		$site_options                     = array();
		$site_options['show_on_front']    = get_option( 'show_on_front' );
		$site_options['page_on_front']    = get_option( 'page_on_front' );
		$site_options['page_for_posts']   = get_option( 'page_for_posts' );
		$site_options['posts_per_page']   = get_option( 'posts_per_page' );
		$site_options['sticky_posts']     = get_option( 'sticky_posts' );
		$site_options['featured-content'] = get_option( 'featured-content' );

		// copy over any jetpack global styles.
		$site_options['jetpack_global_styles'] = get_option( 'jetpack_global_styles' );

		return $site_options;
	}

	private static function build_custom_terms() {
		$taxons = get_taxonomies();
		$taxons = array_diff( $taxons, self::get_default_taxonomies() );

		$custom_terms = array();
		foreach ( $taxons as $taxon ) {
			$terms = get_terms( $taxon, array( 'hide_empty' => false ) );
			if ( ! empty( $terms ) ) {
				$custom_terms[$taxon] = $terms;
			}
		}
		return $custom_terms;
	}

	private static function get_default_taxonomies() {
		return array(
			'category',
			'post_tag',
			'nav_menu',
			'link_category',
			'post_format',
			'wp_theme',
			'wp_template_part_area',
			'mentions',
		);
	}

	private static function get_custom_term_ids( $custom_terms_by_taxonomy ) {
		$all_ids = array();
		foreach ( $custom_terms_by_taxonomy as $taxon => $terms ) {
			$these_ids = array_map( function( $v ) {
				return $v->term_id;
			}, $terms );
			$all_ids = array_merge( $all_ids, $these_ids );
		}
		return $all_ids;
	}

	private static function build_custom_term_meta( $custom_term_ids ) {
		$result = array();
		foreach ( $custom_term_ids as $id ) {
			$meta = get_term_meta( $id );
			if ( ! empty( $meta ) ) {
				$result[ $id ] = $meta;
			}
		}
		return $result;
	}

	private static function build_product_term_assignments( ) {
		$assignments = array();

		$published_product_filter = array(
			'nopaging'    => true,
			'post_status' => 'publish',
			'post_type'   => array( 'product' ),
		);
		$product_posts = get_posts( $published_product_filter );
		foreach ( $product_posts as $post ) {
			$assignments[ $post->ID ] = array();

			$taxes = get_object_taxonomies( $post->post_type );
			foreach ( $taxes as $tax ) {
				$names = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'names' ) );
				if ( ! empty( $names ) ) {
					$assignments[$post->ID][$tax] = $names;
				}
			}

			if ( empty( $assignments[ $post->ID ] ) ) {
				unset( $assignments[ $post->ID ] );
			}
		}
		return $assignments;
	}

	private static function build_woocommerce_product_data() {
		if ( ! class_exists( 'WC_Product_Data_Store_CPT' ) ) {
			return array();
		}

		$headstart_product_meta = array();

		$woo_product_data_store = new WC_Product_Data_Store_CPT();
		$woo_product_meta_keys  = $woo_product_data_store->get_internal_meta_keys();

		$published_product_filter = array(
			'nopaging'    => true,
			'post_status' => 'publish',
			'post_type'   => array( 'product' ),
		);
		$product_posts            = get_posts( $published_product_filter );

		// Post meta keys that we have decided not to include in headstart annotations.
		$skip_these_meta_keys = array(
			'_edit_last',
			'_edit_lock',
			'_thumbnail_id',
		);

		foreach ( $product_posts as $product_post ) {
			$post_meta = get_post_meta( $product_post->ID );

			// If a post_meta_key has only one value, remove the array surrounding the value.
			// Example: '_hs_old_id' => [ 78 ] becomes '_hs_old_id' => 78.
			foreach ( $post_meta as $post_meta_key => $post_meta_value ) {
				if ( is_array( $post_meta_value ) && count( $post_meta_value ) == 1 ) {
					$post_meta[$post_meta_key] = $post_meta_value[0];
				}
			}

			// Remove the list of "skippable" keys.
			$post_meta = array_diff_key( $post_meta, array_flip( $skip_these_meta_keys ) );

			if ( ! empty( $post_meta ) && is_array( $post_meta ) ) {
				// Extract all specified Woo meta keys
				// We may want to avoid any empty keys, in which case we could use ARRAY_FILTER_USE_BOTH
				$headstart_product_meta[ $product_post->ID ] = array_filter(
					$post_meta,
					function ( $meta_key ) use ( $woo_product_meta_keys ) {
						return in_array( $meta_key, $woo_product_meta_keys, true );
					},
					ARRAY_FILTER_USE_KEY
				);
			}
		}

		return $headstart_product_meta;
	}
}


