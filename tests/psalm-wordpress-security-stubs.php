<?php
/**
 * Security-only Psalm stubs.
 *
 * These declarations augment WordPress stubs with taint semantics. They are not
 * loaded by production PHP. REST request extraction is an untrusted source;
 * SQL/outbound-URL/redirect APIs are security sinks.
 */

class WP_REST_Request
{
    /** @psalm-taint-source input */
    public function get_param($key) {}

    /** @psalm-taint-source input */
    public function get_params() {}

    /** @psalm-taint-source input */
    public function get_json_params() {}

    /** @psalm-taint-source input */
    public function get_body_params() {}

    /** @psalm-taint-source input */
    public function get_query_params() {}

    /** @psalm-taint-source input */
    public function get_body() {}
}

class wpdb
{
    /** @psalm-taint-sink sql $query */
    public function query($query) {}

    /** @psalm-taint-sink sql $query */
    public function get_results($query, $output = OBJECT) {}

    /** @psalm-taint-sink sql $query */
    public function get_row($query, $output = OBJECT, $y = 0) {}

    /** @psalm-taint-sink sql $query */
    public function get_var($query, $x = 0, $y = 0) {}
}

/** @psalm-taint-sink ssrf $url */
function wp_remote_request($url, $args = []) {}

/** @psalm-taint-sink ssrf $url */
function wp_remote_get($url, $args = []) {}

/** @psalm-taint-sink ssrf $url */
function wp_remote_post($url, $args = []) {}

/** @psalm-taint-sink header $location */
function wp_redirect($location, $status = 302, $x_redirect_by = 'WordPress') {}

/** @psalm-taint-sink header $location */
function wp_safe_redirect($location, $status = 302, $x_redirect_by = 'WordPress') {}
