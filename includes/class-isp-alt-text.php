<?php
/**
 * Textes alternatifs : recherche des images sans alt, enregistrement, suggestions.
 *
 * @package ImageSeoPro
 */

defined( 'ABSPATH' ) || exit;

class ISP_Alt_Text {

	const META = '_wp_attachment_image_alt';

	/**
	 * Transforme un nom de fichier ou un titre en texte lisible.
	 * « mon-velo_rouge-1024x768.jpg » → « Mon velo rouge ». Renvoie « » si rien d'utile.
	 *
	 * @param string $label Nom de fichier ou titre.
	 * @return string
	 */
	public static function humanize( $label ) {
		$label = html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' );
		$label = preg_replace( '/\.(jpe?g|png|gif|webp|avif|svg|heic|tiff?)$/i', '', trim( $label ) );
		// Suffixes ajoutés par WordPress : dimensions, -scaled, -rotated, -e1712345678, doublons -1.
		$label = preg_replace( '/(-\d+x\d+|-scaled|-rotated|-e\d{10,}|-\d{1,2})+$/i', '', $label );
		// Noms automatiques d'appareils photo et de captures d'écran.
		$label = preg_replace( '/\b(img|dsc|dscn|dcim|pxl|mvimg|photo|image|screenshot|capture)[\s_-]*\d[\d_-]*/i', ' ', $label );
		$label = preg_replace( "/\b(capture d['’]écran|screenshot)\b.*$/iu", ' ', $label );
		$label = preg_replace( '/[\s_\-+.]+/u', ' ', $label );
		$label = trim( preg_replace( '/\s+/u', ' ', $label ) );

		// Il faut au moins un mot de 3 lettres pour que ce soit utile.
		if ( ! preg_match( '/\p{L}{3,}/u', $label ) ) {
			return '';
		}
		return function_exists( 'mb_strtoupper' )
			? mb_strtoupper( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 )
			: ucfirst( $label );
	}

	/**
	 * Suggestion sans IA : titre du média, sinon nom du fichier.
	 */
	public static function suggest_from_filename( $id ) {
		$title = self::humanize( get_the_title( $id ) );
		if ( '' !== $title ) {
			return $title;
		}
		$file = get_attached_file( $id, true );
		return $file ? self::humanize( wp_basename( $file ) ) : '';
	}

	public static function get( $id ) {
		return (string) get_post_meta( (int) $id, self::META, true );
	}

	public static function save( $id, $alt ) {
		$alt = trim( sanitize_text_field( wp_unslash( $alt ) ) );
		update_post_meta( (int) $id, self::META, $alt );
		return $alt;
	}

	/**
	 * Clause meta_query : alt absent ou vide.
	 */
	public static function missing_meta_query() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::META,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META,
				'value'   => '',
				'compare' => '=',
			),
		);
	}

	/**
	 * Images de la médiathèque, paginées.
	 *
	 * @param array $args page, per_page, missing (bool), search.
	 * @return WP_Query
	 */
	public static function query( $args = array() ) {
		$args  = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => 20,
				'missing'  => false,
				'search'   => '',
			)
		);
		$query = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => (int) $args['per_page'],
			'paged'          => max( 1, (int) $args['page'] ),
			'orderby'        => 'ID',
			'order'          => 'DESC',
		);
		if ( $args['missing'] ) {
			$query['meta_query'] = self::missing_meta_query(); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( '' !== $args['search'] ) {
			$query['s'] = $args['search'];
		}
		return new WP_Query( $query );
	}

	/**
	 * IDs de toutes les images sans texte alternatif.
	 *
	 * @return int[]
	 */
	public static function missing_ids() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => self::missing_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return array_map( 'intval', $query->posts );
	}

	public static function count_missing() {
		return count( self::missing_ids() );
	}
}
