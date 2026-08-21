<?php
/**
 * Inhaltstyp „Titel“ – die Musik im Player.
 *
 * Jeder Titel hat: Name (Beitragstitel), Cover (Beitragsbild), Stilrichtung,
 * Dauer und die Audiodatei. Die Reihenfolge steuert das Feld „Reihenfolge“.
 *
 * @package DjAtze
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inhaltstyp anmelden.
 */
function rt_register_track() {
	register_post_type(
		'rt_track',
		array(
			'labels'        => array(
				'name'               => __( 'Titel', 'dj-atze' ),
				'singular_name'      => __( 'Titel', 'dj-atze' ),
				'add_new'            => __( 'Titel hinzufügen', 'dj-atze' ),
				'add_new_item'       => __( 'Neuer Titel', 'dj-atze' ),
				'edit_item'          => __( 'Titel bearbeiten', 'dj-atze' ),
				'all_items'          => __( 'Alle Titel', 'dj-atze' ),
				'menu_name'          => __( 'Musik', 'dj-atze' ),
				'not_found'          => __( 'Noch keine Titel angelegt.', 'dj-atze' ),
			),
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'menu_icon'     => 'dashicons-format-audio',
			'menu_position' => 21,
			'supports'      => array( 'title', 'thumbnail', 'page-attributes' ),
			'has_archive'   => false,
			'rewrite'       => false,
		)
	);
}
add_action( 'init', 'rt_register_track' );

/**
 * Eingabefelder neben dem Titel.
 */
function rt_track_meta_box() {
	add_meta_box(
		'rt_track_details',
		__( 'Angaben zum Titel', 'dj-atze' ),
		'rt_track_meta_box_html',
		'rt_track',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'rt_track_meta_box' );

/**
 * Inhalt des Eingabefelds.
 *
 * @param WP_Post $post Beitrag.
 * @return void
 */
function rt_track_meta_box_html( $post ) {
	wp_nonce_field( 'rt_save_track', 'rt_track_nonce' );

	$tag      = get_post_meta( $post->ID, '_rt_tag', true );
	$duration = get_post_meta( $post->ID, '_rt_duration', true );
	$audio    = get_post_meta( $post->ID, '_rt_audio', true );
	?>
	<p>
		<label for="rt_tag"><strong><?php esc_html_e( 'Stilrichtung', 'dj-atze' ); ?></strong></label><br />
		<input type="text" id="rt_tag" name="rt_tag" class="widefat" value="<?php echo esc_attr( $tag ); ?>"
			placeholder="<?php esc_attr_e( 'z. B. Peak Time Techno', 'dj-atze' ); ?>" />
	</p>
	<p>
		<label for="rt_duration"><strong><?php esc_html_e( 'Dauer', 'dj-atze' ); ?></strong></label><br />
		<input type="text" id="rt_duration" name="rt_duration" value="<?php echo esc_attr( $duration ); ?>"
			placeholder="6:12" />
		<span class="description"><?php esc_html_e( 'Wird angezeigt, bis die Audiodatei geladen ist.', 'dj-atze' ); ?></span>
	</p>
	<p>
		<label for="rt_audio"><strong><?php esc_html_e( 'Audiodatei', 'dj-atze' ); ?></strong></label><br />
		<input type="url" id="rt_audio" name="rt_audio" class="widefat" value="<?php echo esc_url( $audio ); ?>"
			placeholder="https://…/set.mp3" />
		<button type="button" class="button" id="rt_audio_pick"><?php esc_html_e( 'Datei wählen', 'dj-atze' ); ?></button>
		<span class="description"><?php esc_html_e( 'Fehlt die Datei, läuft der Player für diesen Titel im Vorschaumodus.', 'dj-atze' ); ?></span>
	</p>
	<script>
		document.getElementById('rt_audio_pick').addEventListener('click', function () {
			if (!window.wp || !window.wp.media) return;
			var frame = window.wp.media({ title: 'Audiodatei wählen', library: { type: 'audio' }, multiple: false });
			frame.on('select', function () {
				document.getElementById('rt_audio').value = frame.state().get('selection').first().toJSON().url;
			});
			frame.open();
		});
	</script>
	<?php
}

/**
 * Eingaben speichern.
 *
 * @param int $post_id Beitrag.
 * @return void
 */
function rt_save_track( $post_id ) {
	if ( ! isset( $_POST['rt_track_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rt_track_nonce'] ) ), 'rt_save_track' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$fields = array(
		'_rt_tag'      => isset( $_POST['rt_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['rt_tag'] ) ) : '',
		'_rt_duration' => isset( $_POST['rt_duration'] ) ? sanitize_text_field( wp_unslash( $_POST['rt_duration'] ) ) : '',
		'_rt_audio'    => isset( $_POST['rt_audio'] ) ? esc_url_raw( wp_unslash( $_POST['rt_audio'] ) ) : '',
	);

	foreach ( $fields as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
}
add_action( 'save_post_rt_track', 'rt_save_track' );

/**
 * Medienbibliothek im Bearbeiten-Bildschirm bereitstellen.
 *
 * @param string $hook Aktueller Bildschirm.
 * @return void
 */
function rt_track_admin_assets( $hook ) {
	if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && 'rt_track' === get_post_type() ) {
		wp_enqueue_media();
	}
}
add_action( 'admin_enqueue_scripts', 'rt_track_admin_assets' );

/**
 * Alle Titel für den Player aufbereiten.
 *
 * @return array
 */
function rt_get_tracks() {
	$posts = get_posts(
		array(
			'post_type'      => 'rt_track',
			'post_status'    => 'publish',
			'numberposts'    => 50,
			'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
		)
	);

	$fallback = array(
		RT_URI . '/assets/img/cover-01.svg',
		RT_URI . '/assets/img/cover-02.svg',
		RT_URI . '/assets/img/cover-03.svg',
		RT_URI . '/assets/img/cover-04.svg',
		RT_URI . '/assets/img/cover-05.svg',
		RT_URI . '/assets/img/cover-06.svg',
	);

	$tracks = array();

	foreach ( array_values( $posts ) as $index => $post ) {
		$cover = get_the_post_thumbnail_url( $post->ID, 'rt-cover' );

		$tracks[] = array(
			'title'    => $post->post_title,
			'tag'      => (string) get_post_meta( $post->ID, '_rt_tag', true ),
			'duration' => (string) get_post_meta( $post->ID, '_rt_duration', true ),
			'audio'    => (string) get_post_meta( $post->ID, '_rt_audio', true ),
			'cover'    => $cover ? $cover : $fallback[ $index % count( $fallback ) ],
		);
	}

	return $tracks;
}
