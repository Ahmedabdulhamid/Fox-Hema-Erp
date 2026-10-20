<?php

namespace App\Models\Concerns;

use App\Modules\AccessManagement\Models\DocumentNumber;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\DocumentPresentation;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasDocumentNumber
{
    public function initializeHasDocumentNumber(): void
    {
        if (app(DocumentNumberService::class)->installed()) {
            $this->with = array_values(array_unique([...$this->with, 'documentNumber']));
        }
    }

    public function documentNumber(): HasOne
    {
        return $this->hasOne(DocumentNumber::class, 'document_uuid', 'uuid')->where('document_type', static::DOCUMENT_TYPE);
    }

    public function getDisplayReferenceAttribute(): string
    {
        return ($this->exists && app(DocumentNumberService::class)->installed()
            ? $this->documentNumber?->number : null) ?? DocumentPresentation::legacyReference($this);
    }
}
