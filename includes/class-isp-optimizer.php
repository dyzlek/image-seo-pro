<?php
/**
 * Optimisation d'un média : crée à côté de chaque fichier (image principale et miniatures)
 * une copie WebP / AVIF, sans jamais modifier ni supprimer l'original.
 *
 * Exemple : photo-300x200.jpg  →  photo-300x200.jpg.webp  et  photo-300x200.jpg.avif
 *
 * @package ImageSeoPro
 */

defined( 'ABSPATH' ) || exit;

class ISP_Optimizer {

	const META = '_isp_optimization';

	public function __construct() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_upload' ), 20, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'delete_siblings' ) );
	}

	/**
	 * Optimise automatiquement les nouvelles images, une fois les miniatures créées.
	 *
	 * @param array $metadata Métadonnées en cours de création.
	 * @param int   $id       ID du média.
	 * @return array
	 */
	public function on_upload( $metadata, $id ) {
		if ( ISP_Settings::get( 'auto_optimize' ) && self::is_optimizable( $id ) ) {
			self::optimize( $id, is_array( $metadata ) ? $metadata : null );
		}
		return $metadata;
	}

	public static function sibling_path( $path, $format ) {
		return $path . '.' . $format;
	}

	/**
	 * Formats à produire pour ce média (un WebP ne sera pas reconverti en WebP).
	 */
	public static function formats_for( $id ) {
		$mime    = get_post_mime_type( $id );
		$formats = ISP_Settings::active_formats();
		if ( 'image/webp' === $mime ) {
			$formats = array_values( array_diff( $formats, array( 'webp' ) ) );
		}
		return $formats;
	}

	public static function is_optimizable( $id ) {
		return in_array( get_post_mime_type( $id ), ISP_Converter::SOURCE_MIMES, true ) && array() !== self::formats_for( $id );
	}

	/**
	 * Dossier et noms des fichiers affichables d'un média (image principale + miniatures).
	 *
	 * @param int        $id       ID du média.
	 * @param array|null $metadata Métadonnées à utiliser à la place de celles en base.
	 * @return array{dir: string, files: string[]}
	 */
	public static function attachment_files( $id, $metadata = null ) {
		$file = get_attached_file( $id, true );
		if ( ! $file ) {
			return array(
				'dir'   => '',
				'files' => array(),
			);
		}
		$metadata = null === $metadata ? wp_get_attachment_metadata( $id ) : $metadata;
		$files    = array( wp_basename( $file ) );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = wp_basename( $size['file'] );
				}
			}
		}
		return array(
			'dir'   => dirname( $file ),
			'files' => array_values( array_unique( $files ) ),
		);
	}

	/**
	 * Crée les copies WebP / AVIF d'un média.
	 *
	 * @param int        $id       ID du média.
	 * @param array|null $metadata Métadonnées (pendant l'upload, elles ne sont pas encore en base).
	 * @return array|WP_Error Données d'optimisation enregistrées.
	 */
	public static function optimize( $id, $metadata = null ) {
		$id = (int) $id;
		if ( ! in_array( get_post_mime_type( $id ), ISP_Converter::SOURCE_MIMES, true ) ) {
			return new WP_Error( 'isp_type', __( 'Only JPEG, PNG and WebP images can be optimized.', 'image-seo-pro' ) );
		}
		$formats = self::formats_for( $id );
		if ( array() === $formats ) {
			return new WP_Error( 'isp_no_format', __( 'No output format is enabled or supported by this server.', 'image-seo-pro' ) );
		}

		$quality = (int) apply_filters( 'isp_quality', ISP_Settings::get( 'quality' ), $id );
		$set     = self::attachment_files( $id, $metadata );
		if ( array() === $set['files'] ) {
			return new WP_Error( 'isp_no_file', __( 'The image file is missing.', 'image-seo-pro' ) );
		}

		self::delete_siblings( $id, $metadata );

		$data   = array(
			'version' => 1,
			'time'    => time(),
			'quality' => $quality,
			'formats' => $formats,
			'files'   => array(),
		);
		$errors = array();

		foreach ( $set['files'] as $name ) {
			$path = $set['dir'] . '/' . $name;
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$entry = array( 'size' => (int) filesize( $path ) );
			foreach ( $formats as $format ) {
				$target = self::sibling_path( $path, $format );
				$bytes  = ISP_Converter::convert( $path, $target, $format, $quality );
				if ( is_wp_error( $bytes ) ) {
					$errors[] = $bytes;
					continue;
				}
				// Une copie plus lourde que l'original ne sert à rien : on ne la garde pas.
				if ( $bytes >= $entry['size'] ) {
					wp_delete_file( $target );
					continue;
				}
				$entry[ $format ] = $bytes;
			}
			$data['files'][ $name ] = $entry;
		}

		if ( array() === $data['files'] ) {
			return new WP_Error( 'isp_no_file', __( 'The image file is missing.', 'image-seo-pro' ) );
		}
		if ( $errors && 0 === self::count_siblings( $data ) ) {
			return $errors[0];
		}

		update_post_meta( $id, self::META, $data );
		do_action( 'isp_optimized', $id, $data );
		return $data;
	}

	public static function get_data( $id ) {
		$data = get_post_meta( (int) $id, self::META, true );
		return is_array( $data ) && isset( $data['files'] ) ? $data : null;
	}

	/**
	 * Supprime les copies et revient à l'état d'origine.
	 */
	public static function restore( $id ) {
		self::delete_siblings( $id );
		delete_post_meta( (int) $id, self::META );
		return true;
	}

	/**
	 * Supprime toutes les copies WebP / AVIF connues d'un média.
	 *
	 * @param int        $id       ID du média.
	 * @param array|null $metadata Métadonnées à utiliser.
	 */
	public static function delete_siblings( $id, $metadata = null ) {
		$set  = self::attachment_files( $id, $metadata );
		$data = self::get_data( $id );
		$names = $set['files'];
		if ( $data ) {
			$names = array_unique( array_merge( $names, array_keys( $data['files'] ) ) );
		}
		if ( '' === $set['dir'] ) {
			return;
		}
		foreach ( $names as $name ) {
			foreach ( ISP_Converter::FORMATS as $format ) {
				$sibling = self::sibling_path( $set['dir'] . '/' . $name, $format );
				if ( file_exists( $sibling ) ) {
					wp_delete_file( $sibling );
				}
			}
		}
	}

	public static function count_siblings( $data ) {
		$count = 0;
		foreach ( $data['files'] as $entry ) {
			foreach ( ISP_Converter::FORMATS as $format ) {
				if ( ! empty( $entry[ $format ] ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Résumé d'une optimisation : poids d'origine, poids servi (meilleure copie de chaque fichier)
	 * et gain par format.
	 *
	 * @param array $data Données d'optimisation.
	 * @return array{original: int, optimized: int, saved: int, percent: int, formats: array}
	 */
	public static function summarize( $data ) {
		$original  = 0;
		$optimized = 0;
		$formats   = array();
		foreach ( $data['files'] as $entry ) {
			$size      = (int) $entry['size'];
			$original += $size;
			$best      = $size;
			foreach ( ISP_Converter::FORMATS as $format ) {
				if ( empty( $entry[ $format ] ) ) {
					continue;
				}
				if ( ! isset( $formats[ $format ] ) ) {
					$formats[ $format ] = array(
						'original'  => 0,
						'optimized' => 0,
					);
				}
				$formats[ $format ]['original']  += $size;
				$formats[ $format ]['optimized'] += (int) $entry[ $format ];
				$best                             = min( $best, (int) $entry[ $format ] );
			}
			$optimized += $best;
		}
		foreach ( $formats as $format => $f ) {
			$formats[ $format ]['percent'] = $f['original'] ? (int) round( 100 * ( $f['original'] - $f['optimized'] ) / $f['original'] ) : 0;
		}
		return array(
			'original'  => $original,
			'optimized' => $optimized,
			'saved'     => $original - $optimized,
			'percent'   => $original ? (int) round( 100 * ( $original - $optimized ) / $original ) : 0,
			'formats'   => $formats,
		);
	}

	/**
	 * Requête SQL commune : médias images optimisables.
	 */
	private static function source_mimes_sql() {
		return "'" . implode( "','", array_map( 'esc_sql', ISP_Converter::SOURCE_MIMES ) ) . "'";
	}

	/**
	 * IDs des images à optimiser (toutes si $force).
	 *
	 * @param bool $force Inclure celles déjà optimisées.
	 * @return int[]
	 */
	public static function pending_ids( $force = false ) {
		global $wpdb;
		$mimes = self::source_mimes_sql();
		if ( $force ) {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($mimes) ORDER BY ID DESC" ); // phpcs:ignore WordPress.DB
		} else {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
					WHERE p.post_type = 'attachment' AND p.post_mime_type IN ($mimes) AND m.meta_id IS NULL
					ORDER BY p.ID DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::META
				)
			);
		}
		return array_values( array_filter( array_map( 'intval', $ids ), array( __CLASS__, 'can_optimize_now' ) ) );
	}

	/**
	 * Optimisable et fichier bien présent sur le disque.
	 */
	public static function can_optimize_now( $id ) {
		$file = get_attached_file( $id, true );
		return self::is_optimizable( $id ) && $file && file_exists( $file );
	}

	/**
	 * Statistiques globales pour le tableau de bord.
	 */
	public static function stats() {
		global $wpdb;
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" ); // phpcs:ignore WordPress.DB
		$values = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ); // phpcs:ignore WordPress.DB

		$stats = array(
			'images'    => $total,
			'optimized' => 0,
			'pending'   => count( self::pending_ids() ),
			'original'  => 0,
			'saved'     => 0,
			'percent'   => 0,
		);
		foreach ( $values as $value ) {
			$data = maybe_unserialize( $value );
			if ( ! is_array( $data ) || empty( $data['files'] ) ) {
				continue;
			}
			$summary = self::summarize( $data );
			++$stats['optimized'];
			$stats['original'] += $summary['original'];
			$stats['saved']    += $summary['saved'];
		}
		if ( $stats['original'] ) {
			$stats['percent'] = (int) round( 100 * $stats['saved'] / $stats['original'] );
		}
		return $stats;
	}
}
