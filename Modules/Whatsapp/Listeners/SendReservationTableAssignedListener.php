<?php

namespace Modules\Whatsapp\Listeners;

use App\Events\ReservationTableAssigned;
use Modules\Whatsapp\Entities\WhatsAppNotificationPreference;
use Modules\Whatsapp\Services\WhatsAppNotificationService;
use Illuminate\Support\Facades\Log;

class SendReservationTableAssignedListener
{
    protected WhatsAppNotificationService $notificationService;

    public function __construct(WhatsAppNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     */
    public function handle(ReservationTableAssigned $event): void
    {
        try {
            $reservation = $event->reservation;
            $restaurantId = $reservation->branch->restaurant_id ?? null;
            $currentTableId = $reservation->table_id ?? null;
            $previousTableId = $event->previousTableId;

            if (!$restaurantId) {
                Log::info('WhatsApp Reservation Table Assigned Listener: Skipping - no restaurant_id', [
                    'reservation_id' => $reservation->id ?? null,
                ]);
                return;
            }

            // Check if WhatsApp module is in restaurant's package
            if (function_exists('restaurant_modules')) {
                $restaurant = $reservation->branch->restaurant ?? \App\Models\Restaurant::find($restaurantId);
                if ($restaurant) {
                    $restaurantModules = restaurant_modules($restaurant);
                    if (!in_array('Whatsapp', $restaurantModules)) {
                        return;
                    }
                }
            }

            // Only send if a table was actually assigned (not removed)
            if (!$currentTableId) {
                Log::info('WhatsApp Reservation Table Assigned Listener: Skipping - no table assigned', [
                    'reservation_id' => $reservation->id ?? null,
                ]);
                return;
            }

            // Skip if table didn't change (already had this table)
            if ($currentTableId === $previousTableId) {
                Log::info('WhatsApp Reservation Table Assigned Listener: Skipping - table unchanged', [
                    'reservation_id' => $reservation->id ?? null,
                    'table_id' => $currentTableId,
                ]);
                return;
            }

            // Determine if this is a first assignment or a table change
            $isTableChange = $previousTableId !== null;
            
            Log::info('WhatsApp Reservation Table Assigned Listener: Event triggered', [
                'reservation_id' => $reservation->id ?? null,
                'current_table_id' => $currentTableId,
                'previous_table_id' => $previousTableId,
                'is_table_change' => $isTableChange,
                'restaurant_id' => $restaurantId,
            ]);

            // Check if notification is enabled for customer
            $customerPreference = WhatsAppNotificationPreference::where('restaurant_id', $restaurantId)
                ->where('notification_type', 'reservation_status_update')
                ->where('recipient_type', 'customer')
                ->where('is_enabled', true)
                ->first();

            if (!$customerPreference) {
                Log::info('WhatsApp Reservation Table Assigned Listener: Skipping - notification not enabled', [
                    'reservation_id' => $reservation->id ?? null,
                ]);
                return;
            }

            if (!$reservation->customer) {
                Log::info('WhatsApp Reservation Table Assigned Listener: Skipping - no customer', [
                    'reservation_id' => $reservation->id ?? null,
                ]);
                return;
            }

            // Get customer phone number (combine phone_code and phone, no + sign)
            $customerPhone = null;
            if ($reservation->customer->phone) {
                if ($reservation->customer->phone_code) {
                    $customerPhone = $reservation->customer->phone_code . $reservation->customer->phone;
                } else {
                    $customerPhone = $reservation->customer->phone;
                }
            }

            if (!$customerPhone) {
                Log::warning('WhatsApp Reservation Table Assigned Listener: Customer has no valid phone number', [
                    'reservation_id' => $reservation->id ?? null,
                    'customer_id' => $reservation->customer->id,
                ]);
                return;
            }

            Log::info('WhatsApp Reservation Table Assigned Listener: Sending notification to customer', [
                'reservation_id' => $reservation->id ?? null,
                'customer_id' => $reservation->customer->id,
                'customer_phone' => $customerPhone,
                'table_id' => $currentTableId,
            ]);

            $variables = $this->getReservationVariables($reservation, $isTableChange);
            
            $result = $this->notificationService->send(
                $restaurantId,
                'reservation_status_update',
                $customerPhone,
                $variables,
                'en',
                'customer'
            );

            Log::info('WhatsApp Reservation Table Assigned Listener: Notification service response', [
                'reservation_id' => $reservation->id ?? null,
                'success' => $result['success'] ?? false,
                'message_id' => $result['message_id'] ?? null,
                'error' => $result['error'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp Reservation Table Assigned Listener Error: ' . $e->getMessage(), [
                'reservation_id' => $event->reservation->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    protected function getReservationVariables($reservation, bool $isTableChange = false): array
    {
        // Ensure relationships are loaded
        if (!$reservation->relationLoaded('table')) {
            $reservation->load('table');
        }
        if (!$reservation->relationLoaded('branch')) {
            $reservation->load('branch.restaurant');
        }
        
        $customerName = $reservation->customer->name ?? 'Customer';
        $reservationDate = $reservation->reservation_date_time->format('d M, Y') ?? 'N/A';
        $reservationTime = $reservation->reservation_date_time->format('h:i A') ?? 'N/A';
        $partySize = $reservation->party_size ?? 'N/A';
        
        // Get table name - should always be assigned at this point
        $tableName = 'Not assigned';
        if ($reservation->table_id && $reservation->table) {
            $tableName = $reservation->table->table_code ?? 'Table #' . $reservation->table_id;
        }
        
        $restaurantName = $reservation->branch->restaurant->name ?? '';
        $branchName = $reservation->branch->name ?? '';
        $contactNumber = $reservation->branch->restaurant->contact_number ?? '';
        $restaurantHash = $reservation->branch->restaurant->hash ?? '';

        // Get actual reservation status (should be Confirmed when table is assigned)
        $reservationStatus = $reservation->reservation_status ?? 'Confirmed';
        // Use Confirmed status when table is assigned
        $statusDisplay = 'Confirmed';

        // Variables format expected by template mapper: [customer_name, date, time, party_size, table_name, restaurant_name, branch_name, contact_number, restaurant_hash, status]
        return [
            $customerName,        // Index 0: Customer name
            $reservationDate,      // Index 1: Date
            $reservationTime,      // Index 2: Time
            $partySize,            // Index 3: Number of guests
            $tableName,            // Index 4: Table name or "Not assigned"
            $restaurantName,       // Index 5: Restaurant name
            $branchName,           // Index 6: Branch name
            $contactNumber,        // Index 7: Contact number
            $restaurantHash,       // Index 8: Restaurant hash/slug for button URL
            $statusDisplay,        // Index 9: Status (Confirmed when table is assigned)
        ];
    }
}

