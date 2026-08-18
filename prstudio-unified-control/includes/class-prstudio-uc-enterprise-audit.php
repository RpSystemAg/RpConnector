<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only access to the generated enterprise readiness assessment. */
final class PRSTUDIO_UC_Enterprise_Audit {
	private static ?array $report = null;

	public static function report(): array {
		if ( null !== self::$report ) { return self::$report; }
		$path = trailingslashit( PRSTUDIO_UC_DIR ) . 'reports/ENTERPRISE-TOOL-AUDIT.json';
		$data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : array();
		self::$report = is_array( $data ) ? $data : array();
		return self::$report;
	}

	public static function summary(): array {
		$report = self::report();
		return array(
			'version'=>(string)($report['suite_version']??''),
			'generated_at'=>(string)($report['generated_at']??''),
			'method'=>(string)($report['method']??''),
			'counts'=>(array)($report['counts']??array()),
			'average_score'=>(float)($report['average_score']??0),
			'minimum_score'=>(float)($report['minimum_score']??0),
			'complete'=>(int)($report['counts']['audited']??0)===(int)($report['counts']['callable_tools']??-1),
			'certification'=>'internal_static_readiness_assessment',
		);
	}

	public static function tool( string $tool_name ): array {
		$report = self::report();
		$item = $report['tools'][ $tool_name ] ?? array();
		return is_array( $item ) ? $item : array();
	}
}
