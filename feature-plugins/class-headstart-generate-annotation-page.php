<?php

function display_headstart_generate_annotation_page() {
	$anno = Headstart_Generate_Annotation_Atomic::generate_theme_annotation();
	echo "<h3>Headstart Annotation Generated From This Site:</h3>";
	echo "<div><pre>";
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
