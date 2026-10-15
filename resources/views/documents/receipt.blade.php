@php($doc = $doc ?? app(\App\Services\DocumentPresenter::class)->receipt($receipt))
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Payment Receipt {{ $doc['number'] }}</title>
    <style>
        body { margin: 0; background: #FFFFFF; }
    </style>
    @include('documents.partials.doc-styles')
</head>

<body>
    @include('documents.partials.receipt-sheet', ['doc' => $doc])
</body>

</html>
