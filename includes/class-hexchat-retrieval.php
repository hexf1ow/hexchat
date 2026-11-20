<?php
/**
 * Retrieval over the site's own content.
 *
 * v0.1 is deliberately dependency-free: no embeddings, no vector store, no
 * external service. Published pages and posts are split into passages and
 * scored against the question with a small BM25-style ranking. That is enough
 * to answer "what does it cost" or "where are you" from the real page text,
 * and it keeps the plugin honest on cheap shared hosting.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Retrieval
 */
class HexChat_Retrieval {

	/**
	 * Cache key for the passage index.
	 */
	const CACHE_KEY = 'hexchat_index_v1';

	/**
	 * Words that add nothing to a match.
	 *
	 * @return array
	 */
	private static function stopwords() {
		return array(
			'a', 'about', 'after', 'all', 'also', 'am', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'been', 'but', 'by',
			'can', 'could', 'did', 'do', 'does', 'for', 'from', 'get', 'got', 'had', 'has', 'have', 'he', 'her', 'here', 'him',
			'his', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'just', 'me', 'more', 'my', 'no', 'not', 'of', 'on',
			'or', 'our', 'out', 'please', 'so', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this',
			'to', 'up', 'us', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'would', 'you', 'your',
		);
	}

	/**
	 * Split text into lowercase terms.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	private static function terms( $text ) {
		$text = strtolower( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/[^a-z0-9\s\-\']/u', ' ', $text );
		$raw  = preg_split( '/\s+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		$stop = array_flip( self::stopwords() );
		$out  = array();

		foreach ( (array) $raw as $word ) {
			$word = trim( $word, "-' " );
			if ( strlen( $word ) < 2 || isset( $stop[ $word ] ) ) {
				continue;
			}
			$out[] = $word;
		}

		return $out;
	}

	/**
	 * Build or fetch the passage index.
	 *
	 * @return array
	 */
	public static function index() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$types = (array) hexchat_opt( 'index_types' );
		$types = array_values( array_intersect( array_map( 'sanitize_key', $types ), array( 'page', 'post' ) ) );
		if ( empty( $types ) ) {
			$types = array( 'page' );
		}

		$query = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => 200,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		$passages = array();

		foreach ( $query->posts as $post ) {
			$body   = preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
			$body   = trim( (string) $body );
			$chunks = array();

			if ( '' !== $body ) {
				$length = strlen( $body );
				$step   = 900;
				for ( $offset = 0; $offset < $length; $offset += $step ) {
					$piece = trim( substr( $body, $offset, 1100 ) );
					if ( strlen( $piece ) > 80 ) {
						$chunks[] = $piece;
					}
				}
			}

			if ( empty( $chunks ) ) {
				$chunks[] = trim( (string) $post->post_excerpt );
			}

			foreach ( $chunks as $i => $chunk ) {
				if ( '' === $chunk ) {
					continue;
				}
				$passages[] = array(
					'id'    => $post->ID . '-' . $i,
					'title' => get_the_title( $post ),
					'url'   => get_permalink( $post ),
					'text'  => $chunk,
					'terms' => array_count_values( self::terms( $chunk ) ),
					'len'   => max( 1, count( self::terms( $chunk ) ) ),
				);
			}
		}

		set_transient( self::CACHE_KEY, $passages, HOUR_IN_SECONDS * 6 );

		return $passages;
	}

	/**
	 * Drop the cache — called when a post is saved.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Best passages for a question.
	 *
	 * @param string $question Visitor question.
	 * @param int    $limit    Passages to return.
	 * @return array
	 */
	public static function search( $question, $limit = 4 ) {
		$passages = self::index();
		if ( empty( $passages ) ) {
			return array();
		}

		$q_terms = array_count_values( self::terms( $question ) );
		if ( empty( $q_terms ) ) {
			return array();
		}

		$total   = count( $passages );
		$lengths = array_sum( wp_list_pluck( $passages, 'len' ) );
		$avg_len = $total ? ( $lengths / $total ) : 1;
		$k1      = 1.4;
		$b       = 0.75;
		$scored  = array();

		foreach ( $passages as $passage ) {
			$score = 0.0;

			foreach ( $q_terms as $term => $weight ) {
				if ( empty( $passage['terms'][ $term ] ) ) {
					continue;
				}

				$tf  = $passage['terms'][ $term ];
				$idf = log( 1 + ( $total / ( 1 + $tf ) ) );
				$score += $weight * $idf * ( ( $tf * ( $k1 + 1 ) ) / ( $tf + $k1 * ( 1 - $b + $b * ( $passage['len'] / $avg_len ) ) ) );
			}

			if ( $score > 0 ) {
				$scored[] = array( 'score' => $score ) + $passage;
			}
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Build the context block handed to the model.
	 *
	 * @param array $passages Scored passages.
	 * @param int   $budget   Character budget.
	 * @return array array( context string, sources array )
	 */
	public static function context( array $passages, $budget = 3500 ) {
		$context = '';
		$sources = array();

		foreach ( $passages as $passage ) {
			$block = "### " . $passage['title'] . "\n" . $passage['text'] . "\n\n";
			if ( strlen( $context ) + strlen( $block ) > $budget ) {
				break;
			}
			$context .= $block;
			$sources[] = array(
				'title' => $passage['title'],
				'url'   => $passage['url'],
			);
		}

		return array( $context, $sources );
	}
}

/**
 * Refresh the index when content changes.
 */
add_action( 'save_post', array( 'HexChat_Retrieval', 'flush' ) );
add_action( 'deleted_post', array( 'HexChat_Retrieval', 'flush' ) );
