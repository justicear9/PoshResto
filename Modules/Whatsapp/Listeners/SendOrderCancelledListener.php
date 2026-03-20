<?php

namespace Modules\Whatsapp\Listeners;

use App\Events\OrderCancelled;
use Modules\Whatsapp\Entities\WhatsAppNotificationPreference;
use Modules\Whatsapp\Services\WhatsAppNotificationService;
use Illuminate\Support\Facades\Log;

class SendOrderCancelledListener
{
    protected WhatsAppNotificationService $notificationService;

    public function __construct(WhatsAppNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     */
    public function handle(OrderCancelled $event): void
    {
        try {
            $order = $event->order;
            $restaurantId = $order->branch->restaurant_id ?? null;

            if (!$restaurantId) {
                return;
            }

            // Check if WhatsApp module is in restaurant's package
            if (function_exists('restaurant_modules')) {
                $restaurant = $order->branch->restaurant ?? \App\Models\Restaurant::find($restaurantId);
                if ($restaurant) {
                    $restaurantModules = restaurant_modules($restaurant);
                    if (!in_array('Whatsapp', $restaurantModules)) {
                        return;
                    }
                }
            }

            // Check if notification is enabled for customer
            $customerPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
                ->where('notification_type', 'order_cancelled')
                ->where('recipient_type', 'customer')
                ->where('is_enabled', true)
                ->first();

            if ($customerPreference && $order->customer && $order->customer->mobile) {
                $variables = $this->getOrderCancelledVariables($order);
                
                $this->notificationService->send(
                    $restaurantId,
                    'order_cancelled',
                    $order->customer->mobile,
                    $variables
                );
            }

            // Check if notification is enabled for admin
            $adminPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
                ->where('notification_type', 'order_cancellation_alert')
                ->where('recipient_type', 'admin')
                ->where('is_enabled', true)
                ->first();

            if ($adminPreference) {
                // Get admin users with phone numbers
                $admins = \App\Models\User::role('Admin_' . $restaurantId)
                    ->where('restaurant_id', $restaurantId)
                    ->whereNotNull('mobile')
                    ->get();

                foreach ($admins as $admin) {
                    $variables = $this->getOrderCancellationAlertVariables($order);
                    
                    $this->notificationService->send(
                        $restaurantId,
                        'order_cancellation_alert',
                        $admin->mobile,
                        $variables
                    );
                }
            }

        } catch (\Exception $e) {
            Log::error('WhatsApp Order Cancelled Listener Error: ' . $e->getMessage(), [
                'order_id' => $event->order->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    protected function getOrderCancelledVariables($order): array
    {
        $customerName = $order->customer->name ?? 'Customer';
        $orderNumber = $order->show_formatted_order_number ?? 'N/A';
        $cancelReason = $order->cancelReason->name ?? 'Not specified';
        $restaurantName = $order->branch->restaurant->name ?? '';
        $contactNumber = $order->branch->restaurant->contact_number ?? '';

        $restaurantHash = $order->branch->restaurant->hash ?? null;

        return [
            $customerName,        // [0] Customer name
            $orderNumber,         // [1] Order number
            $cancelReason,        // [2] Cancel reason
            'Pending',            // [3] Refund status
            $order->id ?? null,   // [4] Order ID (for button URL)
            $restaurantHash,      // [5] Restaurant hash (for button URL)
        ];
    }

    protected function getOrderCancellationAlertVariables($order): array
    {
        $orderNumber = $order->show_formatted_order_number ?? 'N/A';
        $customerName = $order->customer->name ?? 'Guest';
        $cancelReason = $order->cancelReason->name ?? 'Not specified';
        $totalAmount = $order->total_amount ?? 0;
        $currency = $order->branch->restaurant->currency->currency_symbol ?? '';
        $branchName = $order->branch->name ?? '';

        return [
            $orderNumber,
            $customerName,
            $cancelReason,
            $currency . number_format($totalAmount, 2),
            $branchName,
        ];
    }
}

