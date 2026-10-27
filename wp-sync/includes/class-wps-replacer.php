<?php
/**
 * URL やパスの置換。
 *
 * serialize された値は中身をたどって置き換え、文字列の長さ（s:N:）を直す。
 * 置換は strtr で一度にやるので、置き換えた結果がまた別の置換に引っかかることはない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Replacer {

	/** @var array<string,string> */
	private $map = array();

	/** @var array<string,string> 値全体がこれと同じときだけ置き換えるもの。 */
	private $exact = array();

	/**
	 * @param array<int,array{0:string,1:string,2?:string}> $pairs [前, 後, 区切り]。
	 *        区切りがあるときは「前」の直後が区切り（/ など）か、値全体が「前」と同じときだけ置き換える
	 *        （https://example.com/wp が https://example.com/wp-content に引っかからないように）。
	 */
	public function __construct( array $pairs ) {
		foreach ( $pairs as $pair ) {
			if ( ! isset( $pair[0], $pair[1] ) || '' === (string) $pair[0] || $pair[0] === $pair[1] ) {
				continue;
			}
			$sep = isset( $pair[2] ) ? (string) $pair[2] : '';
			if ( '' === $sep ) {
				$this->map[ (string) $pair[0] ] = (string) $pair[1];
			} else {
				$this->map[ $pair[0] . $sep ]    = $pair[1] . $sep;
				$this->exact[ (string) $pair[0] ] = (string) $pair[1];
			}
		}
	}

	/**
	 * 送り元と送り先の情報から置換の組を作る。
	 *
	 * @param array $src   送り元の info。
	 * @param array $dst   送り先の info。
	 * @param array $extra 画面で足した組（[from, to] の配列）。
	 * @param array $sites マルチサイトのメイン以外のサイトの URL の組（[from, to] の配列）。区切りで止めて置き換える。
	 */
	public static function build_pairs( array $src, array $dst, array $extra = array(), array $sites = array() ) {
		$pairs = array();

		// [前, 後, 区切りで止めるか]
		$urls = array( array( $src['home'], $dst['home'], false ) );
		// WordPress を別のフォルダに置いているとき（siteurl が home/wp など）。
		// 送り元が直下で送り先がフォルダのときは区別できないので、home の置換に任せる（siteurl の値は送り先のものを残す）。
		if ( $src['siteurl'] !== $src['home'] ) {
			$urls[] = array( $src['siteurl'], $dst['siteurl'], true );
		}
		foreach ( $sites as $site ) {
			$urls[] = array( $site[0], $site[1], true );
		}
		if ( ! empty( $src['content_url'] ) && ! empty( $dst['content_url'] ) ) {
			$urls[] = array( $src['content_url'], $dst['content_url'], true );
		}
		foreach ( $urls as $url ) {
			$from    = untrailingslashit( $url[0] );
			$to      = untrailingslashit( $url[1] );
			$pairs[] = array( $from, $to, $url[2] );
			// http / https が混ざっていても拾えるよう、スキームを除いた形も置き換える。
			$pairs[] = array( self::strip_scheme( $from ), self::strip_scheme( $to ), $url[2] );
		}

		foreach ( $extra as $pair ) {
			if ( isset( $pair[0], $pair[1] ) && '' !== trim( $pair[0] ) ) {
				$pairs[] = array( $pair[0], $pair[1], false );
			}
		}

		foreach ( array( 'abspath', 'content_dir', 'uploads_dir' ) as $key ) {
			if ( ! empty( $src[ $key ] ) && ! empty( $dst[ $key ] ) ) {
				$pairs[] = array( untrailingslashit( $src[ $key ] ), untrailingslashit( $dst[ $key ] ), true );
			}
		}

		// ブロックの属性などの JSON（\/ でエスケープされた形）と、URL エンコードされた形。
		$out = array();
		foreach ( $pairs as $pair ) {
			list( $from, $to, $bounded ) = $pair;
			$forms                       = array( array( $from, $to, '/' ) );
			if ( false !== strpos( $from, '/' ) ) {
				$forms[] = array( str_replace( '/', '\\/', $from ), str_replace( '/', '\\/', $to ), '\\/' );
			}
			if ( preg_match( '#^(https?:)?//#', $from ) ) {
				$forms[] = array( rawurlencode( $from ), rawurlencode( $to ), '%2F' );
			}
			foreach ( $forms as $form ) {
				if ( '' !== $form[0] && $form[0] !== $form[1] && ! isset( $out[ $form[0] ] ) ) {
					$out[ $form[0] ] = array( $form[0], $form[1], $bounded ? $form[2] : '' );
				}
			}
		}
		return array_values( $out );
	}

	private static function strip_scheme( $url ) {
		return preg_replace( '#^https?:#i', '', $url );
	}

	public function is_empty() {
		return ! $this->map && ! $this->exact;
	}

	public function value( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( isset( $this->exact[ $value ] ) ) {
			return $this->exact[ $value ];
		}
		if ( ! $this->contains( $value ) ) {
			return $value;
		}
		if ( is_serialized( $value ) ) {
			try {
				$pos = 0;
				$out = $this->walk( $value, $pos, 0 );
				if ( strlen( $value ) === $pos ) {
					return $out;
				}
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// 壊れた serialize は、長さがずれないようそのまま残す。
			}
			return $value;
		}
		return strtr( $value, $this->map );
	}

	private function contains( $value ) {
		foreach ( $this->map as $from => $to ) {
			if ( false !== strpos( $value, (string) $from ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * serialize された値を 1 つ読み、置換したものを返す。$pos は読んだ分だけ進む。
	 */
	private function walk( $s, &$pos, $depth ) {
		if ( $depth > 512 ) {
			throw new Exception( 'too deep' );
		}
		$start = $pos;
		$type  = isset( $s[ $pos ] ) ? $s[ $pos ] : '';

		switch ( $type ) {
			case 'N':
				$this->expect( $s, $pos, 'N;' );
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $pos );
				if ( false === $end ) {
					throw new Exception( 'bad scalar' );
				}
				$pos = $end + 1;
				return substr( $s, $start, $pos - $start );

			case 's':
				$len = $this->read_int( $s, $pos, 's:' );
				$this->expect( $s, $pos, ':"' );
				$inner = (string) substr( $s, $pos, $len );
				if ( strlen( $inner ) !== $len ) {
					throw new Exception( 'short string' );
				}
				$pos += $len;
				$this->expect( $s, $pos, '";' );
				$new = $this->value( $inner );
				return 's:' . strlen( $new ) . ':"' . $new . '";';

			case 'a':
				$count = $this->read_int( $s, $pos, 'a:' );
				$this->expect( $s, $pos, ':{' );
				$out = 'a:' . $count . ':{';
				for ( $i = 0; $i < $count * 2; $i++ ) {
					$out .= $this->walk( $s, $pos, $depth + 1 );
				}
				$this->expect( $s, $pos, '}' );
				return $out . '}';

			case 'O':
				$len = $this->read_int( $s, $pos, 'O:' );
				$this->expect( $s, $pos, ':"' );
				$pos += $len;
				$this->expect( $s, $pos, '":' );
				$count = $this->read_int( $s, $pos, '' );
				$this->expect( $s, $pos, ':{' );
				$out = substr( $s, $start, $pos - $start );
				for ( $i = 0; $i < $count * 2; $i++ ) {
					$out .= $this->walk( $s, $pos, $depth + 1 );
				}
				$this->expect( $s, $pos, '}' );
				return $out . '}';

			case 'C':
				// 独自形式（Serializable）は中身を解釈できないので、そのまま残す。
				$len = $this->read_int( $s, $pos, 'C:' );
				$this->expect( $s, $pos, ':"' );
				$pos += $len;
				$this->expect( $s, $pos, '":' );
				$size = $this->read_int( $s, $pos, '' );
				$this->expect( $s, $pos, ':{' );
				$pos += $size;
				$this->expect( $s, $pos, '}' );
				return substr( $s, $start, $pos - $start );

			case 'E':
				$len = $this->read_int( $s, $pos, 'E:' );
				$this->expect( $s, $pos, ':"' );
				$pos += $len;
				$this->expect( $s, $pos, '";' );
				return substr( $s, $start, $pos - $start );
		}
		throw new Exception( 'unknown type' );
	}

	private function expect( $s, &$pos, $token ) {
		if ( substr( $s, $pos, strlen( $token ) ) !== $token ) {
			throw new Exception( 'unexpected' );
		}
		$pos += strlen( $token );
	}

	private function read_int( $s, &$pos, $prefix ) {
		$this->expect( $s, $pos, $prefix );
		if ( ! preg_match( '/\G\d+/', $s, $m, 0, $pos ) ) {
			throw new Exception( 'number expected' );
		}
		$pos += strlen( $m[0] );
		return (int) $m[0];
	}
}
