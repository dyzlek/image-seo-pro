<?php
/**
 * Commandes WP-CLI : wp isp optimize | restore | stats | alt
 *
 * @package ImageSeoPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Optimise les images et complète les textes alternatifs depuis la ligne de commande.
 */
class ISP_CLI {

	/**
	 * Crée les copies WebP / AVIF.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : IDs des médias. Sans ID : toutes les images pas encore optimisées.
	 *
	 * [--force]
	 * : Refaire aussi les images déjà optimisées.
	 *
	 * ## EXAMPLES
	 *
	 *     wp isp optimize
	 *     wp isp optimize 42 43 --force
	 *
	 * @param array $args       IDs.
	 * @param array $assoc_args Options.
	 */
	public function optimize( $args, $assoc_args ) {
		$ids = $args ? array_map( 'absint', $args ) : ISP_Optimizer::pending_ids( ! empty( $assoc_args['force'] ) );
		if ( ! $ids ) {
			WP_CLI::success( 'Rien à optimiser.' );
			return;
		}
		$saved    = 0;
		$failed   = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Optimisation', count( $ids ) );
		foreach ( $ids as $id ) {
			$result = ISP_Optimizer::optimize( $id );
			if ( is_wp_error( $result ) ) {
				++$failed;
				WP_CLI::warning( "#$id : " . $result->get_error_message() );
			} else {
				$saved += ISP_Optimizer::summarize( $result )['saved'];
			}
			$progress->tick();
		}
		$progress->finish();
		WP_CLI::success( sprintf( '%d image(s) traitée(s), %d échec(s), %s gagnés.', count( $ids ) - $failed, $failed, size_format( $saved, 1 ) ) );
	}

	/**
	 * Supprime les copies WebP / AVIF (les originaux ne sont jamais modifiés).
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : IDs des médias.
	 *
	 * [--all]
	 * : Toutes les images optimisées.
	 *
	 * @param array $args       IDs.
	 * @param array $assoc_args Options.
	 */
	public function restore( $args, $assoc_args ) {
		if ( ! $args && empty( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Donnez des IDs ou --all.' );
		}
		$ids = $args ? array_map( 'absint', $args ) : array_values( array_diff( ISP_Optimizer::pending_ids( true ), ISP_Optimizer::pending_ids() ) );
		foreach ( $ids as $id ) {
			ISP_Optimizer::restore( $id );
		}
		WP_CLI::success( sprintf( '%d image(s) restaurée(s).', count( $ids ) ) );
	}

	/**
	 * Affiche les statistiques.
	 *
	 * [--format=<format>]
	 * : table, json…
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Inutilisé.
	 * @param array $assoc_args Options.
	 */
	public function stats( $args, $assoc_args ) {
		$stats                 = ISP_Optimizer::stats();
		$stats['missing_alt']  = ISP_Alt_Text::count_missing();
		$stats['formats']      = implode( ',', ISP_Settings::active_formats() );
		\WP_CLI\Utils\format_items( $assoc_args['format'], array( $stats ), array_keys( $stats ) );
	}

	/**
	 * Complète les textes alternatifs manquants.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : D'où vient le texte.
	 * ---
	 * default: filename
	 * options:
	 *   - filename
	 *   - ai
	 * ---
	 *
	 * [--dry-run]
	 * : Affiche les suggestions sans les enregistrer.
	 *
	 * @param array $args       Inutilisé.
	 * @param array $assoc_args Options.
	 */
	public function alt( $args, $assoc_args ) {
		$dry   = ! empty( $assoc_args['dry-run'] );
		$ai    = 'ai' === $assoc_args['source'];
		$ids   = ISP_Alt_Text::missing_ids();
		$saved = 0;
		foreach ( $ids as $id ) {
			$alt = $ai ? ISP_AI_Client::describe( $id ) : ISP_Alt_Text::suggest_from_filename( $id );
			if ( is_wp_error( $alt ) ) {
				WP_CLI::warning( "#$id : " . $alt->get_error_message() );
				continue;
			}
			if ( '' === $alt ) {
				WP_CLI::log( "#$id : aucune suggestion" );
				continue;
			}
			WP_CLI::log( "#$id : $alt" );
			if ( ! $dry ) {
				ISP_Alt_Text::save( $id, $alt );
				++$saved;
			}
		}
		WP_CLI::success( $dry ? sprintf( '%d image(s) sans alt (simulation).', count( $ids ) ) : sprintf( '%d texte(s) alternatif(s) enregistré(s).', $saved ) );
	}
}
