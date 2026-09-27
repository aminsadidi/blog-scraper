<?php
/**
 * Small, escaped form-field helpers for the admin screens.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

defined( 'ABSPATH' ) || exit;

class Form {

	public static function text( $name, $value, array $attrs = array() ) {
		printf( '<input type="text" name="%s" value="%s"%s>', esc_attr( $name ), esc_attr( $value ), self::attrs( $attrs + array( 'class' => 'regular-text' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- attrs() escapes.
	}

	public static function url( $name, $value, array $attrs = array() ) {
		printf( '<input type="url" name="%s" value="%s" dir="ltr"%s>', esc_attr( $name ), esc_attr( $value ), self::attrs( $attrs + array( 'class' => 'large-text' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- attrs() escapes.
	}

	public static function number( $name, $value, $min = 0, $max = null, array $attrs = array() ) {
		$attrs['min'] = $min;
		if ( null !== $max ) {
			$attrs['max'] = $max;
		}
		printf( '<input type="number" name="%s" value="%s"%s>', esc_attr( $name ), esc_attr( $value ), self::attrs( $attrs + array( 'class' => 'small-text' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- attrs() escapes.
	}

	public static function textarea( $name, $value, $rows = 4, array $attrs = array() ) {
		printf( '<textarea name="%s" rows="%d"%s>%s</textarea>', esc_attr( $name ), (int) $rows, self::attrs( $attrs + array( 'class' => 'large-text' ) ), esc_textarea( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- attrs() escapes.
	}

	public static function checkbox( $name, $checked, $label ) {
		printf(
			'<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
			esc_attr( $name ),
			checked( (bool) $checked, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * @param array $options value => label.
	 */
	public static function select( $name, $value, array $options, array $attrs = array() ) {
		printf( '<select name="%s"%s>', esc_attr( $name ), self::attrs( $attrs ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- attrs() escapes.
		foreach ( $options as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( (string) $value, (string) $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * @param array $options value => [label, description].
	 */
	public static function radios( $name, $value, array $options ) {
		echo '<fieldset class="pnr-radios">';
		foreach ( $options as $key => $opt ) {
			$opt = (array) $opt;
			printf(
				'<label><input type="radio" name="%s" value="%s"%s> <strong>%s</strong>%s</label>',
				esc_attr( $name ),
				esc_attr( $key ),
				checked( (string) $value, (string) $key, false ),
				esc_html( $opt[0] ),
				! empty( $opt[1] ) ? '<span class="description">' . esc_html( $opt[1] ) . '</span>' : ''
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Category dropdown (hierarchical). $only limits the choice to these term ids.
	 */
	public static function category_select( $name, $value, $none_label = '— انتخاب کنید —', array $only = array(), array $attrs = array() ) {
		$options = array( '0' => $none_label );
		foreach ( self::categories( $only ) as $term ) {
			$options[ $term->term_id ] = str_repeat( '— ', $term->depth ) . $term->name;
		}
		self::select( $name, $value, $options, $attrs );
	}

	/**
	 * Checkbox list of categories.
	 */
	public static function category_checklist( $name, array $values, array $only = array(), $empty = 'دسته‌ای وجود ندارد.' ) {
		$terms = self::categories( $only );
		if ( ! $terms ) {
			echo '<p class="description">' . esc_html( $empty ) . '</p>';
			return;
		}
		$values = array_map( 'intval', $values );
		echo '<div class="pnr-checklist">';
		foreach ( $terms as $term ) {
			printf(
				'<label style="padding-inline-start:%dpx"><input type="checkbox" name="%s[]" value="%d"%s> %s</label>',
				(int) $term->depth * 16,
				esc_attr( $name ),
				(int) $term->term_id,
				checked( in_array( (int) $term->term_id, $values, true ), true, false ),
				esc_html( $term->name )
			);
		}
		echo '</div>';
	}

	/**
	 * Categories in hierarchical order with a depth property.
	 *
	 * @return \WP_Term[]
	 */
	public static function categories( array $only = array() ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$by_parent = array();
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}
		$out  = array();
		$walk = function ( $parent, $depth ) use ( &$walk, &$out, $by_parent ) {
			foreach ( isset( $by_parent[ $parent ] ) ? $by_parent[ $parent ] : array() as $term ) {
				$term->depth = $depth;
				$out[]       = $term;
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		// Orphans (parent missing) at the end.
		$ids = wp_list_pluck( $out, 'term_id' );
		foreach ( $terms as $term ) {
			if ( ! in_array( $term->term_id, $ids, true ) ) {
				$term->depth = 0;
				$out[]       = $term;
			}
		}
		if ( $only ) {
			$only = array_map( 'intval', $only );
			$out  = array_values(
				array_filter(
					$out,
					function ( $t ) use ( $only ) {
						return in_array( (int) $t->term_id, $only, true );
					}
				)
			);
			foreach ( $out as $term ) {
				$term->depth = 0;
			}
		}
		return $out;
	}

	private static function attrs( array $attrs ) {
		$html = '';
		foreach ( $attrs as $key => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}
			$html .= true === $value ? ' ' . esc_attr( $key ) : sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}
		return $html;
	}

	/* ---------- Reading posted values ---------- */

	public static function post( $key, $default = null ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security
	}

	public static function int( array $data, $key, $min = 0, $max = PHP_INT_MAX, $default = 0 ) {
		if ( ! isset( $data[ $key ] ) || '' === $data[ $key ] ) {
			return $default;
		}
		return max( $min, min( $max, (int) $data[ $key ] ) );
	}

	public static function bool( array $data, $key ) {
		return ! empty( $data[ $key ] ) ? 1 : 0;
	}

	public static function choice( array $data, $key, array $allowed, $default ) {
		$value = isset( $data[ $key ] ) ? sanitize_key( $data[ $key ] ) : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	public static function ids( array $data, $key ) {
		return isset( $data[ $key ] ) ? array_values( array_filter( array_map( 'absint', (array) $data[ $key ] ) ) ) : array();
	}

	public static function textarea_value( array $data, $key ) {
		return isset( $data[ $key ] ) ? sanitize_textarea_field( $data[ $key ] ) : '';
	}

	public static function text_value( array $data, $key ) {
		return isset( $data[ $key ] ) ? sanitize_text_field( $data[ $key ] ) : '';
	}
}
