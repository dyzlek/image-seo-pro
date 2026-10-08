<?php
/**
 * Côté site : sert les copies WebP / AVIF, complète les textes alternatifs manquants
 * et règle le chargement différé.
 *
 * @package ImageSeoPro
 */

defined( 'ABSPATH' ) || exit;

class ISP_Delivery {

	/**
	 * Attribut posé sur les <img> déjà traitées, pour ne jamais les traiter deux fois.
	 */
	const MARK = 'data-isp';

	public function __construct() {
		add_filter( 'wp_content_img_tag', array( $this, 'filter_content_image' ), 20, 3 );
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20, 2 );
		add_filter( 'wp_omit_loading_attr_threshold', array( $this, 'lazy_threshold' ) );
	}

	/**
	 * Images insérées dans les contenus (articles, pages, blocs).
	 *
	 * @param string $html    Balise <img>.
	 * @param string $context Contexte WordPress.
	 * @param int    $id      ID du média (0 si inconnu).
	 * @return string
	 */
	public function filter_content_image( $html, $context, $id ) {
		$id = $id ? (int) $id : self::id_from_class( $html );
		if ( ! $id ) {
			return $html;
		}
		$html = $this->complete_alt( $html, $id );
		return $this->should_deliver() ? self::transform( $html, $id ) : $html;
	}

	/**
	 * Images affichées par le thème (images mises en avant, galeries…).
	 *
	 * @param string $html Balise <img>.
	 * @param int    $id   ID du média.
	 * @return string
	 */
	public function filter_attachment_image( $html, $id ) {
		return $this->should_deliver() ? self::transform( $html, (int) $id ) : $html;
	}

	/**
	 * Nombre d'images du haut de page chargées tout de suite (pas de loading="lazy"),
	 * pour ne pas retarder la plus grande image visible (LCP).
	 */
	public function lazy_threshold( $threshold ) {
		$skip = ISP_Settings::get( 'lazy_skip' );
		return null === $skip ? $threshold : (int) $skip;
	}

	private function should_deliver() {
		$run = 'off' !== ISP_Settings::get( 'delivery' )
			&& ! is_admin()
			&& ! is_feed()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		return (bool) apply_filters( 'isp_deliver', $run );
	}

	public static function id_from_class( $html ) {
		return preg_match( '/\bwp-image-(\d+)\b/', $html, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Lit un attribut d'une balise HTML (valeur décodée), ou null s'il est absent.
	 */
	public static function get_attr( $html, $name ) {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $html, $m ) ) {
			$value = isset( $m[3] ) && '' !== $m[3] ? $m[3] : $m[2];
			return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		}
		return null;
	}

	/**
	 * Remplace (ou ajoute) un attribut dans la balise ouvrante.
	 */
	public static function set_attr( $html, $name, $value ) {
		$attr    = $name . '="' . esc_attr( $value ) . '"';
		$pattern = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("[^"]*"|\'[^\']*\')/i';
		if ( preg_match( $pattern, $html ) ) {
			return preg_replace( $pattern, ' ' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $attr ), $html, 1 );
		}
		return preg_replace( '/^<(\w+)/', '<$1 ' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $attr ), $html, 1 );
	}

	/**
	 * URL de la copie au format demandé, ou null si elle n'existe pas.
	 *
	 * @param string $url  URL d'un fichier du média.
	 * @param array  $data Données d'optimisation du média.
	 * @param string $format webp|avif.
	 * @return string|null
	 */
	public static function sibling_url( $url, $data, $format ) {
		$parts = explode( '?', $url, 2 );
		$path  = wp_parse_url( $parts[0], PHP_URL_PATH );
		$name  = $path ? rawurldecode( wp_basename( $path ) ) : '';
		if ( '' === $name || empty( $data['files'][ $name ][ $format ] ) ) {
			return null;
		}
		return $parts[0] . '.' . $format . ( isset( $parts[1] ) ? '?' . $parts[1] : '' );
	}

	/**
	 * Liste [url, descripteur] d'un srcset.
	 */
	private static function parse_srcset( $srcset ) {
		$items = array();
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			$bits    = preg_split( '/\s+/', $candidate, 2 );
			$items[] = array( $bits[0], isset( $bits[1] ) ? $bits[1] : '' );
		}
		return $items;
	}

	/**
	 * Convertit les URL d'un srcset vers le format demandé. Null si aucune copie n'existe.
	 */
	private static function map_srcset( $items, $data, $format ) {
		$out    = array();
		$mapped = 0;
		foreach ( $items as $item ) {
			$url = self::sibling_url( $item[0], $data, $format );
			if ( $url ) {
				++$mapped;
			}
			$out[] = trim( ( $url ? $url : $item[0] ) . ' ' . $item[1] );
		}
		return $mapped ? implode( ', ', $out ) : null;
	}

	/**
	 * Transforme une balise <img> pour servir les copies optimisées.
	 *
	 * Mode « picture » : <picture><source avif><source webp><img d'origine></picture>,
	 * chaque navigateur prend le meilleur format qu'il comprend.
	 * Mode « rewrite » : les URL de l'<img> pointent directement vers le WebP.
	 *
	 * @param string $html Balise <img>.
	 * @param int    $id   ID du média.
	 * @return string
	 */
	public static function transform( $html, $id ) {
		if ( ! $id || false !== stripos( $html, self::MARK . '=' ) ) {
			return $html;
		}
		$data = ISP_Optimizer::get_data( $id );
		if ( ! $data ) {
			return $html;
		}
		$src = self::get_attr( $html, 'src' );
		if ( null === $src || '' === $src ) {
			return $html;
		}
		$srcset = self::get_attr( $html, 'srcset' );
		$items  = $srcset ? self::parse_srcset( $srcset ) : array( array( $src, '' ) );

		if ( 'rewrite' === ISP_Settings::get( 'delivery' ) ) {
			$new_src = self::sibling_url( $src, $data, 'webp' );
			if ( ! $new_src ) {
				return $html;
			}
			$html = self::set_attr( $html, 'src', $new_src );
			if ( $srcset ) {
				$html = self::set_attr( $html, 'srcset', self::map_srcset( $items, $data, 'webp' ) );
			}
			return self::set_attr( $html, self::MARK, 'webp' );
		}

		$sizes   = self::get_attr( $html, 'sizes' );
		$sources = '';
		foreach ( array( 'avif', 'webp' ) as $format ) {
			$mapped = self::map_srcset( $items, $data, $format );
			if ( null === $mapped ) {
				continue;
			}
			$sources .= '<source type="image/' . $format . '" srcset="' . esc_attr( $mapped ) . '"'
				. ( $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
		}
		if ( '' === $sources ) {
			return $html;
		}
		// display:contents : la balise <picture> n'existe pas pour la mise en page, le thème
		// continue de styler l'<img> comme avant.
		return '<picture class="isp-picture" style="display:contents">' . $sources . self::set_attr( $html, self::MARK, 'picture' ) . '</picture>';
	}

	/**
	 * Complète un alt vide ou absent avec celui de la médiathèque (ou le titre du média).
	 *
	 * @param string $html Balise <img>.
	 * @param int    $id   ID du média.
	 * @return string
	 */
	public function complete_alt( $html, $id ) {
		$mode = ISP_Settings::get( 'alt_fallback' );
		if ( 'off' === $mode ) {
			return $html;
		}
		$current = self::get_attr( $html, 'alt' );
		if ( null !== $current && '' !== trim( $current ) ) {
			return $html;
		}
		$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt && 'title' === $mode ) {
			$alt = ISP_Alt_Text::humanize( get_the_title( $id ) );
		}
		return '' === $alt ? $html : self::set_attr( $html, 'alt', $alt );
	}
}
