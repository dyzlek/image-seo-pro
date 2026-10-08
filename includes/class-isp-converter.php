<?php
/**
 * Conversion d'un fichier image vers WebP ou AVIF (Imagick, sinon GD).
 *
 * @package ImageSeoPro
 */

defined( 'ABSPATH' ) || exit;

class ISP_Converter {

	const FORMATS = array( 'webp', 'avif' );

	/**
	 * Types d'images sources acceptés.
	 */
	const SOURCE_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Moteur à utiliser : « imagick », « gd » ou « » si aucun ne sait produire ce format.
	 *
	 * @param string $format webp|avif.
	 * @return string
	 */
	public static function engine_for( $format ) {
		/**
		 * Force un moteur de conversion (« imagick » ou « gd »). « auto » par défaut.
		 */
		$forced = apply_filters( 'isp_image_engine', 'auto' );

		$imagick = self::imagick_supports( $format );
		$gd      = self::gd_supports( $format );

		if ( 'gd' === $forced ) {
			return $gd ? 'gd' : '';
		}
		if ( 'imagick' === $forced ) {
			return $imagick ? 'imagick' : '';
		}
		if ( $imagick ) {
			return 'imagick';
		}
		return $gd ? 'gd' : '';
	}

	/**
	 * Nom affiché d'un format : « WebP », « AVIF ».
	 */
	public static function label( $format ) {
		return 'webp' === $format ? 'WebP' : strtoupper( $format );
	}

	public static function supports( $format ) {
		return in_array( $format, self::FORMATS, true ) && '' !== self::engine_for( $format );
	}

	private static function imagick_supports( $format ) {
		static $cache = array();
		if ( ! isset( $cache[ $format ] ) ) {
			$cache[ $format ] = false;
			if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
				try {
					$cache[ $format ] = (bool) Imagick::queryFormats( strtoupper( $format ) );
				} catch ( Exception $e ) {
					$cache[ $format ] = false;
				}
			}
		}
		return $cache[ $format ];
	}

	private static function gd_supports( $format ) {
		if ( ! function_exists( 'gd_info' ) ) {
			return false;
		}
		$info = gd_info();
		if ( 'webp' === $format ) {
			return function_exists( 'imagewebp' ) && ! empty( $info['WebP Support'] );
		}
		if ( 'avif' === $format ) {
			return function_exists( 'imageavif' ) && ! empty( $info['AVIF Support'] );
		}
		return false;
	}

	/**
	 * Convertit $source en $format dans $destination.
	 *
	 * @param string $source      Chemin du fichier source.
	 * @param string $destination Chemin du fichier à écrire.
	 * @param string $format      webp|avif.
	 * @param int    $quality     Qualité 1-100.
	 * @return int|WP_Error Taille du fichier écrit, en octets.
	 */
	public static function convert( $source, $destination, $format, $quality ) {
		if ( ! is_readable( $source ) ) {
			return new WP_Error( 'isp_unreadable', __( 'Source file is missing or unreadable.', 'image-seo-pro' ) );
		}
		$engine = self::engine_for( $format );
		if ( '' === $engine ) {
			/* translators: %s: image format (WEBP, AVIF). */
			return new WP_Error( 'isp_unsupported', sprintf( __( 'This server cannot create %s images.', 'image-seo-pro' ), self::label( $format ) ) );
		}

		$quality = min( 100, max( 1, (int) $quality ) );
		wp_raise_memory_limit( 'image' );

		$result = 'imagick' === $engine
			? self::convert_imagick( $source, $destination, $format, $quality )
			: self::convert_gd( $source, $destination, $format, $quality );

		if ( is_wp_error( $result ) ) {
			if ( file_exists( $destination ) ) {
				wp_delete_file( $destination );
			}
			return $result;
		}

		clearstatcache( true, $destination );
		$bytes = file_exists( $destination ) ? (int) filesize( $destination ) : 0;
		if ( $bytes <= 0 ) {
			return new WP_Error( 'isp_write_failed', __( 'The converted file could not be written.', 'image-seo-pro' ) );
		}
		return $bytes;
	}

	private static function convert_imagick( $source, $destination, $format, $quality ) {
		try {
			$image = new Imagick( $source );
			if ( $image->getNumberImages() > 1 ) {
				$image->clear();
				return new WP_Error( 'isp_animated', __( 'Animated images are not converted.', 'image-seo-pro' ) );
			}

			// On retire les métadonnées (EXIF, XMP…) mais on garde le profil couleur.
			$profiles = $image->getImageProfiles( 'icc', true );
			$image->stripImage();
			if ( ! empty( $profiles['icc'] ) ) {
				$image->profileImage( 'icc', $profiles['icc'] );
			}

			$image->setImageFormat( $format );
			$image->setImageCompressionQuality( $quality );
			if ( 'webp' === $format ) {
				$image->setOption( 'webp:method', '4' );
			} else {
				$image->setOption( 'heic:speed', '6' );
			}
			$image->writeImage( $format . ':' . $destination );
			$image->clear();
			return true;
		} catch ( Exception $e ) {
			// Imagick peut échouer sur un format qu'il annonce : on retente avec GD.
			if ( self::gd_supports( $format ) ) {
				return self::convert_gd( $source, $destination, $format, $quality );
			}
			return new WP_Error( 'isp_imagick', $e->getMessage() );
		}
	}

	private static function convert_gd( $source, $destination, $format, $quality ) {
		$info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$mime = $info ? $info['mime'] : '';

		switch ( $mime ) {
			case 'image/jpeg':
				$image = @imagecreatefromjpeg( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			case 'image/png':
				$image = @imagecreatefrompng( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			case 'image/webp':
				$image = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $source ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			default:
				$image = false;
		}

		if ( ! $image ) {
			return new WP_Error( 'isp_gd_read', __( 'The image could not be read.', 'image-seo-pro' ) );
		}

		if ( ! imageistruecolor( $image ) ) {
			imagepalettetotruecolor( $image );
		}
		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$ok = 'webp' === $format
			? imagewebp( $image, $destination, $quality )
			: imageavif( $image, $destination, $quality, 6 );

		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $image ); // Inutile (et obsolète) depuis PHP 8 : l'objet est libéré seul.
		}

		return $ok ? true : new WP_Error( 'isp_gd_write', __( 'The converted file could not be written.', 'image-seo-pro' ) );
	}
}
