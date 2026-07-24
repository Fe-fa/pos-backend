<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Requests\Billing\StoreBillingRequest;
use App\Http\Requests\Billing\UpdateBillingRequest;
use App\Models\Billing;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class BillingController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly BillingService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('billings.view')) return $error;

        $perPage = max(1, min((int) ($request->per_page ?? 15), 100));
        $user    = $request->user();

        if ($request->filled('store_id')) {
            $this->service->authorizeStoreAccess($user, $request->store_id);
        }

        $query = Billing::query()
            ->select([
                'billing_id',
                'uuid',
                'store_id',
                'customer_id',
                'user_id',
                'invnumber',
                'status',
                'subtotal',
                'vat_amount',
                'total',
                'paid_amount',
                'balance_due',
                'is_draft',
                'billing_date',
                'fulfillment_status',
                'fulfillment_type',
                'points_discount',
                'stock_applied_at',
                'created_at',
                'deleted_at',
            ])
            ->with([
                'customer:customer_id,full_name,email,phone',
                'store:store_id,store_name',
                'user:user_id,first_name,last_name,email',
                // NOTE: status, mpesa_receipt, and payment_meta are required by the
                // frontend's M-Pesa reversal eligibility check (HistoryModal.jsx /
                // CashierPosPage.jsx getReversibleMpesaPayment). Without them, every
                // payment silently fails that check and the "Request Reversal" /
                // "Refund" button never renders, regardless of permissions.
                'payments:payment_id,billing_id,amount_received,payment_method,payment_date,receiptnumber,status,mpesa_receipt,mpesa_phone,payment_meta',
            ])
            ->withCount('items')
            ->withSum('items', 'quantity');

        if ($request->boolean('only_trashed')) {
            $query->onlyTrashed();
        } elseif ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        // $query = $this->service->scopeAccessible($query, $user);
        $isSearchRequest = $request->filled('invnumber') || $request->filled('search');

if ($isSearchRequest) {
    // Only restrict to stores the user has access to, not to their own records
    $allowedStoreIds = $this->service->allowedStoreIds($user);
    $query->whereIn('store_id', $allowedStoreIds);
} else {
    $query = $this->service->scopeAccessible($query, $user);
}

        $query = $this->service->applyListFilters($query, $request->only([
            'store_id',
            'customer_id',
            'status',
            'is_draft',
            'fulfillment_status',
            'fulfillment_type',
            'user_id',         
            'payment_method',   
            'date_from',       
            'date_to', 
            'invnumber',  
            'search',
        ]));

        $billings = $query->orderByDesc('billing_id')->paginate($perPage);

        return response()->json([
            'data' => $billings->items(),
            'meta' => [
                'current_page' => $billings->currentPage(),
                'last_page'    => $billings->lastPage(),
                'per_page'     => $billings->perPage(),
                'total'        => $billings->total(),
                'from'         => $billings->firstItem(),
                'to'           => $billings->lastItem(),
            ],
        ]);
    }

    public function store(StoreBillingRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('billings.manage')) return $error;

        return response()->json([
            'message' => 'Draft billing created successfully.',
            'data'    => $this->service->createDraft($request->user(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        if ($error = $this->authorizePermission('billings.view')) return $error;

        $billing = Billing::withTrashed()->find($id);

        if (!$billing) {
            return response()->json([
                'message' => "Billing record #{$id} was not found in our system.",
                'data'    => null,
            ], 404);
        }

        return response()->json([
            'message' => 'Billing retrieved successfully.',
            'data'    => $this->service->show($billing),
        ]);
    }

    public function update(UpdateBillingRequest $request, $id): JsonResponse
    {
        if ($error = $this->authorizePermission('billings.manage')) return $error;

        $billing = Billing::withTrashed()->find($id);

        if (!$billing) {
            return response()->json([
                'message'    => "Update failed: Billing record #{$id} does not exist.",
                'debug_info' => 'Check if the record was deleted or if the database was refreshed.',
            ], 404);
        }

        return response()->json([
            'message' => 'Billing updated successfully.',
            'data'    => $this->service->updateHeader($billing, $request->validated()),
        ]);
    }

    public function dispatchDocuments(Request $request, Billing $billing): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        $data = $request->validate([
            'mode'  => ['nullable', 'in:receipt,invoice'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $billing = $this->service->show($billing);

        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));

        if ($email === '' && $phone === '') {
            return response()->json([
                'message' => 'Provide an email address or phone number to dispatch the document.',
            ], 422);
        }

        $mode = $data['mode'] ?? (((float) ($billing->balance_due ?? 0) <= 0) ? 'receipt' : 'invoice');
        $viewUrl = route('public.documents.show', ['mode' => $mode, 'uuid' => $billing->uuid]);
        $downloadUrl = route('public.documents.download', ['mode' => $mode, 'uuid' => $billing->uuid]);
        $documentLabel = $mode === 'receipt' ? 'Sales Receipt' : 'Tax Invoice';
        $storeName = $billing->store?->store_name ?: 'Your store';
        $customerName = $billing->customer?->full_name ?: 'Customer';

        $channels = [];
        $channelErrors = [];

        if ($email !== '') {
            try {
                Mail::html(
                    "<p>Hello {$customerName},</p><p>Your {$documentLabel} from {$storeName} is ready.</p><p><a href=\"{$viewUrl}\">View online</a> | <a href=\"{$downloadUrl}\">Download copy</a></p>",
                    function ($message) use ($email, $documentLabel, $storeName) {
                        $message->to($email)->subject("{$documentLabel} from {$storeName}");
                    }
                );

                $channels['email'] = 'sent';
            } catch (\Throwable $exception) {
                $channels['email'] = 'failed';
                $channelErrors['email'] = $exception->getMessage();
            }
        }

if ($phone !== '') {
    $token = config('services.whatsapp.token');
    $phoneNumberId = config('services.whatsapp.phone_number_id');

    if ($token && $phoneNumberId) {
        try {
            $waPhone = preg_replace('/[^0-9]/', '', $phone); // digits only, e.g. 2547XXXXXXXX

            $response = Http::withToken($token)
                ->timeout(8)
                ->acceptJson()
                ->post("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $waPhone,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => true,
                        'body' => "Hello {$customerName},\n\nYour {$documentLabel} from {$storeName} is ready.\n\nView: {$viewUrl}\nDownload: {$downloadUrl}",
                    ],
                ]);

            if ($response->successful()) {
                $channels['whatsapp'] = 'sent';
            } else {
                $channels['whatsapp'] = 'failed';
                $channelErrors['whatsapp'] = $response->json('error.message')
                    ?: trim((string) $response->body())
                    ?: "WhatsApp API responded with HTTP {$response->status()}";
            }
        } catch (\Throwable $exception) {
            $channels['whatsapp'] = 'failed';
            $channelErrors['whatsapp'] = $exception->getMessage();
        }
    } else {
        $channels['whatsapp'] = 'not_configured';
        $channelErrors['whatsapp'] = 'WhatsApp Cloud API credentials are not configured.';
    }
}

        return response()->json([
            'message' => 'Digital document dispatch processed successfully.',
            'data' => [
                'mode' => $mode,
                'channels' => $channels,
                'channel_errors' => $channelErrors,
                'view_url' => $viewUrl,
                'download_url' => $downloadUrl,
            ],
        ]);
    }

    public function destroy(Billing $billing): JsonResponse
    {
        if ($error = $this->authorizePermission('billings.manage')) return $error;

        $this->service->destroy($billing);

        return response()->json([
            'message' => 'Billing deleted successfully.',
        ]);
    }

    public function restore(Request $request, $id): JsonResponse
    {
        if ($error = $this->authorizePermission('billings.manage')) return $error;

        return response()->json([
            'message' => 'Billing restored successfully.',
            'data'    => $this->service->restore($id, $request->user()),
        ]);
    }
}