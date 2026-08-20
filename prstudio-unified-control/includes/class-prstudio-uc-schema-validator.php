<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/** Deliberately small JSON-Schema subset used at the execution boundary. */
final class PRSTUDIO_UC_Schema_Validator {
    private const TYPES = array( 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' );

    /**
     * array_is_list() equivalent that does not depend on the host providing it.
     *
     * array_is_list() arrived in PHP 8.1 and this plugin declares a PHP 8.0
     * floor, which looks like a fatal waiting to happen. It is not, and the
     * correction is worth recording: WordPress 6.5 ships a polyfill for it in
     * wp-includes/compat.php, and this plugin requires WordPress 6.5, so on any
     * supported install the function exists. PHPCompatibilityWP knows this and
     * deliberately does not flag it.
     *
     * The guard stays anyway, because it costs nothing and removes the
     * dependency on that coincidence: the validator also runs under the test
     * harness and any future context where WordPress is not loaded, and a bare
     * PHP 8.0 process would fatal there. The suite already had this helper in
     * the GPT REST layer, labelled PHP 8.0-compatible; the validator simply did
     * not use it.
     */
    private static function is_list_array( array $value ): bool {
        if ( function_exists( 'array_is_list' ) ) { return array_is_list( $value ); }
        $expected = 0;
        foreach ( $value as $key => $unused ) {
            if ( $key !== $expected ) { return false; }
            $expected++;
        }
        return true;
    }

    private static function is_object_value( $value ): bool {
        return is_array( $value ) && ( array() === $value || ! self::is_list_array( $value ) );
    }

    private static function is_array_value( $value ): bool {
        return is_array( $value ) && ( array() === $value || self::is_list_array( $value ) );
    }

    private static function type_matches( $value, string $type ): bool {
        if ( 'object' === $type ) { return self::is_object_value( $value ); }
        if ( 'array' === $type ) { return self::is_array_value( $value ); }
        if ( 'string' === $type ) { return is_string( $value ); }
        if ( 'integer' === $type ) { return is_int( $value ); }
        if ( 'number' === $type ) { return is_int( $value ) || is_float( $value ); }
        if ( 'boolean' === $type ) { return is_bool( $value ); }
        if ( 'null' === $type ) { return null === $value; }
        return false;
    }

    private static function schema_types( array $schema ): ?array {
        if ( ! array_key_exists( 'type', $schema ) ) { return array(); }
        $raw = $schema['type'];
        if ( is_string( $raw ) ) { $raw = array( $raw ); }
        if ( ! is_array( $raw ) || array() === $raw ) { return null; }
        $types = array();
        foreach ( $raw as $type ) {
            if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) { return null; }
            if ( ! in_array( $type, $types, true ) ) { $types[] = $type; }
        }
        return $types;
    }

    private static function unicode_length( string $value ): int {
        if ( function_exists( 'mb_strlen' ) ) { return mb_strlen( $value, 'UTF-8' ); }
        $count = preg_match_all( '/./us', $value, $matches );
        return false === $count ? strlen( $value ) : $count;
    }

    /** Local JSON Pointer refs only (`#/$defs/...`). Unresolved refs are technical failures. */
    private static function resolve_local_ref( string $ref, array $root ): ?array {
        if ( ! str_starts_with( $ref, '#/' ) ) { return null; }
        $node = $root;
        foreach ( explode( '/', substr( $ref, 2 ) ) as $part ) {
            $part = str_replace( array( '~1', '~0' ), array( '/', '~' ), $part );
            if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) { return null; }
            $node = $node[ $part ];
        }
        return is_array( $node ) ? $node : null;
    }

    public static function validate( $value, array $schema, string $path = '$', ?array $root = null ): array {
        $root = is_array( $root ) ? $root : $schema;
        if ( isset( $schema['$ref'] ) ) {
            $resolved = self::resolve_local_ref( (string) $schema['$ref'], $root );
            if ( ! is_array( $resolved ) ) { return array( $path . ' has unresolved $ref' ); }
            $next = $resolved;
            foreach ( $schema as $key => $child ) {
                if ( '$ref' === $key ) { continue; }
                $next[ $key ] = $child;
            }
            return self::validate( $value, $next, $path, $root );
        }
        $errors = array();
        $types = self::schema_types( $schema );
        if ( null === $types ) { return array( $path . ' has invalid schema type' ); }
        $type = '';
        if ( $types ) {
            foreach ( $types as $candidate ) {
                if ( self::type_matches( $value, $candidate ) ) { $type = $candidate; break; }
            }
            if ( '' === $type ) { return array( $path . ' must match allowed type' ); }
        }
        if ( array_key_exists( 'const', $schema ) && $value !== $schema['const'] ) {
            return array( $path . ' must equal const' );
        }

        if ( 'object' === $type ) {
            foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
                if ( ! array_key_exists( (string) $required, $value ) ) { $errors[] = $path . '.' . $required . ' is required'; }
            }
            $properties = (array) ( $schema['properties'] ?? array() );
            if ( array_key_exists( 'additionalProperties', $schema ) ) {
                $additional = $schema['additionalProperties'];
                if ( false === $additional ) {
                    foreach ( array_keys( $value ) as $key ) {
                        if ( ! array_key_exists( $key, $properties ) ) { $errors[] = $path . '.' . $key . ' is not allowed'; }
                    }
                } elseif ( is_array( $additional ) ) {
                    foreach ( $value as $key => $child ) {
                        if ( ! array_key_exists( $key, $properties ) ) {
                            foreach ( self::validate( $child, $additional, $path . '.' . $key, $root ) as $e ) { $errors[] = $e; }
                        }
                    }
                }
            }
            foreach ( $properties as $key => $child ) {
                if ( array_key_exists( $key, $value ) ) {
                    foreach ( self::validate( $value[ $key ], (array) $child, $path . '.' . $key, $root ) as $e ) { $errors[] = $e; }
                }
            }
        } elseif ( 'array' === $type ) {
            if ( isset( $schema['minItems'] ) && count( $value ) < (int) $schema['minItems'] ) { $errors[] = $path . ' below minItems'; }
            if ( isset( $schema['maxItems'] ) && count( $value ) > (int) $schema['maxItems'] ) { $errors[] = $path . ' exceeds maxItems'; }
            foreach ( $value as $i => $child ) {
                foreach ( self::validate( $child, (array) ( $schema['items'] ?? array() ), $path . '[' . $i . ']', $root ) as $e ) { $errors[] = $e; }
            }
        } elseif ( 'string' === $type ) {
            if ( isset( $schema['minLength'] ) && self::unicode_length( $value ) < (int) $schema['minLength'] ) { $errors[] = $path . ' below minLength'; }
            if ( isset( $schema['maxLength'] ) && self::unicode_length( $value ) > (int) $schema['maxLength'] ) { $errors[] = $path . ' exceeds maxLength'; }
            if ( isset( $schema['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\\/', (string) $schema['pattern'] ) . '/', $value ) ) { $errors[] = $path . ' does not match pattern'; }
            if ( isset( $schema['enum'] ) && ! in_array( $value, (array) $schema['enum'], true ) ) { $errors[] = $path . ' is not an allowed value'; }
        } elseif ( 'integer' === $type || 'number' === $type ) {
            if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) { $errors[] = $path . ' below minimum'; }
            if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) { $errors[] = $path . ' above maximum'; }
        }
        if ( isset( $schema['enum'] ) && ! in_array( $value, (array) $schema['enum'], true ) ) { $errors[] = $path . ' is not an allowed value'; }
        if ( isset( $schema['anyOf'] ) && is_array( $schema['anyOf'] ) ) {
            $matched = false;
            foreach ( $schema['anyOf'] as $sub ) {
                if ( array() === self::validate( $value, (array) $sub, $path, $root ) ) { $matched = true; break; }
            }
            if ( ! $matched ) { $errors[] = $path . ' does not match anyOf'; }
        }
        if ( isset( $schema['oneOf'] ) && is_array( $schema['oneOf'] ) ) {
            $matches = 0;
            foreach ( $schema['oneOf'] as $sub ) {
                if ( array() === self::validate( $value, (array) $sub, $path, $root ) ) { $matches++; }
            }
            if ( 1 !== $matches ) { $errors[] = $path . ' does not match oneOf'; }
        }
        return array_values( array_unique( $errors ) );
    }
}
