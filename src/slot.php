<?php
declare(strict_types=1);

// Koppeling met het fietsslot.
//
// De website beslist OF een slot open mag (fiets is vandaag aan deze gebruiker verhuurd).
// Het daadwerkelijk openen hangt af van het merk slot en de leverancier; die koppeling moet nog
// worden ingevuld zodra bekend is welk slot er op de fietsen komt. Twee standen:
//
//  - 'simulatie': er gaat niets naar een slot, de opening wordt alleen gelogd. Voor testen.
//  - 'webhook':   POST naar slot_webhook_url met JSON
//                 {"actie":"openen","fiets":"L-04","barcode":"...","openingscode":"123456"|null,"tijd":"..."}
//                 en de header "Authorization: Bearer <slot_webhook_token>". Een 2xx-antwoord = geopend.
//                 Dit is een algemeen voorbeeld; pas het aan op de API van de slotleverancier.

/**
 * @param string|null $openingscode eenmalige code uit een vrijgave door de beheerder (null bij openen door de huurder)
 * @return array{ok:bool, melding:string}
 */
function slot_openen(array $fiets, ?string $openingscode = null): array
{
    global $config;

    if ($config['slot_modus'] === 'simulatie') {
        error_log('Slot (simulatie): fiets ' . $fiets['nummer'] . ' geopend' . ($openingscode ? ' met openingscode' : ''));
        return ['ok' => true, 'melding' => 'Simulatie: er is geen echt slot gekoppeld.'];
    }

    if ($config['slot_modus'] !== 'webhook' || $config['slot_webhook_url'] === '') {
        error_log('Slot: geen geldige slot_modus ingesteld');
        return ['ok' => false, 'melding' => 'Het slot is nog niet gekoppeld.'];
    }

    $ch = curl_init($config['slot_webhook_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['slot_webhook_token'],
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'actie' => 'openen',
            'fiets' => $fiets['nummer'],
            'barcode'      => $fiets['code'],
            'openingscode' => $openingscode,
            'tijd'  => date(DATE_ATOM),
        ]),
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $fout = curl_error($ch);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'melding' => ''];
    }
    error_log('Slot: HTTP ' . $status . ' ' . $fout . ' bij fiets ' . $fiets['nummer']);
    return ['ok' => false, 'melding' => 'Het slot reageert niet. Probeer het opnieuw of bel ons.'];
}
