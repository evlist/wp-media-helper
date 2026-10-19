<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Admin\SubtitleMatcher;

class SubtitleMatcherTest extends TestCase {

	private function file( string $key, string $name, string $dir = 'd' ): array {
		return [ 'key' => $key, 'name' => $name, 'dir' => $dir ];
	}

	public function test_the_language_is_read_from_the_end_of_the_name(): void {
		$this->assertSame( [ 'base' => 'film', 'lang' => 'fr', 'variant' => '' ], SubtitleMatcher::parse( 'film.fr.vtt' ) );
		$this->assertSame( [ 'base' => 'film', 'lang' => 'pt-BR', 'variant' => '' ], SubtitleMatcher::parse( 'film_pt-br.vtt' ) );
		$this->assertSame( [ 'base' => 'film', 'lang' => 'en', 'variant' => 'forced' ], SubtitleMatcher::parse( 'film.en.forced.vtt' ) );
		$this->assertSame( [ 'base' => 'film-the', 'lang' => '', 'variant' => '' ], SubtitleMatcher::parse( 'film-the.vtt' ) );
		$this->assertSame( [ 'base' => 'film', 'lang' => '', 'variant' => '' ], SubtitleMatcher::parse( 'film.vtt' ) );
	}

	public function test_the_label_is_the_name_of_the_language(): void {
		$this->assertSame( 'Français', SubtitleMatcher::label( 'fr' ) );
		$this->assertSame( 'English (forced)', SubtitleMatcher::label( 'en', 'forced' ) );
		$this->assertSame( 'Português', SubtitleMatcher::label( 'pt-BR' ) );
		$this->assertSame( 'xx', SubtitleMatcher::label( 'xx' ) );
		$this->assertSame( '', SubtitleMatcher::label( '' ) );
	}

	public function test_a_single_video_takes_all_the_subtitles(): void {
		$proposal = SubtitleMatcher::propose(
			[ $this->file( 'v', 'clip.mp4' ) ],
			[ $this->file( 's1', 'whatever.fr.vtt' ), $this->file( 's2', 'other.en.vtt' ) ]
		);

		$this->assertSame( 'v', $proposal[0]['video'] );
		$this->assertSame( 'v', $proposal[1]['video'] );
		$this->assertSame( 'single', $proposal[0]['reason'] );
		$this->assertSame( [ 'fr', 'Français' ], [ $proposal[0]['srclang'], $proposal[0]['label'] ] );
	}

	public function test_names_that_match_are_paired(): void {
		$videos = [ $this->file( 'a', '20260101_visit.mp4' ), $this->file( 'b', '20260102_beach.mp4' ) ];
		$proposal = SubtitleMatcher::propose( $videos, [ $this->file( 's1', '20260102_beach.fr.vtt' ), $this->file( 's2', '20260101_visit.en.vtt' ), $this->file( 's3', '20260101_visit.vtt' ) ] );

		$this->assertSame( [ 'b', 'a', 'a' ], array_column( $proposal, 'video' ) );
		$this->assertSame( [ 'name', 'name', 'name' ], array_column( $proposal, 'reason' ) );
		$this->assertSame( '', $proposal[2]['srclang'] );
	}

	public function test_a_name_that_starts_with_the_video_is_paired_and_the_longest_wins(): void {
		$videos = [ $this->file( 'a', 'trip.mp4' ), $this->file( 'b', 'trip_day2.mp4' ) ];
		$proposal = SubtitleMatcher::propose( $videos, [ $this->file( 's1', 'trip_day2_subtitles.fr.vtt' ), $this->file( 's2', 'trip_subtitles.fr.vtt' ) ] );

		$this->assertSame( [ 'b', 'a' ], array_column( $proposal, 'video' ) );
		$this->assertSame( [ 'prefix', 'prefix' ], array_column( $proposal, 'reason' ) );
	}

	public function test_a_name_that_only_starts_like_the_video_is_not_paired(): void {
		$proposal = SubtitleMatcher::propose( [ $this->file( 'a', 'trip.mp4' ), $this->file( 'b', 'other.mp4' ) ], [ $this->file( 's1', 'tripod.fr.vtt' ) ] );

		$this->assertNull( $proposal[0]['video'] );
		$this->assertSame( 'none', $proposal[0]['reason'] );
	}

	public function test_a_tie_goes_to_the_video_of_the_same_directory_or_to_none(): void {
		$videos = [ $this->file( 'a', 'clip.mp4', 'one' ), $this->file( 'b', 'clip.webm', 'two' ) ];

		$this->assertSame( 'b', SubtitleMatcher::propose( $videos, [ $this->file( 's', 'clip.fr.vtt', 'two' ) ] )[0]['video'] );
		$this->assertNull( SubtitleMatcher::propose( $videos, [ $this->file( 's', 'clip.fr.vtt', 'three' ) ] )[0]['video'] );
	}
}
