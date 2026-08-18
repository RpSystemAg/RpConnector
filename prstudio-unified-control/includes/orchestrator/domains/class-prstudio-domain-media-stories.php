<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class PRSTUDIO_Domain_Media_Stories extends PRSTUDIO_UC_Domain_Abstract {
    public function id(): string { return 'media_stories'; }
    public function label(): string { return 'Media, immagini e Web Stories'; }
    public function routes(): array { return array( '/media-manage', '/web-stories-manage' ); }
    public function keywords(): array { return array( 'media', 'immagine', 'allegato', 'alt', 'caption', 'thumbnail', 'web story', 'storia', 'video' ); }
}
