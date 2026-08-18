<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
final class PRSTUDIO_UC_Evidence_Engine {
    private static function count_value( $value, int $default ): int {
        if ( is_int( $value ) ) { return $value >= 0 ? $value : $default; }
        if ( is_float( $value ) ) {
            return is_finite( $value ) && $value >= 0 && $value <= PHP_INT_MAX && floor( $value ) === $value ? (int) $value : $default;
        }
        if ( is_string( $value ) ) {
            $text = trim( $value );
            if ( '' === $text || 1 !== preg_match( '/^\d+$/D', $text ) ) { return $default; }
            $digits = ltrim( $text, '0' );
            $digits = '' === $digits ? '0' : $digits;
            $max = (string) PHP_INT_MAX;
            if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) { return $default; }
            return (int) $digits;
        }
        return $default;
    }

    private static function sources( $value ): array {
        if ( ! is_array( $value ) ) { return array(); }
        $sources = array();
        $seen = array();
        foreach ( $value as $source ) {
            if ( ! is_string( $source ) || '' === $source || 1 !== preg_match( '//u', $source ) || isset( $seen[ $source ] ) ) { continue; }
            $seen[ $source ] = true;
            $sources[] = $source;
        }
        return $sources;
    }

    private static function evidence_json( $value ): string {
        $json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
        return is_string( $json ) ? $json : 'null';
    }

    public static function receipt(array $cap,array $result,array $context=array()):array{
        $counts=array(
            'requested'=>self::count_value($context['requested']??1,1),
            'processed'=>self::count_value($result['processed']??$result['count']??$result['totals']['products']??1,1),
            'changed'=>self::count_value($result['changed']??$result['affected_rows']??0,0),
            'verified'=>self::count_value($context['verified']??0,0),
            'failed'=>self::count_value($result['failed']??$result['error_count']??0,0),
            'skipped'=>self::count_value($result['skipped']??0,0),
            'memory_reused'=>self::count_value($context['memory_reused']??$result['memory']['reused_count']??0,0),
        );
        $safe=class_exists('PRSTUDIO_UC_Memory')?PRSTUDIO_UC_Memory::redact($result):$result;
        $capability = is_string( $cap['id'] ?? null ) && 1 === preg_match( '//u', $cap['id'] ) ? $cap['id'] : '';
        return array('capability'=>$capability,'counts'=>$counts,'evidence_hash'=>hash('sha256',self::evidence_json($safe)),'sources'=>self::sources($context['sources']??array()),'verified'=>(bool)($context['verified']??false),'created_at'=>gmdate('c'));
    }
}
