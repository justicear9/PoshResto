<?php

namespace Modules\Whatsapp\Listeners;

use App\Events\NotifyWaiter;
use Modules\Whatsapp\Entities\WhatsAppNotificationPreference;
use Modules\Whatsapp\Services\WhatsAppNotificationService;
use Illuminate\Support\Facades\Log;

class SendNotifyWaiterListener
{
    protected WhatsAppNotificationService $notificationService;

    public function __construct(WhatsAppNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     */
    public function handle(NotifyWaiter $event): void
    {
        try {
            // Get table from table number
            $table = \App\Models\Table::where('table_number', $event->tableNumber)
                ->with(['branch.restaurant'])
                ->first();

            if (!$table || !$table->branch) {
                return;
            }

            $restaurantId = $table->branch->restaurant_id ?? null;

            if (!$restaurantId) {
                return;
            }

            // Check if WhatsApp module is in restaurant's package
            if (function_exists('restaurant_modules')) {
                $restaurant = $table->branch->restaurant ?? \App\Models\Restaurant::find($restaurantId);
                if ($restaurant) {
                    $restaurantModules = restaurant_modules($restaurant);
                    if (!in_array('Whatsapp', $restaurantModules)) {
                        return;
                    }
                }
            }

            // Check if notification is enabled for staff
            $staffPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
                ->where('notification_type', 'waiter_request_acknowledgment')
                ->where('recipient_type', 'staff')
                ->where('is_enabled', true)
                ->first();

            if ($staffPreference) {
                // Get staff users with phone numbers
                $staff = \App\Models\User::role('Staff_' . $restaurantId)
                    ->where('restaurant_id', $restaurantId)
                    ->whereNotNull('mobile')
                    ->get();

                $tableNumber = $event->tableNumber ?? 'N/A';
                $restaurantName = $table->branch->restaurant->name ?? '';

                foreach ($staff as $staffMember) {
                    $variables = [
                        $tableNumber,
                        $restaurantName,
                    ];
                    
                    $this->notificationService->send(
                        $restaurantId,
                        'waiter_request_acknowledgment',
                        $staffMember->mobile,
                        $variables
                    );
                }
            }

        } catch (\Exception $e) {
            Log::error('WhatsApp Notify Waiter Listener Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

