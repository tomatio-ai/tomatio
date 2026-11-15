<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\HtmlFiles;

/**
 * #469: the install icons of a shared HTML artifact (Home Screen, app
 * launcher), drawn on the server so every browser gets one without page code.
 *
 * The base is the artifact's own icon: the PNG, JPEG, GIF or WebP its
 * index.html declares (apple-touch-icon first, then icon), else a file with
 * a well-known name (apple-touch-icon.png, icon.png, ...), else the brain's
 * Site Icon. Without any, an icon is generated from the title: its first
 * letter on a colour from the title. A seat's icon (a personal link) is told apart at a glance by its
 * style (an artifact setting, StoreMode::seatIconStyle):
 *
 *  - recolor (default): the icon's background takes the seat's colour and
 *    the badge with its first letter matches, darker. The background is the
 *    main hue of the icon's outer ring; every pixel near that hue moves to
 *    the seat's hue by the same amount, keeping its saturation and
 *    lightness, so gradients and stripes survive and the subject, accents,
 *    whites and blacks stay as they are;
 *  - badge: only the badge, on a colour kept clear of the icon's own hues;
 *  - off: the link's own icon.
 *
 * A seat's colour is one of twelve well-spaced hues, picked from its name
 * (the same on every device and size), skipping hues close to the
 * background or to any other strong colour of the icon so accents still
 * stand out. The link's own icon (no seat) never changes.
 *
 * Icons are opaque squares (iOS ignores transparency on the Home Screen).
 * Drawing needs GD; without it the base bytes are served as they are, or
 * the bundled TomaTío icon.
 */
final class AppIcon {

	public const SIZES   = [ 180, 192, 512 ];
	// Bumped when the drawing changes, so every icon address changes with it.
	public const RENDER  = '1';
	// Bumped when a seat's drawing changes (seat icons only).
	public const SEAT_RENDER = '2';
	public const DEFAULT = 180;

	public const STYLE_RECOLOR = 'recolor';
	public const STYLE_BADGE   = 'badge';
	public const STYLE_OFF     = 'off';
	public const STYLES        = [ self::STYLE_RECOLOR, self::STYLE_BADGE, self::STYLE_OFF ];

	/** The seat colours: twelve hues 30 degrees apart (an icon's own colours rule some out). */
	public const SEAT_HUES = [ 0, 30, 60, 90, 120, 150, 180, 210, 240, 270, 300, 330 ];

	/** Pixels within this many degrees of the background hue are the background. */
	public const BACKGROUND_SPREAD = 40;

	/** A seat hue keeps at least this far from the background and every accent. */
	public const SEAT_CLEARANCE = 40;

	public const FONT    = '/assets/fonts/tomatio-badge.ttf';
	public const BUNDLED = '/assets/brand/apple-touch-icon.png';

	private const WELL_KNOWN = [ 'apple-touch-icon.png', 'apple-touch-icon-precomposed.png', 'icon.png', 'icon-512.png', 'icon-192.png', 'logo.png', 'favicon.png' ];

	private const RASTER = [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ];

	/**
	 * A short hash of everything an icon is drawn from (the base bytes or
	 * the title, the seat, the drawing code): its address changes exactly
	 * when the icon does, so caches (Safari's per web app) never go stale.
	 */
	public static function contentHash( ?string $base, string $title, ?string $seat, string $style = self::STYLE_RECOLOR ): string {
		$from = self::RENDER . '|' . ( $base !== null ? 'b:' . hash( 'sha256', $base ) : 't:' . $title ) . '|' . ( $seat ?? '' );
		if ( $seat !== null ) {
			// The link's own icon keeps its address; a seat's moves with its style.
			$from .= '|' . self::SEAT_RENDER . '|' . self::style( $style );
		}

		return substr( hash( 'sha256', $from ), 0, 12 );
	}

	public static function style( string $style ): string {
		return in_array( $style, self::STYLES, true ) ? $style : self::STYLE_RECOLOR;
	}

	/**
	 * A seat name as an address segment: lower-case ASCII letters, digits
	 * and dashes ("app" for the link's own icon).
	 */
	public static function slug( ?string $seat ): string {
		if ( $seat === null ) {
			return 'app';
		}
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $seat ) ) ), '-' );

		return $slug !== '' ? substr( $slug, 0, 24 ) : 'seat';
	}

	public static function canDraw(): bool {
		return function_exists( 'imagecreatefromstring' ) && function_exists( 'imagecreatetruecolor' );
	}

	/**
	 * The artifact's own icon file (its path in the version), or null.
	 *
	 * @param array<string, array{type:string,enc:string,data:string}> $files
	 */
	public static function declaredPath( array $files ): ?string {
		$isRaster = static fn ( string $path ): bool => isset( $files[ $path ] ) && in_array( strtok( (string) $files[ $path ]['type'], ';' ), self::RASTER, true );

		$html = isset( $files[ HtmlFiles::ENTRY ] ) ? HtmlFiles::bytes( $files[ HtmlFiles::ENTRY ] ) : '';
		$tags = preg_match_all( '#<link\b[^>]*>#i', $html, $m ) > 0 ? $m[0] : [];
		foreach ( [ 'apple-touch-icon(?:-precomposed)?', '(?:shortcut )?icon' ] as $rel ) {
			foreach ( $tags as $tag ) {
				if ( preg_match( '#\brel\s*=\s*(["\'])\s*' . $rel . '\s*\1#i', $tag ) !== 1 || preg_match( '#\bhref\s*=\s*(["\'])(.*?)\1#i', $tag, $href ) !== 1 ) {
					continue;
				}
				$path = self::localPath( html_entity_decode( $href[2], ENT_QUOTES ) );
				if ( $path !== null && $isRaster( $path ) ) {
					return $path;
				}
			}
		}

		foreach ( self::WELL_KNOWN as $path ) {
			if ( $isRaster( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * #518: the bytes of the artifact's own icon: its declared file
	 * (declaredPath()), or an icon `<link>` written inline as a base64
	 * `data:` image. Null when it has none.
	 *
	 * @param array<string, array{type:string,enc:string,data:string}> $files
	 */
	public static function declaredBytes( array $files ): ?string {
		$path = self::declaredPath( $files );
		if ( $path !== null ) {
			return HtmlFiles::bytes( $files[ $path ] );
		}

		$html = isset( $files[ HtmlFiles::ENTRY ] ) ? HtmlFiles::bytes( $files[ HtmlFiles::ENTRY ] ) : '';
		$tags = preg_match_all( '#<link\b[^>]*>#i', $html, $m ) > 0 ? $m[0] : [];
		foreach ( [ 'apple-touch-icon(?:-precomposed)?', '(?:shortcut )?icon' ] as $rel ) {
			foreach ( $tags as $tag ) {
				if ( preg_match( '#\brel\s*=\s*(["\'])\s*' . $rel . '\s*\1#i', $tag ) === 1
					&& preg_match( '#\bhref\s*=\s*(["\'])data:(image/(?:png|jpeg|gif|webp));base64,([A-Za-z0-9+/=]+)\1#i', $tag, $data ) === 1 ) {
					$bytes = base64_decode( $data[3], true );
					if ( is_string( $bytes ) && $bytes !== '' ) {
						return $bytes;
					}
				}
			}
		}

		return null;
	}

	/**
	 * A PNG of $size for the artifact, optionally badged for a seat.
	 *
	 * @param string|null $base  The base icon's bytes (declaredPath()), null to generate one.
	 * @param string      $title The artifact's title, for a generated icon.
	 * @param string|null $seat  The seat name, null for the link's own icon.
	 * @param string      $style How a seat's icon differs (STYLES).
	 */
	public static function png( ?string $base, string $title, int $size, ?string $seat = null, string $style = self::STYLE_RECOLOR ): string {
		$size  = in_array( $size, self::SIZES, true ) ? $size : self::DEFAULT;
		$style = self::style( $style );

		if ( ! self::canDraw() ) {
			return $base !== null && str_starts_with( $base, "\x89PNG" ) ? $base : self::bundled();
		}

		$canvas = self::canvas( $base, $title, $size );
		if ( $seat !== null && $seat !== '' && $style === self::STYLE_RECOLOR ) {
			// Read at one fixed size, so every size of a seat's icon gets the same colour.
			$sample  = self::canvas( $base, $title, 96 );
			$palette = self::palette( self::pixels( $sample, true ), self::pixels( $sample, false ) );
			imagedestroy( $sample );
			$hue = self::seatHue( $seat, $palette['background'], $palette['accents'] );
			if ( $palette['background'] !== null ) {
				self::recolorImage( $canvas, $palette['background'], $hue );
			}
			self::badge( $canvas, $seat, $size, self::hex( $hue, 0.65, 0.32 ) );
		} elseif ( $seat !== null && $seat !== '' && $style === self::STYLE_BADGE ) {
			self::badge( $canvas, $seat, $size );
		}

		ob_start();
		imagepng( $canvas, null, 9 );
		$png = (string) ob_get_clean();
		imagedestroy( $canvas );

		return $png !== '' ? $png : self::bundled();
	}

	/**
	 * A seat's badge colour as #rrggbb: a hue from the name, skipping hues
	 * close to the base icon's own (so the badge is never lost on it).
	 *
	 * @param array<int, int> $avoid Hues (0-359) to keep clear of.
	 */
	public static function seatColor( string $seat, array $avoid = [] ): string {
		$steps = 18;
		$start = (int) ( hexdec( substr( hash( 'sha256', mb_strtoupper( trim( $seat ) ) ), 0, 6 ) ) % $steps );

		for ( $i = 0; $i < $steps; $i++ ) {
			// Steps of 100 degrees walk the wheel in an order that spreads neighbours apart.
			$hue   = ( ( $start + $i * 5 ) % $steps ) * 20;
			$clear = true;
			foreach ( $avoid as $other ) {
				$gap = abs( $hue - $other ) % 360;
				if ( min( $gap, 360 - $gap ) < 45 ) {
					$clear = false;
					break;
				}
			}
			if ( $clear ) {
				return self::hex( $hue, 0.7, 0.45 );
			}
		}

		return self::hex( $start * 20, 0.7, 0.45 );
	}

	/**
	 * The colour of a seat: one of the SEAT_HUES that keep SEAT_CLEARANCE
	 * from the background and every accent, picked from its name (so the same
	 * name gets the same colour everywhere, and names spread evenly over
	 * what is left); the name's own of all twelve when none is clear.
	 *
	 * @param int|null        $background The icon's background hue, null when it has none.
	 * @param array<int, int> $accents    The icon's other strong hues.
	 */
	public static function seatHue( string $seat, ?int $background = null, array $accents = [] ): int {
		$pick  = (int) hexdec( substr( hash( 'sha256', mb_strtoupper( trim( $seat ) ) ), 0, 6 ) );
		$avoid = $background !== null ? array_merge( [ $background ], $accents ) : $accents;
		$clear = array_values(
			array_filter(
				self::SEAT_HUES,
				static function ( int $hue ) use ( $avoid ): bool {
					foreach ( $avoid as $other ) {
						if ( self::hueGap( $hue, $other ) < self::SEAT_CLEARANCE ) {
							return false;
						}
					}

					return true;
				}
			)
		);

		return $clear !== [] ? $clear[ $pick % count( $clear ) ] : self::SEAT_HUES[ $pick % count( self::SEAT_HUES ) ];
	}

	/**
	 * An icon's background hue (the main hue of its outer ring, null when
	 * the ring is grey or white) and its accents (other strong hues, at least
	 * 3% of its coloured pixels, more than BACKGROUND_SPREAD from the
	 * background).
	 *
	 * @param array<int, array{0:int,1:int,2:int}> $ring The outer ring's pixels.
	 * @param array<int, array{0:int,1:int,2:int}> $all  Every pixel.
	 * @return array{background:int|null, accents:array<int, int>}
	 */
	public static function palette( array $ring, array $all ): array {
		$bins = [];
		$hues = [];
		foreach ( $ring as [ $r, $g, $b ] ) {
			[ $h, $s, $l ] = self::hsl( $r, $g, $b );
			if ( $s < 0.25 || $l < 0.1 || $l > 0.92 ) {
				continue;
			}
			$bin          = ( (int) floor( $h / 10 ) * 10 ) % 360;
			$bins[ $bin ] = ( $bins[ $bin ] ?? 0 ) + 1;
			$hues[]       = $h;
		}

		$background = null;
		if ( $bins !== [] ) {
			arsort( $bins );
			$top = (int) array_key_first( $bins ) + 5;
			// The circular mean of the ring's hues near the main one: a gradient's middle.
			$x = 0.0;
			$y = 0.0;
			foreach ( $hues as $h ) {
				if ( self::hueGap( (int) round( $h ), $top ) <= self::BACKGROUND_SPREAD ) {
					$x += cos( deg2rad( $h ) );
					$y += sin( deg2rad( $h ) );
				}
			}
			$background = ( (int) round( rad2deg( atan2( $y, $x ) ) ) + 360 ) % 360;
		}

		$strong = [];
		$total  = 0;
		foreach ( $all as [ $r, $g, $b ] ) {
			[ $h, $s, $l ] = self::hsl( $r, $g, $b );
			if ( $s < 0.4 || $l < 0.1 || $l > 0.92 ) {
				continue;
			}
			++$total;
			$bin            = ( (int) floor( $h / 10 ) * 10 ) % 360;
			$strong[ $bin ] = ( $strong[ $bin ] ?? 0 ) + 1;
		}

		$accents = [];
		foreach ( $strong as $bin => $n ) {
			$hue = $bin + 5;
			if ( $n / max( 1, $total ) >= 0.03 && ( $background === null || self::hueGap( $hue, $background ) > self::BACKGROUND_SPREAD ) ) {
				$accents[] = $hue;
			}
		}
		sort( $accents );

		return [ 'background' => $background, 'accents' => $accents ];
	}

	/**
	 * One pixel of a seat's icon: a background pixel (coloured, within
	 * BACKGROUND_SPREAD of the background hue) turns by the seat hue minus
	 * the background hue, keeping its saturation and lightness; anything
	 * else is left as it is.
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	public static function recolorPixel( int $r, int $g, int $b, int $background, int $seat ): array {
		[ $h, $s, $l ] = self::hsl( $r, $g, $b );
		if ( $s <= 0.15 || $l < 0.05 || $l > 0.96 || self::hueGap( (int) round( $h ), $background ) > self::BACKGROUND_SPREAD ) {
			return [ $r, $g, $b ];
		}

		return self::fromHsl( fmod( $h + $seat - $background + 720, 360 ), $s, $l );
	}

	/**
	 * The shortest distance between two hues, 0 to 180.
	 */
	public static function hueGap( int $a, int $b ): int {
		$gap = abs( $a - $b ) % 360;

		return min( $gap, 360 - $gap );
	}

	/**
	 * Every pixel of an image as [r, g, b], or only its outer ring (the
	 * outer tenth on each side).
	 *
	 * @param \GdImage $image
	 * @return array<int, array{0:int,1:int,2:int}>
	 */
	private static function pixels( $image, bool $ringOnly ): array {
		$w    = imagesx( $image );
		$h    = imagesy( $image );
		$edge = max( 1, (int) round( min( $w, $h ) * 0.1 ) );
		$out  = [];
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				if ( $ringOnly && min( $x, $y, $w - 1 - $x, $h - 1 - $y ) >= $edge ) {
					continue;
				}
				$c     = (int) imagecolorat( $image, $x, $y );
				$out[] = [ ( $c >> 16 ) & 0xFF, ( $c >> 8 ) & 0xFF, $c & 0xFF ];
			}
		}

		return $out;
	}

	/**
	 * recolorPixel() over a whole (truecolor, opaque) image.
	 *
	 * @param \GdImage $image
	 */
	private static function recolorImage( $image, int $background, int $seat ): void {
		$w    = imagesx( $image );
		$h    = imagesy( $image );
		$done = [];
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$c = (int) imagecolorat( $image, $x, $y ) & 0xFFFFFF;
				if ( ! isset( $done[ $c ] ) ) {
					[ $r, $g, $b ] = self::recolorPixel( ( $c >> 16 ) & 0xFF, ( $c >> 8 ) & 0xFF, $c & 0xFF, $background, $seat );
					$done[ $c ]    = ( $r << 16 ) | ( $g << 8 ) | $b;
				}
				if ( $done[ $c ] !== $c ) {
					imagesetpixel( $image, $x, $y, $done[ $c ] );
				}
			}
		}
	}

	/**
	 * The main hues of an image (saturated, not too dark or light pixels,
	 * sampled), most common first, at most three.
	 *
	 * @param \GdImage $image
	 * @return array<int, int>
	 */
	public static function hues( $image ): array {
		$w      = imagesx( $image );
		$h      = imagesy( $image );
		$counts = [];
		$step   = max( 1, (int) ( min( $w, $h ) / 24 ) );

		for ( $y = 0; $y < $h; $y += $step ) {
			for ( $x = 0; $x < $w; $x += $step ) {
				$rgb = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );
				if ( $rgb['alpha'] > 64 ) {
					continue;
				}
				[ $hue, $sat, $light ] = self::hsl( $rgb['red'], $rgb['green'], $rgb['blue'] );
				if ( $sat < 0.25 || $light < 0.15 || $light > 0.9 ) {
					continue;
				}
				$bin            = (int) ( round( $hue / 15 ) * 15 ) % 360;
				$counts[ $bin ] = ( $counts[ $bin ] ?? 0 ) + 1;
			}
		}

		arsort( $counts );
		$total = array_sum( $counts );

		return array_slice( array_keys( array_filter( $counts, static fn ( int $n ): bool => $total > 0 && $n / $total >= 0.08 ) ), 0, 3 );
	}

	/**
	 * The square, opaque base: the artifact's icon scaled to cover, or one
	 * generated from the title.
	 *
	 * @return \GdImage
	 */
	private static function canvas( ?string $base, string $title, int $size ) {
		$canvas = imagecreatetruecolor( $size, $size );
		imagealphablending( $canvas, true );

		$source = $base !== null ? @imagecreatefromstring( $base ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken image falls back to a generated icon.
		if ( $source !== false ) {
			$w = imagesx( $source );
			$h = imagesy( $source );
			// Transparent parts land on the icon's own corner colour, else white.
			$corner = imagecolorsforindex( $source, imagecolorat( $source, 0, 0 ) );
			$fill   = $corner['alpha'] < 16 ? [ $corner['red'], $corner['green'], $corner['blue'] ] : [ 255, 255, 255 ];
			imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, $fill[0], $fill[1], $fill[2] ) );
			$side = min( $w, $h );
			imagecopyresampled( $canvas, $source, 0, 0, (int) ( ( $w - $side ) / 2 ), (int) ( ( $h - $side ) / 2 ), $size, $size, $side, $side );
			imagedestroy( $source );

			return $canvas;
		}

		$title = trim( $title ) !== '' ? $title : 'TomaTío';
		[ $r, $g, $b ] = self::rgb( self::hex( (int) ( hexdec( substr( hash( 'sha256', $title ), 0, 6 ) ) % 360 ), 0.55, 0.38 ) );
		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, $r, $g, $b ) );
		self::letter( $canvas, self::initial( $title ), $size / 2, $size / 2, $size * 0.5, (int) imagecolorallocate( $canvas, 255, 255, 255 ) );

		return $canvas;
	}

	/**
	 * The seat badge: a disc in the bottom-right corner with a white ring and
	 * the seat's first letter, in $color (#rrggbb) or one kept clear of the
	 * icon's own hues.
	 *
	 * @param \GdImage $canvas
	 */
	private static function badge( $canvas, string $seat, int $size, ?string $color = null ): void {
		[ $r, $g, $b ] = self::rgb( $color ?? self::seatColor( $seat, self::hues( $canvas ) ) );

		// Drawn at 4x and scaled down: GD's ellipses have no anti-aliasing.
		$scale = 4;
		$disc  = (int) round( $size * 0.5 );
		$big   = $disc * $scale;
		$layer = imagecreatetruecolor( $big, $big );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefill( $layer, 0, 0, (int) imagecolorallocatealpha( $layer, 0, 0, 0, 127 ) );
		imagealphablending( $layer, true );
		imagefilledellipse( $layer, (int) ( $big / 2 ), (int) ( $big / 2 ), $big, $big, (int) imagecolorallocate( $layer, 255, 255, 255 ) );
		$ring = (int) round( $big * 0.07 );
		imagefilledellipse( $layer, (int) ( $big / 2 ), (int) ( $big / 2 ), $big - 2 * $ring, $big - 2 * $ring, (int) imagecolorallocate( $layer, $r, $g, $b ) );
		self::letter( $layer, self::initial( $seat ), $big / 2, $big / 2, $big * 0.48, (int) imagecolorallocate( $layer, 255, 255, 255 ) );

		$at = $size - $disc - (int) round( $size * 0.03 );
		imagecopyresampled( $canvas, $layer, $at, $at, 0, 0, $disc, $disc, $big, $big );
		imagedestroy( $layer );
	}

	/**
	 * One letter centred on (cx, cy), cap height about $height, in the badge
	 * font; GD's built-in font scaled up when FreeType is missing.
	 *
	 * @param \GdImage $image
	 */
	private static function letter( $image, string $letter, float $cx, float $cy, float $height, int $color ): void {
		$font = \AGENT_BRAIN_PLUGIN_DIR . self::FONT;
		// Some GD builds report FreeType support and still fail: the bounding box tells.
		$box = self::hasFreeType() && is_readable( $font ) ? @imagettfbbox( $height, 0, $font, $letter ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure picks the fallback.
		if ( $box === false ) {
			self::pixelLetter( $image, $letter, $cx, $cy, $height, $color );

			return;
		}

		$width = $box[2] - $box[0];
		$tall  = $box[1] - $box[7];
		$x     = (int) round( $cx - $width / 2 - $box[0] );
		$y     = (int) round( $cy + $tall / 2 - $box[1] );
		imagettftext( $image, $height, 0, $x, $y, $color, $font, $letter );
	}

	/**
	 * GD's built-in font, scaled up: blocky but legible.
	 *
	 * @param \GdImage $image
	 */
	private static function pixelLetter( $image, string $letter, float $cx, float $cy, float $height, int $color ): void {
		$letter = preg_match( '/^[A-Z0-9#]$/', $letter ) === 1 ? $letter : (string) preg_replace( '/[^A-Z0-9#]/', '', strtoupper( remove_accents( $letter ) ) );
		$w      = imagefontwidth( 5 );
		$h      = imagefontheight( 5 );
		$small  = imagecreatetruecolor( $w, $h );
		imagealphablending( $small, false );
		imagesavealpha( $small, true );
		imagefill( $small, 0, 0, (int) imagecolorallocatealpha( $small, 0, 0, 0, 127 ) );
		$rgb = imagecolorsforindex( $image, $color );
		imagestring( $small, 5, 0, 0, $letter !== '' ? $letter[0] : '#', (int) imagecolorallocate( $small, $rgb['red'], $rgb['green'], $rgb['blue'] ) );
		$tall = (int) round( $height * 1.4 );
		$wide = (int) round( $tall * $w / $h );
		imagealphablending( $image, true );
		imagecopyresized( $image, $small, (int) round( $cx - $wide / 2 ), (int) round( $cy - $tall / 2 ), 0, 0, $wide, $tall, $w, $h );
		imagedestroy( $small );
	}

	private static function hasFreeType(): bool {
		return function_exists( 'imagettftext' ) && function_exists( 'gd_info' ) && ! empty( gd_info()['FreeType Support'] );
	}

	/**
	 * The first letter or digit of a name, upper case, when the badge font
	 * has it (Latin), else the first ASCII letter or digit, else "#".
	 */
	public static function initial( string $name ): string {
		if ( preg_match( '/[\p{L}\p{N}]/u', $name, $m ) === 1 ) {
			$first = mb_strtoupper( $m[0] );
			if ( preg_match( '/^[A-Z0-9\x{00C0}-\x{017F}]$/u', $first ) === 1 ) {
				return $first;
			}
		}

		return preg_match( '/[A-Za-z0-9]/', $name, $m ) === 1 ? strtoupper( $m[0] ) : '#';
	}

	/**
	 * The brain's monogram as a PNG of $size: the first letter of $name in
	 * white on $hex (#rrggbb), for a brain without a Site Icon. Needs GD;
	 * without it, null (callers fall back to the SVG monogram).
	 */
	public static function monogram( string $name, string $hex, int $size ): ?string {
		if ( ! self::canDraw() || preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) !== 1 ) {
			return null;
		}

		$canvas        = imagecreatetruecolor( $size, $size );
		[ $r, $g, $b ] = self::rgb( strtolower( $hex ) );
		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, $r, $g, $b ) );
		self::letter( $canvas, self::initial( $name ), $size / 2, $size / 2, $size * 0.5, (int) imagecolorallocate( $canvas, 255, 255, 255 ) );

		ob_start();
		imagepng( $canvas, null, 9 );
		$png = (string) ob_get_clean();
		imagedestroy( $canvas );

		return $png !== '' ? $png : null;
	}

	/**
	 * The bytes of the site's Site Icon (WordPress Site Identity), the base
	 * of a shared page's icons when the page brings none of its own. Null
	 * when no icon is set or its file is not local.
	 */
	public static function siteIconBytes(): ?string {
		$id   = function_exists( 'get_option' ) ? (int) get_option( 'site_icon' ) : 0;
		$file = $id > 0 ? get_attached_file( $id ) : false;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local image file, read-only.
		$bytes = is_string( $file ) && is_readable( $file ) ? file_get_contents( $file ) : false;

		return is_string( $bytes ) && $bytes !== '' ? $bytes : null;
	}

	private static function bundled(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled plugin file, read-only.
		return (string) file_get_contents( \AGENT_BRAIN_PLUGIN_DIR . self::BUNDLED );
	}

	/**
	 * A relative reference in the page as a file path of the version, or
	 * null for anything absolute, protocol-relative or data:.
	 */
	private static function localPath( string $ref ): ?string {
		if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|/|\#)#i', $ref ) === 1 ) {
			return null;
		}
		$path = ltrim( (string) preg_replace( '#^\./#', '', (string) strtok( $ref, '?#' ) ), '/' );

		return $path !== '' && HtmlFiles::isValidPath( $path ) ? $path : null;
	}

	/**
	 * @return array{0:float,1:float,2:float} Hue 0-360, saturation and lightness 0-1.
	 */
	private static function hsl( int $r, int $g, int $b ): array {
		$r  /= 255;
		$g  /= 255;
		$b  /= 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		$d   = $max - $min;
		if ( $d == 0 ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- float zero.
			return [ 0.0, 0.0, $l ];
		}
		$s = $d / ( 1 - abs( 2 * $l - 1 ) );
		if ( $max === $r ) {
			$h = 60 * fmod( ( $g - $b ) / $d + 6, 6 );
		} elseif ( $max === $g ) {
			$h = 60 * ( ( $b - $r ) / $d + 2 );
		} else {
			$h = 60 * ( ( $r - $g ) / $d + 4 );
		}

		return [ $h, $s, $l ];
	}

	/**
	 * @return array{0:int,1:int,2:int}
	 */
	private static function fromHsl( float $hue, float $s, float $l ): array {
		$c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x = $c * ( 1 - abs( fmod( $hue / 60, 2 ) - 1 ) );
		$m = $l - $c / 2;
		[ $r, $g, $b ] = [ [ $c, $x, 0 ], [ $x, $c, 0 ], [ 0, $c, $x ], [ 0, $x, $c ], [ $x, 0, $c ], [ $c, 0, $x ] ][ min( 5, (int) floor( fmod( $hue, 360 ) / 60 ) ) ];

		return [ (int) round( ( $r + $m ) * 255 ), (int) round( ( $g + $m ) * 255 ), (int) round( ( $b + $m ) * 255 ) ];
	}

	private static function hex( int $hue, float $s, float $l ): string {
		$c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x = $c * ( 1 - abs( fmod( $hue / 60, 2 ) - 1 ) );
		$m = $l - $c / 2;
		[ $r, $g, $b ] = [ [ $c, $x, 0 ], [ $x, $c, 0 ], [ 0, $c, $x ], [ 0, $x, $c ], [ $x, 0, $c ], [ $c, 0, $x ] ][ intdiv( $hue % 360, 60 ) ];

		return sprintf( '#%02x%02x%02x', (int) round( ( $r + $m ) * 255 ), (int) round( ( $g + $m ) * 255 ), (int) round( ( $b + $m ) * 255 ) );
	}

	/**
	 * @return array{0:int,1:int,2:int}
	 */
	private static function rgb( string $hex ): array {
		return [ (int) hexdec( substr( $hex, 1, 2 ) ), (int) hexdec( substr( $hex, 3, 2 ) ), (int) hexdec( substr( $hex, 5, 2 ) ) ];
	}
}
