<?php
/**
 * Inhaltstyp „Termin“ – die Liste auf der Startseite.
 *
 * @package DjAtze
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inhaltstyp anmelden.
 */
function rt_register_gig() {
	register_post_type(
		'rt_gig',
		array(
			'labels'        => array(
				'name'          => __( 'Termine', 'dj-atze' ),
				'singular_name' => __( 'Termin', 'dj-atze' ),
				'add_new'       => __( 'Termin hinzufügen', 'dj-atze' ),
				'add_new_item'  => __( 'Neuer Termin', 'dj-atze' ),
				'edit_item'     => __( 'Termin bearbeiten', 'dj-atze' ),
				'all_items'     => __( 'Alle Termine', 'dj-atze' ),
				'menu_name'     => __( 'Termine', 'dj-atze' ),
				'not_found'     => __( 'Noch keine Termine angelegt.', 'dj-atze' ),
			),
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'menu_icon'     => 'dashicons-calendar-alt',
			'menu_position' => 22,
			'supports'      => array( 'title', 'page-attributes' ),
			'has_archive'   => false,
			'rewrite'       => false,
		)
	);
}
add_action( 'init', 'rt_register_gig' );

/**
 * Eingabefelder.
 */
function rt_gig_meta_box() {
	add_meta_box(
		'rt_gig_details',
		__( 'Angaben zum Termin', 'dj-atze' ),
		'rt_gig_meta_box_html',
		'rt_gig',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'rt_gig_meta_box' );

/**
 * Inhalt des Eingabefelds.
 *
 * @param WP_Post $post Beitrag.
 * @return void
 */
function rt_gig_meta_box_html( $post ) {
	wp_nonce_field( 'rt_save_gig', 'rt_gig_nonce' );

	$day    = get_post_meta( $post->ID, '_rt_gig_day', true );
	$place  = get_post_meta( $post->ID, '_rt_gig_place', true );
	$status = get_post_meta( $post->ID, '_rt_gig_status', true );
	$link   = get_post_meta( $post->ID, '_rt_gig_link', true );
	?>
	<p>
		<label for="rt_gig_day"><strong><?php esc_html_e( 'Datum', 'dj-atze' ); ?></strong></label><br />
		<input type="text" id="rt_gig_day" name="rt_gig_day" value="<?php echo esc_attr( $day ); ?>" placeholder="12.09." />
		<span class="description"><?php esc_html_e( 'Freier Text – auch „TBA“ ist möglich.', 'dj-atze' ); ?></span>
	</p>
	<p>
		<label for="rt_gig_place"><strong><?php esc_html_e( 'Ort', 'dj-atze' ); ?></strong></label><br />
		<input type="text" id="rt_gig_place" name="rt_gig_place" class="widefat" value="<?php echo esc_attr( $place ); ?>" />
	</p>
	<p>
		<label for="rt_gig_status"><strong><?php esc_html_e( 'Status', 'dj-atze' ); ?></strong></label><br />
		<input type="text" id="rt_gig_status" name="rt_gig_status" value="<?php echo esc_attr( $status ); ?>"
			placeholder="<?php esc_attr_e( 'Tickets', 'dj-atze' ); ?>" />
	</p>
	<p>
		<label for="rt_gig_link"><strong><?php esc_html_e( 'Verweis', 'dj-atze' ); ?></strong></label><br />
		<input type="url" id="rt_gig_link" name="rt_gig_link" class="widefat" value="<?php echo esc_url( $link ); ?>" />
		<span class="description"><?php esc_html_e( 'Leer lassen: der Termin verweist dann auf die Kontaktseite.', 'dj-atze' ); ?></span>
	</p>
	<?php
}

/**
 * Eingaben speichern.
 *
 * @param int $post_id Beitrag.
 * @return void
 */
function rt_save_gig( $post_id ) {
	if ( ! isset( $_POST['rt_gig_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rt_gig_nonce'] ) ), 'rt_save_gig' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$fields = array(
		'_rt_gig_day'    => isset( $_POST['rt_gig_day'] ) ? sanitize_text_field( wp_unslash( $_POST['rt_gig_day'] ) ) : '',
		'_rt_gig_place'  => isset( $_POST['rt_gig_place'] ) ? sanitize_text_field( wp_unslash( $_POST['rt_gig_place'] ) ) : '',
		'_rt_gig_status' => isset( $_POST['rt_gig_status'] ) ? sanitize_text_field( wp_unslash( $_POST['rt_gig_status'] ) ) : '',
		'_rt_gig_link'   => isset( $_POST['rt_gig_link'] ) ? esc_url_raw( wp_unslash( $_POST['rt_gig_link'] ) ) : '',
	);

	foreach ( $fields as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
}
add_action( 'save_post_rt_gig', 'rt_save_gig' );

/**
 * Termine für die Startseite holen.
 *
 * @return array
 */
function rt_get_gigs() {
	$posts = get_posts(
		array(
			'post_type'   => 'rt_gig',
			'post_status' => 'publish',
			'numberposts' => 20,
			'orderby'     => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
		)
	);

	$gigs = array();

	foreach ( $posts as $post ) {
		$gigs[] = array(
			'title'  => $post->post_title,
			'day'    => (string) get_post_meta( $post->ID, '_rt_gig_day', true ),
			'place'  => (string) get_post_meta( $post->ID, '_rt_gig_place', true ),
			'status' => (string) get_post_meta( $post->ID, '_rt_gig_status', true ),
			'link'   => (string) get_post_meta( $post->ID, '_rt_gig_link', true ),
		);
	}

	return $gigs;
}
