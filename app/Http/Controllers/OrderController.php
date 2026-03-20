<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantTax;
use Barryvdh\DomPDF\Facade\Pdf;
class OrderController extends Controller
{

    public function index()
    {
        abort_if(!in_array('Order', restaurant_modules()), 403);
        abort_if((!user_can('Show Order')), 403);
        return view('order.index');
    }

    public function show($id)
    {
        return view('order.show', compact('id'));
    }

    public function printOrder($id, $width = 80, $thermal = false, $generateImage = false)
    {
        $id = Order::where('id', $id)->orWhere('uuid', $id)->value('id') ?: $id;

        $payment = Payment::where('order_id', $id)->first();
        $restaurant = restaurant();
        $taxDetails = RestaurantTax::where('restaurant_id', $restaurant->id)->get();
        $order = Order::with(['items.menuItem', 'items.menuItemVariation', 'items.modifierOptions'])->find($id);
        $orderBranch = $order->branch ?? branch();
        $receiptSettings = $orderBranch->receiptSetting;
        $taxMode = $order?->tax_mode ?? ($restaurant->tax_mode ?? 'order');
        $totalTaxAmount = 0;

        if ($taxMode === 'item') {
            $totalTaxAmount = $order->total_tax_amount ?? 0;
        }

        // Keep one line with all variables from both views (union of both compact calls)
        $content = view('order.print', compact('order', 'orderBranch', 'receiptSettings', 'taxDetails', 'payment', 'taxMode', 'totalTaxAmount', 'width', 'thermal', 'generateImage'));

        return $content;
    }

    /**
     * Generate PDF for order print
     */
    public function generateOrderPdf($id)
    {
        $payment = Payment::where('order_id', $id)->first();
        $restaurant = restaurant();
        $taxDetails = RestaurantTax::where('restaurant_id', $restaurant->id)->get();
        $order = Order::with(['items.menuItem', 'items.menuItemVariation', 'items.modifierOptions'])->find($id);
        $orderBranch = $order->branch ?? branch();
        $receiptSettings = $orderBranch->receiptSetting;
        $taxMode = $restaurant->tax_mode ?? 'order';
        $totalTaxAmount = 0;

        if ($taxMode === 'item') {
            $totalTaxAmount = $order->total_tax_amount ?? 0;
        }

        // Calculate tax_base for PDF view
        if ($order->tax_base) {
            $taxBase = $order->tax_base;
        } else {
            // Fallback for old orders
            $net = $order->sub_total - ($order->discount_amount ?? 0);
            $serviceTotal = 0;
            foreach ($order->charges as $item) {
                $serviceTotal += $item->charge->getAmount($net);
            }
            $includeChargesInTaxBase = $restaurant->include_charges_in_tax_base ?? true;
            $taxBase = $includeChargesInTaxBase ? ($net + $serviceTotal) : $net;
        }

        // Generate PDF
        $pdf = Pdf::loadView('order.print-pdf', compact('order', 'orderBranch', 'receiptSettings', 'taxDetails', 'payment', 'taxMode', 'totalTaxAmount', 'taxBase'));

        // Set paper size to A4
        $pdf->setPaper('A4', 'portrait');

        return $pdf->download($order->show_formatted_order_number . '.pdf');
    }

    /**
     * Get PDF content as string for email attachment
     */
    public function getOrderPdfContent($id)
    {
        $payment = Payment::where('order_id', $id)->first();
        $restaurant = restaurant();
        $taxDetails = RestaurantTax::where('restaurant_id', $restaurant->id)->get();
        $order = Order::with(['items.menuItem', 'items.menuItemVariation', 'items.modifierOptions'])->find($id);
        $orderBranch = $order->branch ?? branch();
        $receiptSettings = $orderBranch->receiptSetting;
        $taxMode = $restaurant->tax_mode ?? 'order';
        $totalTaxAmount = 0;

        if ($taxMode === 'item') {
            $totalTaxAmount = $order->total_tax_amount ?? 0;
        }

        // Calculate tax_base for PDF view
        if ($order->tax_base) {
            $taxBase = $order->tax_base;
        } else {
            // Fallback for old orders
            $net = $order->sub_total - ($order->discount_amount ?? 0);
            $serviceTotal = 0;
            foreach ($order->charges as $item) {
                $serviceTotal += $item->charge->getAmount($net);
            }
            $includeChargesInTaxBase = $restaurant->include_charges_in_tax_base ?? true;
            $taxBase = $includeChargesInTaxBase ? ($net + $serviceTotal) : $net;
        }

        // Generate PDF
        $pdf = Pdf::loadView('order.print-pdf', compact('order', 'orderBranch', 'receiptSettings', 'taxDetails', 'payment', 'taxMode', 'totalTaxAmount', 'taxBase'));

        // Set paper size to A4
        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }
}
