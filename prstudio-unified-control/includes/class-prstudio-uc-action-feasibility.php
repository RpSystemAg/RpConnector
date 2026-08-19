<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Verifica di fattibilità delle azioni prima dell'esecuzione.
 *
 * Riferimento: "On the Fragility of Self-Improving Agents: Variance, Task
 * Order and a Way Forward" (arXiv, settimana 13-19 agosto 2026) — aspetto
 * fragilità dell'esecuzione multi-step.
 *
 * Prima di eseguire un'azione complessa, verifica che sia fattibile dato lo
 * stato corrente: il target esiste, gli argomenti richiesti ci sono, la lane
 * è posseduta, il budget è rispettato, non ci sono conflitti noti. Obiettivo:
 * evitare fallimenti a metà esecuzione (un'azione che parte e poi scopre che
 * il post non esiste lascia stato intermedio e rumore).
 *
 * È un controllo di correttezza tecnica (runtime invariant), NON un gate
 * autorizzativo: le infeasibilità restituite sono errori tecnici con codice
 * stabile `action_infeasible`; nulla qui approva o rifiuta per policy (Law 4,
 * Law 10).
 */
final class PRSTUDIO_UC_Action_Feasibility {
    public const VERSION = '1.0.0';

    /**
     * Pre-check completo.
     *
     * @param array<string,mixed> $action {id?,action?,target?,args?,required?,lane_handle?,budget?,idempotency_key?}
     * @param array<string,mixed> $state  {entities?,lane_owner?,steps_used?,conflicts?,capabilities?}
     * @param array<int,string>   $rules  Sottoinsieme di regole da applicare (default: tutte).
     * @return array{feasible:bool,blocking:array<int,array{rule:string,reason:string}>,warnings:array<int,array{rule:string,reason:string}>,checks:array<string,bool>}
     */
    public static function precheck( array $action, array $state, array $rules = array() ): array {
        $all_rules = array( 'target_exists', 'args_complete', 'lane_owned', 'budget_within', 'no_conflict', 'idempotency_ready' );
        $selected = $rules ? array_values( array_intersect( $all_rules, $rules ) ) : $all_rules;
        $blocking = array();
        $warnings = array();
        $checks = array();

        foreach ( $selected as $rule ) {
            $result = self::check_rule( $rule, $action, $state );
            $checks[ $rule ] = (bool) $result['ok'];
            if ( $result['ok'] ) { continue; }
            $row = array( 'rule' => $rule, 'reason' => $result['reason'] );
            if ( $result['severity'] ) { $blocking[] = $row; } else { $warnings[] = $row; }
        }

        return array(
            'feasible' => empty( $blocking ),
            'blocking' => $blocking,
            'warnings' => $warnings,
            'checks' => $checks,
        );
    }

    /**
     * @return array{ok:bool,severity:bool,reason:string}
     */
    private static function check_rule( string $rule, array $action, array $state ): array {
        switch ( $rule ) {
            case 'target_exists':
                $target = (string) ( $action['target'] ?? '' );
                if ( '' === $target ) { return array( 'ok' => true, 'severity' => false, 'reason' => 'no_target_required' ); }
                $entities = is_array( $state['entities'] ?? null ) ? $state['entities'] : array();
                $known = is_array( $entities[ $target ] ?? null ) || true === ( $entities[ $target ] ?? false );
                return $known
                    ? array( 'ok' => true, 'severity' => false, 'reason' => 'target_exists' )
                    : array( 'ok' => false, 'severity' => true, 'reason' => 'target_not_found_in_state:' . $target );

            case 'args_complete':
                $required = is_array( $action['required'] ?? null ) ? $action['required'] : array();
                $args = is_array( $action['args'] ?? null ) ? $action['args'] : array();
                $missing = array();
                foreach ( $required as $name ) {
                    $value = $args[ $name ] ?? null;
                    if ( null === $value || '' === $value || array() === $value ) { $missing[] = (string) $name; }
                }
                return empty( $missing )
                    ? array( 'ok' => true, 'severity' => false, 'reason' => 'args_complete' )
                    : array( 'ok' => false, 'severity' => true, 'reason' => 'missing_required_args:' . implode( ',', $missing ) );

            case 'lane_owned':
                $lane = (string) ( $action['lane_handle'] ?? '' );
                if ( '' === $lane ) { return array( 'ok' => true, 'severity' => false, 'reason' => 'no_lane_required' ); }
                $owner = (string) ( $state['lane_owner'] ?? '' );
                return '' !== $owner && hash_equals( $owner, $lane )
                    ? array( 'ok' => true, 'severity' => false, 'reason' => 'lane_owned' )
                    : array( 'ok' => false, 'severity' => true, 'reason' => 'lane_not_owned_by_current_session' );

            case 'budget_within':
                $budget = is_array( $action['budget'] ?? null ) ? $action['budget'] : array();
                $max_steps = (int) ( $budget['max_steps'] ?? 0 );
                if ( $max_steps <= 0 ) { return array( 'ok' => true, 'severity' => false, 'reason' => 'no_budget_required' ); }
                $used = (int) ( $state['steps_used'] ?? 0 );
                return $used <= $max_steps
                    ? array( 'ok' => true, 'severity' => false, 'reason' => 'budget_within' )
                    : array( 'ok' => false, 'severity' => true, 'reason' => 'step_budget_exceeded:' . $used . '>' . $max_steps );

            case 'no_conflict':
                $conflicts = is_array( $state['conflicts'] ?? null ) ? $state['conflicts'] : array();
                $id = (string) ( $action['id'] ?? $action['action'] ?? '' );
                if ( '' !== $id && in_array( $id, array_map( 'strval', $conflicts ), true ) ) {
                    return array( 'ok' => false, 'severity' => true, 'reason' => 'action_conflicts_with_running_work:' . $id );
                }
                return array( 'ok' => true, 'severity' => false, 'reason' => 'no_conflict' );

            case 'idempotency_ready':
                // Avviso, non bloccante: un retry senza idempotency key può
                // duplicare l'effetto, ma l'azione resta eseguibile (Law 3).
                if ( empty( $action['idempotency_key'] ) && ! empty( $action['requires_idempotency'] ) ) {
                    return array( 'ok' => false, 'severity' => false, 'reason' => 'idempotency_key_missing_for_retry_safety' );
                }
                return array( 'ok' => true, 'severity' => false, 'reason' => 'idempotency_ready' );
        }
        return array( 'ok' => true, 'severity' => false, 'reason' => 'rule_unknown:' . $rule );
    }
}
