<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Shared semantic extension for site-study intents.
 *
 * LAW 15 requires Italian and English to collapse to one semantic intent before
 * routing.  The core Action Lexicon remains the vocabulary source for objects
 * (site, WordPress, users, posts, plugins, ...); this class contributes the one
 * missing action concept -- autonomous study/learning -- in one bidirectional
 * concept rather than parallel language maps.
 */
final class PRSTUDIO_UC_Site_Study_Lexicon {
    public const VERSION = '1.0.0';

    /** One concept, both languages, no direction-specific alias table. */
    private const STUDY_WORDS = array(
        'study','studying','learn','learning',
        'studia','studiare','studio','impara','imparare','apprendi','apprendere',
    );

    private static function normalize( string $value ): string {
        if ( class_exists( 'PRSTUDIO_UC_Action_Lexicon' ) ) {
            return PRSTUDIO_UC_Action_Lexicon::normalize_text( $value );
        }
        $value = strtolower( trim( $value ) );
        $value = strtr( $value, array( 'à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u' ) );
        return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $value ) );
    }

    private static function words( string $value ): array {
        return array_values( array_filter( explode( ' ', self::normalize( $value ) ) ) );
    }

    private static function action_lexicon_covers( string $text, string $canonical ): bool {
        if ( ! class_exists( 'PRSTUDIO_UC_Action_Lexicon' ) ) { return false; }
        return PRSTUDIO_UC_Action_Lexicon::covers(
            PRSTUDIO_UC_Action_Lexicon::query_concepts( $text ),
            PRSTUDIO_UC_Action_Lexicon::query_concepts( $canonical )
        );
    }

    public static function classify( string $text ): array {
        $words = self::words( $text );
        $study = false;
        foreach ( self::STUDY_WORDS as $word ) {
            if ( in_array( $word, $words, true ) ) { $study = true; break; }
        }
        $wordpress = in_array( 'wordpress', $words, true ) || in_array( 'wp', $words, true ) || self::action_lexicon_covers( $text, 'wordpress' );
        $site = $wordpress || self::action_lexicon_covers( $text, 'site' );
        return array(
            'study' => $study,
            'wordpress' => $wordpress,
            'site' => $site,
            'semantic_intent' => $study && $wordpress ? 'study_wordpress' : ( $study && $site ? 'study_site' : '' ),
            'normalized' => self::normalize( $text ),
        );
    }

    /**
     * Resolve a WordPress knowledge question to an existing Action Lexicon
     * object concept.  Returned values are language-neutral Memory search keys.
     */
    public static function memory_subject( string $text ): string {
        if ( ! class_exists( 'PRSTUDIO_UC_Action_Lexicon' ) ) { return ''; }
        $map = array(
            'users' => 'users',
            'posts' => 'content posts',
            'pages' => 'pages',
            'plugins' => 'plugins',
            'comments' => 'comments',
            'media' => 'media images',
            'settings' => 'settings',
            'menus' => 'menus',
            'tables' => 'tables',
        );
        $query = PRSTUDIO_UC_Action_Lexicon::query_concepts( $text );
        foreach ( $map as $subject => $canonical ) {
            if ( PRSTUDIO_UC_Action_Lexicon::covers( $query, PRSTUDIO_UC_Action_Lexicon::query_concepts( $canonical ) ) ) {
                return $subject;
            }
        }
        return '';
    }
}
