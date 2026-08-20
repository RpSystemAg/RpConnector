<?php
/**
 * Action feasibility smoke — "On the Fragility of Self-Improving Agents"
 * (arXiv 2026-08-13..19), aspetto pre-check.
 *
 * Prima di eseguire un'azione complessa si verifica la fattibilità sullo
 * stato corrente: target esistente, argomenti completi, lane posseduta,
 * budget rispettato, nessun conflitto. Infeasibility = errore tecnico
 * (mai un gate autorizzativo, Law 4/Law 10).
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-action-feasibility.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

$state = array(
    'entities' => array( 'post:42' => true, 'url:https://example.com' => true ),
    'lane_owner' => 'lane_abc',
    'steps_used' => 3,
    'conflicts' => array( 'edit:post:7' ),
);

// 1) Azione fattibile: tutto in ordine.
$feasible = PRSTUDIO_UC_Action_Feasibility::precheck(
    array(
        'id' => 'edit:post:42',
        'action' => 'wordpress_content_transaction',
        'target' => 'post:42',
        'args' => array( 'operation' => 'append_once', 'replacement' => 'x' ),
        'required' => array( 'operation', 'replacement' ),
        'lane_handle' => 'lane_abc',
        'budget' => array( 'max_steps' => 10 ),
        'idempotency_key' => 'k-1',
    ),
    $state
);
ok( true === $feasible['feasible'], 'complete action is feasible' );
ok( empty( $feasible['blocking'] ), 'no blocking reasons on feasible action' );

// 2) Target mancante dallo stato.
$missing_target = PRSTUDIO_UC_Action_Feasibility::precheck(
    array( 'id' => 'edit:post:99', 'target' => 'post:99', 'args' => array(), 'required' => array() ),
    $state
);
ok( false === $missing_target['feasible'], 'missing target is infeasible' );
ok( 'target_exists' === ( $missing_target['blocking'][0]['rule'] ?? '' ), 'blocking reason names the target rule' );

// 3) Argomento richiesto mancante.
$missing_args = PRSTUDIO_UC_Action_Feasibility::precheck(
    array( 'target' => 'post:42', 'args' => array( 'operation' => 'append_once' ), 'required' => array( 'operation', 'replacement' ) ),
    $state
);
ok( false === $missing_args['feasible'], 'missing required argument is infeasible' );
ok( 'args_complete' === ( $missing_args['blocking'][0]['rule'] ?? '' ), 'blocking reason names the args rule' );

// 4) Lane non posseduta.
$wrong_lane = PRSTUDIO_UC_Action_Feasibility::precheck(
    array( 'target' => 'post:42', 'lane_handle' => 'lane_evil', 'args' => array(), 'required' => array() ),
    $state
);
ok( false === $wrong_lane['feasible'], 'foreign lane is infeasible' );
ok( 'lane_owned' === ( $wrong_lane['blocking'][0]['rule'] ?? '' ), 'blocking reason names the lane rule' );

// 5) Budget superato.
$over_budget = PRSTUDIO_UC_Action_Feasibility::precheck(
    array( 'target' => 'post:42', 'budget' => array( 'max_steps' => 2 ), 'args' => array(), 'required' => array() ),
    $state
);
ok( false === $over_budget['feasible'], 'exceeded step budget is infeasible' );

// 6) Conflitto con lavoro in corso.
$conflict = PRSTUDIO_UC_Action_Feasibility::precheck(
    array( 'id' => 'edit:post:7', 'args' => array(), 'required' => array() ),
    $state
);
ok( false === $conflict['feasible'], 'action conflicting with running work is infeasible' );

// 7) Idempotency mancante: warning, non bloccante (Law 3: l'azione esegue).
$no_idem = PRSTUDIO_UC_Action_Feasibility::precheck(
    array( 'target' => 'post:42', 'requires_idempotency' => true, 'args' => array(), 'required' => array() ),
    $state
);
ok( true === $no_idem['feasible'], 'missing idempotency key is a warning, not a blocker' );
ok( 'idempotency_ready' === ( $no_idem['warnings'][0]['rule'] ?? '' ), 'warning names the idempotency rule' );

// 8) Selezione regole: solo le regole richieste vengono valutate.
$only_lane = PRSTUDIO_UC_Action_Feasibility::precheck(
    array( 'target' => 'post:99', 'lane_handle' => 'lane_abc', 'args' => array(), 'required' => array() ),
    $state,
    array( 'lane_owned' )
);
ok( true === $only_lane['feasible'], 'with only the lane rule, missing target is not evaluated' );
ok( array( 'lane_owned' ) === array_keys( $only_lane['checks'] ), 'checks reflect the selected rules only' );

fwrite( STDOUT, "PASS action feasibility smoke complete\n" );
exit( 0 );
