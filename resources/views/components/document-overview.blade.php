@php
    $document = app(\App\Modules\AccessManagement\Services\DocumentPresentation::class)->overview($getRecord(), auth()->user());
@endphp
<section class="erp-document-overview" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" aria-label="{{ $document['type'] }}">
    <div class="erp-document-heading">
        <div>
            <span>{{ $document['type'] }}</span>
            <h2 class="erp-document-number">{{ $document['reference'] }}</h2>
            <div class="erp-document-context"><span>{{ $document['location'] }}</span><span>{{ $document['party'] }}</span></div>
        </div>
    </div>
    <ol class="erp-document-steps" aria-label="{{ __('documents.status_progression') }}">
        @foreach($document['steps'] as $step)
            <li class="erp-document-step erp-document-step-{{ $step }}" @if($step === $document['status']) aria-current="step" @endif>{{ __('documents.'.$step) }}</li>
        @endforeach
    </ol>
    @if($document['links'])
        <nav class="erp-document-links" aria-label="{{ __('documents.related_documents') }}">
            @foreach($document['links'] as $link)<a class="erp-document-link" href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach
        </nav>
    @endif
    @if($document['legacy_reference'])
        <details class="erp-document-legacy"><summary>{{ __('documents.legacy_reference') }}</summary><bdi>{{ $document['legacy_reference'] }}</bdi></details>
    @endif
</section>
