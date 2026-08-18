<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class PRSTUDIO_Domain_Catalog_Commerce extends PRSTUDIO_UC_Domain_Abstract {
    public function id(): string { return 'catalog_commerce'; }
    public function label(): string { return 'Catalogo, prodotti, inventario e commerce'; }
    public function routes(): array { return array( '/products-manage', '/inventory-manage', '/taxonomy-manage', '/coupons-manage', '/commerce-settings-manage' ); }
    public function keywords(): array { return array( 'prodotto', 'catalogo', 'prezzo', 'stock', 'inventario', 'categoria', 'tag', 'attributo', 'coupon', 'spedizione', 'tassa', 'pagamento', 'woocommerce' ); }
}
