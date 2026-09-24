<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Dashboard\Contracts\DashboardInvalidationRepository;
use App\Application\Dashboard\Data\DashboardInvalidationData;
use App\Application\Dashboard\FinancialRevision;
use App\Application\Outbox\OutboxUnavailable;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;
use Illuminate\Support\Facades\DB;
use JsonException;
use PDOException;

final class MysqlDashboardInvalidationRepository implements DashboardInvalidationRepository
{
    public function pending(string $deliveryId): ?DashboardInvalidationData
    {
        $this->requireOwnTransaction();
        try {
            return DB::transaction(function () use ($deliveryId): ?DashboardInvalidationData {
                $delivery = DB::table('outbox_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
                if ($delivery === null || in_array($delivery->status, ['acknowledged', 'failed'], true)) {
                    return null;
                }
                $event = DB::table('outbox_events')->where('id', $delivery->event_id)->first();
                try {
                    $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                    if ($delivery->consumer !== 'dashboard-cache-invalidator' || $event->event_type !== 'LedgerChanged' || (int) $event->event_version !== 1
                        || ! is_array($payload) || ($payload['currency'] ?? null) !== 'BRL'
                        || ! is_string($payload['owner_user_id'] ?? null) || ! is_string($payload['financial_revision'] ?? null)
                        || (string) DecimalInteger::positive($payload['owner_user_id']) !== $payload['owner_user_id']) {
                        throw new DomainViolation('unsupported_event', 'Unsupported cache event.');
                    }
                    FinancialRevision::validate($payload['financial_revision']);
                } catch (DomainViolation|JsonException) {
                    DB::table('outbox_deliveries')->where('id', $deliveryId)->update(['status' => 'failed', 'last_error' => 'unsupported_dashboard_event', 'lease_token' => null, 'lease_expires_at' => null]);

                    return null;
                }

                return new DashboardInvalidationData($deliveryId, (string) $delivery->event_id, $payload['owner_user_id'], $payload['financial_revision']);
            }, attempts: 3);
        } catch (PDOException) {
            throw new OutboxUnavailable;
        }
    }

    public function acknowledge(DashboardInvalidationData $event): void
    {
        $this->requireOwnTransaction();
        try {
            DB::table('outbox_deliveries')->where('id', $event->deliveryId)->where('event_id', $event->eventId)
                ->where('consumer', 'dashboard-cache-invalidator')->whereNotIn('status', ['failed', 'acknowledged'])
                ->update(['status' => 'acknowledged', 'acknowledged_at' => DB::raw('CURRENT_TIMESTAMP(6)'),
                    'lease_token' => null, 'lease_expires_at' => null, 'last_error' => null]);
        } catch (PDOException) {
            throw new OutboxUnavailable;
        }
    }

    private function requireOwnTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new OutboxUnavailable;
        }
    }
}
