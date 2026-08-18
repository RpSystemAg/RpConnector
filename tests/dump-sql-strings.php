<?php
/**
 * Emit every SQL statement the plugin builds, as JSON, for MySQL syntax validation.
 *
 * Uses PHP's own tokenizer rather than a regex, because string boundaries have
 * to be exact. The bug this feeds -- a `// phpcs:ignore` suppression written
 * *inside* a SQL string literal, which MySQL parses as a division operator and
 * rejects -- is invisible to grep-style checks precisely because the text reads
 * as an ordinary comment when you look at the file.
 *
 * Two things make naive extraction useless here:
 *
 *  1. An interpolated double-quoted string is not one token. `"SELECT * FROM
 *     $table WHERE x = %s"` arrives as `"` + T_ENCAPSED_AND_WHITESPACE +
 *     T_VARIABLE + T_ENCAPSED_AND_WHITESPACE + `"`. Treating each piece as a
 *     statement produces nonsense like `SELECT * FROM ` and a wall of false
 *     "expected table name" errors.
 *  2. Queries are routinely assembled by concatenation --
 *     `'UPDATE ' . self::jobs_table() . " SET ..."` -- so the statement only
 *     exists once the pieces are joined.
 *
 * So this walker reassembles both: it joins interpolated segments and follows
 * `.` concatenation chains, substituting any non-literal expression (variable,
 * method call, constant) with the placeholder identifier `tbl`. Comment syntax
 * is deliberately left untouched -- that is the defect under test.
 *
 * Consumed by tests/validate-sql-syntax.py.
 *
 * Usage: php tests/dump-sql-strings.php
 */

declare( strict_types = 1 );

$root = dirname( __DIR__ ) . '/prstudio-unified-control';
$verbs = array( 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'SHOW' );
$out = array();

/** Strip the enclosing quotes from a complete literal token. */
$unquote = static function ( string $raw ): string {
    if ( strlen( $raw ) > 1 ) {
        $first = $raw[0];
        $last = substr( $raw, -1 );
        if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {
            return substr( $raw, 1, -1 );
        }
    }
    return $raw;
};

/** Unescape the sequences that matter for reading SQL text. */
$unescape = static function ( string $s ): string {
    return str_replace(
        array( "\\'", '\\"', '\\n', '\\t', '\\\\' ),
        array( "'", '"', "\n", "\t", '\\' ),
        $s
    );
};

$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
foreach ( $rii as $file ) {
    if ( $file->isDir() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
    $path = str_replace( '\\', '/', $file->getPathname() );
    $tokens = array_values( token_get_all( (string) file_get_contents( $file->getPathname() ) ) );
    $count = count( $tokens );

    for ( $i = 0; $i < $count; $i++ ) {
        $token = $tokens[ $i ];
        $buffer = null;
        $line = 0;

        if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
            // A complete literal with no interpolation.
            $buffer = $unescape( $unquote( (string) $token[1] ) );
            $line = (int) $token[2];
        } elseif ( is_string( $token ) && '"' === $token ) {
            // Opening quote of an interpolated string: consume to the closing quote.
            $line = 0;
            $buffer = '';
            $j = $i + 1;
            for ( ; $j < $count; $j++ ) {
                $inner = $tokens[ $j ];
                if ( is_string( $inner ) && '"' === $inner ) { break; }
                if ( is_array( $inner ) ) {
                    if ( 0 === $line ) { $line = (int) $inner[2]; }
                    if ( T_ENCAPSED_AND_WHITESPACE === $inner[0] ) {
                        $buffer .= $unescape( (string) $inner[1] );
                        continue;
                    }
                    if ( T_VARIABLE === $inner[0] || T_STRING === $inner[0] || T_NUM_STRING === $inner[0] ) {
                        // Emit the placeholder once per interpolation, not per token
                        // (a chain like $obj->method() is several tokens).
                        if ( 'tbl' !== substr( $buffer, -3 ) ) { $buffer .= 'tbl'; }
                        continue;
                    }
                    continue; // T_CURLY_OPEN, T_OBJECT_OPERATOR, T_DOUBLE_COLON, ...
                }
                // Structural characters inside the interpolation: {, }, [, ], (, ), ->
                continue;
            }
            $i = $j; // resume after the closing quote
        }

        if ( null === $buffer ) { continue; }

        // Follow a `.` concatenation chain so assembled statements are complete.
        $k = $i + 1;
        $guard = 0;
        while ( $k < $count && $guard < 64 ) {
            $next = $tokens[ $k ];
            if ( is_array( $next ) && T_WHITESPACE === $next[0] ) { $k++; continue; }
            if ( ! is_string( $next ) || '.' !== $next ) { break; }
            $guard++;
            $k++;
            // Skip whitespace after the dot.
            while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) { $k++; }
            if ( $k >= $count ) { break; }
            $operand = $tokens[ $k ];
            if ( is_array( $operand ) && T_CONSTANT_ENCAPSED_STRING === $operand[0] ) {
                $buffer .= $unescape( $unquote( (string) $operand[1] ) );
                $k++;
            } elseif ( is_string( $operand ) && '"' === $operand ) {
                $k++;
                for ( ; $k < $count; $k++ ) {
                    $inner = $tokens[ $k ];
                    if ( is_string( $inner ) && '"' === $inner ) { break; }
                    if ( is_array( $inner ) && T_ENCAPSED_AND_WHITESPACE === $inner[0] ) {
                        $buffer .= $unescape( (string) $inner[1] );
                    } elseif ( is_array( $inner ) && in_array( $inner[0], array( T_VARIABLE, T_STRING, T_NUM_STRING ), true ) ) {
                        if ( 'tbl' !== substr( $buffer, -3 ) ) { $buffer .= 'tbl'; }
                    }
                }
                $k++;
            } else {
                // A variable, constant or call: consume its token run and
                // substitute one placeholder for the whole expression.
                if ( 'tbl' !== substr( $buffer, -3 ) ) { $buffer .= 'tbl'; }
                $depth = 0;
                for ( ; $k < $count; $k++ ) {
                    $t = $tokens[ $k ];
                    if ( is_string( $t ) ) {
                        if ( '(' === $t || '[' === $t ) { $depth++; continue; }
                        if ( ')' === $t || ']' === $t ) { $depth--; continue; }
                        if ( 0 === $depth && ( '.' === $t || ',' === $t || ';' === $t ) ) { break; }
                        continue;
                    }
                    if ( is_array( $t ) && T_WHITESPACE === $t[0] && 0 === $depth ) { break; }
                }
            }
            $i = $k - 1;
        }

        $has_php_comment = ( false !== strpos( $buffer, '// phpcs' ) )
            || ( false !== strpos( $buffer, '//phpcs' ) )
            || ( false !== strpos( $buffer, '/* phpcs' ) );

        $trimmed = ltrim( $buffer );
        $starts_with_verb = false;
        foreach ( $verbs as $verb ) {
            if ( preg_match( '/^' . $verb . '[\s(]/i', $trimmed ) ) { $starts_with_verb = true; break; }
        }
        $mentions_sql = false;
        foreach ( $verbs as $verb ) {
            if ( false !== stripos( $buffer, $verb . ' ' ) ) { $mentions_sql = true; break; }
        }
        if ( ! $mentions_sql && ! $has_php_comment ) { continue; }

        $out[] = array(
            'file' => $path,
            'line' => $line,
            'sql' => $buffer,
            'complete' => $starts_with_verb,
            'has_php_comment' => $has_php_comment,
        );
    }
}

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
