@php($doc = $doc ?? app(\App\Services\DocumentPresenter::class)->invoice($invoice, ! empty($groupVehicles ?? []) ? $groupVehicles : null))
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $doc['number'] }}</title>
    <style>
        body { margin: 0; background: #FFFFFF; }
    </style>
    @include('documents.partials.doc-styles')
</head>

<body>
    @include('documents.partials.invoice-sheet', ['doc' => $doc])
</body>

</html>
