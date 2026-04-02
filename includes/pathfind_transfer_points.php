<?php
declare(strict_types=1);

if (!function_exists('cvPfManualTransferConfig')) {
    /**
     * Definisce i punti di contatto manuali tra fermate usabili come scalo.
     *
     * Formato:
     * [
     *   'hubs' => [
     *     'hub_key' => [
     *       'min_transfer_minutes' => 25,
     *       'distance_km' => 0.0,
     *       'stops' => ['provider|stop_id', ...],
     *     ],
     *   ],
     *   'pairs' => [
     *     [
     *       'from' => 'provider|stop_id',
     *       'to' => 'provider|stop_id',
     *       'min_transfer_minutes' => 25,
     *       'distance_km' => 0.0,
     *       'bidirectional' => true,
     *     ],
     *   ],
     * ]
     *
     * Per ora resta vuoto: in assenza di configurazione sono permessi solo
     * i cambi sulla stessa identica fermata.
     *
     * @return array<string,mixed>
     */
    function cvPfManualTransferConfig(): array
    {
        return [
            'hubs' => [],
            'pairs' => [],
        ];
    }
}
