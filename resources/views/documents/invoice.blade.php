@php($doc = $doc ?? app(\App\Services\DocumentPresenter::class)->invoice($invoice, ! empty($groupVehicles ?? []) ? $groupVehicles : null))
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $doc['number'] }}</title>
    @include('documents.partials.doc-pdf-styles')
</head>

<body>
    @include('documents.partials.invoice-pdf', ['doc' => $doc])
</body>

</html>
