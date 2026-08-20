<?php
/**
 * PR STUDIO AI BRIDGE — Media Upload Extension
 *
 * Caricamento sicuro e chunked di immagini Base64 nella Libreria Media.
 * Compatibile con PR STUDIO AI BRIDGE 1.2.0, WordPress 6.5+ e PHP 8.0+.
 *
 * Installazione:
 * 1. Copiare questo file in:
 *    wp-content/plugins/wp-ai-bridge/includes/class-wpaib-media-upload-extension.php
 * 2. In wp-ai-bridge.php, subito dopo il require di class-wpaib-rest.php, aggiungere:
 *    require_once WPAIB_DIR . 'includes/class-wpaib-media-upload-extension.php';
 *
 * Il modulo:
 * - eredita l'autenticazione write del bridge;
 * - accetta soltanto immagini raster sicure (no SVG);
 * - usa chunk da massimo 1 MiB;
 * - verifica SHA-256 per ogni chunk e per il file completo;
 * - limita dimensioni, pixel e sessioni concorrenti;
 * - crea allegato, metadati e miniature tramite API WordPress;
 * - non sovrascrive file esistenti;
 * - pulisce automaticamente upload temporanei scaduti;
 * - non modifica tema, database custom o file core.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPAIB_Media_Upload_Extension', false ) ) {

	final class WPAIB_Media_Upload_Extension {

		private const VERSION              = '2.0.0';
		private const REST_NAMESPACE       = 'wp-ai-bridge/v1';
		private const ROUTE_BASE           = '/media-upload';
		private const SESSION_PREFIX       = 'wpaib_mupload_session_';
		private const REQUEST_PREFIX       = 'wpaib_mupload_request_';
		private const SESSION_TTL          = 3600;
		private const COMPLETED_TTL        = 86400;
		private const MAX_CHUNK_BYTES      = 1048576; // 1 MiB decodificato.
		private const MAX_ACTIVE_SESSIONS  = 24;
		private const MAX_IMAGE_PIXELS     = 36000000; // Protezione da decompression bomb.
		private const CRON_HOOK            = 'wpaib_media_upload_cleanup';
		private const TEMP_PREFIX          = 'wpaib_media_';

		/**
		 * Registra hook REST e pulizia periodica.
		 */
		public static function bootstrap(): void {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 20 );
			add_action( 'init', array( __CLASS__, 'maybe_schedule_cleanup' ), 20 );
			add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup_expired_files' ) );
		}

		/**
		 * Registra endpoint separati e facilmente verificabili.
		 */
		public static function register_routes(): void {
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_BASE . '/capabilities',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'capabilities' ),
					'permission_callback' => array( __CLASS__, 'read_permission' ),
				)
			);

			foreach ( array( 'init', 'chunk', 'status', 'complete', 'cancel' ) as $action ) {
				register_rest_route(
					self::REST_NAMESPACE,
					self::ROUTE_BASE . '/' . $action,
					array(
						'methods'             => 'POST',
						'callback'            => array( __CLASS__, $action ),
						'permission_callback' => array( __CLASS__, 'write_permission' ),
					)
				);
			}
		}

		/**
		 * Usa gli stessi permessi del bridge. Il fallback serve solo per evitare
		 * fatal error durante bootstrap incompleti.
		 *
		 * @return true|WP_Error
		 */
		public static function read_permission() {
			if ( class_exists( 'WPAIB_REST' ) && is_callable( array( 'WPAIB_REST', 'read_permission' ) ) ) {
				return WPAIB_REST::read_permission();
			}

			return current_user_can( 'upload_files' )
				? true
				: new WP_Error( 'wpaib_media_forbidden', 'Permesso di lettura non disponibile.', array( 'status' => 403 ) );
		}

		/**
		 * @return true|WP_Error
		 */
		public static function write_permission() {
			if ( class_exists( 'WPAIB_REST' ) && is_callable( array( 'WPAIB_REST', 'write_permission' ) ) ) {
				return WPAIB_REST::write_permission();
			}

			return current_user_can( 'upload_files' )
				? true
				: new WP_Error( 'wpaib_media_forbidden', 'Permesso di scrittura non disponibile.', array( 'status' => 403 ) );
		}

		/**
		 * Descrive limiti e formati senza modificare il sito.
		 */
		public static function capabilities( WP_REST_Request $request ): WP_REST_Response {
			unset( $request );

			return self::success_response(
				array(
					'module'              => 'WPAIB Media Upload Extension',
					'version'             => self::VERSION,
					'mode'                => 'chunked_base64',
					'max_file_bytes'      => self::max_file_bytes(),
					'max_chunk_bytes'     => self::MAX_CHUNK_BYTES,
					'max_image_pixels'    => self::MAX_IMAGE_PIXELS,
					'session_ttl_seconds' => self::SESSION_TTL,
					'allowed_mimes'       => array_values( self::allowed_mimes() ),
					'routes'              => array(
						'init'     => rest_url( self::REST_NAMESPACE . self::ROUTE_BASE . '/init' ),
						'chunk'    => rest_url( self::REST_NAMESPACE . self::ROUTE_BASE . '/chunk' ),
						'status'   => rest_url( self::REST_NAMESPACE . self::ROUTE_BASE . '/status' ),
						'complete' => rest_url( self::REST_NAMESPACE . self::ROUTE_BASE . '/complete' ),
						'cancel'   => rest_url( self::REST_NAMESPACE . self::ROUTE_BASE . '/cancel' ),
					),
				)
			);
		}

		/**
		 * Inizializza una sessione idempotente.
		 *
		 * JSON richiesto:
		 * {
		 *   "request_id": "...",
		 *   "filename": "immagine.png",
		 *   "file_size": 123456,
		 *   "file_sha256": "...64 hex...",
		 *   "mime_type": "image/png",
		 *   "parent_id": 0,
		 *   "title": "...",
		 *   "alt_text": "...",
		 *   "caption": "...",
		 *   "description": "..."
		 * }
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public static function init( WP_REST_Request $request ) {
			return self::guard(
				'init',
				static function () use ( $request ) {
					$body = self::json_body( $request );

					$request_id = self::clean_request_id( $body['request_id'] ?? '' );
					$filename   = self::clean_filename( $body['filename'] ?? '' );
					$file_size  = self::positive_int( $body['file_size'] ?? 0 );
					$file_hash  = self::clean_sha256( $body['file_sha256'] ?? '' );
					$mime_type  = sanitize_mime_type( (string) ( $body['mime_type'] ?? '' ) );
					$parent_id  = absint( $body['parent_id'] ?? 0 );
					$metadata   = self::normalize_metadata( $body );

					if ( '' === $request_id ) {
						return self::error( 'wpaib_media_request_id_invalid', 'request_id mancante o non valido.', 400 );
					}
					if ( is_wp_error( $filename ) ) {
						return $filename;
					}
					if ( $file_size < 1 || $file_size > self::max_file_bytes() ) {
						return self::error(
							'wpaib_media_size_invalid',
							'Dimensione file non valida o superiore al limite del bridge.',
							413,
							array( 'max_file_bytes' => self::max_file_bytes() )
						);
					}
					if ( is_wp_error( $file_hash ) ) {
						return $file_hash;
					}
					if ( '' !== $mime_type && ! in_array( $mime_type, self::allowed_mimes(), true ) ) {
						return self::error( 'wpaib_media_mime_not_allowed', 'Tipo MIME non consentito.', 415 );
					}
					if ( $parent_id > 0 && ! get_post( $parent_id ) ) {
						return self::error( 'wpaib_media_parent_missing', 'Il contenuto parent indicato non esiste.', 404 );
					}

					$request_key = self::REQUEST_PREFIX . hash( 'sha256', $request_id );
					$previous    = get_transient( $request_key );

					if ( is_array( $previous ) ) {
						if ( 'complete' === ( $previous['status'] ?? '' ) && ! empty( $previous['attachment_id'] ) ) {
							$attachment_id = absint( $previous['attachment_id'] );
							if ( get_post( $attachment_id ) ) {
								return self::success_response(
									array(
										'idempotent'    => true,
										'status'        => 'complete',
										'attachment_id' => $attachment_id,
										'media'         => self::attachment_payload( $attachment_id ),
									)
								);
							}
						}

						if ( 'active' === ( $previous['status'] ?? '' ) && ! empty( $previous['session_id'] ) ) {
							$existing = self::get_session( (string) $previous['session_id'] );
							if ( is_array( $existing ) ) {
								if (
									hash_equals( (string) $existing['file_sha256'], $file_hash )
									&& (int) $existing['file_size'] === $file_size
								) {
									return self::success_response(
										array(
											'idempotent' => true,
											'status'     => 'active',
											'session'    => self::public_session( $existing ),
										)
									);
								}

								return self::error(
									'wpaib_media_request_id_conflict',
									'request_id già usato per un file differente.',
									409
								);
							}
						}
					}

					if ( self::count_active_temp_files() >= self::MAX_ACTIVE_SESSIONS ) {
						return self::error(
							'wpaib_media_too_many_sessions',
							'Troppe sessioni di caricamento attive. Riprovare dopo la pulizia automatica.',
							429
						);
					}

					$temp_dir = self::temp_dir();
					if ( is_wp_error( $temp_dir ) ) {
						return $temp_dir;
					}

					$temp_path = tempnam( $temp_dir, self::TEMP_PREFIX );
					if ( false === $temp_path ) {
						return self::error( 'wpaib_media_temp_create_failed', 'Impossibile creare il file temporaneo.', 500 );
					}

					@chmod( $temp_path, 0600 );

					$session_id = bin2hex( random_bytes( 24 ) );
					$now        = time();
					$session    = array(
						'session_id'  => $session_id,
						'request_id'  => $request_id,
						'filename'    => $filename,
						'file_size'   => $file_size,
						'file_sha256' => $file_hash,
						'mime_type'   => $mime_type,
						'parent_id'   => $parent_id,
						'metadata'    => $metadata,
						'temp_path'   => $temp_path,
						'received'    => 0,
						'created_at'  => $now,
						'updated_at'  => $now,
						'expires_at'  => $now + self::SESSION_TTL,
					);

					if ( ! set_transient( self::session_key( $session_id ), $session, self::SESSION_TTL ) ) {
						@unlink( $temp_path );
						return self::error( 'wpaib_media_session_store_failed', 'Impossibile salvare la sessione.', 500 );
					}

					set_transient(
						$request_key,
						array(
							'status'     => 'active',
							'session_id' => $session_id,
						),
						self::SESSION_TTL
					);

					self::audit(
						'media_upload.init',
						'info',
						$filename,
						array(
							'request_id' => $request_id,
							'file_size'  => $file_size,
							'mime_type'  => $mime_type,
						)
					);

					return self::success_response(
						array(
							'idempotent' => false,
							'status'     => 'active',
							'session'    => self::public_session( $session ),
						),
						201
					);
				}
			);
		}

		/**
		 * Riceve un chunk Base64 con offset e SHA-256.
		 *
		 * JSON:
		 * {
		 *   "session_id": "...",
		 *   "offset": 0,
		 *   "chunk_sha256": "...",
		 *   "data_b64": "..."
		 * }
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public static function chunk( WP_REST_Request $request ) {
			return self::guard(
				'chunk',
				static function () use ( $request ) {
					$body       = self::json_body( $request );
					$session_id = self::clean_session_id( $body['session_id'] ?? '' );
					$offset     = self::non_negative_int( $body['offset'] ?? -1 );
					$chunk_hash = self::clean_sha256( $body['chunk_sha256'] ?? '' );

					if ( is_wp_error( $session_id ) ) {
						return $session_id;
					}
					if ( $offset < 0 ) {
						return self::error( 'wpaib_media_offset_invalid', 'Offset non valido.', 400 );
					}
					if ( is_wp_error( $chunk_hash ) ) {
						return $chunk_hash;
					}

					$session = self::get_session( $session_id );
					if ( is_wp_error( $session ) ) {
						return $session;
					}

					$data_b64 = (string) ( $body['data_b64'] ?? '' );
					if ( str_starts_with( $data_b64, 'data:' ) ) {
						$comma = strpos( $data_b64, ',' );
						if ( false === $comma ) {
							return self::error( 'wpaib_media_base64_invalid', 'Data URL Base64 non valida.', 400 );
						}
						$data_b64 = substr( $data_b64, $comma + 1 );
					}

					$data_b64 = preg_replace( '/\s+/', '', $data_b64 );
					if ( ! is_string( $data_b64 ) || '' === $data_b64 ) {
						return self::error( 'wpaib_media_chunk_empty', 'Chunk vuoto.', 400 );
					}

					$max_encoded = (int) ceil( self::MAX_CHUNK_BYTES / 3 ) * 4 + 8;
					if ( strlen( $data_b64 ) > $max_encoded ) {
						return self::error(
							'wpaib_media_chunk_too_large',
							'Chunk superiore al limite consentito.',
							413,
							array( 'max_chunk_bytes' => self::MAX_CHUNK_BYTES )
						);
					}

					$decoded = base64_decode( $data_b64, true );
					if ( false === $decoded ) {
						return self::error( 'wpaib_media_base64_invalid', 'Base64 non valido.', 400 );
					}

					$decoded_length = strlen( $decoded );
					if ( $decoded_length < 1 || $decoded_length > self::MAX_CHUNK_BYTES ) {
						return self::error( 'wpaib_media_chunk_size_invalid', 'Dimensione chunk non valida.', 413 );
					}
					if ( ! hash_equals( $chunk_hash, hash( 'sha256', $decoded ) ) ) {
						return self::error( 'wpaib_media_chunk_hash_mismatch', 'SHA-256 del chunk non corrispondente.', 422 );
					}
					if ( $offset + $decoded_length > (int) $session['file_size'] ) {
						return self::error( 'wpaib_media_chunk_overflow', 'Il chunk supera la dimensione prevista.', 422 );
					}

					$temp_path = (string) $session['temp_path'];
					if ( ! self::is_valid_temp_path( $temp_path ) ) {
						return self::error( 'wpaib_media_temp_invalid', 'Percorso temporaneo non valido.', 500 );
					}

					$handle = @fopen( $temp_path, 'c+b' );
					if ( false === $handle ) {
						return self::error( 'wpaib_media_temp_open_failed', 'Impossibile aprire il file temporaneo.', 500 );
					}

					$idempotent = false;

					try {
						if ( ! flock( $handle, LOCK_EX ) ) {
							return self::error( 'wpaib_media_lock_failed', 'Impossibile acquisire il lock del file.', 503 );
						}

						$stat         = fstat( $handle );
						$current_size = is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : 0;

						if ( $offset > $current_size ) {
							return self::error(
								'wpaib_media_offset_gap',
								'Offset superiore ai byte già ricevuti.',
								409,
								array( 'expected_offset' => $current_size )
							);
						}

						if ( $offset < $current_size ) {
							if ( $offset + $decoded_length > $current_size ) {
								return self::error(
									'wpaib_media_chunk_overlap',
									'Il chunk si sovrappone parzialmente a dati esistenti.',
									409
								);
							}

							if ( 0 !== fseek( $handle, $offset ) ) {
								return self::error( 'wpaib_media_seek_failed', 'Impossibile leggere il chunk esistente.', 500 );
							}

							$existing = '';
							while ( strlen( $existing ) < $decoded_length && ! feof( $handle ) ) {
								$piece = fread( $handle, $decoded_length - strlen( $existing ) );
								if ( false === $piece ) {
									return self::error( 'wpaib_media_read_failed', 'Lettura del chunk esistente fallita.', 500 );
								}
								$existing .= $piece;
							}

							if ( strlen( $existing ) !== $decoded_length || ! hash_equals( hash( 'sha256', $existing ), $chunk_hash ) ) {
								return self::error(
									'wpaib_media_chunk_conflict',
									'I byte già presenti non corrispondono al chunk ripetuto.',
									409
								);
							}

							$idempotent = true;
						} else {
							if ( 0 !== fseek( $handle, $offset ) ) {
								return self::error( 'wpaib_media_seek_failed', 'Impossibile posizionare il cursore di scrittura.', 500 );
							}

							$written = 0;
							while ( $written < $decoded_length ) {
								$count = fwrite( $handle, substr( $decoded, $written ) );
								if ( false === $count || 0 === $count ) {
									return self::error( 'wpaib_media_write_failed', 'Scrittura del chunk fallita.', 500 );
								}
								$written += $count;
							}

							fflush( $handle );
							if ( function_exists( 'fsync' ) ) {
								@fsync( $handle );
							}
							$current_size += $decoded_length;
						}
					} finally {
						@flock( $handle, LOCK_UN );
						@fclose( $handle );
					}

					clearstatcache( true, $temp_path );
					$received              = (int) @filesize( $temp_path );
					$session['received']   = $received;
					$session['updated_at'] = time();
					$session['expires_at'] = time() + self::SESSION_TTL;

					self::save_session( $session );

					return self::success_response(
						array(
							'idempotent'     => $idempotent,
							'session_id'     => $session_id,
							'received_bytes' => $received,
							'file_size'      => (int) $session['file_size'],
							'next_offset'    => $received,
							'complete'       => $received === (int) $session['file_size'],
						)
					);
				}
			);
		}

		/**
		 * Stato della sessione, senza esporre percorsi server.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public static function status( WP_REST_Request $request ) {
			return self::guard(
				'status',
				static function () use ( $request ) {
					$body       = self::json_body( $request );
					$session_id = self::clean_session_id( $body['session_id'] ?? '' );

					if ( is_wp_error( $session_id ) ) {
						return $session_id;
					}

					$session = self::get_session( $session_id );
					if ( is_wp_error( $session ) ) {
						return $session;
					}

					$temp_path = (string) $session['temp_path'];
					if ( is_file( $temp_path ) ) {
						clearstatcache( true, $temp_path );
						$received = (int) @filesize( $temp_path );
						if ( $received !== (int) ( $session['received'] ?? 0 ) ) {
							$session['received'] = $received;
							self::save_session( $session );
						}
					}

					return self::success_response(
						array(
							'status'  => 'active',
							'session' => self::public_session( $session ),
						)
					);
				}
			);
		}

		/**
		 * Verifica, importa e registra il file nella Libreria Media.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public static function complete( WP_REST_Request $request ) {
			return self::guard(
				'complete',
				static function () use ( $request ) {
					$body       = self::json_body( $request );
					$session_id = self::clean_session_id( $body['session_id'] ?? '' );

					if ( is_wp_error( $session_id ) ) {
						return $session_id;
					}

					$session = self::get_session( $session_id );
					if ( is_wp_error( $session ) ) {
						return $session;
					}

					$temp_path = (string) $session['temp_path'];
					if ( ! self::is_valid_temp_path( $temp_path ) || ! is_file( $temp_path ) ) {
						return self::error( 'wpaib_media_temp_missing', 'File temporaneo mancante.', 410 );
					}

					clearstatcache( true, $temp_path );
					$actual_size = (int) @filesize( $temp_path );
					if ( $actual_size !== (int) $session['file_size'] ) {
						return self::error(
							'wpaib_media_incomplete',
							'Upload incompleto.',
							409,
							array(
								'received_bytes' => $actual_size,
								'file_size'      => (int) $session['file_size'],
								'next_offset'    => $actual_size,
							)
						);
					}

					$actual_hash = hash_file( 'sha256', $temp_path );
					if ( ! is_string( $actual_hash ) || ! hash_equals( (string) $session['file_sha256'], $actual_hash ) ) {
						self::destroy_session( $session, true );
						return self::error(
							'wpaib_media_file_hash_mismatch',
							'SHA-256 del file completo non corrispondente. La sessione è stata annullata.',
							422
						);
					}

					$validation = self::validate_image_file( $temp_path, (string) $session['filename'] );
					if ( is_wp_error( $validation ) ) {
						self::destroy_session( $session, true );
						return $validation;
					}

					$declared_mime = (string) $session['mime_type'];
					if ( '' !== $declared_mime && $declared_mime !== (string) $validation['mime_type'] ) {
						self::destroy_session( $session, true );
						return self::error(
							'wpaib_media_declared_mime_mismatch',
							'Il MIME dichiarato non corrisponde al contenuto reale.',
							422
						);
					}

					$filename = (string) ( $validation['proper_filename'] ?: $session['filename'] );
					$metadata = (array) $session['metadata'];

					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';

					if ( function_exists( 'wp_raise_memory_limit' ) ) {
						wp_raise_memory_limit( 'image' );
					}

					$file_array = array(
						'name'     => $filename,
						'type'     => (string) $validation['mime_type'],
						'tmp_name' => $temp_path,
						'error'    => 0,
						'size'     => $actual_size,
					);

					$upload = wp_handle_sideload(
						$file_array,
						array(
							'test_form' => false,
							'mimes'     => self::allowed_mimes(),
						)
					);

					if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
						$message = is_array( $upload ) ? (string) ( $upload['error'] ?? '' ) : '';
						return self::error(
							'wpaib_media_sideload_failed',
							'WordPress non ha potuto spostare il file nella Libreria Media.' . ( '' !== $message ? ' ' . $message : '' ),
							500
						);
					}

					$uploaded_file = (string) ( $upload['file'] ?? '' );
					$uploaded_url  = (string) ( $upload['url'] ?? '' );
					$uploaded_mime = (string) ( $upload['type'] ?? $validation['mime_type'] );

					$attachment_post = array(
						'post_mime_type' => $uploaded_mime,
						'post_title'     => '' !== $metadata['title']
							? $metadata['title']
							: self::filename_title( $filename ),
						'post_content'   => $metadata['description'],
						'post_excerpt'   => $metadata['caption'],
						'post_status'    => 'inherit',
						'post_parent'    => absint( $session['parent_id'] ),
						'guid'           => esc_url_raw( $uploaded_url ),
					);

					$attachment_id = wp_insert_attachment(
						$attachment_post,
						$uploaded_file,
						absint( $session['parent_id'] ),
						true
					);

					if ( is_wp_error( $attachment_id ) ) {
						@unlink( $uploaded_file );
						return self::error(
							'wpaib_media_attachment_insert_failed',
							'Creazione dell’allegato WordPress fallita.',
							500,
							array( 'wordpress_error' => $attachment_id->get_error_code() )
						);
					}

					$attachment_id = absint( $attachment_id );

					try {
						if ( '' !== $metadata['alt_text'] ) {
							update_post_meta( $attachment_id, '_wp_attachment_image_alt', $metadata['alt_text'] );
						} else {
							delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
						}

						$generated_metadata = wp_generate_attachment_metadata( $attachment_id, $uploaded_file );
						if ( is_wp_error( $generated_metadata ) ) {
							throw new RuntimeException( $generated_metadata->get_error_message() );
						}
						if ( is_array( $generated_metadata ) ) {
							wp_update_attachment_metadata( $attachment_id, $generated_metadata );
						}

						$attachment = get_post( $attachment_id );
						if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
							throw new RuntimeException( 'Allegato non rileggibile dopo la creazione.' );
						}
					} catch ( Throwable $exception ) {
						wp_delete_attachment( $attachment_id, true );
						self::audit(
							'media_upload.rollback',
							'error',
							$filename,
							array(
								'request_id' => (string) $session['request_id'],
								'message'    => self::safe_exception_message( $exception ),
							)
						);

						return self::error(
							'wpaib_media_metadata_failed',
							'Generazione dei metadati fallita: allegato e file sono stati rimossi.',
							500
						);
					}

					set_transient(
						self::REQUEST_PREFIX . hash( 'sha256', (string) $session['request_id'] ),
						array(
							'status'        => 'complete',
							'attachment_id' => $attachment_id,
							'file_sha256'   => $actual_hash,
						),
						self::COMPLETED_TTL
					);

					self::destroy_session( $session, false );

					self::audit(
						'media_upload.complete',
						'info',
						$filename,
						array(
							'request_id'    => (string) $session['request_id'],
							'attachment_id' => $attachment_id,
							'file_size'     => $actual_size,
							'mime_type'     => $uploaded_mime,
							'width'         => (int) $validation['width'],
							'height'        => (int) $validation['height'],
							'sha256'        => $actual_hash,
						)
					);

					return self::success_response(
						array(
							'status'        => 'complete',
							'attachment_id' => $attachment_id,
							'file_sha256'   => $actual_hash,
							'media'         => self::attachment_payload( $attachment_id ),
						),
						201
					);
				}
			);
		}

		/**
		 * Annulla la sessione e rimuove soltanto il temporaneo.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public static function cancel( WP_REST_Request $request ) {
			return self::guard(
				'cancel',
				static function () use ( $request ) {
					$body       = self::json_body( $request );
					$session_id = self::clean_session_id( $body['session_id'] ?? '' );

					if ( is_wp_error( $session_id ) ) {
						return $session_id;
					}

					$session = get_transient( self::session_key( $session_id ) );
					if ( ! is_array( $session ) ) {
						return self::success_response(
							array(
								'idempotent' => true,
								'status'     => 'cancelled_or_expired',
								'session_id' => $session_id,
							)
						);
					}

					self::destroy_session( $session, true );

					self::audit(
						'media_upload.cancel',
						'info',
						(string) ( $session['filename'] ?? '' ),
						array( 'request_id' => (string) ( $session['request_id'] ?? '' ) )
					);

					return self::success_response(
						array(
							'idempotent' => false,
							'status'     => 'cancelled',
							'session_id' => $session_id,
						)
					);
				}
			);
		}

		/**
		 * Pianifica una pulizia leggera e idempotente.
		 */
		public static function maybe_schedule_cleanup(): void {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
			}
		}

		/**
		 * Rimuove solo file temporanei creati dal modulo e più vecchi del TTL.
		 */
		public static function cleanup_expired_files(): int {
			$temp_dir = self::temp_dir();
			if ( is_wp_error( $temp_dir ) || ! is_dir( $temp_dir ) ) {
				return 0;
			}

			$deleted = 0;
			$cutoff  = time() - ( self::SESSION_TTL * 2 );
			$files   = glob( trailingslashit( $temp_dir ) . self::TEMP_PREFIX . '*' );

			if ( ! is_array( $files ) ) {
				return 0;
			}

			foreach ( $files as $file ) {
				if ( ! is_file( $file ) || ! self::is_valid_temp_path( $file ) ) {
					continue;
				}

				$modified = @filemtime( $file );
				if ( false !== $modified && $modified < $cutoff && @unlink( $file ) ) {
					++$deleted;
				}
			}

			if ( $deleted > 0 ) {
				self::audit( 'media_upload.cleanup', 'info', 'temporary_files', array( 'deleted' => $deleted ) );
			}

			return $deleted;
		}

		/**
		 * Esegue una callback senza propagare eccezioni al ciclo REST.
		 *
		 * @param string   $operation Operazione corrente.
		 * @param callable $callback  Callback.
		 * @return mixed
		 */
		private static function guard( string $operation, callable $callback ) {
			try {
				return $callback();
			} catch ( Throwable $exception ) {
				self::audit(
					'media_upload.exception',
					'error',
					$operation,
					array( 'message' => self::safe_exception_message( $exception ) )
				);

				return self::error(
					'wpaib_media_unexpected_error',
					'Errore interno controllato durante il caricamento media.',
					500
				);
			}
		}

		/**
		 * @return array<string,mixed>
		 */
		private static function json_body( WP_REST_Request $request ): array {
			$body = $request->get_json_params();
			return is_array( $body ) ? $body : array();
		}

		/**
		 * @return array<string,string>
		 */
		private static function normalize_metadata( array $body ): array {
			return array(
				'title'       => self::limit_string(
					sanitize_text_field( (string) ( $body['title'] ?? '' ) ),
					300
				),
				'alt_text'    => self::limit_string(
					sanitize_text_field( (string) ( $body['alt_text'] ?? '' ) ),
					1000
				),
				'caption'     => self::limit_string(
					wp_kses_post( (string) ( $body['caption'] ?? '' ) ),
					4000
				),
				'description' => self::limit_string(
					wp_kses_post( (string) ( $body['description'] ?? '' ) ),
					30000
				),
			);
		}

		/**
		 * @return string|WP_Error
		 */
		private static function clean_filename( $value ) {
			$raw = wp_basename( (string) $value );
			if ( '' === $raw || $raw !== (string) $value && false !== strpos( (string) $value, '..' ) ) {
				return self::error( 'wpaib_media_filename_invalid', 'Nome file non valido.', 400 );
			}

			$filename = sanitize_file_name( $raw );
			$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

			if ( '' === $filename || '' === $ext ) {
				return self::error( 'wpaib_media_filename_invalid', 'Nome file o estensione non validi.', 400 );
			}
			if ( ! self::extension_is_allowed( $ext ) ) {
				return self::error( 'wpaib_media_extension_not_allowed', 'Estensione non consentita.', 415 );
			}

			return $filename;
		}

		private static function clean_request_id( $value ): string {
			$value = trim( sanitize_text_field( (string) $value ) );
			if ( 1 !== preg_match( '/^[A-Za-z0-9_.:-]{8,160}$/', $value ) ) {
				return '';
			}
			return $value;
		}

		/**
		 * @return string|WP_Error
		 */
		private static function clean_session_id( $value ) {
			$value = strtolower( trim( (string) $value ) );
			if ( 1 !== preg_match( '/^[a-f0-9]{48}$/', $value ) ) {
				return self::error( 'wpaib_media_session_invalid', 'session_id non valido.', 400 );
			}
			return $value;
		}

		/**
		 * @return string|WP_Error
		 */
		private static function clean_sha256( $value ) {
			$value = strtolower( trim( (string) $value ) );
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) {
				return self::error( 'wpaib_media_sha256_invalid', 'SHA-256 non valido.', 400 );
			}
			return $value;
		}

		private static function positive_int( $value ): int {
			if ( ! is_numeric( $value ) ) {
				return 0;
			}
			$value = (int) $value;
			return $value > 0 ? $value : 0;
		}

		private static function non_negative_int( $value ): int {
			if ( ! is_numeric( $value ) ) {
				return -1;
			}
			$value = (int) $value;
			return $value >= 0 ? $value : -1;
		}

		private static function session_key( string $session_id ): string {
			return self::SESSION_PREFIX . $session_id;
		}

		/**
		 * @return array<string,mixed>|WP_Error
		 */
		private static function get_session( string $session_id ) {
			$session = get_transient( self::session_key( $session_id ) );

			if ( ! is_array( $session ) ) {
				return self::error( 'wpaib_media_session_expired', 'Sessione inesistente o scaduta.', 410 );
			}

			if ( (int) ( $session['expires_at'] ?? 0 ) < time() ) {
				self::destroy_session( $session, true );
				return self::error( 'wpaib_media_session_expired', 'Sessione scaduta.', 410 );
			}

			return $session;
		}

		private static function save_session( array $session ): void {
			set_transient(
				self::session_key( (string) $session['session_id'] ),
				$session,
				self::SESSION_TTL
			);
		}

		private static function destroy_session( array $session, bool $delete_temp ): void {
			$session_id = (string) ( $session['session_id'] ?? '' );

			if ( '' !== $session_id ) {
				delete_transient( self::session_key( $session_id ) );
			}

			$request_id = (string) ( $session['request_id'] ?? '' );
			if ( '' !== $request_id ) {
				$request_key = self::REQUEST_PREFIX . hash( 'sha256', $request_id );
				$current     = get_transient( $request_key );

				if ( is_array( $current ) && 'active' === ( $current['status'] ?? '' ) ) {
					delete_transient( $request_key );
				}
			}

			if ( $delete_temp ) {
				$temp_path = (string) ( $session['temp_path'] ?? '' );
				if ( self::is_valid_temp_path( $temp_path ) && is_file( $temp_path ) ) {
					@unlink( $temp_path );
				}
			}
		}

		/**
		 * @return array<string,mixed>
		 */
		private static function public_session( array $session ): array {
			$received = (int) ( $session['received'] ?? 0 );
			$file_size = (int) ( $session['file_size'] ?? 0 );

			return array(
				'session_id'      => (string) $session['session_id'],
				'filename'        => (string) $session['filename'],
				'file_size'       => $file_size,
				'received_bytes'  => $received,
				'next_offset'     => $received,
				'complete'        => $file_size > 0 && $received === $file_size,
				'expires_at_gmt'  => gmdate( 'c', (int) $session['expires_at'] ),
				'max_chunk_bytes' => self::MAX_CHUNK_BYTES,
			);
		}

		/**
		 * @return string|WP_Error
		 */
		private static function temp_dir() {
			$base = trailingslashit( get_temp_dir() ) . 'wpaib-media-upload';

			if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
				return self::error( 'wpaib_media_temp_dir_failed', 'Impossibile creare la directory temporanea.', 500 );
			}

			if ( ! is_writable( $base ) ) {
				return self::error( 'wpaib_media_temp_dir_not_writable', 'Directory temporanea non scrivibile.', 500 );
			}

			// Difesa aggiuntiva se il temp directory ricade nella webroot Apache.
			$webroot = wp_normalize_path( ABSPATH );
			$normal  = wp_normalize_path( $base );

			if ( str_starts_with( $normal, $webroot ) ) {
				$index = trailingslashit( $base ) . 'index.php';
				$deny  = trailingslashit( $base ) . '.htaccess';

				if ( ! file_exists( $index ) ) {
					@file_put_contents( $index, "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX );
				}
				if ( ! file_exists( $deny ) ) {
					@file_put_contents( $deny, "Require all denied\nDeny from all\n", LOCK_EX );
				}
			}

			return $base;
		}

		private static function is_valid_temp_path( string $path ): bool {
			if ( '' === $path || ! is_file( $path ) ) {
				return false;
			}

			$temp_dir = self::temp_dir();
			if ( is_wp_error( $temp_dir ) ) {
				return false;
			}

			$real_dir  = realpath( $temp_dir );
			$real_file = realpath( $path );

			if ( false === $real_dir || false === $real_file ) {
				return false;
			}

			$real_dir  = rtrim( wp_normalize_path( $real_dir ), '/' ) . '/';
			$real_file = wp_normalize_path( $real_file );

			return str_starts_with( $real_file, $real_dir )
				&& str_starts_with( basename( $real_file ), self::TEMP_PREFIX );
		}

		private static function count_active_temp_files(): int {
			$temp_dir = self::temp_dir();
			if ( is_wp_error( $temp_dir ) ) {
				return self::MAX_ACTIVE_SESSIONS;
			}

			$files = glob( trailingslashit( $temp_dir ) . self::TEMP_PREFIX . '*' );
			return is_array( $files ) ? count( array_filter( $files, 'is_file' ) ) : 0;
		}

		private static function max_file_bytes(): int {
			$settings   = get_option( 'wpaib_settings', array() );
			$configured = is_array( $settings ) ? absint( $settings['max_file_bytes'] ?? 0 ) : 0;

			if ( $configured < 1048576 ) {
				$configured = 8 * 1024 * 1024;
			}

			// Limite assoluto del modulo, anche se l'opzione viene impostata male.
			return min( $configured, 32 * 1024 * 1024 );
		}

		/**
		 * @return array<string,string>
		 */
		private static function allowed_mimes(): array {
			return array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
				'avif'         => 'image/avif',
			);
		}

		private static function extension_is_allowed( string $extension ): bool {
			foreach ( array_keys( self::allowed_mimes() ) as $pattern ) {
				if ( in_array( $extension, explode( '|', $pattern ), true ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * @return array<string,mixed>|WP_Error
		 */
		private static function validate_image_file( string $path, string $filename ) {
			if ( ! is_readable( $path ) ) {
				return self::error( 'wpaib_media_temp_unreadable', 'File temporaneo non leggibile.', 500 );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';

			$checked = wp_check_filetype_and_ext( $path, $filename, self::allowed_mimes() );
			$ext     = strtolower( (string) ( $checked['ext'] ?? '' ) );
			$mime    = sanitize_mime_type( (string) ( $checked['type'] ?? '' ) );

			if ( '' === $ext || '' === $mime || ! in_array( $mime, self::allowed_mimes(), true ) ) {
				return self::error( 'wpaib_media_content_type_invalid', 'Il contenuto non è un’immagine consentita.', 415 );
			}

			$image_info = @getimagesize( $path );
			if ( ! is_array( $image_info ) || empty( $image_info[0] ) || empty( $image_info[1] ) ) {
				return self::error( 'wpaib_media_image_invalid', 'Immagine non valida o non decodificabile.', 415 );
			}

			$width       = (int) $image_info[0];
			$height      = (int) $image_info[1];
			$actual_mime = sanitize_mime_type( (string) ( $image_info['mime'] ?? '' ) );
			$pixels      = $width * $height;

			if ( $actual_mime !== $mime || ! in_array( $actual_mime, self::allowed_mimes(), true ) ) {
				return self::error( 'wpaib_media_real_mime_invalid', 'Firma MIME reale non consentita.', 415 );
			}

			if ( $width < 1 || $height < 1 || $pixels > self::MAX_IMAGE_PIXELS ) {
				return self::error(
					'wpaib_media_dimensions_invalid',
					'Dimensioni immagine non valide o numero di pixel eccessivo.',
					413,
					array( 'max_image_pixels' => self::MAX_IMAGE_PIXELS )
				);
			}

			return array(
				'extension'       => $ext,
				'mime_type'       => $actual_mime,
				'width'           => $width,
				'height'          => $height,
				'pixels'          => $pixels,
				'proper_filename' => sanitize_file_name( (string) ( $checked['proper_filename'] ?? '' ) ),
			);
		}

		private static function filename_title( string $filename ): string {
			$title = pathinfo( $filename, PATHINFO_FILENAME );
			$title = str_replace( array( '-', '_' ), ' ', $title );
			return self::limit_string( sanitize_text_field( ucwords( $title ) ), 300 );
		}

		/**
		 * @return array<string,mixed>
		 */
		private static function attachment_payload( int $attachment_id ): array {
			$post     = get_post( $attachment_id );
			$metadata = wp_get_attachment_metadata( $attachment_id );
			$url      = wp_get_attachment_url( $attachment_id );
			$file     = get_attached_file( $attachment_id );

			return array(
				'id'            => $attachment_id,
				'title'         => $post ? get_the_title( $post ) : '',
				'alt_text'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'caption'       => $post ? (string) $post->post_excerpt : '',
				'description'   => $post ? (string) $post->post_content : '',
				'parent_id'     => $post ? (int) $post->post_parent : 0,
				'mime_type'     => get_post_mime_type( $attachment_id ),
				'url'           => $url ? esc_url_raw( $url ) : '',
				'file'          => $file ? wp_basename( $file ) : '',
				'width'         => is_array( $metadata ) ? (int) ( $metadata['width'] ?? 0 ) : 0,
				'height'        => is_array( $metadata ) ? (int) ( $metadata['height'] ?? 0 ) : 0,
				'modified_gmt'  => $post ? get_gmt_from_date( $post->post_modified ) : '',
				'edit_url'      => get_edit_post_link( $attachment_id, 'raw' ) ?: '',
			);
		}

		private static function limit_string( string $value, int $max_length ): string {
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $value, 0, $max_length );
			}
			return substr( $value, 0, $max_length );
		}

		private static function safe_exception_message( Throwable $exception ): string {
			$message = sanitize_text_field( $exception->getMessage() );
			return self::limit_string( $message, 500 );
		}

		private static function audit( string $action, string $level, string $target, array $context = array() ): void {
			if ( ! class_exists( 'WPAIB_Audit' ) || ! is_callable( array( 'WPAIB_Audit', 'log' ) ) ) {
				return;
			}

			try {
				WPAIB_Audit::log( $action, $level, $target, $context );
			} catch ( Throwable $exception ) {
				// L'audit non deve mai interrompere un upload.
				unset( $exception );
			}
		}

		/**
		 * @return WP_REST_Response
		 */
		private static function success_response( array $data, int $status = 200 ): WP_REST_Response {
			$response = new WP_REST_Response(
				array(
					'success' => true,
					'status'  => $status,
					'data'    => $data,
				),
				$status
			);
			$response->header( 'Cache-Control', 'no-store, private' );
			$response->header( 'X-WPAIB-Media-Upload-Version', self::VERSION );
			return $response;
		}

		/**
		 * @return WP_Error
		 */
		private static function error( string $code, string $message, int $status, array $extra = array() ): WP_Error {
			return new WP_Error(
				$code,
				$message,
				array_merge( array( 'status' => $status ), $extra )
			);
		}
	}

	WPAIB_Media_Upload_Extension::bootstrap();
}
