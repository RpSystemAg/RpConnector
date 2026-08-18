<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/** Deliberately small JSON-Schema subset used at the execution boundary. */
final class PRSTUDIO_UC_Schema_Validator {
    private const TYPES = array( 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' );

    private static function is_object_value( $value ): bool {
        return is_array( $value ) && ( array() === $value || ! array_is_list( $value ) );
    }

    private static function is_array_value( $value ): bool {
        return is_array( $value ) && ( array() === $value || array_is_list( $value ) );
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

    public static function validate( $value, array $schema, string $path = '$' ): array {
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

        if ( 'object' === $type ) {
            foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
                if ( ! array_key_exists( (string) $required, $value ) ) { $errors[] = $path . '.' . $required . ' is required'; }
            }
            $properties = (array) ( $schema['properties'] ?? array() );
            if ( array_key_exists( 'additionalProperties', $schema ) && false === $schema['additionalProperties'] ) {
                foreach ( array_keys( $value ) as $key ) {
                    if ( ! array_key_exists( $key, $properties ) ) { $errors[] = $path . '.' . $key . ' is not allowed'; }
                }
            }
            foreach ( $properties as $key => $child ) {
                if ( array_key_exists( $key, $value ) ) {
                    foreach ( self::validate( $value[ $key ], (array) $child, $path . '.' . $key ) as $e ) { $errors[] = $e; }
                }
            }
        } elseif ( 'array' === $type ) {
            if ( isset( $schema['minItems'] ) && count( $value ) < (int) $schema['minItems'] ) { $errors[] = $path . ' below minItems'; }
            if ( isset( $schema['maxItems'] ) && count( $value ) > (int) $schema['maxItems'] ) { $errors[] = $path . ' exceeds maxItems'; }
            foreach ( $value as $i => $child ) {
                foreach ( self::validate( $child, (array) ( $schema['items'] ?? array() ), $path . '[' . $i . ']' ) as $e ) { $errors[] = $e; }
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
        return array_values( array_unique( $errors ) );
    }
}
