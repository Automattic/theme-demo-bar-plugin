<?php

function display_headstart_generate_annotation_page() {
	$anno = Headstart_Generate_Annotation_Atomic::generate_theme_annotation();
	echo "<h3>Headstart Annotation Generated From This Site:</h3>";
	echo "<span id='hs_select_all_code' style='cursor: pointer; color: blue'>Select all</span>";
	echo "<div><pre id='hs_code'>";
	echo esc_html( wp_json_encode( $anno, JSON_PRETTY_PRINT ) );
	echo "</pre></div>";
}

function headstart_generate_annotation_admin_menu() {
	add_menu_page(
		'Headstart',      // page title
		'Headstart',      // menu title
		'manage_options', // capability
		'headstart',      // menu slug
		'display_headstart_generate_annotation_page'
	);
}

add_action('admin_menu', 'headstart_generate_annotation_admin_menu');

function page_headstart_queue_script( $hook ) {
	if ( $hook !== 'toplevel_page_headstart' ) {
		return;
	}
	wp_enqueue_script( 'headstart_custom_script', plugin_dir_url( __FILE__ ) . 'headstart-generate-annotation-page.js', array(), '1.0.0' );
}

add_action( 'admin_enqueue_scripts', 'page_headstart_queue_script' );
