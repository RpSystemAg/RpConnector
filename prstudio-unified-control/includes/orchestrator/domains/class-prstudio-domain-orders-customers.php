<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class PRSTUDIO_Domain_Orders_Customers extends PRSTUDIO_UC_Domain_Abstract {
    public function id(): string { return 'orders_customers'; }
    public function label(): string { return 'Ordini e clienti'; }
    public function routes(): array { return array( '/orders-manage', '/customers-manage' ); }
    public function keywords(): array { return array( 'ordine', 'ordini', 'cliente', 'clienti', 'rimborso', 'spedizione ordine', 'nota ordine', 'customer' ); }
}
