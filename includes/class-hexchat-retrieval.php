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
			'hi', 'hey', 'hello', 'guys', 'folks', 'mate', 'sir', 'there', 'ok', 'okay', 'yes', 'yeah', 'nah', 'thanks', 'thank',
			'want', 'wanna', 'need', 'needed', 'know', 'tell', 'ask', 'asking', 'say', 'said', 'thing', 'things', 'kind', 'kinda',
		);
	}

	/**
	 * Crude stemmer. Deliberately tiny: it exists so "prices" matches "pricing"
	 * and "reports" matches "reporting", which is the difference between the
	 * chat finding the pricing page and telling the visitor it knows nothing.
	 *
	 * @param string $word Single lowercase word.
	 * @return string
	 */
	private static function stem( $word ) {
		$word = (string) $word;

		if ( strlen( $word ) <= 4 ) {
			return $word;
		}

		foreach ( array( 'ing', 'ies', 'ied', 'ed', 'es', 's' ) as $suffix ) {
			$len = strlen( $suffix );
			if ( substr( $word, -$len ) === $suffix ) {
				$cut = substr( $word, 0, -$len );
				if ( strlen( $cut ) >= 4 ) {
					return $cut;
				}
			}
		}

		return $word;
	}

	/**
	 * Split text into lowercase terms.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	public static function terms( $text ) {
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
			$out[] = self::stem( $word );
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
	 * Every word the site actually uses, as a lookup set.
	 *
	 * @return array
	 */
	public static function vocabulary() {
		$cached = get_transient( 'hexchat_vocab_v2' );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$vocab = array();

		foreach ( self::index() as $passage ) {
			foreach ( array_keys( (array) $passage['terms'] ) as $term ) {
				$vocab[ $term ] = true;
			}
		}

		set_transient( 'hexchat_vocab_v2', $vocab, HOUR_IN_SECONDS * 6 );

		return $vocab;
	}

	/**
	 * How much of the question's vocabulary the site shares.
	 *
	 * Zero means the visitor is asking about something this site never mentions —
	 * a genuine off-site question. Anything above zero means the subject exists
	 * somewhere on the site, so it is safe to look for it.
	 *
	 * @param array $terms Question terms.
	 * @return int
	 */
	public static function coverage( array $terms ) {
		$vocab = self::vocabulary();
		$hits  = 0;

		foreach ( $terms as $term ) {
			if ( isset( $vocab[ $term ] ) ) {
				$hits++;
			}
		}

		return $hits;
	}

	/**
	 * The site's own introduction: home, services, solutions, about.
	 *
	 * Used when a visitor asks something as broad as "what do you guys do" —
	 * a question with no searchable keywords but an obvious answer on the
	 * homepage. This is still site content, so the wall applies afterwards.
	 *
	 * @param int $limit Passages to return.
	 * @return array
	 */
	public static function overview( $limit = 3 ) {
		$passages = self::index();
		$picked   = array();
		$home     = untrailingslashit( home_url() );

		foreach ( $passages as $passage ) {
			$url   = (string) $passage['url'];
			$title = strtolower( (string) $passage['title'] );
			$is_home = ( untrailingslashit( $url ) === $home || untrailingslashit( $url ) === $home . '/home' );

			if ( $is_home || preg_match( '/service|solution|about|what we do|agent|industr/i', $title ) ) {
				$picked[] = array( 'score' => $is_home ? 2 : 1 ) + $passage;
				if ( count( $picked ) >= max( 1, (int) $limit ) ) {
					break;
				}
			}
		}

		// No title matched and no obvious homepage: any real content beats a dead end.
		if ( empty( $picked ) ) {
			foreach ( $passages as $passage ) {
				$picked[] = array( 'score' => 0.5 ) + $passage;
				if ( count( $picked ) >= max( 1, (int) $limit ) ) {
					break;
				}
			}
		}

		return $picked;
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

		$q_terms   = array_count_values( self::terms( $question ) );
		$money_q   = (bool) preg_match( '/pric|cost|charg|fee|rate|quote|plan/i', $question );

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

			// A page titled "Pricing" must beat a blog post that happens to say
			// "price" twice. Titles are the author's own summary of the page.
			$title_terms = array_count_values( self::terms( (string) $passage['title'] ) );

			foreach ( $title_terms as $term => $count ) {
				if ( isset( $q_terms[ $term ] ) ) {
					$score += 2.5 * $count * $q_terms[ $term ];
				}
			}

			foreach ( $q_terms as $term => $weight ) {
				if ( empty( $passage['terms'][ $term ] ) ) {
					continue;
				}

				$tf  = $passage['terms'][ $term ];
				$idf = log( 1 + ( $total / ( 1 + $tf ) ) );
				$score += $weight * $idf * ( ( $tf * ( $k1 + 1 ) ) / ( $tf + $k1 * ( 1 - $b + $b * ( $passage['len'] / $avg_len ) ) ) );
			}

			// Money questions need the passage that actually carries the figures.
			if ( $money_q && preg_match( '/\$\s?\d/', (string) $passage['text'] ) ) {
				$score += 2.0;
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
	 * Does this reply actually draw on the passages we retrieved?
	 *
	 * Each content word in the answer is looked up in the retrieved text plus the
	 * question and the system-level vocabulary. If too many words are foreign to
	 * everything the site says, the answer came from the model's own knowledge
	 * and must not reach the visitor.
	 *
	 * @param string $answer   Model reply.
	 * @param array  $passages Scored passages used for the reply.
	 * @param float  $min_ratio Minimum share of answer words found in the sources.
	 * @return bool
	 */
	public static function is_grounded( $answer, array $passages, $min_ratio = 0.55 ) {
		$answer = trim( wp_strip_all_tags( (string) $answer ) );
		if ( '' === $answer ) {
			return false;
		}

		$vocab = array();

		foreach ( $passages as $passage ) {
			foreach ( array_keys( (array) $passage['terms'] ) as $term ) {
				$vocab[ $term ] = true;
			}
		}

		// Words a grounded answer may legitimately add: courtesy, connectives, names of the site itself.
		$allowed = array_flip(
			array_merge(
				self::stopwords(),
				array(
					'yes', 'no', 'okay', 'ok', 'sure', 'thanks', 'thank', 'happy', 'help', 'helps', 'happy', 'sorry',
					'hex', 'flow', 'chat', 'assistant', 'sorry', 'please', 'team', 'person', 'someone', 'question',
					'answer', 'information', 'details', 'site', 'website', 'page', 'contact', 'email', 'call',
					'offer', 'offers', 'offerings', 'provide', 'provides', 'service', 'services', 'work', 'works',
				)
			)
		);

		$words = self::terms( $answer );
		if ( empty( $words ) ) {
			return false;
		}

		$hits  = 0;
		$total = 0;

		foreach ( $words as $word ) {
			$total++;
			if ( isset( $vocab[ $word ] ) || isset( $allowed[ $word ] ) ) {
				$hits++;
				continue;
			}

			// Allow a simple plural/verb-form difference: report/reporting, price/prices.
			$stem = preg_replace( '/(ing|ed|es|s)$/', '', $word );
			if ( strlen( $stem ) > 3 ) {
				foreach ( array_keys( $vocab ) as $known ) {
					if ( $known === $stem || 0 === strpos( $known, $stem ) ) {
						$hits++;
						break;
					}
				}
			}
		}

		if ( 0 === $total ) {
			return false;
		}

		return ( $hits / $total ) >= (float) $min_ratio;
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
