<?php
/**
 * AI answer formatter.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

/**
 * Turns a chat model's plain-text answer into safe HTML.
 *
 * The text is escaped first, then a small Markdown subset is applied:
 * paragraphs, "- " / "1. " lists, **bold**, `code`, headings (shown as bold
 * paragraphs), and citations like [1] or [1, 2] linked to the matching
 * source. The result is passed through wp_kses() with a short allow-list,
 * so model output can never inject markup or scripts.
 *
 * @package WP_AI_Smart_Search
 */
final class Answer_Formatter {

	/**
	 * Convert an answer to HTML.
	 *
	 * @param string $text    Answer text from the chat model.
	 * @param array  $sources List of array{n: int, url: string, title: string}.
	 * @return string Safe HTML.
	 */
	public static function to_html( string $text, array $sources ): string {
		$urls = array();

		foreach ( $sources as $source ) {
			$urls[ (int) $source['n'] ] = (string) $source['url'];
		}

		$lines     = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", trim( $text ) ) );
		$html      = array();
		$paragraph = array();
		$list_type = '';
		$items     = array();

		$flush_paragraph = function () use ( &$html, &$paragraph ): void {
			if ( $paragraph !== array() ) {
				$html[]    = '<p>' . implode( '<br>', $paragraph ) . '</p>';
				$paragraph = array();
			}
		};

		$flush_list = function () use ( &$html, &$list_type, &$items ): void {
			if ( $list_type !== '' ) {
				$html[]    = "<{$list_type}><li>" . implode( '</li><li>', $items ) . "</li></{$list_type}>";
				$list_type = '';
				$items     = array();
			}
		};

		foreach ( $lines as $line ) {
			if ( trim( $line ) === '' ) {
				// A blank line ends a paragraph; lists continue across blank lines.
				$flush_paragraph();
				continue;
			}

			if ( preg_match( '/^\s*[-*•]\s+(.*)$/u', $line, $match ) ) {
				$flush_paragraph();
				if ( $list_type !== 'ul' ) {
					$flush_list();
					$list_type = 'ul';
				}
				$items[] = self::inline( $match[1], $urls );
			} elseif ( preg_match( '/^\s*\d+[.)]\s+(.*)$/u', $line, $match ) ) {
				$flush_paragraph();
				if ( $list_type !== 'ol' ) {
					$flush_list();
					$list_type = 'ol';
				}
				$items[] = self::inline( $match[1], $urls );
			} elseif ( preg_match( '/^\s*#{1,6}\s+(.*)$/u', $line, $match ) ) {
				$flush_paragraph();
				$flush_list();
				$html[] = '<p><strong>' . self::inline( $match[1], $urls ) . '</strong></p>';
			} else {
				$flush_list();
				$paragraph[] = self::inline( $line, $urls );
			}
		}

		$flush_paragraph();
		$flush_list();

		return wp_kses(
			implode( "\n", $html ),
			array(
				'p'      => array(),
				'br'     => array(),
				'strong' => array(),
				'code'   => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'a'      => array(
					'href'  => true,
					'class' => true,
				),
			)
		);
	}

	/**
	 * Escape one line and apply inline formatting and citation links.
	 *
	 * @param string             $text Raw text.
	 * @param array<int, string> $urls Source URLs keyed by citation number.
	 * @return string
	 */
	private static function inline( string $text, array $urls ): string {
		$text = esc_html( trim( $text ) );
		$text = (string) preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text );
		$text = (string) preg_replace( '/`([^`]+)`/u', '<code>$1</code>', $text );

		// [1] or [1, 2]: link each number that matches a source; leave others as text.
		return (string) preg_replace_callback(
			'/\[(\d+(?:\s*,\s*\d+)*)\]/',
			function ( array $citation ) use ( $urls ): string {
				$numbers = array_map( 'intval', preg_split( '/\s*,\s*/', $citation[1] ) );
				$links   = array_map(
					fn( int $n ): string => isset( $urls[ $n ] )
						? sprintf( '<a class="wpaismse-cite" href="%s">%d</a>', esc_url( $urls[ $n ] ), $n )
						: (string) $n,
					$numbers
				);

				return '[' . implode( ', ', $links ) . ']';
			},
			$text
		);
	}
}
