<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

/**
 * Guesses which video each subtitle file belongs to, and in which language (slice 051). It only proposes: the panel shows the proposal
 * in a screen where it can be changed before anything is inserted.
 *
 * The rules, in this order, for each subtitle:
 * 1. a single video in the list: the subtitle goes to it;
 * 2. the name of the subtitle, without its extension and without a language suffix (`.fr`, `-en`, `_pt-BR`, `.fr.forced`), is the name of
 *    a video without its extension: that video (reason `name`);
 * 3. the name of the subtitle starts with the name of a video followed by a separator: that video, the longest name winning (reason `prefix`);
 * 4. otherwise no video (reason `none`). A tie between two videos is no video too.
 * When several videos match equally, the one in the same directory is preferred.
 */
final class SubtitleMatcher {

	/** Language codes and names, for the label of a track. */
	private const LANGUAGES = [
		'ar' => 'العربية', 'ca' => 'Català', 'cs' => 'Čeština', 'da' => 'Dansk', 'de' => 'Deutsch', 'el' => 'Ελληνικά', 'en' => 'English',
		'es' => 'Español', 'eu' => 'Euskara', 'fi' => 'Suomi', 'fr' => 'Français', 'gl' => 'Galego', 'he' => 'עברית', 'hu' => 'Magyar',
		'it' => 'Italiano', 'ja' => '日本語', 'ko' => '한국어', 'nl' => 'Nederlands', 'no' => 'Norsk', 'pl' => 'Polski', 'pt' => 'Português',
		'ro' => 'Română', 'ru' => 'Русский', 'sv' => 'Svenska', 'tr' => 'Türkçe', 'uk' => 'Українська', 'zh' => '中文',
	];

	private const VARIANTS = [ 'forced', 'sdh', 'cc', 'hi', 'default' ];

	/**
	 * Name, language code and variant of a subtitle file: `film.fr.forced.vtt` is `film`, `fr`, `forced`.
	 *
	 * @return array{base:string, lang:string, variant:string}
	 */
	public static function parse( string $filename ): array {
		$base    = preg_replace( '/\.[A-Za-z0-9]{2,4}$/', '', $filename ) ?? $filename;
		$variant = '';
		$lang    = '';

		if ( 1 === preg_match( '/^(.*?)[._-](' . implode( '|', self::VARIANTS ) . ')$/i', $base, $found ) ) {
			$base    = $found[1];
			$variant = strtolower( $found[2] );
		}
		if ( 1 === preg_match( '/^(.*?)[._-]([A-Za-z]{2,3}(?:-[A-Za-z]{2,4})?)$/', $base, $found ) && self::isLanguage( $found[2] ) ) {
			$base = $found[1];
			$lang = self::normalizeLanguage( $found[2] );
		}

		return [ 'base' => $base, 'lang' => $lang, 'variant' => $variant ];
	}

	/**
	 * The name shown for a track: the language name, with the variant when there is one.
	 */
	public static function label( string $lang, string $variant = '' ): string {
		$primary = strtolower( explode( '-', $lang )[0] );
		$name    = self::LANGUAGES[ $primary ] ?? ( '' !== $lang ? $lang : '' );
		if ( '' !== $name && '' !== $variant ) {
			$name .= ' (' . $variant . ')';
		}

		return $name;
	}

	/**
	 * @param array<int, array{key:string, name:string, dir?:string}> $videos
	 * @param array<int, array{key:string, name:string, dir?:string}> $subtitles
	 * @return array<int, array{subtitle:string, video:?string, reason:string, srclang:string, label:string}>
	 */
	public static function propose( array $videos, array $subtitles ): array {
		$proposal = [];
		foreach ( $subtitles as $subtitle ) {
			$parsed = self::parse( $subtitle['name'] );
			$match  = self::findVideo( $videos, $subtitle, $parsed['base'] );

			$proposal[] = [
				'subtitle' => $subtitle['key'],
				'video'    => $match['video'],
				'reason'   => $match['reason'],
				'srclang'  => $parsed['lang'],
				'label'    => self::label( $parsed['lang'], $parsed['variant'] ),
			];
		}

		return $proposal;
	}

	/**
	 * @param array<int, array{key:string, name:string, dir?:string}> $videos
	 * @param array{key:string, name:string, dir?:string}             $subtitle
	 * @return array{video:?string, reason:string}
	 */
	private static function findVideo( array $videos, array $subtitle, string $base ): array {
		if ( 1 === count( $videos ) ) {
			return [ 'video' => $videos[0]['key'], 'reason' => 'single' ];
		}

		$needle = self::fold( $base );
		$exact  = [];
		$starts = [];
		foreach ( $videos as $video ) {
			$name = self::fold( preg_replace( '/\.[A-Za-z0-9]{2,4}$/', '', $video['name'] ) ?? $video['name'] );
			if ( '' === $name ) {
				continue;
			}
			if ( $name === $needle ) {
				$exact[] = $video;
			} elseif ( 0 === strpos( $needle, $name ) && 1 === preg_match( '/^[._\- ]/', substr( $needle, strlen( $name ) ) ) ) {
				$starts[ strlen( $name ) ][] = $video;
			}
		}

		if ( [] !== $exact ) {
			return self::pick( $exact, $subtitle, 'name' );
		}
		if ( [] !== $starts ) {
			krsort( $starts );

			return self::pick( reset( $starts ), $subtitle, 'prefix' );
		}

		return [ 'video' => null, 'reason' => 'none' ];
	}

	/**
	 * @param array<int, array{key:string, name:string, dir?:string}> $candidates
	 * @param array{key:string, name:string, dir?:string}             $subtitle
	 * @return array{video:?string, reason:string}
	 */
	private static function pick( array $candidates, array $subtitle, string $reason ): array {
		if ( 1 === count( $candidates ) ) {
			return [ 'video' => $candidates[0]['key'], 'reason' => $reason ];
		}

		$sameDir = array_values( array_filter( $candidates, static fn ( array $video ): bool => ( $video['dir'] ?? '' ) === ( $subtitle['dir'] ?? '' ) ) );

		return 1 === count( $sameDir ) ? [ 'video' => $sameDir[0]['key'], 'reason' => $reason ] : [ 'video' => null, 'reason' => 'none' ];
	}

	private static function fold( string $name ): string {
		return strtolower( trim( $name ) );
	}

	private static function isLanguage( string $code ): bool {
		return isset( self::LANGUAGES[ strtolower( explode( '-', $code )[0] ) ] );
	}

	private static function normalizeLanguage( string $code ): string {
		$parts = explode( '-', $code );

		return strtolower( $parts[0] ) . ( isset( $parts[1] ) ? '-' . ( 2 === strlen( $parts[1] ) ? strtoupper( $parts[1] ) : ucfirst( strtolower( $parts[1] ) ) ) : '' );
	}
}
