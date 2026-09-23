<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Outbox\Contracts\OutboxRepository;
use App\Application\Outbox\Data\ClaimedDeliveriesData;
use App\Application\Outbox\Data\OutboxDeliveryData;
use App\Application\Outbox\OutboxUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;

final class MysqlOutboxRepository implements OutboxRepository
{
    public function claim(): ClaimedDeliveriesData
    {
        if (DB::transactionLevel() !== 0) {
            throw new OutboxUnavailable;
        }
        try {
            return DB::transaction(function (): ClaimedDeliveriesData {
                $rows = DB::table('outbox_deliveries')->where('consumer', 'import-preparer')->where(function ($query): void {
                    $query->where(fn ($q) => $q->whereIn('status', ['pending', 'published'])->where('available_at', '<=', DB::raw('CURRENT_TIMESTAMP(6)')))
                        ->orWhere(fn ($q) => $q->where('status', 'publishing')->where('lease_expires_at', '<=', DB::raw('CURRENT_TIMESTAMP(6)')));
                })->orderBy('id')->limit(10)->lock('FOR UPDATE SKIP LOCKED')->get();
                $deliveries = [];
                foreach ($rows as $row) {
                    $event = DB::table('outbox_events')->where('id', $row->event_id)->first();
                    $token = (string) Str::uuid();
                    DB::table('outbox_deliveries')->where('id', $row->id)->update([
                        'status' => 'publishing', 'attempts' => DB::raw('attempts + 1'), 'lease_token' => $token,
                        'lease_expires_at' => DB::raw('CURRENT_TIMESTAMP(6) + INTERVAL 60 SECOND'),
                    ]);
                    $deliveries[] = new OutboxDeliveryData((string) $row->id, (string) $row->event_id, $row->consumer,
                        $event->event_type, (int) $event->event_version, $event->payload, (int) $row->attempts + 1, $token);
                }

                return new ClaimedDeliveriesData($deliveries);
            }, attempts: 3);
        } catch (PDOException) {
            throw new OutboxUnavailable;
        }
    }

    public function published(OutboxDeliveryData $delivery): void
    {
        $this->update($delivery, [
            'status' => 'published', 'published_at' => DB::raw('CURRENT_TIMESTAMP(6)'),
            'available_at' => DB::raw('CURRENT_TIMESTAMP(6) + INTERVAL 300 SECOND'),
            'lease_token' => null, 'lease_expires_at' => null, 'last_error' => null,
        ]);
    }

    public function retry(OutboxDeliveryData $delivery): void
    {
        $delay = min(300, 2 ** min($delivery->attempts, 8));
        $this->update($delivery, [
            'status' => 'pending', 'available_at' => DB::raw("CURRENT_TIMESTAMP(6) + INTERVAL {$delay} SECOND"),
            'lease_token' => null, 'lease_expires_at' => null, 'last_error' => 'publication_unavailable',
        ]);
    }

    /** @param array<string,mixed> $changes */
    private function update(OutboxDeliveryData $delivery, array $changes): void
    {
        try {
            DB::table('outbox_deliveries')->where('id', $delivery->id)->where('status', 'publishing')
                ->where('lease_token', $delivery->leaseToken)->update($changes);
        } catch (PDOException) {
            throw new OutboxUnavailable;
        }
    }
}
