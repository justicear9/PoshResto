<?php

namespace Modules\Whatsapp\Listeners;

use App\Events\OrderUpdated;
use App\Enums\OrderStatus;
use Modules\Whatsapp\Entities\WhatsAppNotificationPreference;
use Modules\Whatsapp\Services\WhatsAppNotificationService;
use Modules\Whatsapp\Services\WhatsAppHelperService;
use Illuminate\Support\Facades\Log;

class SendOrderStatusUpdateListener
{
    protected WhatsAppNotificationService $notificationService;
    protected WhatsAppHelperService $helperService;

    public function __construct(
        WhatsAppNotificationService $notificationService,
        WhatsAppHelperService $helperService
    ) {
        $this->notificationService = $notificationService;
        $this->helperService = $helperService;
    }

    /**
     * Handle the event.
     */
    public function handle(OrderUpdated $event): void
    {
        try {
            $order = $event->order;
            $restaurantId = $order->branch->restaurant_id ?? null;

            if (!$restaurantId) {
                Log::info('WhatsApp Order Status Update Listener: Skipping - no restaurant_id', [
                    'order_id' => $order->id ?? null,
                ]);
                return;
            }

            // Check if WhatsApp module is in restaurant's package
            if (function_exists('restaurant_modules')) {
                $restaurant = \App\Models\Restaurant::find($restaurantId);
                if ($restaurant) {
                    $restaurantModules = restaurant_modules($restaurant);
                    if (!in_array('Whatsapp', $restaurantModules)) {
                        Log::info('WhatsApp Order Status Update Listener: Skipping - WhatsApp module not in restaurant package', [
                            'order_id' => $order->id ?? null,
                            'restaurant_id' => $restaurantId,
                        ]);
                        return;
                    }
                }
            }

            // Only send if order_status actually changed
            if (!$order->wasChanged('order_status') && !$order->wasChanged('status')) {
                Log::info('WhatsApp Order Status Update Listener: Skipping - no status change', [
                    'order_id' => $order->id ?? null,
                    'order_status_changed' => $order->wasChanged('order_status'),
                    'status_changed' => $order->wasChanged('status'),
                ]);
                return;
            }

            Log::info('WhatsApp Order Status Update Listener: Event triggered', [
                'order_id' => $order->id ?? null,
                'order_status_changed' => $order->wasChanged('order_status'),
                'status_changed' => $order->wasChanged('status'),
                'restaurant_id' => $restaurantId,
            ]);

            $currentStatus = $order->order_status?->value ?? $order->status;
            $previousOrderStatus = $order->getOriginal('order_status');
            if ($previousOrderStatus instanceof OrderStatus) {
                $previousOrderStatus = $previousOrderStatus->value;
            }
            
            // Get previous status value for payment confirmation check
            $previousStatus = $order->getOriginal('status');

            // Handle payment confirmation (when status changes to 'paid')
            if ($order->wasChanged('status') && $order->status === 'paid' && $previousStatus !== 'paid') {
                Log::info('WhatsApp Order Status Update Listener: Payment confirmation triggered', [
                    'order_id' => $order->id,
                    'current_status' => $order->status,
                    'previous_status' => $previousStatus,
                    'restaurant_id' => $restaurantId,
                ]);
                $this->handlePaymentConfirmation($order, $restaurantId);
            }

            // Handle specific order status changes
            if ($order->wasChanged('order_status')) {
                match ($currentStatus) {
                    'food_ready' => $this->handleOrderReadyToServe($order, $restaurantId),
                    'ready_for_pickup' => $this->handleOrderReadyForPickup($order, $restaurantId),
                    'out_for_delivery' => $this->handleDeliveryAssignment($order, $restaurantId),
                    'delivered' => $this->handleDeliveryCompletion($order, $restaurantId),
                    default => $this->handleGenericOrderStatusUpdate($order, $restaurantId),
                };
            }
            
            // Log if payment confirmation was not triggered but status is paid
            if ($order->wasChanged('status') && $order->status === 'paid' && $previousStatus === 'paid') {
                Log::info('WhatsApp Order Status Update Listener: Payment confirmation skipped (already paid)', [
                    'order_id' => $order->id,
                    'current_status' => $order->status,
                    'previous_status' => $previousStatus,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('WhatsApp Order Status Update Listener Error: ' . $e->getMessage(), [
                'order_id' => $event->order->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle payment confirmation notification.
     */
    protected function handlePaymentConfirmation($order, int $restaurantId): void
    {
        // Get customer phone number (combine phone_code and phone, no + sign)
        $customerPhone = null;
        if ($order->customer && $order->customer->phone) {
            if ($order->customer->phone_code) {
                $customerPhone = $order->customer->phone_code . $order->customer->phone;
            } else {
                $customerPhone = $order->customer->phone;
            }
        }
        
        // Check both old notification type (payment_confirmation) and consolidated (payment_notification)
        $preference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
            ->where(function($query) {
                $query->where('notification_type', 'payment_notification')
                    ->orWhere('notification_type', 'payment_confirmation');
            })
            ->where('recipient_type', 'customer')
            ->where('is_enabled', true)
            ->first();

        if (!$preference) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping payment confirmation - preference not enabled");
            return;
        }

        if (!$order->customer) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping payment confirmation - no customer assigned");
            return;
        }

        if (!$customerPhone) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping payment confirmation - customer has no phone number");
            return;
        }

        $payment = $order->payments()->latest()->first();
        // Get total amount with GST/taxes included - always use total field (includes all taxes, charges, discounts)
        $paymentAmount = $order->amount_paid ?? $this->getOrderTotal($order);
        $currency = $order->branch->restaurant->currency->currency_symbol ?? '';
        
        // Get order type name
        $orderTypeName = 'N/A';
        if ($order->orderType) {
            $orderTypeName = $order->orderType->order_type_name ?? $order->orderType->type ?? 'N/A';
        } elseif ($order->custom_order_type_name) {
            $orderTypeName = $order->custom_order_type_name;
        } elseif ($order->order_type) {
            $orderTypeName = ucfirst(str_replace('_', ' ', $order->order_type));
        }
        
        $restaurantHash = $order->branch->restaurant->hash ?? null;

        $variables = [
            $order->customer->name ?? 'Customer',
            $currency . number_format($paymentAmount, 2),
            $order->show_formatted_order_number ?? 'N/A',
            $payment->transaction_id ?? 'N/A',
            $payment->payment_method ?? 'N/A',
            now()->format('d M, Y H:i'),
            $orderTypeName, // Order type
            $order->id ?? null, // Order ID (for button URL)
            $restaurantHash, // Restaurant hash (for button URL)
        ];

        $this->notificationService->send(
            $restaurantId,
            'payment_confirmation',
            $customerPhone,
            $variables,
            'en',
            'customer'
        );
    }

    /**
     * Handle order ready to serve notification (for waiters and customers).
     */
    protected function handleOrderReadyToServe($order, int $restaurantId): void
    {
        Log::info('WhatsApp Order Status Update Listener: handleOrderReadyToServe called', [
            'order_id' => $order->id ?? null,
            'order_status' => $order->order_status?->value ?? 'N/A',
            'restaurant_id' => $restaurantId,
        ]);

        // 1) Notify customer that order is ready
        // Check both old notification type (order_status_update) and consolidated (order_notifications)
        $customerPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
            ->where(function($query) {
                $query->where('notification_type', 'order_notifications')
                    ->orWhere('notification_type', 'order_status_update');
            })
            ->where('recipient_type', 'customer')
            ->where('is_enabled', true)
            ->first();

        if (!$customerPreference) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer status notification (food_ready) - preference not enabled");
        } elseif (!$order->customer) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer status notification (food_ready) - no customer assigned");
        } else {
            // Get customer phone number (combine phone_code and phone, no + sign)
            $customerPhone = null;
            if ($order->customer->phone) {
                if ($order->customer->phone_code) {
                    $customerPhone = $order->customer->phone_code . $order->customer->phone;
                } else {
                    $customerPhone = $order->customer->phone;
                }
            }

            if (!$customerPhone) {
                Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer status notification (food_ready) - customer has no phone number");
            } else {
                $variables = $this->getOrderStatusVariables($order);
                
                $result = $this->notificationService->send(
                    $restaurantId,
                    'order_status_update',
                    $customerPhone,
                    $variables,
                    'en',
                    'customer'
                );
                // Final result logged by WhatsAppNotificationService
            }
        }

        // 2) Notify waiter (existing behavior)
        $preference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
            ->where('notification_type', 'order_ready_to_serve')
            ->where('recipient_type', 'staff')
            ->where('is_enabled', true)
            ->first();

        if (!$preference || !$order->waiter || !$order->waiter->mobile) {
            Log::info('WhatsApp Order Status Update Listener: Skipping waiter notification for food_ready', [
                'order_id' => $order->id ?? null,
                'has_preference' => $preference !== null,
                'has_waiter' => $order->waiter !== null,
                'has_waiter_mobile' => $order->waiter && $order->waiter->mobile !== null,
            ]);
            return;
        }

        $tableNumber = $order->table->table_number ?? 'N/A';
        $items = $order->items()->take(3)->pluck('name')->implode(', ');
        if ($order->items()->count() > 3) {
            $items .= ' and ' . ($order->items()->count() - 3) . ' more';
        }
        
        // Get order type name
        $orderTypeName = 'N/A';
        if ($order->orderType) {
            $orderTypeName = $order->orderType->order_type_name ?? $order->orderType->type ?? 'N/A';
        } elseif ($order->custom_order_type_name) {
            $orderTypeName = $order->custom_order_type_name;
        } elseif ($order->order_type) {
            $orderTypeName = ucfirst(str_replace('_', ' ', $order->order_type));
        }

        $variables = [
            $order->waiter->name ?? 'Waiter', // {{1}} - Staff name
            $order->show_formatted_order_number ?? 'N/A', // {{2}} - Order number (for extraction)
            $tableNumber, // {{3}} - Table number (will be {{4}} in template)
            $items ?: 'N/A', // {{4}} - Items (will be {{6}} in template)
            now()->format('H:i'), // Not used in new template
            $order->special_instructions ?? 'None', // Not used in new template
            $orderTypeName, // {{5}} - Order type (added for new template format)
        ];

        $this->notificationService->send(
            $restaurantId,
            'order_ready_to_serve',
            $order->waiter->mobile,
            $variables
        );

        // 2) Additionally notify delivery executive when order type is delivery
        //    so they know the order is ready at the restaurant.
        if ($order->order_type === 'delivery' && $order->deliveryExecutive && $order->deliveryExecutive->mobile) {
            $deliveryPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
                ->where('notification_type', 'order_ready_for_pickup')
                ->where('recipient_type', 'delivery')
                ->where('is_enabled', true)
                ->first();

            if ($deliveryPreference) {
                $pickupAddress = $order->branch->address ?? 'Restaurant';
                $deliveryAddress = $order->delivery_address ?? 'N/A';
                $currency = $order->branch->restaurant->currency->currency_symbol ?? '';
                $customerPhone = null;
                if ($order->customer && $order->customer->phone) {
                    if ($order->customer->phone_code) {
                        $customerPhone = $order->customer->phone_code . $order->customer->phone;
                    } else {
                        $customerPhone = $order->customer->phone;
                    }
                }

                $deliveryVariables = [
                    $order->deliveryExecutive->name ?? 'Delivery Executive',
                    $order->show_formatted_order_number ?? 'N/A',
                    $pickupAddress,
                    $order->customer->name ?? 'Customer',
                    $deliveryAddress,
                    now()->format('d M, Y H:i'),
                    $customerPhone ?? ($order->branch->restaurant->contact_number ?? 'N/A'), // Customer phone or restaurant phone
                    $currency . number_format($this->getOrderTotal($order), 2), // Amount (with GST/taxes)
                ];

                $this->notificationService->send(
                    $restaurantId,
                    'order_ready_for_pickup',
                    $order->deliveryExecutive->mobile,
                    $deliveryVariables
                );
            }
        }
    }

    /**
     * Handle order ready for pickup notification (for customers and delivery executives).
     */
    protected function handleOrderReadyForPickup($order, int $restaurantId): void
    {
        // Notify customer
        $customerPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
            ->where('notification_type', 'order_ready_for_pickup')
            ->where('recipient_type', 'customer')
            ->where('is_enabled', true)
            ->first();

        if (!$customerPreference) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer notification (ready_for_pickup) - preference not enabled");
        } elseif (!$order->customer) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer notification (ready_for_pickup) - no customer assigned");
        } elseif (!$order->customer->mobile) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer notification (ready_for_pickup) - customer has no mobile number");
        } else {
            $pickupAddress = $order->branch->address ?? 'Restaurant';
            $deliveryAddress = $order->delivery_address ?? 'N/A';

            $variables = [
                $order->customer->name ?? 'Customer',
                $order->show_formatted_order_number ?? 'N/A',
                $pickupAddress,
                $order->customer->name ?? 'Customer',
                $deliveryAddress,
                now()->format('d M, Y H:i'),
                $order->branch->restaurant->contact_number ?? '',
            ];

            $this->notificationService->send(
                $restaurantId,
                'order_ready_for_pickup',
                $order->customer->mobile,
                $variables
            );
            // Final result logged by WhatsAppNotificationService
        }

        // Notify delivery executive if assigned
        $deliveryPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
            ->where('notification_type', 'order_ready_for_pickup')
            ->where('recipient_type', 'delivery')
            ->where('is_enabled', true)
            ->first();

        if ($deliveryPreference && $order->deliveryExecutive && $order->deliveryExecutive->mobile) {
            $pickupAddress = $order->branch->address ?? 'Restaurant';
            $deliveryAddress = $order->delivery_address ?? 'N/A';
            $currency = $order->branch->restaurant->currency->currency_symbol ?? '';
            $customerPhone = null;
            if ($order->customer && $order->customer->phone) {
                if ($order->customer->phone_code) {
                    $customerPhone = $order->customer->phone_code . $order->customer->phone;
                } else {
                    $customerPhone = $order->customer->phone;
                }
            }

            $variables = [
                $order->deliveryExecutive->name ?? 'Delivery Executive',
                $order->show_formatted_order_number ?? 'N/A',
                $pickupAddress,
                $order->customer->name ?? 'Customer',
                $deliveryAddress,
                now()->format('d M, Y H:i'),
                $customerPhone ?? ($order->branch->restaurant->contact_number ?? 'N/A'), // Customer phone or restaurant phone
                $currency . number_format($order->total ?? ($order->sub_total ?? 0), 2), // Amount
            ];

            $this->notificationService->send(
                $restaurantId,
                'order_ready_for_pickup',
                $order->deliveryExecutive->mobile,
                $variables
            );
        }
    }

    /**
     * Handle delivery assignment notification.
     */
    protected function handleDeliveryAssignment($order, int $restaurantId): void
    {
        $preference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
            ->where('notification_type', 'delivery_assignment')
            ->where('recipient_type', 'delivery')
            ->where('is_enabled', true)
            ->first();

        if (!$preference || !$order->deliveryExecutive || !$order->deliveryExecutive->mobile) {
            return;
        }

        $pickupAddress = $order->branch->address ?? 'Restaurant';
        $deliveryAddress = $order->delivery_address ?? 'N/A';
        $currency = $order->branch->restaurant->currency->currency_symbol ?? '';

        $variables = [
            $order->deliveryExecutive->name ?? 'Delivery Executive',
            $order->show_formatted_order_number ?? 'N/A',
            $order->customer->name ?? 'Customer',
            $order->customer->mobile ?? 'N/A',
            $pickupAddress,
            $deliveryAddress,
            $currency . number_format($this->getOrderTotal($order), 2), // Amount (with GST/taxes)
            $order->estimated_delivery_time ?? '30 minutes',
        ];

        $this->notificationService->send(
            $restaurantId,
            'delivery_assignment',
            $order->deliveryExecutive->mobile,
            $variables
        );
    }

    /**
     * Handle delivery completion confirmation.
     */
    protected function handleDeliveryCompletion($order, int $restaurantId): void
    {
        // Notify delivery executive
        $deliveryPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
            ->where('notification_type', 'delivery_completion_confirmation')
            ->where('recipient_type', 'delivery')
            ->where('is_enabled', true)
            ->first();

        if ($deliveryPreference && $order->deliveryExecutive && $order->deliveryExecutive->mobile) {
            $deliveryAddress = $order->delivery_address ?? 'N/A';
            $paymentStatus = $order->status === 'paid' ? 'Paid' : 'Pending';
            $currency = $order->branch->restaurant->currency->currency_symbol ?? '';

            $variables = [
                $order->deliveryExecutive->name ?? 'Delivery Executive',
                $order->show_formatted_order_number ?? 'N/A',
                $order->customer->name ?? 'Customer',
                now()->format('d M, Y H:i'),
                $deliveryAddress . ', Amount: ' . $currency . number_format($this->getOrderTotal($order), 2), // Address and amount combined (with GST/taxes)
                $paymentStatus,
            ];

            $this->notificationService->send(
                $restaurantId,
                'delivery_completion_confirmation',
                $order->deliveryExecutive->mobile,
                $variables
            );
        }

        // Notify customer
        $customerPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
            ->where('notification_type', 'delivery_completion_confirmation')
            ->where('recipient_type', 'customer')
            ->where('is_enabled', true)
            ->first();

        if (!$customerPreference) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer notification (delivered) - preference not enabled");
        } elseif (!$order->customer) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer notification (delivered) - no customer assigned");
        } elseif (!$order->customer->mobile) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer notification (delivered) - customer has no mobile number");
        } else {
            $deliveryAddress = $order->delivery_address ?? 'N/A';
            $paymentStatus = $order->status === 'paid' ? 'Paid' : 'Pending';

            $variables = [
                $order->customer->name ?? 'Customer',
                $order->show_formatted_order_number ?? 'N/A',
                $order->customer->name ?? 'Customer',
                now()->format('d M, Y H:i'),
                $deliveryAddress,
                $paymentStatus,
                'Thank you for your order!',
            ];

            $this->notificationService->send(
                $restaurantId,
                'delivery_completion_confirmation',
                $order->customer->mobile,
                $variables
            );
            // Final result logged by WhatsAppNotificationService
        }
    }

    /**
     * Handle generic order status update (fallback).
     * Handles status changes like: confirmed, preparing, cancelled, etc.
     */
    protected function handleGenericOrderStatusUpdate($order, int $restaurantId): void
    {
        $currentStatus = $order->order_status?->value ?? $order->status;
        
        Log::info('WhatsApp Order Status Update Listener: Generic status update triggered', [
            'order_id' => $order->id ?? null,
            'current_status' => $currentStatus,
            'restaurant_id' => $restaurantId,
        ]);

        // Check both old notification type (order_status_update) and consolidated (order_notifications)
        $preference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
            ->where(function($query) {
                $query->where('notification_type', 'order_notifications')
                    ->orWhere('notification_type', 'order_status_update');
            })
            ->where('recipient_type', 'customer')
            ->where('is_enabled', true)
            ->first();

        if (!$preference) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer status notification ({$currentStatus}) - preference not enabled");
            return;
        }

        if (!$order->customer) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer status notification ({$currentStatus}) - no customer assigned");
            return;
        }

        // Get customer phone number (combine phone_code and phone, no + sign)
        $customerPhone = null;
        if ($order->customer->phone) {
            if ($order->customer->phone_code) {
                $customerPhone = $order->customer->phone_code . $order->customer->phone;
            } else {
                $customerPhone = $order->customer->phone;
            }
        }

        if (!$customerPhone) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping customer status notification ({$currentStatus}) - customer has no phone number");
            return;
        }

        $variables = $this->getOrderStatusVariables($order);
        
        $result = $this->notificationService->send(
            $restaurantId,
            'order_status_update',
            $customerPhone,
            $variables,
            'en',
            'customer'
        );
        // Final result logged by WhatsAppNotificationService

        // 2) Notify delivery executive when order type is delivery and status is "preparing"
        if ($order->order_type === 'delivery' && in_array($currentStatus, ['preparing', 'confirmed', 'preparing_order'])) {
            $this->notifyDeliveryExecutiveForStatusUpdate($order, $restaurantId, $currentStatus);
        }
    }

    /**
     * Notify delivery executive about order status update (preparing, etc.)
     */
    protected function notifyDeliveryExecutiveForStatusUpdate($order, int $restaurantId, string $status): void
    {
        if (!$order->deliveryExecutive || !$order->deliveryExecutive->mobile) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping delivery executive status notification ({$status}) - no delivery executive assigned or no phone");
            return;
        }

        // Check preference for delivery executive order status updates
        // Check both old notification type (order_status_update) and consolidated (order_notifications)
        $deliveryPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
            ->where(function($query) {
                $query->where('notification_type', 'order_notifications')
                    ->orWhere('notification_type', 'order_status_update');
            })
            ->where('recipient_type', 'delivery')
            ->where('is_enabled', true)
            ->first();

        if (!$deliveryPreference) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping delivery executive status notification ({$status}) - preference not enabled");
            return;
        }

        // Get delivery executive phone number (combine phone_code and phone)
        $executivePhone = null;
        if ($order->deliveryExecutive->phone) {
            if ($order->deliveryExecutive->phone_code) {
                $executivePhone = $order->deliveryExecutive->phone_code . $order->deliveryExecutive->phone;
            } else {
                $executivePhone = $order->deliveryExecutive->phone;
            }
        } else {
            $executivePhone = $order->deliveryExecutive->mobile;
        }

        if (!$executivePhone) {
            Log::info("WhatsApp Order #{$order->id}: ⏭️ Skipping delivery executive status notification ({$status}) - no phone number");
            return;
        }

        // Prepare variables for delivery executive notification
        $statusLabel = $order->order_status?->label() ?? ucfirst(str_replace('_', ' ', $status));
        $orderType = $this->getOrderTypeName($order);
        $totalAmount = $this->getOrderTotal($order);
        $currency = $order->branch->restaurant->currency->currency_symbol ?? '';
        $restaurantName = $order->branch->restaurant->name ?? '';
        $contactNumber = $order->branch->restaurant->contact_number ?? '';
        $restaurantHash = $order->branch->restaurant->hash ?? null;

        $message = "order status updated to {$statusLabel}";
        $orderDetails = "Order type: {$orderType}. Total amount: {$currency}" . number_format($totalAmount, 2);

        $variables = [
            $order->deliveryExecutive->name ?? 'Delivery Executive', // [0] Delivery executive name
            $message, // [1] Message (e.g., "order status updated to Preparing")
            $order->show_formatted_order_number ?? 'N/A', // [2] Order number
            $orderDetails, // [3] Order details (type + amount)
            $this->getEstimatedTime($order), // [4] Estimated time
            $restaurantName, // [5] Restaurant name
            $contactNumber, // [6] Contact number
            $order->id ?? null, // [7] Order ID
            $restaurantHash, // [8] Restaurant hash (for button URL)
        ];

        $this->notificationService->send(
            $restaurantId,
            'order_status_update',
            $executivePhone,
            $variables,
            'en',
            'delivery'
        );
    }

    /**
     * Calculate preparation time or estimated delivery time for the order.
     * Matches the logic used on the customer site.
     */
    protected function getEstimatedTime($order): string
    {
        // For delivery orders, use estimated_eta_max if available
        if ($order->order_type === 'delivery' && !is_null($order->estimated_eta_max)) {
            return $order->estimated_eta_max . ' minutes';
        }

        // For other orders, get the maximum preparation time from order items
        $maxPreparationTime = null;
        
        // Always load items with menuItem relationship to ensure we have preparation_time
        $items = $order->items()->with('menuItem')->get();

        if ($items && $items->isNotEmpty()) {
            $preparationTimes = [];
            foreach ($items as $item) {
                // Handle cases where menuItem might be null
                if (!$item->menuItem) {
                    Log::warning("WhatsApp Order Status Update #{$order->id}: Item #{$item->id} has no menuItem", [
                        'item_id' => $item->id,
                        'menu_item_id' => $item->menu_item_id,
                    ]);
                    continue;
                }
                // Get preparation time as integer (no rounding)
                $prepTime = $item->menuItem->preparation_time ?? 0;
                $prepTime = (int) $prepTime;
                if ($prepTime > 0) {
                    $preparationTimes[] = $prepTime;
                }
            }
            
            if (!empty($preparationTimes)) {
                $maxPreparationTime = max($preparationTimes);
                Log::info("WhatsApp Order Status Update #{$order->id}: Calculated preparation time", [
                    'max_preparation_time' => $maxPreparationTime,
                    'all_preparation_times' => $preparationTimes,
                    'items_count' => $items->count(),
                ]);
            } else {
                Log::warning("WhatsApp Order Status Update #{$order->id}: No valid preparation times found", [
                    'items_count' => $items->count(),
                ]);
            }
        } else {
            Log::warning("WhatsApp Order Status Update #{$order->id}: No items found for preparation time calculation");
        }

        if ($maxPreparationTime && $maxPreparationTime > 0) {
            // Return exact value without rounding
            return (string) $maxPreparationTime . ' minutes';
        }

        // Fallback to default
        Log::warning("WhatsApp Order Status Update #{$order->id}: Using fallback preparation time (30 minutes)");
        return '30 minutes';
    }

    protected function getOrderStatusVariables($order): array
    {
        $customerName = $order->customer->name ?? 'Customer';
        $orderNumber = $order->show_formatted_order_number ?? 'N/A';
        $status = $order->order_status?->label() ?? $order->status ?? 'N/A';
        $orderType = $this->getOrderTypeName($order);
        $totalAmount = $this->getOrderTotal($order);
        $currency = $order->branch->restaurant->currency->currency_symbol ?? '';
        $estimatedTime = $this->getEstimatedTime($order);
        $restaurantName = $order->branch->restaurant->name ?? '';
        $contactNumber = $order->branch->restaurant->contact_number ?? '';

        // For order_notifications template: [customer_name, message, order_number, order_details, estimated_time]
        // message format: "order status updated to {status}"
        $message = "order status updated to {$status}";
        $orderDetails = "Order type: {$orderType}. Total amount: {$currency}" . number_format($totalAmount, 2);

        $restaurantHash = $order->branch->restaurant->hash ?? null;

        return [
            $customerName,        // [0] Customer name
            $message,              // [1] Message (e.g., "order status updated to Order Confirmed")
            $orderNumber,         // [2] Order number (numeric part only)
            $orderDetails,        // [3] Order details (type + amount)
            $estimatedTime,      // [4] Estimated time (calculated from order items)
            $restaurantName,       // [5] Restaurant name
            $contactNumber,        // [6] Contact number
            $order->id ?? null,    // [7] Order ID
            $restaurantHash,       // [8] Restaurant hash (for button URL)
        ];
    }

    /**
     * Get order type name (helper method).
     */
    protected function getOrderTypeName($order): string
    {
        if ($order->orderType) {
            return $order->orderType->order_type_name ?? $order->orderType->type ?? 'N/A';
        } elseif ($order->custom_order_type_name) {
            return $order->custom_order_type_name;
        } elseif ($order->order_type) {
            return ucfirst(str_replace('_', ' ', $order->order_type));
        }
        return 'N/A';
    }

    /**
     * Get order total amount with GST/taxes included.
     * Always returns the final total amount that customer sees (includes taxes, charges, discounts).
     * Never returns subtotal - always shows the complete total amount.
     */
    protected function getOrderTotal($order): float
    {
        // If total exists and is greater than 0, use it (this includes all taxes, charges, discounts)
        if (isset($order->total) && $order->total > 0) {
            return (float) $order->total;
        }

        // If total is 0 or null, calculate it from components to get the complete total with GST
        $subTotal = (float) ($order->sub_total ?? 0);
        $taxAmount = (float) ($order->total_tax_amount ?? 0);
        $discountAmount = (float) ($order->discount_amount ?? 0);
        $deliveryFee = (float) ($order->delivery_fee ?? 0);
        $tipAmount = (float) ($order->tip_amount ?? 0);

        // Calculate complete total: subtotal + taxes - discount + delivery fee + tip
        // This gives us the final amount customer pays (with GST included)
        $calculatedTotal = $subTotal + $taxAmount - $discountAmount + $deliveryFee + $tipAmount;

        // Always return calculated total (even if 0) - never return just subtotal
        return max(0, $calculatedTotal);
    }
}

