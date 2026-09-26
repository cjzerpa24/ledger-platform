<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Infrastructure\Messaging\IntegrationEvent;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Messenger\Envelope;

/**
 * Pins what lands in the ledger_events stream, because webhooks-service decodes it:
 * the Messenger frame's body must be exactly the event envelope as JSON.
 */
final class WireFormatTest extends IntegrationTestCase
{
    public function testMessengerBodyIsTheEnvelopeJson(): void
    {
        $event = new IntegrationEvent(
            '0199a111-2222-7333-8444-555566667777',
            'ledger.deposit_recorded',
            1,
            '2026-09-26T00:30:00.000+00:00',
            '0199a111-2222-7333-8444-555566668888',
            ['amount' => '10.00', 'amountMinor' => 1000, 'description' => null],
        );
        $serializer = self::getContainer()->get('messenger.transport.symfony_serializer');

        $encoded = $serializer->encode(new Envelope($event));
        self::assertSame($event->toArray(), json_decode($encoded['body'], true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testRedisTransportIsConfiguredForNonPhpConsumers(): void
    {
        // Tests use in-memory://, so check the committed default the containers run with.
        $env = (string) file_get_contents(\dirname(__DIR__, 3) . '/.env');

        if (1 !== preg_match('#^LEDGER_EVENTS_TRANSPORT_DSN=(redis://\S+)$#m', $env, $match)) {
            self::fail('The .env must define a redis:// LEDGER_EVENTS_TRANSPORT_DSN.');
        }
        parse_str((string) parse_url($match[1], \PHP_URL_QUERY), $options);

        self::assertSame('0', $options['serializer'] ?? null, 'Non-PHP consumers need JSON entries.');
        self::assertSame('false', $options['auto_setup'] ?? null, 'The producer must not create a consumer group.');
        self::assertSame('false', $options['delete_after_ack'] ?? null);
    }
}
