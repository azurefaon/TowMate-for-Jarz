@php($doc = $doc ?? app(\App\Services\DocumentPresenter::class)->receipt($receipt))
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Payment Receipt {{ $doc['number'] }}</title>
    @include('documents.partials.doc-pdf-styles')
</head>

<body>
    @include('documents.partials.receipt-pdf', ['doc' => $doc])
</body>

</html>
