<?php
/**
 * Class AMP_Style_Sanitizer
 *
 * @package AMP
 */

/**
 * Class AMP_Style_Sanitizer
 *
 * Collects inline styles and outputs them in the amp-custom stylesheet.
 */
class AMP_Style_Sanitizer extends AMP_Base_Sanitizer {

	/**
	 * Styles.
	 *
	 * @var string[] List of CSS styles in HTML content of DOMDocument ($this->dom).
	 *
	 * @since 0.4
	 */
	private $styles = array();

	/**
	 * Stylesheets.
	 *
	 * Values are the CSS stylesheets. Keys are MD5 hashes of the stylesheets
	 *
	 * @since 0.7
	 * @var string[]
	 */
	private $stylesheets = array();

	/**
	 * Maximum number of bytes allowed for a keyframes style.
	 *
	 * @since 0.7
	 * @var int
	 */
	private $keyframes_max_size;

	/**
	 * Maximum number of bytes allowed for a AMP Custom style.
	 *
	 * @since 0.7
	 * @var int
	 */
	private $custom_max_size;

	/**
	 * The style[amp-custom] element.
	 *
	 * @var DOMElement
	 */
	private $amp_custom_style_element;

	/**
	 * Regex for allowed font stylesheet URL.
	 *
	 * @var string
	 */
	private $allowed_font_src_regex;

	/**
	 * Base URL for styles.
	 *
	 * Full URL with trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * URL of the content directory.
	 *
	 * @var string
	 */
	private $content_url;

	/**
	 * AMP_Base_Sanitizer constructor.
	 *
	 * @since 0.7
	 *
	 * @param DOMDocument $dom  Represents the HTML document to sanitize.
	 * @param array       $args Args.
	 */
	public function __construct( DOMDocument $dom, array $args = array() ) {
		parent::__construct( $dom, $args );

		$spec_name = 'style[amp-keyframes]';
		foreach ( AMP_Allowed_Tags_Generated::get_allowed_tag( 'style' ) as $spec_rule ) {
			if ( isset( $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) && $spec_name === $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) {
				$this->keyframes_max_size = $spec_rule[ AMP_Rule_Spec::CDATA ]['max_bytes'];
				break;
			}
		}

		$spec_name = 'style amp-custom';
		foreach ( AMP_Allowed_Tags_Generated::get_allowed_tag( 'style' ) as $spec_rule ) {
			if ( isset( $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) && $spec_name === $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) {
				$this->custom_max_size = $spec_rule[ AMP_Rule_Spec::CDATA ]['max_bytes'];
				break;
			}
		}

		$spec_name = 'link rel=stylesheet for fonts'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		foreach ( AMP_Allowed_Tags_Generated::get_allowed_tag( 'link' ) as $spec_rule ) {
			if ( isset( $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) && $spec_name === $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) {
				$this->allowed_font_src_regex = '@^(' . $spec_rule[ AMP_Rule_Spec::ATTR_SPEC_LIST ]['href']['value_regex'] . ')$@';
				break;
			}
		}

		$guessurl = site_url();
		if ( ! $guessurl ) {
			$guessurl = wp_guess_url();
		}
		$this->base_url    = $guessurl;
		$this->content_url = WP_CONTENT_URL;
	}

	/**
	 * Get list of CSS styles in HTML content of DOMDocument ($this->dom).
	 *
	 * @since 0.4
	 *
	 * @return string[] Mapping CSS selectors to array of properties, or mapping of keys starting with 'stylesheet:' with value being the stylesheet.
	 */
	public function get_styles() {
		if ( ! $this->did_convert_elements ) {
			return array();
		}
		return $this->styles;
	}

	/**
	 * Get stylesheets.
	 *
	 * @since 0.7
	 * @returns array Values are the CSS stylesheets. Keys are MD5 hashes of the stylesheets.
	 */
	public function get_stylesheets() {
		return array_merge( $this->stylesheets, parent::get_stylesheets() );
	}

	/**
	 * Sanitize CSS styles within the HTML contained in this instance's DOMDocument.
	 *
	 * @since 0.4
	 */
	public function sanitize() {
		$elements = array();

		/*
		 * Note that xpath is used to query the DOM so that the link and style elements will be
		 * in document order. DOMNode::compareDocumentPosition() is not yet implemented.
		 */
		$xpath = new DOMXPath( $this->dom );

		$lower_case = 'translate( %s, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz" )'; // In XPath 2.0 this is lower-case().
		$predicates = array(
			sprintf( '( self::style and not( @amp-boilerplate ) and ( not( @type ) or %s = "text/css" ) )', sprintf( $lower_case, '@type' ) ),
			sprintf( '( self::link and @href and %s = "stylesheet" )', sprintf( $lower_case, '@rel' ) ),
		);

		foreach ( $xpath->query( '//*[ ' . implode( ' or ', $predicates ) . ' ]' ) as $element ) {
			$elements[] = $element;
		}

		/**
		 * Element.
		 *
		 * @var DOMElement $element
		 */
		foreach ( $elements as $element ) {
			$node_name = strtolower( $element->nodeName );
			if ( 'style' === $node_name ) {
				$this->process_style_element( $element );
			} elseif ( 'link' === $node_name ) {
				$this->process_link_element( $element );
			}
		}

		$elements = array();
		foreach ( $xpath->query( '//*[ @style ]' ) as $element ) {
			$elements[] = $element;
		}
		foreach ( $elements as $element ) {
			$this->collect_inline_styles( $element );
		}
		$this->did_convert_elements = true;

		// Now make sure the amp-custom style is in the DOM and populated, if we're working with the document element.
		if ( ! empty( $this->args['use_document_element'] ) ) {
			if ( ! $this->amp_custom_style_element ) {
				$this->amp_custom_style_element = $this->dom->createElement( 'style' );
				$this->amp_custom_style_element->setAttribute( 'amp-custom', '' );
				$head = $this->dom->getElementsByTagName( 'head' )->item( 0 );
				if ( ! $head ) {
					$head = $this->dom->createElement( 'head' );
					$this->dom->documentElement->insertBefore( $head, $this->dom->documentElement->firstChild );
				}
				$head->appendChild( $this->amp_custom_style_element );
			}

			// Gather stylesheets to print as long as they don't surpass the limit.
			$skipped    = array();
			$css        = '';
			$total_size = 0;
			foreach ( $this->get_stylesheets() as $key => $stylesheet ) {
				$sheet_size = strlen( $stylesheet );
				if ( $total_size + $sheet_size > $this->custom_max_size ) {
					$skipped[] = $key;
				} else {
					if ( $total_size ) {
						$css .= ' ';
					}
					$css        .= $stylesheet;
					$total_size += $sheet_size;
				}
			}

			/*
			 * Let the style[amp-custom] be populated with the concatenated CSS.
			 * !important: Updating the contents of this style element by setting textContent is not
			 * reliable across PHP/libxml versions, so this is why the children are removed and the
			 * text node is then explicitly added containing the CSS.
			 */
			while ( $this->amp_custom_style_element->firstChild ) {
				$this->amp_custom_style_element->removeChild( $this->amp_custom_style_element->firstChild );
			}
			$this->amp_custom_style_element->appendChild( $this->dom->createTextNode( $css ) );

			// @todo This would be a candidate for sanitization reporting.
			// Add comments to indicate which sheets were not included.
			foreach ( array_reverse( $skipped ) as $skip ) {
				$this->amp_custom_style_element->parentNode->insertBefore(
					$this->dom->createComment( sprintf( 'Skipped including %s stylesheet since too large.', $skip ) ),
					$this->amp_custom_style_element->nextSibling
				);
			}
		}
	}

	/**
	 * Generates an enqueued style's fully-qualified file path.
	 *
	 * @since 0.7
	 * @see WP_Styles::_css_href()
	 *
	 * @param string $src The source URL of the enqueued style.
	 * @return string|WP_Error Style's absolute validated filesystem path, or WP_Error when error.
	 */
	public function get_validated_css_file_path( $src ) {
		$needs_base_url = (
			! is_bool( $src )
			&&
			! preg_match( '|^(https?:)?//|', $src )
			&&
			! ( $this->content_url && 0 === strpos( $src, $this->content_url ) )
		);
		if ( $needs_base_url ) {
			$src = $this->base_url . $src;
		}

		// Strip query and fragment from URL.
		$src = preg_replace( ':[\?#].*$:', '', $src );

		if ( ! preg_match( '/\.(css|less|scss|sass)$/i', $src ) ) {
			/* translators: %s is stylesheet URL */
			return new WP_Error( 'amp_css_bad_file_extension', sprintf( __( 'Skipped stylesheet which does not have recognized CSS file extension (%s).', 'amp' ), $src ) );
		}

		$includes_url = includes_url( '/' );
		$content_url  = content_url( '/' );
		$admin_url    = get_admin_url( null, '/' );
		$css_path     = null;
		if ( 0 === strpos( $src, $content_url ) ) {
			$css_path = WP_CONTENT_DIR . substr( $src, strlen( $content_url ) - 1 );
		} elseif ( 0 === strpos( $src, $includes_url ) ) {
			$css_path = ABSPATH . WPINC . substr( $src, strlen( $includes_url ) - 1 );
		} elseif ( 0 === strpos( $src, $admin_url ) ) {
			$css_path = ABSPATH . 'wp-admin' . substr( $src, strlen( $admin_url ) - 1 );
		}

		if ( ! $css_path || false !== strpos( '../', $css_path ) || 0 !== validate_file( $css_path ) || ! file_exists( $css_path ) ) {
			/* translators: %s is stylesheet URL */
			return new WP_Error( 'amp_css_path_not_found', sprintf( __( 'Unable to locate filesystem path for stylesheet %s.', 'amp' ), $src ) );
		}

		return $css_path;
	}

	/**
	 * Process style element.
	 *
	 * @param DOMElement $element Style element.
	 */
	private function process_style_element( DOMElement $element ) {
		if ( 'body' === $element->parentNode->nodeName && $element->hasAttribute( 'amp-keyframes' ) ) {
			$validity = $this->validate_amp_keyframe( $element );
			if ( true !== $validity ) {
				$element->parentNode->removeChild( $element ); // @todo Add reporting.
			}
			return;
		}

		$rules = trim( $element->textContent );
		$rules = $this->remove_illegal_css( $rules );

		$this->stylesheets[ md5( $rules ) ] = $rules;

		if ( $element->hasAttribute( 'amp-custom' ) ) {
			if ( ! $this->amp_custom_style_element ) {
				$this->amp_custom_style_element = $element;
			} else {
				$element->parentNode->removeChild( $element ); // There can only be one. #highlander.
			}
		} else {

			// Remove from DOM since we'll be adding it to amp-custom.
			$element->parentNode->removeChild( $element );
		}
	}

	/**
	 * Process link element.
	 *
	 * @param DOMElement $element Link element.
	 */
	private function process_link_element( DOMElement $element ) {
		$href = $element->getAttribute( 'href' );

		// Allow font URLs.
		if ( $this->allowed_font_src_regex && preg_match( $this->allowed_font_src_regex, $href ) ) {
			return;
		}

		$css_file_path = $this->get_validated_css_file_path( $href );
		if ( is_wp_error( $css_file_path ) ) {
			$element->parentNode->removeChild( $element ); // @todo Report removal. Show HTML comment?
			return;
		}

		// Load the CSS from the filesystem.
		$css  = "\n/* $href */\n";
		$css .= file_get_contents( $css_file_path ); // phpcs:ignore -- It's a local filesystem path not a remote request.

		$css = $this->remove_illegal_css( $css );

		$media = $element->getAttribute( 'media' );
		if ( $media && 'all' !== $media ) {
			$css = sprintf( '@media %s { %s }', $media, $css );
		}

		$this->stylesheets[ $href ] = $css;

		// Remove now that styles have been processed.
		$element->parentNode->removeChild( $element );
	}

	/**
	 * Remove illegal CSS from the stylesheet.
	 *
	 * @since 0.7
	 *
	 * @todo This needs proper CSS parser and to take an alternative approach to removing !important by extracting
	 * the rule into a separate style rule with a very specific selector.
	 * @param string $stylesheet Stylesheet.
	 * @return string Scrubbed stylesheet.
	 */
	private function remove_illegal_css( $stylesheet ) {
		$stylesheet = preg_replace( '/\s*!important/', '', $stylesheet ); // Note this has to also replace inside comments to be valid.
		$stylesheet = preg_replace( '/overflow\s*:\s*(auto|scroll)\s*;?\s*/', '', $stylesheet );
		return $stylesheet;
	}

	/**
	 * Validate amp-keyframe style.
	 *
	 * @since 0.7
	 * @link https://github.com/ampproject/amphtml/blob/b685a0780a7f59313666225478b2b79b463bcd0b/validator/validator-main.protoascii#L1002-L1043
	 *
	 * @param DOMElement $style Style element.
	 * @return true|WP_Error Validity.
	 */
	private function validate_amp_keyframe( $style ) {
		if ( $this->keyframes_max_size && strlen( $style->textContent ) > $this->keyframes_max_size ) {
			return new WP_Error( 'max_bytes' );
		}

		// This logic could be in AMP_Tag_And_Attribute_Sanitizer, but since it only applies to amp-keyframes it seems unnecessary.
		$next_sibling = $style->nextSibling;
		while ( $next_sibling ) {
			if ( $next_sibling instanceof DOMElement ) {
				return new WP_Error( 'mandatory_last_child' );
			}
			$next_sibling = $next_sibling->nextSibling;
		}

		// @todo Also add validation of the CSS spec itself.
		return true;
	}

	/**
	 * Collect and store all CSS style attributes.
	 *
	 * Collects the CSS styles from within the HTML contained in this instance's DOMDocument.
	 *
	 * @see Retrieve array of styles using $this->get_styles() after calling this method.
	 *
	 * @since 0.4
	 * @since 0.7 Modified to use element passed by XPath query.
	 *
	 * @note Uses recursion to traverse down the tree of DOMDocument nodes.
	 *
	 * @param DOMElement $element Node.
	 */
	private function collect_inline_styles( $element ) {
		$style = $element->getAttribute( 'style' );
		if ( ! $style ) {
			return;
		}
		$class = $element->getAttribute( 'class' );

		$style = $this->process_style( $style );
		if ( ! empty( $style ) ) {
			$class_name = $this->generate_class_name( $style );
			$new_class  = trim( $class . ' ' . $class_name );

			$element->setAttribute( 'class', $new_class );
			$this->styles[ '.' . $class_name ] = $style;
		}
		$element->removeAttribute( 'style' );
	}

	/**
	 * Sanitize and convert individual styles.
	 *
	 * @since 0.4
	 *
	 * @param string $string Style string.
	 * @return array
	 */
	private function process_style( $string ) {

		/**
		 * Filter properties
		 */
		$string = safecss_filter_attr( esc_html( $string ) );

		if ( ! $string ) {
			return array();
		}

		/*
		 * safecss returns a string but we want individual rules.
		 * Use preg_split to break up rules by `;` but only if the
		 * semi-colon is not inside parens (like a data-encoded image).
		 */
		$styles = array_map( 'trim', preg_split( '/;(?![^(]*\))/', $string ) );

		// Normalize the order of the styles.
		sort( $styles );

		$processed_styles = array();

		// Normalize whitespace and filter rules.
		foreach ( $styles as $index => $rule ) {
			$arr2 = array_map( 'trim', explode( ':', $rule, 2 ) );
			if ( 2 !== count( $arr2 ) ) {
				continue;
			}

			list( $property, $value ) = $this->filter_style( $arr2[0], $arr2[1] );
			if ( empty( $property ) || empty( $value ) ) {
				continue;
			}

			$processed_styles[ $index ] = "{$property}:{$value}";
		}

		return $processed_styles;
	}

	/**
	 * Filter individual CSS name/value pairs.
	 *
	 *   - Remove overflow if value is `auto` or `scroll`
	 *   - Change `width` to `max-width`
	 *   - Remove !important
	 *
	 * @since 0.4
	 *
	 * @param string $property Property.
	 * @param string $value    Value.
	 * @return array
	 */
	private function filter_style( $property, $value ) {

		/**
		 * Remove overflow if value is `auto` or `scroll`; not allowed in AMP
		 *
		 * @todo This removal needs to be reported.
		 * @see https://www.ampproject.org/docs/reference/spec.html#properties
		 */
		if ( preg_match( '#^overflow#i', $property ) && preg_match( '#^(auto|scroll)$#i', $value ) ) {
			return array( false, false );
		}

		if ( 'width' === $property ) {
			$property = 'max-width';
		}

		/**
		 * Remove `!important`; not allowed in AMP
		 *
		 * @todo This removal needs to be reported.
		 */
		if ( false !== strpos( $value, 'important' ) ) {
			$value = preg_replace( '/\s*\!\s*important$/', '', $value );
		}

		return array( $property, $value );
	}

	/**
	 * Generate a unique class name
	 *
	 * Use the md5() of the $data parameter
	 *
	 * @since 0.4
	 *
	 * @param string $data Data.
	 * @return string Class name.
	 */
	private function generate_class_name( $data ) {
		$string = maybe_serialize( $data );
		return 'amp-wp-inline-' . md5( $string );
	}
}
