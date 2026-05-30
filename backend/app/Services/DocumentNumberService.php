<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function nextNumber(int $storeId, string $documentType): string
    {
        return DB::transaction(function () use ($storeId, $documentType) {
            $sequence = DocumentSequence::query()
                ->where('store_id', $storeId)
                ->where('document_type', $documentType)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $defaultPrefix = $documentType === 'receipt' ? 'REC-' : 'INV-';

                $sequence = DocumentSequence::create([
                    'store_id' => $storeId,
                    'document_type' => $documentType,
                    'prefix' => $defaultPrefix,
                    'suffix' => '',
                    'last_number' => 0,
                ]);
            }

            $sequence->last_number += 1;
            $sequence->save();

            return sprintf(
                '%s%04d%s',
                $sequence->prefix ?? '',
                $sequence->last_number,
                $sequence->suffix ?? ''
            );
        });
    }
}
