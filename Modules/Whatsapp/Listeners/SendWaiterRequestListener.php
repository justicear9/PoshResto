<?php

namespace Modules\Whatsapp\Listeners;

use App\Events\ActiveWaiterRequestCreatedEvent;
use Modules\Whatsapp\Entities\WhatsAppNotificationPreference;
use Modules\Whatsapp\Services\WhatsAppNotificationService;
use Illuminate\Support\Facades\Log;

class SendWaiterRequestListener
{
    protected WhatsAppNotificationService $notificationService;

    public function __construct(WhatsAppNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     * Note: This event only contains a count, not specific request details.
     * We'll need to get the latest waiter request from the database.
     */
    public function handle(ActiveWaiterRequestCreatedEvent $event): void
    {
        try {
            // Since the event only has a count, we need to get the latest waiter request
            // Get the latest pending waiter request
            $waiterRequest = \App\Models\WaiterRequest::with(['branch', 'table'])
                ->where('status', 'pending')
                ->latest()
                ->first();

            if (!$waiterRequest || !$waiterRequest->branch) {
                return;
            }

            $restaurantId = $waiterRequest->branch->restaurant_id ?? null;

            if (!$restaurantId) {
                return;
            }

            // Check if WhatsApp module is in restaurant's package
            if (function_exists('restaurant_modules')) {
                $restaurant = $waiterRequest->branch->restaurant ?? \App\Models\Restaurant::find($restaurantId);
                if ($restaurant) {
                    $restaurantModules = restaurant_modules($restaurant);
                    if (!in_array('Whatsapp', $restaurantModules)) {
                        return;
                    }
                }
            }

            // Check if notification is enabled for staff
            $staffPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
                ->where('notification_type', 'waiter_request')
                ->where('recipient_type', 'staff')
                ->where('is_enabled', true)
                ->first();

            if ($staffPreference && $waiterRequest->table) {
                // Get staff users with phone numbers
                $staff = \App\Models\User::role('Staff_' . $restaurantId)
                    ->where('restaurant_id', $restaurantId)
                    ->whereNotNull('mobile')
                    ->get();

                foreach ($staff as $staffMember) {
                    $variables = $this->getWaiterRequestVariables($waiterRequest);
                    
                    $this->notificationService->send(
                        $restaurantId,
                        'waiter_request',
                        $staffMember->mobile,
                        $variables
                    );
                }
            }

        } catch (\Exception $e) {
            Log::error('WhatsApp Waiter Request Listener Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    protected function getWaiterRequestVariables($waiterRequest): array
    {
        $tableNumber = $waiterRequest->table->table_number ?? 'N/A';
        $branchName = $waiterRequest->branch->name ?? '';
        $restaurantName = $waiterRequest->branch->restaurant->name ?? '';

        return [
            $tableNumber,
            $branchName,
            $restaurantName,
        ];
    }
}

